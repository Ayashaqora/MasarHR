<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;

/** The outcome of DesignateQualificationAsPrimary (S41 §S41.7): the new Primary, the one it replaced, and whether anything changed. */
final class PrimaryQualificationDesignation
{
    public function __construct(
        public readonly PersonQualification $qualification,
        public readonly ?string $previousPrimaryQualificationId,
        public readonly bool $changed,
    ) {}
}
