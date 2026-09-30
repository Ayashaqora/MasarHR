<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationship;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationshipAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * S35 — Employment Relationship End Movement Integrity (Full Secondment + Workplace Assignment).
 * Case 1: a movement crossing the end date E (open or bounded) is truncated to E. Case 2: a movement
 * ending on/before E is untouched. Case 3: a movement starting on/after E never blocks the end and is
 * kept exactly as recorded, never effective. Synthetic data only; no schema is involved.
 */
class EmploymentEndMovementIntegrityTest extends HumanResourcesTestCase
{
    private const E = '2026-11-01';

    private const TYPES = ['FULL_SECONDMENT' => 'hr.full_secondment_periods', 'WORKPLACE_ASSIGNMENT' => 'hr.workplace_assignment_periods'];

    private function emp(): array
    {
        $person = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-10');

        return [$person, $rel->refresh()];
    }

    /** Starts the movement, ends it at $to when bounded, and returns its id. */
    private function movement(string $type, $rel, string $from, ?string $to): string
    {
        $unit = $this->createUnit();
        if ($type === 'FULL_SECONDMENT') {
            $m = app(StartFullSecondment::class)->handle($rel->refresh(), $unit, $from);
            $to !== null && app(EndFullSecondment::class)->handle($rel->refresh(), $to);
        } else {
            $m = app(StartWorkplaceAssignment::class)->handle($rel->refresh(), $unit, $from, $this->assignmentDecisionType());
            $to !== null && app(EndWorkplaceAssignment::class)->handle($rel->refresh(), $to);
        }

        return $m->getKey();
    }

    private function row(string $type, string $id): array
    {
        $r = DB::table(self::TYPES[$type])->where('id', $id)->first();

        return [$r->effective_from, $r->effective_to];
    }

