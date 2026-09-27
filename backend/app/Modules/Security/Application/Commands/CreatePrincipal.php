<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Domain\Exceptions\DuplicateUsernameException;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Domain\UsernameNormalizer;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;

/**
 * Creates a principal with no credential. Pair with SetInitialPassword — the two are separate,
 * explicit use cases (§11 of the S03 authorization), never a single implicit "register" action.
 */
final class CreatePrincipal
{
    /** @throws DuplicateUsernameException */
    public function handle(string $username, string $displayName): Principal
    {
        $principal = new Principal([
            'username' => $username,
            'username_normalized' => UsernameNormalizer::normalize($username),
            'display_name' => $displayName,
        ]);

        // `status` is intentionally not mass-assignable (see Principal::class docblock), so the
        // initial ACTIVE status is set through direct property assignment here — this is the one
        // and only place a Principal is born, and CreatePrincipal is itself the authorized command
        // for that; every subsequent status change goes through ChangePrincipalStatus instead.
        $principal->status = PrincipalStatus::Active;

        try {
            $principal->save();
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateUsernameException;
            }

            throw $e;
        }

        return $principal;
    }
}
