<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\LeaveType;

final class ActivateLeaveType extends AbstractActivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return LeaveType::class;
    }
}
