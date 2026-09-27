<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;

final class CreateMonthlyCadreCategory extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return MonthlyCadreCategory::class;
    }
}
