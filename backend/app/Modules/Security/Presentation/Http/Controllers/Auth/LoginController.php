<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use App\Modules\Security\Application\Authentication\AuthenticateWithPassword;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Presentation\Http\Resources\CurrentPrincipalPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController
{
    public function __invoke(
        Request $request,
        AuthenticateWithPassword $authenticate,
        EffectivePermissionsResolver $resolver,
    ): JsonResponse {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Throws InvalidCredentialsException (mapped to a generic 401) for every rejection reason —
        // unknown username, wrong password, or a disabled principal — so the response cannot be
        // used to enumerate accounts or account state (§12 of the S03 authorization).
        $principal = $authenticate->handle($credentials['username'], $credentials['password']);

        // Regenerate before establishing the session (session-fixation protection), then log in.
        $request->session()->regenerate();
        Auth::guard('web')->login($principal);

        return response()->json(CurrentPrincipalPayload::build($principal, $resolver));
    }
}
