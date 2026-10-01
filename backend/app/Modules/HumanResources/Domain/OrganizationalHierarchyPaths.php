<?php

namespace App\Modules\HumanResources\Domain;

use App\Modules\HumanResources\Domain\Exceptions\InconsistentDimensionHistoryException;

/**
 * R2 (docs/administrative-report-foundation-specification.md §S42.8): the root → unit ancestor path of organizational units from an
 * in-memory unit map (id => parent_id), so the hierarchy is read in one batch and never per node. A cycle in the parent chain
 * contradicts the hierarchy invariant and fails explicitly. Pure.
 */
final class OrganizationalHierarchyPaths
{
    /**
     * @param  array<string, array{parent_id: string|null}>  $units
     * @return list<string> unit ids from the root down to $unitId (inclusive)
     */
    public static function path(string $unitId, array $units): array
    {
        $path = [];
        $seen = [];
        $current = $unitId;
        while ($current !== null) {
            if (isset($seen[$current]) || ! isset($units[$current])) {
                throw new InconsistentDimensionHistoryException('The organizational hierarchy is cyclic or references a missing unit.');
            }
            $seen[$current] = true;
            array_unshift($path, $current);
            $current = $units[$current]['parent_id'];
        }

        return $path;
    }
}
