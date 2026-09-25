<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;

final class DeactivateGender extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return Gender::class;
    }
}
