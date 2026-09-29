<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveWeekdayActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActivePartialSecondmentExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActiveWorkplaceAssignmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentWeekdaysException;
use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentOutsideWorkScheduleException;
use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentWeekdayConflictException;
use App\Modules\HumanResources\Domain\Exceptions\WorkScheduleChangeInvalidatesPartialSecondmentException;
use App\Modules\HumanResources\Domain\WeekdayActualWorkplaceAsOf;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * S30 Partial Secondment Foundation (docs/partial-secondment-foundation-specification.md,
 * ADR-S30-001…012): relationship-owned partial secondment periods with relational weekday
 * allocation; the Work Schedule dependency (NOT_RECORDED never a default week) and schedule-change
 * revalidation; Partial/Partial coexistence on disjoint weekdays; Full/Partial mutual exclusion;
 * Assignment/Partial supersession (ADR-S30-007, both directions, tests "ADR-S30-007 #1–#12");
 * transfer and relationship-end consequences; the weekday-aware and date-only actual-workplace
 * readers; PostgreSQL integrity; RBAC + S08 scope; audit. Cross-session races live in
 * ConcurrencyTest. Every fixture is synthetic. Section letters follow the authorization's matrix.
 */
class PartialSecondmentFoundationTest extends HumanResourcesTestCase
{
    private const SUN_THU = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY'];

    // ---------------------------------------------------------------------
    // A–D. Recording
    // ---------------------------------------------------------------------

    public function test_a_first_partial_secondment_is_recorded_with_its_destination_and_weekdays(): void
    {
        [, $rel] = $this->employee();
        $dest = $this->createUnit();

        $period = $this->partial($rel, $dest, '2026-03-01', null, ['MONDAY', 'SUNDAY']);

        $this->assertSame($rel->id, $period->employment_relationship_id);
        $this->assertSame($dest->id, $period->organizational_unit_id);
        $this->assertSame('2026-03-01', $period->effective_from->toDateString());
        $this->assertNull($period->effective_to);
        $this->assertSame(['MONDAY', 'SUNDAY'], $period->weekdayCodes(), 'ISO order');
    }

