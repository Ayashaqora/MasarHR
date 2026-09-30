<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Postgres;

use App\Modules\HumanResources\Domain\RetiredEmploymentStatusCodes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * S34 fail-closed migration guard (M0 only). Return Intention is being separated from the exclusive
 * employment-status stream; that is safe to do WITHOUT converting data only when no status period
 * uses either retired code. If any exists this guard fails the migration outright: it never
 * deletes, converts or reinterprets those rows, never fabricates an underlying status (such as
 * on_duty), and never continues silently. Converting real legacy rows (M1/M2/M3) needs an explicit
 * Architecture Authority decision first.
 */
final class LegacyReturnIntentionStatusGuard
{
    public static function legacyPeriodCount(): int
    {
        return (int) DB::table('hr.employment_status_periods as p')
            ->join('ref.employment_status_details as d', 'd.id', '=', 'p.status_detail_id')
            ->whereIn('d.code', RetiredEmploymentStatusCodes::CODES)
            ->count();
    }

    /** @throws RuntimeException when any legacy status period exists */
    public static function assertNoLegacyStatusPeriods(): void
    {
        $count = self::legacyPeriodCount();

        if ($count > 0) {
            throw new RuntimeException(
                "S34 cannot proceed: {$count} hr.employment_status_periods row(s) use a legacy Return Intention status code ("
                .implode(', ', RetiredEmploymentStatusCodes::CODES)
                .'). Legacy Return Intention status data requires an explicit migration decision before S34 can be applied. '
                .'No data was deleted, converted or altered.'
            );
        }
    }
}
