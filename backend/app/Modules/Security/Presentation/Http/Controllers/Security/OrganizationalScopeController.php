<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Application\Commands\GrantOrganizationalScope;
use App\Modules\Security\Application\Commands\RevokeOrganizationalScope;
use App\Modules\Security\Application\Queries\ListOrganizationalScopeGrantsForPrincipal;
use App\Modules\Security\Application\Queries\ResolveEffectiveOrganizationalScope;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\OrganizationalScopeGrant;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Presentation\Http\Resources\OrganizationalScopeGrantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S08 administration surface (spec §18): grant/list/revoke a principal's organizational scope
 * grants, plus a read-only effective-scope resolution endpoint. All four routes are gated by the
 * single security.organization_scopes.manage permission — there is no separate "view" permission,
 * mirroring the existing ROLE_ASSIGNMENTS_MANAGE precedent. Mutations route through
 * AuditedCommandExecutor exactly like every other audited mutation in the codebase (spec §16).
 */
class OrganizationalScopeController
{
    public function index(Principal $principal, ListOrganizationalScopeGrantsForPrincipal $query): JsonResponse
    {
        return OrganizationalScopeGrantResource::collection($query($principal))->response();
    }

    public function effective(Principal $principal, ResolveEffectiveOrganizationalScope $query): JsonResponse
    {
        $scope = $query($principal);

        return response()->json([
            'is_global' => $scope->isGlobal(),
            'organizational_unit_ids' => $scope->unitIds(),
        ]);
    }

    public function store(
        Request $request,
        Principal $principal,
        GrantOrganizationalScope $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'scope_kind' => ['required', 'string', 'in:GLOBAL,UNIT'],
            'organizational_unit_id' => ['required_if:scope_kind,UNIT', 'prohibited_if:scope_kind,GLOBAL', 'nullable', 'uuid'],
        ]);

        $unit = null;

        if ($data['scope_kind'] === 'UNIT') {
            $unit = OrganizationalUnit::query()->find($data['organizational_unit_id']);

            if ($unit === null) {
                throw new NotFoundHttpException('Organizational unit not found.');
            }
        }

        /** @var Principal|null $actor */
        $actor = Auth::guard('web')->user();

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.organizational_scope.grant',
            targetType: 'security_organizational_scope_grant',
            targetId: fn (OrganizationalScopeGrant $created) => $created->getKey(),
            changes: fn (OrganizationalScopeGrant $created) => [
                'scope_kind' => $created->scope_kind,
                'organizational_unit_id' => $created->organizational_unit_id,
            ],
            metadata: fn () => [],
        );

        $grant = $executor->run(
            $context,
            $spec,
            fn (): OrganizationalScopeGrant => $command->handle($principal, $data['scope_kind'], $unit, $actor),
        );

        return (new OrganizationalScopeGrantResource($grant))->response()->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        Principal $principal,
        OrganizationalScopeGrant $organizationalScopeGrant,
        RevokeOrganizationalScope $command,
        AuditedCommandExecutor $executor,
    ): Response {
        if ($organizationalScopeGrant->principal_id !== $principal->getKey()) {
            throw new NotFoundHttpException('Organizational scope grant not found for this principal.');
        }

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.organizational_scope.revoke',
            targetType: 'security_organizational_scope_grant',
            targetId: fn () => $principal->getKey().':'.$organizationalScopeGrant->getKey(),
            changes: fn () => [],
            metadata: fn () => [],
        );

        $executor->run($context, $spec, fn () => $command->handle($organizationalScopeGrant));

        return response()->noContent();
    }
}
