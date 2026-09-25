<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** S04 retrofit: records a security.authentication.logout SECURITY_EVENT (§14/§16). */
class LogoutController
{
    public function __invoke(Request $request, AuditSecurityEventRecorder $recorder): Response
    {
        $context = ResolveCommandContext::from($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $recorder->record(
            context: $context,
            action: 'security.authentication.logout',
            targetType: 'security_principal',
            targetId: $context->actor->principalId,
            outcome: Outcome::Succeeded,
        );

        return response()->noContent();
    }
}
