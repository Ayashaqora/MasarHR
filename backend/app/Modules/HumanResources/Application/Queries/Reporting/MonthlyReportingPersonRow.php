<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * One canonical monthly row: exactly ONE per Person (never per relationship, never per qualification).
 * Relationship-owned facts stay inside their own MonthlyRelationshipSegment and are never merged.
 */
final class MonthlyReportingPersonRow
{
    /**
     * @param  string  $dutyClassification  HAS_ON_DUTY | NO_ON_DUTY | INDETERMINATE (MonthlyDutyClassification)
     * @param  ?string  $birthDate  the Person's existing DATE fact (YYYY-MM-DD); null when unrecorded. Never an age.
     * @param  list<array<string, string|null>>  $qualifications  CURRENT recorded facts ordered by (created_at, id) — technical order, not a ranking, not as of the month
     * @param  list<MonthlyRelationshipSegment>  $relationships  ordered by clipped start, then id
     */
    public function __construct(
        public readonly string $personId,
        public readonly ?string $genderId,
        public readonly ?string $birthDate,
        public readonly array $qualifications,
        public readonly string $qualificationSemantics,
        public readonly string $dutyClassification,
        public readonly array $relationships,
    ) {}
}
