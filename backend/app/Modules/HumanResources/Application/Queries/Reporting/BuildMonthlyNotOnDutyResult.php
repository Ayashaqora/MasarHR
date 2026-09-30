<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\MonthlyDutyClassification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S39 REPORT-3 — Monthly Not-On-Duty (docs/monthly-not-on-duty-report-foundation-specification.md). A PROJECTION over the
 * S37 canonical monthly dataset, never a second population engine:
 *
 *   month → ONE ListMonthlyReportingPopulation call → filter Person rows by dutyClassification
 *         → NO_ON_DUTY rows / INDETERMINATE count / HAS_ON_DUTY excluded
 *         → ONE batched hr.persons identity lookup (keyed by the already-filtered Person ids)
 *         → ONE batched ref.employment_status_details label lookup → one result.
 *
 * This class runs no employment, relationship or status population logic of its own and does not re-derive any S37
 * classification. Membership is decided ONLY by the S37 person-level classification; the identity and label lookups add
 * display facts to rows that already exist and can neither add nor remove one. INDETERMINATE is never treated as NO_ON_DUTY:
 * it is counted as data-quality metadata only. Reasons stay per relationship (no Person-level reason exists). The full name is
 * the stored hr.persons.full_name_ar exactly as stored (S24: a single name field; a legacy NULL stays null).
 *
 * Constant statement count independent of population size: the 8 S37 statements + 1 identity + 1 label statement.
 */
final class BuildMonthlyNotOnDutyResult
{
    public function __construct(private readonly ListMonthlyReportingPopulation $population) {}

    public function __invoke(string|Carbon $monthStart): MonthlyNotOnDutyResult
    {
        // The ONE canonical S37 computation for this report (validates the explicit first-day month itself).
        $canonical = ($this->population)($monthStart);

        $included = [];
        $indeterminate = 0;
        foreach ($canonical->persons as $person) {
            if ($person->dutyClassification === MonthlyDutyClassification::NO_ON_DUTY) {
                $included[] = $person;
            } elseif ($person->dutyClassification === MonthlyDutyClassification::INDETERMINATE) {
                $indeterminate++;
            }
        }

        $identity = $this->identityOf(array_map(fn (MonthlyReportingPersonRow $p) => $p->personId, $included));
        $labels = $this->statusLabelsOf($included);

        $rows = array_map(fn (MonthlyReportingPersonRow $p) => [
            'person' => [
                'person_id' => $p->personId,
                'national_id' => $identity[$p->personId]['national_id'],
                'full_name' => $identity[$p->personId]['full_name'],
                'gender_id' => $p->genderId,
                'birth_date' => $p->birthDate,
                'qualifications' => $p->qualifications,
                'qualification_semantics' => $p->qualificationSemantics,
            ],
            'duty_classification' => $p->dutyClassification,
            'relationships' => $p->relationships,
        ], $included);

        return new MonthlyNotOnDutyResult($canonical->monthStart, $canonical->nextMonthStart, count($rows), $indeterminate, $rows, $labels);
    }

    /**
     * ONE batched lookup of the existing Person identity facts for the REPORT-3 Person ids (a single array binding: no per-Person
     * query and no bind-parameter ceiling). Display facts only.
     *
     * @param  list<string>  $personIds
     * @return array<string, array{national_id: string, full_name: ?string}>
     */
    private function identityOf(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $identity = [];
        foreach (DB::select('SELECT id, national_id, full_name_ar FROM hr.persons WHERE id = ANY (CAST(? AS uuid[]))', ['{'.implode(',', $personIds).'}']) as $row) {
            $identity[$row->id] = ['national_id' => $row->national_id, 'full_name' => $row->full_name_ar];
        }

        return $identity;
    }

    /**
     * ONE batched label lookup in the existing ref.employment_status_details for every status detail id that appears in a
     * reason or an explicit status segment of the included rows.
     *
     * @param  list<MonthlyReportingPersonRow>  $included
     * @return array<string, array{code: string, name_ar: string, name_en: ?string}>
     */
    private function statusLabelsOf(array $included): array
    {
        $ids = [];
        foreach ($included as $person) {
            foreach ($person->relationships as $segment) {
                foreach ([$segment->lastNonOnDutyReason, $segment->relationshipEndReason] as $reason) {
                    if ($reason !== null && $reason['status_detail_id'] !== null) {
                        $ids[$reason['status_detail_id']] = true;
                    }
                }
                foreach ($segment->statusSegments as $status) {
                    if ($status['status_detail_id'] !== null) {
                        $ids[$status['status_detail_id']] = true;
                    }
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $labels = [];
        foreach (DB::select('SELECT id, code, name_ar, name_en FROM ref.employment_status_details WHERE id = ANY (CAST(? AS uuid[]))', ['{'.implode(',', array_keys($ids)).'}']) as $row) {
            $labels[$row->id] = ['code' => $row->code, 'name_ar' => $row->name_ar, 'name_en' => $row->name_en];
        }

        return $labels;
    }
}
