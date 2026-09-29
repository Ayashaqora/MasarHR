<?php

namespace App\Modules\HumanResources\Domain;

use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkSchedulePeriod;

/**
 * The Work Schedule of an Employment Relationship on one business date
 * (docs/work-schedule-foundation-specification.md §S29.10, ADR-S29-003). Immutable, never
 * persisted — the same result style as S27's ActualWorkplaceAsOf.
 *
 *  - RESOLVED: a schedule period is effective on the date; weekdayCodes() lists its weekdays.
 *  - NOT_RECORDED: no schedule period covers the date. This is UNKNOWN — never a default week
 *    (no Sunday–Thursday, no Monday–Friday, no organizational default).
 */
final class WorkScheduleAsOf
{
    public const RESOLVED = 'RESOLVED';

    public const NOT_RECORDED = 'NOT_RECORDED';

    /** @param list<string> $weekdayCodes */
    private function __construct(
        private readonly string $state,
        private readonly ?WorkSchedulePeriod $period,
        private readonly array $weekdayCodes,
    ) {}

    public static function resolved(WorkSchedulePeriod $period): self
    {
        return new self(self::RESOLVED, $period, $period->weekdayCodes());
    }

    public static function notRecorded(): self
    {
        return new self(self::NOT_RECORDED, null, []);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isRecorded(): bool
    {
        return $this->state === self::RESOLVED;
    }

    public function period(): ?WorkSchedulePeriod
    {
        return $this->period;
    }

    /** @return list<string> stable codes (MONDAY…SUNDAY), ISO order; empty when NOT_RECORDED */
    public function weekdayCodes(): array
    {
        return $this->weekdayCodes;
    }

    public function isScheduledOn(string $weekdayCode): bool
    {
        return in_array($weekdayCode, $this->weekdayCodes, true);
    }
}
