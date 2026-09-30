<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\EndEmploymentRelationship;
use App\Modules\HumanResources\Application\Queries\Reporting\ListReportingPopulationAsOf;
use App\Modules\HumanResources\Application\Queries\Reporting\ReportingPopulationRow;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S36 — Reporting Read-Model Completeness: the S27 canonical row now also exposes the Person's existing
 * birth_date (a DATE fact, never an age) and the Person's CURRENT recorded qualifications as a nested,
 * deterministically ordered collection. Cardinality stays one row per effective EmploymentRelationship.
 * Qualifications carry no dates, so they are NOT reconstructed as of the requested date. Synthetic data only.
 */
class ReportingPersonFactsExposureTest extends HumanResourcesTestCase
{
    private function population(string $date, ?array $ids = null): array
    {
        return app(ListReportingPopulationAsOf::class)($date, $ids);
    }

    private function rowFor(string $relationshipId, string $date): ReportingPopulationRow
    {
        $rows = $this->population($date, [$relationshipId]);
        $this->assertCount(1, $rows, 'exactly one canonical row for the effective relationship');

        return $rows[0];
    }

    private function employee(?string $birthDate = null, string $from = '2026-01-01'): array
    {
        $person = $this->createPersonRecord();
        DB::table('hr.persons')->where('id', $person->id)->update(['birth_date' => $birthDate]);

        return [$person, $this->createEmploymentRelationship($person, 'permanent', null, $from)];
    }

    /** Inserts a person qualification with an explicit id/created_at so ordering can be proven. */
    private function qualification(Person $person, ?string $degreeId, ?string $typeId, string $createdAt, ?string $id = null): string
    {
        $id ??= (string) Str::uuid7();
        DB::table('hr.person_qualifications')->insert([
            'id' => $id, 'person_id' => $person->id, 'academic_degree_id' => $degreeId,
            'qualification_type_id' => $typeId, 'created_at' => $createdAt,
        ]);

        return $id;
    }

    // ---- A / B / C: zero, one, multiple -------------------------------------------------------

    public function test_a_person_without_qualifications_stays_in_the_population_with_an_empty_collection(): void
    {
        [, $rel] = $this->employee();

        $row = $this->rowFor($rel->id, '2026-06-01');

        $this->assertSame([], $row->qualifications);
        $this->assertCount(1, $this->population('2026-06-01', [$rel->id]));
    }

    public function test_one_qualification_is_exposed_with_ids_codes_and_names(): void
    {
        [$person, $rel] = $this->employee();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();
        $id = $this->qualification($person, $degree->id, $type->id, '2026-03-01 00:00:00+00');

        $row = $this->rowFor($rel->id, '2026-06-01');

        $this->assertSame([[
            'id' => $id,
            'academic_degree_id' => $degree->id, 'academic_degree_code' => $degree->code,
            'academic_degree_name_ar' => $degree->name_ar, 'academic_degree_name_en' => $degree->name_en,
            'qualification_type_id' => $type->id, 'qualification_type_code' => $type->code,
            'qualification_type_name_ar' => $type->name_ar, 'qualification_type_name_en' => $type->name_en,
        ]], $row->qualifications);
    }

    public function test_multiple_qualifications_never_multiply_the_population_row(): void
    {
        [$person, $rel] = $this->employee();
        foreach (range(1, 4) as $i) {
            $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, "2026-03-0{$i} 00:00:00+00");
        }

        $rows = $this->population('2026-06-01', [$rel->id]);

