<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\EndFullSecondment;
use App\Modules\HumanResources\Application\Commands\RecordEmploymentStatusPeriod;
use App\Modules\HumanResources\Application\Commands\RecordPartialSecondmentPeriod;
use App\Modules\HumanResources\Application\Commands\RecordReturnIntention;
use App\Modules\HumanResources\Application\Commands\RecordWorkSchedulePeriod;
use App\Modules\HumanResources\Application\Commands\StartFullSecondment;
use App\Modules\HumanResources\Application\Commands\StartWorkplaceAssignment;
use App\Modules\HumanResources\Application\Commands\TransferEmployee;
use App\Modules\HumanResources\Application\Queries\Reporting\ListMonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyRelationshipSegment;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyReportingPersonRow;
use App\Modules\HumanResources\Application\Queries\Reporting\MonthlyReportingPopulation;
use App\Modules\HumanResources\Application\Queries\Reporting\ReportingPopulationRow;
use App\Modules\HumanResources\Application\Queries\ResolveWeekdayActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use App\Modules\HumanResources\Domain\MonthInterval;
use App\Modules\HumanResources\Domain\MonthlyDutyClassification as Duty;
use App\Modules\HumanResources\Domain\MonthlyStatusSegmentation as Seg;
use App\Modules\HumanResources\Domain\WorkScheduleAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * S37 — Monthly Workforce Reporting Semantics Foundation
 * (docs/monthly-workforce-reporting-semantics-foundation-specification.md). Real PostgreSQL, synthetic data only.
 * The reporting month is November 2026: [2026-11-01, 2026-12-01). Status dates start after 2026-09-26, the
 * date from which the S06 behavior periods are authoritative.
 */
class MonthlyWorkforceReportingFoundationTest extends HumanResourcesTestCase
{
    private const M = '2026-11-01';

    private const N = '2026-12-01';

    private const SUN_THU = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY'];

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /** @param list<string>|null $personIds */
    private function month(?array $personIds = null, string $month = self::M): MonthlyReportingPopulation
    {
        return app(ListMonthlyReportingPopulation::class)($month, $personIds);
    }

    private function row(Person $person, string $month = self::M): MonthlyReportingPersonRow
    {
        $rows = $this->month([$person->id], $month)->persons;
        $this->assertCount(1, $rows, 'exactly one canonical Person row');

        return $rows[0];
    }

    private function rowOrNull(Person $person, string $month = self::M): ?MonthlyReportingPersonRow
    {
        return $this->month([$person->id], $month)->persons[0] ?? null;
    }

    private function seg(Person $person, int $i = 0, string $month = self::M): MonthlyRelationshipSegment
    {
        return $this->row($person, $month)->relationships[$i];
    }

    /** @return array{0: Person, 1: EmploymentRelationship} */
    private function emp(string $from = '2026-01-01', string $type = 'permanent', ?Person $person = null): array
    {
        $person ??= $this->createPersonRecord();

        return [$person, $this->createEmploymentRelationship($person, $type, null, $from)];
    }

    private function recStatus(Person $person, EmploymentRelationship $rel, string $code, string $from, ?string $to = null): void
    {
        app(RecordEmploymentStatusPeriod::class)->handle($person, $rel->refresh(), $this->statusDetail($code), $from, $to);
    }

    private function end(Person $person, EmploymentRelationship $rel, string $to): void
    {
        $rel->refresh();
        app(EndEmploymentRelationship::class)->handle($person, $rel, $rel->version, $to, false);
    }

    /** Compact view of status segments: [from, to, kind, code]. */
    private function statusView(MonthlyRelationshipSegment $s): array
    {
        return array_map(fn (array $g) => [$g['from'], $g['to'], $g['kind'], $g['status_code']], $s->statusSegments);
    }

    private function workplaceView(MonthlyRelationshipSegment $s): array
    {
        return array_map(fn (array $g) => [$g['from'], $g['to'], $g['state'], $g['organizational_unit_id'], $g['source']], $s->workplaceSegments);
    }

