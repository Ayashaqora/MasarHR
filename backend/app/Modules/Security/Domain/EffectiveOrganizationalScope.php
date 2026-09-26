<?php

namespace App\Modules\Security\Domain;

/**
 * The result of resolving a principal's organizational scope (spec §11): either GLOBAL (covers
 * every unit, present and future) or a concrete set of unit ids (the union of every granted unit's
 * inclusive subtree — ADR-S08-002). Immutable value object, no persistence of its own.
 */
final class EffectiveOrganizationalScope
{
    /** @param list<string> $unitIds */
    private function __construct(
        private readonly bool $isGlobal,
        private readonly array $unitIds,
    ) {}

    public static function global(): self
    {
        return new self(true, []);
    }

    /** @param list<string> $unitIds */
    public static function units(array $unitIds): self
    {
        return new self(false, $unitIds);
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }

    /** @return list<string> Empty once isGlobal() is true — the set is meaningless at that point. */
    public function unitIds(): array
    {
        return $this->unitIds;
    }

    public function covers(string $unitId): bool
    {
        if ($this->isGlobal) {
            return true;
        }

        return in_array($unitId, $this->unitIds, true);
    }
}
