<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentCategoryPeriod;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Queries\ResolveEmploymentCategoryForRelationshipAsOf;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentCategoryException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentCategoryPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as Perm;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentCategoryPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Reference\Application\Commands\DeactivateEmploymentCategory;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S20 Employment Category History Foundation
 * (docs/employment-category-history-foundation-specification.md, ADR-S20-001): domain behaviour,
 * temporal invariants, PostgreSQL integrity, the active-at-command-time reference rule, the
 * relationship-end and reappointment decisions, plain-RBAC authorization (hr.* only — reference.*
 * never grants assignment), and audit. Cross-session concurrency lives in ConcurrencyTest (it
 * needs real top-level transactions, which this DatabaseTransactions-based class cannot provide).
 * Mirrors WorkplaceAssignmentFoundationTest's (S16) structural conventions throughout.
 */
class EmploymentCategoryHistoryFoundationTest extends HumanResourcesTestCase
{
    // ---------------------------------------------------------------------
    // A. Basic
    // ---------------------------------------------------------------------

    public function test_recording_the_first_category_creates_one_open_period(): void
    {
        $relationship = $this->relationship();
        $grade = $this->employmentCategory('grade_3');

        $period = $this->record($relationship, $grade, '2026-01-15');

        $this->assertSame($relationship->id, $period->employment_relationship_id);
        $this->assertSame($grade->id, $period->employment_category_id);
        $this->assertSame('2026-01-15', $period->effective_from->toDateString());
        $this->assertNull($period->effective_to);
        $this->assertTrue($period->isCurrent());
        $this->assertSame(1, $this->periodCount($relationship));
    }

