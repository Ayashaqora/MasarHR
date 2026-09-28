<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentContractPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentContractForRelationshipAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipNotContractSchemeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractTermException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentContractPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Commands\DeactivateContractType;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S21 Employment Contract Foundation (docs/employment-contract-foundation-specification.md,
 * ADR-S21-001): the contract lifecycle of a CONTRACT Employment Relationship — initial contract,
 * renewal, as-of resolution, temporal/PostgreSQL integrity, the active-at-command-time contract-type
 * rule, relationship-end/status-triggered-termination coherence, reappointment, employee-number
 * non-regression, plain hr.* RBAC, and audit. ref.contract_types has no seeded content (S13 refused
 * to invent values), so every test uses synthetic contract types. Cross-session concurrency lives in
 * ConcurrencyTest. Mirrors EmploymentCategoryHistoryFoundationTest's (S20) conventions.
 */
class EmploymentContractFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A. Basic
    // ---------------------------------------------------------------------

    public function test_a_contract_relationship_records_a_valid_initial_contract(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();

        $period = $this->record($relationship, $type, '2026-01-01', '2027-01-01');

        $this->assertSame($relationship->id, $period->employment_relationship_id);
        $this->assertSame($type->id, $period->contract_type_id);
        $this->assertSame('2026-01-01', $period->effective_from->toDateString());
        $this->assertSame('2027-01-01', $period->contractual_effective_to->toDateString());
        $this->assertSame('2027-01-01', $period->effective_to->toDateString(), 'a known-term contract is valid exactly for its agreed term');
        $this->assertSame('KNOWN', $period->contract_end_knowledge_state);
    }

    public function test_a_permanent_relationship_is_not_forced_to_have_and_cannot_receive_a_contract(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship('permanent');

        $this->assertSame(0, $this->periodCount($relationship), 'no contract is fabricated for permanent employment');
        $this->assertNull($this->asOf($relationship, '2026-06-01'));

        $this->postJson($this->url($person, $relationship), $this->payload())->assertStatus(409);
        $this->assertSame(0, $this->periodCount($relationship));

        $this->expectException(EmploymentRelationshipNotContractSchemeException::class);
        $this->record($relationship, $this->createSyntheticContractType(), '2026-02-01', '2027-02-01');
    }

    /**
     * CA-S21-01: a CONTRACT relationship may temporarily exist without a recorded contract —
     * incomplete HR data, not an indefinite contract. Nothing (contract, type, dates) is fabricated.
     */
    public function test_a_contract_relationship_may_exist_without_a_recorded_contract_and_nothing_is_fabricated(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship('contract');

        $this->assertSame('CONTRACT', $relationship->employee_number_scheme);
        $this->assertSame(0, $this->periodCount($relationship), 'no contract is created implicitly with the relationship');
        $this->assertNull($this->asOf($relationship, '2026-01-01'), 'UNRESOLVED — never an implied indefinite contract');
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonCount(0);
    }

    public function test_store_returns_201_and_the_created_period(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();

        $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => $type->id,
            'effective_from' => '2026-01-01',
            'contractual_effective_to' => '2027-01-01',
        ])->assertStatus(201)
            ->assertJsonPath('employment_relationship_id', $relationship->id)
            ->assertJsonPath('contract_type_id', $type->id)
            ->assertJsonPath('effective_from', '2026-01-01')
            ->assertJsonPath('effective_to', '2027-01-01')
            ->assertJsonPath('contractual_effective_to', '2027-01-01')
            ->assertJsonPath('contract_end_knowledge_state', 'KNOWN');
    }

    public function test_index_returns_the_full_history_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $this->record($relationship, $type, '2026-01-01', '2027-01-01');
        $this->record($relationship, $type, '2027-01-01', '2028-01-01');

        $response = $this->getJson($this->url($person, $relationship))->assertOk();

        $this->assertCount(2, $response->json());
        $this->assertSame('2027-01-01', $response->json('0.effective_from'));
        $this->assertSame('2026-01-01', $response->json('1.effective_from'));
    }

    public function test_as_of_resolution_follows_actual_validity_and_is_unresolved_outside_it(): void
    {
        $relationship = $this->contractRelationship();
        $a = $this->createSyntheticContractType();
        $b = $this->createSyntheticContractType();
        $this->record($relationship, $a, '2026-01-01', '2027-01-01');
        $this->record($relationship, $b, '2027-03-01', '2028-03-01');

        $this->assertNull($this->asOf($relationship, '2025-12-31'));
        $this->assertSame($a->id, $this->asOf($relationship, '2026-01-01')?->contract_type_id);
        $this->assertSame($a->id, $this->asOf($relationship, '2026-12-31')?->contract_type_id, 'a historical date resolves to that date\'s contract type, not today\'s');
        $this->assertNull($this->asOf($relationship, '2027-01-01'), 'half-open: no contract in force on the exclusive term end');
        $this->assertNull($this->asOf($relationship, '2027-02-15'), 'a lapse between an expired term and a later renewal stays visible');
        $this->assertSame($b->id, $this->asOf($relationship, Carbon::parse('2027-03-01'))?->contract_type_id);
    }

    // ---------------------------------------------------------------------
    // B. Temporal / renewal
    // ---------------------------------------------------------------------

    public function test_a_contract_may_start_on_the_relationship_start_date(): void
    {
        $relationship = $this->contractRelationship('2026-01-01');
        $type = $this->createSyntheticContractType();

        $this->record($relationship, $type, '2026-01-01', '2026-07-01');

        $this->assertSame($type->id, $this->asOf($relationship, '2026-01-01')?->contract_type_id);
    }

    public function test_a_contract_starting_before_the_relationship_is_rejected(): void
    {
        $relationship = $this->contractRelationship('2026-01-01');

        try {
            $this->record($relationship, $this->createSyntheticContractType(), '2025-12-31', '2026-12-31');
            $this->fail('a contract may never predate its relationship');
        } catch (InvalidEmploymentContractPeriodDateException) {
        }

        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_an_empty_or_reversed_contractual_term_is_rejected(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();

        foreach (['2026-02-01', '2026-01-15'] as $to) {
            $this->postJson($this->url($person, $relationship), [
                'contract_type_id' => $type->id,
                'effective_from' => '2026-02-01',
                'contractual_effective_to' => $to,
            ])->assertStatus(422)->assertJsonValidationErrors(['contractual_effective_to']);
        }

        $this->postJson($this->url($person, $relationship), [])
            ->assertStatus(422)->assertJsonValidationErrors(['contract_type_id', 'effective_from', 'contractual_effective_to']);

        $this->assertSame(0, $this->periodCount($relationship));

        $this->expectException(InvalidEmploymentContractTermException::class);
        $this->record($relationship, $type, '2026-02-01', '2026-02-01');
    }

    public function test_a_future_contract_does_not_become_current_early(): void
    {
        $relationship = $this->contractRelationship();
        $current = $this->createSyntheticContractType();
        $future = $this->createSyntheticContractType();
        $today = Carbon::today();
        $renewalFrom = $today->copy()->addMonths(3)->toDateString();

        $this->record($relationship, $current, '2026-01-01', $today->copy()->addYear()->toDateString());
        $this->record($relationship, $future, $renewalFrom, $today->copy()->addYears(2)->toDateString());

        $this->assertSame($current->id, $this->asOf($relationship, $today)?->contract_type_id);
        $this->assertSame($future->id, $this->asOf($relationship, $renewalFrom)?->contract_type_id);
    }

    public function test_an_early_renewal_closes_the_previous_validity_but_never_rewrites_its_agreed_term(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();
        $original = $this->record($relationship, $type, '2026-01-01', '2027-01-01');

        $renewal = $this->record($relationship, $type, '2026-10-01', '2027-10-01');

        $originalId = $original->id;
        $original->refresh();
        // Temporal closure, not destructive replacement: identity, type, start and agreed term are
        // preserved; only the actual effective_to is closed at the renewal date.
        $this->assertSame($originalId, $original->id);
        $this->assertSame('2026-01-01', $original->effective_from->toDateString());
        $this->assertSame('2026-10-01', $original->effective_to->toDateString(), 'validity temporally closed at the renewal date — no overlap');
        $this->assertSame('2027-01-01', $original->contractual_effective_to->toDateString(), 'the original agreed term is preserved, not overwritten');
        $this->assertTrue($original->endedBeforeContractualTerm());
        $this->assertSame($type->id, $original->contract_type_id);
        $this->assertSame('2026-10-01', $renewal->effective_from->toDateString());
        $this->assertSame(2, $this->periodCount($relationship), 'renewal adds a period; the previous one stays historical');
    }

    public function test_a_renewal_at_or_after_the_term_end_leaves_the_previous_period_untouched(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();
        $first = $this->record($relationship, $type, '2026-01-01', '2027-01-01');
        $this->record($relationship, $type, '2027-01-01', '2028-01-01');
        $second = EmploymentContractPeriod::query()->where('employment_relationship_id', $relationship->id)->where('effective_from', '2027-01-01')->firstOrFail();
        $this->record($relationship, $type, '2028-03-01', '2029-03-01');

        $this->assertSame('2027-01-01', $first->refresh()->effective_to->toDateString(), 'adjacent renewal: previous period unchanged');
        $this->assertFalse($first->endedBeforeContractualTerm());
        $this->assertSame('2028-01-01', $second->refresh()->effective_to->toDateString(), 'late renewal after a lapse: previous period unchanged');
    }

    public function test_a_backdated_renewal_before_the_latest_period_is_rejected_and_history_is_untouched(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();
        $this->record($relationship, $type, '2026-01-01', '2027-01-01');
        $this->record($relationship, $type, '2026-06-01', '2027-06-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-03-01', '2026-06-01', '2026-01-01'] as $from) {
            try {
                $this->record($relationship, $type, $from, '2028-01-01');
                $this->fail("{$from} would rewrite later history");
            } catch (InvalidEmploymentContractPeriodDateException) {
            }
        }

        $this->assertSame($before, $this->snapshot($relationship));
    }

    // ---------------------------------------------------------------------
    // C. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_a_direct_overlapping_insert_is_rejected_by_the_exclusion_constraint(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();
        $this->insertRaw($relationship, $type, '2026-01-01', '2027-01-01', '2027-01-01');

        $error = $this->queryError(fn () => $this->insertRaw($relationship, $type, '2026-06-01', '2027-06-01', '2027-06-01'));

        $this->assertTrue(Errors::isExclusionViolation($error));
    }

    public function test_validity_can_never_exceed_a_known_agreed_term_and_knowledge_state_is_consistent(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();

        foreach ([
            ['2026-01-01', '2027-06-01', '2027-01-01', 'KNOWN'],          // validity past term
            ['2026-01-01', null, '2027-01-01', 'KNOWN'],                  // KNOWN term but open validity
            ['2026-01-01', '2027-01-01', null, 'KNOWN'],                  // KNOWN without a term
            ['2026-01-01', null, '2027-01-01', 'UNKNOWN_LEGACY'],         // unknown but a term given
            ['2026-01-01', null, null, 'FABRICATED'],                     // unsupported state
            ['2026-01-01', '2026-01-01', '2027-01-01', 'KNOWN'],          // empty validity
            ['2026-01-01', '2025-06-01', '2025-06-01', 'KNOWN'],          // reversed term
        ] as [$from, $to, $contractual, $state]) {
            $error = $this->queryError(fn () => $this->insertRaw($relationship, $type, $from, $to, $contractual, $state));
            $this->assertTrue(Errors::isCheckViolation($error), "[{$from}, {$to}) term {$contractual} {$state} must be rejected");
        }

        // A legacy contract whose end is genuinely unknown is representable without fabrication.
        $this->insertRaw($relationship, $type, '2026-01-01', null, null, 'UNKNOWN_LEGACY');
        $this->assertSame(1, $this->periodCount($relationship));
    }

    public function test_foreign_keys_reject_a_nonexistent_relationship_or_contract_type(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();

        $error = $this->queryError(fn () => DB::table('hr.employment_contract_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => (string) Str::uuid7(), 'contract_type_id' => $type->id,
            'effective_from' => '2026-01-01', 'effective_to' => '2027-01-01', 'contractual_effective_to' => '2027-01-01',
            'contract_end_knowledge_state' => 'KNOWN', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.employment_contract_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $relationship->id, 'contract_type_id' => (string) Str::uuid7(),
            'effective_from' => '2026-01-01', 'effective_to' => '2027-01-01', 'contractual_effective_to' => '2027-01-01',
            'contract_end_knowledge_state' => 'KNOWN', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));
    }

    public function test_a_referenced_contract_type_cannot_be_hard_deleted(): void
    {
        $relationship = $this->contractRelationship();
        $type = $this->createSyntheticContractType();
        $this->record($relationship, $type, '2026-01-01', '2027-01-01');

        $error = $this->queryError(fn () => DB::table('ref.contract_types')->where('id', $type->id)->delete());

        $this->assertTrue(Errors::isForeignKeyViolation($error), 'RESTRICT FK keeps historical contracts valid');
    }

    public function test_the_table_shape_has_no_speculative_columns_and_the_relationship_has_no_contract_columns(): void
    {
        $types = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_contract_periods')
            ->pluck('data_type', 'column_name')->all();
        ksort($types);

        $this->assertSame([
            'contract_end_knowledge_state' => 'character varying',
            'contract_type_id' => 'uuid',
            'contractual_effective_to' => 'date',
            'created_at' => 'timestamp with time zone',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'employment_relationship_id' => 'uuid',
            'id' => 'uuid',
        ], $types, 'no person_id, organizational_unit_id, version/updated_at, or renewal/automation flags');

        $relationshipColumns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_relationships')
            ->pluck('column_name')->all();
        foreach ($relationshipColumns as $column) {
            $this->assertStringNotContainsString('contract', $column, 'the current contract is derived from history, never stored on the relationship');
        }
    }

    // ---------------------------------------------------------------------
    // D. Reference
    // ---------------------------------------------------------------------

    public function test_a_nonexistent_contract_type_is_404_and_writes_nothing(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => (string) Str::uuid7(),
            'effective_from' => '2026-01-01',
            'contractual_effective_to' => '2027-01-01',
        ])->assertNotFound();

        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_an_inactive_contract_type_is_rejected_for_a_new_contract(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $inactive = $this->createSyntheticContractType(active: false);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => $inactive->id,
            'effective_from' => '2026-01-01',
            'contractual_effective_to' => '2027-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['contract_type_id']);

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->expectException(InvalidEmploymentContractTypeException::class);
        $this->record($relationship, $inactive, '2026-01-01', '2027-01-01');
    }

    public function test_a_contract_type_deactivated_later_keeps_historical_contracts_intact_and_readable(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $period = $this->record($relationship, $type, '2026-01-01', '2027-01-01');
        $before = $this->snapshot($relationship);

        app(DeactivateContractType::class)->handle($type, $type->version);
        $this->assertFalse($type->refresh()->is_active);

        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame($type->id, $this->asOf($relationship, '2026-06-01')?->contract_type_id);
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonPath('0.id', $period->id);

        // ...but it can no longer be used for a renewal.
        $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => $type->id,
            'effective_from' => '2027-01-01',
            'contractual_effective_to' => '2028-01-01',
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // E. Employment lifecycle
    // ---------------------------------------------------------------------

    public function test_an_ended_relationship_rejects_a_new_contract(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->end($person, $relationship, '2026-06-01');

        $this->postJson($this->url($person, $relationship->refresh()), $this->payload())->assertStatus(409);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->record($relationship, $this->createSyntheticContractType(), '2026-02-01', '2027-02-01');
    }

    public function test_ending_the_relationship_before_the_agreed_term_closes_contract_validity_and_keeps_the_term(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $period = $this->record($relationship, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
            'expected_version' => $relationship->version,
            'effective_to' => '2026-08-01',
            'is_terminal' => false,
        ])->assertOk();

        $period->refresh();
        $this->assertSame('2026-08-01', $period->effective_to->toDateString(), 'no contract in force after employment ended');
        $this->assertSame('2027-01-01', $period->contractual_effective_to->toDateString(), 'the agreed term is not deleted or rewritten');
        $this->assertNull($this->asOf($relationship, '2026-08-01'));
        $this->assertContractsWithinRelationship($relationship->refresh());

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertTrue($entry->metadata['employment_contract_period_closed_as_consequence'] ?? false);
    }

    public function test_ending_the_relationship_after_the_term_already_expired_leaves_the_contract_untouched(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $period = $this->record($relationship, $this->createSyntheticContractType(), '2026-01-01', '2026-07-01');

        $this->end($person, $relationship, '2026-09-01');

        $this->assertSame('2026-07-01', $period->refresh()->effective_to->toDateString(), 'an expired contract is never extended');
        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertArrayNotHasKey('employment_contract_period_closed_as_consequence', $entry?->metadata ?? []);
    }

    public function test_a_relationship_end_before_a_scheduled_renewal_is_rejected_atomically(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $this->record($relationship, $type, '2026-01-01', '2027-01-01');
        $this->record($relationship, $type, '2026-11-01', '2027-11-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-10-01', '2026-11-01'] as $endDate) {
            $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
                'expected_version' => $relationship->version,
                'effective_to' => $endDate,
                'is_terminal' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        }

        $this->assertSame($before, $this->snapshot($relationship), 'nothing deleted, truncated, or left beyond the relationship');
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_contract_ended_status_terminates_the_relationship_and_its_audit_exposes_the_contract_closure(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $period = $this->record($relationship, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');
        $auditBefore = $this->auditEntriesCount();

        // إنهاء تعاقد — the existing S06/S10/S15 relationship-ending status path.
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'contract_ended',
            'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertSame('KNOWN', $relationship->refresh()->end_knowledge_state);
        $this->assertSame('2026-10-15', $period->refresh()->effective_to->toDateString());
        $this->assertSame('2027-01-01', $period->contractual_effective_to->toDateString());

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertTrue($entry->metadata['employment_contract_period_closed_as_consequence'] ?? false);
        $this->assertSame($auditBefore + 1, $this->auditEntriesCount(), 'one entry — no duplicate event for the consequence');
    }

    public function test_a_rejected_status_triggered_termination_rolls_back_with_no_success_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $this->record($relationship, $type, '2026-01-01', '2027-01-01');
        $this->record($relationship, $type, '2026-11-01', '2027-11-01');
        $before = $this->snapshot($relationship);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'contract_ended',
            'effective_from' => '2026-10-15',
        ])->assertStatus(422);

        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame(0, DB::table('hr.employment_status_periods')->where('employment_relationship_id', $relationship->id)->count());
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_a_terminal_status_ends_the_relationship_with_coherent_contract_history(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $period = $this->record($relationship, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('martyred'), '2026-10-15');

        $this->assertTrue($person->refresh()->is_terminal);
        $this->assertSame('2026-10-15', $period->refresh()->effective_to->toDateString());
        $this->assertContractsWithinRelationship($relationship->refresh());
    }

    public function test_an_early_relationship_end_with_several_contracts_keeps_the_whole_history_coherent(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $first = $this->record($relationship, $type, '2026-01-01', '2026-07-01');
        $second = $this->record($relationship, $type, '2026-07-01', '2027-07-01');

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-09-15', false);

        $this->assertSame('2026-07-01', $first->refresh()->effective_to->toDateString());
        $this->assertSame('2026-09-15', $second->refresh()->effective_to->toDateString());
        $this->assertSame('2027-07-01', $second->contractual_effective_to->toDateString());
        $this->assertContractsWithinRelationship($relationship->refresh());
    }

    public function test_a_relationship_end_before_an_already_started_legacy_period_is_rejected(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, $this->createSyntheticContractType(), '2026-06-01', null, null, 'UNKNOWN_LEGACY');

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);
    }

    public function test_reappointment_never_inherits_the_previous_relationships_contract(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $old] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $oldPeriod = $this->record($old, $type, '2026-01-01', '2027-01-01');
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(0, $this->periodCount($new), 'no contract period or type is copied to the new relationship');
        $this->assertNull($this->asOf($new, '2026-09-01'));
        $this->getJson($this->url($person, $new))->assertOk()->assertJsonCount(0);

        $this->assertSame($old->id, $oldPeriod->refresh()->employment_relationship_id);
        $this->assertSame('2026-06-01', $oldPeriod->effective_to->toDateString());

        // The new CONTRACT relationship records its own contract, independently.
        $this->record($new, $type, '2026-08-01', '2027-08-01');
        $this->assertSame(1, $this->periodCount($new));
        $this->assertSame(1, $this->periodCount($old));
    }

    public function test_reappointment_as_permanent_has_no_fabricated_contract(): void
    {
        [$person, $old] = $this->personAndRelationship();
        $this->record($old, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'permanent', null, '2026-08-01');

        $this->assertSame(0, $this->periodCount($new));
        $this->expectException(EmploymentRelationshipNotContractSchemeException::class);
        $this->record($new, $this->createSyntheticContractType(), '2026-08-01', '2027-08-01');
    }

    // ---------------------------------------------------------------------
    // G. Employee number (non-regression)
    // ---------------------------------------------------------------------

    public function test_contract_and_permanent_employee_number_rules_are_unchanged_by_contract_recording(): void
    {
        $person = $this->createPersonRecord();
        $contract = $this->createEmploymentRelationship($person, 'contract');
        $this->record($contract, $this->createSyntheticContractType(), '2026-01-01', '2027-01-01');

        $contract->refresh();
        $this->assertSame('CONTRACT', $contract->employee_number_scheme);
        $this->assertSame($person->national_id, $contract->employee_number, 'CONTRACT employee number = National ID');

        $other = $this->createPersonRecord();
        $permanent = $this->createEmploymentRelationship($other, 'permanent', 'PN-S21-'.Str::upper(Str::random(8)));
        $this->assertSame('PERMANENT', $permanent->employee_number_scheme);
        $this->assertNotSame($other->national_id, $permanent->employee_number, 'PERMANENT number stays independent of National ID');
    }

    // ---------------------------------------------------------------------
    // H. Security
    // ---------------------------------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$person, $relationship] = $this->personAndRelationship();

        $this->getJson($this->url($person, $relationship))->assertUnauthorized();
        $this->postJson($this->url($person, $relationship), [])->assertUnauthorized();
    }

    public function test_a_principal_without_hr_permissions_is_forbidden(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->actingAs($this->createPrincipal(), 'web');

        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_view_permission_alone_cannot_record(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->principalWithPermissions([Perm::EMPLOYMENT_CONTRACT_PERIODS_VIEW]);

        $this->getJson($this->url($person, $relationship))->assertOk();
        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
    }

    public function test_the_record_permission_alone_is_sufficient_without_organizational_scope(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->principalWithPermissions([Perm::EMPLOYMENT_CONTRACT_PERIODS_RECORD]);

        $this->postJson($this->url($person, $relationship), $this->payload())->assertStatus(201);
        $this->getJson($this->url($person, $relationship))->assertForbidden();
    }

    public function test_reference_catalog_permissions_never_grant_contract_assignment(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);

        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->getJson('/api/v1/reference/contract-types')->assertOk();
        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_other_hr_permissions_do_not_grant_contract_assignment(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [
            Perm::EMPLOYMENT_CONTRACT_PERIODS_VIEW, Perm::EMPLOYMENT_CONTRACT_PERIODS_RECORD,
        ])));

        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();
    }

    public function test_ownership_mismatch_is_404_and_no_patch_or_delete_route_exists(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $stranger = $this->createPersonRecord();

        $this->getJson($this->url($stranger, $relationship))->assertNotFound();
        $this->postJson($this->url($stranger, $relationship), $this->payload())->assertNotFound();
        $this->patchJson($this->url($person, $relationship), $this->payload())->assertStatus(405);
        $this->deleteJson($this->url($person, $relationship))->assertStatus(405);
        $this->assertSame(0, $this->periodCount($relationship));
    }

    // ---------------------------------------------------------------------
    // I. Audit
    // ---------------------------------------------------------------------

    public function test_a_successful_record_is_audited_without_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();

        $response = $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => $type->id,
            'effective_from' => '2026-01-01',
            'contractual_effective_to' => '2027-01-01',
        ])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_contract_period.record');
        $this->assertNotNull($entry);
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_employment_contract_period', $entry->target_type);
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertEquals([
            'employment_relationship_id' => $relationship->id,
            'contract_type_id' => $type->id,
            'effective_from' => '2026-01-01',
            'contractual_effective_to' => '2027-01-01',
        ], $entry->changes);
        $this->assertEquals(['contract_type_code' => $type->code], $entry->metadata, 'an initial contract carries no renewal metadata');
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    public function test_a_renewal_audit_records_the_renewed_period_and_its_early_closure(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $original = $this->record($relationship, $type, '2026-01-01', '2027-01-01');

        $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => $type->id,
            'effective_from' => '2026-10-01',
            'contractual_effective_to' => '2027-10-01',
        ])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_contract_period.record');
        $this->assertSame($original->id, $entry->metadata['renewal_of_period_id']);
        $this->assertSame('2026-10-01', $entry->metadata['previous_period_closed_at']);
    }

    public function test_a_rejected_write_leaves_no_audit_entry_and_no_partial_truncation(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $type = $this->createSyntheticContractType();
        $existing = $this->record($relationship, $type, '2026-06-01', '2027-06-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), [
            'contract_type_id' => $type->id,
            'effective_from' => '2026-03-01',
            'contractual_effective_to' => '2027-03-01',
        ])->assertStatus(422);

        $this->assertSame($auditBefore, $this->auditEntriesCount());
        $this->assertSame('2027-06-01', $existing->refresh()->effective_to->toDateString());
        $this->assertSame(1, $this->periodCount($relationship));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function contractRelationship(string $effectiveFrom = '2026-01-01'): EmploymentRelationship
    {
        return $this->createEmploymentRelationship($this->createPersonRecord(), 'contract', null, $effectiveFrom);
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function personAndRelationship(string $typeCode = 'contract'): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $typeCode)];
    }

    private function record(EmploymentRelationship $relationship, ContractType $type, string $from, string $contractualTo): EmploymentContractPeriod
    {
        return app(RecordEmploymentContractPeriod::class)->handle($relationship, $type, $from, $contractualTo);
    }

    private function asOf(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentContractPeriod
    {
        return app(ResolveEmploymentContractForRelationshipAsOf::class)($relationship, $date);
    }

    private function end(Person $person, EmploymentRelationship $relationship, string $effectiveTo): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $relationship->refresh(), $relationship->version, $effectiveTo, false);
    }

    private function url(Person $person, EmploymentRelationship $relationship): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/employment-contract-periods";
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'contract_type_id' => $this->createSyntheticContractType()->id,
            'effective_from' => '2026-02-01',
            'contractual_effective_to' => '2027-02-01',
        ];
    }

    private function periodCount(EmploymentRelationship $relationship): int
    {
        return EmploymentContractPeriod::query()->where('employment_relationship_id', $relationship->id)->count();
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(EmploymentRelationship $relationship): array
    {
        return DB::table('hr.employment_contract_periods')
            ->where('employment_relationship_id', $relationship->id)
            ->orderBy('effective_from')
            ->get(['id', 'contract_type_id', 'effective_from', 'effective_to', 'contractual_effective_to', 'contract_end_knowledge_state'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function insertRaw(
        EmploymentRelationship $relationship,
        ContractType $type,
        string $from,
        ?string $to,
        ?string $contractualTo,
        string $state = 'KNOWN',
    ): void {
        DB::table('hr.employment_contract_periods')->insert([
            'id' => (string) Str::uuid7(),
            'employment_relationship_id' => $relationship->id,
            'contract_type_id' => $type->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'contractual_effective_to' => $contractualTo,
            'contract_end_knowledge_state' => $state,
            'created_at' => now(),
        ]);
    }

    private function assertContractsWithinRelationship(EmploymentRelationship $relationship): void
    {
        $this->assertNotNull($relationship->effective_to);

        foreach (EmploymentContractPeriod::query()->where('employment_relationship_id', $relationship->id)->get() as $period) {
            $this->assertNotNull($period->effective_to, 'no contract may remain in force after the relationship ended');
            $this->assertTrue($period->effective_from->gte($relationship->effective_from));
            $this->assertTrue($period->effective_to->lte($relationship->effective_to), 'no contract validity may extend beyond the relationship');
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
