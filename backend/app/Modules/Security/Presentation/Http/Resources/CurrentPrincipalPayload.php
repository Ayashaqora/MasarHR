<?php

namespace App\Modules\Security\Presentation\Http\Resources;

use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;

/**
 * The GET /api/v1/auth/me response body (§21 of the S03 authorization). Never includes
 * password_hash, credentials, session identifiers, or internal secrets — only principal identity,
 * safe role information, and effective permission codes.
 */
final class CurrentPrincipalPayload
{
    public static function build(Principal $principal, EffectivePermissionsResolver $resolver): array
    {
        $roles = Role::query()
            ->join('security.principal_roles as pr', 'pr.role_id', '=', 'security.roles.id')
            ->where('pr.principal_id', $principal->getKey())
            ->orderBy('security.roles.code')
            ->get(['security.roles.id', 'security.roles.code', 'security.roles.name_ar', 'security.roles.name_en', 'security.roles.is_active']);

        return [
            'principal' => [
                'id' => $principal->id,
                'username' => $principal->username,
                'display_name' => $principal->display_name,
            ],
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
                'is_active' => (bool) $role->is_active,
            ])->all(),
            'permissions' => $resolver->resolve($principal),
        ];
    }
}
