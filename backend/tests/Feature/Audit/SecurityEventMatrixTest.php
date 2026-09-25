<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Domain\Category;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Domain\ActorType;
use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Domain\PrincipalStatus;

/**
 * S04 §14/§16/ERRATA-01: the full SECURITY_EVENT matrix — login success/failure, logout,
 * authorization denial, disabled-principal session rejection, and last-administrator rejection —
 * preserving the S03 anti-enumeration and status semantics those endpoints already had.
 */
class SecurityEventMatrixTest extends AuditTestCase
{
    public function test_a_successful_login_is_recorded_as_a_human_succeeded_security_event(): void
    {
        $principal = $this->createSecurityAdministrator();

        $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => self::VALID_PASSWORD,
        ])->assertOk();

        $entry = $this->latestAuditEntryFor('security.authentication.login.succeeded');
        $this->assertNotNull($entry);
        $this->assertSame(Category::SecurityEvent, $entry->category);
        $this->assertSame(Outcome::Succeeded, $entry->outcome);
        $this->assertSame(ActorType::Human, $entry->actor_type);
        $this->assertSame($principal->getKey(), $entry->actor_principal_id);
        $this->assertSame($principal->getKey(), $entry->target_id);
    }

    public function test_a_failed_login_is_recorded_as_an_unauthenticated_system_rejected_event_never_implying_trust(): void
    {
        $principal = $this->createPrincipal();

        $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => 'TotallyWrongPassword1!',
        ])->assertStatus(401);

        $entry = $this->latestAuditEntryFor('security.authentication.login.failed');
        $this->assertNotNull($entry);
        $this->assertSame(Outcome::Rejected, $entry->outcome);
        // ERRATA-01: SYSTEM/UNAUTHENTICATED is audit provenance only — never a real Principal id,
        // never implying trust or SYSTEM authority.
        $this->assertSame(ActorType::System, $entry->actor_type);
        $this->assertSame('UNAUTHENTICATED', $entry->actor_label);
        $this->assertNull($entry->actor_principal_id);
        // The internal lookup may still reference which principal the attempt matched, for
        // investigation purposes only — never exposed in the HTTP response (§12 anti-enumeration).
        $this->assertSame($principal->getKey(), $entry->target_id);
    }

    public function test_an_unknown_username_failed_login_is_recorded_with_a_null_target(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->uniqueUsername('ghost'),
            'password' => 'AnythingAtAll1!',
        ])->assertStatus(401);

        $entry = $this->latestAuditEntryFor('security.authentication.login.failed');
        $this->assertNotNull($entry);
        $this->assertNull($entry->target_id);
        $this->assertSame('UNAUTHENTICATED', $entry->actor_label);
    }

    public function test_logout_is_recorded_as_a_human_succeeded_security_event(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $entry = $this->latestAuditEntryFor('security.authentication.logout');
        $this->assertNotNull($entry);
        $this->assertSame(Outcome::Succeeded, $entry->outcome);
        $this->assertSame(ActorType::Human, $entry->actor_type);
        $this->assertSame($principal->getKey(), $entry->actor_principal_id);
    }

    public function test_authorization_denial_is_recorded_with_the_required_permission_code_as_its_target(): void
    {
        $principal = $this->createPrincipal(); // no permissions granted.
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/security/principals')->assertStatus(403);

        $entry = $this->latestAuditEntryFor('security.authorization.denied');
        $this->assertNotNull($entry);
        $this->assertSame(Outcome::Rejected, $entry->outcome);
        $this->assertSame(ActorType::Human, $entry->actor_type);
        $this->assertSame($principal->getKey(), $entry->actor_principal_id);
        // §17: target_type/target_id carry the required permission code itself, not the acting
        // principal — the acting principal is already captured via actor_principal_id.
        $this->assertSame('security_permission', $entry->target_type);
        $this->assertSame('security.users.view', $entry->target_id);
        $this->assertNull($entry->metadata);
    }

    public function test_a_disabled_principals_stale_session_rejection_is_recorded(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk();

        app(ChangePrincipalStatus::class)->handle($principal, PrincipalStatus::Disabled, $principal->version);

        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $entry = $this->latestAuditEntryFor('security.authentication.session_rejected');
        $this->assertNotNull($entry);
        $this->assertSame(Outcome::Rejected, $entry->outcome);
        $this->assertSame(ActorType::Human, $entry->actor_type);
        $this->assertSame($principal->getKey(), $entry->actor_principal_id);
        $this->assertSame('PRINCIPAL_DISABLED', $entry->metadata['reason']);
    }

    public function test_a_last_administrator_rejection_is_recorded_after_the_mutation_rolled_back(): void
    {
        $admin = $this->createSecurityAdministrator();
        $this->actingAs($admin, 'web');
        $countBefore = $this->auditEntriesCount();

        $this->patchJson("/api/v1/security/principals/{$admin->id}/status", [
            'status' => 'DISABLED',
            'expected_version' => $admin->version,
        ])->assertStatus(409);

        // Exactly one new entry: the rejection SECURITY_EVENT. No MUTATION entry (nothing committed).
        $this->assertSame($countBefore + 1, $this->auditEntriesCount());

        // §17: the rejection reuses the attempted mutation's own action code and target — it is not
        // a distinct dedicated action code — and category remains SECURITY_EVENT despite sharing the
        // mutation's action string, since the rejection was never a committed MUTATION.
        $entry = $this->latestAuditEntryFor('security.principal.status.change');
        $this->assertNotNull($entry);
        $this->assertSame(Category::SecurityEvent, $entry->category);
        $this->assertSame(Outcome::Rejected, $entry->outcome);
        $this->assertSame('security_principal', $entry->target_type);
        $this->assertSame($admin->getKey(), $entry->target_id);
        $this->assertSame($admin->getKey(), $entry->actor_principal_id);
        $this->assertSame('LAST_SECURITY_ADMINISTRATOR', $entry->metadata['rejection_reason']);
        $this->assertSame(PrincipalStatus::Active, $admin->fresh()->status, 'the rejected mutation must leave the principal untouched');
    }
}
