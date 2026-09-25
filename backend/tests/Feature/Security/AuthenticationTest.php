<?php

namespace Tests\Feature\Security;

use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Domain\PrincipalStatus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * §30 AUTHENTICATION. The rejection-reason tests assert both the HTTP outcome (401, generic body)
 * and, together, that unknown-username/wrong-password/disabled-account are indistinguishable —
 * that is the enumeration-resistance requirement, not a separate test on its own.
 */
class AuthenticationTest extends SecurityTestCase
{
    public function test_successful_login_returns_principal_roles_and_permissions(): void
    {
        $principal = $this->createSecurityAdministrator();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => self::VALID_PASSWORD,
        ]);

        $response->assertOk()->assertJsonStructure(['principal' => ['id', 'username', 'display_name'], 'roles', 'permissions']);
        $this->assertSame($principal->id, $response->json('principal.id'));
        $this->assertContains('security.users.view', $response->json('permissions'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $principal = $this->createPrincipal();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => 'DefinitelyTheWrongPassword1!',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_unknown_username_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->uniqueUsername('ghost'),
            'password' => 'AnythingAtAll1!',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_unknown_username_and_wrong_password_are_indistinguishable(): void
    {
        $principal = $this->createPrincipal();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => 'DefinitelyTheWrongPassword1!',
        ]);

        $unknownUser = $this->postJson('/api/v1/auth/login', [
            'username' => $this->uniqueUsername('ghost'),
            'password' => 'DefinitelyTheWrongPassword1!',
        ]);

        $this->assertSame($wrongPassword->getStatusCode(), $unknownUser->getStatusCode());
        $this->assertSame($wrongPassword->json(), $unknownUser->json());
    }

    public function test_disabled_principal_cannot_log_in(): void
    {
        $principal = $this->createPrincipal();
        app(ChangePrincipalStatus::class)->handle($principal, PrincipalStatus::Disabled, $principal->version);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => self::VALID_PASSWORD,
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_a_stale_session_stops_working_once_the_principal_is_disabled(): void
    {
        $principal = $this->createPrincipal();

        $this->actingAs($principal, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk();

        app(ChangePrincipalStatus::class)->handle($principal, PrincipalStatus::Disabled, $principal->version);

        // The same session cookie/guard state now fails: status is re-checked on every request (§13, SEC-05).
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_login_regenerates_the_session_id(): void
    {
        $principal = $this->createPrincipal();

        $this->get('/api/v1/auth/csrf-cookie');
        $before = $this->app['session']->getId();

        $this->postJson('/api/v1/auth/login', [
            'username' => $principal->username,
            'password' => self::VALID_PASSWORD,
        ])->assertOk();

        $this->assertNotSame($before, $this->app['session']->getId());
    }

    public function test_logout_invalidates_the_session(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_me_reports_the_authenticated_principal(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('principal.username', $principal->username)
            ->assertJsonPath('principal.display_name', $principal->display_name)
            ->assertJsonPath('roles', []);
    }

    public function test_me_never_exposes_password_hash_or_credential_fields(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $body = $this->getJson('/api/v1/auth/me')->getContent();

        foreach (['password', 'hash', 'credential', 'session'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body);
        }
    }

    public function test_unauthenticated_request_to_a_protected_route_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/security/principals')->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('nonexistent-user|127.0.0.1');
        $username = $this->uniqueUsername('throttled');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_state_changing_web_requests_are_protected_by_csrf_middleware(): void
    {
        $routes = collect(Route::getRoutes())
            ->first(fn ($route) => $route->getName() === 'api.v1.auth.login');

        $this->assertNotNull($routes);
        $middleware = $routes->gatherMiddleware();
        $this->assertContains('web', $middleware, 'the login route runs through the web middleware group, which carries CSRF protection');
    }
}
