<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentJobTitlePeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPersonQualification;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentContractForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentSpecialtyForRelationshipAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentSpecialtyException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentSpecialtyPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentSpecialtyPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Commands\DeactivateSpecialty;
use App\Modules\Reference\Application\Commands\DefineContractTypePopulationMappingPeriod;
use App\Modules\Reference\Application\Commands\DefineSpecialtyCadreCategoryMappingPeriod;
use App\Modules\Reference\Application\Queries\ResolveContractTypePopulationCategoryAsOf;
use App\Modules\Reference\Application\Queries\ResolveSpecialtyCadreCategoryAsOf;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S26 Employee Specialty History Foundation
 * (docs/employee-specialty-history-foundation-specification.md, ADR-S26-001, CA-S26-01): the
 * specialty stream of an Employment Relationship (either appointment type) — recording, specialty
 * change as temporal closure (including the CA-S26-01 same-specialty re-record), as-of resolution,
 * PostgreSQL integrity, the active-at-command-time reference rule, relationship-end /
 * status-triggered coherence, reappointment, independence from PersonQualification and the other
 * employment streams, plain hr.* RBAC, audit, the S06 specialty→cadre as-of chain, and S26 scope
 * guards. ref.specialties has no seeded content (S25), so every test uses synthetic specialties.
 * Cross-session concurrency lives in ConcurrencyTest. Derived from the S22 foundation test.
 */
class EmployeeSpecialtyHistoryFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A. Basic
    // ---------------------------------------------------------------------

    public function test_both_contract_and_permanent_relationships_can_have_a_specialty(): void
    {
        foreach (['contract', 'permanent'] as $typeCode) {
            [, $relationship] = $this->personAndRelationship($typeCode);
            $specialty = $this->createSyntheticSpecialty();

            $period = $this->record($relationship, $specialty, '2026-02-01');

            $this->assertSame($specialty->id, $period->specialty_id, "{$typeCode} relationship");
            $this->assertNull($period->effective_to);
        }
    }

    public function test_a_relationship_without_a_recorded_title_is_valid_and_unresolved(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->assertSame(0, $this->periodCount($relationship), 'no specialty is fabricated with the relationship');
        $this->assertNull($this->asOf($relationship, '2026-01-01'));
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonCount(0);
    }

    public function test_store_returns_201_and_index_lists_history_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $a = $this->createSyntheticSpecialty();
        $b = $this->createSyntheticSpecialty();

        $this->postJson($this->url($person, $relationship), ['specialty_id' => $a->id, 'effective_from' => '2026-01-01'])
            ->assertStatus(201)
            ->assertJsonPath('specialty_id', $a->id)
            ->assertJsonPath('effective_from', '2026-01-01')
            ->assertJsonPath('effective_to', null);
        $this->postJson($this->url($person, $relationship), ['specialty_id' => $b->id, 'effective_from' => '2026-07-01'])
            ->assertStatus(201);

        $response = $this->getJson($this->url($person, $relationship))->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertSame($b->id, $response->json('0.specialty_id'));
        $this->assertSame($a->id, $response->json('1.specialty_id'));
        $this->assertSame('2026-07-01', $response->json('1.effective_to'));
    }

    public function test_as_of_resolution_uses_history_not_the_current_title(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticSpecialty();
        $b = $this->createSyntheticSpecialty();
        $this->record($relationship, $a, '2026-02-01');
        $this->record($relationship, $b, '2026-07-01');

        $this->assertNull($this->asOf($relationship, '2026-01-31'), 'UNRESOLVED before any specialty — never a guess');
        $this->assertSame($a->id, $this->asOf($relationship, '2026-06-30')?->specialty_id, 'a past date resolves the specialty held then, not today\'s');
        $this->assertSame($b->id, $this->asOf($relationship, '2026-07-01')?->specialty_id, 'half-open boundary belongs to the new specialty');
        $this->assertSame($b->id, $this->asOf($relationship, Carbon::parse('2030-01-01'))?->specialty_id);
    }

    // ---------------------------------------------------------------------
    // B. Temporal
    // ---------------------------------------------------------------------

    public function test_a_title_may_start_on_the_relationship_start_but_never_before(): void
    {
        $relationship = $this->relationship('2026-01-01');
        $specialty = $this->createSyntheticSpecialty();

        try {
            $this->record($relationship, $specialty, '2025-12-31');
            $this->fail('a specialty may never predate its relationship');
        } catch (InvalidEmploymentSpecialtyPeriodDateException) {
        }

        $this->record($relationship, $specialty, '2026-01-01');
        $this->assertSame($specialty->id, $this->asOf($relationship, '2026-01-01')?->specialty_id);
    }

    public function test_a_future_title_does_not_become_current_early(): void
    {
        $relationship = $this->relationship();
        $current = $this->createSyntheticSpecialty();
        $future = $this->createSyntheticSpecialty();
        $today = Carbon::today();
        $futureFrom = $today->copy()->addMonths(4)->toDateString();

        $this->record($relationship, $current, '2026-01-01');
        $this->record($relationship, $future, $futureFrom);

        $this->assertSame($current->id, $this->asOf($relationship, $today)?->specialty_id);
        $this->assertSame($future->id, $this->asOf($relationship, $futureFrom)?->specialty_id);
    }

    public function test_a_title_change_temporally_closes_the_previous_period_and_preserves_it(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticSpecialty();
        $first = $this->record($relationship, $a, '2026-01-01');
        $firstId = $first->id;

        $second = $this->record($relationship, $this->createSyntheticSpecialty(), '2026-05-01');

        $first->refresh();
        $this->assertSame($firstId, $first->id);
        $this->assertSame($a->id, $first->specialty_id, 'the historical specialty is never overwritten');
        $this->assertSame('2026-01-01', $first->effective_from->toDateString());
        $this->assertSame('2026-05-01', $first->effective_to->toDateString(), 'temporal closure at the change date — adjacent, no overlap');
        $this->assertSame('2026-05-01', $second->effective_from->toDateString());
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_a_genuine_gap_in_history_is_preserved(): void
    {
        $relationship = $this->relationship();
        $specialty = $this->createSyntheticSpecialty();
        // A closed period with a genuine gap after it (only representable through imported data).
        $this->insertRaw($relationship, $specialty, '2026-01-01', '2026-03-01');

        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-06-01');

        $this->assertSame('2026-03-01', (string) DB::table('hr.employment_specialty_periods')
            ->where('employment_relationship_id', $relationship->id)->where('effective_from', '2026-01-01')->value('effective_to'), 'the earlier end is not stretched to close the gap');
        $this->assertNull($this->asOf($relationship, '2026-04-15'), 'the gap stays UNRESOLVED');
    }

    public function test_backdated_or_same_date_changes_are_rejected_and_history_is_untouched(): void
    {
        $relationship = $this->relationship();
        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-02-01');
        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-06-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-04-01', '2026-06-01', '2026-01-01'] as $from) {
            try {
                $this->record($relationship, $this->createSyntheticSpecialty(), $from);
                $this->fail("{$from} conflicts with later history");
            } catch (InvalidEmploymentSpecialtyPeriodDateException) {
            }
        }

        $this->assertSame($before, $this->snapshot($relationship));
    }

    public function test_invalid_payloads_are_rejected_with_422(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), [])
            ->assertStatus(422)->assertJsonValidationErrors(['specialty_id', 'effective_from']);
        $this->postJson($this->url($person, $relationship), ['specialty_id' => 'x', 'effective_from' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['specialty_id', 'effective_from']);
        $this->assertSame(0, $this->periodCount($relationship));
    }

    // ---------------------------------------------------------------------
    // C. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_direct_overlap_is_rejected_while_adjacent_periods_are_accepted(): void
    {
        $relationship = $this->relationship();
        $specialty = $this->createSyntheticSpecialty();
        $this->insertRaw($relationship, $specialty, '2026-01-01', '2026-06-01');

        $error = $this->queryError(fn () => $this->insertRaw($relationship, $specialty, '2026-05-01', null));
        $this->assertTrue(Errors::isExclusionViolation($error));

        $this->insertRaw($relationship, $specialty, '2026-06-01', null);
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_invalid_intervals_are_rejected_by_the_check_constraint(): void
    {
        $relationship = $this->relationship();
        $specialty = $this->createSyntheticSpecialty();

        foreach ([['2026-06-01', '2026-06-01'], ['2026-06-01', '2026-05-01']] as [$from, $to]) {
            $error = $this->queryError(fn () => $this->insertRaw($relationship, $specialty, $from, $to));
            $this->assertTrue(Errors::isCheckViolation($error), "[{$from}, {$to}) must be rejected");
        }
    }

    public function test_different_relationships_are_independent_and_may_share_a_specialty(): void
    {
        $specialty = $this->createSyntheticSpecialty();
        $r1 = $this->relationship();
        $r2 = $this->relationship();

        $this->insertRaw($r1, $specialty, '2026-01-01', null);
        $this->insertRaw($r2, $specialty, '2026-01-01', null);
        $this->record($r1, $this->createSyntheticSpecialty(), '2026-03-01');

        $this->assertSame($specialty->id, $this->asOf($r2, '2026-06-01')?->specialty_id, 'the exclusion constraint is per relationship only');
        $this->assertNotSame($specialty->id, $this->asOf($r1, '2026-06-01')?->specialty_id);
    }

    public function test_the_api_ignores_a_client_supplied_knowledge_state_or_extra_column(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $response = $this->postJson($this->url($person, $relationship), [
            'specialty_id' => $this->createSyntheticSpecialty()->id,
            'effective_from' => '2026-02-01',
            'start_knowledge_state' => 'UNKNOWN_LEGACY',
            'qualification_id' => (string) Str::uuid7(),
            'is_primary' => true,
        ])->assertStatus(201);

        $this->assertSame(['id', 'employment_relationship_id', 'specialty_id', 'effective_from', 'effective_to'], array_keys($response->json()));
    }

    public function test_foreign_keys_and_restrict_delete_protect_history(): void
    {
        $relationship = $this->relationship();
        $specialty = $this->createSyntheticSpecialty();

        $error = $this->queryError(fn () => DB::table('hr.employment_specialty_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => (string) Str::uuid7(), 'specialty_id' => $specialty->id,
            'effective_from' => '2026-02-01', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.employment_specialty_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $relationship->id, 'specialty_id' => (string) Str::uuid7(),
            'effective_from' => '2026-02-01', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $this->record($relationship, $specialty, '2026-02-01');
        $error = $this->queryError(fn () => DB::table('ref.specialties')->where('id', $specialty->id)->delete());
        $this->assertTrue(Errors::isForeignKeyViolation($error), 'a referenced specialty cannot be hard-deleted');
    }

    public function test_table_shape_has_no_speculative_columns_and_nothing_is_stored_on_person_or_relationship(): void
    {
        $types = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_specialty_periods')
            ->pluck('data_type', 'column_name')->all();
        ksort($types);

        $this->assertSame([
            'created_at' => 'timestamp with time zone',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'employment_relationship_id' => 'uuid',
            'id' => 'uuid',
            'specialty_id' => 'uuid',
        ], $types, 'no person_id, qualification_id, primary flag, percentage/allocation, notes, text snapshot, decision, import metadata, cadre or organizational column');

        foreach (['persons', 'employment_relationships', 'person_qualifications'] as $table) {
            foreach (DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', $table)->pluck('column_name') as $column) {
                $this->assertStringNotContainsString('specialt', $column, "hr.{$table} carries no specialty column — current specialty is derived from history");
            }
        }
    }

    // ---------------------------------------------------------------------
    // D. Reference
    // ---------------------------------------------------------------------

    public function test_a_nonexistent_title_is_404_and_an_inactive_title_is_rejected(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), ['specialty_id' => (string) Str::uuid7(), 'effective_from' => '2026-02-01'])
            ->assertNotFound();
        $this->postJson($this->url($person, $relationship), ['specialty_id' => $this->createSyntheticSpecialty(active: false)->id, 'effective_from' => '2026-02-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['specialty_id']);

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->expectException(InvalidEmploymentSpecialtyException::class);
        $this->record($relationship, $this->createSyntheticSpecialty(active: false), '2026-02-01');
    }

    public function test_a_title_deactivated_later_keeps_history_intact_and_readable(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $specialty = $this->createSyntheticSpecialty();
        $period = $this->record($relationship, $specialty, '2026-02-01');
        $before = $this->snapshot($relationship);

        app(DeactivateSpecialty::class)->handle($specialty, $specialty->version);

        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame($specialty->id, $this->asOf($relationship, '2026-03-01')?->specialty_id);
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonPath('0.id', $period->id);
        $this->postJson($this->url($person, $relationship), ['specialty_id' => $specialty->id, 'effective_from' => '2026-06-01'])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // E. Lifecycle
    // ---------------------------------------------------------------------

    public function test_relationship_end_closes_the_open_title_and_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $earlier = $this->record($relationship, $this->createSyntheticSpecialty(), '2026-01-01');
        $open = $this->record($relationship, $this->createSyntheticSpecialty(), '2026-04-01');

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
            'expected_version' => $relationship->version, 'effective_to' => '2026-09-01', 'is_terminal' => false,
        ])->assertOk();

        $this->assertSame('2026-09-01', $open->refresh()->effective_to->toDateString());
        $this->assertSame('2026-04-01', $earlier->refresh()->effective_to->toDateString());
        $this->assertSpecialtiesWithinRelationship($relationship->refresh());
        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertTrue($entry->metadata['employment_specialty_period_closed_as_consequence'] ?? false);
    }

    public function test_a_title_that_already_ended_is_not_extended_by_the_relationship_end(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, $this->createSyntheticSpecialty(), '2026-02-01', '2026-04-01');

        $this->end($person, $relationship, '2026-09-01');

        $this->assertSame('2026-04-01', (string) DB::table('hr.employment_specialty_periods')->where('employment_relationship_id', $relationship->id)->value('effective_to'));
        $this->assertArrayNotHasKey('employment_specialty_period_closed_as_consequence', $this->latestAuditEntryFor('hr.employment_relationship.end')?->metadata ?? []);
    }

    public function test_a_relationship_end_before_a_future_title_is_rejected_atomically(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-02-01');
        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-10-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-09-01', '2026-10-01'] as $endDate) {
            $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
                'expected_version' => $relationship->version, 'effective_to' => $endDate, 'is_terminal' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        }

        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_closed_imported_title_beyond_the_end_date_rejects_the_end(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, $this->createSyntheticSpecialty(), '2026-02-01', '2026-11-01');

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-09-01', false);
    }

    public function test_an_ended_relationship_rejects_a_new_title(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->end($person, $relationship, '2026-06-01');

        $this->postJson($this->url($person, $relationship->refresh()), ['specialty_id' => $this->createSyntheticSpecialty()->id, 'effective_from' => '2026-03-01'])
            ->assertStatus(409);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-03-01');
    }

    public function test_status_triggered_termination_closes_the_title_and_the_triggering_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->createSyntheticSpecialty(), '2026-02-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'resigned', 'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());
        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertTrue($entry->metadata['employment_specialty_period_closed_as_consequence'] ?? false);
        $this->assertSame($auditBefore + 1, $this->auditEntriesCount(), 'one entry, no duplicate consequence event');
    }

    public function test_terminal_and_rejected_status_triggered_endings_stay_coherent(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->createSyntheticSpecialty(), '2026-02-01');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('deceased'), '2026-10-15');
        $this->assertTrue($person->refresh()->is_terminal);
        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());

        $this->actingAsHrAdministrator();
        [$p2, $r2] = $this->personAndRelationship();
        $this->record($r2, $this->createSyntheticSpecialty(), '2026-02-01');
        $this->record($r2, $this->createSyntheticSpecialty(), '2026-11-01');
        $before = $this->snapshot($r2);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$p2->id}/employment-relationships/{$r2->id}/status-periods", [
            'status_detail_code' => 'resigned', 'effective_from' => '2026-10-15',
        ])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($r2));
        $this->assertSame('NOT_APPLICABLE', $r2->refresh()->end_knowledge_state);
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no false success audit for a rolled-back termination');
    }

    // ---------------------------------------------------------------------
    // F. Reappointment
    // ---------------------------------------------------------------------

    public function test_reappointment_does_not_inherit_the_old_title_and_can_receive_its_own(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $old] = $this->personAndRelationship();
        $specialty = $this->createSyntheticSpecialty();
        $oldPeriod = $this->record($old, $specialty, '2026-01-01');
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(0, $this->periodCount($new), 'no carry-forward because the Person is the same');
        $this->assertNull($this->asOf($new, '2026-08-01'));
        $this->assertSame($old->id, $oldPeriod->refresh()->employment_relationship_id);
        $this->assertSame('2026-06-01', $oldPeriod->effective_to->toDateString());

        $this->record($new, $specialty, '2026-08-01');
        $this->assertSame(1, $this->periodCount($new));
        $this->assertSame(1, $this->periodCount($old));
    }

    // ---------------------------------------------------------------------
    // G. Separation
    // ---------------------------------------------------------------------

    public function test_recording_a_specialty_changes_no_other_employment_stream_qualification_catalog_or_mapping(): void
    {
        [$person, $relationship] = $this->personAndRelationship('contract');
        $category = app(RecordEmploymentCategoryPeriod::class)->handle($relationship, $this->employmentCategory('grade_3'), '2026-01-01');
        $contract = app(RecordEmploymentContractPeriod::class)->handle($relationship, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');
        $jobTitle = app(RecordEmploymentJobTitlePeriod::class)->handle($relationship, $this->createSyntheticJobTitle(), '2026-01-01');
        $placement = $this->recordPlacement($relationship, $this->createUnit(), '2026-01-15');
        $qualification = app(RecordPersonQualification::class)->handle($person, $this->createSyntheticAcademicDegree(), null);
        $before = $this->otherStreams($relationship);
        $specialty = $this->createSyntheticSpecialty();
        $catalogBefore = (array) DB::table('ref.specialties')->where('id', $specialty->id)->first();
        $mappingsBefore = DB::table('ref.specialty_cadre_category_mappings')->count();
        $qualificationBefore = (array) DB::table('hr.person_qualifications')->where('id', $qualification->id)->first();

        $this->record($relationship, $specialty, '2026-02-01');
        $this->record($relationship, $this->createSyntheticSpecialty(), '2026-05-01');

        $this->assertSame($before, $this->otherStreams($relationship), 'category, contract, job title, placement, status and movement history untouched');
        $this->assertNull($category->refresh()->effective_to);
        $this->assertSame('2027-01-01', $contract->refresh()->effective_to->toDateString());
        $this->assertNull($jobTitle->refresh()->effective_to);
        $this->assertNull($placement->refresh()->effective_to);
        $this->assertEquals($qualificationBefore, (array) DB::table('hr.person_qualifications')->where('id', $qualification->id)->first(),
            'ADR-S26-001 E: a PersonQualification is never linked, derived or synchronised');
        $this->assertEquals($catalogBefore, (array) DB::table('ref.specialties')->where('id', $specialty->id)->first(),
            'the S25 catalog row is never created, renamed, activated or deactivated by S26');
        $this->assertSame($mappingsBefore, DB::table('ref.specialty_cadre_category_mappings')->count(), 'no automatic cadre mapping');
    }

    // ---------------------------------------------------------------------
    // G2. CA-S26-01 — same-specialty re-recording follows S20/S22
    // ---------------------------------------------------------------------

    public function test_ca_s26_01_recording_the_same_specialty_again_closes_and_creates_an_adjacent_period(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $a = $this->createSyntheticSpecialty();

        $first = $this->postJson($this->url($person, $relationship), ['specialty_id' => $a->id, 'effective_from' => '2026-01-01'])->assertStatus(201);
        $auditBefore = $this->auditEntriesCount();

        $second = $this->postJson($this->url($person, $relationship), ['specialty_id' => $a->id, 'effective_from' => '2026-06-01'])
            ->assertStatus(201)
            ->assertJsonPath('specialty_id', $a->id)
            ->assertJsonPath('effective_from', '2026-06-01')
            ->assertJsonPath('effective_to', null);

        $this->assertNotSame($first->json('id'), $second->json('id'), 'a new period — no 200 idempotent no-op, no suppression');
        $this->assertSame([
            ['id' => $first->json('id'), 'specialty_id' => $a->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01'],
            ['id' => $second->json('id'), 'specialty_id' => $a->id, 'effective_from' => '2026-06-01', 'effective_to' => null],
        ], $this->snapshot($relationship));

        $this->assertSame($auditBefore + 1, $this->auditEntriesCount());
        $entry = $this->latestAuditEntryFor('hr.employment_specialty_period.record');
        $this->assertSame($second->json('id'), $entry->target_id);
        $this->assertEquals(['employment_relationship_id' => $relationship->id, 'specialty_id' => $a->id, 'effective_from' => '2026-06-01'], $entry->changes);
        $this->assertEquals(['specialty_code' => $a->code, 'previous_period_id' => $first->json('id'), 'previous_period_closed_at' => '2026-06-01'], $entry->metadata,
            'audited exactly like any other successful record');
    }

    public function test_ca_s26_01_an_older_historical_specialty_may_be_recorded_again(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticSpecialty();
        $b = $this->createSyntheticSpecialty();

        $this->record($relationship, $a, '2026-01-01');
        $this->record($relationship, $b, '2026-03-01');
        $this->record($relationship, $a, '2026-06-01');

        $this->assertSame([$a->id, $b->id, $a->id], array_column($this->snapshot($relationship), 'specialty_id'));
        $this->assertSame($b->id, $this->asOf($relationship, '2026-05-31')?->specialty_id);
        $this->assertSame($a->id, $this->asOf($relationship, '2026-06-01')?->specialty_id);
    }

    public function test_ca_s26_01_same_specialty_still_obeys_the_temporal_ordering_rules(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticSpecialty();
        $this->record($relationship, $a, '2026-06-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-06-01', '2026-03-01'] as $from) {
            try {
                $this->record($relationship, $a, $from);
                $this->fail("{$from} is not strictly after the latest period's start");
            } catch (InvalidEmploymentSpecialtyPeriodDateException) {
            }
        }

        $this->assertSame($before, $this->snapshot($relationship), 'validity is decided by temporal rules, not by the specialty value');
    }

    // ---------------------------------------------------------------------
    // G3. S06 reporting compatibility (R1 / R5 readiness — no report built)
    // ---------------------------------------------------------------------

    public function test_r1_chain_resolves_relationship_to_specialty_to_cadre_category_as_of_a_date(): void
    {
        $relationship = $this->relationship();
        $a = $this->createSyntheticSpecialty();
        $b = $this->createSyntheticSpecialty();
        $doctors = MonthlyCadreCategory::query()->where('code', 'doctors')->firstOrFail();
        $nursing = MonthlyCadreCategory::query()->where('code', 'nursing')->firstOrFail();
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($a, $doctors, '2025-01-01', null);
        app(DefineSpecialtyCadreCategoryMappingPeriod::class)->handle($b, $nursing, '2025-01-01', null);

        $this->record($relationship, $a, '2026-01-01');
        $this->record($relationship, $b, '2026-07-01');

        $this->assertSame($doctors->id, $this->cadreAsOf($relationship, '2026-03-01')?->id, 'specialty A → doctors');
        $this->assertSame($nursing->id, $this->cadreAsOf($relationship, '2026-09-01')?->id, 'after the change → nursing');
        $this->assertNull($this->cadreAsOf($relationship, '2025-12-31'), 'no specialty yet → UNRESOLVED');
    }

    public function test_an_unmapped_specialty_resolves_to_null_never_other(): void
    {
        $relationship = $this->relationship();
        $unmapped = $this->createSyntheticSpecialty();
        $mappingsBefore = DB::table('ref.specialty_cadre_category_mappings')->count();

        $this->record($relationship, $unmapped, '2026-01-01');

        $this->assertSame($unmapped->id, $this->asOf($relationship, '2026-03-01')?->specialty_id);
        $this->assertNull($this->cadreAsOf($relationship, '2026-03-01'), 'UNRESOLVED — never an automatic Other');
        $this->assertSame($mappingsBefore, DB::table('ref.specialty_cadre_category_mappings')->count());
        $this->assertSame(0, DB::table('ref.specialties')->whereRaw('lower(code) like ?', ['%other%'])->count(), 'no Other specialty exists');
    }

    public function test_r5_chain_resolves_contract_population_and_specialty_as_of_a_date(): void
    {
        [, $relationship] = $this->personAndRelationship('contract');
        $contractType = $this->createSyntheticContractType();
        $volunteers = ContractBasedPopulationCategory::query()->where('code', 'volunteer_unemployment')->firstOrFail();
        app(DefineContractTypePopulationMappingPeriod::class)->handle($contractType, $volunteers, '2025-01-01', null);
        app(RecordEmploymentContractPeriod::class)->handle($relationship, $contractType, '2026-01-01', '2027-01-01');
        $specialty = $this->createSyntheticSpecialty();
        $this->record($relationship, $specialty, '2026-02-01');

        $contract = app(ResolveEmploymentContractForRelationshipAsOf::class)($relationship, '2026-04-01');
        $population = app(ResolveContractTypePopulationCategoryAsOf::class)(ContractType::query()->findOrFail($contract->contract_type_id), '2026-04-01');

        $this->assertSame($volunteers->id, $population?->id);
        $this->assertSame($specialty->id, $this->asOf($relationship, '2026-04-01')?->specialty_id);
    }

    // ---------------------------------------------------------------------
    // G4. S26 scope guards
    // ---------------------------------------------------------------------

    public function test_s26_introduces_no_person_qualification_primary_allocation_seed_report_or_import_concept(): void
    {
        $specialtyColumns = DB::table('information_schema.columns')->where('column_name', 'like', '%specialt%')
            ->whereNotIn('table_schema', ['pg_catalog', 'information_schema'])
            ->get(['table_schema', 'table_name', 'column_name'])
            ->map(fn ($c) => "{$c->table_schema}.{$c->table_name}.{$c->column_name}")->sort()->values()->all();
        $this->assertSame([
            'hr.employment_specialty_periods.specialty_id',
            'ref.specialty_cadre_category_mappings.specialty_id',
        ], $specialtyColumns, 'no Person/qualification specialty column, no cached cadre');

        $this->assertSame(0, DB::table('ref.specialties')->count(), 'no specialty seed');
        $this->assertSame(0, DB::table('information_schema.tables')->where('table_schema', 'reporting')->count(), 'no Report 1 / Report 5');
        foreach (['import', 'staging'] as $schema) {
            $this->assertSame(0, DB::table('information_schema.tables')->where('table_schema', $schema)->count(), "no {$schema} engine");
        }
        // 'work_schedule'/'WorkSchedule' were removed in S29 (docs/work-schedule-foundation-specification.md),
        // which authorizes that domain itself.
        foreach (['promotion', 'job_desc', 'supervisory_assignment', 'partial_secondment'] as $forbidden) {
            $this->assertSame(0, DB::table('information_schema.tables')->where('table_name', 'like', "%{$forbidden}%")->count(), "no {$forbidden} table");
        }
        foreach (['Promotion', 'JobDescription', 'SupervisoryAssignment', 'PartialSecondment', 'Import'] as $forbidden) {
            $this->assertSame([], glob(base_path("app/Modules/*/Application/Commands/*{$forbidden}*.php")), "no {$forbidden} command");
        }
    }

    // ---------------------------------------------------------------------
    // H. Security
    // ---------------------------------------------------------------------

    public function test_authentication_and_hr_permissions_are_enforced(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $payload = fn () => ['specialty_id' => $this->createSyntheticSpecialty()->id, 'effective_from' => '2026-02-01'];

        $this->getJson($this->url($person, $relationship))->assertUnauthorized();
        $this->postJson($this->url($person, $relationship), [])->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();

        $this->principalWithPermissions([Perm::EMPLOYMENT_SPECIALTY_PERIODS_VIEW]);
        $this->getJson($this->url($person, $relationship))->assertOk();
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();

        $this->principalWithPermissions([Perm::EMPLOYMENT_SPECIALTY_PERIODS_RECORD]);
        $this->postJson($this->url($person, $relationship), $payload())->assertStatus(201);
    }

    public function test_reference_and_other_hr_permissions_never_grant_specialty_assignment(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $payload = fn () => ['specialty_id' => $this->createSyntheticSpecialty()->id, 'effective_from' => '2026-02-01'];

        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->getJson('/api/v1/reference/specialties')->assertOk();

        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [
            Perm::EMPLOYMENT_SPECIALTY_PERIODS_VIEW, Perm::EMPLOYMENT_SPECIALTY_PERIODS_RECORD,
        ])));
        $this->postJson($this->url($person, $relationship), $payload())->assertForbidden();

        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_ownership_mismatch_is_404_and_no_patch_or_delete_route_exists(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $stranger = $this->createPersonRecord();

        $this->getJson($this->url($stranger, $relationship))->assertNotFound();
        $this->postJson($this->url($stranger, $relationship), ['specialty_id' => $this->createSyntheticSpecialty()->id, 'effective_from' => '2026-02-01'])->assertNotFound();
        $this->patchJson($this->url($person, $relationship), [])->assertStatus(405);
        $this->deleteJson($this->url($person, $relationship))->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // I. Audit
    // ---------------------------------------------------------------------

    public function test_record_and_change_audits_carry_accurate_temporal_closure_metadata_without_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $a = $this->createSyntheticSpecialty();
        $b = $this->createSyntheticSpecialty();

        $first = $this->postJson($this->url($person, $relationship), ['specialty_id' => $a->id, 'effective_from' => '2026-01-01'])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.employment_specialty_period.record');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_employment_specialty_period', $entry->target_type);
        $this->assertSame($first->json('id'), $entry->target_id);
        $this->assertEquals(['employment_relationship_id' => $relationship->id, 'specialty_id' => $a->id, 'effective_from' => '2026-01-01'], $entry->changes);
        $this->assertEquals(['specialty_code' => $a->code], $entry->metadata);

        $this->postJson($this->url($person, $relationship), ['specialty_id' => $b->id, 'effective_from' => '2026-05-01'])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.employment_specialty_period.record');
        $this->assertSame($first->json('id'), $entry->metadata['previous_period_id']);
        $this->assertSame('2026-05-01', $entry->metadata['previous_period_closed_at']);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    public function test_a_rejected_write_leaves_no_audit_and_no_partial_closure(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $existing = $this->record($relationship, $this->createSyntheticSpecialty(), '2026-05-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), ['specialty_id' => $this->createSyntheticSpecialty()->id, 'effective_from' => '2026-03-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        $this->assertSame($auditBefore, $this->auditEntriesCount());
        $this->assertNull($existing->refresh()->effective_to);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function relationship(string $effectiveFrom = '2026-01-01'): EmploymentRelationship
    {
        return $this->createEmploymentRelationship($this->createPersonRecord(), 'permanent', null, $effectiveFrom);
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function personAndRelationship(string $typeCode = 'permanent'): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $typeCode)];
    }

    private function record(EmploymentRelationship $relationship, Specialty $specialty, string $from): EmploymentSpecialtyPeriod
    {
        return app(RecordEmploymentSpecialtyPeriod::class)->handle($relationship, $specialty, $from);
    }

    private function asOf(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentSpecialtyPeriod
    {
        return app(ResolveEmploymentSpecialtyForRelationshipAsOf::class)($relationship, $date);
    }

    private function cadreAsOf(EmploymentRelationship $relationship, string $date): ?MonthlyCadreCategory
    {
        $period = $this->asOf($relationship, $date);

        return $period === null ? null : app(ResolveSpecialtyCadreCategoryAsOf::class)(Specialty::query()->findOrFail($period->specialty_id), $date);
    }

    private function end(Person $person, EmploymentRelationship $relationship, string $effectiveTo): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $relationship->refresh(), $relationship->version, $effectiveTo, false);
    }

    private function url(Person $person, EmploymentRelationship $relationship): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/employment-specialty-periods";
    }

    private function periodCount(EmploymentRelationship $relationship): int
    {
        return EmploymentSpecialtyPeriod::query()->where('employment_relationship_id', $relationship->id)->count();
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(EmploymentRelationship $relationship): array
    {
        return DB::table('hr.employment_specialty_periods')
            ->where('employment_relationship_id', $relationship->id)
            ->orderBy('effective_from')
            ->get(['id', 'specialty_id', 'effective_from', 'effective_to'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function otherStreams(EmploymentRelationship $relationship): array
    {
        $streams = [];
        foreach (['employment_category_periods', 'employment_contract_periods', 'employment_job_title_periods', 'organizational_placement_periods', 'employment_status_periods', 'workplace_assignment_periods', 'full_secondment_periods'] as $table) {
            $streams[$table] = DB::table("hr.{$table}")->where('employment_relationship_id', $relationship->id)
                ->orderBy('effective_from')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $streams;
    }

    private function insertRaw(EmploymentRelationship $relationship, Specialty $specialty, string $from, ?string $to): void
    {
        DB::table('hr.employment_specialty_periods')->insert([
            'id' => (string) Str::uuid7(),
            'employment_relationship_id' => $relationship->id,
            'specialty_id' => $specialty->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'created_at' => now(),
        ]);
    }

    private function assertSpecialtiesWithinRelationship(EmploymentRelationship $relationship): void
    {
        foreach (EmploymentSpecialtyPeriod::query()->where('employment_relationship_id', $relationship->id)->get() as $period) {
            $this->assertNotNull($period->effective_to, 'no specialty may remain in force after the relationship ended');
            $this->assertTrue($period->effective_from->gte($relationship->effective_from));
            $this->assertTrue($period->effective_to->lte($relationship->effective_to));
        }
    }

    private function queryError(callable $work): QueryException
    {
        try {
            DB::transaction(fn () => $work());
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected a QueryException.');
    }

    private function principalWithPermissions(array $permissionCodes): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions($permissionCodes);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
