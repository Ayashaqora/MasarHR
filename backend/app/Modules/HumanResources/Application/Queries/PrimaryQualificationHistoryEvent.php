<?php

namespace App\Modules\HumanResources\Application\Queries;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.10): one real
 * Primary-designation event — a `DESIGNATED` entry with `metadata.state_changed = true`, or an
 * `AUTO_FIRST` entry (a `record` entry carrying `changes.is_primary = true`). A correction event
 * never appears here (unchanged, D15/§S48.10).
 */
final class PrimaryQualificationHistoryEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $qualificationId,
        public readonly ?string $previousPrimaryQualificationId,
        public readonly ?string $actorPrincipalId,
        public readonly string $occurredAt,
    ) {}
}
