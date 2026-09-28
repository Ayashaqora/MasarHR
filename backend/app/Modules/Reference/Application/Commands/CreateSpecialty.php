<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;

final class CreateSpecialty extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return Specialty::class;
    }
}
