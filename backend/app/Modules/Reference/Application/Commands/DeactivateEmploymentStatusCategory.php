<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusCategory;

final class DeactivateEmploymentStatusCategory extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return EmploymentStatusCategory::class;
    }
}
