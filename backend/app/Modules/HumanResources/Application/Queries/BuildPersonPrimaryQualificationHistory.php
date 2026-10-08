<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\Audit\Infrastructure\Persistence\Eloquent\AuditEntry;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PersonQualification;

/**
 * S48 Primary-designation history read model (docs/person-qualification-history-foundation-specification.md
 * §S48.10, D28/D35/D41/D38). Sourced entirely from `audit.audit_entries` — never from the versioned
 * qualification data, which answers a different question (§S48.11). Scoped to the given Person's
 * own qualification ids; a foreign qualification id is never surfaced (the query itself only ever
 * looks at this Person's own ids, so there is nothing to filter out after the fact).
 */
final class BuildPersonPrimaryQualificationHistory
{
    public const GAP_NO_DESIGNATION_EVIDENCE = 'GAP_NO_DESIGNATION_EVIDENCE';

    public const GAP_CHAIN_BROKEN = 'GAP_CHAIN_BROKEN';

    public function __invoke(Person $person): PersonPrimaryQualificationHistory
    {
        $qualificationIds = PersonQualification::query()
            ->where('person_id', $person->getKey())
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($qualificationIds === []) {
            return new PersonPrimaryQualificationHistory([], []);
        }

        // Deterministic ordering (D35, qualified by D41): occurred_at ascending, then the audit
        // entry's own id as a stable, deterministic tie-breaker for equal timestamps — never
        // asserted as a proof of true execution order (RC5's own softening of the UUIDv7 claim).
        $entries = AuditEntry::query()
            ->where('target_type', 'hr_person_qualification')
            ->whereIn('action', ['hr.person_qualification.record', 'hr.person_qualification.designate_primary'])
            ->where('outcome', 'SUCCEEDED')
            ->whereIn('target_id', $qualificationIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        /** @var list<PrimaryQualificationHistoryEvent> $events */
        $events = [];

        foreach ($entries as $entry) {
            $changes = $entry->changes ?? [];
            $metadata = $entry->metadata ?? [];

            if ($entry->action === 'hr.person_qualification.designate_primary') {
                // D15, unchanged: an idempotent re-designation (state_changed = false) is never a
                // transition.
                if (($metadata['state_changed'] ?? false) !== true) {
                    continue;
                }

                $events[] = new PrimaryQualificationHistoryEvent(
                    type: 'DESIGNATED',
                    qualificationId: (string) ($changes['new_primary_qualification_id'] ?? $entry->target_id),
                    previousPrimaryQualificationId: $changes['previous_primary_qualification_id'] ?? null,
                    actorPrincipalId: $entry->actor_principal_id,
                    occurredAt: $entry->occurred_at->toJSON(),
                );

                continue;
            }

            // action === 'hr.person_qualification.record': AUTO_FIRST only when this very event
            // carried changes.is_primary = true (field-level evidence, D15/acceptance matrix). A
            // record entry's changes payload has no "previous" key at all (verified against
            // source, D35) — never treated as though one existed and read null.
            if (($changes['is_primary'] ?? false) !== true) {
                continue;
            }

            $events[] = new PrimaryQualificationHistoryEvent(
                type: 'AUTO_FIRST',
                qualificationId: (string) $entry->target_id,
                previousPrimaryQualificationId: null,
                actorPrincipalId: $entry->actor_principal_id,
                occurredAt: $entry->occurred_at->toJSON(),
            );
        }

        $currentPrimaryId = PersonQualification::query()
            ->where('person_id', $person->getKey())
            ->where('is_primary', true)
            ->value('id');

        $gaps = $currentPrimaryId === null ? [] : $this->walkChain((string) $currentPrimaryId, $events);

        return new PersonPrimaryQualificationHistory($events, $gaps);
    }

    /**
     * The chain walk itself — advances by the POSITION of the audit entry in the Person's fully
     * ordered event list, never by qualification identity (D41, correcting RC4's id-tracking
     * cycle-stop). At each step, "what accounts for qualification Q becoming Primary" is answered
     * by searching strictly earlier positions for the LATEST DESIGNATED/AUTO_FIRST event whose
     * "new" qualification is Q. Strictly decreasing every step — bounded by count($events),
     * provably terminates, and a repeated qualification id is never, by itself, a reason to stop.
     *
     * @param  list<PrimaryQualificationHistoryEvent>  $events
     * @return list<array{code: string, qualification_id: string}>
     */
    private function walkChain(string $target, array $events): array
    {
        $searchBeforePosition = count($events);
        $isFirstStep = true;

        while (true) {
            $foundPosition = null;

            for ($position = $searchBeforePosition - 1; $position >= 0; $position--) {
                if ($events[$position]->qualificationId === $target) {
                    $foundPosition = $position;
                    break;
                }
            }

            if ($foundPosition === null) {
                $code = $isFirstStep ? self::GAP_NO_DESIGNATION_EVIDENCE : self::GAP_CHAIN_BROKEN;

                return [['code' => $code, 'qualification_id' => $target]];
            }

            $event = $events[$foundPosition];

            if ($event->type === 'AUTO_FIRST' || $event->previousPrimaryQualificationId === null) {
                // AUTO_FIRST never carries a "previous" at all; a DESIGNATED event whose own
                // previous_primary_qualification_id is null means there genuinely was no current
                // Primary at the time (e.g. a legacy Person with 2+ qualifications and no R1-D42
                // backfilled Primary) — both are a legitimate, evidenced start of history, not a
                // gap.
                return [];
            }

            $target = $event->previousPrimaryQualificationId;
            $searchBeforePosition = $foundPosition; // strictly decreasing every step
            $isFirstStep = false;
        }
    }
}
