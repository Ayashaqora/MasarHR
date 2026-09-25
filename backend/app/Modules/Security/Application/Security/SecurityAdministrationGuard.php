<?php

namespace App\Modules\Security\Application\Security;

use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Domain\SecurityAdministrationCapability;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\DB;

/**
 * Enforces §17 of the S03 authorization: no administrative operation may leave zero ACTIVE
 * principals capable of security administration/recovery.
 *
 * Concurrency: two concurrent operations must not both independently observe "another
 * administrator exists" and then both remove the last two capable administrators. A PostgreSQL
 * transaction-scoped advisory lock (pg_advisory_xact_lock) serializes every guarded operation on a
 * single, fixed key: the second concurrent caller blocks until the first commits or rolls back, so
 * it always re-checks capability against the post-first-operation state. This is the minimal
 * primitive for the job — no new locking architecture beyond what S02 already established
 * (PostgreSQL row/transaction locking) and no generic saga/outbox machinery.
 */
final class SecurityAdministrationGuard
{
    /**
     * A fixed, arbitrary 63-bit key for pg_advisory_xact_lock. Chosen once and never reused for
     * another purpose; changing it has no data effect, it only changes which lock name is used.
     */
    private const ADVISORY_LOCK_KEY = 774_411_002_003;

    public function __construct(private readonly EffectivePermissionsResolver $effectivePermissions) {}

    /**
     * Runs $operation inside a transaction, serialized against every other guarded operation, and
     * rolls the whole transaction back if the result leaves no capable ACTIVE principal.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws LastSecurityAdministratorException
     */
    public function run(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            DB::statement('select pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK_KEY]);

            $result = $operation();

            if (! $this->atLeastOneCapableActivePrincipalExists()) {
                throw new LastSecurityAdministratorException;
            }

            return $result;
        });
    }

    private function atLeastOneCapableActivePrincipalExists(): bool
    {
        $activePrincipals = Principal::query()
            ->where('status', 'ACTIVE')
            ->get();

        foreach ($activePrincipals as $principal) {
            if (SecurityAdministrationCapability::isCapable($this->effectivePermissions->resolve($principal))) {
                return true;
            }
        }

        return false;
    }
}