    public function test_b_c_one_or_several_weekdays_and_a_bounded_period_are_recorded(): void
    {
        [, $rel] = $this->employee();

        $this->assertSame(['WEDNESDAY'], $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['WEDNESDAY'])->weekdayCodes());
        $bounded = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['THURSDAY', 'SUNDAY', 'MONDAY']);

        $this->assertSame(['MONDAY', 'THURSDAY', 'SUNDAY'], $bounded->weekdayCodes());
        $this->assertSame('2026-06-01', $bounded->effective_to->toDateString());
    }

    public function test_d_all_seven_weekdays_are_allowed_when_the_schedule_contains_them(): void
    {
        [, $rel] = $this->employee(Weekday::CODES);

        $this->assertSame(Weekday::CODES, $this->partial($rel, $this->createUnit(), '2026-03-01', null, array_reverse(Weekday::CODES))->weekdayCodes());
    }

    public function test_store_returns_201_and_index_lists_history_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $a = $this->createUnit();
        $b = $this->createUnit();

        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $a->id, 'effective_from' => '2026-03-01', 'weekdays' => ['TUESDAY', 'SUNDAY']])
            ->assertStatus(201)
            ->assertJsonPath('organizational_unit_id', $a->id)
            ->assertJsonPath('effective_from', '2026-03-01')
            ->assertJsonPath('effective_to', null)
            ->assertJsonPath('weekdays', ['TUESDAY', 'SUNDAY']);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $b->id, 'effective_from' => '2026-04-01', 'effective_to' => '2026-05-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(201);

        $response = $this->getJson($this->url($person, $rel))->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertSame($b->id, $response->json('0.organizational_unit_id'));
        $this->assertSame(['id', 'employment_relationship_id', 'organizational_unit_id', 'effective_from', 'effective_to', 'weekdays'], array_keys($response->json('0')));
    }

    // ---------------------------------------------------------------------
    // E–G. Weekday allocation validation (ADR-S30-003)
    // ---------------------------------------------------------------------

    public function test_e_f_g_duplicate_empty_unknown_and_localized_weekdays_are_rejected(): void
    {
        [, $rel] = $this->employee();

        foreach ([['MONDAY', 'MONDAY'], [], ['FUNDAY'], ['الاثنين'], ['monday'], ['1']] as $codes) {
            try {
                $this->partial($rel, $this->createUnit(), '2026-03-01', null, $codes);
                $this->fail(json_encode($codes, JSON_UNESCAPED_UNICODE).' must be rejected');
            } catch (InvalidPartialSecondmentWeekdaysException) {
            }
        }

        $this->assertSame(0, $this->partialCount($rel));
    }

    public function test_invalid_payloads_are_rejected_with_422_and_no_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $unit = $this->createUnit();
        $before = $this->auditEntriesCount();

        $this->postJson($this->url($person, $rel), [])->assertStatus(422)->assertJsonValidationErrors(['organizational_unit_id', 'effective_from', 'weekdays']);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-03-01', 'weekdays' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-03-01', 'weekdays' => ['MONDAY', 'MONDAY']])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-03-01', 'effective_to' => '2026-03-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => (string) Str::uuid7(), 'effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']])
            ->assertNotFound();

        $this->assertSame(0, $this->partialCount($rel));
        $this->assertSame($before, $this->auditEntriesCount());
    }

    // ---------------------------------------------------------------------
    // H–I. Work Schedule dependency (ADR-S30-004)
    // ---------------------------------------------------------------------

    public function test_h_a_missing_work_schedule_is_never_a_default_week(): void
    {
        [, $rel] = $this->employee(null);

        foreach (['MONDAY', 'SUNDAY', 'FRIDAY'] as $code) {
            try {
                $this->partial($rel, $this->createUnit(), '2026-03-01', null, [$code]);
                $this->fail("{$code} must be rejected without a recorded schedule");
            } catch (PartialSecondmentOutsideWorkScheduleException) {
            }
        }

        // A schedule recorded only from a later date leaves the start of the period NOT_RECORDED.
        $this->schedule($rel, '2026-04-01', self::SUN_THU);
        $this->expectException(PartialSecondmentOutsideWorkScheduleException::class);
        $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
    }

    public function test_i_a_weekday_outside_the_schedule_is_rejected_for_every_date_of_the_period(): void
    {
        [, $rel] = $this->employee();

        try {
            $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY', 'FRIDAY']);
            $this->fail('FRIDAY is not scheduled');
        } catch (PartialSecondmentOutsideWorkScheduleException) {
        }

        // A later schedule without MONDAY: a period spanning it is rejected, one ending before it is valid.
        $this->schedule($rel, '2026-06-01', ['SUNDAY', 'TUESDAY']);
        try {
            $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-07-01', ['MONDAY']);
            $this->fail('MONDAY is not scheduled from 2026-06-01');
        } catch (PartialSecondmentOutsideWorkScheduleException) {
        }
        $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['MONDAY']);
        $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-07-01', ['TUESDAY']);

        $this->assertSame(2, $this->partialCount($rel));
    }

    public function test_an_imported_gap_in_the_schedule_rejects_a_period_spanning_it(): void
    {
        [, $rel] = $this->employee(null);
        $this->insertSchedule($rel, '2026-01-01', '2026-04-01', self::SUN_THU);
        $this->insertSchedule($rel, '2026-05-01', null, self::SUN_THU);

        $this->expectException(PartialSecondmentOutsideWorkScheduleException::class);
        $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['MONDAY']);
    }

    // ---------------------------------------------------------------------
    // J–O. Partial/Partial coexistence (ADR-S30-005)
    // ---------------------------------------------------------------------

    public function test_j_k_disjoint_weekdays_coexist_on_the_same_or_overlapping_dates(): void
    {
        [, $rel] = $this->employee();
        $b = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['SUNDAY', 'MONDAY']);
        $c = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['TUESDAY', 'WEDNESDAY']);
        $d = $this->partial($rel, $this->createUnit(), '2026-04-01', '2026-05-01', ['THURSDAY']);

        $this->assertSame(3, $this->partialCount($rel));
        foreach ([$b, $c] as $period) {
            $this->assertNull($period->refresh()->effective_to, 'nothing is truncated to make room');
        }
        $this->assertNotNull($d->id);
    }

    public function test_l_n_o_overlapping_dates_with_a_shared_weekday_are_rejected_whatever_the_destination(): void
    {
        [, $rel] = $this->employee();
        $unit = $this->createUnit();
        $this->partial($rel, $unit, '2026-03-01', '2026-06-01', ['SUNDAY', 'MONDAY']);
        $before = $this->rows($rel);

        foreach ([[$unit, '2026-03-01', null], [$unit, '2026-05-01', '2026-07-01'], [$this->createUnit(), '2026-02-01', '2026-03-02']] as [$dest, $from, $to]) {
            try {
                $this->partial($rel, $dest, $from, $to, ['MONDAY', 'TUESDAY']);
                $this->fail("[{$from}, {$to}) shares MONDAY");
            } catch (PartialSecondmentWeekdayConflictException) {
            }
        }

        $this->assertSame($before, $this->rows($rel), 'no winner, no truncation');
    }

    public function test_m_adjacent_periods_may_allocate_the_same_weekday(): void
    {
        [, $rel] = $this->employee();
        $first = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['MONDAY']);

        $second = $this->partial($rel, $this->createUnit(), '2026-06-01', null, ['MONDAY']);

        $this->assertSame('2026-06-01', $first->refresh()->effective_to->toDateString());
        $this->assertSame('2026-06-01', $second->effective_from->toDateString());
    }

    public function test_conflict_over_http_is_409_with_no_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $before = $this->auditEntriesCount();

        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(409);

        $this->assertSame($before, $this->auditEntriesCount());
    }

    // ---------------------------------------------------------------------
    // P–Q. Full ↔ Partial mutual exclusion (ADR-S30-006) — ADR-S30-007 #11
    // ---------------------------------------------------------------------

    public function test_p_a_full_secondment_overlapping_a_partial_is_rejected_never_superseding(): void
    {
        [, $rel] = $this->employee();
        $partial = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $future = $this->partial($rel, $this->createUnit(), '2026-08-01', '2026-09-01', ['TUESDAY']);
        $before = $this->rows($rel);

        foreach (['2026-04-01', '2026-02-01', '2026-08-15'] as $from) {
            try {
                app(StartFullSecondment::class)->handle($rel, $this->createUnit(), $from);
                $this->fail("a full secondment from {$from} overlaps a partial secondment");
            } catch (ActivePartialSecondmentExistsException) {
            }
        }

        $this->assertSame($before, $this->rows($rel), 'ADR-S30-007 #11: Full/Partial rejects, it does not supersede');
        $this->assertNull($partial->refresh()->effective_to);
        $this->assertSame('2026-09-01', $future->refresh()->effective_to->toDateString());
    }

    public function test_p_a_full_secondment_after_every_partial_has_ended_is_allowed(): void
    {
        [, $rel] = $this->employee();
        $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY']);

        $full = app(StartFullSecondment::class)->handle($rel, $this->createUnit(), '2026-05-01');

        $this->assertSame('2026-05-01', $full->effective_from->toDateString());
    }

    public function test_q_a_partial_overlapping_an_existing_full_secondment_is_rejected(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        app(StartFullSecondment::class)->handle($rel, $this->createUnit(), '2026-03-01');
        $before = $this->rows($rel);

        try {
            $this->partial($rel, $this->createUnit(), '2026-04-01', null, ['MONDAY']);
            $this->fail('overlaps the open full secondment');
        } catch (ActiveFullSecondmentAlreadyExistsException) {
        }
        try {
            $this->partial($rel, $this->createUnit(), '2026-02-01', '2026-03-02', ['MONDAY']);
            $this->fail('overlaps the full secondment on its first day');
        } catch (ActiveFullSecondmentAlreadyExistsException) {
        }
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(409);

        $this->assertSame($before, $this->rows($rel));
        $this->partial($rel, $this->createUnit(), '2026-02-01', '2026-03-01', ['MONDAY']); // adjacent, before it
    }

    // ---------------------------------------------------------------------
    // R–U. Temporal bounds (ADR-S30-002)
    // ---------------------------------------------------------------------

    public function test_r_a_future_partial_secondment_is_allowed(): void
    {
        [, $rel] = $this->employee();

        $period = $this->partial($rel, $this->createUnit(), '2030-01-06', null, ['MONDAY']);

        $this->assertSame('2030-01-06', $period->effective_from->toDateString());
        $this->assertSame(ActualWorkplaceAsOf::RESOLVED, $this->dateOnly($rel, '2029-12-31')->state(), 'not effective before its start');
    }

    public function test_s_a_start_on_or_before_the_relationship_start_is_rejected(): void
    {
        [, $rel] = $this->employee();

        foreach (['2025-12-31', '2026-01-01'] as $from) {
            try {
                $this->partial($rel, $this->createUnit(), $from, null, ['MONDAY']);
                $this->fail("{$from} is not after the relationship start");
            } catch (InvalidPartialSecondmentPeriodDateException $e) {
                $this->assertSame('effective_from', $e->field);
            }
        }
    }

    public function test_t_an_ended_relationship_rejects_a_partial_secondment(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $this->end($person, $rel, '2026-06-01');

        $this->postJson($this->url($person, $rel->refresh()), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(409);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-05-01', ['MONDAY']);
    }

    public function test_u_unknown_legacy_never_fabricates_an_end(): void
    {
        [, $rel] = $this->employee();
        DB::table('hr.employment_relationships')->where('id', $rel->id)->update(['end_knowledge_state' => 'UNKNOWN_LEGACY']);

        $period = $this->partial($rel->refresh(), $this->createUnit(), '2026-03-01', null, ['MONDAY']);

        $this->assertNull($period->effective_to, 'no end is invented for an UNKNOWN_LEGACY relationship');
        $this->assertSame('UNKNOWN_LEGACY', $rel->refresh()->end_knowledge_state);
    }

    // ---------------------------------------------------------------------
    // V. Reappointment (ADR-S30-001)
    // ---------------------------------------------------------------------

    public function test_v_reappointment_does_not_inherit_partial_secondments(): void
    {
        [$person, $old] = $this->employee();
        $oldPeriod = $this->partial($old, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(0, $this->partialCount($new));
        $this->assertSame($old->id, $oldPeriod->refresh()->employment_relationship_id);
        $this->assertSame('2026-06-01', $oldPeriod->effective_to->toDateString());

        $this->schedule($new, '2026-08-01', self::SUN_THU);
        $this->recordPlacement($new, $this->createUnit(), '2026-08-02');
        $this->partial($new, $this->createUnit(), '2026-08-03', null, ['MONDAY']); // same weekday, other relationship
        $this->assertSame(1, $this->partialCount($new));
    }

    // ---------------------------------------------------------------------
    // W–X / AP. Employment end (ADR-S30-009)
    // ---------------------------------------------------------------------

    public function test_w_relationship_end_closes_every_effective_partial_and_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $open = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $planned = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-12-01', ['TUESDAY']);
        $ended = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-04-01', ['WEDNESDAY']);

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/end", [
            'expected_version' => $rel->version, 'effective_to' => '2026-09-01', 'is_terminal' => false,
        ])->assertOk();

        $this->assertSame('2026-09-01', $open->refresh()->effective_to->toDateString());
        $this->assertSame('2026-09-01', $planned->refresh()->effective_to->toDateString(), 'a planned end beyond the employment end is closed too');
        $this->assertSame('2026-04-01', $ended->refresh()->effective_to->toDateString(), 'an ended period is never extended');
        $this->assertSame(['[2026-03-01,2026-09-01)'], DB::table('hr.partial_secondment_period_weekdays')->where('partial_secondment_period_id', $open->id)->pluck('period')->all(), 'the membership copy follows the truncation');
        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertTrue($entry->metadata['partial_secondment_closed_as_consequence'] ?? false);
        $this->assertEqualsCanonicalizing([$open->id, $planned->id], $entry->metadata['closed_partial_secondment_period_ids']);
    }

    public function test_x_a_future_partial_makes_an_earlier_relationship_end_reject_atomically(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $this->partial($rel, $this->createUnit(), '2026-10-04', null, ['TUESDAY']);
        $before = $this->rows($rel);

        foreach (['2026-09-01', '2026-10-04'] as $endDate) {
            $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/end", [
                'expected_version' => $rel->version, 'effective_to' => $endDate, 'is_terminal' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        }

        $this->assertSame($before, $this->rows($rel));
        $this->assertSame('NOT_APPLICABLE', $rel->refresh()->end_knowledge_state);

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, '2026-09-01', false);
    }

    public function test_ap_status_triggered_termination_closes_partials_and_the_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $open = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $before = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/status-periods", [
            'status_detail_code' => 'resigned', 'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());
        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertTrue($entry->metadata['partial_secondment_closed_as_consequence'] ?? false);
        $this->assertSame($before + 1, $this->auditEntriesCount(), 'one entry, no duplicate consequence event');

        [$p2, $r2] = $this->employee();
        $terminal = $this->partial($r2, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        app(RecordEmploymentStatusPeriod::class)->handle($p2, $r2, $this->statusDetail('deceased'), '2026-10-15');
        $this->assertSame('2026-10-15', $terminal->refresh()->effective_to->toDateString());
    }

    // ---------------------------------------------------------------------
    // Y–Z. Work Schedule change revalidation (ADR-S30-010)
    // ---------------------------------------------------------------------

    public function test_y_a_schedule_change_keeping_every_allocated_weekday_succeeds(): void
    {
        [, $rel] = $this->employee();
        $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY', 'TUESDAY']);

        $period = $this->schedule($rel, '2026-05-01', ['MONDAY', 'TUESDAY', 'SATURDAY']);

        $this->assertSame(['MONDAY', 'TUESDAY', 'SATURDAY'], $period->weekdayCodes());
    }

    public function test_z_a_schedule_change_removing_an_allocated_weekday_is_rejected_atomically(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $partial = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-08-01', ['MONDAY', 'TUESDAY']);
        $future = $this->partial($rel, $this->createUnit(), '2026-09-06', null, ['THURSDAY']);
        $schedules = $this->scheduleRows($rel);
        $before = $this->rows($rel);
        $audit = $this->auditEntriesCount();

        foreach ([['2026-05-01', ['SUNDAY', 'MONDAY', 'WEDNESDAY', 'THURSDAY']], ['2026-08-15', ['SUNDAY', 'MONDAY']]] as [$from, $codes]) {
            try {
                $this->schedule($rel, $from, $codes);
                $this->fail("a schedule from {$from} removes an allocated weekday");
            } catch (WorkScheduleChangeInvalidatesPartialSecondmentException) {
            }
        }
        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/work-schedule-periods", [
            'effective_from' => '2026-05-01', 'weekdays' => ['SUNDAY', 'WEDNESDAY'],
        ])->assertStatus(422)->assertJsonValidationErrors(['weekdays']);

        $this->assertSame($schedules, $this->scheduleRows($rel), 'the previous schedule is not closed, no new schedule is written');
        $this->assertSame($before, $this->rows($rel), 'the partial secondments are never changed or ended');
        $this->assertSame($audit, $this->auditEntriesCount());

        // After the bounded partial ends, only the future THURSDAY allocation constrains the schedule.
        $this->schedule($rel, '2026-08-15', ['THURSDAY', 'SATURDAY']);
        $this->assertSame('2026-08-01', $partial->refresh()->effective_to->toDateString());
        $this->assertNull($future->refresh()->effective_to);
    }

    public function test_an_schedule_changes_without_partial_secondments_are_unaffected(): void
    {
        [, $rel] = $this->employee();

        $this->schedule($rel, '2026-05-01', ['FRIDAY']);

        $this->assertSame(2, (int) DB::table('hr.work_schedule_periods')->where('employment_relationship_id', $rel->id)->count());
    }

    // ---------------------------------------------------------------------
    // AA–AC. Actual workplace (ADR-S30-011/012) — ADR-S30-007 #12
    // ---------------------------------------------------------------------

    public function test_aa_ab_the_weekday_resolver_uses_the_allocation_the_underlying_workplace_and_the_schedule(): void
    {
        [, $rel, $origin] = $this->employee();
        $dest = $this->createUnit();
        $period = $this->partial($rel, $dest, '2026-03-01', null, ['MONDAY', 'TUESDAY']);

        $monday = $this->weekday($rel, '2026-03-02'); // a Monday
        $this->assertSame(WeekdayActualWorkplaceAsOf::RESOLVED, $monday->state());
        $this->assertSame('MONDAY', $monday->weekday());
        $this->assertSame($dest->id, $monday->organizationalUnitId());
        $this->assertSame('partial_secondment', $monday->source());
        $this->assertSame($period->id, $monday->periodId());

        $wednesday = $this->weekday($rel, '2026-03-04');
        $this->assertSame($origin->id, $wednesday->organizationalUnitId(), 'an unallocated scheduled weekday is the underlying workplace');
        $this->assertSame('placement', $wednesday->source());

        $this->assertSame(WeekdayActualWorkplaceAsOf::NOT_SCHEDULED, $this->weekday($rel, '2026-03-06')->state(), 'FRIDAY is not scheduled');
        $this->assertSame($dest->id, $this->weekday($rel, '2026-03-06', 'TUESDAY')->organizationalUnitId(), 'explicit weekday under the arrangement effective on the date');
        $this->assertSame($origin->id, $this->weekday($rel, '2026-02-02')->organizationalUnitId(), 'before the partial secondment');
        $this->assertSame('RESOLVED', $monday->scheduleState());
    }

    public function test_the_weekday_resolver_without_a_schedule_never_invents_a_non_working_day(): void
    {
        [, $rel, $origin] = $this->employee(null);

        $friday = $this->weekday($rel, '2026-03-06');

        $this->assertSame(WeekdayActualWorkplaceAsOf::RESOLVED, $friday->state());
        $this->assertSame($origin->id, $friday->organizationalUnitId());
        $this->assertSame('NOT_RECORDED', $friday->scheduleState());
    }

    public function test_ac_the_date_only_resolver_reports_partial_allocation_never_a_chosen_destination(): void
    {
        [, $rel, $origin] = $this->employee();
        $b = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['SUNDAY', 'MONDAY']);
        $c = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['TUESDAY', 'WEDNESDAY']);

        $asOf = $this->dateOnly($rel, '2026-04-01');

        $this->assertSame(ActualWorkplaceAsOf::PARTIAL_ALLOCATION, $asOf->state(), 'two disjoint partials are valid allocation, not AMBIGUOUS');
        $this->assertTrue($asOf->isPartialAllocation());
        $this->assertFalse($asOf->isResolved());
        $this->assertNull($asOf->organizationalUnitId(), 'no scalar workplace is fabricated');
        $this->assertSame($origin->id, $asOf->underlyingOrganizationalUnitId());
        $this->assertSame([$b->id, $c->id], array_column($asOf->partialAllocations(), 'period_id'));
        $this->assertSame(['MONDAY', 'SUNDAY'], $asOf->partialAllocations()[0]['weekdays'], 'ISO order');

        [$row] = app(ListReportingPopulationAsOf::class)('2026-04-01', [$rel->id]);
        $this->assertEquals($asOf, $row->actualWorkplace, 'the population read model agrees with the single-relationship resolver');
        $this->assertSame(ActualWorkplaceAsOf::RESOLVED, $this->dateOnly($rel, '2026-02-15')->state());
    }

    public function test_adr_s30_007_12_an_assignment_is_never_a_hidden_layer_under_a_partial(): void
    {
        [, $rel, $origin] = $this->employee();
        $assignmentUnit = $this->createUnit();
        $this->assign($rel, $assignmentUnit, '2026-02-01');
        $partialUnit = $this->createUnit();
        $this->partial($rel, $partialUnit, '2026-03-01', null, ['MONDAY']);

        $wednesday = $this->weekday($rel, '2026-03-04');
        $this->assertSame($origin->id, $wednesday->organizationalUnitId(), 'the superseded assignment does not sit under the partial');
        $this->assertSame('placement', $wednesday->source());
        $this->assertSame($assignmentUnit->id, $this->weekday($rel, '2026-02-04')->organizationalUnitId(), 'before D the assignment applied');

        // Legacy data with both effective together is reported as ambiguity, never layered.
        [, $legacy] = $this->employee();
        $this->insertPartial($legacy, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        DB::table('hr.workplace_assignment_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => $legacy->id, 'organizational_unit_id' => $this->createUnit()->id,
            'effective_from' => '2026-02-01', 'effective_to' => null, 'created_at' => now(),
        ]);
        $this->assertSame(ActualWorkplaceAsOf::AMBIGUOUS_MOVEMENT_STATE, $this->dateOnly($legacy, '2026-04-01')->state());
        $this->assertSame(WeekdayActualWorkplaceAsOf::AMBIGUOUS_MOVEMENT_STATE, $this->weekday($legacy, '2026-04-01')->state());
    }

    // ---------------------------------------------------------------------
    // ADR-S30-007 — Assignment ↔ Partial supersession
    // ---------------------------------------------------------------------

    public function test_adr_s30_007_1_an_effective_assignment_is_truncated_when_a_partial_starts(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $assignment = $this->assign($rel, $this->createUnit(), '2026-02-01');

        $response = $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(201);

        $this->assertSame('2026-03-01', $assignment->refresh()->effective_to->toDateString());
        $entry = $this->latestAuditEntryFor('hr.partial_secondment_period.record');
        $this->assertEquals([[
            'stream' => 'workplace_assignment', 'period_id' => $assignment->id, 'previous_effective_to' => null, 'effective_to' => '2026-03-01',
        ]], $entry->metadata['superseded_movements']);
        $this->assertSame('2026-03-01', $entry->metadata['superseded_at']);
        $this->assertSame($response->json('id'), $entry->target_id);
    }

    public function test_adr_s30_007_2_and_3_every_effective_partial_is_truncated_when_an_assignment_starts(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $b = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['SUNDAY', 'MONDAY']);
        $c = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-12-01', ['TUESDAY']);

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/workplace-assignment-periods", [
            'organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-05-01', 'decision_type_id' => $this->assignmentDecisionType()->id,
        ])->assertStatus(201);

        $this->assertSame('2026-05-01', $b->refresh()->effective_to->toDateString());
        $this->assertSame('2026-05-01', $c->refresh()->effective_to->toDateString(), 'a future-closed partial effective at D is truncated too');
        $entry = $this->latestAuditEntryFor('hr.workplace_assignment_period.start');
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($entry->metadata['superseded_movements'], 'period_id'));
        $this->assertSame(['partial_secondment'], array_values(array_unique(array_column($entry->metadata['superseded_movements'], 'stream'))));
        $this->assertSame(ActualWorkplaceAsOf::RESOLVED, $this->dateOnly($rel, '2026-06-01')->state(), 'rule 3: no coexistence afterwards');
    }

    public function test_adr_s30_007_4_and_5_movements_ended_before_the_start_are_untouched(): void
    {
        [, $rel] = $this->employee();
        $assignment = $this->assign($rel, $this->createUnit(), '2026-02-01');
        app(EndWorkplaceAssignment::class)->handle($rel, '2026-02-15');
        $partial = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-04-01', ['MONDAY']);
        $this->assertSame('2026-02-15', $assignment->refresh()->effective_to->toDateString(), '#4: historical assignment unchanged');

        $this->assign($rel, $this->createUnit(), '2026-05-01');
        $this->assertSame('2026-04-01', $partial->refresh()->effective_to->toDateString(), '#5: historical partial unchanged');
    }

    public function test_adr_s30_007_6_a_later_assignment_inside_the_requested_period_rejects_the_partial(): void
    {
        [, $rel] = $this->employee();
        $current = $this->assign($rel, $this->createUnit(), '2026-02-01');
        $later = $this->assign($rel, $this->createUnit(), '2026-06-01');
        $before = $this->rows($rel);

        foreach ([[null], ['2026-07-01'], ['2026-06-02']] as [$to]) {
            try {
                $this->partial($rel, $this->createUnit(), '2026-03-01', $to, ['MONDAY']);
                $this->fail('the assignment starting 2026-06-01 would have to be rewritten');
            } catch (ActiveWorkplaceAssignmentAlreadyExistsException) {
            }
        }
        $this->assertSame($before, $this->rows($rel));
        $this->assertSame('2026-06-01', $current->refresh()->effective_to->toDateString());
        $this->assertNull($later->refresh()->effective_to);

        // A bounded partial ending before the later assignment rewrites nothing, so it is valid.
        $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['MONDAY']);
        $this->assertSame('2026-03-01', $current->refresh()->effective_to->toDateString());
    }

    public function test_adr_s30_007_7_a_later_partial_rejects_an_earlier_assignment_start(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $partial = $this->partial($rel, $this->createUnit(), '2026-06-07', null, ['MONDAY']);
        $before = $this->rows($rel);

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/workplace-assignment-periods", [
            'organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-03-01', 'decision_type_id' => $this->assignmentDecisionType()->id,
        ])->assertStatus(409);
        try {
            $this->assign($rel, $this->createUnit(), '2026-06-07');
            $this->fail('a partial starting on the date would be erased');
        } catch (ActivePartialSecondmentExistsException) {
        }

        $this->assertSame($before, $this->rows($rel));
        $this->assertNull($partial->refresh()->effective_to);
    }

    public function test_adr_s30_007_8_a_failed_creation_rolls_back_the_planned_truncation(): void
    {
        [, $rel] = $this->employee();
        $assignment = $this->assign($rel, $this->createUnit(), '2026-02-01');
        $before = $this->rows($rel);

        $this->failNextInsertInto('partial_secondment_periods');
        try {
            $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
            $this->fail('the insert must fail');
        } catch (Throwable) {
        }
        $this->assertSame($before, $this->rows($rel), 'the assignment truncation was rolled back');
        $this->assertNull($assignment->refresh()->effective_to);
        DB::unprepared('DROP TRIGGER s30_probe_fail_insert ON hr.partial_secondment_periods');

        [, $rel2] = $this->employee();
        $b = $this->partial($rel2, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $c = $this->partial($rel2, $this->createUnit(), '2026-03-01', null, ['TUESDAY']);
        $before = $this->rows($rel2);

        $this->failNextInsertInto('workplace_assignment_periods');
        try {
            $this->assign($rel2, $this->createUnit(), '2026-05-01');
            $this->fail('the insert must fail');
        } catch (Throwable) {
        }
        $this->assertSame($before, $this->rows($rel2), 'both partial truncations were rolled back');
        $this->assertNull($b->refresh()->effective_to);
        $this->assertNull($c->refresh()->effective_to);
    }

    public function test_adr_s30_007_10_disjoint_partials_are_unaffected_by_the_assignment_rule(): void
    {
        [, $rel] = $this->employee();
        $b = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);

        $c = $this->partial($rel, $this->createUnit(), '2026-04-01', null, ['TUESDAY']);

        $this->assertNull($b->refresh()->effective_to, 'a new partial never supersedes another partial');
        $this->assertNull($c->effective_to);
    }

    // ---------------------------------------------------------------------
    // AO. Transfer (ADR-S30-008)
    // ---------------------------------------------------------------------

    public function test_ao_a_transfer_truncates_every_effective_partial_and_rejects_later_history(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $b = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $c = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-11-01', ['TUESDAY']);
        $ended = $this->partial($rel, $this->createUnit(), '2026-03-01', '2026-04-01', ['WEDNESDAY']);
        $destination = $this->createUnit();

        $response = $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/transfer", [
            'organizational_unit_id' => $destination->id, 'effective_from' => '2026-06-01', 'decision_type_id' => $this->transferDecisionType()->id,
        ])->assertStatus(201);

        $this->assertSame('2026-06-01', $b->refresh()->effective_to->toDateString());
        $this->assertSame('2026-06-01', $c->refresh()->effective_to->toDateString());
        $this->assertSame('2026-04-01', $ended->refresh()->effective_to->toDateString());
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($response->json('closed_partial_secondment_periods'), 'id'));
        $entry = $this->latestAuditEntryFor('hr.transfer.execute');
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $entry->changes['closed_partial_secondment_period_ids']);
    }

    public function test_ao_a_transfer_before_a_later_partial_is_rejected_atomically(): void
    {
        [, $rel] = $this->employee();
        $this->partial($rel, $this->createUnit(), '2026-08-02', null, ['MONDAY']);
        $placements = DB::table('hr.organizational_placement_periods')->where('employment_relationship_id', $rel->id)->count();
        $before = $this->rows($rel);

        try {
            app(TransferEmployee::class)->handle($rel, $this->createUnit(), '2026-06-01', $this->transferDecisionType());
            $this->fail('a transfer before a later partial secondment would rewrite it');
        } catch (InvalidPartialSecondmentEndDateException) {
        }

        $this->assertSame($before, $this->rows($rel));
        $this->assertSame($placements, DB::table('hr.organizational_placement_periods')->where('employment_relationship_id', $rel->id)->count(), 'the placement write rolled back too');
    }

    // ---------------------------------------------------------------------
    // AD–AF. Security and organizational scope
    // ---------------------------------------------------------------------

    public function test_ad_organizational_scope_covers_destination_source_and_superseded_units(): void
    {
        [$person, $rel, $origin] = $this->employee();
        $dest = $this->createUnit();
        $foreign = $this->createUnit();
        $payload = fn (OrganizationalUnit $unit) => ['organizational_unit_id' => $unit->id, 'effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']];

        $principal = $this->principalWithPermissions([Perm::PARTIAL_SECONDMENT_PERIODS_RECORD, Perm::PARTIAL_SECONDMENT_PERIODS_VIEW]);
        $this->grantUnitScope($principal, $origin);
        $this->postJson($this->url($person, $rel), $payload($dest))->assertForbidden(); // destination out of scope

        $principal = $this->principalWithPermissions([Perm::PARTIAL_SECONDMENT_PERIODS_RECORD]);
        $this->grantUnitScope($principal, $dest);
        $this->postJson($this->url($person, $rel), $payload($dest))->assertForbidden(); // source out of scope

        $this->grantUnitScope($principal, $origin);
        $assignment = $this->assign($rel, $foreign, '2026-02-01');
        $this->postJson($this->url($person, $rel), $payload($dest))->assertForbidden(); // superseded assignment out of scope
        $this->assertNull($assignment->refresh()->effective_to);
        $this->assertSame(0, $this->partialCount($rel));

        $this->grantUnitScope($principal, $foreign);
        $this->postJson($this->url($person, $rel), $payload($dest))->assertStatus(201);

        $viewer = $this->principalWithPermissions([Perm::PARTIAL_SECONDMENT_PERIODS_VIEW]);
        $this->getJson($this->url($person, $rel))->assertForbidden(); // current workplace (placement) out of scope
        $this->grantUnitScope($viewer, $origin);
        $this->getJson($this->url($person, $rel))->assertOk()->assertJsonCount(1);
    }

    public function test_ad_an_assignment_cannot_supersede_a_partial_in_a_unit_outside_scope(): void
    {
        [$person, $rel, $origin] = $this->employee();
        $partialUnit = $this->createUnit();
        $partial = $this->partial($rel, $partialUnit, '2026-03-01', null, ['MONDAY']);
        $dest = $this->createUnit();

        $principal = $this->principalWithPermissions([Perm::WORKPLACE_ASSIGNMENT_PERIODS_START]);
        $this->grantUnitScope($principal, $origin);
        $this->grantUnitScope($principal, $dest);

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/workplace-assignment-periods", [
            'organizational_unit_id' => $dest->id, 'effective_from' => '2026-05-01', 'decision_type_id' => $this->assignmentDecisionType()->id,
        ])->assertForbidden();

        $this->assertNull($partial->refresh()->effective_to);
    }

    public function test_ae_af_authentication_and_permission_separation(): void
    {
        [$person, $rel] = $this->employee();
        $payload = fn () => ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']];

        $this->getJson($this->url($person, $rel))->assertUnauthorized();
        $this->postJson($this->url($person, $rel), $payload())->assertUnauthorized();

        $viewer = $this->principalWithPermissions([Perm::PARTIAL_SECONDMENT_PERIODS_VIEW]);
        $this->grantGlobalScope($viewer);
        $this->getJson($this->url($person, $rel))->assertOk();
        $this->postJson($this->url($person, $rel), $payload())->assertForbidden();

        $recorder = $this->principalWithPermissions([Perm::PARTIAL_SECONDMENT_PERIODS_RECORD]);
        $this->grantGlobalScope($recorder);
        $this->getJson($this->url($person, $rel))->assertForbidden();
        $this->postJson($this->url($person, $rel), $payload())->assertStatus(201);

        $others = $this->principalWithPermissions([...array_values(array_diff(Perm::ALL, [Perm::PARTIAL_SECONDMENT_PERIODS_VIEW, Perm::PARTIAL_SECONDMENT_PERIODS_RECORD])), ...ReferencePermissionCatalog::ALL]);
        $this->grantGlobalScope($others);
        $this->getJson($this->url($person, $rel))->assertForbidden();
        $this->postJson($this->url($person, $rel), $payload())->assertForbidden();
        $this->assertSame(1, $this->partialCount($rel));
    }

    public function test_ownership_mismatch_is_404_and_no_patch_put_or_delete_route_exists(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $period = $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $stranger = $this->createPersonRecord();

        $this->getJson($this->url($stranger, $rel))->assertNotFound();
        $this->postJson($this->url($stranger, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01', 'weekdays' => ['TUESDAY']])->assertNotFound();
        $this->patchJson($this->url($person, $rel), [])->assertStatus(405);
        $this->putJson($this->url($person, $rel), [])->assertStatus(405);
        $this->deleteJson($this->url($person, $rel))->assertStatus(405);
        $this->deleteJson($this->url($person, $rel)."/{$period->id}")->assertNotFound();
        $this->assertSame(1, $this->partialCount($rel));
    }

    // ---------------------------------------------------------------------
    // AG–AH. Audit
    // ---------------------------------------------------------------------

    public function test_ag_the_record_audit_carries_ids_dates_and_stable_weekday_codes_without_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $dest = $this->createUnit();

        $response = $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $dest->id, 'effective_from' => '2026-03-01', 'effective_to' => '2026-09-01', 'weekdays' => ['SUNDAY', 'MONDAY']])
            ->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.partial_secondment_period.record');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_partial_secondment_period', $entry->target_type);
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertEquals([
            'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $dest->id,
            'effective_from' => '2026-03-01', 'effective_to' => '2026-09-01', 'weekdays' => ['MONDAY', 'SUNDAY'],
        ], $entry->changes);
        $this->assertEquals([], $entry->metadata ?? [], 'nothing superseded');
        $encoded = json_encode([$entry->changes, $entry->metadata], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($person->national_id, $encoded);
        $this->assertStringNotContainsString('الاثنين', $encoded);
    }

    public function test_ah_rejected_writes_leave_no_success_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->employee();
        $this->partial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $before = $this->auditEntriesCount();

        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01', 'weekdays' => ['FRIDAY']])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-04-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(409);
        $this->postJson($this->url($person, $rel), ['organizational_unit_id' => $this->createUnit()->id, 'effective_from' => '2026-01-01', 'weekdays' => ['TUESDAY']])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        $this->assertSame($before, $this->auditEntriesCount());
        $this->assertNull($this->latestAuditEntryFor('hr.partial_secondment_period.record'));
    }

    // ---------------------------------------------------------------------
    // AI. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_ai_the_database_rejects_an_overlapping_same_weekday_allocation_directly(): void
    {
        [, $rel] = $this->employee();
        $this->insertPartial($rel, $this->createUnit(), '2026-03-01', '2026-06-01', ['MONDAY', 'TUESDAY']);

        $error = $this->queryError(fn () => $this->insertPartial($rel, $this->createUnit(), '2026-05-01', null, ['TUESDAY']));
        $this->assertTrue(Errors::isExclusionViolation($error), 'same relationship + overlapping dates + same weekday');

        $this->insertPartial($rel, $this->createUnit(), '2026-05-01', null, ['WEDNESDAY']); // disjoint weekday
        $this->insertPartial($rel, $this->createUnit(), '2026-06-01', null, ['TUESDAY']);   // adjacent
        [, $other] = $this->employee();
        $this->insertPartial($other, $this->createUnit(), '2026-03-01', null, ['MONDAY']);  // other relationship
        $this->assertSame(3, $this->partialCount($rel));
    }

    public function test_ai_membership_can_never_disagree_with_its_parent_and_constraints_hold(): void
    {
        // The period FK is DEFERRABLE INITIALLY DEFERRED (checked at commit); the test transaction
        // never commits, so the check is made immediate here to observe it.
        DB::statement('SET CONSTRAINTS hr.partial_secondment_period_weekdays_period_fk IMMEDIATE');
        [, $rel] = $this->employee();
        $id = $this->insertPartial($rel, $this->createUnit(), '2026-03-01', null, ['MONDAY']);
        $monday = DB::table('ref.weekdays')->where('code', 'MONDAY')->value('id');

        $desync = $this->queryError(fn () => DB::table('hr.partial_secondment_period_weekdays')->where('partial_secondment_period_id', $id)->update(['period' => '[2026-01-01,)']));
        $this->assertTrue(Errors::isForeignKeyViolation($desync));
        $wrongOwner = $this->queryError(fn () => DB::table('hr.partial_secondment_period_weekdays')->insert([
            'partial_secondment_period_id' => $id, 'weekday_id' => DB::table('ref.weekdays')->where('code', 'TUESDAY')->value('id'),
            'employment_relationship_id' => $this->employee()[1]->id, 'period' => '[2026-03-01,)',
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($wrongOwner));
        $duplicate = $this->queryError(fn () => DB::table('hr.partial_secondment_period_weekdays')->insert([
            'partial_secondment_period_id' => $id, 'weekday_id' => $monday, 'employment_relationship_id' => $rel->id, 'period' => '[2026-03-01,)',
        ]));
        $this->assertTrue(Errors::isUniqueViolation($duplicate) || Errors::isExclusionViolation($duplicate));
        // An empty period trips the CHECK; a reversed one is already refused by the generated
        // daterange() column itself (SQLSTATE 22000) before the CHECK is evaluated.
        $this->assertTrue(Errors::isCheckViolation($this->queryError(fn () => $this->insertPartial($rel, $this->createUnit(), '2026-06-01', '2026-06-01', []))));
        $this->assertSame('22000', Errors::sqlState($this->queryError(fn () => $this->insertPartial($rel, $this->createUnit(), '2026-06-01', '2026-05-01', []))));
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('ref.weekdays')->where('id', $monday)->delete())));
        $this->assertTrue(Errors::isForeignKeyViolation($this->queryError(fn () => DB::table('hr.partial_secondment_periods')->where('id', $id)->delete())));

        $parentOnly = $this->queryError(fn () => DB::table('hr.partial_secondment_periods')->where('id', $id)->update(['effective_to' => '2026-04-01']));
        $this->assertTrue(Errors::isForeignKeyViolation($parentOnly), 'changing a period without its membership copy is rejected');

        // The model's update is two statements (parent, then membership copy): deferred to "commit",
        // then forced — SET CONSTRAINTS … IMMEDIATE checks everything still pending.
        DB::statement('SET CONSTRAINTS hr.partial_secondment_period_weekdays_period_fk DEFERRED');
        PartialSecondmentPeriod::query()->findOrFail($id)->update(['effective_to' => '2026-04-01']);
        DB::statement('SET CONSTRAINTS hr.partial_secondment_period_weekdays_period_fk IMMEDIATE');
        $this->assertSame('[2026-03-01,2026-04-01)', DB::table('hr.partial_secondment_period_weekdays')->where('partial_secondment_period_id', $id)->value('period'), 'the model re-copies the range');
    }

    public function test_table_shapes_have_no_hours_percentage_attendance_or_payroll_column(): void
    {
        $columns = fn (string $table) => DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', $table)
            ->orderBy('column_name')->pluck('column_name')->all();

        $this->assertSame(['created_at', 'effective_from', 'effective_to', 'employment_relationship_id', 'id', 'organizational_unit_id', 'period'], $columns('partial_secondment_periods'));
        $this->assertSame(['employment_relationship_id', 'partial_secondment_period_id', 'period', 'weekday_id'], $columns('partial_secondment_period_weekdays'));
        foreach (['full_secondment_periods', 'workplace_assignment_periods', 'employment_relationships', 'persons'] as $table) {
            foreach ($columns($table) as $column) {
                $this->assertStringNotContainsString('partial', $column, "hr.{$table} is not given a partial flag");
            }
        }
    }

    // ---------------------------------------------------------------------
    // AL–AN. Regression anchors (S27 / S28 / S29)
    // ---------------------------------------------------------------------

    public function test_al_am_s27_and_s28_behaviour_without_partials_is_unchanged(): void
    {
        [, $rel, $origin] = $this->employee();
        $this->assertSame($origin->id, $this->dateOnly($rel, '2026-02-15')->organizationalUnitId());

        $secondmentUnit = $this->createUnit();
        $secondment = app(StartFullSecondment::class)->handle($rel, $secondmentUnit, '2026-03-01');
        $assignmentUnit = $this->createUnit();
        $this->assign($rel, $assignmentUnit, '2026-05-01'); // S28: supersedes the secondment

        $this->assertSame('2026-05-01', $secondment->refresh()->effective_to->toDateString());
        $asOf = $this->dateOnly($rel, '2026-06-01');
        $this->assertSame(ActualWorkplaceAsOf::RESOLVED, $asOf->state());
        $this->assertSame([$assignmentUnit->id, 'assignment'], [$asOf->organizationalUnitId(), $asOf->source()]);
        $this->assertSame([], $asOf->partialAllocations());
        $this->assertSame($assignmentUnit->id, $this->weekday($rel, '2026-06-01')->organizationalUnitId(), 'the weekday reader agrees on scheduled weekdays');
    }

    // ---------------------------------------------------------------------
    // Scope guard
    // ---------------------------------------------------------------------

    public function test_s30_introduces_no_hours_attendance_payroll_or_generic_movement_concept(): void
    {
        foreach (['attendance', 'shift', 'working_hour', 'payroll', 'overtime', 'holiday', 'allocation_percent', 'movement_event'] as $forbidden) {
            $this->assertSame(0, DB::table('information_schema.tables')->whereNotIn('table_schema', ['pg_catalog', 'information_schema'])
                ->where('table_name', 'like', "%{$forbidden}%")->count(), "no {$forbidden} table");
        }
        $this->assertSame(
            ['RecordPartialSecondmentPeriod.php'],
            array_map('basename', glob(base_path('app/Modules/*/Application/Commands/*PartialSecondmentPeriod*.php'))),
            'exactly one explicit S30 command — no update/end/delete command',
        );
        foreach (['MovementEngine', 'Attendance', 'Payroll', 'Shift'] as $forbidden) {
            $this->assertSame([], glob(base_path("app/Modules/*/*/*{$forbidden}*.php")));
            $this->assertSame([], glob(base_path("app/Modules/*/*/*/*{$forbidden}*.php")));
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * A permanent relationship from 2026-01-01, placed at a fresh unit from 2026-01-15, with a
     * synthetic Work Schedule from 2026-01-01 (null = no schedule recorded).
     *
     * @param  list<string>|null  $schedule
     * @return array{0: Person, 1: EmploymentRelationship, 2: OrganizationalUnit}
     */
    private function employee(?array $schedule = self::SUN_THU): array
    {
        $person = $this->createPersonRecord();
        $rel = $this->createEmploymentRelationship($person, 'permanent', null, '2026-01-01');
        $origin = $this->createUnit();
        $this->recordPlacement($rel, $origin, '2026-01-15');

        if ($schedule !== null) {
            $this->schedule($rel, '2026-01-01', $schedule);
        }

        return [$person, $rel->refresh(), $origin];
    }

    /** @param list<string> $codes */
    private function partial(EmploymentRelationship $rel, OrganizationalUnit $unit, string $from, ?string $to, array $codes): PartialSecondmentPeriod
    {
        return app(RecordPartialSecondmentPeriod::class)->handle($rel, $unit, $from, $to, $codes);
    }

    /** @param list<string> $codes */
    private function schedule(EmploymentRelationship $rel, string $from, array $codes)
    {
        return app(RecordWorkSchedulePeriod::class)->handle($rel, $from, $codes);
    }

    private function assign(EmploymentRelationship $rel, OrganizationalUnit $unit, string $from)
    {
        return app(StartWorkplaceAssignment::class)->handle($rel, $unit, $from, $this->assignmentDecisionType());
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, $to, false);
    }

    private function dateOnly(EmploymentRelationship $rel, string $date): ActualWorkplaceAsOf
    {
        return app(ResolveActualWorkplaceForRelationshipAsOf::class)($rel->refresh(), $date);
    }

    private function weekday(EmploymentRelationship $rel, string $date, ?string $code = null): WeekdayActualWorkplaceAsOf
    {
        return app(ResolveWeekdayActualWorkplaceAsOf::class)($rel->refresh(), $date, $code);
    }

    private function url(Person $person, EmploymentRelationship $rel): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/partial-secondment-periods";
    }

    private function partialCount(EmploymentRelationship $rel): int
    {
        return PartialSecondmentPeriod::query()->where('employment_relationship_id', $rel->id)->count();
    }

    /** Snapshot of every movement stream (and partial membership) of the relationship. */
    private function rows(EmploymentRelationship $rel): array
    {
        $rows = [];
        foreach (['partial_secondment_periods', 'workplace_assignment_periods', 'full_secondment_periods'] as $table) {
            $rows[$table] = DB::table("hr.{$table}")->where('employment_relationship_id', $rel->id)
                ->orderBy('effective_from')->orderBy('id')->get(['id', 'organizational_unit_id', 'effective_from', 'effective_to'])
                ->map(fn ($r) => (array) $r)->all();
        }
        $rows['membership'] = DB::table('hr.partial_secondment_period_weekdays')->where('employment_relationship_id', $rel->id)
            ->orderBy('partial_secondment_period_id')->orderBy('weekday_id')->get()->map(fn ($r) => (array) $r)->all();

        return $rows;
    }

    private function scheduleRows(EmploymentRelationship $rel): array
    {
        return DB::table('hr.work_schedule_periods')->where('employment_relationship_id', $rel->id)
            ->orderBy('effective_from')->get(['id', 'effective_from', 'effective_to'])->map(fn ($r) => (array) $r)->all();
    }

    /** @param list<string> $codes */
    private function insertPartial(EmploymentRelationship $rel, OrganizationalUnit $unit, string $from, ?string $to, array $codes): string
    {
        $id = (string) Str::uuid7();
        DB::table('hr.partial_secondment_periods')->insert([
            'id' => $id, 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $unit->id,
            'effective_from' => $from, 'effective_to' => $to, 'created_at' => now(),
        ]);
        foreach ($codes as $code) {
            DB::insert(
                'insert into hr.partial_secondment_period_weekdays (partial_secondment_period_id, weekday_id, employment_relationship_id, period)
                 select p.id, w.id, p.employment_relationship_id, p.period from hr.partial_secondment_periods p, ref.weekdays w where p.id = ? and w.code = ?',
                [$id, $code],
            );
        }

        return $id;
    }

    /** @param list<string> $codes */
    private function insertSchedule(EmploymentRelationship $rel, string $from, ?string $to, array $codes): void
    {
        $id = (string) Str::uuid7();
        DB::table('hr.work_schedule_periods')->insert(['id' => $id, 'employment_relationship_id' => $rel->id, 'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()]);
        foreach ($codes as $code) {
            DB::table('hr.work_schedule_period_weekdays')->insert(['work_schedule_period_id' => $id, 'weekday_id' => DB::table('ref.weekdays')->where('code', $code)->value('id')]);
        }
    }

    /**
     * Forces the next INSERT into hr.$table to fail AFTER every application check has passed —
     * a transaction-scoped trigger (PostgreSQL DDL is transactional, so the test's own rollback
     * removes it). Used to prove a planned truncation rolls back with a failed creation.
     */
    private function failNextInsertInto(string $table): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION hr.s30_probe_fail_insert() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 's30 probe: forced insert failure'; END \$\$;
            DROP TRIGGER IF EXISTS s30_probe_fail_insert ON hr.{$table};
            CREATE TRIGGER s30_probe_fail_insert BEFORE INSERT ON hr.{$table} FOR EACH ROW EXECUTE FUNCTION hr.s30_probe_fail_insert();
            SQL);
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