    private function insertLegacyRelationship(Person $person, string $from): string
    {
        $id = (string) Str::uuid7();
        DB::table('hr.employment_relationships')->insert([
            'id' => $id, 'person_id' => $person->id, 'employment_type_id' => $this->employmentType('contract')->id,
            'employee_number' => 'CN-LEGACY-'.Str::upper(Str::random(8)), 'employee_number_scheme' => 'CONTRACT', 'effective_from' => $from,
            'effective_to' => null, 'end_knowledge_state' => 'UNKNOWN_LEGACY', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function qualification(Person $person, ?string $degreeId, ?string $typeId, string $createdAt, ?string $id = null): string
    {
        $id ??= (string) Str::uuid7();
        DB::table('hr.person_qualifications')->insert([
            'id' => $id, 'person_id' => $person->id, 'academic_degree_id' => $degreeId,
            'qualification_type_id' => $typeId, 'created_at' => $createdAt,
        ]);

        return $id;
    }

    // ------------------------------------------------------------------------------------------
    // A–I. Month interval boundaries
    // ------------------------------------------------------------------------------------------

    public function test_month_interval_is_half_open_and_requires_an_explicit_first_day(): void
    {
        $m = MonthInterval::of('2026-11-01');
        $this->assertSame(['2026-11-01', '2026-12-01'], [$m->start, $m->next]);
        $this->assertSame('2027-01-01', MonthInterval::of('2026-12-01')->next, 'year rollover');
        $this->assertSame('2024-03-01', MonthInterval::of('2024-02-01')->next, 'leap February');

        foreach (['2026-11-02', '2026-11', '2026-13-01', 'nonsense', '2026-02-30'] as $bad) {
            try {
                MonthInterval::of($bad);
                $this->fail("{$bad} must be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_overlap_predicate_boundary_table(): void
    {
        $m = MonthInterval::of(self::M);
        $cases = [
            'starts on first day' => ['2026-11-01', '2026-11-05', true],
            'ends on month_start' => ['2026-10-01', '2026-11-01', false],
            'ends the day after month_start' => ['2026-10-01', '2026-11-02', true],
            'starts on last day' => ['2026-11-30', null, true],
            'ends at next_month_start' => ['2026-10-01', '2026-12-01', true],
            'starts at next_month_start' => ['2026-12-01', null, false],
            'one-day interval inside' => ['2026-11-10', '2026-11-11', true],
            'one-day interval before' => ['2026-10-31', '2026-11-01', false],
            'open-ended started before' => ['2020-01-01', null, true],
            'spans the whole month' => ['2026-10-01', '2027-01-01', true],
        ];
        foreach ($cases as $label => [$from, $to, $expected]) {
            $this->assertSame($expected, $m->overlaps($from, $to), $label);
            $this->assertSame($expected, $m->clip($from, $to) !== null, "{$label} (clip)");
        }
        $this->assertSame(['2026-11-01', '2026-11-05'], $m->clip('2026-10-01', '2026-11-05'));
        $this->assertSame(['2026-11-20', '2026-12-01'], $m->clip('2026-11-20', null));
    }

    public function test_a_relationship_starting_on_the_first_day_is_included(): void
    {
        [$person] = $this->emp('2026-11-01');

        $this->assertSame(['2026-11-01', '2026-12-01'], [$this->seg($person)->clippedFrom, $this->seg($person)->clippedTo]);
    }

    public function test_b_a_relationship_ending_exactly_on_month_start_is_excluded(): void
    {
        [$person, $rel] = $this->emp('2026-01-01');
        $this->end($person, $rel, '2026-11-01');

        $this->assertNull($this->rowOrNull($person));
        $this->assertNotNull($this->rowOrNull($person, '2026-10-01'), 'still present in the month before');
    }

    public function test_c_a_relationship_starting_on_the_last_day_is_included_for_one_day(): void
    {
        [$person] = $this->emp('2026-11-30');

        $this->assertSame(['2026-11-30', '2026-12-01'], [$this->seg($person)->clippedFrom, $this->seg($person)->clippedTo]);
    }

    public function test_d_a_relationship_ending_at_next_month_start_covers_the_whole_month(): void
    {
        [$person, $rel] = $this->emp('2026-01-01');
        $this->end($person, $rel, '2026-12-01');

        $s = $this->seg($person);
        $this->assertSame(['2026-11-01', '2026-12-01'], [$s->clippedFrom, $s->clippedTo]);
        $this->assertSame('2026-12-01', $s->effectiveTo, 'original boundary kept for provenance');
        $this->assertNull($this->rowOrNull($person, '2026-12-01'), 'and it is gone the next month');
    }

    public function test_e_a_relationship_starting_at_next_month_start_is_excluded(): void
    {
        [$person] = $this->emp('2026-12-01');

        $this->assertNull($this->rowOrNull($person));
    }

    public function test_f_a_one_day_relationship_yields_a_one_day_segment(): void
    {
        [$person, $rel] = $this->emp('2026-11-10');
        $this->end($person, $rel, '2026-11-11');

        $s = $this->seg($person);
        $this->assertSame(['2026-11-10', '2026-11-11'], [$s->clippedFrom, $s->clippedTo]);
        $this->assertSame([['2026-11-10', '2026-11-11', Seg::UNRESOLVED, null]], $this->statusView($s));
    }

    public function test_g_an_open_relationship_started_long_ago_spans_the_month(): void
    {
        [$person] = $this->emp('2020-03-15');

        $s = $this->seg($person);
        $this->assertSame(['2026-11-01', '2026-12-01'], [$s->clippedFrom, $s->clippedTo]);
        $this->assertSame('2020-03-15', $s->effectiveFrom);
        $this->assertNull($s->effectiveTo);
        $this->assertFalse($s->endIsUncertain);
        $this->assertSame('NOT_APPLICABLE', $s->endKnowledgeState);
    }

    public function test_h_and_i_mid_month_start_and_mid_month_end_are_clipped(): void
    {
        [$starter] = $this->emp('2026-11-15');
        [$leaver, $rel] = $this->emp('2026-01-01');
        $this->end($leaver, $rel, '2026-11-20');

        $this->assertSame(['2026-11-15', '2026-12-01'], [$this->seg($starter)->clippedFrom, $this->seg($starter)->clippedTo]);
        $s = $this->seg($leaver);
        $this->assertSame(['2026-11-01', '2026-11-20'], [$s->clippedFrom, $s->clippedTo]);
        $this->assertSame('2026-11-20', $s->effectiveTo);
        $this->assertSame('KNOWN', $s->endKnowledgeState);
    }

    public function test_the_month_argument_must_be_an_explicit_first_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->month(null, '2026-11-15');
    }

    // ------------------------------------------------------------------------------------------
    // J–M. Person grain
    // ------------------------------------------------------------------------------------------

    public function test_j_one_person_one_relationship_is_one_row_with_one_segment(): void
    {
        [$person, $rel] = $this->emp();

        $row = $this->row($person);
        $this->assertSame($person->id, $row->personId);
        $this->assertCount(1, $row->relationships);
        $this->assertSame($rel->id, $row->relationships[0]->employmentRelationshipId);
        $this->assertSame('permanent', $row->relationships[0]->employmentTypeCode);
        $this->assertSame('PERMANENT', $row->relationships[0]->employeeNumberScheme);
    }

    public function test_k_reappointment_in_the_same_month_is_one_person_and_two_isolated_segments(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');

        $rows = $this->month([$person->id])->persons;
        $this->assertCount(1, $rows, 'one Person, never one row per relationship');
        $segments = $rows[0]->relationships;
        $this->assertSame([$old->id, $new->id], array_map(fn ($s) => $s->employmentRelationshipId, $segments));
        $this->assertSame([['2026-11-01', '2026-11-10'], ['2026-11-10', '2026-12-01']], array_map(fn ($s) => [$s->clippedFrom, $s->clippedTo], $segments), 'touching at the boundary, no overlap');
    }

    public function test_k2_a_gap_between_two_relationships_in_the_month_is_not_filled(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->end($person, $old, '2026-11-10');
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-20');

        $segments = $this->row($person)->relationships;
        $this->assertSame([['2026-11-01', '2026-11-10'], ['2026-11-20', '2026-12-01']], array_map(fn ($s) => [$s->clippedFrom, $s->clippedTo], $segments));
    }

    public function test_l_no_fact_leaks_across_relationships_of_the_same_person(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $unitOld = $this->createUnit();
        $this->recordPlacement($old, $unitOld, '2026-01-15');
        $this->recStatus($person, $old, 'on_duty', '2026-09-27');
        $this->recStatus($person, $old, 'unpaid_leave', '2026-11-03', '2026-11-08');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');

        [$a, $b] = $this->row($person)->relationships;
        $this->assertSame($old->id, $a->employmentRelationshipId);
        $this->assertSame('unpaid_leave', $a->lastNonOnDutyReason['status_code']);
        $this->assertSame([$unitOld->id], array_values(array_unique(array_column($a->workplaceSegments, 'organizational_unit_id'))));

        $this->assertSame($new->id, $b->employmentRelationshipId);
        $this->assertSame([['2026-11-10', '2026-12-01', Seg::UNRESOLVED, null]], $this->statusView($b), 'the new relationship inherits no status');
        $this->assertNull($b->lastNonOnDutyReason);
        $this->assertNull($b->relationshipEndReason);
        $this->assertSame([ActualWorkplaceAsOf::UNRESOLVED], array_column($b->workplaceSegments, 'state'), 'and no workplace');
    }

    public function test_m_person_identity_is_unique_and_distinct_people_are_distinct_rows(): void
    {
        [$p1] = $this->emp();
        [$p2] = $this->emp();
        $this->assertNotSame($p1->national_id, $p2->national_id);

        $rows = $this->month([$p1->id, $p2->id])->persons;
        $this->assertCount(2, $rows);
        $this->assertSame([$p1->id, $p2->id], array_values(array_intersect(array_column($rows, 'personId'), [$p1->id, $p2->id])));
        $this->assertSame(1, (int) DB::selectOne("select count(*) c from pg_indexes where schemaname='hr' and tablename='persons' and indexname='persons_national_id_unique'")->c, 'PostgreSQL itself keeps one Person per national id');
    }

    public function test_person_population_is_not_relationship_population(): void
    {
        [$person, $a] = $this->emp('2026-01-01', 'contract');
        $this->end($person, $a, '2026-11-10');
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        [$solo] = $this->emp();

        $this->assertCount(2, $this->month([$person->id, $solo->id])->persons, '3 relationships, 2 persons');
    }

    // ------------------------------------------------------------------------------------------
    // N–W. Status segmentation and duty classification
    // ------------------------------------------------------------------------------------------

    public function test_n_explicit_on_duty_is_has_on_duty(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'on_duty', '2026-09-27');

        $row = $this->row($person);
        $this->assertSame(Duty::HAS_ON_DUTY, $row->dutyClassification);
        $this->assertSame([['2026-11-01', '2026-12-01', Seg::EXPLICIT, 'on_duty']], $this->statusView($row->relationships[0]));
        $this->assertNull($row->relationships[0]->lastNonOnDutyReason, 'on_duty is never a reason');
    }

    public function test_o_full_known_non_on_duty_is_no_on_duty(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-09-27');

        $row = $this->row($person);
        $this->assertSame(Duty::NO_ON_DUTY, $row->dutyClassification);
        $this->assertSame([['2026-11-01', '2026-12-01', Seg::EXPLICIT, 'traveling']], $this->statusView($row->relationships[0]));
    }

    public function test_p_no_status_at_all_is_indeterminate_and_never_on_duty_or_not_on_duty(): void
    {
        [$person] = $this->emp();

        $row = $this->row($person);
        $this->assertSame(Duty::INDETERMINATE, $row->dutyClassification);
        $this->assertSame([['2026-11-01', '2026-12-01', Seg::UNRESOLVED, null]], $this->statusView($row->relationships[0]));
        $this->assertNull($row->relationships[0]->lastNonOnDutyReason);
    }

    public function test_q_a_leading_status_gap_is_unresolved_and_indeterminate_unless_on_duty_exists(): void
    {
        [$gapOnly, $relA] = $this->emp('2026-11-10');
        $this->recStatus($gapOnly, $relA, 'traveling', '2026-11-12');
        [$withDuty, $relB] = $this->emp('2026-11-10');
        $this->recStatus($withDuty, $relB, 'on_duty', '2026-11-12');

        $a = $this->row($gapOnly);
        $this->assertSame(Duty::INDETERMINATE, $a->dutyClassification);
        $this->assertSame([
            ['2026-11-10', '2026-11-12', Seg::UNRESOLVED, null],
            ['2026-11-12', '2026-12-01', Seg::EXPLICIT, 'traveling'],
        ], $this->statusView($a->relationships[0]));

        $b = $this->row($withDuty);
        $this->assertSame(Duty::HAS_ON_DUTY, $b->dutyClassification);
        $this->assertSame(Seg::UNRESOLVED, $b->relationships[0]->statusSegments[0]['kind'], 'the gap stays visible even when on_duty exists');
    }

    public function test_r_s32_derived_on_duty_after_bounded_expiry_is_read_time_only(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-09-27', '2026-11-10');
        $before = DB::table('hr.employment_status_periods')->where('employment_relationship_id', $rel->id)->count();

        $row = $this->row($person);
        $this->assertSame(Duty::HAS_ON_DUTY, $row->dutyClassification, 'the derived interval counts as on_duty');
        $this->assertSame([
            ['2026-11-01', '2026-11-10', Seg::EXPLICIT, 'traveling'],
            ['2026-11-10', '2026-12-01', Seg::DERIVED_ON_DUTY, 'on_duty'],
        ], $this->statusView($row->relationships[0]));
        $derived = $row->relationships[0]->statusSegments[1];
        $this->assertNull($derived['status_period_id'], 'no persisted id');
        $this->assertNotNull($derived['derived_from_status_period_id']);
        $this->assertSame($before, DB::table('hr.employment_status_periods')->where('employment_relationship_id', $rel->id)->count(), 'no synthetic row was persisted');
    }

    public function test_s_an_explicit_successor_at_expiry_wins_over_the_derived_return(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-09-27', '2026-11-10');
        $this->recStatus($person, $rel, 'suspended', '2026-11-10');

        $row = $this->row($person);
        $this->assertSame([
            ['2026-11-01', '2026-11-10', Seg::EXPLICIT, 'traveling'],
            ['2026-11-10', '2026-12-01', Seg::EXPLICIT, 'suspended'],
        ], $this->statusView($row->relationships[0]));
        $this->assertSame(Duty::NO_ON_DUTY, $row->dutyClassification);
    }

    public function test_t_multiple_statuses_are_segmented_chronologically_and_the_last_reason_is_the_latest(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-09-27', '2026-11-10');
        $this->recStatus($person, $rel, 'suspended', '2026-11-10', '2026-11-20');
        $this->recStatus($person, $rel, 'captive', '2026-11-20');

        $s = $this->seg($person);
        $this->assertSame(['traveling', 'suspended', 'captive'], array_column($s->statusSegments, 'status_code'));
        $this->assertSame('captive', $s->lastNonOnDutyReason['status_code']);
        $this->assertSame(['2026-11-20', '2026-12-01'], [$s->lastNonOnDutyReason['segment_from'], $s->lastNonOnDutyReason['segment_to']]);
        $this->assertSame(Duty::NO_ON_DUTY, $this->row($person)->dutyClassification);
    }

    public function test_u_has_on_duty_takes_precedence_over_indeterminate(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->end($person, $old, '2026-11-10');             // no status: unresolved
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        $this->recStatus($person, $new, 'on_duty', '2026-11-11');

        $this->assertSame(Duty::HAS_ON_DUTY, $this->row($person)->dutyClassification);
    }

    public function test_v_indeterminate_takes_precedence_over_no_on_duty(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->recStatus($person, $old, 'unpaid_leave', '2026-09-27', '2027-01-01');
        $this->end($person, $old, '2026-11-10');
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10'); // no status: unresolved

        $this->assertSame(Duty::INDETERMINATE, $this->row($person)->dutyClassification);
    }

    public function test_w_no_on_duty_requires_full_known_coverage_of_the_active_days(): void
    {
        [$full, $relF] = $this->emp();
        $this->recStatus($full, $relF, 'unpaid_leave', '2026-10-20', '2026-12-05');
        [$partial, $relP] = $this->emp();
        $this->recStatus($partial, $relP, 'unpaid_leave', '2026-10-20', '2026-11-20');   // derived on_duty afterwards
        [$gap, $relG] = $this->emp('2026-11-05');
        $this->recStatus($gap, $relG, 'unpaid_leave', '2026-11-08', '2026-12-05');       // leading gap 11-05..11-08

        $this->assertSame(Duty::NO_ON_DUTY, $this->row($full)->dutyClassification);
        $this->assertSame(Duty::HAS_ON_DUTY, $this->row($partial)->dutyClassification);
        $this->assertSame(Duty::INDETERMINATE, $this->row($gap)->dutyClassification);
    }

    public function test_an_unknown_legacy_relationship_is_never_a_fabricated_end_and_never_no_on_duty(): void
    {
        $person = $this->createPersonRecord();
        $relId = $this->insertLegacyRelationship($person, '2020-01-01');
        $rel = EmploymentRelationship::query()->findOrFail($relId);
        $this->recStatus($person, $rel, 'traveling', '2026-09-27');

        $row = $this->row($person);
        $s = $row->relationships[0];
        $this->assertSame('UNKNOWN_LEGACY', $s->endKnowledgeState);
        $this->assertTrue($s->endIsUncertain);
        $this->assertNull($s->effectiveTo, 'no end date fabricated');
        $this->assertSame(Duty::INDETERMINATE, $row->dutyClassification, 'the active days cannot be asserted, so never NO_ON_DUTY');
    }

    // ------------------------------------------------------------------------------------------
    // X–Z. Reasons
    // ------------------------------------------------------------------------------------------

    public function test_x_last_non_on_duty_reason_is_the_latest_explicit_non_on_duty_segment(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-09-27', '2026-11-10');
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-11-10', '2026-11-20');

        $s = $this->seg($person);
        $this->assertSame('unpaid_leave', $s->lastNonOnDutyReason['status_code'], 'the derived on_duty after it is not a reason');
        $this->assertNotNull($s->lastNonOnDutyReason['status_period_id']);
        $this->assertSame(['2026-11-10', '2026-11-20'], [$s->lastNonOnDutyReason['segment_from'], $s->lastNonOnDutyReason['segment_to']]);
        $this->assertSame(Seg::DERIVED_ON_DUTY, $s->statusSegments[count($s->statusSegments) - 1]['kind']);
    }

    public function test_x2_the_reason_is_clipped_to_the_month_and_determined_identically_on_every_read(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'suspended', '2026-10-05', '2026-11-12');

        $reads = array_map(fn () => $this->seg($person)->lastNonOnDutyReason, [1, 2, 3]);
        $this->assertSame($reads[0], $reads[1]);
        $this->assertSame($reads[0], $reads[2]);
        $this->assertSame(['2026-11-01', '2026-11-12'], [$reads[0]['segment_from'], $reads[0]['segment_to']]);
    }

    public function test_y_the_relationship_end_reason_is_separate_and_never_the_temporary_reason(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-10-20', '2026-11-20');
        $this->recStatus($person, $rel, 'resigned', '2026-11-20');

        $s = $this->seg($person);
        $this->assertSame('KNOWN', $s->endKnowledgeState);
        $this->assertSame('2026-11-20', $s->clippedTo);
        $this->assertSame('unpaid_leave', $s->lastNonOnDutyReason['status_code'], 'the terminal status is not the temporary reason');
        $this->assertSame('resigned', $s->relationshipEndReason['status_code']);
        $this->assertSame('2026-11-20', $s->relationshipEndReason['effective_from']);
        $this->assertNotNull($s->relationshipEndReason['status_period_id']);
        $this->assertSame(Duty::NO_ON_DUTY, $this->row($person)->dutyClassification, 'the terminal status begins after the active days, so it is not evaluated');
        $this->assertSame([['2026-11-01', '2026-11-20', Seg::EXPLICIT, 'unpaid_leave']], $this->statusView($s), 'no derived on_duty after a relationship end');
    }

    public function test_y2_end_reason_boundaries_and_absence(): void
    {
        [$atN, $relN] = $this->emp();
        $this->recStatus($atN, $relN, 'on_duty', '2026-09-27');
        $this->recStatus($atN, $relN, 'retired', '2026-12-01');
        [$direct, $relD] = $this->emp();
        $this->end($direct, $relD, '2026-11-15');
        [$later, $relL] = $this->emp();
        $this->recStatus($later, $relL, 'on_duty', '2026-09-27');
        $this->recStatus($later, $relL, 'resigned', '2027-01-15');

        $this->assertNull($this->seg($atN)->relationshipEndReason, 'E == next_month_start belongs to the next month (Correction 01)');
        $this->assertNull($this->seg($direct)->relationshipEndReason, 'an end without an ending status has no end reason');
        $this->assertSame('2026-11-15', $this->seg($direct)->effectiveTo);
        $this->assertNull($this->seg($later)->relationshipEndReason, 'an end after the month is not applicable to it');
    }

    public function test_correction01_end_reason_belongs_to_the_month_iff_month_start_lt_end_lt_next_month_start(): void
    {
        // A: E strictly inside the month, with an ending status.
        [$inside, $relI] = $this->emp();
        $this->recStatus($inside, $relI, 'on_duty', '2026-09-27');
        $this->recStatus($inside, $relI, 'resigned', '2026-11-30');   // E = last day: still inside
        [$first, $relF] = $this->emp();
        $this->recStatus($first, $relF, 'on_duty', '2026-09-27');
        $this->recStatus($first, $relF, 'resigned', '2026-11-02');    // E = M1 + 1

        // B/C: E == N (2026-12-01).
        [$atN, $relN] = $this->emp();
        $this->recStatus($atN, $relN, 'unpaid_leave', '2026-10-20', '2026-12-01');
        $this->recStatus($atN, $relN, 'retired', '2026-12-01');

        // D: E == M1 (2026-11-01).
        [$atM1, $relM] = $this->emp();
        $this->recStatus($atM1, $relM, 'on_duty', '2026-09-27');
        $this->recStatus($atM1, $relM, 'resigned', '2026-11-01');

        $this->assertSame('resigned', $this->seg($inside)->relationshipEndReason['status_code']);
        $this->assertSame('2026-11-30', $this->seg($inside)->relationshipEndReason['effective_from']);
        $this->assertSame('resigned', $this->seg($first)->relationshipEndReason['status_code']);

        // B: previous month — no end reason, but the active days are unchanged (clipping not touched).
        $s = $this->seg($atN);
        $this->assertNull($s->relationshipEndReason, 'E == N is not an end event of [M1, N)');
        $this->assertSame(['2026-11-01', '2026-12-01'], [$s->clippedFrom, $s->clippedTo]);
        $this->assertSame('2026-12-01', $s->effectiveTo);
        $this->assertSame('KNOWN', $s->endKnowledgeState);
        // E: the temporary reason is unchanged.
        $this->assertSame('unpaid_leave', $s->lastNonOnDutyReason['status_code']);
        $this->assertSame(['2026-11-01', '2026-12-01'], [$s->lastNonOnDutyReason['segment_from'], $s->lastNonOnDutyReason['segment_to']]);

        // C: the next month — the relationship no longer overlaps, so the terminal reason is not attached anywhere.
        $this->assertNull($this->rowOrNull($atN, '2026-12-01'), 'no segment, hence no reason, in the month beginning at E');
        $this->assertSame(0, count($this->month([$atN->id], '2026-12-01')->persons));

        // D: E == M1 — no overlap, no segment, no end reason for this month.
        $this->assertNull($this->rowOrNull($atM1));
        // ... and in October the same end is E == N, so it is null there too (it is never attributed to the previous month).
        $this->assertNull($this->seg($atM1, 0, '2026-10-01')->relationshipEndReason);
    }

    public function test_correction01_reappointment_isolation_is_unchanged_at_the_boundary(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->recStatus($person, $old, 'unpaid_leave', '2026-10-01', '2026-12-01');
        $this->recStatus($person, $old, 'contract_ended', '2026-12-01');        // old ends at N
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-12-01');
        $this->recStatus($person, $new, 'traveling', '2026-12-05');

        [$a] = $this->row($person)->relationships;
        $this->assertCount(1, $this->row($person)->relationships, 'the new relationship starts at N and is not part of November');
        $this->assertNull($a->relationshipEndReason);
        $this->assertSame('unpaid_leave', $a->lastNonOnDutyReason['status_code']);

        $dec = $this->row($person, '2026-12-01');
        $this->assertCount(1, $dec->relationships);
        $this->assertSame($new->id, $dec->relationships[0]->employmentRelationshipId);
        $this->assertNull($dec->relationships[0]->relationshipEndReason, 'the old relationship\'s end is not attached to the new one');
        $this->assertSame('traveling', $dec->relationships[0]->lastNonOnDutyReason['status_code']);
    }

    public function test_z_reasons_stay_isolated_per_relationship(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->recStatus($person, $old, 'unpaid_leave', '2026-10-01', '2026-11-08');
        $this->recStatus($person, $old, 'contract_ended', '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        $this->recStatus($person, $new, 'traveling', '2026-11-12');

        [$a, $b] = $this->row($person)->relationships;
        $this->assertSame('unpaid_leave', $a->lastNonOnDutyReason['status_code']);
        $this->assertSame('contract_ended', $a->relationshipEndReason['status_code']);
        $this->assertSame('traveling', $b->lastNonOnDutyReason['status_code']);
        $this->assertNull($b->relationshipEndReason);
    }

    // ------------------------------------------------------------------------------------------
    // AA–AJ. Workplace segments
    // ------------------------------------------------------------------------------------------

    public function test_aa_placement_is_one_resolved_segment_and_a_mid_month_first_placement_leaves_an_unresolved_part(): void
    {
        [, $relA] = $this->emp();
        $home = $this->createUnit();
        $this->recordPlacement($relA, $home, '2026-01-15');
        [$pB, $relB] = $this->emp();
        $this->recordPlacement($relB, $home, '2026-11-10');
        [$pC] = $this->emp();

        $a = $this->month([$relA->person_id])->persons[0]->relationships[0];
        $this->assertSame([['2026-11-01', '2026-12-01', 'RESOLVED', $home->id, 'placement']], $this->workplaceView($a));
        $this->assertSame([
            ['2026-11-01', '2026-11-10', 'UNRESOLVED', null, null],
            ['2026-11-10', '2026-12-01', 'RESOLVED', $home->id, 'placement'],
        ], $this->workplaceView($this->seg($pB)));
        $this->assertSame([['2026-11-01', '2026-12-01', 'UNRESOLVED', null, null]], $this->workplaceView($this->seg($pC)));
    }

    public function test_ab_a_transfer_mid_month_yields_two_segments(): void
    {
        [$person, $rel] = $this->emp();
        $origin = $this->createUnit();
        $dest = $this->createUnit();
        $this->recordPlacement($rel, $origin, '2026-01-15');
        app(TransferEmployee::class)->handle($rel->refresh(), $dest, '2026-11-15', $this->transferDecisionType());

        $this->assertSame([
            ['2026-11-01', '2026-11-15', 'RESOLVED', $origin->id, 'placement'],
            ['2026-11-15', '2026-12-01', 'RESOLVED', $dest->id, 'placement'],
        ], $this->workplaceView($this->seg($person)));
    }

    public function test_ac_full_secondment_overrides_placement_in_its_interval_and_returns_derived(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $dest = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(StartFullSecondment::class)->handle($rel->refresh(), $dest, '2026-11-10');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-11-20');

        $this->assertSame([
            ['2026-11-01', '2026-11-10', 'RESOLVED', $home->id, 'placement'],
            ['2026-11-10', '2026-11-20', 'RESOLVED', $dest->id, 'secondment'],
            ['2026-11-20', '2026-12-01', 'RESOLVED', $home->id, 'placement'],
        ], $this->workplaceView($this->seg($person)));
    }

    public function test_ad_workplace_assignment_follows_the_s27_rule(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $dest = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(StartWorkplaceAssignment::class)->handle($rel->refresh(), $dest, '2026-11-12', $this->assignmentDecisionType());

        $this->assertSame([
            ['2026-11-01', '2026-11-12', 'RESOLVED', $home->id, 'placement'],
            ['2026-11-12', '2026-12-01', 'RESOLVED', $dest->id, 'assignment'],
        ], $this->workplaceView($this->seg($person)));
    }

    public function test_ae_legacy_overlapping_secondment_and_assignment_is_ambiguous_with_no_winner(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $secDest = $this->createUnit();
        $asgDest = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        foreach ([['hr.full_secondment_periods', $secDest, '2026-10-01', '2026-11-20'], ['hr.workplace_assignment_periods', $asgDest, '2026-11-10', null]] as [$table, $unit, $from, $to]) {
            DB::table($table)->insert(['id' => (string) Str::uuid7(), 'employment_relationship_id' => $rel->id, 'organizational_unit_id' => $unit->id,
                'effective_from' => $from, 'effective_to' => $to, 'created_at' => now()]);
        }

        $segments = $this->seg($person)->workplaceSegments;
        $this->assertSame([
            ['2026-11-01', '2026-11-10', 'RESOLVED'],
            ['2026-11-10', '2026-11-20', 'AMBIGUOUS_MOVEMENT_STATE'],
            ['2026-11-20', '2026-12-01', 'RESOLVED'],
        ], array_map(fn ($g) => [$g['from'], $g['to'], $g['state']], $segments));
        $this->assertNull($segments[1]['organizational_unit_id'], 'no chosen winner');
        $this->assertCount(2, $segments[1]['competing_movements']);
        $this->assertSame([$secDest->id, $asgDest->id], array_column($segments[1]['competing_movements'], 'organizational_unit_id'));
    }

    public function test_af_partial_secondment_preserves_its_weekday_allocation_and_the_underlying_workplace(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $dest = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $dest, '2026-11-10', '2026-11-24', ['SUNDAY', 'TUESDAY']);

        $segments = $this->seg($person)->workplaceSegments;
        $this->assertSame(['RESOLVED', 'PARTIAL_ALLOCATION', 'RESOLVED'], array_column($segments, 'state'));
        $partial = $segments[1];
        $this->assertSame(['2026-11-10', '2026-11-24'], [$partial['from'], $partial['to']]);
        $this->assertNull($partial['organizational_unit_id'], 'no scalar workplace while the weekday decides');
        $this->assertSame($home->id, $partial['underlying_organizational_unit_id']);
        $this->assertCount(1, $partial['partial_allocations']);
        $this->assertSame($dest->id, $partial['partial_allocations'][0]['organizational_unit_id']);
        $this->assertSame(['TUESDAY', 'SUNDAY'], $partial['partial_allocations'][0]['weekdays'], 'ISO order (Monday first)');
        $this->assertArrayNotHasKey('percentage', $partial);
        $this->assertArrayNotHasKey('allocated_days', $partial);
    }

    public function test_ag_parallel_disjoint_partial_allocations_are_both_preserved(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $d1 = $this->createUnit();
        $d2 = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $d1, '2026-11-01', null, ['SUNDAY', 'MONDAY']);
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $d2, '2026-11-01', '2026-11-16', ['WEDNESDAY']);

        $segments = $this->seg($person)->workplaceSegments;
        $this->assertSame(['PARTIAL_ALLOCATION', 'PARTIAL_ALLOCATION'], array_column($segments, 'state'));
        $this->assertSame(['2026-11-01', '2026-11-16'], [$segments[0]['from'], $segments[0]['to']]);
        $this->assertSame([[$d1->id, ['MONDAY', 'SUNDAY']], [$d2->id, ['WEDNESDAY']]], array_map(fn ($a) => [$a['organizational_unit_id'], $a['weekdays']], $segments[0]['partial_allocations']));
        $this->assertSame([[$d1->id, ['MONDAY', 'SUNDAY']]], array_map(fn ($a) => [$a['organizational_unit_id'], $a['weekdays']], $segments[1]['partial_allocations']));
    }

    public function test_ah_a_work_schedule_change_splits_the_schedule_segments(): void
    {
        [$person, $rel] = $this->emp();
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        app(RecordWorkSchedulePeriod::class)->handle($rel->refresh(), '2026-11-15', ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY']);

        $segments = $this->seg($person)->workScheduleSegments;
        $this->assertSame([
            ['2026-11-01', '2026-11-15', WorkScheduleAsOf::RESOLVED, ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'SUNDAY']],
            ['2026-11-15', '2026-12-01', WorkScheduleAsOf::RESOLVED, ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY']],
        ], array_map(fn ($g) => [$g['from'], $g['to'], $g['state'], $g['weekdays']], $segments));
    }

    public function test_ai_a_schedule_that_is_not_recorded_stays_not_recorded_with_no_default_week(): void
    {
        [$none] = $this->emp();
        [$late, $relL] = $this->emp();
        app(RecordWorkSchedulePeriod::class)->handle($relL, '2026-11-20', self::SUN_THU);

        $this->assertSame([['2026-11-01', '2026-12-01', 'NOT_RECORDED', null, []]], array_map(
            fn ($g) => [$g['from'], $g['to'], $g['state'], $g['work_schedule_period_id'], $g['weekdays']],
            $this->seg($none)->workScheduleSegments,
        ));
        $this->assertSame(['NOT_RECORDED', 'RESOLVED'], array_column($this->seg($late)->workScheduleSegments, 'state'));
    }

    public function test_aj_multiple_workplace_segments_are_preserved_not_collapsed_to_one(): void
    {
        [$person, $rel] = $this->emp();
        $a = $this->createUnit();
        $b = $this->createUnit();
        $c = $this->createUnit();
        $this->recordPlacement($rel, $a, '2026-01-15');
        app(TransferEmployee::class)->handle($rel->refresh(), $b, '2026-11-08', $this->transferDecisionType());
        app(StartFullSecondment::class)->handle($rel->refresh(), $c, '2026-11-16');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-11-24');

        $this->assertSame([$a->id, $b->id, $c->id, $b->id], array_column($this->seg($person)->workplaceSegments, 'organizational_unit_id'));
        $this->assertCount(1, $this->month([$person->id])->persons, 'still exactly one Person row');
    }

    public function test_workplace_segments_of_a_partial_relationship_end_with_the_relationship(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $dest = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(StartFullSecondment::class)->handle($rel->refresh(), $dest, '2026-11-10');
        $this->end($person, $rel, '2026-11-20');   // S35: the open secondment is truncated at the end

        $s = $this->seg($person);
        $this->assertSame('2026-11-20', $s->clippedTo);
        $this->assertSame([
            ['2026-11-01', '2026-11-10', 'RESOLVED', $home->id, 'placement'],
            ['2026-11-10', '2026-11-20', 'RESOLVED', $dest->id, 'secondment'],
        ], $this->workplaceView($s));
    }

    // ------------------------------------------------------------------------------------------
    // AK–AP. Person facts
    // ------------------------------------------------------------------------------------------

    public function test_ak_and_al_birth_date_is_exposed_exactly_and_never_as_an_age(): void
    {
        [$none] = $this->emp();
        [$leap] = $this->emp();
        [$plain] = $this->emp();
        DB::table('hr.persons')->where('id', $none->id)->update(['birth_date' => null]);
        DB::table('hr.persons')->where('id', $leap->id)->update(['birth_date' => '2000-02-29']);
        DB::table('hr.persons')->where('id', $plain->id)->update(['birth_date' => '1989-11-30']);

        $this->assertNull($this->row($none)->birthDate);
        $this->assertSame('2000-02-29', $this->row($leap)->birthDate);
        $this->assertSame('1989-11-30', $this->row($plain)->birthDate);
        foreach (['age', 'ageBand', 'ageBracket'] as $property) {
            $this->assertFalse(property_exists(MonthlyReportingPersonRow::class, $property), "no {$property}");
            $this->assertFalse(property_exists(MonthlyReportingPopulation::class, $property), "no {$property}");
        }
    }

    public function test_am_a_person_without_qualifications_stays_in_the_population(): void
    {
        [$person] = $this->emp();

        $this->assertSame([], $this->row($person)->qualifications);
    }

    public function test_an_multiple_qualifications_never_multiply_the_person_row_or_the_relationships(): void
    {
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->end($person, $old, '2026-11-10');
        $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        foreach (range(1, 5) as $i) {
            $this->qualification($person, $this->createSyntheticAcademicDegree()->id, $this->createSyntheticQualificationType()->id, "2026-03-0{$i} 00:00:00+00");
        }

        $rows = $this->month([$person->id])->persons;
        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]->relationships, 'relationships are not multiplied by qualifications');
        $this->assertCount(5, $rows[0]->qualifications);
        $this->assertSame(['id', 'academic_degree_id', 'academic_degree_code', 'academic_degree_name_ar', 'academic_degree_name_en',
            'qualification_type_id', 'qualification_type_code', 'qualification_type_name_ar', 'qualification_type_name_en'], array_keys($rows[0]->qualifications[0]));
    }

    public function test_ao_qualifications_are_current_recorded_facts_even_for_a_historical_month(): void
    {
        [$person] = $this->emp('2020-01-01');
        $id = $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, '2031-05-05 00:00:00+00'); // "recorded" after the month

        $population = $this->month([$person->id], '2024-06-01');
        $this->assertSame('CURRENT_RECORDED_PERSON_FACTS', $population->qualificationSemantics);
        $this->assertSame('CURRENT_RECORDED_PERSON_FACTS', $population->persons[0]->qualificationSemantics);
        $this->assertSame([$id], array_column($population->persons[0]->qualifications, 'id'), 'created_at is not an effective date');
        $this->assertSame([$id], array_column($this->month([$person->id], '2020-01-01')->persons[0]->qualifications, 'id'));
    }

