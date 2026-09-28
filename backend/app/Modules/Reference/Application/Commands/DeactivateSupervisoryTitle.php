<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SupervisoryTitle;

final class DeactivateSupervisoryTitle extends AbstractDeactivateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return SupervisoryTitle::class;
    }
}
