<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use App\Modules\Security\Application\Commands\ChangePassword;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ChangeOwnPasswordController
{
    public function __invoke(Request $request, ChangePassword $changePassword): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH],
        ]);

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        $changePassword->handle($principal, $data['current_password'], $data['password']);

        return response()->noContent();
    }
}
