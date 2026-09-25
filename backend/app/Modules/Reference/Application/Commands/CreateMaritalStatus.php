<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;

final class CreateMaritalStatus extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return MaritalStatus::class;
    }
}
