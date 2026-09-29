<?php

namespace App\Modules\HumanResources\Domain;

/**
 * S32 (ADR-S32-003): the effective employment status of one relationship on one business date,
 * distinguishing a PERSISTED period from the DERIVED on_duty that follows an expired bounded
 * status. A derived value has no row of its own: no id, no recorded_at, no actor, no decision.
 */
final class EmploymentStatusAsOf
{
    private function __construct(
        public readonly string $statusDetailId,
        public readonly string $statusDetailCode,
        public readonly bool $derived,
        public readonly ?string $periodId,
        public readonly ?string $derivedFromPeriodId,
        public readonly ?string $effectiveFrom,
        public readonly ?string $effectiveTo,
    ) {}

    public static function persisted(string $periodId, string $detailId, string $code, string $from, ?string $to): self
    {
        return new self($detailId, $code, false, $periodId, null, $from, $to);
    }

    /** $from is the end (exclusive boundary) of the expired bounded period the return follows. */
    public static function derivedOnDuty(string $detailId, string $derivedFromPeriodId, string $from): self
    {
        return new self($detailId, BoundedEmploymentStatusPolicy::DERIVED_RETURN_CODE, true, null, $derivedFromPeriodId, $from, null);
    }
}
