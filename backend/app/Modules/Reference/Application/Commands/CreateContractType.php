<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;

final class CreateContractType extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return ContractType::class;
    }
}