    public function test_store_returns_201_and_the_created_period(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $grade = $this->employmentCategory('grade_1');

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $grade->id,
            'effective_from' => '2026-02-01',
        ])->assertStatus(201)
            ->assertJsonPath('employment_relationship_id', $relationship->id)
            ->assertJsonPath('employment_category_id', $grade->id)
            ->assertJsonPath('effective_from', '2026-02-01')
            ->assertJsonPath('effective_to', null);
    }

    public function test_index_returns_the_full_history_most_recent_first(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $this->record($relationship, $this->employmentCategory('grade_4'), '2026-05-01');
        $this->record($relationship, $this->employmentCategory('grade_3'), '2026-09-01');

        $response = $this->getJson($this->url($person, $relationship))->assertOk();

        $this->assertCount(3, $response->json());
        $this->assertSame('2026-09-01', $response->json('0.effective_from'));
        $this->assertNull($response->json('0.effective_to'));
        $this->assertSame('2026-05-01', $response->json('1.effective_from'));
        $this->assertSame('2026-09-01', $response->json('1.effective_to'));
        $this->assertSame('2026-02-01', $response->json('2.effective_from'));
        $this->assertSame('2026-05-01', $response->json('2.effective_to'));
    }

    public function test_as_of_resolution_returns_the_category_effective_on_each_date_and_null_before_any_period(): void
    {
        $relationship = $this->relationship();
        $g5 = $this->employmentCategory('grade_5');
        $g4 = $this->employmentCategory('grade_4');
        $this->record($relationship, $g5, '2026-02-01');
        $this->record($relationship, $g4, '2026-05-01');

        $this->assertNull($this->asOf($relationship, '2026-01-31'), 'UNRESOLVED before any period — never a guessed category');
        $this->assertSame($g5->id, $this->asOf($relationship, '2026-02-01')?->id);
        $this->assertSame($g5->id, $this->asOf($relationship, '2026-04-30')?->id, 'a historical date resolves to the category of that date, not today\'s');
        $this->assertSame($g4->id, $this->asOf($relationship, '2026-05-01')?->id, 'half-open [from,to): the boundary date belongs to the new period');
        $this->assertSame($g4->id, $this->asOf($relationship, '2030-01-01')?->id);
        $this->assertSame($g4->id, $this->asOf($relationship, Carbon::parse('2026-06-15'))?->id, 'accepts a Carbon date too');
    }

    // ---------------------------------------------------------------------
    // B. Temporal
    // ---------------------------------------------------------------------

    public function test_a_future_dated_category_does_not_become_current_before_its_effective_date(): void
    {
        $relationship = $this->relationship();
        $current = $this->employmentCategory('grade_3');
        $future = $this->employmentCategory('grade_2');
        $today = Carbon::today();
        $futureFrom = $today->copy()->addMonths(6)->toDateString();

        $this->record($relationship, $current, '2026-01-15');
        $this->record($relationship, $future, $futureFrom);

        $this->assertSame($current->id, $this->asOf($relationship, $today)?->id, 'today still resolves to the category effective today, not the scheduled one');
        $this->assertSame($current->id, $this->asOf($relationship, $today->copy()->addMonths(6)->subDay())?->id);
        $this->assertSame($future->id, $this->asOf($relationship, $futureFrom)?->id);
    }

    public function test_a_backdated_category_after_the_latest_period_closes_it_at_exactly_that_date(): void
    {
        $relationship = $this->relationship();
        $first = $this->record($relationship, $this->employmentCategory('grade_5'), '2026-01-15');

        $this->record($relationship, $this->employmentCategory('grade_4'), '2026-03-01');

        $this->assertSame('2026-03-01', $first->refresh()->effective_to->toDateString());
    }

    public function test_a_backdated_category_before_the_latest_period_is_rejected_and_history_is_untouched(): void
    {
        $relationship = $this->relationship();
        $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $this->record($relationship, $this->employmentCategory('grade_4'), '2026-06-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-04-01', '2026-06-01', '2026-01-20'] as $backdated) {
            try {
                $this->record($relationship, $this->employmentCategory('grade_3'), $backdated);
                $this->fail("a period starting {$backdated} would rewrite later history and must be rejected");
            } catch (InvalidEmploymentCategoryPeriodDateException) {
            }
        }

        $this->assertSame($before, $this->snapshot($relationship), 'rejected backdating must leave every existing period exactly as it was');
    }

    /** CA-01 (ADR-S20-001): a day-one category — same date as the relationship start — is allowed. */
    public function test_a_day_one_category_starting_on_the_relationship_start_date_is_accepted(): void
    {
        $relationship = $this->relationship('2026-01-01');
        $grade = $this->employmentCategory('grade_1');

        $period = $this->record($relationship, $grade, '2026-01-01');

        $this->assertSame('2026-01-01', $period->effective_from->toDateString());
        $this->assertNull($period->effective_to);
        $this->assertSame(1, $this->periodCount($relationship));
    }

    /** CA-01: as-of the relationship's first day resolves the day-one category. */
    public function test_as_of_the_relationship_start_date_resolves_the_day_one_category(): void
    {
        $relationship = $this->relationship('2026-01-01');
        $grade = $this->employmentCategory('grade_1');
        $this->record($relationship, $grade, '2026-01-01');

        $this->assertSame($grade->id, $this->asOf($relationship, '2026-01-01')?->id);
        $this->assertNull($this->asOf($relationship, '2025-12-31'), 'nothing resolves before the relationship');
    }

    /** CA-01: a category may never begin before its relationship. */
    public function test_a_category_starting_before_the_relationship_start_is_rejected(): void
    {
        $relationship = $this->relationship('2026-01-01');

        try {
            $this->record($relationship, $this->employmentCategory('grade_1'), '2025-12-31');
            $this->fail('2025-12-31 predates the relationship and must be rejected');
        } catch (InvalidEmploymentCategoryPeriodDateException) {
        }

        $this->assertSame(0, $this->periodCount($relationship));
    }

    /** CA-01 via HTTP: day-one accepted (201), pre-relationship rejected (422 on effective_from). */
    public function test_store_accepts_a_day_one_category_and_rejects_one_before_the_relationship(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $this->employmentCategory('grade_2')->id,
            'effective_from' => '2025-12-31',
        ])->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $this->employmentCategory('grade_2')->id,
            'effective_from' => '2026-01-01',
        ])->assertStatus(201)->assertJsonPath('effective_from', '2026-01-01');
    }

    /** CA-01 keeps every other invariant: a second period on the same day-one date still overlaps. */
    public function test_a_second_period_on_the_same_day_one_date_is_still_rejected(): void
    {
        $relationship = $this->relationship('2026-01-01');
        $this->record($relationship, $this->employmentCategory('grade_1'), '2026-01-01');

        $this->expectException(InvalidEmploymentCategoryPeriodDateException::class);
        $this->record($relationship, $this->employmentCategory('grade_2'), '2026-01-01');
    }

    /** CA-01 + relationship end: a day-one category is closed at the relationship end, staying within bounds. */
    public function test_a_day_one_category_is_closed_within_bounds_when_the_relationship_ends(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $period = $this->record($relationship, $this->employmentCategory('grade_1'), '2026-01-01');

        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-03-01', false);

        $this->assertSame('2026-03-01', $period->refresh()->effective_to->toDateString());
        $this->assertCategoryHistoryWithinRelationship($relationship->refresh());
    }

    public function test_consecutive_periods_are_adjacent_and_the_earlier_one_is_bounded_by_its_successor(): void
    {
        $relationship = $this->relationship();
        $a = $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $b = $this->record($relationship, $this->employmentCategory('grade_4'), '2026-05-01');
        $c = $this->record($relationship, $this->employmentCategory('grade_3'), '2026-08-01');

        $this->assertSame('2026-05-01', $a->refresh()->effective_to->toDateString());
        $this->assertSame('2026-08-01', $b->refresh()->effective_to->toDateString());
        $this->assertNull($c->refresh()->effective_to);
        $this->assertSame(1, EmploymentCategoryPeriod::query()
            ->where('employment_relationship_id', $relationship->id)->whereNull('effective_to')->count(), 'exactly one open period');
    }

    public function test_recording_a_new_period_preserves_every_earlier_period_row(): void
    {
        $relationship = $this->relationship();
        $a = $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $b = $this->record($relationship, $this->employmentCategory('grade_4'), '2026-05-01');
        $this->record($relationship, $this->employmentCategory('grade_3'), '2026-08-01');

        $a->refresh();
        $b->refresh();
        $this->assertSame($this->employmentCategory('grade_5')->id, $a->employment_category_id, 'earlier category values are never rewritten');
        $this->assertSame('2026-02-01', $a->effective_from->toDateString());
        $this->assertSame($this->employmentCategory('grade_4')->id, $b->employment_category_id);
        $this->assertSame(3, $this->periodCount($relationship));
    }

    public function test_store_rejects_a_missing_or_malformed_payload_with_422(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), [])
            ->assertStatus(422)->assertJsonValidationErrors(['employment_category_id', 'effective_from']);
        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => 'grade_1',
            'effective_from' => 'not-a-date',
        ])->assertStatus(422)->assertJsonValidationErrors(['employment_category_id', 'effective_from']);

        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_store_rejects_a_backdated_overlap_with_422_on_effective_from(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->record($relationship, $this->employmentCategory('grade_5'), '2026-05-01');

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $this->employmentCategory('grade_4')->id,
            'effective_from' => '2026-03-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        $this->assertSame(1, $this->periodCount($relationship));
    }

    // ---------------------------------------------------------------------
    // C. PostgreSQL integrity
    // ---------------------------------------------------------------------

    public function test_a_direct_overlapping_insert_is_rejected_by_the_exclusion_constraint(): void
    {
        $relationship = $this->relationship();
        $this->insertRaw($relationship, $this->employmentCategory('grade_5'), '2026-02-01', '2026-06-01');

        $error = $this->queryError(fn () => $this->insertRaw($relationship, $this->employmentCategory('grade_4'), '2026-05-01', null));

        $this->assertTrue(Errors::isExclusionViolation($error));
    }

    public function test_two_direct_open_ended_periods_for_the_same_relationship_are_rejected(): void
    {
        $relationship = $this->relationship();
        $this->insertRaw($relationship, $this->employmentCategory('grade_5'), '2026-02-01', null);

        $error = $this->queryError(fn () => $this->insertRaw($relationship, $this->employmentCategory('grade_4'), '2027-02-01', null));

        $this->assertTrue(Errors::isExclusionViolation($error), 'two open periods always overlap');
    }

    public function test_direct_adjacent_periods_are_accepted_and_other_relationships_are_independent(): void
    {
        $relationship = $this->relationship();
        $this->insertRaw($relationship, $this->employmentCategory('grade_5'), '2026-02-01', '2026-06-01');
        $this->insertRaw($relationship, $this->employmentCategory('grade_4'), '2026-06-01', null);

        $other = $this->relationship();
        $this->insertRaw($other, $this->employmentCategory('grade_1'), '2026-02-01', null);

        $this->assertSame(2, $this->periodCount($relationship));
        $this->assertSame(1, $this->periodCount($other));
    }

    public function test_a_reversed_or_empty_period_is_rejected_by_the_check_constraint(): void
    {
        $relationship = $this->relationship();

        foreach ([['2026-06-01', '2026-06-01'], ['2026-06-01', '2026-05-01']] as [$from, $to]) {
            $error = $this->queryError(fn () => $this->insertRaw($relationship, $this->employmentCategory('grade_5'), $from, $to));
            $this->assertTrue(Errors::isCheckViolation($error), "[{$from}, {$to}) must be rejected");
        }
    }

    public function test_foreign_keys_reject_a_nonexistent_relationship_or_category(): void
    {
        $relationship = $this->relationship();

        $error = $this->queryError(fn () => DB::table('hr.employment_category_periods')->insert([
            'id' => (string) Str::uuid7(),
            'employment_relationship_id' => (string) Str::uuid7(),
            'employment_category_id' => $this->employmentCategory('grade_1')->id,
            'effective_from' => '2026-02-01',
            'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));

        $error = $this->queryError(fn () => DB::table('hr.employment_category_periods')->insert([
            'id' => (string) Str::uuid7(),
            'employment_relationship_id' => $relationship->id,
            'employment_category_id' => (string) Str::uuid7(),
            'effective_from' => '2026-02-01',
            'created_at' => now(),
        ]));
        $this->assertTrue(Errors::isForeignKeyViolation($error));
    }

    public function test_a_referenced_category_cannot_be_hard_deleted(): void
    {
        $relationship = $this->relationship();
        $category = $this->createSyntheticEmploymentCategory();
        $this->record($relationship, $category, '2026-02-01');

        $error = $this->queryError(fn () => DB::table('ref.employment_categories')->where('id', $category->id)->delete());

        $this->assertTrue(Errors::isForeignKeyViolation($error), 'RESTRICT FK keeps historical references valid');
    }

    public function test_the_period_table_has_no_speculative_columns_and_the_relationship_has_no_current_category_column(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_category_periods')
            ->pluck('column_name')->sort()->values()->all();

        $this->assertSame(
            ['created_at', 'effective_from', 'effective_to', 'employment_category_id', 'employment_relationship_id', 'id'],
            $columns,
            'no person_id (owned by the relationship), no organizational_unit_id (plain RBAC), no version/updated_at (append-only)',
        );

        $relationshipColumns = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_relationships')
            ->pluck('column_name')->all();

        foreach ($relationshipColumns as $column) {
            $this->assertStringNotContainsString('category', $column, 'current category is derived from history, never stored on the relationship');
        }

        $types = DB::table('information_schema.columns')
            ->where('table_schema', 'hr')->where('table_name', 'employment_category_periods')
            ->pluck('data_type', 'column_name');
        $this->assertSame('date', $types['effective_from']);
        $this->assertSame('date', $types['effective_to']);
        $this->assertSame('timestamp with time zone', $types['created_at']);
    }

    // ---------------------------------------------------------------------
    // D. Reference
    // ---------------------------------------------------------------------

    public function test_a_nonexistent_category_is_404_and_writes_nothing(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => (string) Str::uuid7(),
            'effective_from' => '2026-02-01',
        ])->assertNotFound();

        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_an_inactive_category_is_rejected_for_a_new_assignment(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $inactive = $this->createSyntheticEmploymentCategory(active: false);
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $inactive->id,
            'effective_from' => '2026-02-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['employment_category_id']);

        $this->assertSame(0, $this->periodCount($relationship));
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'a rejected write records no false success audit entry');

        $this->expectException(InvalidEmploymentCategoryException::class);
        $this->record($relationship, $inactive, '2026-02-01');
    }

    public function test_a_category_deactivated_after_assignment_keeps_its_history_readable_and_unchanged(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $category = $this->createSyntheticEmploymentCategory();
        $period = $this->record($relationship, $category, '2026-02-01');
        $before = $this->snapshot($relationship);

        app(DeactivateEmploymentCategory::class)->handle($category, $category->version);
        $this->assertFalse($category->refresh()->is_active);

        $this->assertSame($before, $this->snapshot($relationship), 'deactivation never deletes or rewrites history');
        $this->assertSame($category->id, $this->asOf($relationship, '2026-03-01')?->id, 'historical as-of reads still resolve the now-inactive category');
        $this->getJson($this->url($person, $relationship))->assertOk()
            ->assertJsonPath('0.id', $period->id)
            ->assertJsonPath('0.employment_category_id', $category->id);

        // ...but it can no longer be newly assigned.
        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $category->id,
            'effective_from' => '2026-06-01',
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // E. Employment lifecycle
    // ---------------------------------------------------------------------

    public function test_an_ended_relationship_rejects_a_new_category_assignment(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->end($person, $relationship, '2026-06-01');

        $this->postJson($this->url($person, $relationship->refresh()), [
            'employment_category_id' => $this->employmentCategory('grade_1')->id,
            'effective_from' => '2026-03-01',
        ])->assertStatus(409);

        $this->expectException(EmploymentRelationshipAlreadyEndedException::class);
        $this->record($relationship, $this->employmentCategory('grade_1'), '2026-03-01');
    }

    public function test_ending_the_relationship_closes_the_open_category_at_the_same_effective_date(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $earlier = $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $open = $this->record($relationship, $this->employmentCategory('grade_4'), '2026-05-01');

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
            'expected_version' => $relationship->version,
            'effective_to' => '2026-09-01',
            'is_terminal' => false,
        ])->assertOk();

        $this->assertSame('2026-09-01', $open->refresh()->effective_to->toDateString());
        $this->assertSame('2026-05-01', $earlier->refresh()->effective_to->toDateString(), 'earlier history untouched');
        $this->assertSame(2, $this->periodCount($relationship), 'nothing deleted, nothing added');
        $this->assertNull($this->asOf($relationship, '2026-09-01'), 'no category resolves on or after the relationship end');

        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertTrue($entry->metadata['employment_category_period_closed_as_consequence'] ?? false);
    }

    public function test_ending_a_relationship_without_an_open_category_neither_extends_an_earlier_end_nor_adds_metadata(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        // A period that already ended before the relationship end (e.g. future-imported history)
        // with no open successor.
        $this->insertRaw($relationship, $this->employmentCategory('grade_5'), '2026-02-01', '2026-04-01');

        $this->end($person, $relationship, '2026-09-01');

        $row = DB::table('hr.employment_category_periods')->where('employment_relationship_id', $relationship->id)->first();
        $this->assertSame('2026-04-01', (string) $row->effective_to, 'an earlier category end is never extended to the relationship end');
        $entry = $this->latestAuditEntryFor('hr.employment_relationship.end');
        $this->assertArrayNotHasKey('employment_category_period_closed_as_consequence', $entry->metadata ?? []);
    }

    public function test_a_relationship_end_before_a_future_category_is_rejected_and_nothing_changes(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $this->record($relationship, $this->employmentCategory('grade_4'), '2026-10-01');
        $before = $this->snapshot($relationship);

        foreach (['2026-09-01', '2026-10-01'] as $endDate) {
            $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/end", [
                'expected_version' => $relationship->version,
                'effective_to' => $endDate,
                'is_terminal' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        }

        $this->assertSame($before, $this->snapshot($relationship), 'no impossible period is created, nothing is deleted or truncated');
        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state, 'the whole end transaction rolled back atomically');
        $this->assertNull($relationship->effective_to);
    }

    public function test_a_relationship_end_before_an_already_closed_category_end_is_rejected(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->insertRaw($relationship, $this->employmentCategory('grade_5'), '2026-02-01', '2026-11-01');

        $this->expectException(InvalidEndDateException::class);
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-09-01', false);
    }

    public function test_a_backdated_relationship_end_closes_the_open_category_coherently(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $a = $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $b = $this->record($relationship, $this->employmentCategory('grade_4'), '2026-05-01');

        // Backdated: recorded after the fact, effective on a date already in the past relative to
        // the category history's own later entries being recorded.
        app(EndEmploymentRelationship::class)->handle($person, $relationship, $relationship->version, '2026-06-15', false);

        $this->assertSame('2026-05-01', $a->refresh()->effective_to->toDateString());
        $this->assertSame('2026-06-15', $b->refresh()->effective_to->toDateString());
        $this->assertCategoryHistoryWithinRelationship($relationship->refresh());
        $this->assertSame($this->employmentCategory('grade_4')->id, $this->asOf($relationship, '2026-06-14')?->id);
        $this->assertNull($this->asOf($relationship, '2026-06-15'));
    }

    public function test_a_status_triggered_relationship_end_also_closes_the_open_category(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->employmentCategory('grade_2'), '2026-02-01');

        app(RecordEmploymentStatusPeriod::class)->handle($person, $relationship, $this->statusDetail('resigned'), '2026-10-15');

        $this->assertSame('KNOWN', $relationship->refresh()->end_knowledge_state);
        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());
        $this->assertCategoryHistoryWithinRelationship($relationship);
    }

    /**
     * CA-02: a status-triggered termination that closes an open category exposes that consequence
     * in the TRIGGERING status-period audit entry (no separate audit event).
     */
    public function test_status_triggered_termination_audit_exposes_the_category_closure(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->employmentCategory('grade_2'), '2026-02-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'resigned',
            'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertSame('KNOWN', $relationship->refresh()->end_knowledge_state);
        $this->assertSame('2026-10-15', $open->refresh()->effective_to->toDateString());

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertTrue($entry->metadata['employment_category_period_closed_as_consequence'] ?? false);
        $this->assertSame($auditBefore + 1, $this->auditEntriesCount(), 'exactly one audit entry — no duplicate event for the consequence');
    }

    /** CA-02: without an open category, the triggering audit entry does not claim a category closure. */
    public function test_status_triggered_termination_without_a_category_makes_no_category_claim(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'resigned',
            'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertTrue($entry->metadata['relationship_closed_as_consequence'] ?? false);
        $this->assertArrayNotHasKey('employment_category_period_closed_as_consequence', $entry->metadata);
    }

    /** CA-02: a non-terminating status transition never claims a category closure and leaves the category open. */
    public function test_a_non_terminating_status_transition_leaves_the_category_open_and_unclaimed(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $open = $this->record($relationship, $this->employmentCategory('grade_2'), '2026-02-01');

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'traveling',
            'effective_from' => '2026-10-15',
        ])->assertStatus(201);

        $this->assertNull($open->refresh()->effective_to);
        $entry = $this->latestAuditEntryFor('hr.employment_status_period.record');
        $this->assertArrayNotHasKey('employment_category_period_closed_as_consequence', $entry->metadata ?? []);
    }

    /**
     * CA-02: when a status-triggered termination is rejected because a category would extend
     * beyond the relationship, the whole triggering operation rolls back — no status period, no
     * relationship end, no category change, and no (false) success audit entry.
     */
    public function test_a_rejected_status_triggered_termination_writes_nothing_and_no_success_audit(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $this->record($relationship, $this->employmentCategory('grade_5'), '2026-02-01');
        $this->record($relationship, $this->employmentCategory('grade_4'), '2026-11-01');
        $before = $this->snapshot($relationship);
        $statusPeriodsBefore = DB::table('hr.employment_status_periods')->where('employment_relationship_id', $relationship->id)->count();
        $auditBefore = $this->auditEntriesCount();

        $this->postJson("/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/status-periods", [
            'status_detail_code' => 'resigned',
            'effective_from' => '2026-10-15',
        ])->assertStatus(422);

        $this->assertSame('NOT_APPLICABLE', $relationship->refresh()->end_knowledge_state);
        $this->assertSame($before, $this->snapshot($relationship));
        $this->assertSame($statusPeriodsBefore, DB::table('hr.employment_status_periods')->where('employment_relationship_id', $relationship->id)->count());
        $this->assertSame($auditBefore, $this->auditEntriesCount(), 'no success audit entry for a rolled-back termination');
    }

    public function test_reappointment_does_not_inherit_the_previous_relationships_category(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $old] = $this->personAndRelationship();
        $oldPeriod = $this->record($old, $this->employmentCategory('grade_senior'), '2026-02-01');
        $this->end($person, $old, '2026-06-01');

        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-08-01');

        $this->assertSame(0, $this->periodCount($new), 'no category is copied or inferred onto the new relationship');
        $this->assertNull($this->asOf($new, '2026-08-01'));
        $this->assertNull($this->asOf($new, '2027-01-01'));
        $this->getJson($this->url($person, $new))->assertOk()->assertJsonCount(0);

        // The old relationship's history stays exactly where it was.
        $this->assertSame($old->id, $oldPeriod->refresh()->employment_relationship_id);
        $this->assertSame('2026-06-01', $oldPeriod->effective_to->toDateString());
        $this->assertSame($this->employmentCategory('grade_senior')->id, $this->asOf($old, '2026-03-01')?->id);

        // Recording on the new relationship is independent of the old one's history.
        $this->record($new, $this->employmentCategory('grade_1'), '2026-08-02');
        $this->assertSame(1, $this->periodCount($new));
        $this->assertSame(1, $this->periodCount($old));
    }

    // ---------------------------------------------------------------------
    // F. Security
    // ---------------------------------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$person, $relationship] = $this->personAndRelationship();

        $this->getJson($this->url($person, $relationship))->assertUnauthorized();
        $this->postJson($this->url($person, $relationship), [])->assertUnauthorized();
    }

    public function test_an_authenticated_principal_without_hr_permissions_is_forbidden(): void
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
        $this->principalWithPermissions([Perm::EMPLOYMENT_CATEGORY_PERIODS_VIEW]);

        $this->getJson($this->url($person, $relationship))->assertOk();
        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_the_record_permission_alone_is_sufficient_without_any_organizational_scope(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        // Plain RBAC (ADR-S20-001 §8): no S08 scope grant of any kind is given here.
        $this->principalWithPermissions([Perm::EMPLOYMENT_CATEGORY_PERIODS_RECORD]);

        $this->postJson($this->url($person, $relationship), $this->payload())->assertStatus(201);
        $this->getJson($this->url($person, $relationship))->assertForbidden();
    }

    public function test_reference_catalog_permissions_never_grant_employment_category_assignment(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->principalWithPermissions(ReferencePermissionCatalog::ALL);

        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();
        $this->assertSame(0, $this->periodCount($relationship));

        // ...while still administering the catalog itself exactly as before.
        $this->getJson('/api/v1/reference/employment-categories')->assertOk();
    }

    public function test_other_hr_permissions_do_not_grant_employment_category_assignment(): void
    {
        [$person, $relationship] = $this->personAndRelationship();
        $this->principalWithPermissions(array_values(array_diff(Perm::ALL, [
            Perm::EMPLOYMENT_CATEGORY_PERIODS_VIEW, Perm::EMPLOYMENT_CATEGORY_PERIODS_RECORD,
        ])));

        $this->postJson($this->url($person, $relationship), $this->payload())->assertForbidden();
        $this->getJson($this->url($person, $relationship))->assertForbidden();
    }

    public function test_a_relationship_belonging_to_another_person_is_404(): void
    {
        $this->actingAsHrAdministrator();
        [, $relationship] = $this->personAndRelationship();
        $stranger = $this->createPersonRecord();

        $this->getJson($this->url($stranger, $relationship))->assertNotFound();
        $this->postJson($this->url($stranger, $relationship), $this->payload())->assertNotFound();
        $this->assertSame(0, $this->periodCount($relationship));
    }

    public function test_no_patch_or_delete_route_exists_for_category_periods(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();

        $this->patchJson($this->url($person, $relationship), $this->payload())->assertStatus(405);
        $this->deleteJson($this->url($person, $relationship))->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // G. Audit
    // ---------------------------------------------------------------------

    public function test_a_successful_record_is_audited_with_actor_target_and_changes_without_pii(): void
    {
        $principal = $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $grade = $this->employmentCategory('grade_3');

        $response = $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $grade->id,
            'effective_from' => '2026-02-01',
        ])->assertStatus(201);

        $entry = $this->latestAuditEntryFor('hr.employment_category_period.record');
        $this->assertNotNull($entry);
        $this->assertSame('MUTATION', $entry->category->value ?? $entry->category);
        $this->assertSame('SUCCEEDED', $entry->outcome->value ?? $entry->outcome);
        $this->assertSame($principal->id, $entry->actor_principal_id);
        $this->assertSame('hr_employment_category_period', $entry->target_type);
        $this->assertSame($response->json('id'), $entry->target_id);
        $this->assertEquals([
            'employment_relationship_id' => $relationship->id,
            'employment_category_id' => $grade->id,
            'effective_from' => '2026-02-01',
        ], $entry->changes);
        $this->assertSame(['employment_category_code' => 'grade_3'], $entry->metadata);
        $this->assertStringNotContainsString($person->national_id, json_encode([$entry->changes, $entry->metadata]));
    }

    public function test_rejected_writes_leave_no_mutation_audit_entry_and_no_partial_write(): void
    {
        $this->actingAsHrAdministrator();
        [$person, $relationship] = $this->personAndRelationship();
        $existing = $this->record($relationship, $this->employmentCategory('grade_5'), '2026-05-01');
        $auditBefore = $this->auditEntriesCount();

        $this->postJson($this->url($person, $relationship), [
            'employment_category_id' => $this->employmentCategory('grade_4')->id,
            'effective_from' => '2026-03-01',
        ])->assertStatus(422);

        $this->assertSame($auditBefore, $this->auditEntriesCount());
        $this->assertNull($existing->refresh()->effective_to, 'the open period was not closed by the rejected write');
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
    private function personAndRelationship(): array
    {
        $person = $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person)];
    }

    private function record(EmploymentRelationship $relationship, EmploymentCategory $category, string $from): EmploymentCategoryPeriod
    {
        return app(RecordEmploymentCategoryPeriod::class)->handle($relationship, $category, $from);
    }

    private function asOf(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentCategory
    {
        return app(ResolveEmploymentCategoryForRelationshipAsOf::class)($relationship, $date);
    }

    private function end(Person $person, EmploymentRelationship $relationship, string $effectiveTo): void
    {
        app(EndEmploymentRelationship::class)->handle($person, $relationship->refresh(), $relationship->version, $effectiveTo, false);
    }

    private function url(Person $person, EmploymentRelationship $relationship): string
    {
        return "/api/v1/hr/persons/{$person->id}/employment-relationships/{$relationship->id}/employment-category-periods";
    }

    /** @return array{employment_category_id: string, effective_from: string} */
    private function payload(): array
    {
        return ['employment_category_id' => $this->employmentCategory('grade_1')->id, 'effective_from' => '2026-02-01'];
    }

    private function periodCount(EmploymentRelationship $relationship): int
    {
        return EmploymentCategoryPeriod::query()->where('employment_relationship_id', $relationship->id)->count();
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(EmploymentRelationship $relationship): array
    {
        return DB::table('hr.employment_category_periods')
            ->where('employment_relationship_id', $relationship->id)
            ->orderBy('effective_from')
            ->get(['id', 'employment_category_id', 'effective_from', 'effective_to'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function insertRaw(EmploymentRelationship $relationship, EmploymentCategory $category, string $from, ?string $to): void
    {
        DB::table('hr.employment_category_periods')->insert([
            'id' => (string) Str::uuid7(),
            'employment_relationship_id' => $relationship->id,
            'employment_category_id' => $category->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'created_at' => now(),
        ]);
    }

    private function assertCategoryHistoryWithinRelationship(EmploymentRelationship $relationship): void
    {
        $this->assertNotNull($relationship->effective_to);

        foreach (EmploymentCategoryPeriod::query()->where('employment_relationship_id', $relationship->id)->get() as $period) {
            $this->assertNotNull($period->effective_to, 'no category period may remain open after the relationship ended');
            $this->assertTrue($period->effective_from->gte($relationship->effective_from), 'a category may never predate its relationship (CA-01)');
            $this->assertTrue($period->effective_to->lte($relationship->effective_to), 'no category period may extend beyond the relationship');
        }
    }

    private function queryError(callable $work): QueryException
    {
        // Each probe runs in its own savepoint so a rejected statement does not poison the
        // surrounding test transaction for the probes that follow it.
        try {
            DB::transaction(fn () => $work());
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected a QueryException.');
    }

    /** A principal with exactly the given permissions via a fresh role, no scope grant. */
    private function principalWithPermissions(array $permissionCodes): Principal
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions($permissionCodes);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
    }
}
