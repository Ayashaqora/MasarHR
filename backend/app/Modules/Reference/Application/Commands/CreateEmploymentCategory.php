<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;

final class CreateEmploymentCategory extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return EmploymentCategory::class;
    }
}
