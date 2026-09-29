<?php

namespace App\Modules\HumanResources\Domain;

use Illuminate\Support\Carbon;

/**
 * The result of resolving an Employment Relationship's ACTUAL workplace on an explicit business
 * date (docs/reporting-as-of-foundation-specification.md §S27.7, ADR-S27-001, CA-S27-01/02). An
 * immutable, never-persisted, internal read-model value — deliberately separate from S12/S16's
 * ActualWorkplace (the latest-recorded / open-period view), which S27 leaves unchanged.
 *
 * Three states:
 *  - RESOLVED: exactly one effective temporary movement (source 'secondment' or 'assignment'),
 *    or none and an effective organizational placement (source 'placement').
 *  - UNRESOLVED: the relationship is not effective on the date, or nothing resolvable is effective.
 *  - AMBIGUOUS_MOVEMENT_STATE (CA-S27-02): more than one competing temporary movement (full
 *    secondment AND workplace assignment) is effective on the same date — historically possible
 *    because S12/S16 write-side checks compare open records only. No winner is chosen and no
 *    fallback to placement is made; the competing movements stay visible to callers.
 *  - PARTIAL_ALLOCATION (S30, docs/partial-secondment-foundation-specification.md §S30.17/§S30.18,
 *    ADR-S30-011/012): one or more valid Partial Secondments are effective on the date. The
 *    employee's workplace then depends on the weekday, so there is NO scalar unit
 *    (organizationalUnitId() is null) — never an arbitrarily chosen destination. The underlying
 *    workplace (the effective placement) and every partial allocation (destination + weekdays)
 *    are exposed; ResolveWeekdayActualWorkplaceAsOf answers the per-weekday question. Several
 *    disjoint Partial Secondments are valid allocation, not ambiguity. A Partial Secondment
 *    effective together with a Full Secondment or Workplace Assignment (impossible through the
 *    S30 commands, so legacy / directly-written data only) is AMBIGUOUS_MOVEMENT_STATE.
 */
final class ActualWorkplaceAsOf
{
    public const RESOLVED = 'RESOLVED';

    public const UNRESOLVED = 'UNRESOLVED';

    public const AMBIGUOUS_MOVEMENT_STATE = 'AMBIGUOUS_MOVEMENT_STATE';

    public const PARTIAL_ALLOCATION = 'PARTIAL_ALLOCATION';

    /**
     * @param  list<array{source: string, period_id: string, organizational_unit_id: string, effective_from: string}>  $competingMovements
     */
    private function __construct(
        private readonly string $state,
        private readonly ?string $organizationalUnitId,
        private readonly ?string $source,
        private readonly ?Carbon $since,
        private readonly array $competingMovements,
        /** @var list<array{period_id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string, weekdays: list<string>}> */
        private readonly array $partialAllocations = [],
        private readonly ?string $underlyingOrganizationalUnitId = null,
    ) {}

    /**
     * The one CA-S27-02 decision table, shared by the single-relationship resolver and the
     * population read model so both always agree. Each argument is the period of that stream
     * effective on the date (each stream is itself exclusive per relationship), or null.
     *
     * @param  array{id: string, organizational_unit_id: string, effective_from: string}|null  $placement
     * @param  array{id: string, organizational_unit_id: string, effective_from: string}|null  $secondment
     * @param  array{id: string, organizational_unit_id: string, effective_from: string}|null  $assignment
     * @param  list<array{id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string, weekdays: list<string>}>  $partials  every Partial Secondment effective on the date (S30)
     */
    public static function fromEffectiveFacts(?array $placement, ?array $secondment, ?array $assignment, array $partials = []): self
    {
        $movements = [];
        if ($secondment !== null) {
            $movements[] = ['source' => 'secondment', 'period_id' => $secondment['id'], 'organizational_unit_id' => $secondment['organizational_unit_id'], 'effective_from' => $secondment['effective_from']];
        }
        if ($assignment !== null) {
            $movements[] = ['source' => 'assignment', 'period_id' => $assignment['id'], 'organizational_unit_id' => $assignment['organizational_unit_id'], 'effective_from' => $assignment['effective_from']];
        }

        if ($partials !== [] && $movements !== []) {
            foreach ($partials as $partial) {
                $movements[] = ['source' => 'partial_secondment', 'period_id' => $partial['id'], 'organizational_unit_id' => $partial['organizational_unit_id'], 'effective_from' => $partial['effective_from']];
            }
        }

        if (count($movements) > 1) {
            return new self(self::AMBIGUOUS_MOVEMENT_STATE, null, null, null, $movements);
        }

        if ($partials !== []) {
            $allocations = array_map(fn (array $partial) => [
                'period_id' => $partial['id'],
                'organizational_unit_id' => $partial['organizational_unit_id'],
                'effective_from' => $partial['effective_from'],
                'effective_to' => $partial['effective_to'],
                'weekdays' => $partial['weekdays'],
            ], array_values($partials));

            return new self(self::PARTIAL_ALLOCATION, null, null, null, [], $allocations, $placement['organizational_unit_id'] ?? null);
        }

        if (count($movements) === 1) {
            return new self(self::RESOLVED, $movements[0]['organizational_unit_id'], $movements[0]['source'], Carbon::parse($movements[0]['effective_from']), []);
        }

        if ($placement !== null) {
            return new self(self::RESOLVED, $placement['organizational_unit_id'], 'placement', Carbon::parse($placement['effective_from']), []);
        }

        return self::unresolved();
    }

    public static function unresolved(): self
    {
        return new self(self::UNRESOLVED, null, null, null, []);
    }

    /** 'RESOLVED' | 'UNRESOLVED' | 'AMBIGUOUS_MOVEMENT_STATE' | 'PARTIAL_ALLOCATION'. */
    public function state(): string
    {
        return $this->state;
    }

    public function isResolved(): bool
    {
        return $this->state === self::RESOLVED;
    }

    public function isAmbiguous(): bool
    {
        return $this->state === self::AMBIGUOUS_MOVEMENT_STATE;
    }

    public function isPartialAllocation(): bool
    {
        return $this->state === self::PARTIAL_ALLOCATION;
    }

    /** Null unless RESOLVED — in particular null for PARTIAL_ALLOCATION (no scalar workplace). */
    public function organizationalUnitId(): ?string
    {
        return $this->organizationalUnitId;
    }

    /** 'placement' | 'secondment' | 'assignment' — null unless RESOLVED. */
    public function source(): ?string
    {
        return $this->source;
    }

    public function since(): ?Carbon
    {
        return $this->since;
    }

    /**
     * The competing effective movements — non-empty only when AMBIGUOUS_MOVEMENT_STATE.
     *
     * @return list<array{source: string, period_id: string, organizational_unit_id: string, effective_from: string}>
     */
    public function competingMovements(): array
    {
        return $this->competingMovements;
    }

    /**
     * PARTIAL_ALLOCATION only: every Partial Secondment effective on the date, with its destination
     * and allocated weekday codes (ISO order). Empty otherwise.
     *
     * @return list<array{period_id: string, organizational_unit_id: string, effective_from: string, effective_to: ?string, weekdays: list<string>}>
     */
    public function partialAllocations(): array
    {
        return $this->partialAllocations;
    }

    /**
     * PARTIAL_ALLOCATION only: the underlying (original) workplace for scheduled weekdays not
     * allocated to any Partial Secondment — the effective organizational placement, or null when
     * none is recorded. Null otherwise.
     */
    public function underlyingOrganizationalUnitId(): ?string
    {
        return $this->underlyingOrganizationalUnitId;
    }
}
