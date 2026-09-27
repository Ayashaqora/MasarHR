<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;

final class UpdateMonthlyCadreCategoryMetadata extends AbstractUpdateSimpleReferenceValueMetadata
{
    protected function modelClass(): string
    {
        return MonthlyCadreCategory::class;
    }
}
