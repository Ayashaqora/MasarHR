<?php

namespace App\Modules\Security\Infrastructure\Authorization;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Security\Application\Queries\ResolveEffectiveOrganizationalScope;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

/**
 * The reusable authorization boundary the S08 authorization's §4 calls for: "Does Principal P have
 * Permission X within Organizational Unit U?" (spec §11). Composes the existing, unmodified S03
 * EffectivePermissionsResolver (WHAT) with the new S08 scope resolution (WHERE) — neither dimension
 * knows about the other. No HR/Reporting caller exists yet; this class exists so a future one can
 * ask the question without reimplementing either dimension.
 */
final class ScopedAuthorizationChecker
{
    public function __construct(
        private readonly EffectivePermissionsResolver $effectivePermissions,
        private readonly ResolveEffectiveOrganizationalScope $resolveScope,
    ) {}

    public function authorize(Principal $principal, string $permissionCode, OrganizationalUnit $target): bool
    {
        // Spec §13: an inactive target is never an eligible authorization target — checked first,
        // before either dimension, so inactive hierarchy state can never become a loophole.
        if (! $target->is_active) {
            return false;
        }

        if (! $this->effectivePermissions->has($principal, $permissionCode)) {
            return false;
        }

        return $this->resolveScope->__invoke($principal)->covers($target->getKey());
    }
}
