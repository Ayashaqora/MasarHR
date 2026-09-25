<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use Illuminate\Http\Response;

/**
 * The Sanctum-compatible "prime the XSRF-TOKEN cookie" endpoint. Laravel's CSRF middleware only
 * attaches the XSRF-TOKEN cookie to a response that completes successfully — a request that throws
 * (401 from auth:web, for example) never reaches that point — so the frontend calls this endpoint
 * once, before login, to get a CSRF token it can echo back on POST /auth/login.
 */
class CsrfCookieController
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
