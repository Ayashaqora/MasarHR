<?php

namespace App\Modules\Platform\Infrastructure\Persistence\Postgres;

use PDOException;
use Throwable;

/**
 * Classifies PostgreSQL failures by SQLSTATE.
 *
 * Later stages use this to translate database-level integrity violations (unique, exclusion,
 * check, foreign key) into domain errors, and to decide whether a failed transaction is safe to
 * retry. It performs no retries itself and holds no business knowledge.
 *
 * @see https://www.postgresql.org/docs/current/errcodes-appendix.html
 */
final class PostgresErrorClassifier
{
    public const NOT_NULL_VIOLATION = '23502';

    public const FOREIGN_KEY_VIOLATION = '23503';

    public const UNIQUE_VIOLATION = '23505';

    public const CHECK_VIOLATION = '23514';

    public const EXCLUSION_VIOLATION = '23P01';

    public const SERIALIZATION_FAILURE = '40001';

    public const DEADLOCK_DETECTED = '40P01';

    public const LOCK_NOT_AVAILABLE = '55P03';

    /** Returns the 5-character SQLSTATE found anywhere in the exception chain, or null. */
    public static function sqlState(Throwable $error): ?string
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && isset($current->errorInfo[0]) && is_string($current->errorInfo[0])) {
                return $current->errorInfo[0];
            }

            $code = $current->getCode();
            if (is_string($code) && preg_match('/^[0-9A-Z]{5}$/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    public static function isUniqueViolation(Throwable $error): bool
    {
        return self::sqlState($error) === self::UNIQUE_VIOLATION;
    }

    public static function isExclusionViolation(Throwable $error): bool
    {
        return self::sqlState($error) === self::EXCLUSION_VIOLATION;
    }

    public static function isCheckViolation(Throwable $error): bool
    {
        return self::sqlState($error) === self::CHECK_VIOLATION;
    }

    public static function isForeignKeyViolation(Throwable $error): bool
    {
        return self::sqlState($error) === self::FOREIGN_KEY_VIOLATION;
    }

    public static function isNotNullViolation(Throwable $error): bool
    {
        return self::sqlState($error) === self::NOT_NULL_VIOLATION;
    }

    public static function isLockNotAvailable(Throwable $error): bool
    {
        return self::sqlState($error) === self::LOCK_NOT_AVAILABLE;
    }

    public static function isSerializationFailure(Throwable $error): bool
    {
        return self::sqlState($error) === self::SERIALIZATION_FAILURE;
    }

    public static function isDeadlock(Throwable $error): bool
    {
        return self::sqlState($error) === self::DEADLOCK_DETECTED;
    }

    /**
     * True only for failures PostgreSQL itself documents as "retry the whole transaction":
     * serialization failures and detected deadlocks. Integrity violations and lock-not-available
     * are never retryable.
     *
     * Retrying is only safe when the retried unit of work is idempotent and has no side effects
     * outside the database transaction; that judgement belongs to the calling application service.
     */
    public static function isRetryable(Throwable $error): bool
    {
        return self::isSerializationFailure($error) || self::isDeadlock($error);
    }
}
