<?php

namespace App\Modules\Security\Presentation\Http\Middleware;

use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * §15: authentication != authorization. Every protected write/read in the Security administration
 * API declares the exact permission code it requires; default behaviour is DENY (§12, SEC-12) —
 * an unrecognised or unauthenticated caller is rejected, never silently allowed through.
 */
class RequirePermission
{
    public function __construct(private readonly EffectivePermissionsResolver $effectivePermissions) {}

    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        /** @var Principal|null $principal */
        $principal = Auth::guard('web')->user();

        if ($principal === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->effectivePermissions->has($principal, $permissionCode)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return $next($request);
    }
}
