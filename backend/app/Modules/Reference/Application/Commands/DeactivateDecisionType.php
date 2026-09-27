<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;

final class DeactivateDecisionType extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return DecisionType::class;
    }
}
