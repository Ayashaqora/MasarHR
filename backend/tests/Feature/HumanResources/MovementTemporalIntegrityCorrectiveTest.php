<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationshipAsOf;
use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActiveWorkplaceAssignmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentStartDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentStartDateException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S28 Movement Temporal Integrity Corrective (docs/movement-temporal-integrity-corrective-specification.md,
 * ADR-S28-001): a new temporary workplace movement SUPERSEDES the full secondment / workplace
 * assignment effective at its start date (interval-aware — including rows closed with a future
 * effective_to), atomically; later-recorded history is never rewritten; transfer truncates any
 * movement effective at the transfer date; legacy overlaps stay detectable as
 * AMBIGUOUS_MOVEMENT_STATE (no data rewrite); audit records the consequence; superseding never
 * bypasses organizational scope. Cross-session concurrency lives in ConcurrencyTest.
 */
class MovementTemporalIntegrityCorrectiveTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A–D. Cross-stream supersession (open and future-closed)
    // ---------------------------------------------------------------------

    public function test_a_secondment_to_assignment_truncates_the_open_secondment(): void
    {
        [$rel] = $this->placed();
        $sec = $this->sec($rel, '2026-01-20');

        $asg = $this->asg($rel, '2026-03-01');

        $this->assertPeriod($sec, '2026-01-20', '2026-03-01');
        $this->assertPeriod($asg, '2026-03-01', null);
        $this->assertNoCrossStreamOverlap($rel);
    }

    public function test_b_assignment_to_secondment_truncates_the_open_assignment(): void
    {
        [$rel] = $this->placed();
        $asg = $this->asg($rel, '2026-01-20');

        $sec = $this->sec($rel, '2026-03-01');

        $this->assertPeriod($asg, '2026-01-20', '2026-03-01');
        $this->assertPeriod($sec, '2026-03-01', null);
        $this->assertNoCrossStreamOverlap($rel);
    }

    public function test_c_a_future_closed_secondment_is_detected_and_truncated(): void
    {
        [$rel] = $this->placed();
        $sec = $this->sec($rel, '2026-02-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-06-01');

        $asg = $this->asg($rel, '2026-03-01');

        $this->assertPeriod($sec, '2026-02-01', '2026-03-01');
        $this->assertPeriod($asg, '2026-03-01', null);
        $this->assertNoCrossStreamOverlap($rel);
        $this->assertSame($asg->organizational_unit_id, $this->asOf($rel, '2026-04-01')->organizationalUnitId(), 'no ambiguity is created');
    }

    public function test_d_a_future_closed_assignment_is_detected_and_truncated(): void
    {
        [$rel] = $this->placed();
        $asg = $this->asg($rel, '2026-02-01');
        app(EndWorkplaceAssignment::class)->handle($rel->refresh(), '2026-06-01');

        $sec = $this->sec($rel, '2026-03-01');

        $this->assertPeriod($asg, '2026-02-01', '2026-03-01');
        $this->assertPeriod($sec, '2026-03-01', null);
        $this->assertNoCrossStreamOverlap($rel);
    }

    // ---------------------------------------------------------------------
    // E–H. Adjacency, earlier-ended, backdated
    // ---------------------------------------------------------------------

    public function test_e_adjacent_movements_are_not_a_conflict_and_nothing_is_rewritten(): void
    {
        [$rel] = $this->placed();
        $sec = $this->sec($rel, '2026-02-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-03-01');
        $before = $this->rows($rel);

        $asg = $this->asg($rel, '2026-03-01');

        $this->assertSame($before['secondments'], $this->rows($rel)['secondments'], 'the adjacent secondment is untouched');
        $this->assertPeriod($asg, '2026-03-01', null);
    }

    public function test_f_a_movement_that_ended_before_the_new_start_is_untouched(): void
    {
        [$rel] = $this->placed();
        $this->asg($rel, '2026-02-01');
        app(EndWorkplaceAssignment::class)->handle($rel->refresh(), '2026-03-01');
        $before = $this->rows($rel);

        $this->sec($rel, '2026-04-01');

        $this->assertSame($before['assignments'], $this->rows($rel)['assignments']);
    }

    public function test_g_a_backdated_start_truncates_exactly_the_directly_superseded_movement(): void
    {
        [$rel] = $this->placed();
        $sec = $this->sec($rel, '2026-01-20');
        // Backdated relative to the recording order, but nothing later is recorded.
        $asg = $this->asg($rel, '2026-02-01');

        $this->assertPeriod($sec, '2026-01-20', '2026-02-01');
        $this->assertPeriod($asg, '2026-02-01', null);
    }

    public function test_h_a_backdated_start_that_would_rewrite_later_history_is_rejected_atomically(): void
    {
        [$rel] = $this->placed();
        $this->asg($rel, '2026-02-01');
        $this->asg($rel, '2026-04-01'); // S16 same-stream: closes the first at 04-01
        $before = $this->rows($rel);

        try {
            $this->sec($rel, '2026-03-01');
            $this->fail('a later-recorded assignment starts after 03-01 — superseding would rewrite several periods');
        } catch (ActiveWorkplaceAssignmentAlreadyExistsException) {
        }

        $this->assertSame($before, $this->rows($rel), 'nothing truncated, nothing created');

        // Same-stream variant keeps the unchanged S16 422.
        try {
            $this->asg($rel, '2026-03-01');
            $this->fail('a later assignment starts after 03-01');
        } catch (InvalidWorkplaceAssignmentStartDateException) {
        }
        $this->assertSame($before, $this->rows($rel));
    }

    public function test_a_movement_starting_exactly_where_a_cross_stream_movement_starts_is_rejected(): void
    {
        [$rel] = $this->placed();
        $this->sec($rel, '2026-03-01');
        $before = $this->rows($rel);

        $this->expectException(ActiveFullSecondmentAlreadyExistsException::class);
        try {
            $this->asg($rel, '2026-03-01');
        } finally {
            $this->assertSame($before, $this->rows($rel), 'truncating to an empty period would erase history — rejected instead');
        }
    }

    public function test_same_stream_secondment_behavior_is_unchanged(): void
    {
        [$rel] = $this->placed();
        $this->sec($rel, '2026-02-01');

        $this->expectException(ActiveFullSecondmentAlreadyExistsException::class);
        $this->sec($rel, '2026-03-01');
    }

    // ---------------------------------------------------------------------
    // I–M. Transfer consequence
    // ---------------------------------------------------------------------

    public function test_i_transfer_truncates_an_open_secondment(): void
    {
        [$rel] = $this->placed();
        $sec = $this->sec($rel, '2026-02-01');

        $result = $this->transfer($rel, '2026-03-01');

        $this->assertPeriod($sec, '2026-02-01', '2026-03-01');
        $this->assertSame($sec->id, $result->closedSecondment()?->id);
    }

    public function test_j_transfer_truncates_a_future_closed_secondment(): void
    {
        [$rel] = $this->placed();
        $sec = $this->sec($rel, '2026-02-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-06-01');

        $result = $this->transfer($rel, '2026-03-01');

        $this->assertPeriod($sec, '2026-02-01', '2026-03-01');
        $this->assertSame($sec->id, $result->closedSecondment()?->id);
        $this->assertSame('placement', $this->asOf($rel, '2026-04-01')->source(), 'no movement that began before the transfer continues across it');
    }

    public function test_k_transfer_truncates_an_open_assignment(): void
    {
        [$rel] = $this->placed();
        $asg = $this->asg($rel, '2026-02-01');

        $result = $this->transfer($rel, '2026-03-01');

        $this->assertPeriod($asg, '2026-02-01', '2026-03-01');
        $this->assertSame($asg->id, $result->closedAssignment()?->id);
    }

    public function test_l_transfer_truncates_a_future_closed_assignment(): void
    {
        [$rel] = $this->placed();
        $asg = $this->asg($rel, '2026-02-01');
        app(EndWorkplaceAssignment::class)->handle($rel->refresh(), '2026-06-01');

        $result = $this->transfer($rel, '2026-03-01');

        $this->assertPeriod($asg, '2026-02-01', '2026-03-01');
        $this->assertSame($asg->id, $result->closedAssignment()?->id);
    }

    public function test_m_transfer_after_a_movement_ended_leaves_history_untouched(): void
    {
        [$rel] = $this->placed();
        $this->sec($rel, '2026-02-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-03-01');
        $before = $this->rows($rel);

        $result = $this->transfer($rel, '2026-04-01');

        $this->assertSame($before['secondments'], $this->rows($rel)['secondments']);
        $this->assertNull($result->closedSecondment());
        $this->assertNull($result->closedAssignment());
    }

    public function test_transfer_before_a_later_recorded_movement_start_is_rejected_atomically(): void
    {
        [$rel] = $this->placed();
        $this->asg($rel, '2026-05-01');
        $before = $this->rows($rel);
        $placementsBefore = DB::table('hr.organizational_placement_periods')->where('employment_relationship_id', $rel->id)->count();

        try {
            $this->transfer($rel, '2026-03-01');
            $this->fail('the S14 §20 rejection, generalised to intervals');
        } catch (InvalidWorkplaceAssignmentEndDateException) {
        }

        $this->assertSame($before, $this->rows($rel));
        $this->assertSame($placementsBefore, DB::table('hr.organizational_placement_periods')->where('employment_relationship_id', $rel->id)->count(), 'the placement write rolled back too');
    }

    // ---------------------------------------------------------------------
    // N. Atomicity
    // ---------------------------------------------------------------------

    public function test_n_a_failed_creation_rolls_back_the_truncation(): void
    {
        [$rel] = $this->placed();
        // Legacy rows (pre-S28): an open assignment from 02-01 and a closed secondment [05-01, 06-01).
        $asgId = $this->insertLegacy('hr.workplace_assignment_periods', $rel, '2026-02-01', null);
        $this->insertLegacy('hr.full_secondment_periods', $rel, '2026-05-01', '2026-06-01');
        $before = $this->rows($rel);

        try {
            // Validation passes (no assignment starts on/after 03-01), the assignment is truncated,
            // then the secondment insert [03-01, ∞) hits the S12 EXCLUDE constraint.
            $this->sec($rel, '2026-03-01');
            $this->fail('the insert must fail');
        } catch (InvalidFullSecondmentStartDateException) {
        }

        $this->assertSame($before, $this->rows($rel), 'the truncation was rolled back — no partial state');
        $this->assertNull(DB::table('hr.workplace_assignment_periods')->where('id', $asgId)->value('effective_to'));
    }

    // ---------------------------------------------------------------------
    // P–Q. S27 compatibility
    // ---------------------------------------------------------------------

    public function test_p_legacy_overlap_stays_ambiguous_and_is_never_repaired(): void
    {
        [$rel] = $this->placed();
        $this->insertLegacy('hr.full_secondment_periods', $rel, '2026-02-01', '2026-06-01');
        $this->insertLegacy('hr.workplace_assignment_periods', $rel, '2026-03-01', null);
        $before = $this->rows($rel);

        $this->assertSame(ActualWorkplaceAsOf::AMBIGUOUS_MOVEMENT_STATE, $this->asOf($rel, '2026-04-01')->state());
        $this->assertSame($before, $this->rows($rel), 'reads never repair; S28 ships no data rewrite');
    }

    public function test_q_future_recorded_movements_keep_the_s27_current_vs_as_of_divergence(): void
    {
        [$rel, $home] = $this->placed();
        $dest = $this->createUnit();
        app(StartFullSecondment::class)->handle($rel, $dest, '2099-01-01');

        $current = app(ResolveActualWorkplaceForRelationship::class)($rel->refresh());
        $this->assertSame($dest->id, $current->organizationalUnitId(), 'open-period view unchanged');
        $this->assertSame($home->id, $this->asOf($rel, '2098-12-31')->organizationalUnitId(), 'effective-date view unchanged');
    }

    // ---------------------------------------------------------------------
    // Audit and security (HTTP)
    // ---------------------------------------------------------------------

    public function test_the_superseding_command_audits_the_consequence_without_pii(): void
    {
        $this->actingAsHrAdministrator();
        [$rel, , $person] = $this->placed();
        $sec = $this->sec($rel, '2026-02-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-06-01');

        $response = $this->postJson($this->url($person, $rel, 'workplace-assignment-periods'), [
            'organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-03-01', 'decision_type_id' => $this->assignmentDecisionType()->id,
        ])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.workplace_assignment_period.start');
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertEquals([
            'superseded_movements' => [['stream' => 'full_secondment', 'period_id' => $sec->id, 'previous_effective_to' => '2026-06-01', 'effective_to' => '2026-03-01']],
            'superseded_at' => '2026-03-01',
        ], $entry->metadata);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));

        $this->postJson($this->url($person, $rel, 'full-secondment-periods'), [
            'organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01',
        ])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.full_secondment_period.start');
        $this->assertSame('workplace_assignment', $entry->metadata['superseded_movements'][0]['stream']);
        $this->assertNull($entry->metadata['superseded_movements'][0]['previous_effective_to']);
    }

    public function test_a_start_without_a_superseded_movement_keeps_empty_metadata(): void
    {
        $this->actingAsHrAdministrator();
        [$rel, , $person] = $this->placed();

        $this->postJson($this->url($person, $rel, 'full-secondment-periods'), [
            'organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01',
        ])->assertStatus(201);

        $this->assertEquals([], $this->latestAuditEntryFor('hr.full_secondment_period.start')->metadata);
    }

    public function test_superseding_a_movement_in_an_out_of_scope_unit_is_forbidden_and_changes_nothing(): void
    {
        [$rel, $home, $person] = $this->placed();
        $foreign = $this->createUnit();
        $this->asgInto($rel, $foreign, '2026-02-01');
        $destination = $this->createUnit();
        $principal = $this->principalWith([Perm::FULL_SECONDMENT_PERIODS_START]);
        $this->grantUnitScope($principal, $home);
        $this->grantUnitScope($principal, $destination);
        $before = $this->rows($rel);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $rel, 'full-secondment-periods'), [
            'organizational_unit_id' => $destination->id, 'effective_from' => '2026-03-01',
        ])->assertForbidden();

        $this->assertSame($before, $this->rows($rel));
        $this->assertSame($auditBefore, $this->auditEntriesCount());

        $this->grantUnitScope($principal, $foreign);
        $this->postJson($this->url($person, $rel, 'full-secondment-periods'), [
            'organizational_unit_id' => $destination->id, 'effective_from' => '2026-03-01',
        ])->assertStatus(201);
    }

    public function test_transfer_scope_covers_a_future_closed_movement_it_truncates(): void
    {
        [$rel, $home, $person] = $this->placed();
        $foreign = $this->createUnit();
        $this->secInto($rel, $foreign, '2026-02-01');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-06-01');
        $destination = $this->createUnit();
        $principal = $this->principalWith([Perm::EMPLOYMENT_RELATIONSHIPS_TRANSFER]);
        $this->grantUnitScope($principal, $home);
        $this->grantUnitScope($principal, $destination);

        $this->postJson($this->url($person, $rel, 'transfer'), [
            'organizational_unit_id' => $destination->id, 'effective_from' => '2026-03-01', 'decision_type_id' => $this->transferDecisionType()->id,
        ])->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{0: EmploymentRelationship, 1: OrganizationalUnit, 2: Person} */
    private function placed(): array
    {
        $person = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $home = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');

        return [$rel->refresh(), $home, $person];
    }

    private function sec(EmploymentRelationship $rel, string $from)
    {
        return $this->secInto($rel, $this->createUnit(), $from);
    }

    private function secInto(EmploymentRelationship $rel, OrganizationalUnit $unit, string $from)
    {
        return app(StartFullSecondment::class)->handle($rel->refresh(), $unit, $from);
    }

    private function asg(EmploymentRelationship $rel, string $from)
    {
        return $this->asgInto($rel, $this->createUnit(), $from);
    }

    private function asgInto(EmploymentRelationship $rel, OrganizationalUnit $unit, string $from)
    {
        return app(StartWorkplaceAssignment::class)->handle($rel->refresh(), $unit, $from, $this->assignmentDecisionType());
    }

    private function transfer(EmploymentRelationship $rel, string $date)
    {
        return app(TransferEmployee::class)->handle($rel->refresh(), $this->createUnit(), $date, $this->transferDecisionType());
    }

    private function asOf(EmploymentRelationship $rel, string $date): ActualWorkplaceAsOf
    {
        return app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel->refresh(), $date);
    }

    private function assertPeriod($period, string $from, ?string $to): void
    {
        $period->refresh();
        $this->assertSame($from, $period->effective_from->toDateString());
        $this->assertSame($to, $period->effective_to?->toDateString());
    }

    /** @return array{secondments: list<array>, assignments: list<array>} */
    private function rows(EmploymentRelationship $rel): array
    {
        $read = fn (string $table) => DB::table($table)->where('employment_relationship_id', $rel->id)->orderBy('effective_from')
            ->get(['id', 'organizational_unit_id', 'effective_from', 'effective_to'])->map(fn ($r) => (array) $r)->all();

        return ['secondments' => $read('hr.full_secondment_periods'), 'assignments' => $read('hr.workplace_assignment_periods')];
    }

    private function assertNoCrossStreamOverlap(EmploymentRelationship $rel): void
    {
        $overlaps = DB::selectOne(<<<'SQL'
            SELECT count(*) AS c
            FROM hr.full_secondment_periods s
            JOIN hr.workplace_assignment_periods a ON a.employment_relationship_id = s.employment_relationship_id
            WHERE s.employment_relationship_id = ?
              AND daterange(s.effective_from, s.effective_to, '[)') && daterange(a.effective_from, a.effective_to, '[)')
            SQL, [$rel->id])->c;

        $this->assertSame(0, (int) $overlaps, 'no secondment and assignment are effective on the same date');
    }

    private function insertLegacy(string $table, EmploymentRelationship $rel, string $from, ?string $to): string
    {
        $id = (string) Str::uuid7();
        DB::table($table)->insert(['id' => $id, 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $this->createUnit()->id,
            'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()]);

        return $id;
    }

    private function url(Person $person, EmploymentRelationship $rel, string $suffix): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/{$suffix}";
    }

    private function principalWith(array $permissions): Principal
    {
        $principal = $this->createPrincipal();
        $this->assignRole($principal, $this->createRoleWithPermissions($permissions));
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
