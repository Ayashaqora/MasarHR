<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;

final class CreateAcademicDegree extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return AcademicDegree::class;
    }
}
