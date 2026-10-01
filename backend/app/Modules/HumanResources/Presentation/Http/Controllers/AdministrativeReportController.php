<?php

namespace App\Modules\HumanResources\Presentation\Http\Controllers;

use App\Modules\HumanResources\Application\Queries\Reporting\BuildAdministrativeReportResult;
use App\Modules\HumanResources\Presentation\Http\Resources\AdministrativeReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S42 R2 read surface (docs/administrative-report-foundation-specification.md §S42.13). ONE read-only endpoint:
 * GET /api/v1/hr/administrative-report?month=YYYY-MM-01, returning the metadata, the official sections and the canonical Person
 * records from ONE computation. Plain RBAC through the route's dedicated permission: middleware (no organizational scope). The only
 * input is `month`, validated here (422) BEFORE the canonical S37 population is computed. No pagination, filter, sort or export.
 */
class AdministrativeReportController
{
    public function index(Request $request, BuildAdministrativeReportResult $report): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'string', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/'],
        ]);

        return response()->json(AdministrativeReportResource::toArray($data['month'], $report($data['month'])));
    }
}
