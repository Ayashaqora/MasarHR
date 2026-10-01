<?php

namespace App\Modules\HumanResources\Application\Queries\Reporting;

/** One Person of the S37 monthly population with the S40 dimension history of each of their relationships. */
final class MonthlyDimensionPersonRow
{
    /** @param  list<MonthlyDimensionRelationship>  $relationships  same order as the S37 relationship segments */
    public function __construct(
        public readonly string $personId,
        public readonly array $relationships,
    ) {}
}
