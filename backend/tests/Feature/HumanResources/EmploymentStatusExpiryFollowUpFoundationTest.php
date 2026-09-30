<?php

namespace Tests\Feature\HumanResources;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\ScanEmploymentStatusExpiryFollowUps;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Queries\ResolveEffectiveEmploymentStatusAsOf;
use App\Modules\HumanResources\Domain\EmploymentStatusExpiryPolicy;
use App\Modules\HumanResources\Domain\StatusExpiryFollowUpEmission;
use App\Modules\HumanResources\Domain\StatusExpiryFollowUpScanResult;
use App\Modules\HumanResources\Domain\StatusFollowUpSuppressionReason;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusExpiryFollowUp;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Application\Clock\BusinessDateClock;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\FixedBusinessDateClock;

/**
 * S38 Temporary Employment Status Expiry Follow-up (docs/employment-status-expiry-followup-specification.md):
 * the 7-calendar-day follow-up for eligible bounded temporary employment statuses — the frozen eligibility
 * allow-list, the exact due date and window, derived LAPSED, successor-at-E, relationship end, truncation,
 * idempotency, relationship isolation, the read API with its dedicated permission, and PostgreSQL integrity.
 * Real cross-session races live in ConcurrencyTest. Every fixture is synthetic; the business date is always
 * explicit or a fixed clock. Statuses start after 2026-09-26, the date from which S06 behaviors are authoritative.
 */
