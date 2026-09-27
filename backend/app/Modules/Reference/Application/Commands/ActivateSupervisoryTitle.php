<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SupervisoryTitle;

final class ActivateSupervisoryTitle extends AbstractActivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return SupervisoryTitle::class;
    }
}
