<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Domain\Exceptions\DuplicatePermanentEmployeeNumberException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\OverlappingEmploymentRelationshipException;
use App\Modules\HumanResources\Domain\Exceptions\PersonIsTerminalException;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use Illuminate\Support\Facades\Route;

/**
 * S09 Employment Relationship lifecycle: PERMANENT/CONTRACT employee-number policy, temporal
 * integrity, reappointment, terminal enforcement, RBAC, audit, error shape (spec §6-§14/§19/§23).
 *
 * S15 (docs/employment-status-lifecycle-consequences-specification.md §8/§17/§21) additions live
 * here too: they are new consequences of EndEmploymentRelationship itself, the command this file
 * already covers, not a new domain.
 */
class EmploymentRelationshipLifecycleTest extends HumanResourcesTestCase
{
    public function test_a_permanent_relationship_has_an_employee_number_independent_of_national_id(): void
    {
        $person = $this->createPersonRecord('1112223330');

        $relationship = $this->createEmploymentRelationship($person, 'permanent', 'PN-0001');

        $this->assertSame('PN-0001', $relationship->employee_number);
        $this->assertSame('PERMANENT', $relationship->employee_number_scheme);
        $this->assertNotSame($person->national_id, $relationship->employee_number);
    }

    public function test_a_contract_relationship_employee_number_always_equals_the_national_id(): void
    {
        $person = $this->createPersonRecord('2223334440');

        $relationship = $this->createEmploymentRelationship($person, 'contract');

        $this->assertSame('2223334440', $relationship->employee_number);
        $this->assertSame('CONTRACT', $relationship->employee_number_scheme);
    }

    public function test_a_permanent_relationship_requires_an_employee_number(): void
    {
        $person = $this->createPersonRecord();
        $employmentType = $this->employmentType('permanent');

        $this->expectException(\InvalidArgumentException::class);
        app(CreateEmploymentRelationship::class)->handle($person, $employmentType, '2026-01-01', null);
    }

