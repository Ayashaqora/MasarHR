<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;

final class DeactivateMaritalStatus extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return MaritalStatus::class;
    }
}
