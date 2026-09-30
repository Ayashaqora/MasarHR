<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\HumanResources\Application\Queries\Reporting\BuildMonthlyNotOnDutyResult;
use App\Modules\HumanResources\Presentation\Http\Resources\NotOnDutyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S39 REPORT-3 read surface (docs/monthly-not-on-duty-report-foundation-specification.md §S39.11–§S39.13). ONE read-only
 * endpoint: GET /api/v1/hr/not-on-duty?month=YYYY-MM-01, returning the report metadata, the summary and the REPORT-3 Person rows
 * from ONE report computation. RBAC comes from the route's dedicated permission: middleware; plain RBAC by the frozen decision
 * (no organizational scope: a monthly Person may have several workplace segments, so no unit can be chosen for authorization).
 * The only public input is `month`: an explicit first day, validated here so an invalid value is a 422 BEFORE the canonical S37
 * population is computed.
 */
class NotOnDutyController
{
    public function index(Request $request, BuildMonthlyNotOnDutyResult $report): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'string', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/'],
        ]);

        return response()->json(NotOnDutyResource::toArray($data['month'], $report($data['month'])));
    }
}
