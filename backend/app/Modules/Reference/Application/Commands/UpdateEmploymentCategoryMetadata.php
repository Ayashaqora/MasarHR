<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;

final class UpdateEmploymentCategoryMetadata extends AbstractUpdateSimpleReferenceValueMetadata
{
    protected function modelClass(): string
    {
        return EmploymentCategory::class;
    }
}
