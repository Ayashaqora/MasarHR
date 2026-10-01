<?php

namespace Tests\Feature\HumanResources;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use App\Modules\HumanResources\Application\Commands\DesignateQualificationAsPrimary;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Domain\Exceptions\InvalidTravelPayStatusException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * S41 SCHEMA-01 / SCHEMA-02 write behavior (docs/human-cadre-report-foundation-specification.md §S41.5–§S41.7): the Primary
 * Qualification (first qualification automatic, later ones never replace it, one explicit atomic designation, 0..1 per Person
 * enforced by PostgreSQL) and the traveling pay indicator (PAID / UNPAID / NULL, only for `traveling`, legacy NULL untouched).
 * Real PostgreSQL, synthetic data only. The cross-session races live in ConcurrencyTest.
 */
class PrimaryQualificationAndTravelPayTest extends HumanResourcesTestCase
{
    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function record(Person $person, bool $degree = true): PersonQualification
    {
        return app(RecordPersonQualification::class)->handle($person, $degree ? $this->createSyntheticAcademicDegree() : null, $degree ? null : $this->createSyntheticQualificationType());
    }

    private function primaryIds(Person $person): array
    {
        return PersonQualification::query()->where('person_id', $person->id)->where('is_primary', true)->pluck('id')->all();
    }

    private function latestAudit(string $action): AuditEntry
    {
        return AuditEntry::query()->where('action', $action)->orderByDesc('id')->firstOrFail();
    }

    private function designateUrl(Person $person, PersonQualification $qualification): string
    {
        return "/api/v1/hr/persons/{$person->id}/qualifications/{$qualification->id}/designate-primary";
    }

