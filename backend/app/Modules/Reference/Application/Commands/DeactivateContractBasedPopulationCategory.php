<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;

final class DeactivateContractBasedPopulationCategory extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return ContractBasedPopulationCategory::class;
    }
}
