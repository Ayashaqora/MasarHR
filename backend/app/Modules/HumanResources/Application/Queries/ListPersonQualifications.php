<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every qualification fact recorded for one Person, in recording order
 * (docs/person-qualification-foundation-specification.md §S23.13). The order carries no meaning —
 * it is NOT a ranking, and no primary/highest/current qualification is derived (ADR-S23-DECISIONS
 * §4). Facts whose referenced degree/type has since been deactivated are returned unchanged. An
 * empty list means "no qualification recorded" — which is also how «بدون» is represented.
 */
final class ListPersonQualifications
{
    public function __invoke(Person $person): Collection
    {
        return PersonQualification::query()
            ->where('person_id', $person->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
