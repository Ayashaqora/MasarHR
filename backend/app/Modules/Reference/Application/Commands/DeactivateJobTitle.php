<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;

final class DeactivateJobTitle extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return JobTitle::class;
    }
}
