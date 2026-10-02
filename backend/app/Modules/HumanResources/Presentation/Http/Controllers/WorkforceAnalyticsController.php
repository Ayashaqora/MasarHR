<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\HumanResources\Application\Queries\Reporting\BuildWorkforceAnalyticsResult;
use App\Modules\HumanResources\Presentation\Http\Resources\WorkforceAnalyticsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S44 Workforce Analytics read surface (docs/workforce-analytics-foundation-specification.md §S44.13). ONE read-only endpoint:
 * GET /api/v1/hr/workforce-analytics?month=YYYY-MM-01, returning the metadata and the analytics sections from ONE computation. Plain RBAC
 * through the route's dedicated permission: middleware (no organizational scope). The only input is `month`, validated here (422) BEFORE the
 * canonical S37 population is computed. No pagination, filter, sort, grouping, range or export.
 */
class WorkforceAnalyticsController
{
    public function index(Request $request, BuildWorkforceAnalyticsResult $analytics): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'string', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/'],
        ]);

        return response()->json(WorkforceAnalyticsResource::toArray($data['month'], $analytics($data['month'])));
    }
}
