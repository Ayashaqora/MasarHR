<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use Illuminate\Database\Eloquent\Collection;

/** Every Employment Relationship for a Person, most recent first (spec §19). */
final class ListEmploymentRelationshipsForPerson
{
    public function __invoke(Person $person): Collection
    {
        return EmploymentRelationship::query()
            ->where('person_id', $person->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }
}
