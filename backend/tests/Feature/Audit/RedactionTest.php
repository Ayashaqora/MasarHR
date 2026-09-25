<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Application\AuditAppendService;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * S04 §15: the allowlist a call site's AuditSpec defines is the primary redaction mechanism; the
 * denylist assertion here is the last-resort, defense-in-depth check for an allowlist authored
 * incorrectly.
 */
class RedactionTest extends AuditTestCase
{
    /** @return array<string, array{string}> */
    public static function denylistedKeys(): array
    {
        return [
            'password' => ['password'],
            'plaintext_password' => ['plaintext_password'],
            'old_password' => ['old_password'],
            'new_password' => ['new_password'],
            'password_hash' => ['password_hash'],
            'session_id' => ['session_id'],
            'session_cookie' => ['session_cookie'],
            'csrf_token' => ['csrf_token'],
            'token' => ['token'],
            'authentication_token' => ['authentication_token'],
            'bearer_token' => ['bearer_token'],
            'authorization' => ['authorization'],
            'secret' => ['secret'],
            'app_key' => ['app_key'],
            'env' => ['env'],
        ];
    }

    #[DataProvider('denylistedKeys')]
    public function test_every_denylisted_key_is_rejected_in_metadata(string $key): void
    {
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::system('TEST_PROBE'), CorrelationId::generate(), Source::Http);

        $this->expectException(RuntimeException::class);

        $service->appendSecurityEvent($context, 'test.redaction.denylist', 'security_principal', null, Outcome::Rejected, [$key => 'x']);
    }

    #[DataProvider('denylistedKeys')]
    public function test_denylisted_keys_are_rejected_case_insensitively(string $key): void
    {
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::system('TEST_PROBE'), CorrelationId::generate(), Source::Http);

        $this->expectException(RuntimeException::class);

        $service->appendSecurityEvent($context, 'test.redaction.denylist_case', 'security_principal', null, Outcome::Rejected, [mb_strtoupper($key) => 'x']);
    }

    public function test_a_non_denylisted_key_passes_through_unchanged(): void
    {
        $admin = $this->createSecurityAdministrator();
        $service = app(AuditAppendService::class);
        $context = new CommandContext(Actor::human($admin->getKey()), CorrelationId::generate(), Source::Http);

        $spec = new AuditSpec(
            action: 'test.redaction.allowed',
            targetType: 'security_principal',
            targetId: fn () => $admin->getKey(),
            changes: fn () => ['username' => 'new-username', 'display_name' => 'New Name'],
            metadata: fn () => ['permission_code' => 'security.users.view'],
        );

        $entry = $service->appendMutation($context, $spec, null);

        $this->assertSame(['username' => 'new-username', 'display_name' => 'New Name'], $entry->changes);
        $this->assertSame(['permission_code' => 'security.users.view'], $entry->metadata);
    }
}
