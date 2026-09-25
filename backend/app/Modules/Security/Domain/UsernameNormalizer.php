<?php

namespace App\Modules\Security\Domain;

/**
 * The single canonical implementation of username normalization (§5 of the S03 authorization):
 * "Admin" and "admin" must never become two principals. Every place that reads or writes
 * username_normalized — CreatePrincipal, ChangePrincipalUsername, and login — calls this.
 */
final class UsernameNormalizer
{
    public static function normalize(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
