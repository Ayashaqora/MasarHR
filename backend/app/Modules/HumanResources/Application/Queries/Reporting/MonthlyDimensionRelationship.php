<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/**
 * One relationship of a Person in the S40 monthly dimension read model. The four dimension histories are
 * relationship-local and never flattened: each list tiles the relationship's month window [clipped_from, clipped_to).
 */
final class MonthlyDimensionRelationship
{
    /**
     * @param  list<array<string, mixed>>  $categorySegments  RESOLVED | NOT_RECORDED
     * @param  list<array<string, mixed>>  $contractSegments  RESOLVED | NOT_RECORDED | NOT_APPLICABLE (relationship scheme is not CONTRACT); each RESOLVED one carries its population mapping
     * @param  list<array<string, mixed>>  $jobTitleSegments  RESOLVED | NOT_RECORDED; each RESOLVED one carries its administrator classification and start_knowledge_state
     * @param  list<array<string, mixed>>  $specialtySegments  RESOLVED | NOT_RECORDED; each RESOLVED one carries its cadre category mapping
     */
    public function __construct(
        public readonly string $employmentRelationshipId,
        public readonly string $employeeNumberScheme,
        public readonly string $clippedFrom,
        public readonly string $clippedTo,
        public readonly array $categorySegments,
        public readonly array $contractSegments,
        public readonly array $jobTitleSegments,
        public readonly array $specialtySegments,
    ) {}
}
