<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;

final class DeactivateEmploymentStatusDetail extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return EmploymentStatusDetail::class;
    }
}
