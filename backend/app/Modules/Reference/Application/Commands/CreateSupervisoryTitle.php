<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SupervisoryTitle;

final class CreateSupervisoryTitle extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return SupervisoryTitle::class;
    }
}
