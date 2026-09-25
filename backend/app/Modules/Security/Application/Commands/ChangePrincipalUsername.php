<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use App\Modules\Security\Domain\Exceptions\DuplicateUsernameException;
use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Domain\UsernameNormalizer;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Optimistic concurrency (§18): the UPDATE is scoped to the caller's expected version. If no row
 * matched, either the version is stale or the principal is gone — distinguished so a genuinely
 * missing principal still 404s instead of masquerading as a conflict.
 */
final class ChangePrincipalUsername
{
    /**
     * @throws DuplicateUsernameException
     * @throws StaleVersionException
     */
    public function handle(Principal $principal, string $newUsername, int $expectedVersion): Principal
    {
        try {
            return DB::transaction(function () use ($principal, $newUsername, $expectedVersion) {
                $updated = Principal::query()
                    ->where('id', $principal->getKey())
                    ->where('version', $expectedVersion)
                    ->update([
                        'username' => $newUsername,
                        'username_normalized' => UsernameNormalizer::normalize($newUsername),
                        'version' => $expectedVersion + 1,
                    ]);

                if ($updated === 0) {
                    Principal::query()->where('id', $principal->getKey())->firstOrFail();

                    throw new StaleVersionException;
                }

                return $principal->refresh();
            });
        } catch (QueryException $e) {
            if (Errors::isUniqueViolation($e)) {
                throw new DuplicateUsernameException;
            }

            throw $e;
        }
    }
}
