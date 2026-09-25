<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\Source;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Application\Authentication\AuthenticateWithPassword;
use App\Modules\Security\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Security\Domain\UsernameNormalizer;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Presentation\Http\Resources\CurrentPrincipalPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * S04 retrofit: records a SECURITY_EVENT for both login outcomes (§14/§16). This route runs
 * outside the auth:web+principal.active+resolve.context group — a caller isn't authenticated yet —
 * so ResolveCommandContext never runs for it; this controller builds its own CommandContext
 * directly: SYSTEM/UNAUTHENTICATED on failure (ERRATA-01 — audit provenance only, never implying
 * trust or SYSTEM authority) and HUMAN (the now-authenticated principal) on success. Always echoes
 * the effective correlation id on the response, matching ResolveCommandContext's own behavior on
 * protected routes.
 */
class LoginController
{
    public function __invoke(
        Request $request,
        AuthenticateWithPassword $authenticate,
        EffectivePermissionsResolver $resolver,
        AuditSecurityEventRecorder $recorder,
    ): JsonResponse {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $correlationId = ResolveCommandContext::resolveCorrelationId($request);

        try {
            // Throws InvalidCredentialsException (mapped to a generic 401) for every rejection reason —
            // unknown username, wrong password, or a disabled principal — so the response cannot be
            // used to enumerate accounts or account state (§12 of the S03 authorization).
            $principal = $authenticate->handle($credentials['username'], $credentials['password']);
        } catch (InvalidCredentialsException $e) {
            $recorder->record(
                context: new CommandContext(Actor::system('UNAUTHENTICATED'), $correlationId, Source::Http),
                action: 'security.authentication.login.failed',
                targetType: 'security_principal',
                targetId: $this->lookUpAttemptedPrincipalId($credentials['username']),
                outcome: Outcome::Rejected,
            );

            throw $e;
        }

        // Regenerate before establishing the session (session-fixation protection), then log in.
        $request->session()->regenerate();
        Auth::guard('web')->login($principal);

        $recorder->record(
            context: new CommandContext(Actor::human($principal->getKey()), $correlationId, Source::Http),
            action: 'security.authentication.login.succeeded',
            targetType: 'security_principal',
            targetId: $principal->getKey(),
            outcome: Outcome::Succeeded,
        );

        $response = response()->json(CurrentPrincipalPayload::build($principal, $resolver));
        $response->headers->set(ResolveCommandContext::HEADER, (string) $correlationId);

        return $response;
    }

    /**
     * Internal-only lookup so a failed-login SECURITY_EVENT can still reference which principal (if
     * any) the attempt matched — never exposed in the HTTP response, so it does not reintroduce the
     * account-enumeration leak §12 forbids.
     */
    private function lookUpAttemptedPrincipalId(string $username): ?string
    {
        return Principal::query()
            ->where('username_normalized', UsernameNormalizer::normalize($username))
            ->value('id');
    }
}