class EmploymentStatusExpiryFollowUpFoundationTest extends HumanResourcesTestCase
{
    private FixedBusinessDateClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedBusinessDateClock('2026-11-10');
        $this->app->instance(BusinessDateClock::class, $this->clock);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function emp(string $type = 'permanent', string $from = '2026-01-01', ?Person $person = null): array
    {
        $person ??= $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $type, null, $from)];
    }

    private function rec(Person $person, EmploymentRelationship $rel, string $code, string $from, ?string $to = null): EmploymentStatusPeriod
    {
        return app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to);
    }

    /** An employee with one bounded status [$from, $to); returns [person, relationship, period]. */
    private function withStatus(string $code = 'unpaid_leave', string $from = '2026-10-01', ?string $to = '2026-11-15', string $type = 'permanent'): array
    {
        [$person, $rel] = $this->emp($type);
        $period = $this->rec($person, $rel, $code, $from, $to);

        return [$person, $rel->refresh(), $period];
    }

    private function scan(string $date): StatusExpiryFollowUpScanResult
    {
        return app(ScanEmploymentStatusExpiryFollowUps::class)->handle($date);
    }

    private function emitOne(string $periodId, string $relationshipId, string $expectedEnd, string $date): StatusExpiryFollowUpEmission
    {
        return app(ScanEmploymentStatusExpiryFollowUps::class)->emit(
            $periodId, $relationshipId, $expectedEnd, $date,
            new CommandContext(Actor::system(ScanEmploymentStatusExpiryFollowUps::ACTOR_LABEL), CorrelationId::generate(), Source::System),
        );
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    /** @return list<EmploymentStatusExpiryFollowUp> */
    private function followUpsOf(EmploymentRelationship $rel): array
    {
        return EmploymentStatusExpiryFollowUp::query()->where('employment_relationship_id', $rel->id)->orderBy('expected_effective_to')->orderBy('id')->get()->all();
    }

    private function onlyFollowUp(EmploymentRelationship $rel): EmploymentStatusExpiryFollowUp
    {
        $rows = $this->followUpsOf($rel);
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    /** A legacy period written directly (the command refuses retired / non-bounded codes), for eligibility negatives. */
    private function legacyPeriod(EmploymentRelationship $rel, string $code, string $from, ?string $to): string
    {
        $id = (string) Str::uuid7();
        DB::table('hr.employment_status_periods')->insert([
            'id' => $id, 'employment_relationship_id' => $rel->id, 'status_detail_id' => $this->statusDetail($code)->id,
            'effective_from' => $from, 'effective_to' => $to, 'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * Audit entries of one S38 action about the given relationships only. Scoped on purpose: the committed
     * cross-session tests in ConcurrencyTest leave real (immutable) audit rows behind, so a global count would
     * depend on which other tests ran before.
     *
     * @param  list<string>  $relationshipIds
     */
    private function auditCount(string $action, array $relationshipIds): int
    {
        return AuditEntry::query()->where('action', $action)
            ->where(fn ($q) => $q->whereRaw("(changes::jsonb ->> 'employment_relationship_id') = any (?)", ['{'.implode(',', $relationshipIds).'}'])
                ->orWhereRaw("(metadata::jsonb ->> 'employment_relationship_id') = any (?)", ['{'.implode(',', $relationshipIds).'}']))
            ->count();
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

    // ---------------------------------------------------------------------------------------------------------
    // A. Eligibility
    // ---------------------------------------------------------------------------------------------------------

    public function test_a1_a6_a7_traveling_with_an_end_unpaid_leave_and_external_sick_leave_are_eligible(): void
    {
        foreach (['traveling', 'unpaid_leave', 'external_sick_leave'] as $code) {
            [, $rel, $period] = $this->withStatus($code);

            $result = $this->scan('2026-11-10');

            $row = $this->onlyFollowUp($rel);
            $this->assertContains($row->getKey(), $result->emitted, $code);
            $this->assertSame([EmploymentStatusExpiryPolicy::KIND, $period->id, '2026-11-15', '2026-11-08', 'ACTIONABLE'], [
                $row->followup_kind, $row->employment_status_period_id, $row->expected_effective_to->toDateString(), $row->due_date->toDateString(), $row->status,
            ], $code);
            $this->assertNull($row->suppression_reason);
        }
    }

    public function test_a4_suspended_with_an_end_is_eligible_and_a2_a5_without_an_end_is_not(): void
    {
        [, $relEnd] = $this->withStatus('suspended');
        [$pOpen, $relOpen] = $this->emp();
        $this->rec($pOpen, $relOpen, 'suspended', '2026-10-01');
        [$tOpen, $relTravel] = $this->emp();
        $this->rec($tOpen, $relTravel, 'traveling', '2026-10-01');

        $this->scan('2026-11-10');

        $this->assertCount(1, $this->followUpsOf($relEnd));
        $this->assertSame([], $this->followUpsOf($relOpen), 'suspended without an end: no end is invented');
        $this->assertSame([], $this->followUpsOf($relTravel), 'traveling without an end: no follow-up');
    }

    public function test_a3_captive_is_never_eligible(): void
    {
        [$person, $rel] = $this->emp();
        $this->rec($person, $rel, 'captive', '2026-10-01');
        foreach (['2026-10-10', '2026-11-10', '2027-01-01'] as $date) {
            $this->assertSame([], $this->scan($date)->emitted, $date);
        }
        $this->assertSame([], $this->followUpsOf($rel));
        $this->assertFalse(EmploymentStatusExpiryPolicy::isEligible('captive', '2026-11-15'), 'even a (legacy) captive row with an end is not eligible');
    }

    public function test_a8_a9_a10_on_duty_retired_return_intention_codes_and_other_legacy_codes_get_no_follow_up(): void
    {
        [$person, $relDuty] = $this->emp();
        $this->rec($person, $relDuty, 'on_duty', '2026-10-01');
        [, $relWants] = $this->emp();
        $this->legacyPeriod($relWants, 'wants_to_return', '2026-10-01', '2026-11-15');
        [, $relNot] = $this->emp();
        $this->legacyPeriod($relNot, 'does_not_want_to_return', '2026-10-01', '2026-11-15');
        [, $relEnded] = $this->emp();
        $this->legacyPeriod($relEnded, 'retired', '2026-10-01', '2026-11-15');
        [, $relLegacyOpen] = $this->emp();
        $this->legacyPeriod($relLegacyOpen, 'unpaid_leave', '2026-10-01', null);   // a legacy open row: no end is fabricated

        $result = $this->scan('2026-11-10');

        $this->assertSame([], $result->emitted);
        $this->assertSame(0, EmploymentStatusExpiryFollowUp::query()->count());
    }

    public function test_the_eligibility_policy_is_an_explicit_allow_list_owned_by_s38(): void
    {
        $this->assertSame(['traveling', 'suspended', 'unpaid_leave', 'external_sick_leave'], EmploymentStatusExpiryPolicy::ELIGIBLE_CODES);
        foreach (['traveling', 'suspended', 'unpaid_leave', 'external_sick_leave'] as $code) {
            $this->assertTrue(EmploymentStatusExpiryPolicy::isEligible($code, '2026-11-15'));
            $this->assertFalse(EmploymentStatusExpiryPolicy::isEligible($code, null), "{$code} requires an end");
        }
        foreach (['captive', 'on_duty', 'retired', 'resigned', 'contract_ended', 'martyred', 'deceased', 'wants_to_return', 'does_not_want_to_return', 'unknown_code'] as $code) {
            $this->assertFalse(EmploymentStatusExpiryPolicy::isEligible($code, '2026-11-15'), $code);
        }
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path('app/Modules/HumanResources/Domain/EmploymentStatusExpiryPolicy.php')));
        $this->assertStringNotContainsString('MovementExpiryPolicy', $code, 'S38 owns its constant; it does not reuse S31\'s movement lead time');
        $this->assertStringNotContainsString('BoundedEmploymentStatusPolicy', $code, 'eligibility is an explicit allow-list, not inferred from generic bounded behaviour');
    }

    // ---------------------------------------------------------------------------------------------------------
    // B. Seven-calendar-day window
    // ---------------------------------------------------------------------------------------------------------

    public function test_b11_b12_nothing_before_e_minus_7_and_the_follow_up_on_it(): void
    {
        [, $rel] = $this->withStatus();   // E = 2026-11-15, due 2026-11-08

        $this->assertSame([], $this->scan('2026-11-07')->emitted, 'the day before the due date');
        $this->assertSame([], $this->scan('2026-10-01')->emitted);
        $this->assertCount(1, $this->scan('2026-11-08')->emitted, 'on the due date');
        $this->assertSame('2026-11-08', $this->onlyFollowUp($rel)->due_date->toDateString(), 'b16: due_date is exactly E − 7');
    }

    public function test_b13_every_day_from_e_minus_6_to_e_minus_1_emits_when_not_yet_emitted(): void
    {
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'] as $date) {
            [, $rel] = $this->withStatus();

            $this->assertCount(1, $this->scan($date)->emitted, $date);
            $this->assertSame('2026-11-08', $this->onlyFollowUp($rel)->due_date->toDateString(), "{$date}: the due date stays E − 7");
        }
    }

    public function test_b14_b15_no_new_actionable_follow_up_at_or_after_e(): void
    {
        [, $rel] = $this->withStatus();

        foreach (['2026-11-15', '2026-11-16', '2026-12-31', '2027-06-01'] as $date) {
            $this->assertSame([], $this->scan($date)->emitted, $date);
        }
        $this->assertSame([], $this->followUpsOf($rel), 'an expired status is history — no retroactive warning');
    }

    public function test_the_window_is_inclusive_of_the_due_date_and_exclusive_of_the_end_date(): void
    {
        $this->assertFalse(EmploymentStatusExpiryPolicy::isInWindow('2026-11-15', '2026-11-07'));
        $this->assertTrue(EmploymentStatusExpiryPolicy::isInWindow('2026-11-15', '2026-11-08'));
        $this->assertTrue(EmploymentStatusExpiryPolicy::isInWindow('2026-11-15', '2026-11-14'));
        $this->assertFalse(EmploymentStatusExpiryPolicy::isInWindow('2026-11-15', '2026-11-15'));
        $this->assertSame(7, EmploymentStatusExpiryPolicy::WARNING_LEAD_DAYS);
        $this->assertSame('2026-02-28', EmploymentStatusExpiryPolicy::dueDate('2026-03-07'), 'pure DATE arithmetic across month ends');
        $this->assertSame('2026-12-28', EmploymentStatusExpiryPolicy::dueDate('2027-01-04'), 'and across year ends');
    }

    public function test_the_due_date_is_calendar_arithmetic_regardless_of_weekday_or_month(): void
    {
        [, $rel] = $this->withStatus('unpaid_leave', '2026-10-01', '2027-01-03');   // due 2026-12-27

        $this->assertSame([], $this->scan('2026-12-26')->emitted);
        $this->assertCount(1, $this->scan('2026-12-27')->emitted);
        $this->assertSame('2026-12-27', $this->onlyFollowUp($rel)->due_date->toDateString());
    }

    // ---------------------------------------------------------------------------------------------------------
    // C. State (LAPSED is derived, never persisted)
    // ---------------------------------------------------------------------------------------------------------

    public function test_c17_to_c21_states_are_derived_at_read_time_and_lapsed_is_not_a_database_state(): void
    {
        [, $rel] = $this->withStatus();
        $this->scan('2026-11-10');
        $row = $this->onlyFollowUp($rel);

        $this->assertSame('ACTIONABLE', $row->status, 'c17');
        $this->assertSame('ACTIONABLE', $row->readState('2026-11-10'), 'c18');
        $this->assertSame('ACTIONABLE', $row->readState('2026-11-14'), 'c18: the day before E');
        $this->assertSame('LAPSED', $row->readState('2026-11-15'), 'c19: at E');
        $this->assertSame('LAPSED', $row->readState('2026-11-16'), 'c20: after E');

        $this->scan('2026-11-20');   // a later scan does not write LAPSED
        $this->assertSame('ACTIONABLE', $row->refresh()->status, 'c21: still ACTIONABLE in storage');
        $error = $this->queryError(fn () => DB::statement("UPDATE automation.employment_status_expiry_followups SET status = 'LAPSED' WHERE id = ?", [$row->id]));
        $this->assertStringContainsString('employment_status_expiry_followups_status_check', $error->getMessage(), 'LAPSED cannot be persisted');
    }

    // ---------------------------------------------------------------------------------------------------------
    // D. Successor at E
    // ---------------------------------------------------------------------------------------------------------

    public function test_d22_an_explicit_successor_already_at_e_means_no_follow_up_is_created(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $this->rec($person, $rel, 'on_duty', '2026-11-15');

        $result = $this->scan('2026-11-10');

        $this->assertSame([], $result->emitted);
        $this->assertSame([['employment_status_period_id' => $period->id, 'reason' => 'SUCCESSOR_RECORDED']], $result->notCreated);
        $this->assertSame([], $this->followUpsOf($rel));
        $this->assertSame(0, $this->auditCount('hr.employment_status_expiry_followup.emit', [$rel->id]));
    }

    public function test_d23_a_successor_recorded_at_e_after_emission_suppresses_the_follow_up(): void
    {
        [$person, $rel] = $this->withStatus();
        $this->scan('2026-11-10');
        $row = $this->onlyFollowUp($rel);

        $this->rec($person, $rel, 'suspended', '2026-11-15');   // explicit successor starting exactly at E
        $result = $this->scan('2026-11-11');

        $this->assertSame([$row->id], $result->suppressed);
        $row->refresh();
        $this->assertSame(['SUPPRESSED', 'SUCCESSOR_RECORDED'], [$row->status, $row->suppression_reason]);
        $this->assertNotNull($row->suppressed_at);
        $this->assertSame('2026-11-15', $row->expected_effective_to->toDateString(), 'history is kept as recorded');
    }

    public function test_d24_d25_the_successor_wins_in_the_s32_resolver_and_no_synthetic_on_duty_is_ever_persisted(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $this->rec($person, $rel, 'suspended', '2026-11-15');
        $before = EmploymentStatusPeriod::query()->where('employment_relationship_id', $rel->id)->count();

        $this->scan('2026-11-10');
        $this->scan('2026-11-16');
        $resolved = app(ResolveEffectiveEmploymentStatusAsOf::class)($rel->refresh(), '2026-11-15');

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->derived, 'the explicit successor controls the boundary — S32 is unchanged');
        $this->assertSame($before, EmploymentStatusPeriod::query()->where('employment_relationship_id', $rel->id)->count(), 'the scanner persists no status period');
        $this->assertSame(0, DB::table('hr.employment_status_periods as p')->join('ref.employment_status_details as d', 'd.id', '=', 'p.status_detail_id')
            ->where('p.employment_relationship_id', $rel->id)->where('d.code', 'on_duty')->count(), 'no synthetic on_duty row');
        $this->assertSame('2026-11-15', $period->refresh()->effective_to->toDateString());
    }

    public function test_a_successor_starting_after_e_leaves_the_derived_return_and_the_warning_intact(): void
    {
        [$person, $rel] = $this->withStatus();
        $this->rec($person, $rel, 'suspended', '2026-11-20');   // a gap after E: the derived on_duty covers [E, 11-20)

        $this->assertCount(1, $this->scan('2026-11-10')->emitted);
        $this->assertSame([], $this->scan('2026-11-11')->suppressed);
        $this->assertSame('ACTIONABLE', $this->onlyFollowUp($rel)->status);
    }

    // ---------------------------------------------------------------------------------------------------------
    // E. Relationship end
    // ---------------------------------------------------------------------------------------------------------

    public function test_e26_a_relationship_ending_before_e_suppresses_with_relationship_ended_not_truncated_earlier(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $this->scan('2026-11-10');
        $row = $this->onlyFollowUp($rel);

        $this->end($person, $rel, '2026-11-12');   // S32/S35 truncate the status at the end date (the period end moves too)
        $this->assertSame('2026-11-12', $period->refresh()->effective_to->toDateString(), 'S32: the relationship end truncates the bounded status');

        $result = $this->scan('2026-11-11');

        $this->assertSame([$row->id], $result->suppressed);
        $this->assertSame('RELATIONSHIP_ENDED', $row->refresh()->suppression_reason, 'the root cause wins over TRUNCATED_EARLIER');
        $this->assertSame('2026-11-15', $row->expected_effective_to->toDateString());
    }

    public function test_e27_a_relationship_ending_exactly_at_e_suppresses_the_follow_up(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $this->scan('2026-11-10');
        $row = $this->onlyFollowUp($rel);

        $this->end($person, $rel, '2026-11-15');
        $this->assertSame('2026-11-15', $period->refresh()->effective_to->toDateString(), 'the period already ends at the boundary — nothing to truncate');

        $this->scan('2026-11-12');

        $this->assertSame(['SUPPRESSED', 'RELATIONSHIP_ENDED'], [$row->refresh()->status, $row->suppression_reason]);
    }

    public function test_e28_a_relationship_ending_after_e_does_not_suppress_the_follow_up(): void
    {
        [$person, $rel] = $this->withStatus();
        $this->scan('2026-11-10');

        $this->end($person, $rel, '2026-11-20');
        $result = $this->scan('2026-11-12');

        $this->assertSame([], $result->suppressed);
        $this->assertSame('ACTIONABLE', $this->onlyFollowUp($rel)->status, 'no premature RELATIONSHIP_ENDED');
    }

    public function test_e29_a_follow_up_is_not_created_for_a_status_the_relationship_end_truncated(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $this->end($person, $rel, '2026-11-10');   // truncates [10-01, 11-15) to [10-01, 11-10)

        $result = $this->scan('2026-11-09');

        $this->assertSame([], $result->emitted);
        $this->assertSame([['employment_status_period_id' => $period->id, 'reason' => 'RELATIONSHIP_ENDED']], $result->notCreated);
        $this->assertSame([], $this->followUpsOf($rel));
        $this->assertSame('2026-11-10', $period->refresh()->effective_to->toDateString(), 'S32/S35 truncation is untouched');
    }

    public function test_the_frozen_priority_relationship_ended_wins_over_successor_recorded(): void
    {
        // A terminal/ending status starting exactly at E is BOTH "an explicit successor at E" and "the relationship ends at E".
        [$person, $rel, $period] = $this->withStatus();
        $this->scan('2026-11-10');
        $row = $this->onlyFollowUp($rel);
        $this->rec($person, $rel, 'resigned', '2026-11-15');

        $this->scan('2026-11-11');

        $this->assertSame(['SUPPRESSED', 'RELATIONSHIP_ENDED'], [$row->refresh()->status, $row->suppression_reason], 'the root cause wins, deterministically');

        // The same priority decides why a follow-up is NOT created.
        [$person2, $rel2, $period2] = $this->withStatus();
        $this->rec($person2, $rel2, 'resigned', '2026-11-15');
        $result = $this->scan('2026-11-10');

        $this->assertContains(['employment_status_period_id' => $period2->id, 'reason' => 'RELATIONSHIP_ENDED'], $result->notCreated);
        $this->assertSame([], $this->followUpsOf($rel2));
    }

    public function test_an_unknown_legacy_end_is_not_a_known_end(): void
    {
        $person = $this->createPersonRecord();
        $relId = (string) Str::uuid7();
        DB::table('hr.employment_relationships')->insert([
            'id' => $relId, 'person_id' => $person->id, 'employment_type_id' => $this->employmentType('contract')->id,
            'employee_number' => 'CN-LEGACY-'.Str::upper(Str::random(8)), 'employee_number_scheme' => 'CONTRACT', 'effective_from' => '2026-01-01',
            'effective_to' => null, 'end_knowledge_state' => 'UNKNOWN_LEGACY', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rel = EmploymentRelationship::query()->findOrFail($relId);
        $this->legacyPeriod($rel, 'unpaid_leave', '2026-10-01', '2026-11-15');

        $this->assertCount(1, $this->scan('2026-11-10')->emitted, 'no end date is fabricated for an UNKNOWN_LEGACY relationship');
    }

    // ---------------------------------------------------------------------------------------------------------
    // F. Truncation
    // ---------------------------------------------------------------------------------------------------------

    public function test_f30_f31_f32_an_ordinary_earlier_truncation_suppresses_the_old_follow_up_and_keeps_its_history(): void
    {
        [$person, $rel, $period] = $this->withStatus('unpaid_leave', '2026-10-01', '2026-11-20');   // due 11-13
        $this->scan('2026-11-14');
        $old = $this->onlyFollowUp($rel);

        $this->rec($person, $rel, 'suspended', '2026-11-17');   // truncates the leave to [10-01, 11-17)
        $this->assertSame('2026-11-17', $period->refresh()->effective_to->toDateString());
        $result = $this->scan('2026-11-15');

        $this->assertSame([$old->id], $result->suppressed);
        $old->refresh();
        $this->assertSame(['SUPPRESSED', 'TRUNCATED_EARLIER'], [$old->status, $old->suppression_reason]);
        $this->assertSame('2026-11-20', $old->expected_effective_to->toDateString(), 'f31: the old expected end is immutable');
        $this->assertSame('2026-11-13', $old->due_date->toDateString());
        $this->assertSame([], $result->emitted, 'the new end 11-17 is followed by an explicit successor: no new follow-up');
        $this->assertSame([['employment_status_period_id' => $period->id, 'reason' => 'SUCCESSOR_RECORDED']], $result->notCreated);
        $this->assertCount(1, $this->followUpsOf($rel), 'f32: no duplicate logical row for the old end');
    }

    public function test_a_truncation_without_a_successor_creates_a_new_logical_follow_up_for_the_new_end_when_it_is_in_the_window(): void
    {
        [, $rel, $period] = $this->withStatus('unpaid_leave', '2026-10-01', '2026-11-20');
        $this->scan('2026-11-14');
        $old = $this->onlyFollowUp($rel);

        // A truncation that no command produces without a successor or a relationship end — simulated directly so the
        // reconciliation rule itself is proven: the old row is suppressed, the new end is its OWN logical follow-up.
        DB::table('hr.employment_status_periods')->where('id', $period->id)->update(['effective_to' => '2026-11-18']);
        $result = $this->scan('2026-11-15');

        $this->assertSame([$old->id], $result->suppressed);
        $this->assertCount(1, $result->emitted);
        $rows = $this->followUpsOf($rel);
        $this->assertSame(['TRUNCATED_EARLIER', null], [$rows[1]->suppression_reason, $rows[0]->suppression_reason], 'rows ordered by end: 11-18 then 11-20');
        $this->assertSame(['2026-11-18', '2026-11-20'], array_map(fn ($r) => $r->expected_effective_to->toDateString(), $rows));
        $this->assertSame('2026-11-11', $rows[0]->due_date->toDateString());
    }

    public function test_a_period_end_that_moved_later_or_was_cleared_is_never_given_an_unsupported_reason(): void
    {
        [, $rel, $period] = $this->withStatus();
        $this->scan('2026-11-10');
        $row = $this->onlyFollowUp($rel);

        // Unreachable through any command (no PATCH): simulated directly to prove no invented reason is written.
        DB::table('hr.employment_status_periods')->where('id', $period->id)->update(['effective_to' => '2026-11-25']);
        $result = $this->scan('2026-11-11');

        $this->assertSame([], $result->suppressed);
        $this->assertSame('ACTIONABLE', $row->refresh()->status);
    }

    // ---------------------------------------------------------------------------------------------------------
    // G. Idempotency and audit
    // ---------------------------------------------------------------------------------------------------------

    public function test_g33_g34_repeated_scans_never_duplicate_a_follow_up_or_its_audit(): void
    {
        [, $rel] = $this->withStatus();

        $first = $this->scan('2026-11-10');
        $second = $this->scan('2026-11-10');
        $third = $this->scan('2026-11-12');

        $this->assertCount(1, $first->emitted);
        $this->assertSame([], $second->emitted);
        $this->assertSame([], $third->emitted);
        $this->assertCount(1, $this->followUpsOf($rel));
        $this->assertSame(1, $this->auditCount('hr.employment_status_expiry_followup.emit', [$rel->id]));
    }

    public function test_g35_repeated_reconciliation_never_repeats_a_suppression_transition_or_its_audit(): void
    {
        [$person, $rel] = $this->withStatus();
        $this->scan('2026-11-10');
        $this->end($person, $rel, '2026-11-12');

        $first = $this->scan('2026-11-11');
        $second = $this->scan('2026-11-11');
        $third = $this->scan('2026-11-12');

        $this->assertCount(1, $first->suppressed);
        $this->assertSame([], $second->suppressed);
        $this->assertSame([], $third->suppressed);
        $this->assertSame(1, $this->auditCount('hr.employment_status_expiry_followup.suppress', [$rel->id]));
    }

    public function test_a_scan_that_finds_nothing_writes_no_audit_entry(): void
    {
        [, $rel] = $this->withStatus('unpaid_leave', '2026-10-01', '2026-12-30');

        $this->scan('2026-11-10');

        $this->assertSame(0, $this->auditCount('hr.employment_status_expiry_followup.emit', [$rel->id]) + $this->auditCount('hr.employment_status_expiry_followup.suppress', [$rel->id]));
    }

    public function test_the_emission_audit_is_a_system_actor_entry_with_ids_dates_and_no_pii(): void
    {
        [$person, $rel, $period] = $this->withStatus();

        $emitted = $this->scan('2026-11-10')->emitted[0];

        $entry = $this->latestAuditEntryFor('hr.employment_status_expiry_followup.emit');
        $this->assertSame('SYSTEM', $entry->actor_type->value);
        $this->assertNull($entry->actor_principal_id);
        $this->assertSame('SCHEDULER_STATUS_EXPIRY', $entry->actor_label);
        $this->assertSame(Source::System, $entry->source);
        $this->assertSame('hr_employment_status_expiry_followup', $entry->target_type);
        $this->assertSame($emitted, $entry->target_id);
        $this->assertEquals([
            'followup_kind' => 'EXPIRY_WARNING_7D', 'employment_status_period_id' => $period->id, 'employment_relationship_id' => $rel->id,
            'expected_effective_to' => '2026-11-15', 'due_date' => '2026-11-08', 'status' => 'ACTIONABLE',
        ], $entry->changes);
        $this->assertEquals(['business_date' => '2026-11-10'], $entry->metadata);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    public function test_the_suppression_audit_records_the_reason_and_the_end_dates(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $emitted = $this->scan('2026-11-10')->emitted[0];
        $this->end($person, $rel, '2026-11-12');
        $this->scan('2026-11-11');

        $entry = $this->latestAuditEntryFor('hr.employment_status_expiry_followup.suppress');
        $this->assertSame($emitted, $entry->target_id);
        $this->assertSame('SCHEDULER_STATUS_EXPIRY', $entry->actor_label);
        $this->assertEquals(['status' => 'SUPPRESSED', 'suppression_reason' => 'RELATIONSHIP_ENDED'], $entry->changes);
        $this->assertSame($period->id, $entry->metadata['employment_status_period_id']);
        $this->assertSame('2026-11-15', $entry->metadata['expected_effective_to']);
        $this->assertSame('2026-11-12', $entry->metadata['current_effective_to']);
        $this->assertSame('2026-11-08', $entry->metadata['due_date']);
        $this->assertSame('2026-11-11', $entry->metadata['business_date']);
    }

    public function test_a_candidate_whose_period_changed_between_discovery_and_emission_is_never_emitted(): void
    {
        [$person, $rel, $period] = $this->withStatus();
        $this->rec($person, $rel, 'suspended', '2026-11-12');   // truncates [10-01, 11-15) to [10-01, 11-12)

        $emission = $this->emitOne($period->id, $rel->id, '2026-11-15', '2026-11-10');   // the stale discovery result

        $this->assertSame(StatusExpiryFollowUpEmission::STALE, $emission->outcome);
        $this->assertSame(StatusFollowUpSuppressionReason::TruncatedEarlier, $emission->reason);
        $this->assertSame([], $this->followUpsOf($rel));
        $this->assertSame(StatusExpiryFollowUpEmission::NOT_DUE, $this->emitOne($period->id, $rel->id, '2026-11-12', '2026-11-01')->outcome);
    }

    // ---------------------------------------------------------------------------------------------------------
    // H. Reappointment isolation
    // ---------------------------------------------------------------------------------------------------------

    public function test_h36_h37_h38_a_reappointment_never_adopts_or_reactivates_an_older_relationships_follow_up(): void
    {
        [$person, $old] = $this->emp('contract');
        $this->rec($person, $old, 'unpaid_leave', '2026-10-01', '2026-11-15');
        $this->scan('2026-11-10');
        $oldRow = $this->onlyFollowUp($old);

        $this->end($person, $old, '2026-11-12');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-12');
        $this->rec($person, $new, 'unpaid_leave', '2026-11-13', '2026-11-20');   // due 11-13
        $this->scan('2026-11-11');
        $result = $this->scan('2026-11-13');

        $oldRow->refresh();
        $this->assertSame([$old->id, 'SUPPRESSED', 'RELATIONSHIP_ENDED'], [$oldRow->employment_relationship_id, $oldRow->status, $oldRow->suppression_reason]);
        $this->assertCount(1, $result->emitted, 'the new relationship gets its OWN follow-up');
        $newRow = $this->onlyFollowUp($new);
        $this->assertSame($new->id, $newRow->employment_relationship_id);
        $this->assertNotSame($oldRow->id, $newRow->id);
        $this->assertNotSame($oldRow->employment_status_period_id, $newRow->employment_status_period_id);
        $this->assertSame('ACTIONABLE', $newRow->status);
        $this->assertSame([$oldRow->id], array_map(fn ($r) => $r->id, $this->followUpsOf($old)), 'the old relationship keeps exactly its own row');
        $this->assertSame($person->id, $old->refresh()->person_id);
        $this->assertSame($person->id, $new->refresh()->person_id, 'same Person, isolated relationships');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Independence (S31 / S32 / movements)
    // ---------------------------------------------------------------------------------------------------------

    public function test_the_scanner_never_mutates_movements_status_history_or_s31_follow_ups(): void
    {
        [$person, $rel] = $this->withStatus();
        $unit = $this->createUnit();
        $this->recordPlacement($rel->refresh(), $this->createUnit(), '2026-01-15');
        app(StartFullSecondment::class)->handle($rel->refresh(), $unit, '2026-10-20');
        $snapshot = fn () => [
            DB::table('hr.full_secondment_periods')->orderBy('id')->get()->toArray(),
            DB::table('hr.workplace_assignment_periods')->orderBy('id')->get()->toArray(),
            DB::table('hr.partial_secondment_periods')->orderBy('id')->get()->toArray(),
            DB::table('hr.organizational_placement_periods')->orderBy('id')->get()->toArray(),
            DB::table('hr.work_schedule_periods')->orderBy('id')->get()->toArray(),
            DB::table('hr.employment_status_periods')->orderBy('id')->get()->toArray(),
            DB::table('hr.employment_relationships')->orderBy('id')->get()->toArray(),
            DB::table('automation.movement_expiry_followups')->orderBy('id')->get()->toArray(),
        ];
        $before = $snapshot();

        $this->scan('2026-11-10');
        $this->scan('2026-11-16');

        $this->assertEquals($before, $snapshot(), 'a status follow-up scan changes no movement, status, relationship or S31 row');
        $this->assertSame(1, EmploymentStatusExpiryFollowUp::query()->count());
    }

    public function test_a_status_and_an_active_secondment_coexist_and_the_secondment_outlives_the_status(): void
    {
        [, $rel] = $this->withStatus();
        $dest = $this->createUnit();
        $this->recordPlacement($rel->refresh(), $this->createUnit(), '2026-01-15');
        $full = app(StartFullSecondment::class)->handle($rel->refresh(), $dest, '2026-10-20');

        $this->scan('2026-11-10');
        $this->scan('2026-11-20');   // after the status expired

        $this->assertNull($full->refresh()->effective_to, 'the secondment is unaffected by the status follow-up lifecycle');
        $this->assertSame(0, DB::table('automation.movement_expiry_followups')->count(), 'and no S31 row is created by S38');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Scheduler and command
    // ---------------------------------------------------------------------------------------------------------

    public function test_the_scan_is_scheduled_daily_independently_of_the_s31_schedule(): void
    {
        $events = collect(app(Schedule::class)->events());
        $status = $events->filter(fn ($event) => str_contains((string) $event->command, 'masar:hr:scan-employment-status-expiry-followups'));
        $movement = $events->filter(fn ($event) => str_contains((string) $event->command, 'masar:hr:scan-movement-expiry-followups'));

        $this->assertCount(1, $status);
        $this->assertSame('15 1 * * *', $status->first()->expression);
        $this->assertTrue($status->first()->withoutOverlapping);
        $this->assertCount(1, $movement);
        $this->assertSame('0 1 * * *', $movement->first()->expression, 'S31 timing is unchanged');
    }

    public function test_the_console_command_uses_the_business_clock_and_is_safe_to_repeat(): void
    {
        [, $rel] = $this->withStatus();
        $this->clock->on('2026-11-10');

        $this->assertSame(0, Artisan::call('masar:hr:scan-employment-status-expiry-followups'));
        $this->assertStringContainsString('2026-11-10', Artisan::output());
        $this->assertSame(0, Artisan::call('masar:hr:scan-employment-status-expiry-followups'));
        $this->assertCount(1, $this->followUpsOf($rel));
    }

    public function test_the_scan_rejects_a_non_date_business_date_and_defaults_to_the_clock(): void
    {
        [, $rel] = $this->withStatus();
        try {
            $this->scan('yesterday');
            $this->fail('a non-date business date must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertCount(1, app(ScanEmploymentStatusExpiryFollowUps::class)->handle()->emitted, 'no explicit date: the BusinessDateClock (2026-11-10) decides');
        $this->assertCount(1, $this->followUpsOf($rel));
    }

    // ---------------------------------------------------------------------------------------------------------
    // J. API / security
    // ---------------------------------------------------------------------------------------------------------

    public function test_j43_to_j46_the_dedicated_permission_is_required_and_the_s31_permission_does_not_grant_it(): void
    {
        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertForbidden();

        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW])));
        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertForbidden();

        $this->principalWithPermissions([Perm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW]);
        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertForbidden();
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertOk();

        $this->principalWithPermissions([Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW]);
        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertOk();
        $this->getJson('/api/v1/hr/movement-expiry-followups')->assertForbidden('the S38 permission does not grant S31 either');
        $this->assertSame('hr.employment_status_expiry_followups.view', Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW);
        $this->assertContains(Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW, Perm::ALL);
    }

    public function test_j47_j48_the_response_derives_lapsed_and_exposes_the_reason_only_when_coherent(): void
    {
        [$person, $rel] = $this->withStatus();
        [$person2, $rel2] = $this->withStatus('suspended', '2026-10-01', '2026-11-15');
        $this->scan('2026-11-10');
        $this->end($person2, $rel2, '2026-11-12');
        $this->scan('2026-11-11');   // rel2's follow-up is suppressed
        $this->principalWithPermissions([Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW]);

        $this->clock->on('2026-11-14');
        $actionable = $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertOk();
        $this->assertSame([$rel->id], array_column($actionable->json('data'), 'employment_relationship_id'), 'only the ACTIONABLE row by default');
        $this->assertSame(['ACTIONABLE', null], [$actionable->json('data.0.state'), $actionable->json('data.0.suppression_reason')]);
        $this->assertSame(['id', 'followup_kind', 'employment_status_period_id', 'employment_relationship_id', 'expected_effective_to', 'due_date', 'status', 'state', 'suppression_reason', 'created_at', 'suppressed_at'],
            array_keys($actionable->json('data.0')), 'the S38 contract only: no person, status label or other HR field');

        $this->clock->on('2026-11-15');
        $this->assertSame([], $this->getJson('/api/v1/hr/employment-status-expiry-followups')->json('data'), 'at E the row is no longer ACTIONABLE');
        $lapsed = $this->getJson('/api/v1/hr/employment-status-expiry-followups?state=LAPSED')->assertOk();
        $this->assertSame([$rel->id, 'LAPSED', 'ACTIONABLE'], [$lapsed->json('data.0.employment_relationship_id'), $lapsed->json('data.0.state'), $lapsed->json('data.0.status')], 'LAPSED is derived; the stored status stays ACTIONABLE');
        $this->assertNull($lapsed->json('data.0.suppression_reason'));

        $suppressed = $this->getJson('/api/v1/hr/employment-status-expiry-followups?state=SUPPRESSED')->assertOk();
        $this->assertSame([$rel2->id, 'SUPPRESSED', 'RELATIONSHIP_ENDED'], [$suppressed->json('data.0.employment_relationship_id'), $suppressed->json('data.0.state'), $suppressed->json('data.0.suppression_reason')]);
        $this->assertNotNull($suppressed->json('data.0.suppressed_at'));

        $this->assertCount(2, $this->getJson('/api/v1/hr/employment-status-expiry-followups?state=ALL')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/hr/employment-status-expiry-followups?state=ALL&employment_relationship_id='.$rel->id)->json('data'));
        $this->getJson('/api/v1/hr/employment-status-expiry-followups?state=BOGUS')->assertUnprocessable();
    }

    public function test_j49_the_surface_is_read_only_and_plain_rbac(): void
    {
        $this->principalWithPermissions([Perm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW]);   // no organizational scope grant at all

        $this->getJson('/api/v1/hr/employment-status-expiry-followups')->assertOk();
        $this->postJson('/api/v1/hr/employment-status-expiry-followups', [])->assertStatus(405);
        $this->patchJson('/api/v1/hr/employment-status-expiry-followups', [])->assertStatus(405);
        $this->putJson('/api/v1/hr/employment-status-expiry-followups', [])->assertStatus(405);
        $this->deleteJson('/api/v1/hr/employment-status-expiry-followups')->assertStatus(405);
        $this->deleteJson('/api/v1/hr/employment-status-expiry-followups/'.Str::uuid7())->assertNotFound();

        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'employment-status-expiry-followups'));
        $this->assertSame([['GET', 'HEAD']], $routes->map(fn ($r) => $r->methods())->values()->all(), 'exactly one read route');
        $controller = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path('app/Modules/HumanResources/Presentation/Http/Controllers/EmploymentStatusExpiryFollowUpController.php')));
        $this->assertStringNotContainsString('ScopedAuthorizationChecker', $controller, 'no organizational scope is derived');
        $this->assertStringNotContainsString('organizational_unit', $controller);
    }

    // ---------------------------------------------------------------------------------------------------------
    // K. Database guards
    // ---------------------------------------------------------------------------------------------------------

    /** Inserts a raw follow-up row with overrides, for constraint probes. */
    private function rawInsert(array $overrides): void
    {
        $row = array_merge([
            'id' => (string) Str::uuid7(), 'followup_kind' => 'EXPIRY_WARNING_7D', 'status' => 'ACTIONABLE', 'suppression_reason' => null,
            'expected_effective_to' => '2026-11-15', 'due_date' => '2026-11-08', 'created_at' => now(), 'suppressed_at' => null,
        ], $overrides);

        DB::table('automation.employment_status_expiry_followups')->insert($row);
    }

    public function test_k50_to_k58_the_database_enforces_every_frozen_invariant(): void
    {
        [, $rel, $period] = $this->withStatus();
        [, $otherRel, $otherPeriod] = $this->withStatus();
        $own = ['employment_status_period_id' => $period->id, 'employment_relationship_id' => $rel->id];

        $cases = [
            'k50 kind' => [$own + ['followup_kind' => 'EXPIRY_WARNING_1D'], 'employment_status_expiry_followups_kind_check'],
            'k51 state' => [$own + ['status' => 'LAPSED'], 'employment_status_expiry_followups_status_check'],
            'k52 reason' => [$own + ['status' => 'SUPPRESSED', 'suppression_reason' => 'COVERED_BY_NEWER_MOVEMENT', 'suppressed_at' => now()], 'employment_status_expiry_followups_status_check'],
            'k53 actionable with reason' => [$own + ['suppression_reason' => 'RELATIONSHIP_ENDED'], 'employment_status_expiry_followups_status_check'],
            'k54 suppressed without reason' => [$own + ['status' => 'SUPPRESSED', 'suppressed_at' => now()], 'employment_status_expiry_followups_status_check'],
            'k55 suppressed without suppressed_at' => [$own + ['status' => 'SUPPRESSED', 'suppression_reason' => 'TRUNCATED_EARLIER'], 'employment_status_expiry_followups_status_check'],
            'k56 due date' => [$own + ['due_date' => '2026-11-09'], 'employment_status_expiry_followups_due_date_check'],
            'k58 invalid period FK' => [['employment_status_period_id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id], 'employment_status_expiry_followups_period'],
            'k58 invalid relationship FK' => [['employment_status_period_id' => $period->id, 'employment_relationship_id' => (string) Str::uuid7()], 'employment_status_expiry_followups_'],
            'ownership: period of another relationship' => [['employment_status_period_id' => $otherPeriod->id, 'employment_relationship_id' => $rel->id], 'employment_status_expiry_followups_period_owner_fk'],
        ];
        foreach ($cases as $label => [$overrides, $constraint]) {
            $error = $this->queryError(fn () => $this->rawInsert($overrides));
            $this->assertStringContainsString($constraint, $error->getMessage(), $label);
        }
        foreach ([['SUCCESSOR_RECORDED', '2026-11-16', '2026-11-09'], ['TRUNCATED_EARLIER', '2026-11-17', '2026-11-10'], ['RELATIONSHIP_ENDED', '2026-11-18', '2026-11-11']] as [$reason, $end, $due]) {
            DB::transaction(fn () => $this->rawInsert($own + ['status' => 'SUPPRESSED', 'suppression_reason' => $reason, 'suppressed_at' => now(), 'expected_effective_to' => $end, 'due_date' => $due]));
        }
        $this->assertSame(3, EmploymentStatusExpiryFollowUp::query()->count(), 'each frozen suppression reason is accepted when coherent');
    }

    public function test_k57_the_logical_key_is_unique_and_a_period_with_follow_ups_cannot_be_deleted(): void
    {
        [, $rel, $period] = $this->withStatus();
        $own = ['employment_status_period_id' => $period->id, 'employment_relationship_id' => $rel->id];
        $this->rawInsert($own);

        $error = $this->queryError(fn () => $this->rawInsert($own));
        $this->assertStringContainsString('employment_status_expiry_followups_logical_key', $error->getMessage());

        DB::transaction(fn () => $this->rawInsert($own + ['expected_effective_to' => '2026-11-16', 'due_date' => '2026-11-09']));   // a different end is a different logical follow-up

        $restrict = $this->queryError(fn () => DB::table('hr.employment_status_periods')->where('id', $period->id)->delete());
        $this->assertStringContainsString('employment_status_expiry_followups', $restrict->getMessage(), 'the owning period can never be deleted (RESTRICT)');
    }

    public function test_the_schema_carries_only_ids_dates_and_stable_codes_and_no_updated_at(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_schema', 'automation')->where('table_name', 'employment_status_expiry_followups')->pluck('column_name')->all();
        $this->assertEqualsCanonicalizing(['id', 'followup_kind', 'employment_status_period_id', 'employment_relationship_id', 'expected_effective_to', 'due_date', 'status', 'suppression_reason', 'created_at', 'suppressed_at'], $columns);
        $this->assertSame(0, (int) DB::selectOne("select count(*) c from pg_constraint where conrelid = 'automation.movement_expiry_followups'::regclass and conname like '%status_period%'")->c, 'S31 constraints are untouched');
        $this->assertSame(1, (int) DB::selectOne("select count(*) c from pg_constraint where conname = 'movement_expiry_followups_due_date_check'")->c);
    }

    public function test_emitting_the_same_logical_follow_up_twice_is_an_idempotent_no_op_with_one_audit_entry(): void
    {
        [, $rel, $period] = $this->withStatus();

        $first = $this->emitOne($period->id, $rel->id, '2026-11-15', '2026-11-10');
        $second = $this->emitOne($period->id, $rel->id, '2026-11-15', '2026-11-11');

        $this->assertSame(StatusExpiryFollowUpEmission::EMITTED, $first->outcome);
        $this->assertSame(StatusExpiryFollowUpEmission::ALREADY_EXISTS, $second->outcome);
        $this->assertCount(1, $this->followUpsOf($rel));
        $this->assertSame(1, $this->auditCount('hr.employment_status_expiry_followup.emit', [$rel->id]));
    }

    public function test_the_candidate_predicate_is_a_plain_range_the_partial_effective_to_index_can_serve(): void
    {
        $this->withStatus();
        $captured = null;
        DB::listen(function ($query) use (&$captured): void {
            if (str_contains($query->sql, 'sp.effective_to IS NOT NULL') && $captured === null) {
                $captured = [$query->sql, $query->bindings];
            }
        });

        $this->scan('2026-11-10');

        $this->assertNotNull($captured, 'discovery is ONE set-based statement');
        $this->assertStringNotContainsString('effective_to - ', $captured[0], 'no expression on the indexed column');
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, DB::select('EXPLAIN '.$captured[0], $captured[1])));
        $this->assertStringContainsString('employment_status_periods_effective_to_index', $plan, 'the range is served by the partial effective_to index');
    }

    public function test_discovery_is_independent_of_the_number_of_relationships_and_statements_per_scan_are_bounded_by_candidates(): void
    {
        foreach (range(1, 4) as $i) {
            $this->withStatus('unpaid_leave', '2026-10-01', '2026-12-30');   // far future: never a candidate
        }
        $this->withStatus();                                                  // one candidate
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->scan('2026-11-10');

        $discovery = array_filter($queries, fn ($sql) => str_contains($sql, 'sp.effective_to IS NOT NULL'));
        $this->assertCount(1, $discovery, 'one discovery statement regardless of history size (no N+1)');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Scope compliance
    // ---------------------------------------------------------------------------------------------------------

    public function test_s38_adds_no_delivery_channel_frontend_or_policy_leak(): void
    {
        foreach (['Notification', 'Mailable', 'Mail', 'Sms', 'Push'] as $word) {
            $this->assertSame([], glob(base_path("app/Modules/*/*/*{$word}*.php")), "no {$word} class");
            $this->assertSame([], glob(base_path("app/Modules/*/*/*/*{$word}*.php")), "no {$word} class");
        }
        $scanner = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path('app/Modules/HumanResources/Application/Commands/ScanEmploymentStatusExpiryFollowUps.php')));
        $this->assertStringNotContainsString('EmploymentStatusPeriod::create', $scanner);
        $this->assertStringNotContainsString('->update(', $scanner, 'the scanner never updates an HR timeline row');
        $this->assertStringNotContainsString('movement_expiry_followups', $scanner, 'S31 is not touched');
    }
}
