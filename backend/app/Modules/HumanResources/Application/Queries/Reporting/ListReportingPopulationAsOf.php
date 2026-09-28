<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The S27 reporting population read model (docs/reporting-as-of-foundation-specification.md
 * §S27.9/§S27.13, ADR-S27-001): every Employment Relationship relevant to ONE explicit business
 * date, with all implemented canonical facts resolved on that same date. LIVE QUERY — nothing is
 * materialized, cached, snapshotted or persisted.
 *
 * Population: relationships with effective_from <= date, excluding only those with a KNOWN end on
 * or before the date. UNKNOWN_LEGACY rows are kept and flagged (ReportingPopulationRow). No report
 * inclusion rule is applied here: status/behavior/workplace/classification facts are exposed so
 * each report applies its own frozen rule. Reappointments stay separate rows — each relationship's
 * facts come only from its own periods.
 *
 * Query strategy: ONE SQL statement, independent of population size (no N+1). Every dimension is a
 * LEFT JOIN with the same half-open predicate used by the single-relationship as-of readers
 * (effective_from <= d AND (effective_to IS NULL OR d < effective_to)). Each joined stream carries
 * a PostgreSQL EXCLUDE (no-overlap) constraint keyed on its owner, so at most one row per dimension
 * can match and relationships are never multiplied. S06 mappings (specialty→cadre,
 * job title→administrator, contract type→population) and status behaviors are resolved on the
 * same date; unmapped → null. Actual workplace goes through the shared CA-S27-02 decision table.
 */
final class ListReportingPopulationAsOf
{
    /**
     * @param  list<string>|null  $employmentRelationshipIds  optional restriction (null = all)
     * @return list<ReportingPopulationRow>
     */
    public function __invoke(string|Carbon $asOfDate, ?array $employmentRelationshipIds = null): array
    {
        $date = $asOfDate instanceof Carbon ? $asOfDate->toDateString() : $asOfDate;

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('as_of_date must be an explicit Y-m-d business date.');
        }

        $bindings = [$date];
        $restriction = '';
        if ($employmentRelationshipIds !== null) {
            if ($employmentRelationshipIds === []) {
                return [];
            }
            $restriction = ' AND r.id IN ('.implode(',', array_fill(0, count($employmentRelationshipIds), '?')).')';
            array_push($bindings, ...array_values($employmentRelationshipIds));
        }

        $rows = DB::select(<<<SQL
            WITH p AS (SELECT CAST(? AS date) AS d)
            SELECT
                r.id AS employment_relationship_id, r.person_id, r.employment_type_id, et.code AS employment_type_code,
                r.employee_number_scheme, r.effective_from AS relationship_effective_from,
                r.effective_to AS relationship_effective_to, r.end_knowledge_state AS relationship_end_knowledge_state,
                per.gender_id,
                sp.id AS status_period_id, sp.status_detail_id, sd.code AS status_detail_code,
                b.participates_in_active_workforce, b.is_ongoing_relationship, b.is_relationship_ending,
                b.is_terminal, b.allows_reappointment, b.counts_in_monthly_reporting,
                pl.id AS placement_id, pl.organizational_unit_id AS placement_unit_id, pl.effective_from AS placement_from,
                fs.id AS secondment_id, fs.organizational_unit_id AS secondment_unit_id, fs.effective_from AS secondment_from,
                wa.id AS assignment_id, wa.organizational_unit_id AS assignment_unit_id, wa.effective_from AS assignment_from,
                cat.employment_category_id, con.id AS contract_period_id, con.contract_type_id,
                jt.job_title_id, spc.specialty_id,
                cm.cadre_category_id, ja.is_administrator, pm.population_category_id
            FROM hr.employment_relationships r
            CROSS JOIN p
            JOIN hr.persons per ON per.id = r.person_id
            JOIN ref.employment_types et ON et.id = r.employment_type_id
            LEFT JOIN hr.employment_status_periods sp ON sp.employment_relationship_id = r.id
                AND sp.effective_from <= p.d AND (sp.effective_to IS NULL OR p.d < sp.effective_to)
            LEFT JOIN ref.employment_status_details sd ON sd.id = sp.status_detail_id
            LEFT JOIN ref.employment_status_detail_behaviors b ON b.status_detail_id = sp.status_detail_id
                AND b.effective_from <= p.d AND (b.effective_to IS NULL OR p.d < b.effective_to)
            LEFT JOIN hr.organizational_placement_periods pl ON pl.employment_relationship_id = r.id
                AND pl.effective_from <= p.d AND (pl.effective_to IS NULL OR p.d < pl.effective_to)
            LEFT JOIN hr.full_secondment_periods fs ON fs.employment_relationship_id = r.id
                AND fs.effective_from <= p.d AND (fs.effective_to IS NULL OR p.d < fs.effective_to)
            LEFT JOIN hr.workplace_assignment_periods wa ON wa.employment_relationship_id = r.id
                AND wa.effective_from <= p.d AND (wa.effective_to IS NULL OR p.d < wa.effective_to)
            LEFT JOIN hr.employment_category_periods cat ON cat.employment_relationship_id = r.id
                AND cat.effective_from <= p.d AND (cat.effective_to IS NULL OR p.d < cat.effective_to)
            LEFT JOIN hr.employment_contract_periods con ON con.employment_relationship_id = r.id
                AND con.effective_from <= p.d AND (con.effective_to IS NULL OR p.d < con.effective_to)
            LEFT JOIN hr.employment_job_title_periods jt ON jt.employment_relationship_id = r.id
                AND jt.effective_from <= p.d AND (jt.effective_to IS NULL OR p.d < jt.effective_to)
            LEFT JOIN hr.employment_specialty_periods spc ON spc.employment_relationship_id = r.id
                AND spc.effective_from <= p.d AND (spc.effective_to IS NULL OR p.d < spc.effective_to)
            LEFT JOIN ref.specialty_cadre_category_mappings cm ON cm.specialty_id = spc.specialty_id
                AND cm.effective_from <= p.d AND (cm.effective_to IS NULL OR p.d < cm.effective_to)
            LEFT JOIN ref.job_title_administrator_classifications ja ON ja.job_title_id = jt.job_title_id
                AND ja.effective_from <= p.d AND (ja.effective_to IS NULL OR p.d < ja.effective_to)
            LEFT JOIN ref.contract_type_population_mappings pm ON pm.contract_type_id = con.contract_type_id
                AND pm.effective_from <= p.d AND (pm.effective_to IS NULL OR p.d < pm.effective_to)
            WHERE r.effective_from <= p.d
              AND NOT (r.end_knowledge_state = 'KNOWN' AND r.effective_to <= p.d){$restriction}
            ORDER BY r.person_id, r.effective_from, r.id
            SQL, $bindings);

