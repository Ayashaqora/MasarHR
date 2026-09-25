<?php

namespace App\Modules\Security\Presentation\Http\Middleware;

use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
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
 *
 * S04 retrofit: records a security.authorization.denied SECURITY_EVENT (§14/§16) on every 403.
 * Registered after resolve.context in every protected route group, so
 * ResolveCommandContext::from($request) is guaranteed to succeed here.
 */
class RequirePermission
{
    public function __construct(
        private readonly EffectivePermissionsResolver $effectivePermissions,
        private readonly AuditSecurityEventRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        /** @var Principal|null $principal */
        $principal = Auth::guard('web')->user();

        if ($principal === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->effectivePermissions->has($principal, $permissionCode)) {
            $this->recorder->record(
                context: ResolveCommandContext::from($request),
                action: 'security.authorization.denied',
                targetType: 'security_permission',
                targetId: $permissionCode,
                outcome: Outcome::Rejected,
            );

            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return $next($request);
    }
}
