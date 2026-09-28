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
 */
final class ActualWorkplaceAsOf
{
    public const RESOLVED = 'RESOLVED';

    public const UNRESOLVED = 'UNRESOLVED';

    public const AMBIGUOUS_MOVEMENT_STATE = 'AMBIGUOUS_MOVEMENT_STATE';

    /**
     * @param  list<array{source: string, period_id: string, organizational_unit_id: string, effective_from: string}>  $competingMovements
     */
    private function __construct(
        private readonly string $state,
        private readonly ?string $organizationalUnitId,
        private readonly ?string $source,
        private readonly ?Carbon $since,
        private readonly array $competingMovements,
    ) {}

    /**
     * The one CA-S27-02 decision table, shared by the single-relationship resolver and the
     * population read model so both always agree. Each argument is the period of that stream
     * effective on the date (each stream is itself exclusive per relationship), or null.
     *
     * @param  array{id: string, organizational_unit_id: string, effective_from: string}|null  $placement
     * @param  array{id: string, organizational_unit_id: string, effective_from: string}|null  $secondment
     * @param  array{id: string, organizational_unit_id: string, effective_from: string}|null  $assignment
     */
    public static function fromEffectiveFacts(?array $placement, ?array $secondment, ?array $assignment): self
    {
        $movements = [];
        if ($secondment !== null) {
            $movements[] = ['source' => 'secondment', 'period_id' => $secondment['id'], 'organizational_unit_id' => $secondment['organizational_unit_id'], 'effective_from' => $secondment['effective_from']];
        }
        if ($assignment !== null) {
            $movements[] = ['source' => 'assignment', 'period_id' => $assignment['id'], 'organizational_unit_id' => $assignment['organizational_unit_id'], 'effective_from' => $assignment['effective_from']];
        }

        if (count($movements) > 1) {
            return new self(self::AMBIGUOUS_MOVEMENT_STATE, null, null, null, $movements);
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

    /** 'RESOLVED' | 'UNRESOLVED' | 'AMBIGUOUS_MOVEMENT_STATE'. */
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

    /** Null unless RESOLVED. */
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
}
