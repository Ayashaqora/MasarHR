<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractBasedPopulationCategory;

final class CreateContractBasedPopulationCategory extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return ContractBasedPopulationCategory::class;
    }
}
