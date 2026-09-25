<?php

namespace App\Modules\Security\Presentation\Http\Middleware;

use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\Source;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * §13/§14/SEC-05: a stale authenticated session must not provide permanent access after the
 * principal has been DISABLED. Runs after auth:web on every protected request and re-validates
 * status against the database on every call — not once at login time.
 *
 * S04 retrofit: records a security.authentication.session_rejected SECURITY_EVENT (§14/§16) on
 * rejection. This middleware runs BEFORE resolve.context in the chain (a disabled principal must
 * never reach resolve.context), so it builds its own CommandContext directly via the shared
 * correlation-id resolution helper, exactly as LoginController does. The principal here is a real,
 * known HUMAN (the session was authenticated) — not an enumeration concern — so the actor is
 * Actor::human() with that principal's own id.
 */
class EnsurePrincipalIsActive
{
    public function __construct(private readonly AuditSecurityEventRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Principal|null $principal */
        $principal = Auth::guard('web')->user();

        if ($principal !== null && ! $principal->isActive()) {
            $context = new CommandContext(
                actor: Actor::human($principal->getKey()),
                correlationId: ResolveCommandContext::resolveCorrelationId($request),
                source: Source::Http,
            );

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->recorder->record(
                context: $context,
                action: 'security.authentication.session_rejected',
                targetType: 'security_principal',
                targetId: $principal->getKey(),
                outcome: Outcome::Rejected,
                metadata: ['reason' => 'PRINCIPAL_DISABLED'],
            );

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
