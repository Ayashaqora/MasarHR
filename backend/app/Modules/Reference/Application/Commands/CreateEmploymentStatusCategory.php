<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusCategory;

final class CreateEmploymentStatusCategory extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return EmploymentStatusCategory::class;
    }
}
