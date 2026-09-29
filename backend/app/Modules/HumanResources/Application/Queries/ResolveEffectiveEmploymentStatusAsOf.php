<?php

namespace App\Modules\HumanResources\Application\Queries;

use App\Modules\HumanResources\Domain\BoundedEmploymentStatusPolicy;
use App\Modules\HumanResources\Domain\EmploymentStatusAsOf;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentStatusPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S32 (docs/bounded-temporary-employment-status-lifecycle-specification.md §S32.4, ADR-S32-003):
 * the effective status on a business date. A covering persisted period wins; otherwise, when the
 * relationship is effective on the date and the latest period starting on/before it is an expired
 * allow-listed bounded period, the status is DERIVED on_duty (read-time only, nothing persisted).
 * Any other gap stays unresolved (null) — legacy data is never re-interpreted.
 */
final class ResolveEffectiveEmploymentStatusAsOf
{
    public function __invoke(EmploymentRelationship $relationship, string|Carbon $date): ?EmploymentStatusAsOf
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        $persisted = app(ResolveEmploymentStatusForRelationshipAsOf::class)($relationship, $date);

        if ($persisted !== null) {
            $code = DB::table('ref.employment_status_details')->where('id', $persisted->status_detail_id)->value('code');

            return EmploymentStatusAsOf::persisted(
                $persisted->getKey(), $persisted->status_detail_id, $code,
                $persisted->effective_from->toDateString(), $persisted->effective_to?->toDateString(),
            );
        }

        if ($relationship->effective_from->toDateString() > $date) {
            return null;
        }

        if ($relationship->end_knowledge_state === 'KNOWN' && $relationship->effective_to->toDateString() <= $date) {
            return null;
        }

        $latest = EmploymentStatusPeriod::query()
            ->where('employment_relationship_id', $relationship->getKey())
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if ($latest === null || $latest->effective_to === null || $latest->effective_to->toDateString() > $date) {
            return null;
        }

        $code = DB::table('ref.employment_status_details')->where('id', $latest->status_detail_id)->value('code');

        if (! BoundedEmploymentStatusPolicy::supportsEnd((string) $code)) {
            return null;
        }

        $onDutyId = DB::table('ref.employment_status_details')->where('code', BoundedEmploymentStatusPolicy::DERIVED_RETURN_CODE)->value('id');

        return EmploymentStatusAsOf::derivedOnDuty($onDutyId, $latest->getKey(), $latest->effective_to->toDateString());
    }
}
