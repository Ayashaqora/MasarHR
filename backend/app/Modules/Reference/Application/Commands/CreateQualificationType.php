<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;

final class CreateQualificationType extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return QualificationType::class;
    }
}
