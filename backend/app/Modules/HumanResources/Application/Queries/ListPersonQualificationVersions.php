<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualificationVersion;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.14): every version of
 * one qualification, oldest first, ordered by version_number ascending (a stable tie-breaker by
 * construction — version_number is unique per qualification). Ownership binding (the qualification
 * belongs to the given Person) is the controller's job, same mechanism as the existing
 * designate-primary route.
 */
final class ListPersonQualificationVersions
{
    public function __invoke(PersonQualification $qualification, int $page, int $perPage): LengthAwarePaginator
    {
        return PersonQualificationVersion::query()
            ->where('person_qualification_id', $qualification->getKey())
            ->orderBy('version_number')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
