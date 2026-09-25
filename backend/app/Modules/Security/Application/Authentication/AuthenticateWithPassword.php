<?php

namespace App\Modules\Security\Application\Authentication;

use App\Modules\Security\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Security\Domain\UsernameNormalizer;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Credential;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\Hash;

/**
 * Login flow (§12 of the S03 authorization): normalize -> locate -> verify password -> verify
 * ACTIVE. Every rejection reason (unknown username, wrong password, disabled account) throws the
 * exact same InvalidCredentialsException, deliberately, so the HTTP layer cannot leak which one it
 * was. Session regeneration and establishing the authenticated principal are HTTP/session concerns
 * and are the caller's job (see the Auth presentation controller), not this class's.
 *
 * Hash::check always runs, even when no principal/credential was found, against a fixed dummy hash
 * (computed once, not from user input) — so a request for an unknown username takes about as long
 * as one for a known username with a wrong password, instead of returning instantly and leaking
 * existence through timing.
 */
final class AuthenticateWithPassword
{
    /** A hash of a value nobody can supply; only its shape (a valid hash the current driver can compare against) matters. */
    private const DUMMY_HASH_SEED = '§03-dummy-credential-authenticate-with-password§';

    /** @throws InvalidCredentialsException */
    public function handle(string $username, string $password): Principal
    {
        $normalized = UsernameNormalizer::normalize($username);

        $principal = Principal::query()->where('username_normalized', $normalized)->first();
        $credential = $principal !== null
            ? Credential::query()->where('principal_id', $principal->getKey())->where('credential_type', 'PASSWORD')->first()
            : null;

        $hashToCompare = $credential->password_hash ?? Hash::make(self::DUMMY_HASH_SEED);
        $passwordMatches = Hash::check($password, $hashToCompare);

        if ($principal === null || $credential === null || ! $passwordMatches || ! $principal->isActive()) {
            throw new InvalidCredentialsException;
        }

        return $principal;
    }
}
