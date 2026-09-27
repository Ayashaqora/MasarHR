<?php

namespace App\Modules\Reference\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Application\Commands\DefineSpecialtyCadreCategoryMappingPeriod;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MonthlyCadreCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\SpecialtyCadreCategoryMapping;
use App\Modules\Reference\Presentation\Http\Resources\SpecialtyCadreCategoryMappingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S06 spec §12.2/§14/§15: only two operations — list the mapping periods defined for a specialty,
 * and define a new one. No update/delete route exists; a period, once inserted, is never changed
 * (append-only), identical shape to EmploymentStatusDetailBehaviorController.
 */
class SpecialtyCadreCategoryMappingController
{
    public function index(Specialty $specialty): JsonResponse
    {
        $periods = SpecialtyCadreCategoryMapping::query()
            ->where('specialty_id', $specialty->getKey())
            ->orderBy('effective_from')
            ->get();

        return SpecialtyCadreCategoryMappingResource::collection($periods)->response();
    }

    /** @throws OverlappingBehaviorPeriodException */
    public function store(
        Request $request,
        Specialty $specialty,
        DefineSpecialtyCadreCategoryMappingPeriod $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        // Existence is enforced by findOrFail() below, not by an `exists:` validation rule: that
        // rule's string form parses a dotted table name as "connection.table" (see
        // ValidationRuleParser::parseTable()), and "ref" is a schema, not a connection — same
        // established precedent as RoleController::grantPermission().
        $data = $request->validate([
            'cadre_category_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ]);

        $cadreCategory = MonthlyCadreCategory::query()->findOrFail($data['cadre_category_id']);
        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'reference.specialty_cadre_category_mapping.period.define',
            targetType: 'reference_specialty_cadre_category_mapping',
            targetId: fn (SpecialtyCadreCategoryMapping $created) => $created->getKey(),
            changes: fn (SpecialtyCadreCategoryMapping $created) => [
                'specialty_id' => $created->specialty_id,
                'cadre_category_id' => $created->cadre_category_id,
                'effective_from' => $created->effective_from?->toDateString(),
                'effective_to' => $created->effective_to?->toDateString(),
            ],
            metadata: fn () => [],
        );

        $period = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($specialty, $cadreCategory, $data['effective_from'], $data['effective_to'] ?? null),
        );

        return (new SpecialtyCadreCategoryMappingResource($period))->response()->setStatusCode(201);
    }
}
