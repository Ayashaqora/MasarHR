<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\HumanResources\Application\Queries\Reporting\BuildHumanCadreResult;
use App\Modules\HumanResources\Presentation\Http\Resources\HumanCadreResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S41 REPORT-1 read surface (docs/human-cadre-report-foundation-specification.md §S41.13). ONE read-only endpoint:
 * GET /api/v1/hr/human-cadre?month=YYYY-MM-01, returning the metadata, the overall headcount, the official summaries and the
 * canonical Person rows from ONE computation. Plain RBAC through the route's dedicated permission: middleware (no organizational
 * scope). The only input is `month`, validated here (422) BEFORE the canonical S37 population is computed. No pagination, no
 * export, no filter: every summary bucket is reproducible by filtering the rows.
 */
class HumanCadreController
{
    public function index(Request $request, BuildHumanCadreResult $report): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'string', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/'],
        ]);

        return response()->json(HumanCadreResource::toArray($data['month'], $report($data['month'])));
    }
}
