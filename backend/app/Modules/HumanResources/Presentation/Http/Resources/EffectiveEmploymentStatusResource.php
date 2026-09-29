<?php

namespace App\Modules\HumanResources\Presentation\Http\Resources;

use App\Modules\HumanResources\Domain\EmploymentStatusAsOf;

/** S32: effective status on a date — persisted vs derived is explicit; a derived value has no id. */
final class EffectiveEmploymentStatusResource
{
    public static function toArray(string $asOf, ?EmploymentStatusAsOf $status): array
    {
        return ['as_of' => $asOf, 'status' => $status === null ? null : [
            'status_detail_id' => $status->statusDetailId,
            'status_detail_code' => $status->statusDetailCode,
            'derived' => $status->derived,
            'period_id' => $status->periodId,
            'derived_from_period_id' => $status->derivedFromPeriodId,
            'effective_from' => $status->effectiveFrom,
            'effective_to' => $status->effectiveTo,
        ]];
    }
}