    public function test_a_client_supplied_employee_number_for_a_contract_relationship_is_rejected_by_the_api(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships", [
            'employment_type_code' => 'contract',
            'effective_from' => '2026-01-01',
            'employee_number' => 'SHOULD-NOT-BE-ALLOWED',
        ])->assertStatus(422)->assertJsonValidationErrors(['employee_number']);
    }

    public function test_a_missing_employee_number_for_a_permanent_relationship_is_rejected_by_the_api(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships", [
            'employment_type_code' => 'permanent',
            'effective_from' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['employee_number']);
    }

    public function test_overlapping_relationships_for_the_same_person_are_rejected(): void
    {
        $person = $this->createPersonRecord();
        $this->createEmploymentRelationship($person, 'permanent', 'PN-A', '2026-01-01');

        $this->expectException(OverlappingEmploymentRelationshipException::class);
        $this->createEmploymentRelationship($person, 'permanent', 'PN-B', '2026-01-15');
    }

    public function test_at_most_one_active_relationship_is_permitted(): void
    {
        $person = $this->createPersonRecord();
        $this->createEmploymentRelationship($person, 'permanent', 'PN-C', '2026-01-01');

        $this->expectException(OverlappingEmploymentRelationshipException::class);
        $this->createEmploymentRelationship($person, 'contract', null, '2026-06-01');
    }

    public function test_non_overlapping_historical_relationships_are_allowed(): void
    {
        $person = $this->createPersonRecord();
        $first = $this->createEmploymentRelationship($person, 'permanent', 'PN-D', '2026-01-01');
        app(EndEmploymentRelationship::class)->handle($person, $first, $first->version, '2026-03-01', false);

        $second = $this->createEmploymentRelationship($person, 'contract', null, '2026-03-01');

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_backdated_relationship_overlapping_history_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $first = $this->createEmploymentRelationship($person, 'permanent', 'PN-E', '2026-01-01');
        app(EndEmploymentRelationship::class)->handle($person, $first, $first->version, '2026-06-01', false);

        $this->expectException(OverlappingEmploymentRelationshipException::class);
        $this->createEmploymentRelationship($person, 'contract', null, '2026-04-01'); // inside the ended interval
    }

    public function test_a_permanent_employee_number_cannot_be_reused_by_a_different_person_even_after_the_relationship_ends(): void
    {
        $personA = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($personA, 'permanent', 'PN-UNIQUE', '2026-01-01');
        app(EndEmploymentRelationship::class)->handle($personA, $relationship, $relationship->version, '2026-03-01', false);

        $personB = $this->createPersonRecord();

        $this->expectException(DuplicatePermanentEmployeeNumberException::class);
        $this->createEmploymentRelationship($personB, 'permanent', 'PN-UNIQUE', '2026-04-01');
    }

    public function test_a_contract_number_may_repeat_for_the_same_persons_own_reappointment(): void
    {
        $person = $this->createPersonRecord('9998887770');
        $first = $this->createEmploymentRelationship($person, 'contract', null, '2026-01-01');
        app(EndEmploymentRelationship::class)->handle($person, $first, $first->version, '2026-03-01', false);

        $second = $this->createEmploymentRelationship($person, 'contract', null, '2026-03-01');

        $this->assertSame('9998887770', $first->employee_number);
        $this->assertSame('9998887770', $second->employee_number);
    }

    public function test_ending_a_relationship_sets_effective_to_and_known_end_state(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $ended = app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $this->assertSame('2026-05-01', $ended->effective_to->toDateString());
        $this->assertSame('KNOWN', $ended->end_knowledge_state);
        $this->assertFalse($ended->ended_terminally);
        $this->assertFalse($person->refresh()->is_terminal);
    }

    public function test_ending_a_relationship_terminally_marks_the_person_terminal(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', true);

        $this->assertTrue($person->refresh()->is_terminal);
    }

    public function test_a_terminal_person_can_never_get_a_new_employment_relationship(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', true);

        $terminalPerson = $person->refresh();

        $this->expectException(PersonIsTerminalException::class);
        $this->createEmploymentRelationship($terminalPerson, 'contract', null, '2026-06-01');
    }

    public function test_ending_with_an_effective_to_not_after_effective_from_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'permanent', 'PN-BADEND', '2026-01-01');

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-01-01', false);
    }

    public function test_ending_with_an_effective_to_not_after_effective_from_via_the_api_is_a_422(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person, 'permanent', 'PN-BADEND2', '2026-01-01');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2025-12-31', 'is_terminal' => false],
        )->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
    }

    public function test_a_terminal_person_can_never_get_a_new_employment_relationship_even_when_the_ending_committed_after_route_binding(): void
    {
        // Reproduces the exact race an earlier draft of CreateEmploymentRelationship missed.
        // $staleCopy is deliberately a SEPARATE Eloquent instance of the same row — standing in
        // for the stale copy route-model-binding would hand a *concurrent* controller before its
        // own mutating transaction opens. (Reusing the very same $person object passed to
        // EndEmploymentRelationship would not test this: that command mutates its own $person
        // argument in place via forceFill()->save(), so the shared reference would no longer be
        // stale after the call — a same-request self-mutation, not the cross-request staleness
        // this regression is about.) The command must re-read the Person fresh inside its own
        // transaction rather than trusting this stale copy.
        $person = $this->createPersonRecord();
        $staleCopy = Person::query()->where('id', $person->getKey())->firstOrFail();
        $relationship = $this->createEmploymentRelationship($person);

        $this->assertFalse($staleCopy->is_terminal, 'fixture assumption: the stale copy predates the ending below');
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', true);
        $this->assertFalse($staleCopy->is_terminal, 'fixture assumption: the stale copy was never refreshed');

        $this->expectException(PersonIsTerminalException::class);
        $this->createEmploymentRelationship($staleCopy, 'contract', null, '2026-06-01');
    }

    public function test_ending_an_already_ended_relationship_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $ended = app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        app(EndEmploymentRelationship::class)->handle($person, $ended, $ended->version, '2026-06-01', false);
    }

    public function test_a_stale_expected_version_when_ending_is_rejected_as_already_ended(): void
    {
        // Two independent in-memory copies of the same row, both still at version 1 — the way two
        // genuinely concurrent requests would each hold their own stale snapshot. Ending a
        // relationship is this command's only mutation, and it is one-way, so a version race here
        // and "already ended" are the same fact, not two distinct outcomes (see
        // EndEmploymentRelationship's own docblock and spec §11): there is no separate
        // StaleVersionException for this module in S09 v1.
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $staleCopy = EmploymentRelationship::query()->where('id', $relationship->id)->firstOrFail();

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        app(EndEmploymentRelationship::class)->handle($person, $staleCopy, $staleCopy->version, '2026-06-01', false);
    }

    public function test_creating_and_listing_relationships_via_the_api_succeeds_and_is_audited(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $response = $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships", [
            'employment_type_code' => 'contract',
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $response->assertJsonPath('employee_number_scheme', 'CONTRACT')->assertJsonPath('employee_number', $person->national_id);

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.create');
        $this->assertNotNull($entry);
        $this->assertSame($response->json('id'), $entry->target_id);

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships")->assertOk()->assertJsonCount(1);
    }

    public function test_ending_via_the_api_succeeds_and_is_audited(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-05-01', 'is_terminal' => false],
        )->assertOk()->assertJsonPath('end_knowledge_state', 'KNOWN');

        $this->assertNotNull($this->latestAuditEntryFor('hr.employment_relationship.end'));
    }

    public function test_ending_a_relationship_that_belongs_to_a_different_person_is_a_404_not_a_cross_person_mutation(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $someoneElse = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($someoneElse);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-05-01', 'is_terminal' => false],
        )->assertStatus(404);

        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_an_unknown_employment_type_code_is_a_standardized_validation_error(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships", [
            'employment_type_code' => 'seasonal',
            'effective_from' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['employment_type_code']);
    }

    public function test_reads_are_rejected_without_the_employment_relationships_view_permission(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $person = $this->createPersonRecord();

        $this->getJson("/api/v1/hr/persons/{$person->id}/employment-relationships")->assertStatus(403);
    }

    public function test_writes_are_rejected_without_the_employment_relationships_create_permission(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $person = $this->createPersonRecord();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships", [
            'employment_type_code' => 'contract',
            'effective_from' => '2026-01-01',
        ])->assertStatus(403);
    }

    public function test_error_responses_never_leak_sql_or_stack_traces(): void
    {
        $person = $this->createPersonRecord();
        $this->createEmploymentRelationship($person, 'permanent', 'PN-LEAK', '2026-01-01');
        $this->actingAsHrAdministrator();

        $body = $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships", [
            'employment_type_code' => 'permanent',
            'effective_from' => '2026-01-10',
            'employee_number' => 'PN-LEAK2',
        ])->getContent();

        foreach (['SQLSTATE', '.php:', 'Stack trace', 'PDOException', 'select *'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    // ---------------------------------------------------------------------
    // S15 — Employment Status Lifecycle Consequences (spec §8/§14/§17/§21)
    // ---------------------------------------------------------------------

    public function test_ending_a_relationship_closes_an_active_full_secondment(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartFullSecondment::class)->handle($relationship, $unit, '2026-02-01');

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $secondment = FullSecondmentPeriod::query()->where('employment_relationship_id', $relationship->getKey())->firstOrFail();
        $this->assertSame('2026-05-01', $secondment->effective_to->toDateString());
    }

    public function test_ending_a_relationship_closes_an_active_workplace_assignment(): void
    {
        // S16 (docs/workplace-assignment-foundation-specification.md §S16.8/§S16.10): mirrors
        // test_ending_a_relationship_closes_an_active_full_secondment() exactly — the two child
        // streams are mutually exclusive (an active relationship can never have both an open
        // secondment and an open assignment at once), so this test uses an assignment where the
        // secondment test above uses a secondment, and is otherwise the same shape.
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($relationship, $unit, '2026-02-01', $this->assignmentDecisionType());

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $assignment = WorkplaceAssignmentPeriod::query()->where('employment_relationship_id', $relationship->getKey())->firstOrFail();
        $this->assertSame('2026-05-01', $assignment->effective_to->toDateString());
    }

    public function test_ending_a_relationship_closes_an_earlier_open_status_period(): void
    {
        // Status effective_from must be on/after the S06-seeded behavior periods' 2026-09-26
        // anchor (see test_an_unresolved_behavior_date_is_rejected_and_nothing_is_written in
        // EmploymentStatusHistoryTest) — unrelated to S15, an existing S10 constraint.
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $period = app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-10-05', false);

        $this->assertSame('2026-10-05', $period->refresh()->effective_to->toDateString());
    }

    public function test_ending_a_relationship_leaves_an_open_organizational_placement_period_untouched(): void
    {
        // Considered and rejected (spec §8.2): no disclosed-gap precedent for S11, and §7
        // movement-independence argues against rewriting workplace history on employment end.
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        $this->recordPlacement($relationship, $unit, '2026-01-15');

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $placement = OrganizationalPlacementPeriod::query()->where('employment_relationship_id', $relationship->getKey())->firstOrFail();
        $this->assertNull($placement->effective_to);
    }

    public function test_ending_a_relationship_with_no_open_secondment_or_status_period_succeeds_as_before(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $ended = app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-05-01', false);

        $this->assertSame('KNOWN', $ended->end_knowledge_state);
        $this->assertSame(0, FullSecondmentPeriod::query()->where('employment_relationship_id', $relationship->getKey())->count());
        $this->assertSame(0, EmploymentStatusPeriod::query()->where('employment_relationship_id', $relationship->getKey())->count());
    }

    public function test_ending_a_relationship_before_an_open_secondments_own_start_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartFullSecondment::class)->handle($relationship, $unit, '2026-06-01');

        $this->expectException(InvalidFullSecondmentEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-03-01', false);
    }

    public function test_ending_a_relationship_before_an_open_status_periods_own_start_is_rejected(): void
    {
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-10-01');

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-09-28', false);
    }

    public function test_ending_via_the_api_surfaces_both_closures_in_the_audit_entry(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartFullSecondment::class)->handle($relationship, $unit, '2026-02-01');
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-10-05', 'is_terminal' => false],
        )->assertOk();

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->metadata['full_secondment_closed_as_consequence']);
        $this->assertTrue($entry->metadata['status_period_closed_as_consequence']);
    }

    public function test_ending_via_the_api_with_nothing_open_carries_no_closure_flags(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-05-01', 'is_terminal' => false],
        )->assertOk();

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');

        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('full_secondment_closed_as_consequence', $entry->metadata);
        $this->assertArrayNotHasKey('status_period_closed_as_consequence', $entry->metadata);
        $this->assertArrayNotHasKey('workplace_assignment_closed_as_consequence', $entry->metadata);
    }

    public function test_ending_via_the_api_with_only_a_secondment_open_carries_only_that_flag(): void
    {
        // Adversarial-review coverage gap (spec §21 item 7 names "both, one, neither" — only
        // "both" and "neither" existed): each of the two independently-computed booleans must be
        // provably independent of the other, not merely both-true/both-false by coincidence.
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartFullSecondment::class)->handle($relationship, $unit, '2026-02-01');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-05-01', 'is_terminal' => false],
        )->assertOk();

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->metadata['full_secondment_closed_as_consequence']);
        $this->assertArrayNotHasKey('status_period_closed_as_consequence', $entry->metadata);
        $this->assertArrayNotHasKey('workplace_assignment_closed_as_consequence', $entry->metadata);
    }

    public function test_ending_via_the_api_with_only_a_status_period_open_carries_only_that_flag(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('on_duty'), '2026-09-27');

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-10-05', 'is_terminal' => false],
        )->assertOk();

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->metadata['status_period_closed_as_consequence']);
        $this->assertArrayNotHasKey('full_secondment_closed_as_consequence', $entry->metadata);
        $this->assertArrayNotHasKey('workplace_assignment_closed_as_consequence', $entry->metadata);
    }

    public function test_ending_via_the_api_with_only_an_assignment_open_carries_only_that_flag(): void
    {
        // S16 (docs/workplace-assignment-foundation-specification.md §S16.10): the third
        // independently-computed audit-metadata boolean — mirrors
        // test_ending_via_the_api_with_only_a_secondment_open_carries_only_that_flag() exactly,
        // using an assignment in place of a secondment (the two are mutually exclusive, so this is
        // the only way to exercise this flag alone rather than alongside the secondment flag).
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord();
        $relationship = $this->createEmploymentRelationship($person);
        $unit = $this->createUnit();
        app(StartWorkplaceAssignment::class)->handle($relationship, $unit, '2026-02-01', $this->assignmentDecisionType());

        $this->postJson(
            "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end",
            ['expected_version' => $relationship->version, 'effective_to' => '2026-05-01', 'is_terminal' => false],
        )->assertOk();

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->metadata['workplace_assignment_closed_as_consequence']);
        $this->assertArrayNotHasKey('full_secondment_closed_as_consequence', $entry->metadata);
        $this->assertArrayNotHasKey('status_period_closed_as_consequence', $entry->metadata);
    }

    public function test_there_is_no_hard_delete_workflow(): void
    {
        foreach (glob(app_path('Modules/HumanResources/Application/Commands/*.php')) as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/->delete\s*\(|::destroy\s*\(/i', $code, "no hard-delete call in $file");
        }

        $hasDeleteRoute = collect(Route::getRoutes())
            ->contains(fn ($route) => str_contains($route->uri(), 'employment-relationships') && in_array('DELETE', $route->methods(), true));
        $this->assertFalse($hasDeleteRoute, 'there must be no DELETE route anywhere under employment-relationships');
    }
}
