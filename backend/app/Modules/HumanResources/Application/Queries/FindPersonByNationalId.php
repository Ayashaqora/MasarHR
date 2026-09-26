<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;

/**
 * The canonical read path for S09 (spec §5/§19): "lookup starts from National ID." Applies the
 * same trim() normalization CreatePerson uses, and only that — no fuzzy matching (spec §4).
 */
final class FindPersonByNationalId
{
    public function __invoke(string $nationalId): ?Person
    {
        return Person::query()->where('national_id', trim($nationalId))->first();
    }
}
