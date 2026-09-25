<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\CredentialType;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Credential;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\Hash;

/**
 * An administrator replaces another principal's password without knowing the old one. Unlike
 * SetInitialPassword this may replace an existing credential (upsert), which is what
 * distinguishes "reset" from "set initial".
 */
final class ResetPasswordAdministratively
{
    /** @throws InvalidPasswordException */
    public function handle(Principal $principal, string $newPassword): Credential
    {
        $violations = PasswordPolicy::violations($newPassword);
        if ($violations !== []) {
            throw new InvalidPasswordException($violations);
        }

        $now = now();

        $credential = Credential::query()
            ->where('principal_id', $principal->getKey())
            ->where('credential_type', CredentialType::Password->value)
            ->first();

        if ($credential === null) {
            return Credential::query()->create([
                'principal_id' => $principal->getKey(),
                'credential_type' => CredentialType::Password,
                'password_hash' => Hash::make($newPassword),
                'password_changed_at' => $now,
            ]);
        }

        $credential->forceFill([
            'password_hash' => Hash::make($newPassword),
            'password_changed_at' => $now,
        ])->save();

        return $credential;
    }
}
