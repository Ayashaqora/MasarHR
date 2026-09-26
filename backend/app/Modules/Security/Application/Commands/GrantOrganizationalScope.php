<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Domain\Exceptions\DuplicateOrganizationalScopeGrantException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\OrganizationalScopeGrant;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Grants a principal either GLOBAL scope or UNIT scope over a specific organizational unit
 * (spec §12). Exactly two partial UNIQUE indexes at the database level are what actually prevent a
 * duplicate-grant race (spec §10) — the same shape AssignRoleToPrincipal/GrantPermissionToRole
 * already rely on for their own tables.
 */
final class GrantOrganizationalScope
{
    /** @throws DuplicateOrganizationalScopeGrantException|InvalidArgumentException */
    public function handle(
        Principal $principal,
        string $scopeKind,
        ?OrganizationalUnit $unit,
        ?Principal $grantedBy,
    ): OrganizationalScopeGrant {
        if (! in_array($scopeKind, ['GLOBAL', 'UNIT'], true)) {
            throw new InvalidArgumentException("Invalid scope_kind: {$scopeKind}");
        }

        if ($scopeKind === 'UNIT' && $unit === null) {
            throw new InvalidArgumentException('A UNIT scope grant requires an organizational unit.');
        }

        if ($scopeKind === 'GLOBAL' && $unit !== null) {
            throw new InvalidArgumentException('A GLOBAL scope grant must not reference an organizational unit.');
        }

        $grant = new OrganizationalScopeGrant([
            'principal_id' => $principal->getKey(),
            'scope_kind' => $scopeKind,
            'organizational_unit_id' => $unit?->getKey(),
            'granted_at' => now(),
            'granted_by' => $grantedBy?->getKey(),
        ]);

        try {
            $grant->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateOrganizationalScopeGrantException;
            }

            throw $e;
        }

        return $grant;
    }
}
