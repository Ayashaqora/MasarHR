<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\LeaveStatus;

final class DeactivateLeaveStatus extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return LeaveStatus::class;
    }
}
