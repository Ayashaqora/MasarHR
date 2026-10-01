<?php

namespace App\Modules\HumanResources\Domain;

/**
 * REPORT-1 age (docs/human-cadre-report-foundation-specification.md §S41.10, R1-D41/D50): COMPLETED CALENDAR years at a
 * reference date (the last calendar day of the report month) — never age_days / 365. A Feb-29 birthday has its anniversary on
 * Feb 29 in a leap year and on Feb 28 in a non-leap year. A missing birth_date is NOT_RECORDED; a birth_date after the
 * reference date is NOT_CALCULABLE (the band is NOT_RECORDED and the caller records BIRTH_DATE_AFTER_REPORT_DATE) — never a
 * negative age. Pure: no clock, no database, nothing persisted.
 */
final class CompletedAge
{
    public const NOT_RECORDED = 'NOT_RECORDED';

    public const NOT_CALCULABLE = 'NOT_CALCULABLE';

    public const CALCULABLE = 'CALCULABLE';

    /** @var list<string> the official bands, in display order (NOT_RECORDED last) */
    public const BANDS = ['<25', '25-34', '35-44', '45-54', '55-64', '65+', self::NOT_RECORDED];

    /** @return array{state: string, years: int|null, band: string} */
    public static function at(?string $birthDate, string $referenceDate): array
    {
        if ($birthDate === null) {
            return ['state' => self::NOT_RECORDED, 'years' => null, 'band' => self::NOT_RECORDED];
        }

        if ($birthDate > $referenceDate) {
            return ['state' => self::NOT_CALCULABLE, 'years' => null, 'band' => self::NOT_RECORDED];
        }

        $years = self::completedYears($birthDate, $referenceDate);

        return ['state' => self::CALCULABLE, 'years' => $years, 'band' => self::band($years)];
    }

    public static function completedYears(string $birthDate, string $referenceDate): int
    {
        [$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $birthDate));
        [$refYear, $refMonth, $refDay] = array_map('intval', explode('-', $referenceDate));

        $anniversaryDay = $birthDay;
        if ($birthMonth === 2 && $birthDay === 29 && ! self::isLeap($refYear)) {
            $anniversaryDay = 28;
        }

        $years = $refYear - $birthYear;
        if ([$refMonth, $refDay] < [$birthMonth, $anniversaryDay]) {
            $years--;
        }

        return $years;
    }

    public static function band(int $years): string
    {
        return match (true) {
            $years < 25 => '<25',
            $years < 35 => '25-34',
            $years < 45 => '35-44',
            $years < 55 => '45-54',
            $years < 65 => '55-64',
            default => '65+',
        };
    }

    private static function isLeap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }
}
