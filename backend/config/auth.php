<?php

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | S03 (Security & Access) authenticates against security.principals, not Laravel's default
    | users table — a security Principal is deliberately not an HR employee (see
    | docs/security-access-foundation.md). No public registration exists (§7/§19): principals are
    | created only through the Security administration API or the bootstrap CLI command.
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'principals',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'principals' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', Principal::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset
    |--------------------------------------------------------------------------
    |
    | Email/SMS password recovery is explicitly out of scope for S03 (§27), so no password-reset
    | broker is configured. Administrative password reset is a Security administration use case
    | (ResetPasswordAdministratively), not Laravel's password-reset flow.
    |
    */

    'passwords' => [],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
