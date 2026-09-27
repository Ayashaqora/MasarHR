<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;

final class UpdateContractBasedPopulationCategoryMetadata extends AbstractUpdateSimpleReferenceValueMetadata
{
    protected function modelClass(): string
    {
        return ContractBasedPopulationCategory::class;
    }
}