    private function viewerWith(array $permissions): void
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');
    }

    // ------------------------------------------------------------------------------------------------------------
    // R1-D49 — recording
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_first_qualification_is_primary_and_a_later_one_never_replaces_it(): void
    {
        $person = $this->createPersonRecord();

        $first = $this->record($person);
        $second = $this->record($person, false);
        $third = $this->record($person);

        $this->assertTrue($first->is_primary);
        $this->assertFalse($second->is_primary);
        $this->assertFalse($third->is_primary);
        $this->assertSame([$first->id], $this->primaryIds($person), 'the existing Primary stays');
    }

    public function test_each_person_gets_their_own_first_primary(): void
    {
        $a = $this->createPersonRecord();
        $b = $this->createPersonRecord();

        $qa = $this->record($a);
        $qb = $this->record($b);

        $this->assertTrue($qa->is_primary);
        $this->assertTrue($qb->is_primary, 'another Person\'s Primary never blocks the first qualification of this one');
    }

    public function test_the_store_endpoint_exposes_is_primary_and_accepts_no_is_primary_input(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $first = $this->createSyntheticAcademicDegree();
        $second = $this->createSyntheticQualificationType();

        $this->postJson("/api/v1/hr/persons/{$person->id}/qualifications", ['academic_degree_id' => $first->id])->assertStatus(201)->assertJsonPath('is_primary', true);
        $this->postJson("/api/v1/hr/persons/{$person->id}/qualifications", ['qualification_type_id' => $second->id, 'is_primary' => true])->assertStatus(201)->assertJsonPath('is_primary', false);
        $this->assertCount(1, $this->primaryIds($person), 'no generic is_primary shortcut: the input is ignored');
    }

    // ------------------------------------------------------------------------------------------------------------
    // R1-D49 — explicit designation
    // ------------------------------------------------------------------------------------------------------------

    public function test_designating_b_moves_the_primary_atomically_from_a_to_b(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->record($person);
        $b = $this->record($person, false);

        $result = app(DesignateQualificationAsPrimary::class)->handle($person, $b);

        $this->assertTrue($result->changed);
        $this->assertSame($a->id, $result->previousPrimaryQualificationId);
        $this->assertSame([$b->id], $this->primaryIds($person), 'exactly one Primary: the new one');
        $this->assertFalse($a->refresh()->is_primary);
    }

    public function test_designation_works_when_there_is_no_previous_primary(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->record($person);
        $b = $this->record($person, false);
        DB::table('hr.person_qualifications')->where('person_id', $person->id)->update(['is_primary' => false]); // valid storage: 0 Primary

        $result = app(DesignateQualificationAsPrimary::class)->handle($person, $a);

        $this->assertTrue($result->changed);
        $this->assertNull($result->previousPrimaryQualificationId);
        $this->assertSame([$a->id], $this->primaryIds($person));
        $this->assertNotSame($b->id, $this->primaryIds($person)[0]);
    }

    public function test_designating_the_current_primary_is_an_idempotent_success_that_changes_nothing(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->record($person);
        $this->record($person, false);

        $result = app(DesignateQualificationAsPrimary::class)->handle($person, $a);

        $this->assertFalse($result->changed);
        $this->assertSame($a->id, $result->previousPrimaryQualificationId);
        $this->assertSame([$a->id], $this->primaryIds($person));
    }

    public function test_a_qualification_of_another_person_cannot_be_designated(): void
    {
        $person = $this->createPersonRecord();
        $other = $this->createPersonRecord();
        $mine = $this->record($person);
        $theirs = $this->record($other);

        $this->expectException(ModelNotFoundException::class);
        try {
            app(DesignateQualificationAsPrimary::class)->handle($person, $theirs);
        } finally {
            $this->assertSame([$mine->id], $this->primaryIds($person));
            $this->assertSame([$theirs->id], $this->primaryIds($other), 'nothing changed for either Person');
        }
    }

    public function test_a_failure_inside_the_designation_rolls_everything_back_to_the_previous_primary(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->record($person);
        $b = $this->record($person, false);

        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update "hr"."person_qualifications" set "is_primary"') && ($query->bindings[0] ?? null) === true) {
                throw new RuntimeException('simulated failure after the previous Primary was unset');
            }
        });

        try {
            app(DesignateQualificationAsPrimary::class)->handle($person, $b);
            $this->fail('the simulated failure must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure after the previous Primary was unset', $e->getMessage());
        }

        $this->assertSame([$a->id], $this->primaryIds($person), 'the transaction rolled back: the previous Primary is intact, never zero and never two');
    }

    public function test_the_database_rejects_two_primary_rows_for_one_person(): void
    {
        $person = $this->createPersonRecord();
        $a = $this->record($person);
        $b = $this->record($person, false);

        $error = null;
        try {
            DB::transaction(fn () => PersonQualification::query()->where('id', $b->id)->update(['is_primary' => true]));
        } catch (QueryException $e) {
            $error = $e;
        }

        $this->assertNotNull($error);
        $this->assertTrue(Errors::isUniqueViolation($error));
        $this->assertStringContainsString('person_qualifications_one_primary_unique', $error->getMessage());
        $this->assertSame([$a->id], $this->primaryIds($person));
    }

    // ------------------------------------------------------------------------------------------------------------
    // R1-D49 — API, authorization, audit
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_designate_endpoint_changes_the_primary_audits_the_transition_and_is_idempotent(): void
    {
        $principal = $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $a = $this->record($person);
        $b = $this->record($person, false);

        $this->postJson($this->designateUrl($person, $b))->assertOk()->assertJsonPath('id', $b->id)->assertJsonPath('is_primary', true);

        $entry = $this->latestAudit('hr.person_qualification.designate_primary');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_person_qualification', $entry->target_type);
        $this->assertSame($b->id, $entry->target_id);
        $this->assertEquals(['person_id' => $person->id, 'previous_primary_qualification_id' => $a->id, 'new_primary_qualification_id' => $b->id], $entry->changes);
        $this->assertEquals(['state_changed' => true], $entry->metadata);

        $this->postJson($this->designateUrl($person, $b))->assertOk()->assertJsonPath('is_primary', true);
        $again = $this->latestAudit('hr.person_qualification.designate_primary');
        $this->assertEquals(['person_id' => $person->id, 'previous_primary_qualification_id' => $b->id, 'new_primary_qualification_id' => $b->id], $again->changes, 'no fabricated transition');
        $this->assertEquals(['state_changed' => false], $again->metadata);
        $this->assertSame([$b->id], $this->primaryIds($person));
    }

    public function test_the_designate_endpoint_returns_404_for_another_persons_qualification_and_403_without_the_permission(): void
    {
        $person = $this->createPersonRecord();
        $other = $this->createPersonRecord();
        $mine = $this->record($person);
        $theirs = $this->record($other);

        $this->viewerWith([Perm::PERSON_QUALIFICATIONS_RECORD, Perm::PERSON_QUALIFICATIONS_VIEW]);
        $this->postJson($this->designateUrl($person, $mine))->assertStatus(403);

        $this->viewerWith([Perm::PERSON_QUALIFICATIONS_DESIGNATE_PRIMARY]);
        $this->postJson($this->designateUrl($person, $theirs))->assertStatus(404);
        $this->postJson($this->designateUrl($person, $mine))->assertOk();
    }

    public function test_there_is_no_generic_patch_delete_or_update_route_for_qualifications(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $qualification = $this->record($person);

        foreach (['patch', 'put', 'delete'] as $method) {
            $status = $this->{$method.'Json'}("/api/v1/hr/persons/{$person->id}/qualifications/{$qualification->id}", ['is_primary' => true])->getStatusCode();
            $this->assertContains($status, [404, 405], "no {$method} route exists for a qualification");
        }
        $this->assertSame(1, PersonQualification::query()->where('person_id', $person->id)->count(), 'nothing was deleted');
    }

    // ------------------------------------------------------------------------------------------------------------
    // R1-D36 / D48 — travel pay status
    // ------------------------------------------------------------------------------------------------------------

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function emp(): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01')];
    }

    private function recStatus(Person $person, EmploymentRelationship $rel, string $code, string $from, ?string $to = null, ?string $pay = null): EmploymentStatusPeriod
    {
        return app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to, $pay);
    }

    public function test_traveling_accepts_paid_unpaid_and_null_and_stores_exactly_what_was_given(): void
    {
        foreach (['PAID', 'UNPAID', null] as $pay) {
            [$person, $rel] = $this->emp();

            $period = $this->recStatus($person, $rel, 'traveling', '2026-10-01', null, $pay);

            $this->assertSame($pay, $period->refresh()->travel_pay_status);
            $this->assertSame($pay, DB::table('hr.employment_status_periods')->where('id', $period->id)->value('travel_pay_status'), 'NULL is stored as NULL, never rewritten to PAID');
        }
    }

    public function test_a_non_traveling_status_rejects_a_pay_indicator_and_accepts_null(): void
    {
        foreach (['captive', 'suspended', 'on_duty'] as $code) {
            [$person, $rel] = $this->emp();

            try {
                $this->recStatus($person, $rel, $code, '2026-10-01', null, 'PAID');
                $this->fail("{$code} must reject a travel pay status");
            } catch (InvalidTravelPayStatusException) {
                $this->assertSame(0, EmploymentStatusPeriod::query()->where('employment_relationship_id', $rel->id)->count(), 'nothing was written');
            }

            $this->assertNull($this->recStatus($person, $rel, $code, '2026-10-01')->refresh()->travel_pay_status);
        }

        [$person, $rel] = $this->emp();
        $this->expectException(InvalidTravelPayStatusException::class);
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01', 'UNPAID');
    }

    public function test_an_unknown_pay_value_is_rejected_by_the_command_and_by_the_database(): void
    {
        [$person, $rel] = $this->emp();
        $this->expectException(InvalidTravelPayStatusException::class);
        try {
            $this->recStatus($person, $rel, 'traveling', '2026-10-01', null, 'MAYBE');
        } finally {
            $error = null;
            try {
                DB::transaction(fn () => DB::table('hr.employment_status_periods')->insert([
                    'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail('traveling')->id,
                    'effective_from' => '2026-10-01', 'effective_to' => null, 'travel_pay_status' => 'MAYBE', 'created_at' => now(),
                ]));
            } catch (QueryException $e) {
                $error = $e;
            }
            $this->assertNotNull($error);
            $this->assertTrue(Errors::isCheckViolation($error), 'the CHECK is the database-level backstop');
        }
    }

    public function test_the_status_endpoint_validates_the_pay_indicator_and_exposes_it_in_the_response_and_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $url = "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/status-periods";

        $this->postJson($url, ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-20', 'travel_pay_status' => 'MAYBE'])->assertStatus(422)->assertJsonValidationErrors(['travel_pay_status']);
        $this->postJson($url, ['status_detail_code' => 'captive', 'effective_from' => '2026-10-01', 'travel_pay_status' => 'PAID'])->assertStatus(422)->assertJsonValidationErrors(['travel_pay_status']);

        $this->postJson($url, ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-20', 'travel_pay_status' => 'UNPAID'])
            ->assertStatus(201)->assertJsonPath('travel_pay_status', 'UNPAID');
        $entry = $this->latestAudit('hr.employment_status_period.record');
        $this->assertSame('UNPAID', $entry->changes['travel_pay_status']);

        $this->postJson($url, ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-20', 'travel_pay_status' => null])->assertStatus(201)->assertJsonPath('travel_pay_status', null);
        $this->postJson($url, ['status_detail_code' => 'captive', 'effective_from' => '2026-11-01'])->assertStatus(201)->assertJsonPath('travel_pay_status', null);
        $this->assertNull($this->latestAudit('hr.employment_status_period.record')->changes['travel_pay_status'], 'an omitted indicator is audited as NULL, distinguishable from PAID / UNPAID');
    }

    public function test_closing_a_traveling_period_preserves_its_pay_indicator(): void
    {
        [$person, $rel] = $this->emp();
        $traveling = $this->recStatus($person, $rel, 'traveling', '2026-10-01', null, 'UNPAID');

        $this->recStatus($person, $rel, 'suspended', '2026-10-15');

        $closed = $traveling->refresh();
        $this->assertSame('2026-10-15', $closed->effective_to->toDateString(), 'auto-close behavior is preserved');
        $this->assertSame('UNPAID', $closed->travel_pay_status, 'only effective_to changed');
    }

    public function test_legacy_rows_without_a_pay_indicator_stay_null_and_the_migration_never_rewrites_them(): void
    {
        [$person, $rel] = $this->emp();
        $id = (string) Str::uuid7();
        DB::table('hr.employment_status_periods')->insert([
            'id' => $id, 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail('traveling')->id,
            'effective_from' => '2026-10-01', 'effective_to' => null, 'created_at' => now(),
        ]);

        $this->assertNull(DB::table('hr.employment_status_periods')->where('id', $id)->value('travel_pay_status'));
        $migration = (string) file_get_contents(base_path('database/migrations/2026_10_18_000001_add_travel_pay_status_to_hr_employment_status_periods_table.php'));
        $this->assertStringNotContainsString('UPDATE', $migration, 'no backfill');
        $this->assertStringNotContainsString('DEFAULT', $migration, 'no default');
        $this->assertStringNotContainsString('ENUM', strtoupper(preg_replace('#/\*.*?\*/#s', '', $migration)), 'no native enum');
    }

    // ------------------------------------------------------------------------------------------------------------
    // SCHEMA-02 — the migration backfill (R1-D42)
    // ------------------------------------------------------------------------------------------------------------

    public function test_the_backfill_makes_a_single_qualification_primary_and_never_infers_a_primary_among_several(): void
    {
        $none = $this->createPersonRecord();
        $one = $this->createPersonRecord();
        $several = $this->createPersonRecord();
        $qOne = $this->record($one);
        $this->record($several);
        $this->record($several, false);
        $this->record($several);
        DB::table('hr.person_qualifications')->whereIn('person_id', [$one->id, $several->id])->update(['is_primary' => false]); // the pre-S41 state

        $migration = (string) file_get_contents(base_path('database/migrations/2026_10_18_000002_add_is_primary_to_hr_person_qualifications_table.php'));
        $this->assertSame(1, preg_match('/UPDATE "hr"\."person_qualifications".*?(?=\s+SQL)/s', $migration, $match), 'the migration contains the backfill');
        DB::statement($match[0]);

        $this->assertSame([$qOne->id], $this->primaryIds($one), 'exactly one qualification: that row is Primary');
        $this->assertSame([], $this->primaryIds($several), 'two or more qualifications: none is set');
        $this->assertSame([], $this->primaryIds($none), 'no qualification: nothing to set');
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from (select person_id from hr.person_qualifications where is_primary group by person_id having count(*) > 1) x')->c, 'the backfill cannot create two Primary rows');
    }
}
