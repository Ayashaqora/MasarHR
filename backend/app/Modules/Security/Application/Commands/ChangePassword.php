<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Domain\Exceptions\IncorrectCurrentPasswordException;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Credential;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Facades\Hash;

/** Self-service password change: requires the current password (§6). */
final class ChangePassword
{
    /**
     * @throws IncorrectCurrentPasswordException
     * @throws InvalidPasswordException
     */
    public function handle(Principal $principal, string $currentPassword, string $newPassword): void
    {
        $credential = Credential::query()
            ->where('principal_id', $principal->getKey())
            ->where('credential_type', 'PASSWORD')
            ->firstOrFail();

        if (! Hash::check($currentPassword, $credential->password_hash)) {
            throw new IncorrectCurrentPasswordException;
        }

        $violations = PasswordPolicy::violations($newPassword);
        if ($violations !== []) {
            throw new InvalidPasswordException($violations);
        }

        $credential->forceFill([
            'password_hash' => Hash::make($newPassword),
            'password_changed_at' => now(),
        ])->save();
    }
}
