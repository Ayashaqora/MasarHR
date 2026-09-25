<?php

namespace App\Modules\Security\Domain;

/**
 * Centralized, reusable password policy (§6 of the S03 authorization). Every write path that
 * accepts a plaintext password — SetInitialPassword, ChangePassword, ResetPasswordAdministratively,
 * and the bootstrap command — validates through this class so the rule lives in exactly one place.
 *
 * Minimum length favors passphrases over complexity rules. The maximum is an explicit, stated
 * limit (never a silent truncation) chosen to stay comfortably under argon2id's practical input
 * size while still allowing long passphrases.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 256;

    /** @return list<string> Validation failure messages; empty means the password is acceptable. */
    public static function violations(string $password): array
    {
        $violations = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $violations[] = sprintf('Password must be at least %d characters.', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = sprintf('Password must be at most %d characters.', self::MAX_LENGTH);
        }

        return $violations;
    }

    public static function isValid(string $password): bool
    {
        return self::violations($password) === [];
    }
}
