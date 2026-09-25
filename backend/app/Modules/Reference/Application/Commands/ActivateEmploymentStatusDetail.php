<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;

final class ActivateEmploymentStatusDetail extends AbstractActivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return EmploymentStatusDetail::class;
    }
}
