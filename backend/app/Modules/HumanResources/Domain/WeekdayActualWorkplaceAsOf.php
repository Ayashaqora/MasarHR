<?php

namespace App\Modules\HumanResources\Domain;

/**
 * The actual workplace of an Employment Relationship for one business date AND one weekday
 * (docs/partial-secondment-foundation-specification.md §S30.17, ADR-S30-011): relationship +
 * business date + weekday → actual workplace. Immutable, never persisted — the S27/S29 result
 * style. It complements, and never replaces, S27's date-only ActualWorkplaceAsOf.
 *
 *  - RESOLVED: one unit, with its source — 'partial_secondment' (the weekday is allocated to a
 *    Partial Secondment effective on the date), or 'secondment' / 'assignment' / 'placement'
 *    exactly as the date-only S27 decision table resolves them.
 *  - NOT_SCHEDULED: the recorded Work Schedule effective on the date does not include the
 *    weekday — not a working day for this relationship.
 *  - UNRESOLVED: the relationship is not effective on the date, or nothing resolvable is recorded.
 *  - AMBIGUOUS_MOVEMENT_STATE: conflicting movement data (S27 CA-S27-02) — never a chosen winner.
 *
 * scheduleState() is the S29 WorkScheduleAsOf state on the date (RESOLVED / NOT_RECORDED), so a
 * caller can tell "scheduled", "not scheduled" and "schedule not recorded" apart; a NOT_RECORDED
 * schedule never makes a weekday a non-working day (no default week).
 */
final class WeekdayActualWorkplaceAsOf
{
    public const RESOLVED = 'RESOLVED';

    public const NOT_SCHEDULED = 'NOT_SCHEDULED';

    public const UNRESOLVED = 'UNRESOLVED';

    public const AMBIGUOUS_MOVEMENT_STATE = 'AMBIGUOUS_MOVEMENT_STATE';

    /** @param list<array{source: string, period_id: string, organizational_unit_id: string, effective_from: string}> $competingMovements */
    private function __construct(
        private readonly string $state,
        private readonly string $weekday,
        private readonly string $scheduleState,
        private readonly ?string $organizationalUnitId = null,
        private readonly ?string $source = null,
        private readonly ?string $periodId = null,
        private readonly array $competingMovements = [],
    ) {}

    public static function resolved(string $weekday, string $scheduleState, string $organizationalUnitId, string $source, ?string $periodId = null): self
    {
        return new self(self::RESOLVED, $weekday, $scheduleState, $organizationalUnitId, $source, $periodId);
    }

    public static function notScheduled(string $weekday, string $scheduleState): self
    {
        return new self(self::NOT_SCHEDULED, $weekday, $scheduleState);
    }

    public static function unresolved(string $weekday, string $scheduleState): self
    {
        return new self(self::UNRESOLVED, $weekday, $scheduleState);
    }

    /** @param list<array{source: string, period_id: string, organizational_unit_id: string, effective_from: string}> $competingMovements */
    public static function ambiguous(string $weekday, string $scheduleState, array $competingMovements): self
    {
        return new self(self::AMBIGUOUS_MOVEMENT_STATE, $weekday, $scheduleState, competingMovements: $competingMovements);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isResolved(): bool
    {
        return $this->state === self::RESOLVED;
    }

    /** The stable weekday code (MONDAY … SUNDAY) the result is for. */
    public function weekday(): string
    {
        return $this->weekday;
    }

    /** 'RESOLVED' | 'NOT_RECORDED' — the S29 Work Schedule state on the date. */
    public function scheduleState(): string
    {
        return $this->scheduleState;
    }

    /** Null unless RESOLVED. */
    public function organizationalUnitId(): ?string
    {
        return $this->organizationalUnitId;
    }

    /** 'partial_secondment' | 'secondment' | 'assignment' | 'placement' — null unless RESOLVED. */
    public function source(): ?string
    {
        return $this->source;
    }

    /** The Partial Secondment period id when source is 'partial_secondment', else null. */
    public function periodId(): ?string
    {
        return $this->periodId;
    }

    /** @return list<array{source: string, period_id: string, organizational_unit_id: string, effective_from: string}> */
    public function competingMovements(): array
    {
        return $this->competingMovements;
    }
}
