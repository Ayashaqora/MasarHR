<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Security\Application\Commands\ActivateRole;
use App\Modules\Security\Application\Commands\CreateRole;
use App\Modules\Security\Application\Commands\DeactivateRole;
use App\Modules\Security\Application\Commands\GrantPermissionToRole;
use App\Modules\Security\Application\Commands\RevokePermissionFromRole;
use App\Modules\Security\Application\Commands\UpdateRoleMetadata;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use App\Modules\Security\Presentation\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function store(Request $request, CreateRole $command): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $role = $command->handle($data['code'], $data['name_ar'], $data['name_en'], $data['description'] ?? null);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function updateMetadata(Request $request, Role $role, UpdateRoleMetadata $command): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $command->handle($role, $data['name_ar'], $data['name_en'], $data['description'] ?? null, $data['expected_version']);

        return (new RoleResource($updated))->response();
    }

    public function activate(Request $request, Role $role, ActivateRole $command): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        $updated = $command->handle($role, $data['expected_version']);

        return (new RoleResource($updated))->response();
    }

    public function deactivate(Request $request, Role $role, DeactivateRole $command, SecurityAdministrationGuard $guard): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        // Deactivating a role can reduce security-administration capability (§17).
        $updated = $guard->run(fn () => $command->handle($role, $data['expected_version']));

        return (new RoleResource($updated))->response();
    }

    public function grantPermission(Request $request, Role $role, GrantPermissionToRole $command): Response
    {
        // Existence is enforced by findOrFail() below, not by an `exists:` validation rule: that
        // rule's string form parses a dotted table name as "connection.table" (see
        // ValidationRuleParser::parseTable()), and "security" is a schema, not a connection.
        $data = $request->validate(['permission_id' => ['required', 'uuid']]);

        $permission = Permission::query()->findOrFail($data['permission_id']);
        $command->handle($role, $permission);

        return response()->noContent()->setStatusCode(201);
    }

    public function revokePermission(Role $role, Permission $permission, RevokePermissionFromRole $command, SecurityAdministrationGuard $guard): Response
    {
        // Revoking a permission from a role can reduce security-administration capability (§17).
        $guard->run(fn () => $command->handle($role, $permission));

        return response()->noContent();
    }
}
