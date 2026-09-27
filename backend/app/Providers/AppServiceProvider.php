<?php

namespace App\Providers;

use App\Modules\Security\Domain\UsernameNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // migrate:fresh, migrate:refresh, migrate:reset and db:wipe are refused in production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // A single-resource response (PrincipalResource, RoleResource, ...) is returned as the
        // plain object, not enveloped in {"data": ...}. Paginated collections are unaffected: they
        // always keep their "data"/"links"/"meta" wrapper regardless of this setting (Laravel
        // forces it for pagination), so `GET /api/v1/security/roles` etc. still nest under "data".
        JsonResource::withoutWrapping();

        // Login rate limiting (§12/§30 of the S03 authorization): keyed by normalized username + IP
        // so one attacker IP cannot lock out a legitimate user by hammering their username from
        // elsewhere, and one compromised credential list cannot be sprayed unthrottled from a
        // single IP either.
        RateLimiter::for('login', function (Request $request): Limit {
            $username = UsernameNormalizer::normalize((string) $request->input('username', ''));

            return Limit::perMinute(5)->by($username.'|'.$request->ip());
        });
    }
}
