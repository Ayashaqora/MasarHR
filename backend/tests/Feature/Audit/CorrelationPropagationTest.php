<?php

namespace Tests\Feature\Audit;

use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use Illuminate\Support\Str;

/**
 * S04 §8/AUD-09: an inbound X-Correlation-ID, if a syntactically valid UUID, is echoed back
 * unchanged; otherwise (missing or invalid) a fresh one is generated — never a 400 — and is always
 * echoed on the response and recorded on the resulting audit entry. Correlation id is tracing
 * metadata only, never authorization evidence.
 */
class CorrelationPropagationTest extends AuditTestCase
{
    public function test_a_valid_inbound_correlation_id_is_echoed_back_on_a_protected_route(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');
        $given = (string) Str::uuid7();

        $response = $this->withHeader(ResolveCommandContext::HEADER, $given)
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame($given, $response->headers->get(ResolveCommandContext::HEADER));
    }

    public function test_a_missing_correlation_id_is_generated_and_echoed(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        $echoed = $response->headers->get(ResolveCommandContext::HEADER);
        $this->assertNotNull($echoed);
        $this->assertTrue(Str::isUuid($echoed));
    }

    public function test_an_invalid_correlation_id_is_replaced_with_a_freshly_generated_one_not_rejected(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $response = $this->withHeader(ResolveCommandContext::HEADER, 'not-a-valid-uuid')
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $echoed = $response->headers->get(ResolveCommandContext::HEADER);
        $this->assertNotNull($echoed);
        $this->assertNotSame('not-a-valid-uuid', $echoed);
        $this->assertTrue(Str::isUuid($echoed));
    }

    public function test_the_correlation_id_echoed_on_the_response_matches_the_one_recorded_on_the_audit_entry(): void
    {
        $admin = $this->createSecurityAdministrator();
        $subject = $this->createPrincipal();
        $this->actingAs($admin, 'web');
        $given = (string) Str::uuid7();

        $response = $this->withHeader(ResolveCommandContext::HEADER, $given)
            ->patchJson("/api/v1/security/principals/{$subject->id}/display-name", [
                'display_name' => 'Correlated Name',
                'expected_version' => $subject->version,
            ])->assertOk();

        $this->assertSame($given, $response->headers->get(ResolveCommandContext::HEADER));

        $entry = $this->latestAuditEntryFor('security.principal.display_name.change');
        $this->assertSame($given, $entry->correlation_id);
    }

    public function test_a_valid_inbound_correlation_id_on_login_is_echoed_even_though_resolve_context_never_runs(): void
    {
        $principal = $this->createPrincipal();
        $given = (string) Str::uuid7();

        $response = $this->withHeader(ResolveCommandContext::HEADER, $given)
            ->postJson('/api/v1/auth/login', [
                'username' => $principal->username,
                'password' => self::VALID_PASSWORD,
            ])->assertOk();

        $this->assertSame($given, $response->headers->get(ResolveCommandContext::HEADER));

        $entry = $this->latestAuditEntryFor('security.authentication.login.succeeded');
        $this->assertSame($given, $entry->correlation_id);
    }
}
