<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Organization\Application\Commands\ActivateOrganizationalUnit;
use App\Modules\Organization\Application\Commands\CreateOrganizationalUnit;
use App\Modules\Organization\Application\Commands\DeactivateOrganizationalUnit;
use App\Modules\Organization\Application\Commands\MoveOrganizationalUnit;
use App\Modules\Organization\Application\Commands\RenameOrganizationalUnit;
use App\Modules\Organization\Application\Queries\ListChildOrganizationalUnits;
use App\Modules\Organization\Application\Queries\ListOrganizationalUnitAncestors;
use App\Modules\Organization\Application\Queries\ListOrganizationalUnitDescendants;
use App\Modules\Organization\Application\Queries\ListRootOrganizationalUnits;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Organization\Presentation\Http\Resources\OrganizationalUnitResource;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S07 Organization hierarchy: Create/Rename/Move/Activate/Deactivate plus the five read queries
 * (spec §15/§25). No hard-delete route exists — no S07 route may expose one (spec §9/§34).
 */
class OrganizationalUnitController
{
    public function index(): JsonResponse
    {
        $units = OrganizationalUnit::query()->orderBy('name')->paginate(50);

        return OrganizationalUnitResource::collection($units)->response();
    }

    public function roots(ListRootOrganizationalUnits $query): JsonResponse
    {
        return OrganizationalUnitResource::collection($query())->response();
    }

    public function show(OrganizationalUnit $organizationalUnit): JsonResponse
    {
        return (new OrganizationalUnitResource($organizationalUnit))->response();
    }

    public function children(OrganizationalUnit $organizationalUnit, ListChildOrganizationalUnits $query): JsonResponse
    {
        return OrganizationalUnitResource::collection($query($organizationalUnit))->response();
    }

    public function ancestors(OrganizationalUnit $organizationalUnit, ListOrganizationalUnitAncestors $query): JsonResponse
    {
        return OrganizationalUnitResource::collection($query($organizationalUnit))->response();
    }

    public function descendants(OrganizationalUnit $organizationalUnit, ListOrganizationalUnitDescendants $query): JsonResponse
    {
        return OrganizationalUnitResource::collection($query($organizationalUnit))->response();
    }

    public function store(Request $request, CreateOrganizationalUnit $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'organization.unit.create',
            targetType: 'organization_unit',
            targetId: fn (OrganizationalUnit $created) => $created->getKey(),
            changes: fn (OrganizationalUnit $created) => [
                'name' => $created->name,
                'parent_id' => $created->parent_id,
            ],
            metadata: fn () => [],
        );

        $unit = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['name'], $data['parent_id'] ?? null),
        );

        return (new OrganizationalUnitResource($unit))->response()->setStatusCode(201);
    }

    public function rename(Request $request, OrganizationalUnit $organizationalUnit, RenameOrganizationalUnit $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previousName = $organizationalUnit->name;

        $spec = new AuditSpec(
            action: 'organization.unit.rename',
            targetType: 'organization_unit',
            targetId: fn (OrganizationalUnit $updated) => $updated->getKey(),
            changes: fn (OrganizationalUnit $updated) => ['name' => ['from' => $previousName, 'to' => $updated->name]],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($organizationalUnit, $data['name'], $data['expected_version']),
        );

        return (new OrganizationalUnitResource($updated))->response();
    }

    public function move(Request $request, OrganizationalUnit $organizationalUnit, MoveOrganizationalUnit $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previousParentId = $organizationalUnit->parent_id;

        $spec = new AuditSpec(
            action: 'organization.unit.move',
            targetType: 'organization_unit',
            targetId: fn (OrganizationalUnit $updated) => $updated->getKey(),
            changes: fn (OrganizationalUnit $updated) => ['parent_id' => ['from' => $previousParentId, 'to' => $updated->parent_id]],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($organizationalUnit, $data['parent_id'] ?? null, $data['expected_version']),
        );

        return (new OrganizationalUnitResource($updated))->response();
    }

    public function activate(Request $request, OrganizationalUnit $organizationalUnit, ActivateOrganizationalUnit $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'organization.unit.activate',
            targetType: 'organization_unit',
            targetId: fn (OrganizationalUnit $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($organizationalUnit, $data['expected_version']));

        return (new OrganizationalUnitResource($updated))->response();
    }

    public function deactivate(Request $request, OrganizationalUnit $organizationalUnit, DeactivateOrganizationalUnit $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'organization.unit.deactivate',
            targetType: 'organization_unit',
            targetId: fn (OrganizationalUnit $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($organizationalUnit, $data['expected_version']));

        return (new OrganizationalUnitResource($updated))->response();
    }
}
