<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\ChangePassword;
use App\Modules\Security\Application\Commands\ResetPasswordAdministratively;
use App\Modules\Security\Application\Commands\SetInitialPassword;
use App\Modules\Security\Domain\Exceptions\CredentialAlreadyExistsException;
use App\Modules\Security\Domain\Exceptions\IncorrectCurrentPasswordException;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Credential;
use Illuminate\Support\Facades\Hash;

/** §30 PASSWORDS. */
class PasswordTest extends SecurityTestCase
{
    public function test_the_stored_hash_is_never_the_plaintext_password(): void
    {
        $principal = $this->createPrincipal(password: 'AVeryStrongPassphrase42!');

        $credential = Credential::query()->where('principal_id', $principal->getKey())->firstOrFail();

        $this->assertNotSame('AVeryStrongPassphrase42!', $credential->password_hash);
        $this->assertTrue(Hash::check('AVeryStrongPassphrase42!', $credential->password_hash));
    }

    public function test_credential_model_hides_the_password_hash_from_array_and_json_output(): void
    {
        $principal = $this->createPrincipal();
        $credential = Credential::query()->where('principal_id', $principal->getKey())->firstOrFail();

        $this->assertArrayNotHasKey('password_hash', $credential->toArray());
        $this->assertStringNotContainsString('password_hash', $credential->toJson());
    }

    public function test_set_initial_password_refuses_a_second_call(): void
    {
        $principal = $this->createPrincipal();

        $this->expectException(CredentialAlreadyExistsException::class);
        app(SetInitialPassword::class)->handle($principal, 'AnotherLongPassphrase9!');
    }

    public function test_self_service_password_change_requires_the_current_password(): void
    {
        $principal = $this->createPrincipal(password: 'TheOriginalPassphrase1!');

        $this->expectException(IncorrectCurrentPasswordException::class);
        app(ChangePassword::class)->handle($principal, 'NotTheRightOne1!', 'ANewLongPassphrase2!');
    }

    public function test_self_service_password_change_succeeds_with_the_correct_current_password(): void
    {
        $principal = $this->createPrincipal(password: 'TheOriginalPassphrase1!');

        app(ChangePassword::class)->handle($principal, 'TheOriginalPassphrase1!', 'TheNewPassphrase2!');

        $credential = Credential::query()->where('principal_id', $principal->getKey())->firstOrFail();
        $this->assertTrue(Hash::check('TheNewPassphrase2!', $credential->password_hash));
    }

    public function test_administrative_reset_does_not_require_the_old_password(): void
    {
        $principal = $this->createPrincipal(password: 'TheOriginalPassphrase1!');

        app(ResetPasswordAdministratively::class)->handle($principal, 'AnAdminChosenPassphrase3!');

        $credential = Credential::query()->where('principal_id', $principal->getKey())->firstOrFail();
        $this->assertTrue(Hash::check('AnAdminChosenPassphrase3!', $credential->password_hash));
        $this->assertFalse(Hash::check('TheOriginalPassphrase1!', $credential->password_hash));
    }

    public function test_a_too_short_password_is_rejected_by_policy(): void
    {
        $principal = $this->createPrincipal();

        $this->expectException(InvalidPasswordException::class);
        app(ResetPasswordAdministratively::class)->handle($principal, 'short1!');
    }

    public function test_a_long_passphrase_is_accepted_and_not_silently_truncated(): void
    {
        // 120 bytes, comfortably past bcrypt's 72-byte silent-truncation point (§6): argon2id has
        // no such limit, so the full passphrase must matter, not just its first 72 bytes.
        $longPrefix = str_repeat('correct-horse-battery-staple-', 4);
        $passwordA = $longPrefix.'AAAA!';
        $passwordB = $longPrefix.'BBBB!';

        $principal = $this->createPrincipal(password: $passwordA);
        $credential = Credential::query()->where('principal_id', $principal->getKey())->firstOrFail();

        $this->assertTrue(Hash::check($passwordA, $credential->password_hash));
        $this->assertFalse(
            Hash::check($passwordB, $credential->password_hash),
            'the tail of the passphrase must matter; a driver that truncates would incorrectly accept this',
        );
    }

    public function test_password_reset_endpoint_requires_the_users_update_permission(): void
    {
        $actor = $this->createPrincipal();
        $target = $this->createPrincipal();
        $this->actingAs($actor, 'web');

        $this->putJson("/api/v1/security/principals/{$target->id}/password", ['password' => 'BrandNewPassphrase9!'])
            ->assertStatus(403);
    }

    public function test_own_password_change_endpoint_rejects_a_wrong_current_password_with_422(): void
    {
        $principal = $this->createPrincipal(password: 'TheOriginalPassphrase1!');
        $this->actingAs($principal, 'web');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'NotIt1!',
            'password' => 'ANewLongPassphrase2!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }
}
