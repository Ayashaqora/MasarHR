<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Application\Commands\ActivateRole;
use App\Modules\Security\Application\Commands\CreateRole;
use App\Modules\Security\Application\Commands\DeactivateRole;
use App\Modules\Security\Application\Commands\GrantPermissionToRole;
use App\Modules\Security\Application\Commands\RevokePermissionFromRole;
use App\Modules\Security\Application\Commands\UpdateRoleMetadata;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\RolePermission;
use App\Modules\Security\Presentation\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * S04 retrofit: every write method routes through AuditedCommandExecutor so its mutation and its
 * MUTATION audit entry commit or roll back together (AUD-01/AUD-02). deactivate() and
 * revokePermission() additionally guard via SecurityAdministrationGuard::protect() (ERRATA-02) and,
 * on LastSecurityAdministratorException, record the rejection as an independent SECURITY_EVENT
 * after the executor's transaction has already rolled back (D10) before rethrowing.
 */
class RoleController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->orderBy('code')->paginate(50);

        return RoleResource::collection($roles)->response();
    }

    public function show(Role $role): JsonResponse
    {
        return (new RoleResource($role))->response();
    }

    public function store(Request $request, CreateRole $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role.create',
            targetType: 'security_role',
            targetId: fn (Role $created) => $created->getKey(),
            // §16: safe display fields only — description is deliberately excluded from the allowlist.
            changes: fn (Role $created) => [
                'code' => $created->code,
                'name_ar' => $created->name_ar,
                'name_en' => $created->name_en,
            ],
            metadata: fn () => [],
        );

        $role = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($data['code'], $data['name_ar'], $data['name_en'], $data['description'] ?? null),
        );

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, Role $role, UpdateRoleMetadata $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previous = ['name_ar' => $role->name_ar, 'name_en' => $role->name_en];

        $spec = new AuditSpec(
            action: 'security.role.metadata.update',
            targetType: 'security_role',
            targetId: fn (Role $updated) => $updated->getKey(),
            // §16: allowlist is {name_ar, name_en} only — description is deliberately excluded even
            // though the command does update it.
            changes: fn (Role $updated) => [
                'name_ar' => ['from' => $previous['name_ar'], 'to' => $updated->name_ar],
                'name_en' => ['from' => $previous['name_en'], 'to' => $updated->name_en],
            ],
            metadata: fn () => [],
        );

        $updated = $executor->run(
            $context,
            $spec,
            fn () => $command->handle($role, $data['name_ar'], $data['name_en'], $data['description'] ?? null, $data['expected_version']),
        );

        return (new RoleResource($updated))->response();
    }

    public function activate(Request $request, Role $role, ActivateRole $command, AuditedCommandExecutor $executor): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role.activate',
            targetType: 'security_role',
            targetId: fn (Role $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => false, 'to' => true]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($role, $data['expected_version']));

        return (new RoleResource($updated))->response();
    }

    public function deactivate(
        Request $request,
        Role $role,
        DeactivateRole $command,
        SecurityAdministrationGuard $guard,
        AuditedCommandExecutor $executor,
        AuditSecurityEventRecorder $recorder,
    ): JsonResponse {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role.deactivate',
            targetType: 'security_role',
            targetId: fn (Role $updated) => $updated->getKey(),
            changes: fn () => ['is_active' => ['from' => true, 'to' => false]],
            metadata: fn () => [],
        );

        // Deactivating a role can reduce security-administration capability (§17); guarded inside
        // the executor's own transaction (ERRATA-02).
        try {
            $updated = $executor->run($context, $spec, fn () => $guard->protect(fn () => $command->handle($role, $data['expected_version'])));
        } catch (LastSecurityAdministratorException $e) {
            $recorder->record(
                context: $context,
                action: 'security.role.deactivate',
                targetType: 'security_role',
                targetId: $role->getKey(),
                outcome: Outcome::Rejected,
                metadata: ['rejection_reason' => 'LAST_SECURITY_ADMINISTRATOR'],
            );

            throw $e;
        }

        return (new RoleResource($updated))->response();
    }

    public function grantPermission(Request $request, Role $role, GrantPermissionToRole $command, AuditedCommandExecutor $executor): Response
    {
        // Existence is enforced by findOrFail() below, not by an `exists:` validation rule: that
        // rule's string form parses a dotted table name as "connection.table" (see
        // ValidationRuleParser::parseTable()), and "security" is a schema, not a connection.
        $data = $request->validate(['permission_id' => ['required', 'uuid']]);

        $permission = Permission::query()->findOrFail($data['permission_id']);
        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role_permission.grant',
            targetType: 'security_role_permission',
            targetId: fn () => $role->getKey().':'.$permission->getKey(),
            // §16: allowlist is {} — the composite target_id already carries the permission id.
            changes: fn () => [],
            metadata: fn () => [],
        );

        $executor->run($context, $spec, fn (): RolePermission => $command->handle($role, $permission));

        return response()->noContent()->setStatusCode(201);
    }

    public function revokePermission(
        Request $request,
        Role $role,
        Permission $permission,
        RevokePermissionFromRole $command,
        SecurityAdministrationGuard $guard,
        AuditedCommandExecutor $executor,
        AuditSecurityEventRecorder $recorder,
    ): Response {
        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role_permission.revoke',
            targetType: 'security_role_permission',
            targetId: fn () => $role->getKey().':'.$permission->getKey(),
            // §16: allowlist is {} — the composite target_id already carries the permission id.
            changes: fn () => [],
            metadata: fn () => [],
        );

        // Revoking a permission from a role can reduce security-administration capability (§17);
        // guarded inside the executor's own transaction (ERRATA-02).
        try {
            $executor->run($context, $spec, fn () => $guard->protect(fn () => $command->handle($role, $permission)));
        } catch (LastSecurityAdministratorException $e) {
            $recorder->record(
                context: $context,
                action: 'security.role_permission.revoke',
                targetType: 'security_role_permission',
                targetId: $role->getKey().':'.$permission->getKey(),
                outcome: Outcome::Rejected,
                metadata: ['rejection_reason' => 'LAST_SECURITY_ADMINISTRATOR'],
            );

            throw $e;
        }

        return response()->noContent();
    }
}
