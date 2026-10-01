<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\MonthInterval;
use App\Modules\HumanResources\Domain\MonthlyDimensionSegmentation;
use App\Modules\HumanResources\Domain\MonthlyDutyClassification;
use App\Modules\HumanResources\Domain\OrganizationalHierarchyPaths;
use App\Modules\HumanResources\Domain\PartialSecondmentOccurrences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * R2 Administrative / Job Title / Gender / Actual Work report (docs/administrative-report-foundation-specification.md). INTERNAL,
 * READ-ONLY. Pipeline: the canonical S37 monthly population is computed ONCE; its HAS_ON_DUTY Persons are kept (NO_ON_DUTY is
 * excluded; INDETERMINATE is excluded from the population and exposed only as data quality); S40's additive fromPopulation enriches
 * exactly those Persons (job-title segments with the as-of administrator classification); the R2-specific enrichment adds the
 * organizational placement segments and hierarchy, the actual-workplace occurrences and the Person identity/gender. The resulting
 * canonical records are the ONLY source of every official section (AdministrativeSections). No population engine of its own.
 *
 * Query strategy: a CONSTANT 18 statements, independent of population size — S37 (8), S40 enrichment (7) and three R2 batches:
 * Person identity + gender label (included and indeterminate Persons), organizational placement periods of the included
 * relationships, and the organizational hierarchy (one recursive CTE over every referenced unit and its ancestors). No per-Person,
 * per-relationship, per-workplace, per-title or per-node query.
 */
final class BuildAdministrativeReportResult
{
    public function __construct(
        private readonly ListMonthlyReportingPopulation $population,
        private readonly ListMonthlyWorkforceDimensions $dimensions,
    ) {}

    public function __invoke(string|Carbon $monthStart): AdministrativeReportResult
    {
        $month = MonthInterval::of($monthStart);
        $monthEnd = Carbon::createFromFormat('!Y-m-d', $month->next)->subDay()->toDateString();

        $canonical = ($this->population)($month->start); // S37, exactly once

        $included = [];
        $indeterminate = [];
        $noOnDuty = 0;
        foreach ($canonical->persons as $person) {
            match ($person->dutyClassification) {
                MonthlyDutyClassification::HAS_ON_DUTY => $included[] = $person,
                MonthlyDutyClassification::INDETERMINATE => $indeterminate[] = $person,
                default => $noOnDuty++,
            };
        }
        $population = ['has_on_duty' => count($included), 'no_on_duty_excluded' => $noOnDuty, 'indeterminate_excluded' => count($indeterminate)];

        $consideredIds = array_map(fn (MonthlyReportingPersonRow $row) => $row->personId, array_merge($included, $indeterminate));
        if ($consideredIds === []) {
            return new AdministrativeReportResult($month->start, $month->next, $monthEnd, 0, $population, AdministrativeSections::build([], [], [], []), []);
        }

        $identity = [];
        foreach (DB::select(
            'SELECT p.id, p.national_id, p.full_name_ar, p.gender_id, g.code AS gender_code, g.name_ar AS gender_name_ar, g.name_en AS gender_name_en
             FROM hr.persons p LEFT JOIN ref.genders g ON g.id = p.gender_id
             WHERE p.id = ANY(CAST(? AS uuid[]))',
            ['{'.implode(',', $consideredIds).'}'],
        ) as $row) {
            $identity[$row->id] = $row;
        }

        $indeterminateRecords = array_map(fn (MonthlyReportingPersonRow $row) => ['person_id' => $row->personId, 'national_id' => $identity[$row->personId]->national_id, 'full_name_ar' => $identity[$row->personId]->full_name_ar], $indeterminate);

        if ($included === []) {
            return new AdministrativeReportResult($month->start, $month->next, $monthEnd, 0, $population, AdministrativeSections::build([], $indeterminateRecords, [], []), []);
        }

        $filtered = new MonthlyReportingPopulation($canonical->monthStart, $canonical->nextMonthStart, $canonical->qualificationSemantics, $included);
        $enriched = $this->dimensions->fromPopulation($filtered); // S40 enrichment only, for the HAS_ON_DUTY Persons

        $relationshipIds = [];
        foreach ($included as $person) {
            foreach ($person->relationships as $segment) {
                $relationshipIds[] = $segment->employmentRelationshipId;
            }
        }

        $placements = [];
        $unitIds = [];
        foreach (DB::select(
            'SELECT pp.id, pp.employment_relationship_id, pp.organizational_unit_id, pp.effective_from, pp.effective_to
             FROM hr.organizational_placement_periods pp
             WHERE pp.effective_from < ? AND (pp.effective_to IS NULL OR pp.effective_to > ?) AND pp.employment_relationship_id = ANY(CAST(? AS uuid[]))
             ORDER BY pp.employment_relationship_id, pp.effective_from, pp.id',
            [$month->next, $month->start, '{'.implode(',', $relationshipIds).'}'],
        ) as $row) {
            $placements[$row->employment_relationship_id][] = (array) $row;
            $unitIds[$row->organizational_unit_id] = true;
        }
        foreach ($included as $person) {
            foreach ($person->relationships as $segment) {
                foreach ($segment->workplaceSegments as $workplace) {
                    foreach ([$workplace['organizational_unit_id'], $workplace['underlying_organizational_unit_id']] as $unitId) {
                        if ($unitId !== null) {
                            $unitIds[$unitId] = true;
                        }
                    }
                    foreach ($workplace['partial_allocations'] as $allocation) {
                        $unitIds[$allocation['organizational_unit_id']] = true;
                    }
                }
            }
        }

        $units = [];
        foreach (DB::select(
            'WITH RECURSIVE tree AS (
                 SELECT u.id, u.parent_id, u.name, u.is_active FROM org.organizational_units u WHERE u.id = ANY(CAST(? AS uuid[]))
                 UNION
                 SELECT a.id, a.parent_id, a.name, a.is_active FROM org.organizational_units a JOIN tree ON a.id = tree.parent_id
             )
             SELECT id, parent_id, name, is_active FROM tree ORDER BY id',
            ['{'.implode(',', array_keys($unitIds)).'}'],
        ) as $row) {
            $units[$row->id] = ['id' => $row->id, 'parent_id' => $row->parent_id, 'name' => $row->name, 'is_active' => (bool) $row->is_active];
        }
        foreach ($units as $id => $unit) {
            $units[$id]['path'] = OrganizationalHierarchyPaths::path($id, $units);
        }