    public function test_ap_qualification_order_is_created_at_then_id_and_stable(): void
    {
        [$person] = $this->emp();
        $spec = [
            ['2026-03-05', '00000000-0000-7000-8000-00000000000a'], ['2026-03-01', '00000000-0000-7000-8000-000000000009'],
            ['2026-03-04', '00000000-0000-7000-8000-000000000001'], ['2026-03-01', '00000000-0000-7000-8000-000000000002'],
            ['2026-03-03', '00000000-0000-7000-8000-000000000008'], ['2026-03-05', '00000000-0000-7000-8000-000000000003'],
        ];
        foreach ($spec as [$createdAt, $id]) {
            $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, "{$createdAt} 00:00:00+00", $id);
        }
        usort($spec, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);

        foreach ([1, 2, 3] as $read) {
            $this->assertSame(array_column($spec, 1), array_column($this->row($person)->qualifications, 'id'), "read #{$read}");
        }
    }

    public function test_qualifications_do_not_leak_between_people(): void
    {
        [$a] = $this->emp();
        [$b] = $this->emp();
        $qa = $this->qualification($a, $this->createSyntheticAcademicDegree()->id, null, '2026-03-01 00:00:00+00');
        $qb = $this->qualification($b, null, $this->createSyntheticQualificationType()->id, '2026-03-01 00:00:00+00');

        $this->assertSame([$qa], array_column($this->row($a)->qualifications, 'id'));
        $this->assertSame([$qb], array_column($this->row($b)->qualifications, 'id'));
    }

    public function test_multi_value_dimensions_are_not_exposed_as_monthly_values(): void
    {
        $forbidden = ['specialty', 'jobTitle', 'contract', 'category', 'cadre', 'administrator', 'population'];
        foreach ([MonthlyReportingPersonRow::class, MonthlyRelationshipSegment::class, MonthlyReportingPopulation::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsStringIgnoringCase($word, $property->getName(), "{$class}::{$property->getName()}");
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // AQ–BA. Guards and regressions
    // ------------------------------------------------------------------------------------------

    public function test_aq_counts_in_monthly_reporting_never_controls_inclusion_or_classification(): void
    {
        [$a, $relA] = $this->emp();
        $this->recStatus($a, $relA, 'traveling', '2026-09-27');
        [$b, $relB] = $this->emp();
        $this->recStatus($b, $relB, 'on_duty', '2026-09-27');
        [$c] = $this->emp();
        $ids = [$a->id, $b->id, $c->id];

        $snapshot = fn () => json_encode($this->month($ids));
        $baseline = $snapshot();

        foreach ([true, false] as $value) {
            DB::table('ref.employment_status_detail_behaviors')->update(['counts_in_monthly_reporting' => $value]);
            $this->assertSame($baseline, $snapshot(), 'the flag set to '.var_export($value, true).' changes nothing');
        }
        foreach (['ListMonthlyReportingPopulation', 'MonthlyReportingPersonRow', 'MonthlyRelationshipSegment', 'MonthlyReportingPopulation'] as $class) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents(base_path("app/Modules/HumanResources/Application/Queries/Reporting/{$class}.php")));
            $this->assertStringNotContainsString('counts_in_monthly_reporting', $code, "{$class} code never reads the flag");
        }
        foreach (['MonthInterval', 'MonthlyStatusSegmentation', 'MonthlyDutyClassification', 'MonthlyWorkplaceSegmentation'] as $class) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents(base_path("app/Modules/HumanResources/Domain/{$class}.php")));
            $this->assertStringNotContainsString('counts_in_monthly_reporting', $code, "{$class} code never reads the flag");
        }
    }

    public function test_ar_no_schema_object_was_added(): void
    {
        // S37 added no migration: every migration after S34's last one (2026_10_15_000003) belongs to S38 (2026_10_16), S39 (2026_10_17, one permission seed) or S41 (2026_10_18).
        $afterS34 = collect(glob(base_path('database/migrations/*.php')))->map('basename')->sort()->filter(fn ($name) => $name >= '2026_10_16')->values()->all();
        $this->assertSame([
            '2026_10_16_000001_create_automation_employment_status_expiry_followups_table.php',
            '2026_10_16_000002_seed_security_employment_status_expiry_followup_permission.php',
            '2026_10_16_000003_add_effective_to_index_to_hr_employment_status_periods_table.php',
            '2026_10_17_000001_seed_security_monthly_not_on_duty_permission.php',
            '2026_10_18_000001_add_travel_pay_status_to_hr_employment_status_periods_table.php',
            '2026_10_18_000002_add_is_primary_to_hr_person_qualifications_table.php',
            '2026_10_18_000003_seed_security_human_cadre_permissions.php',
            '2026_10_19_000001_seed_security_monthly_administrative_report_permission.php',
            '2026_10_20_000001_seed_security_monthly_employment_status_report_permission.php',
        ], $afterS34, 'no S37 migration exists');
        $this->assertCount(91, glob(base_path('database/migrations/*.php')));
        $this->assertSame(0, DB::table('information_schema.tables')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->where('table_name', 'like', '%monthly_population%')->count());
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from pg_matviews')->c);
        $this->assertSame(0, DB::table('information_schema.views')->whereIn('table_schema', ['hr', 'ref', 'org', 'automation', 'reporting'])->count(), 'no view');
    }

    public function test_as_no_route_or_api_exposes_the_monthly_foundation(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), ['api/v1/hr/administrative-report', 'api/v1/hr/employment-status-report'], true)) {
                continue; // S42/S43: the EXACT-URI exceptions — the mandated R2 and R4 endpoints; every other route is still checked
            }
            $this->assertDoesNotMatchRegularExpression('/monthly(?!-cadre-categories|CadreCategory)|report|export|dashboard|pdf|xlsx|csv|print/i', $route->uri(), 'S37 exposes no endpoint');
            $this->assertDoesNotMatchRegularExpression('/ListMonthlyReportingPopulation|MonthlyReporting/', (string) $route->getActionName());
        }
        $this->assertSame([], glob(base_path('app/Modules/*/Presentation/*/*Monthly*.php')));
    }

    public function test_at_no_output_or_export_class_exists(): void
    {
        foreach (['Pdf', 'Xlsx', 'Csv', 'Export', 'Dashboard', 'Chart', 'Print'] as $forbidden) {
            foreach (['app/Modules/*/*/*', 'app/Modules/*/*/*/*', 'app/Modules/*/*/*/*/*'] as $pattern) {
                $this->assertSame([], glob(base_path("{$pattern}{$forbidden}*.php")), "no {$forbidden} class");
            }
        }
    }

    public function test_au_the_s27_contract_is_unchanged(): void
    {
        $params = array_map(fn ($p) => $p->getName(), (new \ReflectionClass(ReportingPopulationRow::class))->getConstructor()->getParameters());
        $this->assertSame([
            'asOfDate', 'personId', 'employmentRelationshipId', 'employmentTypeId', 'employmentTypeCode', 'employeeNumberScheme',
            'relationshipEffectiveFrom', 'relationshipEffectiveTo', 'relationshipEndKnowledgeState', 'relationshipAsOfState',
            'genderId', 'statusPeriodId', 'statusDetailId', 'statusDetailCode', 'participatesInActiveWorkforce', 'isOngoingRelationship',
            'isRelationshipEnding', 'isTerminal', 'allowsReappointment', 'countsInMonthlyReporting', 'placementOrganizationalUnitId',
            'actualWorkplace', 'employmentCategoryId', 'contractPeriodId', 'contractTypeId', 'jobTitleId', 'specialtyId',
            'cadreCategoryId', 'isAdministrator', 'populationCategoryId', 'statusDerived', 'derivedFromStatusPeriodId',
            'returnIntentionPeriodId', 'returnIntention', 'birthDate', 'qualifications',
        ], $params);

        // One row per effective RELATIONSHIP on a date, exactly as before.
        [$person, $old] = $this->emp('2026-01-01', 'contract');
        $this->end($person, $old, '2026-11-10');
        $new = $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
        $rows = app(ListReportingPopulationAsOf::class)('2026-11-10', [$old->id, $new->id]);
        $this->assertSame([$new->id], array_map(fn ($r) => $r->employmentRelationshipId, $rows));
    }

    public function test_av_the_monthly_segments_agree_with_s27_on_every_day_of_the_month(): void
    {
        [$person, $rel] = $this->emp('2026-01-01');
        $home = $this->createUnit();
        $sec = $this->createUnit();
        $pd = $this->createUnit();
        $asg = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        $this->recStatus($person, $rel, 'on_duty', '2026-09-27');
        $this->recStatus($person, $rel, 'traveling', '2026-11-03', '2026-11-09');       // derived on_duty from 11-09
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-11-14', '2026-11-18');    // derived again from 11-18
        $this->recStatus($person, $rel, 'suspended', '2026-11-25');
        app(StartFullSecondment::class)->handle($rel->refresh(), $sec, '2026-11-05');
        app(EndFullSecondment::class)->handle($rel->refresh(), '2026-11-12');
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $pd, '2026-11-13', '2026-11-22', ['SUNDAY', 'THURSDAY']);
        app(StartWorkplaceAssignment::class)->handle($rel->refresh(), $asg, '2026-11-23', $this->assignmentDecisionType());

        $s = $this->seg($person);
        $at = fn (array $segments, string $d) => collect($segments)->first(fn ($g) => $g['from'] <= $d && $d < $g['to']);

        for ($day = 1; $day <= 30; $day++) {
            $d = sprintf('2026-11-%02d', $day);
            $row = app(ListReportingPopulationAsOf::class)($d, [$rel->id])[0];

            $status = $at($s->statusSegments, $d);
            $this->assertSame($row->statusDetailCode, $status['status_code'], "status code {$d}");
            $this->assertSame($row->statusDerived, $status['kind'] === Seg::DERIVED_ON_DUTY, "derived flag {$d}");
            $this->assertSame($row->statusPeriodId, $status['status_period_id'], "status period {$d}");

            $wp = $at($s->workplaceSegments, $d);
            $this->assertSame($row->actualWorkplace->state(), $wp['state'], "workplace state {$d}");
            $this->assertSame($row->actualWorkplace->organizationalUnitId(), $wp['organizational_unit_id'], "workplace unit {$d}");
            $this->assertSame($row->actualWorkplace->source(), $wp['source'], "workplace source {$d}");
            $this->assertSame($row->actualWorkplace->partialAllocations(), $wp['partial_allocations'], "allocations {$d}");
            $this->assertSame($row->actualWorkplace->underlyingOrganizationalUnitId(), $wp['underlying_organizational_unit_id'], "underlying {$d}");
        }
    }

    public function test_aw_s30_weekday_resolution_is_untouched_by_the_monthly_foundation(): void
    {
        [$person, $rel] = $this->emp();
        $home = $this->createUnit();
        $dest = $this->createUnit();
        $this->recordPlacement($rel, $home, '2026-01-15');
        app(RecordWorkSchedulePeriod::class)->handle($rel, '2026-01-01', self::SUN_THU);
        app(RecordPartialSecondmentPeriod::class)->handle($rel->refresh(), $dest, '2026-11-01', null, ['SUNDAY']);

        $before = DB::table('hr.partial_secondment_periods')->count();
        $this->month([$person->id]);
        $this->assertSame($before, DB::table('hr.partial_secondment_periods')->count());
        $this->assertSame($dest->id, app(ResolveWeekdayActualWorkplaceAsOf::class)($rel->refresh(), '2026-11-08', 'SUNDAY')->organizationalUnitId());
    }

    public function test_ax_s32_derived_on_duty_stays_read_time_and_the_query_writes_nothing(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-09-27', '2026-11-05');
        $tables = ['hr.employment_status_periods', 'hr.employment_relationships', 'hr.organizational_placement_periods', 'hr.full_secondment_periods',
            'hr.workplace_assignment_periods', 'hr.partial_secondment_periods', 'hr.work_schedule_periods', 'hr.person_qualifications',
            'hr.return_intention_periods', 'hr.persons', 'audit.audit_entries', 'automation.movement_expiry_followups'];
        $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), $tables);
        $before = $counts();

        $this->month([$person->id]);
        $this->month();

        $this->assertSame($before, $counts(), 'a monthly read creates no row and no audit entry');
        $this->assertSame(1, DB::table('hr.employment_status_periods')->where('employment_relationship_id', $rel->id)->count());
    }

    public function test_ay_s34_return_intention_is_independent_of_duty_and_status_segments(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'traveling', '2026-09-27');
        $before = json_encode($this->month([$person->id]));

        app(RecordReturnIntention::class)->handle($rel->refresh(), 'WANTS_TO_RETURN', '2026-11-05', null);

        $this->assertSame($before, json_encode($this->month([$person->id])), 'an intention never becomes a status, a reason or a duty state');
        $this->assertSame(Duty::NO_ON_DUTY, $this->row($person)->dutyClassification);
    }

    public function test_az_s35_end_of_relationship_consequences_are_reflected_without_reinterpretation(): void
    {
        [$person, $rel] = $this->emp();
        $this->recStatus($person, $rel, 'on_duty', '2026-09-27');
        $this->recStatus($person, $rel, 'unpaid_leave', '2026-11-10', '2026-12-20'); // extends past the end
        $this->end($person, $rel, '2026-11-20');                                   // S35/S32: truncated at the end

        $s = $this->seg($person);
        $this->assertSame('2026-11-20', $s->clippedTo);
        $this->assertSame([
            ['2026-11-01', '2026-11-10', Seg::EXPLICIT, 'on_duty'],
            ['2026-11-10', '2026-11-20', Seg::EXPLICIT, 'unpaid_leave'],
        ], $this->statusView($s), 'no derived on_duty and no status after the relationship end');
    }

    public function test_ba_s36_qualifications_match_the_s27_exposure_exactly(): void
    {
        [$person, $rel] = $this->emp();
        $this->qualification($person, $this->createSyntheticAcademicDegree()->id, $this->createSyntheticQualificationType()->id, '2026-03-02 00:00:00+00');
        $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, '2026-03-01 00:00:00+00');
        DB::table('hr.persons')->where('id', $person->id)->update(['birth_date' => '1990-05-17']);

        $s27 = app(ListReportingPopulationAsOf::class)('2026-11-15', [$rel->id])[0];
        $row = $this->row($person);

        $this->assertSame($s27->qualifications, $row->qualifications);
        $this->assertSame($s27->birthDate, $row->birthDate);
        $this->assertSame($s27->genderId, $row->genderId);
    }

    // ------------------------------------------------------------------------------------------
    // Query safety
    // ------------------------------------------------------------------------------------------

    public function test_the_query_count_is_constant_and_independent_of_population_size(): void
    {
        $build = function (int $people): array {
            $ids = [];
            for ($i = 0; $i < $people; $i++) {
                [$person, $rel] = $this->emp('2026-01-01');
                $this->recordPlacement($rel, $this->createUnit(), '2026-01-15');
                $this->recStatus($person, $rel, 'on_duty', '2026-09-27');
                $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, '2026-03-01 00:00:00+00');
                $ids[] = $person->id;
            }

            return $ids;
        };
        $count = function (array $ids): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $this->month($ids);

            return $queries;
        };

        $small = $count($build(2));
        $large = $count($build(12));

        $this->assertSame(8, $small);
        $this->assertSame($small, $large, 'no N+1: the statement count does not grow with the population');
    }

    public function test_an_empty_restriction_and_an_empty_month_return_an_empty_population(): void
    {
        $this->assertSame([], $this->month([])->persons);
        $this->assertSame([], $this->month(null, '1990-01-01')->persons);
    }

    public function test_persons_and_nested_collections_have_a_deterministic_order(): void
    {
        $people = [];
        for ($i = 0; $i < 4; $i++) {
            [$person, $rel] = $this->emp('2026-01-01', 'contract');
            $this->end($person, $rel, '2026-11-10');
            $this->createEmploymentRelationship($person, 'contract', null, '2026-11-10');
            $people[] = $person->id;
        }

        $first = json_encode($this->month($people));
        $this->assertSame($first, json_encode($this->month(array_reverse($people))), 'independent of the restriction order');
        $this->assertSame($first, json_encode($this->month($people)));
        $ids = array_column($this->month($people)->persons, 'personId');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'ordered by person id');
    }
}