        return array_map(fn (object $row) => $this->row($date, $row), $rows);
    }

    private function row(string $date, object $r): ReportingPopulationRow
    {
        $fact = fn (?string $id, ?string $unit, ?string $from) => $id === null ? null : ['id' => $id, 'organizational_unit_id' => $unit, 'effective_from' => $from];
        $bool = fn ($value) => $value === null ? null : (bool) $value;

        return new ReportingPopulationRow(
            asOfDate: $date,
            personId: $r->person_id,
            employmentRelationshipId: $r->employment_relationship_id,
            employmentTypeId: $r->employment_type_id,
            employmentTypeCode: $r->employment_type_code,
            employeeNumberScheme: $r->employee_number_scheme,
            relationshipEffectiveFrom: $r->relationship_effective_from,
            relationshipEffectiveTo: $r->relationship_effective_to,
            relationshipEndKnowledgeState: $r->relationship_end_knowledge_state,
            relationshipAsOfState: $r->relationship_end_knowledge_state === 'UNKNOWN_LEGACY' ? 'END_UNKNOWN_LEGACY' : 'EFFECTIVE',
            genderId: $r->gender_id,
            statusPeriodId: $r->status_period_id,
            statusDetailId: $r->status_detail_id,
            statusDetailCode: $r->status_detail_code,
            participatesInActiveWorkforce: $bool($r->participates_in_active_workforce),
            isOngoingRelationship: $bool($r->is_ongoing_relationship),
            isRelationshipEnding: $bool($r->is_relationship_ending),
            isTerminal: $bool($r->is_terminal),
            allowsReappointment: $bool($r->allows_reappointment),
            countsInMonthlyReporting: $bool($r->counts_in_monthly_reporting),
            placementOrganizationalUnitId: $r->placement_unit_id,
            actualWorkplace: ActualWorkplaceAsOf::fromEffectiveFacts(
                $fact($r->placement_id, $r->placement_unit_id, $r->placement_from),
                $fact($r->secondment_id, $r->secondment_unit_id, $r->secondment_from),
                $fact($r->assignment_id, $r->assignment_unit_id, $r->assignment_from),
            ),
            employmentCategoryId: $r->employment_category_id,
            contractPeriodId: $r->contract_period_id,
            contractTypeId: $r->contract_type_id,
            jobTitleId: $r->job_title_id,
            specialtyId: $r->specialty_id,
            cadreCategoryId: $r->cadre_category_id,
            isAdministrator: $bool($r->is_administrator),
            populationCategoryId: $r->population_category_id,
        );
    }
}