        $dimensionsByRelationship = [];
        foreach ($enriched->persons as $dimensionRow) {
            foreach ($dimensionRow->relationships as $relationship) {
                $dimensionsByRelationship[$relationship->employmentRelationshipId] = $relationship;
            }
        }

        $records = [];
        foreach ($included as $person) {
            $id = $identity[$person->personId];
            $relationships = [];
            foreach ($person->relationships as $segment) {
                $relationships[] = $this->relationship($segment, $dimensionsByRelationship[$segment->employmentRelationshipId], $placements[$segment->employmentRelationshipId] ?? []);
            }

            $gender = $id->gender_id === null
                ? ['state' => 'NOT_RECORDED', 'code' => null, 'id' => null, 'name_ar' => null, 'name_en' => null]
                : ['state' => 'RECORDED', 'code' => strtoupper($id->gender_code), 'id' => $id->gender_id, 'name_ar' => $id->gender_name_ar, 'name_en' => $id->gender_name_en];

            $records[] = new AdministrativeReportPersonRecord(
                personId: $person->personId,
                nationalId: $id->national_id,
                fullNameAr: $id->full_name_ar,
                gender: $gender,
                relationships: $relationships,
                dataQuality: AdministrativeSections::personDataQuality($gender, $relationships),
            );
        }

