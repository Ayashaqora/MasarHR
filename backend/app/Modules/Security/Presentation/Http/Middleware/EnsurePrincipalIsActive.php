<?php

namespace App\Modules\Security\Presentation\Http\Middleware;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * §13/§14/SEC-05: a stale authenticated session must not provide permanent access after the
 * principal has been DISABLED. Runs after auth:web on every protected request and re-validates
 * status against the database on every call — not once at login time.
 */
class EnsurePrincipalIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Principal|null $principal */
        $principal = Auth::guard('web')->user();

        if ($principal !== null && ! $principal->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
