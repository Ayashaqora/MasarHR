<?php

namespace App\Modules\HumanResources\Domain;

/**
 * The Person/month duty classification (docs/monthly-workforce-reporting-semantics-foundation-specification.md
 * §S37.7). Evaluated ONLY over days on which an Employment Relationship is effective.
 *
 *  - HAS_ON_DUTY: at least one explicit or S32-derived on_duty interval inside any active relationship segment.
 *  - INDETERMINATE: no on_duty interval AND at least one active day is not deterministically covered — an
 *    UNRESOLVED status gap, or a relationship whose end is UNKNOWN_LEGACY (its active days cannot be asserted).
 *  - NO_ON_DUTY: no on_duty interval AND every active day is covered by a known non-on_duty status.
 *
 * Precedence: HAS_ON_DUTY > INDETERMINATE > NO_ON_DUTY. A gap is never read as on_duty and never as not-on-duty.
 * Pure. It never reads counts_in_monthly_reporting or any behavior flag.
 */
final class MonthlyDutyClassification
{
    public const HAS_ON_DUTY = 'HAS_ON_DUTY';

    public const NO_ON_DUTY = 'NO_ON_DUTY';

    public const INDETERMINATE = 'INDETERMINATE';

    /**
     * @param  list<list<array<string, mixed>>>  $statusSegmentsPerRelationship
     * @param  list<bool>  $endIsUncertainPerRelationship
     */
    public static function classify(array $statusSegmentsPerRelationship, array $endIsUncertainPerRelationship): string
    {
        $indeterminate = in_array(true, $endIsUncertainPerRelationship, true);

        foreach ($statusSegmentsPerRelationship as $segments) {
            foreach ($segments as $segment) {
                if ($segment['is_on_duty'] === true) {
                    return self::HAS_ON_DUTY;
                }
                if ($segment['kind'] === MonthlyStatusSegmentation::UNRESOLVED) {
                    $indeterminate = true;
                }
            }
        }

        return $indeterminate ? self::INDETERMINATE : self::NO_ON_DUTY;
    }
}
