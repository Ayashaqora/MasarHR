<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\CredentialType;
use App\Modules\Security\Domain\Exceptions\CredentialAlreadyExistsException;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Credential;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the PASSWORD credential for a principal that has none yet. §6: the plaintext password
 * exists only for the duration of this call and is never persisted or logged — only Hash::make's
 * output is stored.
 */
final class SetInitialPassword
{
    /**
     * @throws InvalidPasswordException
     * @throws CredentialAlreadyExistsException
     */
    public function handle(Principal $principal, string $plaintextPassword): Credential
    {
        $violations = PasswordPolicy::violations($plaintextPassword);
        if ($violations !== []) {
            throw new InvalidPasswordException($violations);
        }

        if (Credential::query()->where('principal_id', $principal->getKey())->exists()) {
            throw new CredentialAlreadyExistsException;
        }

        $now = now();

        return Credential::query()->create([
            'principal_id' => $principal->getKey(),
            'credential_type' => CredentialType::Password,
            'password_hash' => Hash::make($plaintextPassword),
            'password_changed_at' => $now,
        ]);
    }
}
