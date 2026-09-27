<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;

final class DeactivateQualificationType extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return QualificationType::class;
    }
}
