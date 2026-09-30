<?php

namespace Tests\Feature\HumanResources;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\ScanMovementExpiryFollowUps;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveWeekdayActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\ExpiryFollowUpEmission;
use App\Modules\HumanResources\Domain\ExpiryFollowUpScanResult;
use App\Modules\HumanResources\Domain\FollowUpSuppressionReason;
use App\Modules\HumanResources\Domain\MovementExpiryPolicy;
use App\Modules\HumanResources\Domain\TemporaryMovementType;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\MovementExpiryFollowUp;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FixedBusinessDateClock;

/**
 * S31 Movement Expiry & Follow-up Foundation (docs/movement-expiry-followup-foundation-
 * specification.md, ADR-S31-001…018): the 7-calendar-day follow-up for bounded temporary workplace
 * movements (Full Secondment, Workplace Assignment, Partial Secondment) — eligibility, the exact due
 * date and window, idempotency, mandatory stale-alert recheck and suppression, the activation
 * boundary (no retroactive alerts), automatic return as a DERIVED state (no fake return movement, no
 * timeline mutation by the scanner), relationship lifecycle, the SYSTEM-actor audit, the read API
 * with RBAC and S08 scope, and PostgreSQL integrity. Real cross-session races live in
 * ConcurrencyTest. Every fixture is synthetic; the business date is always explicit or a fixed clock.
 */
class MovementExpiryFollowUpFoundationTest extends HumanResourcesTestCase
{
    private const SUN_THU = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY'];

