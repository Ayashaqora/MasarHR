<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * One Employment Relationship's part of the reporting month. Everything here belongs to THIS relationship only
 * (reappointments never share status, reasons or workplace).
 *
 * clippedFrom/clippedTo are the relationship's active days inside the month, half-open [from, to). For an
 * UNKNOWN_LEGACY end (endIsUncertain) effectiveTo is null and clippedTo is merely the month bound — no end date
 * is fabricated and the active days are not certain.
 */
final class MonthlyRelationshipSegment
{
    /**
     * @param  list<array<string, mixed>>  $statusSegments  EXPLICIT | DERIVED_ON_DUTY | UNRESOLVED, chronological (MonthlyStatusSegmentation)
     * @param  ?array<string, mixed>  $lastNonOnDutyReason  the latest EXPLICIT non-on_duty segment of THIS relationship's active days; never a terminal status beginning at the relationship end; null if none
     * @param  ?array<string, mixed>  $relationshipEndReason  the status beginning exactly at this relationship's KNOWN end when that end closes the segment inside the month; separate from, and never ranked against, the temporary reason
     * @param  list<array<string, mixed>>  $workplaceSegments  interval segments from the S27/S30 decision table (RESOLVED | UNRESOLVED | AMBIGUOUS_MOVEMENT_STATE | PARTIAL_ALLOCATION)
     * @param  list<array<string, mixed>>  $workScheduleSegments  RESOLVED | NOT_RECORDED interval segments
     */
    public function __construct(
        public readonly string $employmentRelationshipId,
        public readonly string $employmentTypeId,
        public readonly string $employmentTypeCode,
        public readonly string $employeeNumberScheme,
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
        public readonly string $endKnowledgeState,
        public readonly bool $endIsUncertain,
        public readonly ?bool $endedTerminally,
        public readonly string $clippedFrom,
        public readonly string $clippedTo,
        public readonly array $statusSegments,
        public readonly ?array $lastNonOnDutyReason,
        public readonly ?array $relationshipEndReason,
        public readonly array $workplaceSegments,
        public readonly array $workScheduleSegments,
    ) {}
}
