<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Application\Commands\ChangePassword;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** S04 retrofit: routes through AuditedCommandExecutor so the password change and its MUTATION audit entry commit or roll back together. */
class ChangeOwnPasswordController
{
    public function __invoke(Request $request, ChangePassword $changePassword, AuditedCommandExecutor $executor): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH],
        ]);

        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.credential.password.change',
            targetType: 'security_credential',
            targetId: fn () => $principal->getKey(),
            changes: fn () => null, // §16: no diff payload for a credential rotation — never the password value/hash itself (denylisted).
            metadata: fn () => [],
        );

        // IncorrectCurrentPasswordException (422) is not a security event per the frozen matrix —
        // it propagates unchanged; the executor's transaction rolls back and no audit entry is
        // written (nothing was mutated).
        $executor->run($context, $spec, fn () => $changePassword->handle($principal, $data['current_password'], $data['password']));

        return response()->noContent();
    }
}
