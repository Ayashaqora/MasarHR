<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;

final class ActivateJobTitle extends AbstractActivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return JobTitle::class;
    }
}
