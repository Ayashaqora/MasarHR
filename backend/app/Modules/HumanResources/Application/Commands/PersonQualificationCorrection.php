<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualificationVersion;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.5/D11): the result of
 * CorrectPersonQualification — the qualification's stable identity, the version it superseded,
 * and the new version it created. Carries both sides so the controller's AuditSpec can record the
 * previous values alongside the new ones (D11: "records the previous values, the new values, a
 * mandatory reason, who recorded it, and when").
 */
final class PersonQualificationCorrection
{
    public function __construct(
        public readonly PersonQualification $qualification,
        public readonly PersonQualificationVersion $previousVersion,
        public readonly PersonQualificationVersion $newVersion,
    ) {}
}
