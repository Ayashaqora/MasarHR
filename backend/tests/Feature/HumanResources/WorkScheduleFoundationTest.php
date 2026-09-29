<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveActualWorkplaceForRelationshipAsOf;
use App\Modules\HumanResources\Application\Queries\ResolveWorkScheduleForRelationshipAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkSchedulePeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkScheduleWeekdaysException;
use App\Modules\HumanResources\Domain\WorkScheduleAsOf;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * S29 Work Schedule Foundation (docs/work-schedule-foundation-specification.md, ADR-S29-001…005):
 * the structural weekday identities, the relationship-owned temporal schedule stream (record,
 * change as temporal closure, same-set re-record as a new event, conservative backdating), the
 * as-of reader (RESOLVED / NOT_RECORDED — never a default week), PostgreSQL integrity,
 * relationship-end / status-triggered coherence, reappointment isolation, UNKNOWN_LEGACY handling,
 * independence from the other employment/movement streams and from S27 reporting, plain hr.* RBAC,
 * audit, and S29 scope guards. Cross-session concurrency lives in ConcurrencyTest. Derived from the
 * S26 foundation test; every fixture is synthetic.
 */
class WorkScheduleFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A. Weekday identities (ADR-S29-002)
    // ---------------------------------------------------------------------

    public function test_the_seven_weekday_identities_are_seeded_with_stable_codes_iso_numbers_and_arabic_labels(): void
    {
        $rows = DB::table('ref.weekdays')->orderBy('iso_day_number')->get(['code', 'iso_day_number', 'name_ar', 'name_en']);

        $this->assertSame(Weekday::CODES, $rows->pluck('code')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $rows->pluck('iso_day_number')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'], $rows->pluck('name_ar')->all());
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], $rows->pluck('name_en')->all());
    }

    public function test_weekday_identities_are_structural_constrained_and_have_no_working_week_flag(): void
    {
        $columns = DB::table('information_schema.columns')->where('table_schema', 'ref')->where('table_name', 'weekdays')
            ->pluck('column_name')->sort()->values()->all();
        $this->assertSame(['code', 'created_at', 'id', 'iso_day_number', 'name_ar', 'name_en'], $columns, 'no is_active, is_working_day, is_weekend, sort-as-default or version column');

        foreach ([
            ['code' => 'FUNDAY', 'iso_day_number' => 7],
            ['code' => 'MONDAY', 'iso_day_number' => 1],
            ['code' => 'SUNDAY', 'iso_day_number' => 8],
        ] as $row) {
            $error = $this->queryError(fn () => DB::table('ref.weekdays')->insert([
                'id' => (string) Str::uuid7(), 'code' => $row['code'], 'iso_day_number' => $row['iso_day_number'],
                'name_ar' => 'س', 'name_en' => 'X', 'created_at' => now(),
            ]));
            $this->assertTrue(Errors::isCheckViolation($error) || Errors::isUniqueViolation($error), "{$row['code']}/{$row['iso_day_number']} must be rejected");
        }
        $this->assertSame(7, DB::table('ref.weekdays')->count());
    }

    public function test_a_referenced_weekday_cannot_be_deleted_and_no_weekday_administration_route_exists(): void
    {
        $relationship = $this->relationship();
        $this->record($relationship, '2026-02-01', ['MONDAY']);

        $error = $this->queryError(fn () => DB::table('ref.weekdays')->where('code', 'MONDAY')->delete());
        $this->assertTrue(Errors::isForeignKeyViolation($error), 'a referenced weekday identity is protected by RESTRICT');

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('weekday', $route->uri(), 'weekdays are structural — no administration surface');
        }
    }

    // ---------------------------------------------------------------------
    // B. Recording and reading
    // ---------------------------------------------------------------------

    public function test_both_contract_and_permanent_relationships_can_have_a_schedule(): void
    {
        foreach (['contract', 'permanent'] as $typeCode) {
            [, $relationship] = $this->personAndRelationship($typeCode);

            $period = $this->record($relationship, '2026-02-01', ['SUNDAY', 'MONDAY']);

            $this->assertSame(['MONDAY', 'SUNDAY'], $period->weekdayCodes(), "{$typeCode} relationship, ISO order");
            $this->assertNull($period->effective_to);
        }
    }

    public function test_every_one_of_the_seven_weekdays_can_be_selected_including_all_of_them(): void
    {
        foreach (Weekday::CODES as $code) {
            $relationship = $this->relationship();
            $this->assertSame([$code], $this->record($relationship, '2026-02-01', [$code])->weekdayCodes());
        }

        $relationship = $this->relationship();
        $this->assertSame(Weekday::CODES, $this->record($relationship, '2026-02-01', array_reverse(Weekday::CODES))->weekdayCodes());
    }

    public function test_no_schedule_means_not_recorded_never_a_default_week(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->assertSame(0, $this->periodCount($relationship), 'no schedule is fabricated with the relationship');
        $asOf = $this->asOf($relationship, '2026-03-02');
        $this->assertSame(WorkScheduleAsOf::NOT_RECORDED, $asOf->state());
        $this->assertFalse($asOf->isRecorded());
        $this->assertSame([], $asOf->weekdayCodes());
        $this->assertNull($asOf->period());
        foreach (Weekday::CODES as $code) {
            $this->assertFalse($asOf->isScheduledOn($code), "{$code} is never assumed to be a working day");
        }
        $this->getJson($this->url($person, $relationship))->assertOk()->assertJsonCount(0);
    }

    public function test_store_returns_201_and_index_lists_history_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-01-01', 'weekdays' => ['THURSDAY', 'SUNDAY', 'MONDAY']])
            ->assertStatus(201)
            ->assertJsonPath('employment_relationship_id', $relationship->id)
            ->assertJsonPath('effective_from', '2026-01-01')
            ->assertJsonPath('effective_to', null)
            ->assertJsonPath('weekdays', ['MONDAY', 'THURSDAY', 'SUNDAY']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-07-01', 'weekdays' => ['SATURDAY']])
            ->assertStatus(201);

        $response = $this->getJson($this->url($person, $relationship))->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertSame(['SATURDAY'], $response->json('0.weekdays'));
        $this->assertSame(['MONDAY', 'THURSDAY', 'SUNDAY'], $response->json('1.weekdays'));
        $this->assertSame('2026-07-01', $response->json('1.effective_to'));
        $this->assertSame(['id', 'employment_relationship_id', 'effective_from', 'effective_to', 'weekdays'], array_keys($response->json('0')));
    }

    public function test_as_of_resolution_uses_history_with_half_open_boundaries(): void
    {
        $relationship = $this->relationship();
        $this->record($relationship, '2026-02-01', ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY']);
        $this->record($relationship, '2026-07-01', ['SATURDAY', 'SUNDAY']);

        $this->assertSame(WorkScheduleAsOf::NOT_RECORDED, $this->asOf($relationship, '2026-01-31')->state(), 'before any schedule — never a guess');
        $asOf = $this->asOf($relationship, '2026-06-30');
        $this->assertSame(WorkScheduleAsOf::RESOLVED, $asOf->state());
        $this->assertSame(['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'SUNDAY'], $asOf->weekdayCodes(), 'a past date resolves the schedule in force then');
        $this->assertTrue($asOf->isScheduledOn('MONDAY'));
        $this->assertFalse($asOf->isScheduledOn('SATURDAY'));
        $this->assertSame(['SATURDAY', 'SUNDAY'], $this->asOf($relationship, '2026-07-01')->weekdayCodes(), 'the boundary belongs to the new schedule');
        $this->assertSame(['SATURDAY', 'SUNDAY'], $this->asOf($relationship, Carbon::parse('2030-01-01'))->weekdayCodes());
    }

    // ---------------------------------------------------------------------
    // C. Weekday selection validation
    // ---------------------------------------------------------------------

    public function test_an_empty_unknown_localized_lowercase_or_duplicate_selection_is_rejected(): void
    {
        $relationship = $this->relationship();

        foreach ([[], ['FUNDAY'], ['الاثنين'], ['monday'], ['1'], ['MONDAY', 'MONDAY'], ['MONDAY', 'TUESDAY', 'MONDAY']] as $codes) {
            try {
                $this->record($relationship, '2026-02-01', $codes);
                $this->fail(json_encode($codes, JSON_UNESCAPED_UNICODE).' must be rejected');
            } catch (InvalidWorkScheduleWeekdaysException) {
            }
        }

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame(0, DB::table('hr.work_schedule_period_weekdays')->count());
    }

    public function test_invalid_payloads_are_rejected_with_422_and_no_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), [])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from', 'weekdays']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => 'nope', 'weekdays' => 'MONDAY'])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from', 'weekdays']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-02-01', 'weekdays' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-02-01', 'weekdays' => ['MONDAY', 'MONDAY']])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-02-01', 'weekdays' => ['Monday']])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-02-01', 'weekdays' => [1, 2]])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays.0']);

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame($auditBefore, $this->auditEntriesCount());
    }

    public function test_the_api_ignores_client_supplied_hours_shifts_allocation_or_workplace_fields(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $response = $this->postJson($this->url($person, $relationship), [
            'effective_from' => '2026-02-01',
            'weekdays' => ['MONDAY'],
            'start_time' => '08:00', 'hours_per_day' => 8, 'shift' => 'A',
            'allocation_percentage' => 50, 'organizational_unit_id' => (string) Str::uuid7(),
            'start_knowledge_state' => 'UNKNOWN_LEGACY',
        ])->assertStatus(201);

        $this->assertSame(['id', 'employment_relationship_id', 'effective_from', 'effective_to', 'weekdays'], array_keys($response->json()));
    }

    // ---------------------------------------------------------------------
    // D. Temporal behaviour (ADR-S29-004)
    // ---------------------------------------------------------------------

    public function test_a_schedule_may_start_on_the_relationship_start_but_never_before(): void
    {
        $relationship = $this->relationship('2026-01-01');

        try {
            $this->record($relationship, '2025-12-31', ['MONDAY']);
            $this->fail('a schedule may never predate its relationship');
        } catch (InvalidWorkSchedulePeriodDateException) {
        }

        $this->record($relationship, '2026-01-01', ['MONDAY']);
        $this->assertTrue($this->asOf($relationship, '2026-01-01')->isRecorded());
    }

    public function test_a_later_schedule_temporally_closes_the_previous_period_and_preserves_its_weekdays(): void
    {
        $relationship = $this->relationship();
        $first = $this->record($relationship, '2026-01-01', ['MONDAY', 'TUESDAY']);

        $second = $this->record($relationship, '2026-05-01', ['WEDNESDAY']);

        $first->refresh()->load('weekdays');
        $this->assertSame('2026-01-01', $first->effective_from->toDateString());
        $this->assertSame('2026-05-01', $first->effective_to->toDateString(), 'closed at the change date — adjacent, no overlap');
        $this->assertSame(['MONDAY', 'TUESDAY'], $first->weekdayCodes(), 'historical membership is never rewritten');
        $this->assertSame('2026-05-01', $second->effective_from->toDateString());
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_the_same_weekday_set_at_a_later_date_creates_a_new_adjacent_period(): void
    {
        $relationship = $this->relationship();
        $first = $this->record($relationship, '2026-01-01', ['MONDAY', 'TUESDAY']);

        $second = $this->record($relationship, '2026-05-01', ['TUESDAY', 'MONDAY']);

        $this->assertNotSame($first->id, $second->id, 'the effective event is itself history — never a silent no-op');
        $this->assertSame('2026-05-01', $first->refresh()->effective_to->toDateString());
        $this->assertSame(['MONDAY', 'TUESDAY'], $second->weekdayCodes());
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_a_future_schedule_is_allowed_and_does_not_become_current_early(): void
    {
        $relationship = $this->relationship();
        $today = Carbon::today();
        $futureFrom = $today->copy()->addMonths(4)->toDateString();

        $this->record($relationship, '2026-01-01', ['MONDAY']);
        $this->record($relationship, $futureFrom, ['FRIDAY']);

        $this->assertSame(['MONDAY'], $this->asOf($relationship, $today)->weekdayCodes());
        $this->assertSame(['FRIDAY'], $this->asOf($relationship, $futureFrom)->weekdayCodes());
    }

    public function test_backdated_or_same_date_starts_are_rejected_and_history_is_untouched(): void
    {
        $relationship = $this->relationship();
        $this->record($relationship, '2026-02-01', ['MONDAY']);
        $this->record($relationship, '2026-06-01', ['TUESDAY']);
        $before = $this->snapshot($relationship);

        foreach (['2026-04-01', '2026-06-01', '2026-02-01', '2026-01-15'] as $from) {
            try {
                $this->record($relationship, $from, ['WEDNESDAY']);
                $this->fail("{$from} conflicts with later recorded history");
            } catch (InvalidWorkSchedulePeriodDateException) {
            }
        }

        $this->assertSame($before, $this->snapshot($relationship), 'no multi-period rewrite, no partial closure');
    }

    public function test_a_start_before_a_closed_imported_period_is_rejected_and_a_gap_is_preserved(): void
    {
        $relationship = $this->relationship();
        $this->insertRaw($relationship, '2026-01-01', '2026-03-01', ['MONDAY']);

        try {
            $this->record($relationship, '2026-02-01', ['TUESDAY']);
            $this->fail('a start inside a closed later-recorded period is rejected');
        } catch (InvalidWorkSchedulePeriodDateException) {
        }

        $this->record($relationship, '2026-06-01', ['TUESDAY']);

        $this->assertSame('2026-03-01', (string) DB::table('hr.work_schedule_periods')
            ->where('employment_relationship_id', $relationship->id)->where('effective_from', '2026-01-01')->value('effective_to'), 'the earlier end is not stretched to close the gap');
        $this->assertSame(WorkScheduleAsOf::NOT_RECORDED, $this->asOf($relationship, '2026-04-15')->state(), 'the gap stays NOT_RECORDED');
    }

    // ---------------------------------------------------------------------
    // E. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_direct_overlap_is_rejected_while_adjacent_periods_are_accepted(): void
    {
        $relationship = $this->relationship();
        $this->insertRaw($relationship, '2026-01-01', '2026-06-01', ['MONDAY']);

        $error = $this->queryError(fn () => $this->insertRaw($relationship, '2026-05-01', null, ['MONDAY']));
        $this->assertTrue(Errors::isExclusionViolation($error));

        $this->insertRaw($relationship, '2026-06-01', null, ['MONDAY']);
        $this->assertSame(2, $this->periodCount($relationship));
    }

    public function test_invalid_intervals_are_rejected_by_the_check_constraint(): void
    {
        $relationship = $this->relationship();

        foreach ([['2026-06-01', '2026-06-01'], ['2026-06-01', '2026-05-01']] as [$from, $to]) {
            $error = $this->queryError(fn () => $this->insertRaw($relationship, $from, $to, []));
            $this->assertTrue(Errors::isCheckViolation($error), "[{$from}, {$to}) must be rejected");
        }
    }

    public function test_duplicate_membership_and_dangling_references_are_rejected_by_the_database(): void
    {
        $relationship = $this->relationship();
        $period = $this->record($relationship, '2026-02-01', ['MONDAY']);

        $error = $this->queryError(fn () => DB::table('hr.work_schedule_period_weekdays')->insert([
            'work_schedule_period_id' => $period->id, 'weekday_id' => $this->weekdayId('MONDAY'),
        ]));
        $this->assertTrue(Errors::isUniqueViolation($error), 'the composite primary key forbids a duplicate weekday');

        $error = $this->queryError(fn () => DB::table('hr.work_schedule_period_weekdays')->insert([
            'work_schedule_period_id' => $period->id, 'weekday_id' => (string) Str::uuid7(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.work_schedule_period_weekdays')->insert([
            'work_schedule_period_id' => (string) Str::uuid7(), 'weekday_id' => $this->weekdayId('MONDAY'),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.work_schedule_periods')->insert([
            'id' => (string) Str::uuid7(), 'employment_relationship_id' => (string) Str::uuid7(),
            'effective_from' => '2026-02-01', 'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.work_schedule_periods')->where('id', $period->id)->delete());
        $this->assertTrue(Errors::isForeignKeyViolation($error), 'a period with membership cannot be hard-deleted');
    }

    public function test_table_shapes_have_no_hours_shift_attendance_allocation_or_workplace_column(): void
    {
        $types = fn (string $table) => collect(DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', $table)
            ->pluck('data_type', 'column_name')->all())->sortKeys()->all();

        $this->assertSame([
            'created_at' => 'timestamp with time zone',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'employment_relationship_id' => 'uuid',
            'id' => 'uuid',
        ], $types('work_schedule_periods'), 'no person_id, hours, shift, attendance, allocation, unit, bitmask, version or updated_at column');
        $this->assertSame(['weekday_id' => 'uuid', 'work_schedule_period_id' => 'uuid'], $types('work_schedule_period_weekdays'));

        foreach (['persons', 'employment_relationships'] as $table) {
            foreach (DB::table('information_schema.columns')->where('table_schema', 'hr')->where('table_name', $table)->pluck('column_name') as $column) {
                $this->assertStringNotContainsString('schedule', $column, "hr.{$table} carries no schedule column — the schedule is derived from history");
                $this->assertStringNotContainsString('weekday', $column);
            }
        }
    }

    public function test_different_relationships_are_independent(): void
    {
        $r1 = $this->relationship();
        $r2 = $this->relationship();

        $this->record($r1, '2026-01-01', ['MONDAY']);
        $this->record($r2, '2026-01-01', ['MONDAY']);
        $this->record($r1, '2026-03-01', ['FRIDAY']);

        $this->assertSame(['MONDAY'], $this->asOf($r2, '2026-06-01')->weekdayCodes(), 'the exclusion constraint is per relationship only');
        $this->assertSame(['FRIDAY'], $this->asOf($r1, '2026-06-01')->weekdayCodes());
    }

    // ---------------------------------------------------------------------
    // F. Employment lifecycle
    // ---------------------------------------------------------------------

    public function test_relationship_end_closes_the_open_schedule_and_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $earlier = $this->record($relationship, '2026-01-01', ['MONDAY']);
        $open = $this->record($relationship, '2026-04-01', ['TUESDAY']);

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
            'expected_version' => $relationship->version, 'effective_to' => '2026-09-01', 'is_terminal' => false,
        ])->assertOk();

        $this->assertSame('2026-09-01', $open->refresh()->effective_to->toDateString());
        $this->assertSame('2026-04-01', $earlier->refresh()->effective_to->toDateString());
        $this->assertSame(['TUESDAY'], $open->load('weekdays')->weekdayCodes(), 'membership untouched by the closure');
        $this->assertSame(WorkScheduleAsOf::NOT_RECORDED, $this->asOf($relationship, '2026-09-01')->state(), 'no schedule after the relationship ended');
        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertTrue($entry->metadata['work_schedule_period_closed_as_consequence'] ?? false);
    }

    public function test_a_schedule_that_already_ended_is_not_extended_by_the_relationship_end(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, '2026-02-01', '2026-04-01', ['MONDAY']);

        $this->end($person, $relationship, '2026-09-01');

        $this->assertSame('2026-04-01', (string) DB::table('hr.work_schedule_periods')->where('employment_relationship_id', $relationship->id)->value('effective_to'));
    }

    public function test_a_relationship_end_before_a_future_schedule_is_rejected_atomically(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->record($relationship, '2026-02-01', ['MONDAY']);
        $this->record($relationship, '2026-10-01', ['TUESDAY']);
        $before = $this->snapshot($relationship);

        foreach (['2026-09-01', '2026-10-01'] as $endDate) {
            $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
                'expected_version' => $relationship->version, 'effective_to' => $endDate, 'is_terminal' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        }

        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
    }

    public function test_a_closed_imported_schedule_beyond_the_end_date_rejects_the_end(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, '2026-02-01', '2026-11-01', ['MONDAY']);

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-09-01', false);
    }

    public function test_an_ended_relationship_rejects_a_new_schedule(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->end($person, $relationship, '2026-06-01');

        $this->postJson($this->url($person, $relationship->refresh()), ['effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']])
            ->assertStatus(409);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->record($relationship, '2026-03-01', ['MONDAY']);
    }

    public function test_status_triggered_termination_closes_the_schedule_and_the_triggering_audit_exposes_it(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, '2026-02-01', ['MONDAY']);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'resigned', 'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());
        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertTrue($entry->metadata['work_schedule_period_closed_as_consequence'] ?? false);
        $this->assertSame($auditBefore + 1, $this->auditEntriesCount(), 'one entry, no duplicate consequence event');
    }

    public function test_terminal_status_ending_closes_the_schedule(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, '2026-02-01', ['MONDAY']);

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('deceased'), '2026-10-15');

        $this->assertTrue($person->refresh()->is_terminal);
        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());
    }

    public function test_an_unknown_legacy_end_state_is_never_fabricated_into_a_schedule_end(): void
    {
        [, $relationship] = $this->personAndRelationship();
        DB::table('hr.employment_relationships')->where('id', $relationship->id)->update(['end_knowledge_state' => 'UNKNOWN_LEGACY']);
        $relationship->refresh();

        $this->assertSame(WorkScheduleAsOf::NOT_RECORDED, $this->asOf($relationship, '2026-03-01')->state(), 'no schedule is inferred for legacy data');

        $period = $this->record($relationship, '2026-02-01', ['MONDAY']);

        $this->assertNull($period->effective_to, 'an UNKNOWN_LEGACY end is not a known end — the schedule stays open, never closed at a guessed date');
        $this->assertSame('UNKNOWN_LEGACY', $relationship->refresh()->end_knowledge_state);
    }

    // ---------------------------------------------------------------------
    // G. Reappointment (ADR-S29-001)
    // ---------------------------------------------------------------------

    public function test_reappointment_does_not_inherit_the_old_schedule_and_can_receive_its_own(): void
    {
        [$person, $old] = $this->personAndRelationship();
        $oldPeriod = $this->record($old, '2026-01-01', ['MONDAY', 'TUESDAY']);
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(0, $this->periodCount($new), 'no carry-forward because the Person is the same');
        $this->assertSame(WorkScheduleAsOf::NOT_RECORDED, $this->asOf($new, '2026-08-01')->state());
        $this->assertSame($old->id, $oldPeriod->refresh()->employment_relationship_id);
        $this->assertSame('2026-06-01', $oldPeriod->effective_to->toDateString());

        $this->record($new, '2026-08-01', ['SUNDAY']);
        $this->assertSame(1, $this->periodCount($new));
        $this->assertSame(1, $this->periodCount($old));
        $this->assertSame(['MONDAY', 'TUESDAY'], $this->asOf($old, '2026-03-01')->weekdayCodes(), 'the old history stays with the old relationship');
    }

    // ---------------------------------------------------------------------
    // H. Separation — other streams, movements and S27 reporting are unchanged
    // ---------------------------------------------------------------------

    public function test_recording_a_schedule_changes_no_other_stream_movement_or_reporting_population(): void
    {
        $unit = $this->createUnit();
        $destination = $this->createUnit();
        [, $relationship] = $this->personAndRelationship();
        $this->recordPlacement($relationship, $unit, '2026-01-15');
        app(StartFullSecondment::class)->handle($relationship, $destination, '2026-03-01');

        $streams = $this->otherStreams($relationship);
        $population = app(ListReportingPopulationAsOf::class)('2026-04-01', [$relationship->id]);
        $workplace = app(ResolveActualWorkplaceForRelationshipAsOf::class)($relationship, '2026-04-01');

        $this->record($relationship, '2026-02-01', ['MONDAY', 'WEDNESDAY']);
        $this->record($relationship, '2026-04-01', ['TUESDAY']);

        $this->assertSame($streams, $this->otherStreams($relationship), 'no category/contract/title/specialty/status/placement/movement row is touched');
        $this->assertEquals($population, app(ListReportingPopulationAsOf::class)('2026-04-01', [$relationship->id]), 'the S27 population is unchanged by a schedule');
        $this->assertEquals($workplace, app(ResolveActualWorkplaceForRelationshipAsOf::class)($relationship, '2026-04-01'), 'the actual workplace is not weekday-aware in S29');
    }

    public function test_s29_introduces_no_attendance_shift_hours_partial_secondment_or_default_concept(): void
    {
        foreach (['attendance', 'shift', 'working_hour', 'partial_secondment', 'allocation', 'holiday', 'calendar', 'overtime', 'roster'] as $forbidden) {
            $this->assertSame(0, DB::table('information_schema.tables')->whereNotIn('table_schema', ['pg_catalog', 'information_schema'])
                ->where('table_name', 'like', "%{$forbidden}%")->count(), "no {$forbidden} table");
        }
        $scheduleTables = DB::table('information_schema.tables')->where('table_name', 'like', '%schedule%')
            ->whereNotIn('table_schema', ['pg_catalog', 'information_schema'])
            ->get(['table_schema', 'table_name'])->map(fn ($t) => "{$t->table_schema}.{$t->table_name}")->sort()->values()->all();
        $this->assertSame(['hr.work_schedule_period_weekdays', 'hr.work_schedule_periods'], $scheduleTables, 'no organizational or default schedule table');

        foreach (['Attendance', 'Shift', 'PartialSecondment', 'Allocation', 'DefaultWorkSchedule', 'Holiday'] as $forbidden) {
            $this->assertSame([], glob(base_path("app/Modules/*/*/*{$forbidden}*.php")), "no {$forbidden} class");
            $this->assertSame([], glob(base_path("app/Modules/*/*/*/*{$forbidden}*.php")), "no {$forbidden} class");
        }
        $this->assertSame(
            ['RecordWorkSchedulePeriod.php'],
            array_map('basename', glob(base_path('app/Modules/*/Application/Commands/*WorkSchedule*.php'))),
            'exactly one explicit S29 command — no generic update/end/delete command',
        );
    }

    // ---------------------------------------------------------------------
    // I. Security
    // ---------------------------------------------------------------------

    public function test_authentication_and_hr_permissions_are_enforced(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $payload = ['effective_from' => '2026-02-01', 'weekdays' => ['MONDAY']];

        $this->getJson($this->url($person, $relationship))->assertUnauthorized();
        $this->postJson($this->url($person, $relationship), $payload)->assertUnauthorized();

        $this->actingAs($this->createPrincipal(), 'web');
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->postJson($this->url($person, $relationship), $payload)->assertForbidden();

        $this->principalWithPermissions([Perm::WORK_SCHEDULE_PERIODS_VIEW]);
        $this->getJson($this->url($person, $relationship))->assertOk();
        $this->postJson($this->url($person, $relationship), $payload)->assertForbidden();

        $this->principalWithPermissions([Perm::WORK_SCHEDULE_PERIODS_RECORD]);
        $this->postJson($this->url($person, $relationship), $payload)->assertStatus(201);
        $this->getJson($this->url($person, $relationship))->assertForbidden();
    }

    public function test_reference_and_other_hr_permissions_never_grant_schedule_access(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $payload = ['effective_from' => '2026-02-01', 'weekdays' => ['MONDAY']];

        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);
        $this->postJson($this->url($person, $relationship), $payload)->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();

        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [
            Perm::WORK_SCHEDULE_PERIODS_VIEW, Perm::WORK_SCHEDULE_PERIODS_RECORD,
        ])));
        $this->postJson($this->url($person, $relationship), $payload)->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame(['hr.work_schedule_periods.view', 'hr.work_schedule_periods.record'], [Perm::WORK_SCHEDULE_PERIODS_VIEW, Perm::WORK_SCHEDULE_PERIODS_RECORD]);
    }

    public function test_ownership_mismatch_is_404_and_no_patch_put_or_delete_route_exists(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $stranger = $this->createPersonRecord();
        $period = $this->record($relationship, '2026-02-01', ['MONDAY']);

        $this->getJson($this->url($stranger, $relationship))->assertNotFound();
        $this->postJson($this->url($stranger, $relationship), ['effective_from' => '2026-03-01', 'weekdays' => ['MONDAY']])->assertNotFound();
        $this->patchJson($this->url($person, $relationship), [])->assertStatus(405);
        $this->putJson($this->url($person, $relationship), [])->assertStatus(405);
        $this->deleteJson($this->url($person, $relationship))->assertStatus(405);
        $this->patchJson($this->url($person, $relationship)."/{$period->id}", [])->assertNotFound();
        $this->deleteJson($this->url($person, $relationship)."/{$period->id}")->assertNotFound();
        $this->assertSame(1, $this->periodCount($relationship));
    }

    // ---------------------------------------------------------------------
    // J. Audit
    // ---------------------------------------------------------------------

    public function test_record_and_change_audits_carry_weekday_codes_and_superseded_period_without_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $first = $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-01-01', 'weekdays' => ['SUNDAY', 'MONDAY']])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.work_schedule_period.record');
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_work_schedule_period', $entry->target_type);
        $this->assertSame($first->json('id'), $entry->target_id);
        $this->assertEquals([
            'employment_relationship_id' => $relationship->id,
            'effective_from' => '2026-01-01',
            'weekdays' => ['MONDAY', 'SUNDAY'],
        ], $entry->changes);
        $this->assertEquals([], $entry->metadata ?? [], 'nothing superseded on the first schedule');

        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-05-01', 'weekdays' => ['SUNDAY', 'MONDAY']])->assertStatus(201);
        $entry = $this->latestAuditEntryFor('hr.work_schedule_period.record');
        $this->assertSame(['MONDAY', 'SUNDAY'], $entry->changes['weekdays']);
        $this->assertSame($first->json('id'), $entry->metadata['previous_period_id']);
        $this->assertSame('2026-05-01', $entry->metadata['previous_period_closed_at']);
        $encoded = json_encode([$entry->changes, $entry->metadata], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($person->national_id, $encoded);
        $this->assertStringNotContainsString('الاثنين', $encoded, 'stable codes only, never localized labels');
    }

    public function test_a_rejected_write_leaves_no_audit_and_no_partial_closure(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $existing = $this->record($relationship, '2026-05-01', ['MONDAY']);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-03-01', 'weekdays' => ['TUESDAY']])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from']);
        $this->postJson($this->url($person, $relationship), ['effective_from' => '2026-07-01', 'weekdays' => ['NOPE']])
            ->assertStatus(422)->assertJsonValidationErrors(['weekdays']);

        $this->assertSame($auditBefore, $this->auditEntriesCount());
        $this->assertNull($existing->refresh()->effective_to, 'weekday validation runs before the previous period is closed');
        $this->assertSame(1, $this->periodCount($relationship));
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

    /** @param list<string> $weekdays */
    private function record(EmploymentRelationship $relationship, string $from, array $weekdays): WorkSchedulePeriod
    {
        return app(RecordWorkSchedulePeriod::class)->handle($relationship, $from, $weekdays);
    }

    private function asOf(EmploymentRelationship $relationship, string|Carbon $date): WorkScheduleAsOf
    {
        return app(ResolveWorkScheduleForRelationshipAsOf::class)($relationship, $date);
    }

    private function end(Person $person, EmploymentRelationship $relationship, string $effectiveTo): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $relationship->refresh(), $relationship->version, $effectiveTo, false);
    }

    private function url(Person $person, EmploymentRelationship $relationship): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/work-schedule-periods";
    }

    private function periodCount(EmploymentRelationship $relationship): int
    {
        return WorkSchedulePeriod::query()->where('employment_relationship_id', $relationship->id)->count();
    }

    private function weekdayId(string $code): string
    {
        return DB::table('ref.weekdays')->where('code', $code)->value('id');
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(EmploymentRelationship $relationship): array
    {
        return DB::table('hr.work_schedule_periods')
            ->where('employment_relationship_id', $relationship->id)
            ->orderBy('effective_from')
            ->get(['id', 'effective_from', 'effective_to'])
            ->map(fn ($row) => (array) $row + [
                'weekdays' => DB::table('hr.work_schedule_period_weekdays')->where('work_schedule_period_id', $row->id)
                    ->orderBy('weekday_id')->pluck('weekday_id')->all(),
            ])
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function otherStreams(EmploymentRelationship $relationship): array
    {
        $streams = [];
        foreach (['employment_category_periods', 'employment_contract_periods', 'employment_job_title_periods', 'employment_specialty_periods', 'organizational_placement_periods', 'employment_status_periods', 'workplace_assignment_periods', 'full_secondment_periods'] as $table) {
            $streams[$table] = DB::table("hr.{$table}")->where('employment_relationship_id', $relationship->id)
                ->orderBy('effective_from')->get()->map(fn ($row) => (array) $row)->all();
        }
        $streams['employment_relationships'] = [(array) DB::table('hr.employment_relationships')->where('id', $relationship->id)->first()];

        return $streams;
    }

    /** @param list<string> $weekdays */
    private function insertRaw(EmploymentRelationship $relationship, string $from, ?string $to, array $weekdays): void
    {
        $id = (string) Str::uuid7();
        DB::table('hr.work_schedule_periods')->insert([
            'id' => $id,
            'employment_relationship_id' => $relationship->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'created_at' => now(),
        ]);
        foreach ($weekdays as $code) {
            DB::table('hr.work_schedule_period_weekdays')->insert(['work_schedule_period_id' => $id, 'weekday_id' => $this->weekdayId($code)]);
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
