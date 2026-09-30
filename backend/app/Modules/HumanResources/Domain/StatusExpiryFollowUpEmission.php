<?php

namespace App\Modules\HumanResources\Domain;

/**
 * The outcome of trying to emit ONE candidate status follow-up
 * (docs/employment-status-expiry-followup-specification.md §S38.10): EMITTED (a new ACTIONABLE row + one audit
 * entry), ALREADY_EXISTS (the logical key exists — idempotent no-op, no audit), NOT_DUE (outside [E − 7, E)),
 * NOT_ELIGIBLE (the authoritative period is no longer an eligible bounded status for that end) or STALE (the
 * recheck failed — nothing written; the reason says why no follow-up was created).
 */
final class StatusExpiryFollowUpEmission
{
    public const EMITTED = 'EMITTED';

    public const ALREADY_EXISTS = 'ALREADY_EXISTS';

    public const NOT_DUE = 'NOT_DUE';

    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const STALE = 'STALE';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $followUpId = null,
        public readonly ?StatusFollowUpSuppressionReason $reason = null,
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

    public static function notEligible(): self
    {
        return new self(self::NOT_ELIGIBLE);
    }

    public static function stale(StatusFollowUpSuppressionReason $reason): self
    {
        return new self(self::STALE, null, $reason);
    }
}
