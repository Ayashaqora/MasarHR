<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default hash driver
    |--------------------------------------------------------------------------
    |
    | S03 password policy (§6 of the S03 authorization) requires supporting long passphrases
    | without silently truncating them. bcrypt silently truncates input past 72 bytes, which would
    | violate that requirement, so MasarHR uses argon2id (PHP's built-in password_hash support)
    | instead. PasswordPolicy still enforces an explicit maximum length, but that is a stated
    | validation rule the caller sees, never a silent truncation.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => (int) env('HASH_ARGON_MEMORY', 65536),
        'threads' => (int) env('HASH_ARGON_THREADS', 1),
        'time' => (int) env('HASH_ARGON_TIME', 4),
        'verify' => true,
    ],

];
