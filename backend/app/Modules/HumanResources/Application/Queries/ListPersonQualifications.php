<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every qualification fact recorded for one Person, in recording order
 * (docs/person-qualification-foundation-specification.md §S23.13). The order carries no meaning —
 * it is NOT a ranking, and no primary/highest/current qualification is derived beyond S41's own
 * Primary designation (ADR-S23-DECISIONS §4). An empty list means "no qualification recorded" —
 * which is also how «بدون» is represented.
 *
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.13, D13): repointed to
 * read each qualification's CURRENT version — an Eloquent scope backed by
 * hr.person_qualifications_current (the parent identity row eager-loaded with its one
 * `currentVersion`, rather than a literal query against the view, so the response can also carry
 * `provenance`/D36, which the frozen view's own column list does not select). Facts whose
 * referenced degree/type has since been deactivated are returned unchanged.
 */
final class ListPersonQualifications
{
    public function __invoke(Person $person): Collection
    {
        return PersonQualification::query()
            ->where('person_id', $person->getKey())
            ->with('currentVersion')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