    private FixedBusinessDateClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedBusinessDateClock('2026-04-24');
        $this->app->instance(BusinessDateClock::class, $this->clock);
    }

    // ---------------------------------------------------------------------
    // A–C. Eligibility: every bounded temporary movement type
    // ---------------------------------------------------------------------

    public function test_a_a_bounded_full_secondment_creates_its_follow_up_on_the_due_date(): void
    {
        [, $rel] = $this->employee();
        $dest = $this->createUnit();
        $full = $this->full($rel, $dest, '2026-03-01', '2026-05-01');

        $result = $this->scan('2026-04-24');

        $this->assertCount(1, $result->emitted);
        $row = MovementExpiryFollowUp::query()->findOrFail($result->emitted[0]);
        $this->assertSame('EXPIRY_WARNING_7D', $row->followup_kind);
        $this->assertSame('FULL_SECONDMENT', $row->movement_type);
        $this->assertSame($full->id, $row->movement_id);
        $this->assertSame($rel->id, $row->employment_relationship_id);
        $this->assertSame($dest->id, $row->organizational_unit_id);
        $this->assertSame('2026-05-01', $row->expected_effective_to->toDateString());
        $this->assertSame('2026-04-24', $row->due_date->toDateString());
        $this->assertSame('ACTIONABLE', $row->status);
        $this->assertNull($row->suppression_reason);
    }

    public function test_b_a_bounded_workplace_assignment_creates_its_follow_up(): void
    {
        [, $rel] = $this->employee();
        $assignment = $this->assignment($rel, $this->createUnit(), '2026-03-01', '2026-05-01');

        $result = $this->scan('2026-04-24');

        $this->assertCount(1, $result->emitted);
        $row = MovementExpiryFollowUp::query()->findOrFail($result->emitted[0]);
        $this->assertSame(['WORKPLACE_ASSIGNMENT', $assignment->id], [$row->movement_type, $row->movement_id]);
    }

    public function test_c_t_a_partial_secondment_yields_exactly_one_follow_up_whatever_its_weekday_count(): void
    {
        [, $rel] = $this->employee();
        $one = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY']);
        $many = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-05-01', ['SUNDAY', 'TUESDAY', 'WEDNESDAY']);

        $result = $this->scan('2026-04-24');

        $this->assertCount(2, $result->emitted, 'one logical follow-up per Partial period — never one per weekday');
        $this->assertEqualsCanonicalizing([$one->id, $many->id], MovementExpiryFollowUp::query()->pluck('movement_id')->all());
        $this->assertSame(2, MovementExpiryFollowUp::query()->count());
    }

    // ---------------------------------------------------------------------
    // D–F. Open-ended movements have no expiry (ADR-S31-004)
    // ---------------------------------------------------------------------

    public function test_d_e_f_open_ended_movements_never_get_a_follow_up(): void
    {
        [, $fullRel] = $this->employee();
        [, $assignmentRel] = $this->employee();
        [, $partialRel] = $this->employee();
        $this->full($fullRel, $this->createUnit(), '2026-03-01', null);
        $this->assignment($assignmentRel, $this->createUnit(), '2026-03-01', null);
        $this->partial($partialRel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);

        foreach (['2026-03-01', '2026-04-24', '2026-05-01', '2027-01-01', '2030-06-01'] as $date) {
            $result = $this->scan($date);
            $this->assertSame([], $result->emitted, "no end date is invented for an open-ended movement ({$date})");
        }

        $this->assertSame(0, MovementExpiryFollowUp::query()->count());
    }

    // ---------------------------------------------------------------------
    // G–J. The exact 7-calendar-day rule, window, idempotency
    // ---------------------------------------------------------------------

    public function test_g_the_due_date_is_exactly_seven_calendar_days_before_the_end_regardless_of_weekday_or_month(): void
    {
        foreach ([
            '2026-05-01' => '2026-04-24', // Friday
            '2026-05-05' => '2026-04-28', // Tuesday
            '2026-03-03' => '2026-02-24', // crosses a month boundary
            '2026-01-04' => '2025-12-28', // crosses a year boundary
            '2028-03-05' => '2028-02-27', // leap year
        ] as $end => $due) {
            $this->assertSame($due, MovementExpiryPolicy::dueDate($end), "{$end} - 7 calendar days");
        }

        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-05');
        $this->scan('2026-04-28');

        $this->assertSame('2026-04-28', MovementExpiryFollowUp::query()->firstOrFail()->due_date->toDateString());
        $this->assertSame(7, MovementExpiryPolicy::WARNING_LEAD_DAYS);
    }

    public function test_h_i_nothing_is_emitted_before_the_due_date_and_it_is_emitted_on_it(): void
    {
        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');

        $this->assertSame([], $this->scan('2026-04-23')->emitted, 'H: one day early — no premature occurrence');
        $this->assertSame(0, MovementExpiryFollowUp::query()->count());

        $this->assertCount(1, $this->scan('2026-04-24')->emitted, 'I: on the due date');
    }

    public function test_the_window_is_due_date_inclusive_and_end_date_exclusive(): void
    {
        $this->assertTrue(MovementExpiryPolicy::isInWindow('2026-05-01', '2026-04-24'));
        $this->assertTrue(MovementExpiryPolicy::isInWindow('2026-05-01', '2026-04-30'));
        $this->assertFalse(MovementExpiryPolicy::isInWindow('2026-05-01', '2026-05-01'), 'no longer effective at the end date');
        $this->assertFalse(MovementExpiryPolicy::isInWindow('2026-05-01', '2026-04-23'));

        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $this->assertCount(1, $this->scan('2026-04-30')->emitted, 'a late (catch-up) scan inside the window still emits');

        [, $late] = $this->employee();
        $this->full($late, $this->createUnit(), '2026-03-01', '2026-05-01');
        // Both movements share E; the second is discovered on the end date itself: not emitted.
        $result = $this->scan('2026-05-01');
        $this->assertSame([], $result->emitted);
    }

    public function test_j_repeated_scans_never_duplicate_a_follow_up_or_its_audit(): void
    {
        [, $rel] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $first = $this->scan('2026-04-24');
        $audit = $this->auditEntriesCount();

        foreach (['2026-04-24', '2026-04-25', '2026-04-30'] as $date) {
            $again = $this->scan($date);
            $this->assertSame([], $again->emitted);
            $this->assertSame([], $again->suppressed);
        }

        $this->assertCount(1, $first->emitted);
        $this->assertSame(1, MovementExpiryFollowUp::query()->count());
        $this->assertSame($audit, $this->auditEntriesCount(), 'a repeated scan that finds nothing new writes no audit entry');

        // The idempotent ON CONFLICT path itself: emitting the same logical key again is a no-op.
        $again = $this->emitOne(TemporaryMovementType::FullSecondment, $full->id, $rel->id, '2026-05-01', '2026-04-25');
        $this->assertSame(ExpiryFollowUpEmission::ALREADY_EXISTS, $again->outcome);
        $this->assertSame(1, MovementExpiryFollowUp::query()->count());
        $this->assertSame($audit, $this->auditEntriesCount());
    }

    // ---------------------------------------------------------------------
    // L–N, U, AP. Stale-alert recheck and suppression (ADR-S31-005/006)
    // ---------------------------------------------------------------------

    public function test_l_a_movement_truncated_after_emission_suppresses_the_stale_alert_and_derives_the_new_end_independently(): void
    {
        [, $rel] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $emitted = $this->scan('2026-04-24')->emitted[0];

        // A transfer (permanent) legitimately stops the temporary movement on 2026-04-27 (S14/S28).
        app(TransferEmployee::class)->handle($rel, $this->createUnit(), '2026-04-27', $this->transferDecisionType());
        $this->assertSame('2026-04-27', $full->refresh()->effective_to->toDateString());

        $result = $this->scan('2026-04-25');

        $this->assertSame([$emitted], $result->suppressed);
        $old = MovementExpiryFollowUp::query()->findOrFail($emitted);
        $this->assertSame('SUPPRESSED', $old->status);
        $this->assertSame('TRUNCATED_EARLIER', $old->suppression_reason);
        $this->assertNotNull($old->suppressed_at);
        $this->assertSame('2026-05-01', $old->expected_effective_to->toDateString(), 'the stale record itself is never rewritten');

        $this->assertCount(1, $result->emitted, 'the movement genuinely ends on 04-27: that is its OWN logical follow-up');
        $new = MovementExpiryFollowUp::query()->findOrFail($result->emitted[0]);
        $this->assertSame(['2026-04-27', '2026-04-20', 'ACTIONABLE'], [$new->expected_effective_to->toDateString(), $new->due_date->toDateString(), $new->status]);
        $this->assertSame($full->id, $new->movement_id);
    }

    public function test_l_supersession_by_an_open_assignment_suppresses_the_alert_and_raises_no_new_one(): void
    {
        [, $rel] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $emitted = $this->scan('2026-04-24')->emitted[0];

        // ADR-S28-001: a newer movement stops the previous one on 04-27 — and itself governs from then.
        $this->assignment($rel, $this->createUnit(), '2026-04-27', null);
        $this->assertSame('2026-04-27', $full->refresh()->effective_to->toDateString());

        $result = $this->scan('2026-04-25');

        $this->assertSame([$emitted], $result->suppressed);
        $this->assertSame('TRUNCATED_EARLIER', MovementExpiryFollowUp::query()->findOrFail($emitted)->suppression_reason);
        $this->assertSame([], $result->emitted, 'the open assignment covers the new end: the employee does not return on 04-27');
        $this->assertSame('COVERED_BY_NEWER_MOVEMENT', $result->staleBeforeEmission[0]['reason']);
    }

    public function test_m_a_candidate_whose_movement_changed_between_discovery_and_emission_is_never_emitted(): void
    {
        [$person, $rel, $origin] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $audit = $this->auditEntriesCount();

        // Discovery would have produced (FULL, $full, 2026-05-01); a transfer truncates it first.
        app(TransferEmployee::class)->handle($rel, $this->createUnit(), '2026-04-27', $this->transferDecisionType());

        $emission = $this->emitOne(TemporaryMovementType::FullSecondment, $full->id, $rel->id, '2026-05-01', '2026-04-25');

        $this->assertSame(ExpiryFollowUpEmission::STALE, $emission->outcome);
        $this->assertSame(FollowUpSuppressionReason::TruncatedEarlier, $emission->reason);
        $this->assertSame(0, MovementExpiryFollowUp::query()->count(), 'nothing is written for a stale candidate');
        $this->assertSame($audit, $this->auditEntriesCount(), 'AB: a stale condition produces no success audit');
        $this->assertNull(AuditEntry::query()->where('action', 'hr.movement_expiry_followup.emit')->first());
    }

    public function test_an_end_date_changed_in_any_other_way_is_recognised_and_a_later_end_is_not_truncation(): void
    {
        [, $rel] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $emitted = $this->scan('2026-04-24')->emitted[0];

        // Legacy / directly-written history: the end moved LATER (commands never do this).
        DB::table('hr.full_secondment_periods')->where('id', $full->id)->update(['effective_to' => '2026-06-01']);

        $result = $this->scan('2026-04-25');

        $this->assertSame([$emitted], $result->suppressed);
        $this->assertSame('END_DATE_CHANGED', MovementExpiryFollowUp::query()->findOrFail($emitted)->suppression_reason);
        $this->assertSame([], $result->emitted, 'the new end (06-01) is not yet inside its own window');
    }

    public function test_n_a_newer_movement_covering_the_old_expiry_suppresses_the_alert_and_inserts_no_fake_return(): void
    {
        [, $rel, $origin] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $emitted = $this->scan('2026-04-24')->emitted[0];
        $before = $this->timeline();

        // An extension is a NEW movement starting exactly at the old end — never an overwrite.
        $next = $this->createUnit();
        $assignment = $this->assignment($rel, $next, '2026-05-01', null);

        $result = $this->scan('2026-04-25');

        $this->assertSame([$emitted], $result->suppressed);
        $this->assertSame('COVERED_BY_NEWER_MOVEMENT', MovementExpiryFollowUp::query()->findOrFail($emitted)->suppression_reason);
        $this->assertSame('2026-05-01', $full->refresh()->effective_to->toDateString(), 'the historical period is retained untouched');
        $this->assertSame(1, WorkplaceAssignmentPeriod::query()->where('employment_relationship_id', $rel->id)->count());
        $this->assertNotSame($before, $this->timeline(), 'only the explicit assignment command changed the timeline');

        $atExpiry = $this->dateOnly($rel, '2026-05-01');
        $this->assertSame([$next->id, 'assignment'], [$atExpiry->organizationalUnitId(), $atExpiry->source()], 'the newer movement governs — no automatic return to the origin on the old expiry');
        $this->assertNotSame($origin->id, $atExpiry->organizationalUnitId());
        $this->assertNotNull($assignment->id);

        // A candidate discovered (not yet emitted) for the same covered expiry is never emitted.
        [, $rel2] = $this->employee();
        $full2 = $this->full($rel2, $this->createUnit(), '2026-03-01', '2026-05-01');
        $this->assignment($rel2, $this->createUnit(), '2026-05-01', null);
        $stale = $this->emitOne(TemporaryMovementType::FullSecondment, $full2->id, $rel2->id, '2026-05-01', '2026-04-24');
        $this->assertSame(FollowUpSuppressionReason::CoveredByNewerMovement, $stale->reason);
    }

    public function test_a_partial_is_covered_only_when_other_partials_together_allocate_all_its_weekdays(): void
    {
        [, $rel] = $this->employee();
        $p = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY', 'TUESDAY']);
        $this->partial($rel, $this->createUnit(), '2026-05-01', null, ['MONDAY']); // continues only MONDAY

        $partlyCovered = $this->emitOne(TemporaryMovementType::PartialSecondment, $p->id, $rel->id, '2026-05-01', '2026-04-24');
        $this->assertSame(ExpiryFollowUpEmission::EMITTED, $partlyCovered->outcome, 'TUESDAY returns to the underlying workplace: the warning is still relevant');

        [, $rel2] = $this->employee();
        $q = $this->partial($rel2, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY', 'TUESDAY']);
        $this->partial($rel2, $this->createUnit(), '2026-05-01', null, ['MONDAY']);
        $this->partial($rel2, $this->createUnit(), '2026-05-01', null, ['TUESDAY']);
        $covered = $this->emitOne(TemporaryMovementType::PartialSecondment, $q->id, $rel2->id, '2026-05-01', '2026-04-24');
        $this->assertSame(FollowUpSuppressionReason::CoveredByNewerMovement, $covered->reason, 'every weekday is continued: fully covered');

        [, $rel3] = $this->employee();
        $r = $this->partial($rel3, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY']);
        $this->assignment($rel3, $this->createUnit(), '2026-05-01', null);
        $byAssignment = $this->emitOne(TemporaryMovementType::PartialSecondment, $r->id, $rel3->id, '2026-05-01', '2026-04-24');
        $this->assertSame(FollowUpSuppressionReason::CoveredByNewerMovement, $byAssignment->reason, 'a whole-workplace assignment starting at the expiry covers it');
    }

    public function test_u_a_known_relationship_end_suppresses_the_stale_warning_and_is_respected(): void
    {
        [$person, $rel] = $this->employee();
        $partial = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['MONDAY']);
        $emitted = $this->scan('2026-05-26')->emitted[0];

        // Employment ends on 2026-05-30: the Partial Secondment is closed there (S30) — before its planned end.
        $this->end($person, $rel, '2026-05-30');
        $this->assertSame('2026-05-30', $partial->refresh()->effective_to->toDateString());

        $result = $this->scan('2026-05-27');

        $this->assertSame([$emitted], $result->suppressed);
        $this->assertSame('RELATIONSHIP_ENDED', MovementExpiryFollowUp::query()->findOrFail($emitted)->suppression_reason, 'the root cause wins over TRUNCATED_EARLIER');
        $this->assertSame([], $result->emitted, 'the truncated end (05-30) coincides with employment end: no return to act on');
        $this->assertSame([['movement_type' => 'PARTIAL_SECONDMENT', 'movement_id' => $partial->id, 'reason' => 'RELATIONSHIP_ENDED']], $result->staleBeforeEmission);
        $this->assertSame(1, MovementExpiryFollowUp::query()->count());
    }

    public function test_u_a_bounded_full_secondment_crossing_the_employment_end_is_truncated_and_its_follow_up_suppressed_atomically(): void
    {
        // S35 supersedes the S15 gap: a bounded secondment crossing the employment end is truncated at it, and
        // its ACTIONABLE follow-up is suppressed (RELATIONSHIP_ENDED) in the same transaction as the end.
        [$person, $rel] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-06-01');
        $emitted = $this->scan('2026-05-26')->emitted[0];

        $this->end($person, $rel, '2026-05-30');
        $this->assertSame('2026-05-30', $full->refresh()->effective_to->toDateString(), 'truncated at the employment end by the end itself');
        $this->assertSame('RELATIONSHIP_ENDED', MovementExpiryFollowUp::query()->findOrFail($emitted)->suppression_reason, 'suppressed in the same transaction as the end');

        $result = $this->scan('2026-05-27');

        $this->assertSame([], $result->suppressed, 'nothing left for the scanner to suppress');
        $this->assertSame([], $result->emitted);
        $this->assertSame(1, MovementExpiryFollowUp::query()->count(), 'the suppressed record is the logical follow-up for (movement, 06-01): never emitted again');
    }

    public function test_ap_a_movement_that_ends_with_a_future_known_relationship_end_is_never_alerted(): void
    {
        [$person, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', null);
        $this->end($person, $rel, '2026-06-01'); // closes the open secondment at 06-01

        $result = $this->scan('2026-05-26');

        $this->assertSame([], $result->emitted);
        $this->assertSame('RELATIONSHIP_ENDED', $result->staleBeforeEmission[0]['reason']);
        $this->assertSame(0, MovementExpiryFollowUp::query()->count());
    }

    public function test_ap_status_triggered_termination_is_respected_the_same_way(): void
    {
        [$person, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-10-01', '2026-11-01');
        $emitted = $this->scan('2026-10-25')->emitted[0];

        // The seeded status behaviors are effective from 2026-09-26; this terminates the relationship.
        app(RecordEmploymentStatusPeriod::class)->handle($person, $rel, $this->statusDetail('resigned'), '2026-10-28');
        $this->assertSame('KNOWN', $rel->refresh()->end_knowledge_state);

        $this->assertSame('RELATIONSHIP_ENDED', MovementExpiryFollowUp::query()->findOrFail($emitted)->suppression_reason, 'S35: suppressed atomically by the status-triggered end');

        $this->assertSame([], $this->scan('2026-10-26')->suppressed);
    }

    public function test_ao_an_unknown_legacy_end_is_not_a_known_end_and_no_date_is_fabricated(): void
    {
        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        DB::table('hr.employment_relationships')->where('id', $rel->id)->update(['end_knowledge_state' => 'UNKNOWN_LEGACY']);

        $result = $this->scan('2026-04-24');

        $this->assertCount(1, $result->emitted, 'UNKNOWN_LEGACY does not suppress: it is not a known end');
        $relationship = $rel->refresh();
        $this->assertSame('UNKNOWN_LEGACY', $relationship->end_knowledge_state);
        $this->assertNull($relationship->effective_to, 'the scanner never fabricates a relationship end');
    }

    // ---------------------------------------------------------------------
    // V–W. Activation boundary (ADR-S31-017)
    // ---------------------------------------------------------------------

    public function test_v_historical_movements_that_already_expired_generate_no_retroactive_alert(): void
    {
        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-02-01', '2026-03-01');
        $this->assignment($rel, $this->createUnit(), '2026-03-01', '2026-04-01');
        [, $rel2] = $this->employee();
        $this->partial($rel2, $this->createUnit(), '2026-02-01', '2026-03-15', ['MONDAY']);

        foreach (['2026-03-01', '2026-03-15', '2026-04-01', '2026-06-01', '2027-01-01'] as $date) {
            $this->assertSame([], $this->scan($date)->emitted, "the movements ended on or before {$date}");
        }

        $this->assertSame(0, MovementExpiryFollowUp::query()->count());
        $this->assertSame(0, (int) DB::table('automation.movement_expiry_followups')->count(), 'the migration and scans backfill nothing');
    }

    public function test_w_a_movement_still_inside_its_window_at_first_discovery_is_found_and_a_future_movement_too(): void
    {
        [, $rel] = $this->employee();
        // Due date (04-20) already passed but the end (04-27) is ahead: still relevant, so discovered.
        $current = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-04-27');
        // A future-dated movement that starts and ends inside the window.
        [, $rel2] = $this->employee();
        $future = $this->partial($rel2, $this->createUnit(), '2026-04-25', '2026-04-27', ['MONDAY']);
        // A movement ending far in the future is not discovered yet.
        [, $rel3] = $this->employee();
        $this->full($rel3, $this->createUnit(), '2026-03-01', '2027-01-01');

        $result = $this->scan('2026-04-22');

        $this->assertCount(2, $result->emitted);
        $this->assertEqualsCanonicalizing([$current->id, $future->id], MovementExpiryFollowUp::query()->pluck('movement_id')->all());
    }

    // ---------------------------------------------------------------------
    // O–S, AK, AL. Automatic return is derived, never written
    // ---------------------------------------------------------------------

    public function test_o_full_secondment_expiry_returns_to_the_original_workplace_without_any_write(): void
    {
        [, $rel, $origin] = $this->employee();
        $dest = $this->createUnit();
        $this->full($rel, $dest, '2026-03-01', '2026-05-01');
        $placements = $this->placementRows($rel);

        $this->assertSame([$dest->id, 'secondment'], $this->pair($this->dateOnly($rel, '2026-04-30')));
        $this->assertSame([$origin->id, 'placement'], $this->pair($this->dateOnly($rel, '2026-05-01')), 'no longer effective at its end date');
        $this->assertSame([$origin->id, 'placement'], $this->pair($this->dateOnly($rel, '2026-09-01')));
        $this->assertSame($placements, $this->placementRows($rel), 'the original placement is never mutated by an expiry');
    }

    public function test_p_workplace_assignment_expiry_returns_to_the_original_workplace(): void
    {
        [, $rel, $origin] = $this->employee();
        $dest = $this->createUnit();
        $this->assignment($rel, $dest, '2026-03-01', '2026-05-01');

        $this->assertSame([$dest->id, 'assignment'], $this->pair($this->dateOnly($rel, '2026-04-30')));
        $this->assertSame([$origin->id, 'placement'], $this->pair($this->dateOnly($rel, '2026-05-01')));
    }

    public function test_q_r_s_partial_secondment_expiry_removes_the_period_from_weekday_resolution_naturally(): void
    {
        [, $rel, $origin] = $this->employee();
        $dest = $this->createUnit();
        $this->partial($rel, $dest, '2026-03-01', '2026-05-01', ['MONDAY', 'TUESDAY']);

        // Q: an allocated weekday before expiry resolves to the Partial destination.
        $monday = $this->weekday($rel, '2026-04-27', 'MONDAY');
        $this->assertSame([$dest->id, 'partial_secondment'], [$monday->organizationalUnitId(), $monday->source()]);
        $tuesday = $this->weekday($rel, '2026-04-28');
        $this->assertSame($dest->id, $tuesday->organizationalUnitId());

        // R: the same weekday at / after the expiry resolves to the underlying workplace.
        foreach (['2026-05-01', '2026-05-04', '2026-06-01'] as $date) {
            $after = $this->weekday($rel, $date, 'MONDAY');
            $this->assertSame([$origin->id, 'placement'], [$after->organizationalUnitId(), $after->source()], "MONDAY on {$date}");
        }

        // S: an unallocated scheduled weekday is the underlying workplace before and after.
        $this->assertSame($origin->id, $this->weekday($rel, '2026-04-29', 'WEDNESDAY')->organizationalUnitId());
        $this->assertSame($origin->id, $this->weekday($rel, '2026-05-06', 'WEDNESDAY')->organizationalUnitId());
    }

    public function test_ak_al_the_scanner_never_mutates_the_hr_timeline_or_inserts_a_return_movement(): void
    {
        [$person, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        [, $rel2] = $this->employee();
        $this->assignment($rel2, $this->createUnit(), '2026-03-01', '2026-05-02');
        [, $rel3] = $this->employee();
        $this->partial($rel3, $this->createUnit(), '2026-03-01', '2026-05-03', ['MONDAY']);
        $before = $this->hrSnapshot();

        foreach (['2026-04-24', '2026-04-27', '2026-05-01', '2026-05-02', '2026-05-03', '2026-05-10'] as $date) {
            $this->scan($date);
        }

        $this->assertSame($before, $this->hrSnapshot(), 'every hr.* row is byte-for-byte unchanged: no return movement, no truncation, no extension');
    }

    // ---------------------------------------------------------------------
    // AJ, AN. Transfer and reappointment isolation
    // ---------------------------------------------------------------------

    public function test_aj_transfer_and_placement_history_are_outside_expiry_alert_semantics(): void
    {
        $this->assertSame(['FULL_SECONDMENT', 'WORKPLACE_ASSIGNMENT', 'PARTIAL_SECONDMENT'], array_map(fn (TemporaryMovementType $t) => $t->value, TemporaryMovementType::cases()));

        [, $rel] = $this->employee(); // origin placement from 2026-01-15
        // The transfer closes the origin placement (a BOUNDED row ending 2026-04-01).
        app(TransferEmployee::class)->handle($rel, $this->createUnit(), '2026-04-01', $this->transferDecisionType());
        $this->assertSame('2026-04-01', DB::table('hr.organizational_placement_periods')->where('employment_relationship_id', $rel->id)->whereNotNull('effective_to')->value('effective_to'));

        foreach (['2026-03-25', '2026-03-28', '2026-03-31'] as $date) {
            $this->assertSame([], $this->scan($date)->emitted, 'a permanent transfer / placement end is not a movement expiry');
        }
        $this->assertSame(0, MovementExpiryFollowUp::query()->count());
    }

    public function test_an_reappointment_keeps_follow_ups_isolated_per_relationship(): void
    {
        [$person, $old] = $this->employee();
        $oldFull = $this->full($old, $this->createUnit(), '2026-03-01', '2026-05-01');
        $oldRow = $this->scan('2026-04-24')->emitted[0];
        $this->end($person, $old, '2026-04-28');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-06-01');
        $this->recordPlacement($new, $this->createUnit(), '2026-06-02');
        $newFull = $this->full($new, $this->createUnit(), '2026-06-10', '2026-08-01');

        $this->scan('2026-04-25'); // suppresses the old relationship's alert
        $result = $this->scan('2026-07-25');

        $this->assertSame($old->id, MovementExpiryFollowUp::query()->findOrFail($oldRow)->employment_relationship_id);
        $this->assertSame('SUPPRESSED', MovementExpiryFollowUp::query()->findOrFail($oldRow)->status);
        $this->assertCount(1, $result->emitted);
        $newRow = MovementExpiryFollowUp::query()->findOrFail($result->emitted[0]);
        $this->assertSame([$new->id, $newFull->id], [$newRow->employment_relationship_id, $newRow->movement_id], 'the new relationship inherits nothing from the old one');
        $this->assertNotSame($oldFull->id, $newRow->movement_id);
        $this->assertSame(1, MovementExpiryFollowUp::query()->where('employment_relationship_id', $new->id)->count());
    }

    // ---------------------------------------------------------------------
    // Z, AA. Audit (ADR-S31-016)
    // ---------------------------------------------------------------------

    public function test_z_the_emission_audit_is_a_system_actor_entry_with_ids_dates_and_no_pii(): void
    {
        [$person, $rel] = $this->employee();
        $dest = $this->createUnit();
        $full = $this->full($rel, $dest, '2026-03-01', '2026-05-01');

        $emitted = $this->scan('2026-04-24')->emitted[0];

        $entry = $this->latestAuditEntryFor('hr.movement_expiry_followup.emit');
        $this->assertSame('SYSTEM', $entry->actor_type->value);
        $this->assertNull($entry->actor_principal_id);
        $this->assertSame('SCHEDULER_MOVEMENT_EXPIRY', $entry->actor_label);
        $this->assertSame(Source::System, $entry->source);
        $this->assertSame('hr_movement_expiry_followup', $entry->target_type);
        $this->assertSame($emitted, $entry->target_id);
        $this->assertEquals([
            'followup_kind' => 'EXPIRY_WARNING_7D', 'movement_type' => 'FULL_SECONDMENT', 'movement_id' => $full->id,
            'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $dest->id,
            'expected_effective_to' => '2026-05-01', 'due_date' => '2026-04-24', 'status' => 'ACTIONABLE',
        ], $entry->changes);
        $this->assertEquals(['business_date' => '2026-04-24'], $entry->metadata);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    public function test_aa_the_suppression_audit_records_the_reason_and_the_end_dates(): void
    {
        [, $rel] = $this->employee();
        $full = $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $emitted = $this->scan('2026-04-24')->emitted[0];
        $this->assignment($rel, $this->createUnit(), '2026-04-27', null);
        $this->scan('2026-04-25');

        $entry = $this->latestAuditEntryFor('hr.movement_expiry_followup.suppress');
        $this->assertSame($emitted, $entry->target_id);
        $this->assertSame('SCHEDULER_MOVEMENT_EXPIRY', $entry->actor_label);
        $this->assertEquals(['status' => 'SUPPRESSED', 'suppression_reason' => 'TRUNCATED_EARLIER'], $entry->changes);
        $this->assertSame($full->id, $entry->metadata['movement_id']);
        $this->assertSame('2026-05-01', $entry->metadata['expected_effective_to']);
        $this->assertSame('2026-04-27', $entry->metadata['current_effective_to']);
        $this->assertSame('2026-04-24', $entry->metadata['due_date'] ?? null, 'due date of the stale follow-up');
        $this->assertSame('2026-04-25', $entry->metadata['business_date']);
        $this->assertNull($entry->actor_principal_id);
    }

    public function test_a_scan_that_finds_nothing_writes_no_audit_entries(): void
    {
        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', null);
        $before = $this->auditEntriesCount();

        $result = $this->scan('2026-04-24');

        $this->assertEquals(new ExpiryFollowUpScanResult('2026-04-24'), $result);
        $this->assertSame($before, $this->auditEntriesCount());
    }

    // ---------------------------------------------------------------------
    // AC. Scheduler / service retry safety
    // ---------------------------------------------------------------------

    public function test_ac_the_console_command_uses_the_business_clock_and_is_safe_to_repeat(): void
    {
        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $this->clock->on('2026-04-24');

        $this->assertSame(0, Artisan::call('masar:hr:scan-movement-expiry-followups'));
        $this->assertStringContainsString('2026-04-24: 1 emitted', Artisan::output());
        $this->assertSame(0, Artisan::call('masar:hr:scan-movement-expiry-followups'));
        $this->assertStringContainsString('0 emitted', Artisan::output());
        $this->assertSame(1, MovementExpiryFollowUp::query()->count());
    }

    public function test_the_scan_is_scheduled_daily_and_the_definition_holds_no_business_rule(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'masar:hr:scan-movement-expiry-followups'));

        $this->assertCount(1, $events);
        $this->assertSame('0 1 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertStringNotContainsString('Queue', file_get_contents(base_path('routes/console.php')), 'no queue / worker infrastructure is introduced');
    }

    public function test_the_scan_rejects_a_non_date_business_date_and_defaults_to_the_clock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->scan('yesterday');
        } finally {
            [, $rel] = $this->employee();
            $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
            $this->assertCount(1, app(ScanMovementExpiryFollowUps::class)->handle()->emitted, 'no explicit date: the BusinessDateClock decides');
        }
    }

    // ---------------------------------------------------------------------
    // AM. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_am_the_database_enforces_the_logical_identity_and_every_check(): void
    {
        [, $rel] = $this->employee();
        $unit = $this->createUnit();
        $full = $this->full($rel, $unit, '2026-03-01', '2026-05-01');
        $this->insertFollowUp($rel, $unit, ['full_secondment_period_id' => $full->id]);

        $duplicate = $this->queryError(fn () => $this->insertFollowUp($rel, $unit, ['full_secondment_period_id' => $full->id]));
        $this->assertTrue(Errors::isUniqueViolation($duplicate), 'same (kind, movement type, movement, expected end) exists once');

        // A different expected end is a different logical follow-up.
        $this->insertFollowUp($rel, $unit, ['full_secondment_period_id' => $full->id, 'expected_effective_to' => '2026-04-27', 'due_date' => '2026-04-20']);

        foreach ([
            'due_date is not end - 7' => ['due_date' => '2026-04-25'],
            'unknown kind' => ['followup_kind' => 'EXPIRY_WARNING_30D'],
            'unknown movement type' => ['movement_type' => 'TRANSFER'],
            'movement type disagrees with the arc' => ['movement_type' => 'WORKPLACE_ASSIGNMENT'],
            'ACTIONABLE with a reason' => ['suppression_reason' => 'END_DATE_CHANGED'],
            'SUPPRESSED without a reason' => ['status' => 'SUPPRESSED', 'suppressed_at' => now()],
            'unknown reason' => ['status' => 'SUPPRESSED', 'suppression_reason' => 'WHATEVER', 'suppressed_at' => now()],
            'unknown status' => ['status' => 'PENDING'],
        ] as $why => $override) {
            $error = $this->queryError(fn () => $this->insertFollowUp($rel, $unit, $override + ['expected_effective_to' => '2026-05-03', 'due_date' => '2026-04-26', 'full_secondment_period_id' => $full->id]));
            $this->assertTrue(Errors::isCheckViolation($error) || Errors::isForeignKeyViolation($error), "{$why} must be rejected");
        }

        $twoArcs = $this->queryError(fn () => $this->insertFollowUp($rel, $unit, ['full_secondment_period_id' => $full->id, 'workplace_assignment_period_id' => $this->assignment($rel, $unit, '2026-06-01', '2026-07-01')->id, 'expected_effective_to' => '2026-05-04', 'due_date' => '2026-04-27']));
        $this->assertTrue(Errors::isCheckViolation($twoArcs), 'exactly one movement reference');

        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('hr.full_secondment_periods')->where('id', $full->id)->delete())), 'a movement with a follow-up cannot be hard-deleted (RESTRICT)');
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => $this->insertFollowUp($rel, $unit, ['full_secondment_period_id' => (string) Str::uuid7(), 'expected_effective_to' => '2026-05-09', 'due_date' => '2026-05-02']))), 'the movement reference is a REAL foreign key');
    }

    public function test_the_table_carries_only_ids_dates_and_stable_codes(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_schema', 'automation')->where('table_name', 'movement_expiry_followups')
            ->orderBy('column_name')->pluck('column_name')->all();

        $this->assertSame([
            'created_at', 'due_date', 'employment_relationship_id', 'expected_effective_to', 'followup_kind', 'full_secondment_period_id',
            'id', 'movement_id', 'movement_type', 'organizational_unit_id', 'partial_secondment_period_id', 'status',
            'suppressed_at', 'suppression_reason', 'workplace_assignment_period_id',
        ], $columns, 'no person_id, national id, name, Arabic label, notification channel/recipient or delivery state');
    }

    // ---------------------------------------------------------------------
    // X, Y, API. Security, organizational scope and the read surface
    // ---------------------------------------------------------------------

    public function test_x_authentication_and_the_view_permission_are_enforced_and_the_surface_is_read_only(): void
    {
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertForbidden();

        $other = $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW])));
        $this->grantGlobalScope($other);
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertForbidden();

        $viewer = $this->principalWithPermissions([Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW]);
        $this->grantGlobalScope($viewer);
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk();
        $this->postJson('/api/v1/hr/movement-expiry-followups', [])->assertStatus(405);
        $this->patchJson('/api/v1/hr/movement-expiry-followups', [])->assertStatus(405);
        $this->putJson('/api/v1/hr/movement-expiry-followups', [])->assertStatus(405);
        $this->deleteJson('/api/v1/hr/movement-expiry-followups')->assertStatus(405);
        $this->deleteJson('/api/v1/hr/movement-expiry-followups/'.Str::uuid7())->assertNotFound();
    }

    public function test_y_organizational_scope_filters_follow_ups_by_destination_unit_without_leaking_existence(): void
    {
        [, $relA] = $this->employee();
        [, $relB] = $this->employee();
        $unitA = $this->createUnit();
        $unitB = $this->createUnit();
        $childOfA = $this->createUnit(parentId: $unitA->id);
        $this->full($relA, $unitA, '2026-03-01', '2026-05-01');
        $this->full($relB, $unitB, '2026-03-01', '2026-05-01');
        [, $relC] = $this->employee();
        $this->assignment($relC, $childOfA, '2026-03-01', '2026-05-01');
        $this->scan('2026-04-24');

        $scoped = $this->principalWithPermissions([Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW]);
        $this->grantUnitScope($scoped, $unitA);
        $response = $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk();
        $this->assertEqualsCanonicalizing([$unitA->id, $childOfA->id], array_column($response->json('data'), 'organizational_unit_id'), 'the subtree of the granted unit');
        $this->assertSame(2, $response->json('meta.total'), 'the count never includes rows outside scope');
        $this->assertNotContains($relB->id, array_column($response->json('data'), 'employment_relationship_id'));
        $this->getJson('/api/v1/hr/movement-expiry-followups?employment_relationship_id='.$relB->id)->assertOk()->assertJsonCount(0, 'data');

        $noScope = $this->principalWithPermissions([Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW]);
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk()->assertJsonCount(0, 'data');

        $global = $this->principalWithPermissions([Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW]);
        $this->grantGlobalScope($global);
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk()->assertJsonCount(3, 'data');

        $unitB->forceFill(['is_active' => false])->save();
        $this->assertSame(2, $this->getJson('/api/v1/hr/movement-expiry-followups')->json('meta.total'), 'an inactive unit is never an eligible authorization target (S08)');
        $this->assertNotNull($noScope->id);
    }

    public function test_the_read_api_states_filters_and_shape(): void
    {
        $this->actingAsHrAdministrator();
        [, $rel] = $this->employee();
        $fullUnit = $this->createUnit();
        $this->full($rel, $fullUnit, '2026-03-01', '2026-05-01');
        [, $rel2] = $this->employee();
        $partial = $this->partial($rel2, $this->createUnit(), '2026-03-01', '2026-05-05', ['MONDAY']);
        $this->scan('2026-04-28');

        // Default: ACTIONABLE (emitted, end date still ahead) on the fixed clock's date.
        $this->clock->on('2026-04-28');
        $response = $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(
            ['id', 'followup_kind', 'movement_type', 'movement_id', 'employment_relationship_id', 'organizational_unit_id', 'expected_effective_to', 'due_date', 'status', 'state', 'suppression_reason', 'created_at', 'suppressed_at'],
            array_keys($response->json('data.0')),
        );
        $this->assertSame(['ACTIONABLE', 'ACTIONABLE'], array_column($response->json('data'), 'state'));
        $this->assertSame('2026-04-24', $response->json('data.0.due_date'), 'ordered by due date');
        $this->assertStringNotContainsString('national', json_encode($response->json()));

        $this->getJson('/api/v1/hr/movement-expiry-followups?movement_type=PARTIAL_SECONDMENT')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.movement_id', $partial->id);
        $this->getJson('/api/v1/hr/movement-expiry-followups?employment_relationship_id='.$rel->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/hr/movement-expiry-followups?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);

        // Once the end date is reached the emitted follow-up is history (LAPSED), never ACTIONABLE.
        $this->clock->on('2026-05-02');
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/hr/movement-expiry-followups?state=LAPSED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'LAPSED');
        $this->getJson('/api/v1/hr/movement-expiry-followups?state=ALL')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/hr/movement-expiry-followups?state=SUPPRESSED')->assertOk()->assertJsonCount(0, 'data');

        foreach (['state=BOGUS', 'movement_type=TRANSFER', 'employment_relationship_id=x', 'per_page=0', 'per_page=101'] as $bad) {
            $this->getJson('/api/v1/hr/movement-expiry-followups?'.$bad)->assertStatus(422);
        }
    }

    public function test_suppressed_follow_ups_are_visible_with_their_reason_code_only(): void
    {
        $this->actingAsHrAdministrator();
        [, $rel] = $this->employee();
        $this->full($rel, $this->createUnit(), '2026-03-01', '2026-05-01');
        $this->scan('2026-04-24');
        $this->assignment($rel, $this->createUnit(), '2026-05-01', null);
        $this->scan('2026-04-25');

        $data = $this->getJson('/api/v1/hr/movement-expiry-followups?state=SUPPRESSED')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(['SUPPRESSED', 'SUPPRESSED', 'COVERED_BY_NEWER_MOVEMENT'], [$data[0]['status'], $data[0]['state'], $data[0]['suppression_reason']]);
        $this->assertNotNull($data[0]['suppressed_at']);
    }

    // ---------------------------------------------------------------------
    // AD–AI. Regression anchors (S12 / S16 / S27 / S28 / S29 / S30 behavior is unchanged)
    // ---------------------------------------------------------------------

    public function test_ad_ai_scanning_changes_no_reporting_population_or_movement_semantics(): void
    {
        [, $rel, $origin] = $this->employee();
        $dest = $this->createUnit();
        $this->full($rel, $dest, '2026-03-01', '2026-05-01');
        [, $rel2] = $this->employee();
        $this->partial($rel2, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY', 'TUESDAY']);
        $population = fn (string $date) => app(ListReportingPopulationAsOf::class)($date, [$rel->id, $rel2->id]);
        $before = [$population('2026-04-27'), $population('2026-05-04'), $this->dateOnly($rel, '2026-04-27'), $this->dateOnly($rel2, '2026-04-27')];

        foreach (['2026-04-24', '2026-04-27', '2026-05-01'] as $date) {
            $this->scan($date);
        }

        $this->assertEquals($before, [$population('2026-04-27'), $population('2026-05-04'), $this->dateOnly($rel, '2026-04-27'), $this->dateOnly($rel2, '2026-04-27')], 'AF: S27 as-of semantics are untouched by follow-up persistence');
        $this->assertSame([$dest->id, 'secondment'], $this->pair($this->dateOnly($rel, '2026-04-27')), 'AD: S12');
        $this->assertSame('PARTIAL_ALLOCATION', $this->dateOnly($rel2, '2026-04-27')->state(), 'AI: S30 allocation state');
        $this->assertSame($origin->id, $this->dateOnly($rel, '2026-05-04')->organizationalUnitId());

        // AE/AG/AH: S16 close-previous, S28 supersession and S29 schedule commands still behave.
        $this->assignment($rel, $this->createUnit(), '2026-05-10', null);
        $this->schedule($rel, '2026-06-01', ['FRIDAY']);
        $this->assertSame(2, DB::table('hr.work_schedule_periods')->where('employment_relationship_id', $rel->id)->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{0: Person, 1: EmploymentRelationship, 2: OrganizationalUnit} */
    private function employee(): array
    {
        $person = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $origin = $this->createUnit();
        $this->recordPlacement($rel, $origin, '2026-01-15');
        $this->schedule($rel, '2026-01-01', self::SUN_THU);

        return [$person, $rel->refresh(), $origin];
    }

    private function full(EmploymentRelationship $rel, OrganizationalUnit $dest, string $from, ?string $to): FullSecondmentPeriod
    {
        $period = app(StartFullSecondment::class)->handle($rel, $dest, $from);

        if ($to !== null) {
            $period = app(EndFullSecondment::class)->handle($rel->refresh(), $to);
        }

        return $period;
    }

    private function assignment(EmploymentRelationship $rel, OrganizationalUnit $dest, string $from, ?string $to): WorkplaceAssignmentPeriod
    {
        $period = app(StartWorkplaceAssignment::class)->handle($rel, $dest, $from, $this->assignmentDecisionType());

        if ($to !== null) {
            $period = app(EndWorkplaceAssignment::class)->handle($rel->refresh(), $to);
        }

        return $period;
    }

    /** @param list<string> $codes */
    private function partial(EmploymentRelationship $rel, OrganizationalUnit $dest, string $from, ?string $to, array $codes): PartialSecondmentPeriod
    {
        return app(RecordPartialSecondmentPeriod::class)->handle($rel, $dest, $from, $to, $codes);
    }

    /** @param list<string> $codes */
    private function schedule(EmploymentRelationship $rel, string $from, array $codes)
    {
        return app(RecordWorkSchedulePeriod::class)->handle($rel, $from, $codes);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, $to, false);
    }

    private function scan(string $date): ExpiryFollowUpScanResult
    {
        return app(ScanMovementExpiryFollowUps::class)->handle($date);
    }

    private function emitOne(TemporaryMovementType $type, string $movementId, string $relationshipId, string $expectedEnd, string $date): ExpiryFollowUpEmission
    {
        return app(ScanMovementExpiryFollowUps::class)->emit(
            $type, $movementId, $relationshipId, $expectedEnd, $date,
            new CommandContext(Actor::system(ScanMovementExpiryFollowUps::ACTOR_LABEL), CorrelationId::generate(), Source::System),
        );
    }

    private function dateOnly(EmploymentRelationship $rel, string $date)
    {
        return app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel->refresh(), $date);
    }

    private function weekday(EmploymentRelationship $rel, string $date, ?string $code = null)
    {
        return app(ResolveWeekdayActualWorkplaceAsOf::class)($rel->refresh(), $date, $code);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function pair($workplace): array
    {
        return [$workplace->organizationalUnitId(), $workplace->source()];
    }

    /** @return list<array<string, mixed>> */
    private function placementRows(EmploymentRelationship $rel): array
    {
        return DB::table('hr.organizational_placement_periods')->where('employment_relationship_id', $rel->id)
            ->orderBy('effective_from')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Every movement stream row of the relationship (id, unit, dates). */
    private function timeline(): array
    {
        $rows = [];
        foreach (['full_secondment_periods', 'workplace_assignment_periods', 'partial_secondment_periods', 'organizational_placement_periods'] as $table) {
            $rows[$table] = DB::table("hr.{$table}")->orderBy('id')->get(['id', 'employment_relationship_id', 'organizational_unit_id', 'effective_from', 'effective_to'])->map(fn ($r) => (array) $r)->all();
        }

        return $rows;
    }

    /** A digest of every hr.* table the scanner could conceivably touch. */
    private function hrSnapshot(): array
    {
        $snapshot = [];
        foreach (['employment_relationships', 'organizational_placement_periods', 'full_secondment_periods', 'workplace_assignment_periods', 'partial_secondment_periods', 'partial_secondment_period_weekdays', 'employment_status_periods', 'work_schedule_periods', 'work_schedule_period_weekdays', 'persons'] as $table) {
            $snapshot[$table] = (string) DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from hr.{$table} t")->h;
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $override */
    private function insertFollowUp(EmploymentRelationship $rel, OrganizationalUnit $unit, array $override = []): void
    {
        DB::table('automation.movement_expiry_followups')->insert($override + [
            'id' => (string) Str::uuid7(),
            'followup_kind' => 'EXPIRY_WARNING_7D',
            'movement_type' => 'FULL_SECONDMENT',
            'employment_relationship_id' => $rel->id,
            'organizational_unit_id' => $unit->id,
            'expected_effective_to' => '2026-05-01',
            'due_date' => '2026-04-24',
            'status' => 'ACTIONABLE',
            'created_at' => now(),
        ]);
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
        $this->assignRole($principal, $this->createRoleWithPermissions($permissionCodes));
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
