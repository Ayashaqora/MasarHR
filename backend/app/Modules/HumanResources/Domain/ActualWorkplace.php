<?php

namespace App\Modules\HumanResources\Domain;

use Illuminate\Support\Carbon;

/**
 * The result of resolving an Employment Relationship's current "actual workplace"
 * (docs/full-secondment-foundation-specification.md §1.5/§14): the destination of its
 * currently-open S12 Full Secondment period if one exists, otherwise the unit of its
 * currently-open S11 Placement period, otherwise unresolved. Never persisted — computed fresh on
 * every read by ResolveActualWorkplaceForRelationship. Immutable value object, no persistence of
 * its own, mirrors App\Modules\Security\Domain\EffectiveOrganizationalScope's own shape.
 */
final class ActualWorkplace
{
    private function __construct(
        private readonly ?string $organizationalUnitId,
        private readonly ?string $source,
        private readonly ?Carbon $since,
    ) {}

    public static function fromSecondment(string $organizationalUnitId, Carbon $since): self
    {
        return new self($organizationalUnitId, 'secondment', $since);
    }

    public static function fromPlacement(string $organizationalUnitId, Carbon $since): self
    {
        return new self($organizationalUnitId, 'placement', $since);
    }

    /** No active secondment, no recorded placement, or the relationship has ended (spec §7.3). */
    public static function unresolved(): self
    {
        return new self(null, null, null);
    }

    public function organizationalUnitId(): ?string
    {
        return $this->organizationalUnitId;
    }

    /** 'secondment' | 'placement' | null. */
    public function source(): ?string
    {
        return $this->source;
    }

    public function since(): ?Carbon
    {
        return $this->since;
    }

    public function isResolved(): bool
    {
        return $this->organizationalUnitId !== null;
    }
}
