<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;

final class ActivateDecisionType extends AbstractActivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return DecisionType::class;
    }
}
