<?php

namespace App\Modules\HumanResources\Application\Queries;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.10): the full result for
 * one Person — every real event, in order, AND the whole-chain `evidence_completeness` gaps,
 * computed once over the complete list before any page boundary is applied to `events` (D35).
 *
 * @param  list<PrimaryQualificationHistoryEvent>  $events
 * @param  list<array{code: string, qualification_id: string}>  $gaps
 */
final class PersonPrimaryQualificationHistory
{
    public function __construct(
        public readonly array $events,
        public readonly array $gaps,
    ) {}
}
