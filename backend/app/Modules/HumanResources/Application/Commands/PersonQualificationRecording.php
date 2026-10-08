<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualificationVersion;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.4/§S48.13): the result
 * of RecordPersonQualification — the qualification's stable identity row AND the version_number = 1
 * row created with it in the same transaction. PersonQualificationController::store()'s own
 * AuditSpec `changes` closure reads the version's values here instead of the model's own (now
 * dropped) academic_degree_id/qualification_type_id columns (D38).
 */
final class PersonQualificationRecording
{
    public function __construct(
        public readonly PersonQualification $qualification,
        public readonly PersonQualificationVersion $version,
    ) {}
}