    private function endDirect($person, $rel, string $to = self::E): void
    {
        DB::transaction(fn () => app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, $to, false));
    }

    private function followUp(string $type, string $movementId, $rel, string $expectedTo): string
    {
        $id = (string) Str::uuid7();
        $column = $type === 'FULL_SECONDMENT' ? 'full_secondment_period_id' : 'workplace_assignment_period_id';
        DB::table('automation.movement_expiry_followups')->insert([
            'id' => $id, 'followup_kind' => 'EXPIRY_WARNING_7D', 'movement_type' => $type, $column => $movementId,
            'employment_relationship_id' => $rel->id, 'organizational_unit_id' => DB::table(self::TYPES[$type])->where('id', $movementId)->value('organizational_unit_id'),
            'expected_effective_to' => $expectedTo, 'due_date' => date('Y-m-d', strtotime($expectedTo.' -7 days')),
            'status' => 'ACTIONABLE', 'created_at' => now(),
        ]);

        return $id;
    }

    private function followUpState(string $id): array
    {
        $r = DB::table('automation.movement_expiry_followups')->where('id', $id)->first();

        return [$r->status, $r->suppression_reason];
    }

    public static function movementTypes(): array
    {
        return [['FULL_SECONDMENT'], ['WORKPLACE_ASSIGNMENT']];
    }

    // ---- functional matrix A–H, both movement types -------------------------------------------

    #[DataProvider('movementTypes')]
    public function test_matrix_a_to_h_for_each_movement_type(string $type): void
    {
        $cases = [
            'A open, starts before E' => ['2026-10-01', null, ['2026-10-01', self::E]],
            'B bounded, starts before E and ends after E' => ['2026-10-01', '2026-12-01', ['2026-10-01', self::E]],
            'C bounded, ends exactly at E' => ['2026-09-01', self::E, ['2026-09-01', self::E]],
            'D bounded, ends before E' => ['2026-09-01', '2026-10-01', ['2026-09-01', '2026-10-01']],
            'E open, starts exactly at E' => [self::E, null, [self::E, null]],
            'F bounded, starts exactly at E' => [self::E, '2026-12-01', [self::E, '2026-12-01']],
            'G open, starts after E' => ['2026-12-01', null, ['2026-12-01', null]],
            'H bounded, starts after E' => ['2026-12-01', '2027-01-01', ['2026-12-01', '2027-01-01']],
        ];

        foreach ($cases as $name => [$from, $to, $expected]) {
            [$person, $rel] = $this->emp();
            $id = $this->movement($type, $rel, $from, $to);

            $this->endDirect($person, $rel); // must always succeed — Case 3 never blocks the end

            $this->assertSame('KNOWN', $rel->refresh()->end_knowledge_state, "[$type/$name] relationship ended");
            $this->assertSame($expected, $this->row($type, $id), "[$type/$name] dates");

            // Nothing is effective through any reader from E on.
            foreach (['2026-11-01', '2026-11-15', '2026-12-15', '2027-06-01'] as $date) {
                $this->assertSame([], app(ListReportingPopulationAsOf::class)($date, [$rel->id]), "[$type/$name] S27 population on $date");
                $wp = app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel, $date);
                $this->assertSame('UNRESOLVED', $wp->state(), "[$type/$name] as-of workplace $date");
            }
            $this->assertFalse(app(ResolveActualWorkplaceForRelationship::class)($rel)->isResolved(), "[$type/$name] current workplace unresolved");
        }
    }

    #[DataProvider('movementTypes')]
    public function test_a_truncated_movement_stays_effective_up_to_but_excluding_the_end_date(string $type): void
    {
        [$person, $rel] = $this->emp();
        $this->movement($type, $rel, '2026-10-01', '2026-12-01');
        $this->endDirect($person, $rel);

        $before = app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel->refresh(), '2026-10-31');
        $this->assertSame($type === 'FULL_SECONDMENT' ? 'secondment' : 'assignment', $before->source());
        $this->assertSame('UNRESOLVED', app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel, self::E)->state(), 'the end is exclusive');
        $this->assertCount(1, app(ListReportingPopulationAsOf::class)('2026-10-31', [$rel->id]));
    }

    #[DataProvider('movementTypes')]
    public function test_both_streams_together_and_repeated_history_rows(string $type): void
    {
        [$person, $rel] = $this->emp();
        $done = $this->movement($type, $rel, '2026-08-01', '2026-09-01');
        $crossing = $this->movement($type, $rel, '2026-10-01', '2026-12-01');
        $future = $this->movement($type, $rel, '2026-12-01', '2027-01-01');

        $this->endDirect($person, $rel);

        $this->assertSame(['2026-08-01', '2026-09-01'], $this->row($type, $done));
        $this->assertSame(['2026-10-01', self::E], $this->row($type, $crossing));
        $this->assertSame(['2026-12-01', '2027-01-01'], $this->row($type, $future));
        $this->assertSame(3, DB::table(self::TYPES[$type])->where('employment_relationship_id', $rel->id)->count(), 'nothing deleted');
    }

    // ---- terminal status path is identical ----------------------------------------------------

    public function test_terminal_status_induced_end_has_the_same_movement_consequences_as_the_direct_end(): void
    {
        $shape = [
            ['FULL_SECONDMENT', '2026-10-01', null], ['FULL_SECONDMENT', '2026-10-05', '2026-12-01'],
            ['WORKPLACE_ASSIGNMENT', '2026-10-02', '2026-12-01'], ['WORKPLACE_ASSIGNMENT', '2026-12-01', '2027-01-01'],
            ['FULL_SECONDMENT', '2026-12-05', '2027-01-01'],
        ];
        $outcome = function (bool $viaStatus) use ($shape) {
            [$person, $rel] = $this->emp();
            // Same-stream periods must not overlap: place each shape on its own relationship.
            $result = [];
            foreach ($shape as [$type, $from, $to]) {
                [$person, $rel] = $this->emp();
                $id = $this->movement($type, $rel, $from, $to);
                $viaStatus
                    ? DB::transaction(fn () => app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail('resigned'), self::E))
                    : $this->endDirect($person, $rel);
                $result[] = [$type, $this->row($type, $id), $rel->refresh()->end_knowledge_state];
            }

            return $result;
        };

        $this->assertSame($outcome(false), $outcome(true));
    }

    public function test_a_terminal_status_end_is_not_blocked_by_a_future_movement(): void
    {
        [$person, $rel] = $this->emp();
        $id = $this->movement('FULL_SECONDMENT', $rel, '2026-12-01', null);

        DB::transaction(fn () => app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail('retired'), self::E));

        $this->assertSame('KNOWN', $rel->refresh()->end_knowledge_state);
        $this->assertSame(['2026-12-01', null], $this->row('FULL_SECONDMENT', $id));
    }

    // ---- end-command guards -------------------------------------------------------------------

    #[DataProvider('movementTypes')]
    public function test_end_commands_reject_an_ended_relationship_and_never_mutate_a_surviving_future_row(string $type): void
    {
        [$person, $rel] = $this->emp();
        $id = $this->movement($type, $rel, '2026-12-01', null);
        $this->endDirect($person, $rel);

        $command = $type === 'FULL_SECONDMENT' ? EndFullSecondment::class : EndWorkplaceAssignment::class;
        foreach (['2026-12-15', '2026-11-20'] as $to) {
            try {
                app($command)->handle($rel->refresh(), $to);
                $this->fail("[$type $to] must be rejected");
            } catch (EmploymentRelationshipAlreadyEndedException) {
                $this->assertSame(['2026-12-01', null], $this->row($type, $id));
            }
        }
    }

    #[DataProvider('movementTypes')]
    public function test_the_end_api_rejects_with_409_and_normal_end_still_works_on_an_active_relationship(string $type): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $path = $type === 'FULL_SECONDMENT' ? 'full-secondment-periods/end' : 'workplace-assignment-periods/end';
        $base = "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}";

        $id = $this->movement($type, $rel, '2026-10-01', null);
        $this->postJson("{$base}/{$path}", ['effective_to' => '2026-10-20'])->assertStatus(200);
        $this->assertSame(['2026-10-01', '2026-10-20'], $this->row($type, $id), 'normal end works');

        $future = $this->movement($type, $rel, '2026-12-01', null);
        $this->endDirect($person, $rel);
        $this->postJson("{$base}/{$path}", ['effective_to' => '2026-12-20'])->assertStatus(409);
        $this->assertSame(['2026-12-01', null], $this->row($type, $future));
    }

    // ---- S31 follow-ups -----------------------------------------------------------------------

    #[DataProvider('movementTypes')]
    public function test_actionable_follow_ups_of_truncated_and_future_movements_are_suppressed_atomically(string $type): void
    {
        [$person, $rel] = $this->emp();
        $crossing = $this->movement($type, $rel, '2026-10-01', '2026-11-20');
        $fu1 = $this->followUp($type, $crossing, $rel, '2026-11-20');
        $future = $this->movement($type, $rel, '2026-12-01', '2026-12-20');
        $fu2 = $this->followUp($type, $future, $rel, '2026-12-20');

        $this->endDirect($person, $rel);

        $this->assertSame(['SUPPRESSED', 'RELATIONSHIP_ENDED'], $this->followUpState($fu1));
        $this->assertSame(['SUPPRESSED', 'RELATIONSHIP_ENDED'], $this->followUpState($fu2));
        $this->assertSame(0, DB::table('automation.movement_expiry_followups')->where('employment_relationship_id', $rel->id)->where('status', 'ACTIONABLE')->count());
    }

    public function test_an_already_suppressed_follow_up_stays_historical_and_a_finished_movement_is_not_touched(): void
    {
        [$person, $rel] = $this->emp();
        $done = $this->movement('FULL_SECONDMENT', $rel, '2026-09-01', '2026-10-15');
        $fuDone = $this->followUp('FULL_SECONDMENT', $done, $rel, '2026-10-15'); // Case 2 movement: not directly affected
        $crossing = $this->movement('WORKPLACE_ASSIGNMENT', $rel, '2026-10-01', '2026-11-20');
        $old = $this->followUp('WORKPLACE_ASSIGNMENT', $crossing, $rel, '2026-11-20');
        DB::table('automation.movement_expiry_followups')->where('id', $old)->update(['status' => 'SUPPRESSED', 'suppression_reason' => 'END_DATE_CHANGED', 'suppressed_at' => '2026-10-02 00:00:00+00']);
        $before = (array) DB::table('automation.movement_expiry_followups')->where('id', $old)->first();

        $this->endDirect($person, $rel);

        $this->assertEquals($before, (array) DB::table('automation.movement_expiry_followups')->where('id', $old)->first(), 'historical suppression is not rewritten');
        $this->assertSame(['ACTIONABLE', null], $this->followUpState($fuDone), 'a Case 2 movement ended as planned: untouched');
    }

    public function test_a_rolled_back_end_rolls_back_the_truncation_and_the_suppression_too(): void
    {
        [$person, $rel] = $this->emp();
        app(RecordWorkSchedulePeriod::class)->handle($rel->refresh(), '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        $id = $this->movement('FULL_SECONDMENT', $rel, '2026-10-01', '2026-12-01');
        $fu = $this->followUp('FULL_SECONDMENT', $id, $rel, '2026-12-01');
        // A Partial Secondment starting after E still rejects the end (out of S35 scope, unchanged) — after the movement step ran.
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $this->createUnit(), '2026-12-05', null, ['MONDAY']);

        try {
            $this->endDirect($person, $rel);
            $this->fail('the unrelated partial secondment must reject the end');
        } catch (InvalidEndDateException) {
        }

        $this->assertSame('NOT_APPLICABLE', $rel->refresh()->end_knowledge_state);
        $this->assertSame(['2026-10-01', '2026-12-01'], $this->row('FULL_SECONDMENT', $id), 'truncation rolled back');
        $this->assertSame(['ACTIONABLE', null], $this->followUpState($fu), 'suppression rolled back');
    }

    // ---- audit metadata -----------------------------------------------------------------------

    public function test_direct_end_audit_distinguishes_open_bounded_future_and_suppressed_followups(): void
    {
        $this->actingAsHrAdministrator();
        $person = $this->createPersonRecord('9998887779');
        $rel = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-10');

        // A Full open across E; a later Assignment (future, starts at 12-01) supersedes it at 12-01 (S28), so the
        // Full becomes bounded [10-01, 12-01) crossing E and the Assignment is a future row (Case 3).
        $full = $this->movement('FULL_SECONDMENT', $rel, '2026-10-01', null);
        $future = $this->movement('WORKPLACE_ASSIGNMENT', $rel, '2026-12-01', null);
        $this->assertSame(['2026-10-01', '2026-12-01'], $this->row('FULL_SECONDMENT', $full), 'fixture: superseded at the assignment start');
        $fu = $this->followUp('FULL_SECONDMENT', $full, $rel, '2026-12-01');

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/end", ['expected_version' => $rel->refresh()->version, 'effective_to' => self::E, 'is_terminal' => false])->assertStatus(200);

        $metadata = $this->latestAuditEntryFor('hr.employment_relationship.end')->metadata;
        $this->assertSame([$full], $metadata['full_secondment_bounded_truncated_ids']);
        $this->assertSame([$future], $metadata['workplace_assignment_future_neutralized_ids']);
        $this->assertArrayNotHasKey('full_secondment_open_closed_ids', $metadata);
        $this->assertSame([$fu], $metadata['movement_expiry_followups_suppressed_ids']);
        $this->assertSame(1, $metadata['movement_expiry_followups_suppressed_count']);
        $this->assertStringNotContainsString('9998887779', json_encode($metadata));
        $this->assertSame(['2026-10-01', self::E], $this->row('FULL_SECONDMENT', $full));
        $this->assertSame(['2026-12-01', null], $this->row('WORKPLACE_ASSIGNMENT', $future));
    }

    public function test_an_open_movement_closed_at_the_end_is_reported_as_open_closed_and_existing_flags_are_preserved(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $open = $this->movement('WORKPLACE_ASSIGNMENT', $rel, '2026-10-01', null);
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/end", ['expected_version' => $rel->version, 'effective_to' => self::E, 'is_terminal' => false])->assertStatus(200);

        $metadata = $this->latestAuditEntryFor('hr.employment_relationship.end')->metadata;
        $this->assertSame([$open], $metadata['workplace_assignment_open_closed_ids']);
        $this->assertTrue($metadata['workplace_assignment_closed_as_consequence'], 'existing semantics preserved');
        $this->assertArrayNotHasKey('movement_expiry_followups_suppressed_ids', $metadata);
    }

    public function test_future_movements_are_recorded_as_neutralized_and_terminal_status_end_exposes_equivalent_evidence(): void
    {
        $this->actingAsHrAdministrator();
        foreach (['direct', 'status'] as $path) {
            [$person, $rel] = $this->emp();
            $bounded = $this->movement('WORKPLACE_ASSIGNMENT', $rel, '2026-10-05', '2026-12-01');
            $future = $this->movement('FULL_SECONDMENT', $rel, '2026-12-01', '2027-01-01');
            $fu = $this->followUp('WORKPLACE_ASSIGNMENT', $bounded, $rel, '2026-12-01');
            $base = "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}";

            $path === 'direct'
                ? $this->postJson("{$base}/end", ['expected_version' => $rel->version, 'effective_to' => self::E, 'is_terminal' => false])->assertStatus(200)
                : $this->postJson("{$base}/status-periods", ['status_detail_code' => 'resigned', 'effective_from' => self::E])->assertStatus(201);

            $metadata = $this->latestAuditEntryFor($path === 'direct' ? 'hr.employment_relationship.end' : 'hr.employment_status_period.record')->metadata;
            $this->assertSame([$future], $metadata['full_secondment_future_neutralized_ids'], "[$path]");
            $this->assertSame([$bounded], $metadata['workplace_assignment_bounded_truncated_ids'], "[$path]");
            $this->assertSame([$fu], $metadata['movement_expiry_followups_suppressed_ids'], "[$path]");
            $this->assertSame(1, $metadata['movement_expiry_followups_suppressed_count'], "[$path]");
        }
    }

    // ---- Employee 360 data contract -----------------------------------------------------------

    public function test_the_employee_360_reads_show_no_current_movement_after_the_end_and_history_shows_recorded_dates(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $crossing = $this->movement('FULL_SECONDMENT', $rel, '2026-10-01', '2026-12-01');
        $future = $this->movement('WORKPLACE_ASSIGNMENT', $rel, '2026-12-01', null);
        $this->endDirect($person, $rel);
        $base = "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}";

        $this->getJson("{$base}/actual-workplace")->assertOk()->assertJsonPath('organizational_unit_id', null)->assertJsonPath('source', null);

        $full = collect($this->getJson("{$base}/full-secondment-periods")->assertOk()->json())->firstWhere('id', $crossing);
        $this->assertSame(self::E, $full['effective_to'], 'Case 1 history shows the truncated date');
        $assign = collect($this->getJson("{$base}/workplace-assignment-periods")->assertOk()->json())->firstWhere('id', $future);
        $this->assertSame(['2026-12-01', null], [$assign['effective_from'], $assign['effective_to']], 'Case 3 keeps its recorded dates as history');
    }
}
