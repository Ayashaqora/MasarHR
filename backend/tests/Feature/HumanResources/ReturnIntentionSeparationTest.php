<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordReturnIntention;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEffectiveEmploymentStatusAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentStatusForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveReturnIntentionAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodEndException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionValueException;
use App\Modules\HumanResources\Domain\Exceptions\RetiredEmploymentStatusCodeException;
use App\Modules\HumanResources\Infrastructure\Persistence\Postgres\LegacyReturnIntentionStatusGuard;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Queries\ResolveEmploymentStatusDetailBehaviorAsOf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FixedBusinessDateClock;

/**
 * S34 Return Intention separation: an independent relationship-owned temporal concept (NOT an
 * employment status). Synthetic data only.
 */
class ReturnIntentionSeparationTest extends HumanResourcesTestCase
{
    private function emp(string $from = '2026-01-01'): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, 'permanent', null, $from)];
    }

    private function recStatus($person, $rel, string $code, string $from, ?string $to = null)
    {
        return app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to);
    }

    private function intent($rel, string $value, string $from, ?string $to = null)
    {
        return app(RecordReturnIntention::class)->handle($rel->refresh(), $value, $from, $to);
    }

    private function statusRows($rel): array
    {
        return DB::table('hr.employment_status_periods')->where('employment_relationship_id', $rel->id)->orderBy('effective_from')
            ->get(['status_detail_id', 'effective_from', 'effective_to'])->map(fn ($r) => (array) $r)->all();
    }

    private function intentRows($rel): array
    {
        return DB::table('hr.return_intention_periods')->where('employment_relationship_id', $rel->id)->orderBy('effective_from')
            ->get(['intention', 'effective_from', 'effective_to'])->map(fn ($r) => [$r->intention, $r->effective_from, $r->effective_to])->all();
    }

    private function intentAt($rel, string $date): ?string
    {
        return app(ResolveReturnIntentionAsOf::class)($rel->refresh(), $date)?->intention;
    }

    private function url($person, $rel, string $tail): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/{$tail}";
    }

    // ---- independence / coexistence ----------------------------------------------------------

    public function test_every_status_coexists_with_either_intention_without_touching_the_status(): void
    {
        $cases = ['on_duty' => null, 'traveling' => '2026-12-01', 'captive' => null, 'suspended' => '2026-12-01', 'unpaid_leave' => '2026-12-01', 'external_sick_leave' => '2026-12-01'];
        foreach (['WANTS_TO_RETURN', 'DOES_NOT_WANT_TO_RETURN'] as $intention) {
            foreach ($cases as $code => $to) {
                [$person, $rel] = $this->emp();
                $this->recStatus($person, $rel, $code, '2026-10-01', $to);
                $before = $this->statusRows($rel);

                $this->intent($rel, $intention, '2026-10-05');

                $this->assertSame($before, $this->statusRows($rel), "[$code/$intention] status untouched");
                $this->assertSame($code, app(ResolveEffectiveEmploymentStatusAsOf::class)($rel->refresh(), '2026-10-10')->statusDetailCode);
                $this->assertSame($intention, $this->intentAt($rel, '2026-10-10'), "[$code] intention coexists");
            }
        }
    }

    public function test_a_status_change_never_erases_the_return_intention(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-10-01');
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-05');
        $before = $this->intentRows($rel);

        $this->recStatus($person, $rel, 'suspended', '2026-11-01');
        $this->recStatus($person, $rel, 'on_duty', '2026-12-01');

        $this->assertSame($before, $this->intentRows($rel));
        $this->assertSame('WANTS_TO_RETURN', $this->intentAt($rel, '2026-12-15'));
    }

    public function test_recording_an_intention_never_alters_status_movement_or_schedule(): void
    {
        [$person, $rel] = $this->emp();
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-10');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        app(StartFullSecondment::class)->handle($rel->refresh(), $this->createUnit(), '2026-02-01');
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');

        $tables = ['hr.employment_status_periods', 'hr.organizational_placement_periods', 'hr.full_secondment_periods', 'hr.workplace_assignment_periods', 'hr.partial_secondment_periods', 'hr.work_schedule_periods'];
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->where('employment_relationship_id', $rel->id)->orderBy('id')->get()->toArray(), $tables);
        $before = $snapshot();
        $auditBefore = DB::table('audit.audit_entries')->count();

        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-05');
        $this->intent($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-10-20');

        $this->assertEquals($before, $snapshot());
        $this->assertSame($auditBefore, DB::table('audit.audit_entries')->count(), 'the command alone writes no audit');
    }

    // ---- temporal semantics ------------------------------------------------------------------

    public function test_as_of_resolution_and_half_open_boundaries(): void
    {
        [, $rel] = $this->emp();
        $this->assertNull($this->intentAt($rel, '2026-10-01'), 'NOT RECORDED is null, never a default');
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-05', '2026-11-05');

        $this->assertNull($this->intentAt($rel, '2026-10-04'));
        $this->assertSame('WANTS_TO_RETURN', $this->intentAt($rel, '2026-10-05'), 'from is inclusive');
        $this->assertSame('WANTS_TO_RETURN', $this->intentAt($rel, '2026-11-04'));
        $this->assertNull($this->intentAt($rel, '2026-11-05'), 'to is exclusive: expiry returns to NOT RECORDED, not a third value');
        $this->assertSame(1, DB::table('hr.return_intention_periods')->where('employment_relationship_id', $rel->id)->count());
    }

    public function test_a_later_record_truncates_the_covering_period_and_history_is_kept(): void
    {
        [, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01');
        $this->intent($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-11-01');

        $this->assertSame([['WANTS_TO_RETURN', '2026-10-01', '2026-11-01'], ['DOES_NOT_WANT_TO_RETURN', '2026-11-01', null]], $this->intentRows($rel));
        $this->assertSame('WANTS_TO_RETURN', $this->intentAt($rel, '2026-10-31'));
        $this->assertSame('DOES_NOT_WANT_TO_RETURN', $this->intentAt($rel, '2026-11-01'));
    }

    public function test_future_dated_records_are_append_safe(): void
    {
        [, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01');
        $this->intent($rel, 'DOES_NOT_WANT_TO_RETURN', '2027-06-01');

        $this->assertSame('WANTS_TO_RETURN', $this->intentAt($rel, '2027-05-31'));
        $this->assertSame('DOES_NOT_WANT_TO_RETURN', $this->intentAt($rel, '2027-06-01'));
    }

    public function test_an_ambiguous_backdated_write_is_rejected_atomically(): void
    {
        [, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-12-01', '2027-01-01');
        $before = $this->intentRows($rel);

        foreach (['2026-10-01', '2026-12-01'] as $from) {
            try {
                $this->intent($rel, 'DOES_NOT_WANT_TO_RETURN', $from);
                $this->fail('rewrite of recorded later history must be rejected');
            } catch (InvalidReturnIntentionPeriodDateException) {
                $this->assertSame($before, $this->intentRows($rel));
            }
        }
    }

    public function test_a_bounded_period_strictly_inside_a_bounded_one_is_rejected_and_a_start_before_the_relationship_too(): void
    {
        [, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01', '2026-12-01');
        try {
            $this->intent($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-10-10', '2026-10-20');
            $this->fail('split must be rejected');
        } catch (InvalidReturnIntentionPeriodEndException) {
            $this->assertCount(1, $this->intentRows($rel));
        }

        $this->expectException(InvalidReturnIntentionPeriodDateException::class);
        $this->intent($rel, 'WANTS_TO_RETURN', '2025-12-31');
    }

    public function test_value_and_end_validation(): void
    {
        [, $rel] = $this->emp();
        foreach ([['NOT_RECORDED', InvalidReturnIntentionValueException::class], ['wants_to_return', InvalidReturnIntentionValueException::class], ['', InvalidReturnIntentionValueException::class]] as [$value, $class]) {
            try {
                $this->intent($rel, $value, '2026-10-01');
                $this->fail("[$value] must be rejected");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e);
            }
        }
        foreach (['2026-10-01', '2026-09-30'] as $to) {
            try {
                $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01', $to);
                $this->fail('end <= start must be rejected');
            } catch (InvalidReturnIntentionPeriodEndException) {
            }
        }
        $this->assertSame([], $this->intentRows($rel));
    }

    public function test_the_database_itself_rejects_overlap_invalid_intervals_and_unknown_values(): void
    {
        [, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01', '2026-11-01');
        $insert = fn (string $intention, string $from, ?string $to) => DB::table('hr.return_intention_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'intention' => $intention,
            'effective_from' => $from, 'effective_to' => $to, 'created_at' => now(),
        ]);

        $overlap = $this->databaseError(fn () => DB::transaction(fn () => $insert('DOES_NOT_WANT_TO_RETURN', '2026-10-15', '2026-11-15')));
        $this->assertTrue(Errors::isExclusionViolation($overlap));
        $badInterval = $this->databaseError(fn () => DB::transaction(fn () => $insert('WANTS_TO_RETURN', '2027-01-01', '2027-01-01')));
        $this->assertTrue(Errors::isCheckViolation($badInterval));
        $badValue = $this->databaseError(fn () => DB::transaction(fn () => $insert('UNKNOWN', '2028-01-01', null)));
        $this->assertTrue(Errors::isCheckViolation($badValue), 'no third persisted value');
        DB::transaction(fn () => $insert('DOES_NOT_WANT_TO_RETURN', '2026-11-01', null)); // adjacent is fine
        $this->assertCount(2, $this->intentRows($rel));
    }

    // ---- relationship end / reappointment ----------------------------------------------------

    public function test_relationship_end_truncates_a_period_extending_past_it_and_keeps_history(): void
    {
        [$person, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01', '2026-12-01');
        $this->intent($rel, 'DOES_NOT_WANT_TO_RETURN', '2026-12-01');
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-12-15', false);

        $this->assertSame([['WANTS_TO_RETURN', '2026-10-01', '2026-12-01'], ['DOES_NOT_WANT_TO_RETURN', '2026-12-01', '2026-12-15']], $this->intentRows($rel));
    }

    public function test_relationship_end_before_a_future_intention_rejects_atomically(): void
    {
        [$person, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-12-01');
        $before = $this->intentRows($rel);

        try {
            DB::transaction(fn () => app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-11-01', false));
            $this->fail('must reject');
        } catch (InvalidEndDateException) {
            $this->assertSame($before, $this->intentRows($rel));
            $this->assertSame('NOT_APPLICABLE', $rel->refresh()->end_knowledge_state);
        }
    }

    public function test_recording_against_an_ended_relationship_is_rejected(): void
    {
        [$person, $rel] = $this->emp();
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-11-01', false);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01');
    }

    public function test_a_status_triggered_end_also_truncates_the_intention(): void
    {
        [$person, $rel] = $this->emp();
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-01');
        $this->recStatus($person, $rel, 'resigned', '2026-11-15');

        $this->assertSame([['WANTS_TO_RETURN', '2026-10-01', '2026-11-15']], $this->intentRows($rel));
    }

    public function test_reappointment_starts_with_no_recorded_return_intention(): void
    {
        [$person, $old] = $this->emp();
        $this->intent($old, 'WANTS_TO_RETURN', '2026-10-01');
        app(EndEmploymentRelationship::class)->handle($person, $old->refresh(), $old->version, '2026-11-01', false);
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2027-01-01');

        $this->assertNull($this->intentAt($new, '2027-02-01'));
        $this->assertSame([], $this->intentRows($new));
        $this->assertCount(1, $this->intentRows($old), 'old history stays attached to the old relationship');
    }

    // ---- legacy status retirement ------------------------------------------------------------

    public function test_the_two_legacy_codes_are_rejected_for_new_status_writes_even_after_reactivation(): void
    {
        [$person, $rel] = $this->emp();
        foreach (['wants_to_return', 'does_not_want_to_return'] as $code) {
            $this->assertFalse((bool) $this->statusDetail($code)->is_active, 'retired in the catalog');
            try {
                $this->recStatus($person, $rel, $code, '2026-10-01');
                $this->fail("[$code] must be rejected");
            } catch (RetiredEmploymentStatusCodeException) {
            }

            DB::table('ref.employment_status_details')->where('code', $code)->update(['is_active' => true]);
            try {
                $this->recStatus($person, $rel, $code, '2026-10-01');
                $this->fail("[$code] reactivation must not bypass the guard");
            } catch (RetiredEmploymentStatusCodeException) {
            }
        }
        $this->assertSame([], $this->statusRows($rel));
    }

    public function test_the_api_rejects_a_legacy_code_with_422_on_status_detail_code(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $this->postJson($this->url($person, $rel, 'status-periods'), ['status_detail_code' => 'wants_to_return', 'effective_from' => '2026-10-01'])
            ->assertStatus(422)->assertJsonValidationErrors('status_detail_code');
    }

    public function test_legacy_catalog_rows_and_behaviors_remain_for_historical_reads(): void
    {
        [$person, $rel] = $this->emp();
        foreach (['wants_to_return', 'does_not_want_to_return'] as $code) {
            $detail = $this->statusDetail($code);
            $this->assertNotNull($detail, 'catalog row is never deleted');
            $this->assertNotNull(app(ResolveEmploymentStatusDetailBehaviorAsOf::class)($detail, '2026-10-01'), 'behavior stays resolvable');
        }
        // A pre-existing legacy period (inserted raw, as old data would exist) still reads normally.
        DB::table('hr.employment_status_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id,
            'status_detail_id' => $this->statusDetail('wants_to_return')->id, 'effective_from' => '2026-10-01', 'effective_to' => null, 'created_at' => now(),
        ]);
        $period = app(ResolveEmploymentStatusForRelationshipAsOf::class)($rel->refresh(), '2026-10-15');
        $this->assertSame($this->statusDetail('wants_to_return')->id, $period->status_detail_id);
    }

    // ---- fail-closed migration guard ---------------------------------------------------------

    private function migrationFile(string $name)
    {
        return require base_path("database/migrations/{$name}.php");
    }

    public function test_the_migration_guard_passes_with_zero_legacy_rows(): void
    {
        $this->assertSame(0, LegacyReturnIntentionStatusGuard::legacyPeriodCount());
        LegacyReturnIntentionStatusGuard::assertNoLegacyStatusPeriods();
        $this->assertTrue(true);
    }

    public function test_the_migration_guard_fails_closed_and_never_fabricates_deletes_or_converts(): void
    {
        [$person, $rel] = $this->emp();
        $legacyId = (string) Str::uuid7();
        DB::table('hr.employment_status_periods')->insert([
            'id' => $legacyId, 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail('does_not_want_to_return')->id,
            'effective_from' => '2026-10-01', 'effective_to' => '2026-11-01', 'created_at' => now(),
        ]);
        DB::table('ref.employment_status_details')->whereIn('code', ['wants_to_return', 'does_not_want_to_return'])->update(['is_active' => true]);

        $statusBefore = DB::table('hr.employment_status_periods')->orderBy('id')->get()->toArray();
        $catalogBefore = DB::table('ref.employment_status_details')->orderBy('code')->get(['code', 'is_active', 'version'])->toArray();
        $intentBefore = DB::table('hr.return_intention_periods')->count();

        foreach (['2026_10_15_000003_retire_legacy_return_intention_status_details', '2026_10_15_000001_create_hr_return_intention_periods_table'] as $name) {
            try {
                DB::transaction(fn () => $this->migrationFile($name)->up());
                $this->fail("[$name] must fail with legacy rows present");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('explicit migration decision', $e->getMessage());
                $this->assertStringContainsString('No data was deleted, converted or altered', $e->getMessage());
            }
        }

        $this->assertEquals($statusBefore, DB::table('hr.employment_status_periods')->orderBy('id')->get()->toArray(), 'legacy rows untouched');
        $this->assertEquals($catalogBefore, DB::table('ref.employment_status_details')->orderBy('code')->get(['code', 'is_active', 'version'])->toArray(), 'catalog untouched (still active)');
        $this->assertSame($intentBefore, DB::table('hr.return_intention_periods')->count(), 'no intention converted from the legacy row');
        $this->assertSame(0, DB::table('hr.employment_status_periods as p')->join('ref.employment_status_details as d', 'd.id', '=', 'p.status_detail_id')->where('p.employment_relationship_id', $rel->id)->where('d.code', 'on_duty')->count(), 'no on_duty fabricated');
    }

    public function test_the_retirement_migration_deactivates_but_never_deletes_and_is_reversible(): void
    {
        DB::table('ref.employment_status_details')->whereIn('code', ['wants_to_return', 'does_not_want_to_return'])->update(['is_active' => true]);
        $migration = $this->migrationFile('2026_10_15_000003_retire_legacy_return_intention_status_details');

        $migration->up();
        $this->assertSame(0, DB::table('ref.employment_status_details')->whereIn('code', ['wants_to_return', 'does_not_want_to_return'])->where('is_active', true)->count());
        $this->assertSame(13, DB::table('ref.employment_status_details')->count(), 'nothing deleted');
        $this->assertSame(11, DB::table('ref.employment_status_details')->where('is_active', true)->count(), 'every other status unchanged');

        $migration->down();
        $this->assertSame(2, DB::table('ref.employment_status_details')->whereIn('code', ['wants_to_return', 'does_not_want_to_return'])->where('is_active', true)->count());
        $migration->up();
    }

    // ---- S27 ---------------------------------------------------------------------------------

    public function test_s27_exposes_status_and_return_intention_independently_without_affecting_workforce_participation(): void
    {
        [$person, $rel] = $this->emp();
        [$person2, $rel2] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-10-01');
        $this->recStatus($person2, $rel2, 'traveling', '2026-10-01');
        $this->intent($rel, 'WANTS_TO_RETURN', '2026-10-05', '2026-11-05');

        $row = fn ($r, string $d) => collect(app(ListReportingPopulationAsOf::class)($d, [$r->id]))->all();

        $with = $row($rel, '2026-10-10');
        $this->assertCount(1, $with, 'the join never multiplies relationships');
        $this->assertSame('traveling', $with[0]->statusDetailCode);
        $this->assertSame('WANTS_TO_RETURN', $with[0]->returnIntention);
        $this->assertNotNull($with[0]->returnIntentionPeriodId);

        $without = $row($rel2, '2026-10-10')[0];
        $this->assertNull($without->returnIntention, 'NOT RECORDED is null');
        $this->assertNull($without->returnIntentionPeriodId);
        $this->assertSame($without->participatesInActiveWorkforce, $with[0]->participatesInActiveWorkforce, 'intention does not affect workforce participation');
        $this->assertSame($without->isOngoingRelationship, $with[0]->isOngoingRelationship);

        $after = $row($rel, '2026-11-05')[0];
        $this->assertNull($after->returnIntention, 'expired intention is NOT RECORDED, status unchanged');
        $this->assertSame('traveling', $after->statusDetailCode);
    }

    // ---- API ---------------------------------------------------------------------------------

    public function test_api_record_history_and_effective_as_of(): void
    {
        $this->actingAsHrAdministrator();
        $this->app->instance(BusinessDateClock::class, $clock = new FixedBusinessDateClock('2026-10-15'));
        [$person, $rel] = $this->emp();

        $this->getJson($this->url($person, $rel, 'return-intention'))->assertOk()
            ->assertJsonPath('as_of', '2026-10-15')->assertJsonPath('return_intention', null);

        $this->postJson($this->url($person, $rel, 'return-intention-periods'), ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01', 'effective_to' => '2026-11-01'])
            ->assertStatus(201)->assertJsonPath('intention', 'WANTS_TO_RETURN')->assertJsonPath('effective_to', '2026-11-01');

        $this->getJson($this->url($person, $rel, 'return-intention'))->assertOk()
            ->assertJsonPath('return_intention.intention', 'WANTS_TO_RETURN')->assertJsonPath('return_intention.period_id', fn ($v) => $v !== null);
        $clock->on('2026-11-01');
        $this->getJson($this->url($person, $rel, 'return-intention'))->assertOk()->assertJsonPath('return_intention', null);
        $this->getJson($this->url($person, $rel, 'return-intention').'?as_of=2026-10-02')->assertOk()->assertJsonPath('return_intention.intention', 'WANTS_TO_RETURN');
        $this->getJson($this->url($person, $rel, 'return-intention-periods'))->assertOk()->assertJsonCount(1);
        $this->getJson($this->url($person, $rel, 'status-periods'))->assertOk()->assertJsonCount(0);
    }

    public function test_api_rejects_invalid_input_and_writes_nothing(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $url = $this->url($person, $rel, 'return-intention-periods');
        $good = ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01'];

        $this->postJson($url, ['intention' => 'NOT_RECORDED'] + $good)->assertStatus(422)->assertJsonValidationErrors('intention');
        $this->postJson($url, ['intention' => 'on_duty'] + $good)->assertStatus(422)->assertJsonValidationErrors('intention');
        $this->postJson($url, ['effective_from' => 'nope'] + $good)->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->postJson($url, $good + ['effective_to' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('effective_to');
        $this->postJson($url, $good + ['effective_to' => '2026-11-01T10:00:00'])->assertStatus(422)->assertJsonValidationErrors('effective_to');
        foreach (['end_date', 'effective_until', 'duration_days', 'duration', 'is_temporary', 'return_date', 'expected_return_date', 'auto_return'] as $key) {
            $this->postJson($url, $good + [$key => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors($key);
        }
        $this->postJson($url, ['effective_from' => '2025-12-31'] + $good)->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->assertSame([], $this->intentRows($rel));

        $this->postJson($url, $good)->assertStatus(201);
        $this->postJson($url, ['effective_from' => '2026-09-01'] + $good)->assertStatus(422); // ambiguous backdated
        $this->assertCount(1, $this->intentRows($rel));
    }

    public function test_api_ended_relationship_is_409_and_no_patch_or_delete_exists(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $url = $this->url($person, $rel, 'return-intention-periods');
        $this->postJson($url, ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01'])->assertStatus(201);
        $periodId = DB::table('hr.return_intention_periods')->value('id');
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-11-01', false);

        $this->postJson($url, ['intention' => 'DOES_NOT_WANT_TO_RETURN', 'effective_from' => '2026-10-20'])->assertStatus(409);
        $this->patchJson("$url/$periodId", ['intention' => 'DOES_NOT_WANT_TO_RETURN'])->assertStatus(404);
        $this->deleteJson("$url/$periodId")->assertStatus(404);
        $this->patchJson($url, [])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);
    }

    public function test_api_permissions_are_independent_from_the_status_permissions(): void
    {
        $person = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person);

        $statusOnly = $this->createPrincipal();
        $this->assignRole($statusOnly, $this->createRoleWithPermissions(['hr.employment_status_periods.view', 'hr.employment_status_periods.record']));
        $this->actingAs($statusOnly, 'web');
        $this->getJson($this->url($person, $rel, 'return-intention-periods'))->assertStatus(403);
        $this->getJson($this->url($person, $rel, 'return-intention'))->assertStatus(403);
        $this->postJson($this->url($person, $rel, 'return-intention-periods'), ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01'])->assertStatus(403);

        $recordOnly = $this->createPrincipal();
        $this->assignRole($recordOnly, $this->createRoleWithPermissions(['hr.return_intention_periods.record']));
        $this->actingAs($recordOnly, 'web');
        $this->getJson($this->url($person, $rel, 'return-intention-periods'))->assertStatus(403);

        $viewOnly = $this->createPrincipal();
        $this->assignRole($viewOnly, $this->createRoleWithPermissions(['hr.return_intention_periods.view']));
        $this->actingAs($viewOnly, 'web');
        $this->getJson($this->url($person, $rel, 'return-intention-periods'))->assertOk();
        $this->postJson($this->url($person, $rel, 'return-intention-periods'), ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01'])->assertStatus(403);
    }

    public function test_api_idor_and_audit(): void
    {
        $this->actingAsHrAdministrator();
        $personA = $this->createPersonRecord('9998887771');
        $personB = $this->createPersonRecord();
        $relForB = $this->createEmploymentRelationship($personB);
        $this->getJson($this->url($personA, $relForB, 'return-intention-periods'))->assertStatus(404);
        $this->getJson($this->url($personA, $relForB, 'return-intention'))->assertStatus(404);
        $this->postJson($this->url($personA, $relForB, 'return-intention-periods'), ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01'])->assertStatus(404);

        $rel = $this->createEmploymentRelationship($personA);
        $count = DB::table('audit.audit_entries')->where('action', 'hr.return_intention_period.record')->count();
        $this->postJson($this->url($personA, $rel, 'return-intention-periods'), ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-01', 'effective_to' => '2026-11-01'])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.return_intention_period.record');
        $this->assertSame($count + 1, DB::table('audit.audit_entries')->where('action', 'hr.return_intention_period.record')->count());
        $this->assertSame('hr_return_intention_period', $entry->target_type);
        $encoded = json_encode([$entry->changes, $entry->metadata]);
        $this->assertStringNotContainsString('9998887771', $encoded);
        $this->assertStringContainsString('WANTS_TO_RETURN', $encoded);
        $this->assertStringContainsString('2026-11-01', $encoded);

        // No audit event merely because time passes beyond effective_to.
        $this->app->instance(BusinessDateClock::class, new FixedBusinessDateClock('2027-06-01'));
        $this->getJson($this->url($personA, $rel, 'return-intention'))->assertOk()->assertJsonPath('return_intention', null);
        $this->assertSame($count + 1, DB::table('audit.audit_entries')->where('action', 'hr.return_intention_period.record')->count());
    }

    public function test_status_and_return_intention_recording_through_the_api_are_independent(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $this->postJson($this->url($person, $rel, 'status-periods'), ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-01'])->assertStatus(201);
        $this->postJson($this->url($person, $rel, 'return-intention-periods'), ['intention' => 'WANTS_TO_RETURN', 'effective_from' => '2026-10-05'])->assertStatus(201);
        $this->postJson($this->url($person, $rel, 'status-periods'), ['status_detail_code' => 'captive', 'effective_from' => '2026-11-01'])->assertStatus(201);

        $this->getJson($this->url($person, $rel, 'effective-status').'?as_of=2026-11-10')->assertOk()->assertJsonPath('status.status_detail_code', 'captive');
        $this->getJson($this->url($person, $rel, 'return-intention').'?as_of=2026-11-10')->assertOk()->assertJsonPath('return_intention.intention', 'WANTS_TO_RETURN');
        $this->assertCount(2, $this->statusRows($rel));
        $this->assertCount(1, $this->intentRows($rel));
    }
}
