<?php

namespace App\Modules\HumanResources\Domain;

/**
 * The outcome of trying to emit ONE candidate follow-up
 * (docs/movement-expiry-followup-foundation-specification.md §S31.7/§S31.9): EMITTED (a new
 * ACTIONABLE row + one audit entry), ALREADY_EXISTS (the logical key exists — idempotent no-op, no
 * audit), NOT_DUE (outside the window [E − 7, E)), or STALE (the recheck failed — nothing written).
 */
final class ExpiryFollowUpEmission
{
    public const EMITTED = 'EMITTED';

    public const ALREADY_EXISTS = 'ALREADY_EXISTS';

    public const NOT_DUE = 'NOT_DUE';

    public const STALE = 'STALE';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $followUpId = null,
        public readonly ?FollowUpSuppressionReason $reason = null,
    ) {}

    public static function emitted(string $followUpId): self
    {
        return new self(self::EMITTED, $followUpId);
    }

    public static function alreadyExists(): self
    {
        return new self(self::ALREADY_EXISTS);
    }

    public static function notDue(): self
    {
        return new self(self::NOT_DUE);
    }

    public static function stale(FollowUpSuppressionReason $reason): self
    {
        return new self(self::STALE, null, $reason);
    }
}
