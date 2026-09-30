<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveEffectiveEmploymentStatusAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodEndException;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedBusinessDateClock;

/**
 * S32 (docs/bounded-temporary-employment-status-lifecycle-specification.md): bounded temporary status
 * periods, derived on_duty return, relationship-end bounds, movement independence, API hygiene.
 * Synthetic data only.
 */
class BoundedTemporaryStatusLifecycleTest extends HumanResourcesTestCase
{
    private function emp(string $from = '2026-01-01'): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, 'permanent', null, $from)];
    }

    private function rec($person, $rel, string $code, string $from, ?string $to = null)
    {
        return app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to);
    }

    private function rows($rel): array
    {
        return DB::table('hr.employment_status_periods as p')->join('ref.employment_status_details as d', 'd.id', '=', 'p.status_detail_id')
            ->where('p.employment_relationship_id', $rel->id)->orderBy('p.effective_from')
            ->get(['d.code', 'p.effective_from', 'p.effective_to'])->map(fn ($r) => [$r->code, $r->effective_from, $r->effective_to])->all();
    }

    private function eff($rel, string $d)
    {
        return app(ResolveEffectiveEmploymentStatusAsOf::class)($rel->refresh(), $d);
    }

    private function url($person, $rel, string $tail = 'status-periods'): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$rel->id}/{$tail}";
    }

    // ---- allow-list -------------------------------------------------------------------------

    public function test_each_allow_listed_code_accepts_a_bounded_period(): void
    {
        foreach (['traveling', 'suspended', 'unpaid_leave', 'external_sick_leave'] as $code) {
            [$person, $rel] = $this->emp();
            $p = $this->rec($person, $rel, $code, '2026-10-01', '2026-11-01');
            $this->assertSame('2026-11-01', $p->effective_to->toDateString(), $code);
        }
    }

    public function test_optional_end_codes_may_stay_open_ended(): void
    {
        foreach (['traveling', 'suspended'] as $code) {
            [$person, $rel] = $this->emp();
            $this->assertNull($this->rec($person, $rel, $code, '2026-10-01')->effective_to, $code);
        }
    }

    public function test_required_end_codes_reject_a_missing_effective_to(): void
    {
        foreach (['unpaid_leave', 'external_sick_leave'] as $code) {
            [$person, $rel] = $this->emp();
            try {
                $this->rec($person, $rel, $code, '2026-10-01');
                $this->fail("[$code] must require effective_to");
            } catch (InvalidStatusPeriodEndException $e) {
                $this->assertSame("effective_to is required for status '{$code}'.", $e->getMessage());
                $this->assertSame([], $this->rows($rel));
            }
        }
    }

    public function test_captive_and_every_other_code_reject_effective_to(): void
    {
        foreach (['captive', 'on_duty', 'retired', 'resigned', 'contract_ended', 'martyred', 'deceased'] as $code) {
            [$person, $rel] = $this->emp();
            try {
                $this->rec($person, $rel, $code, '2026-10-01', '2026-11-01');
                $this->fail("[$code] must reject effective_to");
            } catch (InvalidStatusPeriodEndException) {
                $this->assertSame([], $this->rows($rel), "[$code] nothing written");
                $this->assertSame('NOT_APPLICABLE', $rel->refresh()->end_knowledge_state);
            }
        }
    }

    public function test_captive_stays_open_ended(): void
    {
        [$person, $rel] = $this->emp();
        $this->assertNull($this->rec($person, $rel, 'captive', '2026-10-01')->effective_to);
    }

    public function test_end_must_be_strictly_after_start(): void
    {
        foreach (['2026-10-01', '2026-09-30'] as $to) {
            [$person, $rel] = $this->emp();
            try {
                $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', $to);
                $this->fail('end <= start must be rejected');
            } catch (InvalidStatusPeriodEndException) {
                $this->assertSame([], $this->rows($rel));
            }
        }
    }

    // ---- temporal write semantics -----------------------------------------------------------

    public function test_bounded_leave_after_on_duty_leaves_no_persisted_on_duty_row_afterwards(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'on_duty', '2026-09-27');
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');

        $this->assertSame([['on_duty', '2026-09-27', '2026-10-01'], ['unpaid_leave', '2026-10-01', '2026-11-01']], $this->rows($rel));
    }

    public function test_explicit_successor_starting_at_the_boundary_wins_over_the_derived_return(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');
        $this->rec($person, $rel, 'traveling', '2026-11-01');

        $this->assertCount(2, $this->rows($rel));
        $status = $this->eff($rel, '2026-11-01');
        $this->assertFalse($status->derived);
        $this->assertSame('traveling', $status->statusDetailCode);
        $this->assertSame('unpaid_leave', $this->eff($rel, '2026-10-31')->statusDetailCode);
    }

    public function test_a_bounded_period_may_supersede_the_tail_of_an_open_one_and_a_new_start_truncates_a_covering_bounded_one(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $this->rec($person, $rel, 'traveling', '2026-11-01');

        $this->assertSame([['unpaid_leave', '2026-10-01', '2026-11-01'], ['traveling', '2026-11-01', null]], $this->rows($rel));
    }

    public function test_a_bounded_period_strictly_inside_a_bounded_one_is_rejected_atomically(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $before = $this->rows($rel);

        $this->expectException(InvalidStatusPeriodEndException::class);
        try {
            $this->rec($person, $rel, 'external_sick_leave', '2026-10-10', '2026-10-20');
        } finally {
            $this->assertSame($before, $this->rows($rel));
        }
    }

    public function test_future_recorded_history_is_never_silently_rewritten(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-12-01', '2027-01-01');
        $before = $this->rows($rel);

        try {
            $this->rec($person, $rel, 'traveling', '2026-10-01', '2026-11-01');
            $this->fail('a start before recorded history must be rejected');
        } catch (InvalidStatusPeriodDateException) {
            $this->assertSame($before, $this->rows($rel));
        }
    }

    public function test_adjacent_bounded_periods_do_not_overlap(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');
        $this->rec($person, $rel, 'external_sick_leave', '2026-11-01', '2026-12-01');
        $this->assertCount(2, $this->rows($rel));
    }

    // ---- derived state ----------------------------------------------------------------------

    public function test_effective_status_inside_at_and_after_a_bounded_period(): void
    {
        [$person, $rel] = $this->emp();
        $p = $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');

        $this->assertNull($this->eff($rel, '2025-12-31'), 'before the relationship');
        $this->assertNull($this->eff($rel, '2026-09-30'), 'legacy gap before any status stays unresolved');
        $inside = $this->eff($rel, '2026-10-15');
        $this->assertFalse($inside->derived);
        $this->assertSame($p->id, $inside->periodId);
        $this->assertSame('unpaid_leave', $inside->statusDetailCode);

        $at = $this->eff($rel, '2026-11-01');
        $this->assertTrue($at->derived, 'end is exclusive: the boundary is already derived on_duty');
        $this->assertSame('on_duty', $at->statusDetailCode);
        $this->assertNull($at->periodId, 'a derived status has no row id');
        $this->assertSame($p->id, $at->derivedFromPeriodId);
        $this->assertTrue($this->eff($rel, '2030-01-01')->derived);
    }

    public function test_derived_on_duty_is_never_persisted_and_creates_no_audit_event(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'suspended', '2026-10-01', '2026-11-01');
        $audit = DB::table('audit.audit_entries')->count();

        $this->eff($rel, '2027-01-01');

        $this->assertSame(1, DB::table('hr.employment_status_periods')->where('employment_relationship_id', $rel->id)->count());
        $this->assertSame($audit, DB::table('audit.audit_entries')->count());
    }

    public function test_open_ended_and_non_bounded_closed_periods_never_derive(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'captive', '2026-10-01');
        $this->assertFalse($this->eff($rel, '2027-01-01')->derived);
        $this->assertSame('captive', $this->eff($rel, '2027-01-01')->statusDetailCode);

        [$person2, $rel2] = $this->emp();
        $this->rec($person2, $rel2, 'on_duty', '2026-09-27');
        $this->rec($person2, $rel2, 'captive', '2026-10-01');
        $this->rec($person2, $rel2, 'on_duty', '2026-11-01');
        DB::table('hr.employment_status_periods')->where('employment_relationship_id', $rel2->id)->where('effective_from', '2026-11-01')->delete();
        $this->assertNull($this->eff($rel2, '2027-01-01'), 'a closed captive period is a legacy-style gap, not a derivation');
    }

    public function test_derived_status_does_not_apply_after_a_known_relationship_end(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-12-01', false);

        $this->assertTrue($this->eff($rel, '2026-11-30')->derived);
        $this->assertNull($this->eff($rel, '2026-12-01'));
        $this->assertNull($this->eff($rel, '2027-01-01'));
    }

    public function test_unknown_legacy_relationship_never_fabricates_an_end(): void
    {
        [$person, $rel] = $this->emp();
        DB::table('hr.employment_relationships')->where('id', $rel->id)->update(['end_knowledge_state' => 'UNKNOWN_LEGACY']);
        $this->rec($person, $rel, 'traveling', '2026-10-01', '2026-11-01');

        $rel->refresh();
        $this->assertSame('UNKNOWN_LEGACY', $rel->end_knowledge_state);
        $this->assertNull($rel->effective_to);
        $this->assertTrue($this->eff($rel, '2027-06-01')->derived);
    }

    public function test_reappointment_does_not_inherit_a_status_or_a_derivation(): void
    {
        [$person, $old] = $this->emp();
        $this->rec($person, $old, 'unpaid_leave', '2026-10-01', '2026-11-01');
        app(EndEmploymentRelationship::class)->handle($person, $old->refresh(), $old->version, '2026-12-01', false);
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2027-01-01');

        $this->assertNull($this->eff($new, '2027-02-01'));
    }

    // ---- relationship end -------------------------------------------------------------------

    public function test_relationship_end_truncates_a_bounded_status_extending_past_it(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-12-01');
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-11-01', false);

        $this->assertSame([['unpaid_leave', '2026-10-01', '2026-11-01']], $this->rows($rel));
    }

    public function test_relationship_end_rejects_atomically_when_a_status_starts_on_or_after_it(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-12-01', '2027-01-01');
        $before = $this->rows($rel);

        try {
            DB::transaction(fn () => app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-11-01', false));
            $this->fail('must be rejected');
        } catch (InvalidEndDateException) {
            $this->assertSame($before, $this->rows($rel));
            $this->assertSame('NOT_APPLICABLE', $rel->refresh()->end_knowledge_state, 'atomic: the relationship end is rolled back too');
        }
    }

    public function test_a_status_cannot_be_recorded_on_an_already_ended_relationship(): void
    {
        [$person, $rel] = $this->emp();
        app(EndEmploymentRelationship::class)->handle($person, $rel->refresh(), $rel->version, '2026-11-01', false);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-10-15');
    }

    public function test_terminal_status_after_a_bounded_leave_ends_the_relationship_and_keeps_history(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $this->rec($person, $rel, 'resigned', '2026-11-15');

        $this->assertSame([['unpaid_leave', '2026-10-01', '2026-11-15'], ['resigned', '2026-11-15', null]], $this->rows($rel));
        $this->assertSame('KNOWN', $rel->refresh()->end_knowledge_state);
    }

    // ---- movement independence --------------------------------------------------------------

    private function movementSnapshot($rel): array
    {
        return array_map(fn ($t) => DB::table($t)->where('employment_relationship_id', $rel->id)->orderBy('id')->get()->toArray(), [
            'hr.organizational_placement_periods', 'hr.full_secondment_periods', 'hr.workplace_assignment_periods',
            'hr.partial_secondment_periods', 'hr.work_schedule_periods',
        ]);
    }

    public function test_bounded_status_and_its_expiry_never_touch_movements_or_the_work_schedule(): void
    {
        [$person, $rel] = $this->emp();
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-10');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        app(StartFullSecondment::class)->handle($rel->refresh(), $this->createUnit(), '2026-02-01');
        $before = $this->movementSnapshot($rel);

        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');
        $this->assertEquals($before, $this->movementSnapshot($rel), 'recording');
        $this->eff($rel, '2026-12-01');
        $this->assertEquals($before, $this->movementSnapshot($rel), 'expiry is a pure read');
    }

    public function test_transfer_partial_secondment_and_assignment_are_independent_of_a_bounded_status(): void
    {
        [$person, $rel] = $this->emp();
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-10');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        $this->rec($person, $rel, 'external_sick_leave', '2026-10-01', '2026-11-01');

        app(TransferEmployee::class)->handle($rel->refresh(), $this->createUnit(), '2026-10-10', $this->transferDecisionType());
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $this->createUnit(), '2026-10-05', null, ['MONDAY']);

        $this->assertSame([['external_sick_leave', '2026-10-01', '2026-11-01']], $this->rows($rel));
        $this->assertSame(1, DB::table('hr.partial_secondment_periods')->where('employment_relationship_id', $rel->id)->count());
    }

    public function test_assignment_start_during_a_bounded_status_is_independent(): void
    {
        [$person, $rel] = $this->emp();
        $this->recordPlacement($rel, $this->createUnit(), '2026-01-10');
        $this->rec($person, $rel, 'traveling', '2026-10-01', '2026-11-01');
        app(StartWorkplaceAssignment::class)->handle($rel->refresh(), $this->createUnit(), '2026-10-05', $this->assignmentDecisionType());
        $this->assertSame([['traveling', '2026-10-01', '2026-11-01']], $this->rows($rel));
    }

    // ---- S27 --------------------------------------------------------------------------------

    public function test_s27_population_resolves_temporary_derived_and_explicit_successor(): void
    {
        [$person, $rel] = $this->emp();
        [$person2, $rel2] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');
        $this->rec($person2, $rel2, 'unpaid_leave', '2026-10-01', '2026-11-01');
        $this->rec($person2, $rel2, 'traveling', '2026-11-01');

        $row = fn ($r, string $d) => collect(app(ListReportingPopulationAsOf::class)($d, [$r->id]))->first();

        $inside = $row($rel, '2026-10-15');
        $this->assertSame('unpaid_leave', $inside->statusDetailCode);
        $this->assertFalse($inside->statusDerived);
        $this->assertNotNull($inside->statusPeriodId);

        $derived = $row($rel, '2026-11-01');
        $this->assertSame('on_duty', $derived->statusDetailCode);
        $this->assertTrue($derived->statusDerived);
        $this->assertNull($derived->statusPeriodId);
        $this->assertNotNull($derived->derivedFromStatusPeriodId);
        $this->assertNotNull($derived->participatesInActiveWorkforce, 'on_duty behavior resolves on the same date');
        $this->assertTrue($derived->participatesInActiveWorkforce);

        $explicit = $row($rel2, '2026-11-05');
        $this->assertSame('traveling', $explicit->statusDetailCode);
        $this->assertFalse($explicit->statusDerived);

        $this->assertNull($row($rel, '2026-09-15')->statusDetailCode, 'unresolved before any status');
    }

    // ---- API --------------------------------------------------------------------------------

    public function test_api_records_a_bounded_status_and_returns_effective_to(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();

        $this->postJson($this->url($person, $rel), ['status_detail_code' => 'unpaid_leave', 'effective_from' => '2026-10-01', 'effective_to' => '2026-11-01'])
            ->assertStatus(201)->assertJsonPath('effective_to', '2026-11-01');

        $this->getJson($this->url($person, $rel, 'status-periods'))->assertOk()->assertJsonCount(1);
        $this->getJson($this->url($person, $rel, 'effective-status').'?as_of=2026-11-05')->assertOk()
            ->assertJsonPath('status.derived', true)->assertJsonPath('status.status_detail_code', 'on_duty')->assertJsonPath('status.period_id', null);
        $this->getJson($this->url($person, $rel, 'effective-status').'?as_of=2026-10-05')->assertOk()
            ->assertJsonPath('status.derived', false)->assertJsonPath('status.status_detail_code', 'unpaid_leave');
    }

    public function test_api_rejects_invalid_temporal_input_with_422_and_writes_nothing(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $cases = [
            'unsupported code' => ['on_duty', '2026-10-01', '2026-11-01', 'effective_to'],
            'captive' => ['captive', '2026-10-01', '2026-11-01', 'effective_to'],
            'required missing' => ['unpaid_leave', '2026-10-01', null, 'effective_to'],
            'malformed' => ['unpaid_leave', '2026-10-01', 'not-a-date', 'effective_to'],
            'timestamp' => ['unpaid_leave', '2026-10-01', '2026-11-01T10:00:00', 'effective_to'],
            'end equals start' => ['unpaid_leave', '2026-10-01', '2026-10-01', 'effective_to'],
            'end before start' => ['unpaid_leave', '2026-10-01', '2026-09-01', 'effective_to'],
        ];
        foreach ($cases as $name => [$code, $from, $to, $field]) {
            $body = ['status_detail_code' => $code, 'effective_from' => $from] + ($to === null ? [] : ['effective_to' => $to]);
            $this->postJson($this->url($person, $rel), $body)->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertSame([], $this->rows($rel));
    }

    public function test_api_rejects_unknown_temporal_semantics_keys(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        foreach (['end_date', 'effective_until', 'duration_days', 'duration', 'is_temporary', 'return_date', 'expected_return_date', 'auto_return'] as $key) {
            $this->postJson($this->url($person, $rel), ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-01', $key => '2026-11-01'])
                ->assertStatus(422)->assertJsonValidationErrors($key);
        }
        $this->assertSame([], $this->rows($rel));
    }

    public function test_api_conflicting_future_history_and_inside_bounded_are_422_and_ended_relationship_is_409(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $this->postJson($this->url($person, $rel), ['status_detail_code' => 'unpaid_leave', 'effective_from' => '2026-12-01', 'effective_to' => '2027-01-01'])->assertStatus(201);
        $this->postJson($this->url($person, $rel), ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-01', 'effective_to' => '2026-11-01'])->assertStatus(422);
        $this->postJson($this->url($person, $rel), ['status_detail_code' => 'traveling', 'effective_from' => '2026-12-05', 'effective_to' => '2026-12-10'])
            ->assertStatus(422)->assertJsonValidationErrors('effective_to');
        $this->assertCount(1, $this->rows($rel));

        [$p2, $r2] = $this->emp();
        app(EndEmploymentRelationship::class)->handle($p2, $r2->refresh(), $r2->version, '2026-11-01', false);
        $this->postJson($this->url($p2, $r2), ['status_detail_code' => 'traveling', 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-15'])->assertStatus(409);
    }

    public function test_api_audits_the_record_command_with_effective_to_and_no_expiry_event(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        $count = DB::table('audit.audit_entries')->where('action', 'hr.employment_status_period.record')->count();
        $this->postJson($this->url($person, $rel), ['status_detail_code' => 'suspended', 'effective_from' => '2026-10-01', 'effective_to' => '2026-11-01'])->assertStatus(201);
        $this->assertSame($count + 1, DB::table('audit.audit_entries')->where('action', 'hr.employment_status_period.record')->count());
        $event = DB::table('audit.audit_entries')->where('action', 'hr.employment_status_period.record')->latest('id')->first();
        $this->assertStringContainsString('2026-11-01', json_encode($event));
    }

    public function test_api_effective_status_validates_as_of_and_relationship_ownership(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $rel] = $this->emp();
        [$other] = $this->emp();
        $this->getJson($this->url($person, $rel, 'effective-status').'?as_of=2026-13-45')->assertStatus(422);
        $this->getJson($this->url($other, $rel, 'effective-status').'?as_of=2026-10-01')->assertStatus(404);
    }

    public function test_api_effective_status_defaults_to_the_authoritative_business_date_and_creates_no_row(): void
    {
        $this->actingAsHrAdministrator();
        $clock = new FixedBusinessDateClock('2026-10-15');
        $this->app->instance(BusinessDateClock::class, $clock);
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'unpaid_leave', '2026-10-01', '2026-11-01');

        $this->getJson($this->url($person, $rel, 'effective-status'))->assertOk()
            ->assertJsonPath('as_of', '2026-10-15')->assertJsonPath('status.status_detail_code', 'unpaid_leave')->assertJsonPath('status.derived', false);

        $clock->on('2026-11-01');
        $this->getJson($this->url($person, $rel, 'effective-status'))->assertOk()
            ->assertJsonPath('as_of', '2026-11-01')->assertJsonPath('status.status_detail_code', 'on_duty')->assertJsonPath('status.derived', true);

        $this->rec($person, $rel, 'traveling', '2026-11-01');
        $this->getJson($this->url($person, $rel, 'effective-status'))->assertOk()
            ->assertJsonPath('status.status_detail_code', 'traveling')->assertJsonPath('status.derived', false);

        $this->assertSame([['unpaid_leave', '2026-10-01', '2026-11-01'], ['traveling', '2026-11-01', null]], $this->rows($rel), 'reads never persist a synthetic on_duty row');
    }
}
