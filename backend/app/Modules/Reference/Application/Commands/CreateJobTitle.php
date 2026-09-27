<?php

namespace App\Modules\Reference\Application\Commands;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;

final class CreateJobTitle extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string
    {
        return JobTitle::class;
    }
}