        $this->assertCount(1, $rows, 'one row per relationship, not per qualification');
        $this->assertCount(4, $rows[0]->qualifications);
    }

    // ---- D: deterministic order (created_at ASC, then id ASC) ---------------------------------

    public function test_the_collection_order_is_created_at_then_id_and_is_stable_across_reads(): void
    {
        [$person, $rel] = $this->employee();
        // Eight rows whose created_at / id / insertion / degree-creation orders all disagree with one another,
        // including created_at ties that only the id can break.
        $spec = [
            ['2026-03-05', '00000000-0000-7000-8000-00000000000a'], ['2026-03-01', '00000000-0000-7000-8000-000000000009'],
            ['2026-03-04', '00000000-0000-7000-8000-000000000001'], ['2026-03-01', '00000000-0000-7000-8000-000000000002'],
            ['2026-03-03', '00000000-0000-7000-8000-000000000008'], ['2026-03-05', '00000000-0000-7000-8000-000000000003'],
            ['2026-03-02', '00000000-0000-7000-8000-000000000007'], ['2026-03-04', '00000000-0000-7000-8000-000000000005'],
        ];
        foreach ($spec as [$createdAt, $id]) {
            $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, "{$createdAt} 00:00:00+00", $id);
        }
        usort($spec, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
        $expected = array_column($spec, 1);

        foreach ([1, 2, 3] as $read) {
            $this->assertSame($expected, array_column($this->rowFor($rel->id, '2026-06-01')->qualifications, 'id'), "read #{$read}");
        }
    }

    // ---- E / F: two persons, cardinality ------------------------------------------------------

    public function test_each_relationship_receives_only_its_own_persons_qualifications(): void
    {
        [$alice, $relA] = $this->employee();
        [$bob, $relB] = $this->employee();
        $a1 = $this->qualification($alice, $this->createSyntheticAcademicDegree()->id, null, '2026-03-01 00:00:00+00');
        $b1 = $this->qualification($bob, null, $this->createSyntheticQualificationType()->id, '2026-03-01 00:00:00+00');
        $b2 = $this->qualification($bob, $this->createSyntheticAcademicDegree()->id, null, '2026-03-02 00:00:00+00');

        $this->assertSame([$a1], array_column($this->rowFor($relA->id, '2026-06-01')->qualifications, 'id'));
        $this->assertSame([$b1, $b2], array_column($this->rowFor($relB->id, '2026-06-01')->qualifications, 'id'));
    }

    public function test_population_row_count_equals_effective_relationships_not_qualification_count(): void
    {
        [, $none] = $this->employee();
        [$one, $relOne] = $this->employee();
        [$many, $relMany] = $this->employee();
        $this->qualification($one, $this->createSyntheticAcademicDegree()->id, null, '2026-03-01 00:00:00+00');
        foreach (range(1, 5) as $i) {
            $this->qualification($many, null, $this->createSyntheticQualificationType()->id, "2026-03-0{$i} 00:00:00+00");
        }

        $rows = $this->population('2026-06-01', [$none->id, $relOne->id, $relMany->id]);

        $this->assertCount(3, $rows, 'three effective relationships, six qualifications');
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r->employmentRelationshipId] = count($r->qualifications);
        }
        $this->assertSame([$none->id => 0, $relOne->id => 1, $relMany->id => 5], $counts);
    }

    // ---- G: nullable sides --------------------------------------------------------------------

    public function test_degree_only_type_only_and_both_preserve_nulls(): void
    {
        [$person, $rel] = $this->employee();
        $degree = $this->createSyntheticAcademicDegree();
        $type = $this->createSyntheticQualificationType();
        $degreeOnly = $this->qualification($person, $degree->id, null, '2026-03-01 00:00:00+00');
        $typeOnly = $this->qualification($person, null, $type->id, '2026-03-02 00:00:00+00');
        $both = $this->qualification($person, $this->createSyntheticAcademicDegree()->id, $this->createSyntheticQualificationType()->id, '2026-03-03 00:00:00+00');

        $byId = collect($this->rowFor($rel->id, '2026-06-01')->qualifications)->keyBy('id');

        $this->assertSame([$degree->id, $degree->code, null, null, null], [$byId[$degreeOnly]['academic_degree_id'], $byId[$degreeOnly]['academic_degree_code'], $byId[$degreeOnly]['qualification_type_id'], $byId[$degreeOnly]['qualification_type_code'], $byId[$degreeOnly]['qualification_type_name_ar']]);
        $this->assertSame([null, null, null, $type->id, $type->code], [$byId[$typeOnly]['academic_degree_id'], $byId[$typeOnly]['academic_degree_code'], $byId[$typeOnly]['academic_degree_name_ar'], $byId[$typeOnly]['qualification_type_id'], $byId[$typeOnly]['qualification_type_code']]);
        $this->assertNotNull($byId[$both]['academic_degree_id']);
        $this->assertNotNull($byId[$both]['qualification_type_id']);
        $this->assertSame(['id', 'academic_degree_id', 'academic_degree_code', 'academic_degree_name_ar', 'academic_degree_name_en', 'qualification_type_id', 'qualification_type_code', 'qualification_type_name_ar', 'qualification_type_name_en'], array_keys($byId[$both]), 'no invented fields (no rank, primary, highest, level, institution, dates)');
    }

    public function test_an_inactive_catalog_value_does_not_erase_a_recorded_qualification(): void
    {
        [$person, $rel] = $this->employee();
        $degree = $this->createSyntheticAcademicDegree();
        $id = $this->qualification($person, $degree->id, null, '2026-03-01 00:00:00+00');
        DB::table('ref.academic_degrees')->where('id', $degree->id)->update(['is_active' => false]);

        $this->assertSame([$id], array_column($this->rowFor($rel->id, '2026-06-01')->qualifications, 'id'));
    }

    // ---- H / I: birth date --------------------------------------------------------------------

    public function test_a_null_birth_date_stays_null_and_no_age_exists(): void
    {
        [, $rel] = $this->employee(null);

        $row = $this->rowFor($rel->id, '2026-06-01');

        $this->assertNull($row->birthDate);
        $this->assertFalse(property_exists($row, 'age'));
        $this->assertFalse(property_exists($row, 'ageYears'));
    }

    public function test_birth_date_is_exposed_exactly_as_the_person_date_fact(): void
    {
        foreach (['1990-02-28', '2000-02-29', '1985-12-31', '2001-01-01'] as $date) {
            [, $rel] = $this->employee($date);

            $this->assertSame($date, $this->rowFor($rel->id, '2026-06-01')->birthDate, 'no timezone transformation, no reformatting');
        }
    }

    // ---- J: historical as-of contract (intentional limitation) --------------------------------

    public function test_a_historical_row_receives_the_current_recorded_person_qualifications_not_a_reconstructed_set(): void
    {
        [$person, $rel] = $this->employee('1980-05-05', '2020-01-01');
        // Recorded "today" (2026) — and one whose created_at is even later than the requested as-of date and later than now.
        $recorded = $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, '2026-09-30 00:00:00+00');
        $future = $this->qualification($person, null, $this->createSyntheticQualificationType()->id, '2031-01-01 00:00:00+00');

        $historical = $this->rowFor($rel->id, '2021-01-01');

        $this->assertSame([$recorded, $future], array_column($historical->qualifications, 'id'), 'created_at is NOT an effective date: nothing is filtered by it');
        $this->assertSame('1980-05-05', $historical->birthDate);
    }

    // ---- K: reappointment ---------------------------------------------------------------------

    public function test_reappointed_person_rows_keep_relationship_facts_apart_but_share_person_facts(): void
    {
        [$person, $first] = $this->employee('1975-07-07', '2020-01-01');
        $q = $this->qualification($person, $this->createSyntheticAcademicDegree()->id, null, '2026-03-01 00:00:00+00');
        EndEmploymentRelationship::class;
        app(EndEmploymentRelationship::class)->handle($person, $first->refresh(), $first->version, '2021-01-01', false);
        $second = $this->createEmploymentRelationship($person, 'contract', null, '2022-01-01');

        $rowFirst = $this->rowFor($first->id, '2020-06-01');
        $rowSecond = $this->rowFor($second->id, '2022-06-01');

        $this->assertNotSame($rowFirst->employmentRelationshipId, $rowSecond->employmentRelationshipId);
        $this->assertSame(['permanent', 'contract'], [$rowFirst->employmentTypeCode, $rowSecond->employmentTypeCode], 'relationship-owned facts stay independent');
        $this->assertSame([$rowFirst->birthDate, $rowFirst->qualifications], [$rowSecond->birthDate, $rowSecond->qualifications], 'Person-level facts are shared');
        $this->assertSame([$q], array_column($rowSecond->qualifications, 'id'));
        $this->assertSame([], $this->population('2021-06-01', [$first->id, $second->id]), 'a person has no effective relationship in the gap');
    }
}