        return new AdministrativeReportResult(
            $month->start, $month->next, $monthEnd, count($records), $population,
            AdministrativeSections::build($records, $indeterminateRecords, $units, $population),
            $records,
        );
    }

    /**
     * One relationship of the month: the S40 job-title segments, the independent organizational placement segments and the
     * actual-workplace occurrences — all clipped to the S37 relationship window, none collapsed.
     *
     * @param  list<array<string, mixed>>  $placementPeriods
     * @return array<string, mixed>
     */
    private function relationship(MonthlyRelationshipSegment $segment, MonthlyDimensionRelationship $dimension, array $placementPeriods): array
    {
        $from = $segment->clippedFrom;
        $to = $segment->clippedTo;

        $jobTitles = [];
        foreach ($dimension->jobTitleSegments as $s) {
            $classification = match (true) {
                $s['state'] !== 'RESOLVED' => 'NOT_RECORDED',
                ($s['administrator_classification']['state'] ?? null) !== 'RESOLVED' => 'UNMAPPED',
                $s['administrator_classification']['is_administrator'] === true => 'ADMINISTRATOR',
                default => 'NOT_ADMINISTRATOR',
            };
            $jobTitles[] = [
                'from' => $s['from'], 'to' => $s['to'], 'state' => $s['state'],
                'job_title' => $s['value'] === null ? null : ['id' => $s['value']['id'], 'code' => $s['value']['code'], 'name_ar' => $s['value']['name_ar'], 'name_en' => $s['value']['name_en']],
                'start_knowledge_state' => $s['start_knowledge_state'],
                'administrator_classification' => $classification,
            ];
        }

        $placementSegments = [];
        foreach (MonthlyDimensionSegmentation::segments($from, $to, $placementPeriods, true) as $s) {
            $placementSegments[] = [
                'from' => $s['from'], 'to' => $s['to'], 'state' => $s['state'],
                'organizational_unit_id' => $s['period']['organizational_unit_id'] ?? null,
            ];
        }

        $workplaces = [];
        foreach ($segment->workplaceSegments as $w) {
            foreach ($this->workplaceOccurrences($w) as $occurrence) {
                $workplaces[] = $occurrence;
            }
        }

        return [
            'employment_relationship_id' => $segment->employmentRelationshipId,
            'from' => $from,
            'to' => $to,
            'job_title_segments' => $jobTitles,
            'organizational_placement_segments' => $placementSegments,
            'actual_workplace_segments' => $workplaces,
        ];
    }

    /**
     * The actual-workplace occurrences of one S37 workplace segment. A RESOLVED segment is one occurrence of its movement type; a
     * PARTIAL_ALLOCATION segment yields one occurrence per destination that has at least one applicable scheduled weekday inside
     * Report ∩ Movement period ∩ Weekdays (a destination with none yields nothing), plus the underlying placement workplace; an
     * UNRESOLVED or AMBIGUOUS segment is kept as a non-determinable occurrence — never guessed.
     *
     * @param  array<string, mixed>  $w
     * @return list<array<string, mixed>>
     */
    private function workplaceOccurrences(array $w): array
    {
        $base = ['from' => $w['from'], 'to' => $w['to']];
        $movement = fn (?string $source) => match ($source) {
            'placement' => 'PLACEMENT',
            'secondment' => 'FULL_SECONDMENT',
            'assignment' => 'WORKPLACE_ASSIGNMENT',
            'partial_secondment' => 'PARTIAL_SECONDMENT',
            default => 'UNKNOWN',
        };

        if ($w['state'] === 'RESOLVED') {
            return [$base + ['state' => 'RESOLVED', 'organizational_unit_id' => $w['organizational_unit_id'], 'movement_type' => $movement($w['source']), 'weekdays' => null, 'movement_since' => $w['since']]];
        }

        if ($w['state'] === 'PARTIAL_ALLOCATION') {
            $occurrences = [];
            foreach ($w['partial_allocations'] as $allocation) {
                $applicable = PartialSecondmentOccurrences::applicableWeekdays($w['from'], $w['to'], $allocation['effective_from'], $allocation['effective_to'], $allocation['weekdays']);
                if ($applicable === []) {
                    continue; // zero applicable scheduled occurrences: no workplace occurrence for this destination
                }
                $window = MonthInterval::intersect($allocation['effective_from'], $allocation['effective_to'], $w['from'], $w['to']);
                $occurrences[] = [
                    'from' => $window[0], 'to' => $window[1], 'state' => 'RESOLVED', 'organizational_unit_id' => $allocation['organizational_unit_id'],
                    'movement_type' => 'PARTIAL_SECONDMENT', 'weekdays' => $allocation['weekdays'], 'scheduled_weekdays_in_period' => $applicable,
                    'movement_period_id' => $allocation['period_id'],
                ];
            }
            if ($w['underlying_organizational_unit_id'] !== null) {
                $occurrences[] = $base + ['state' => 'RESOLVED', 'organizational_unit_id' => $w['underlying_organizational_unit_id'], 'movement_type' => 'PLACEMENT', 'weekdays' => null, 'underlying_of_partial_allocation' => true];
            }

            return $occurrences;
        }

        return [$base + [
            'state' => $w['state'], 'organizational_unit_id' => null, 'movement_type' => null, 'weekdays' => null,
            'competing_movements' => array_map(fn (array $m) => ['movement_type' => $movement($m['source']), 'organizational_unit_id' => $m['organizational_unit_id'], 'period_id' => $m['period_id'], 'effective_from' => $m['effective_from']], $w['competing_movements']),
        ]];
    }
}
