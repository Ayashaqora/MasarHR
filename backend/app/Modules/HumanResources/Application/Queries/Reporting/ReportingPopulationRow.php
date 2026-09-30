<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

use App\Modules\HumanResources\Domain\ActualWorkplaceAsOf;

/**
 * One Employment Relationship's canonical facts resolved on ONE explicit business date
 * (docs/reporting-as-of-foundation-specification.md §S27.9, ADR-S27-001). An immutable,
 * never-persisted internal read-model row: it carries resolved facts, never a report inclusion
 * decision — each future report applies its own frozen population rule to these fields.
 *
 * Every nullable field means UNKNOWN / UNRESOLVED on that date (no period covers it, or no S06
 * mapping row covers it). Nulls are never replaced by "Other", a fallback, or a synthetic value,
 * and a null dimension never removes the row.
 */
final class ReportingPopulationRow
{
    /**
     * relationshipAsOfState:
     *  - 'EFFECTIVE' — the relationship's recorded interval covers the date (NOT_APPLICABLE open,
     *    or a KNOWN end strictly after the date).
     *  - 'END_UNKNOWN_LEGACY' — an S09 UNKNOWN_LEGACY row (known to have existed, understood not
     *    to be current, historical end unknown) that started on or before the date: whether it was
     *    still in force on the date cannot be determined. Exposed unchanged, never reinterpreted.
     */
    public function __construct(
        public readonly string $asOfDate,
        public readonly string $personId,
        public readonly string $employmentRelationshipId,
        public readonly string $employmentTypeId,
        public readonly string $employmentTypeCode,
        public readonly string $employeeNumberScheme,
        public readonly string $relationshipEffectiveFrom,
        public readonly ?string $relationshipEffectiveTo,
        public readonly string $relationshipEndKnowledgeState,
        public readonly string $relationshipAsOfState,
        public readonly ?string $genderId,
        public readonly ?string $statusPeriodId,
        public readonly ?string $statusDetailId,
        public readonly ?string $statusDetailCode,
        public readonly ?bool $participatesInActiveWorkforce,
        public readonly ?bool $isOngoingRelationship,
        public readonly ?bool $isRelationshipEnding,
        public readonly ?bool $isTerminal,
        public readonly ?bool $allowsReappointment,
        public readonly ?bool $countsInMonthlyReporting,
        public readonly ?string $placementOrganizationalUnitId,
        public readonly ActualWorkplaceAsOf $actualWorkplace,
        public readonly ?string $employmentCategoryId,
        public readonly ?string $contractPeriodId,
        public readonly ?string $contractTypeId,
        public readonly ?string $jobTitleId,
        public readonly ?string $specialtyId,
        public readonly ?string $cadreCategoryId,
        public readonly ?bool $isAdministrator,
        public readonly ?string $populationCategoryId,
        /** S32: true when the status is the read-time DERIVED on_duty after an expired bounded status (no persisted row). */
        public readonly bool $statusDerived = false,
        public readonly ?string $derivedFromStatusPeriodId = null,
        /** S34: independent Return Intention as of the date (WANTS_TO_RETURN / DOES_NOT_WANT_TO_RETURN); null = NOT RECORDED. Never a status. */
        public readonly ?string $returnIntentionPeriodId = null,
        public readonly ?string $returnIntention = null,
        /** S36: the Person's existing birth_date (YYYY-MM-DD, DATE fact); null when unrecorded. Never an age. */
        public readonly ?string $birthDate = null,
        /**
         * S36: the Person's CURRENT recorded qualifications, ordered by (created_at, id) — a technical order,
         * not a ranking. Each entry: id, academic_degree_id/code/name_ar/name_en and qualification_type_id/code/
         * name_ar/name_en (each side nullable). NOT reconstructed as of the requested date (no dates exist).
         *
         * @var list<array<string, string|null>>
         */
        public readonly array $qualifications = [],
    ) {}
}
