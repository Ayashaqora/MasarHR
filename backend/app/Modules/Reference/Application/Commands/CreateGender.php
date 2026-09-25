<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;

final class CreateGender extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return Gender::class;
    }
}
