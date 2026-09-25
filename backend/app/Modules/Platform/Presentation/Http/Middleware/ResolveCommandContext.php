<?php

namespace App\Modules\Platform\Presentation\Http\Middleware;

use App\Modules\Platform\Application\Execution\CommandContext;
use App\Modules\Platform\Domain\Actor;
use App\Modules\Platform\Domain\CorrelationId;
use App\Modules\Platform\Domain\Source;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the HTTP CommandContext and attaches it to the request for controllers/middleware to
 * read (S04 §6/§8). Registered after auth:web/principal.active in every protected route group, so
 * Auth::guard('web')->user() is guaranteed non-null when this runs. Always echoes the effective
 * correlation id on the response, success or failure.
 */
class ResolveCommandContext
{
    public const REQUEST_ATTRIBUTE = 'command_context';

    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        $correlationId = self::resolveCorrelationId($request);

        $context = new CommandContext(
            actor: Actor::human($principal->getKey()),
            correlationId: $correlationId,
            source: Source::Http,
        );

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $context);

        $response = $next($request);
        $response->headers->set(self::HEADER, (string) $correlationId);

        return $response;
    }

    /** Reads the CommandContext this middleware attached. Throws if it never ran for this request. */
    public static function from(Request $request): CommandContext
    {
        $context = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! $context instanceof CommandContext) {
            throw new RuntimeException('CommandContext was not resolved for this request — ResolveCommandContext middleware must run first.');
        }

        return $context;
    }

    /**
     * Shared correlation-id resolution logic (§8), reusable by call sites that need it before this
     * middleware has run (e.g. LoginController, EnsurePrincipalIsActive) — the one place HTTP
     * coupling for correlation ids exists, matching the "no framework dependency" rule on
     * CorrelationId/CommandContext themselves.
     */
    public static function resolveCorrelationId(Request $request): CorrelationId
    {
        $incoming = $request->header(self::HEADER);

        if (is_string($incoming) && $incoming !== '') {
            $valid = CorrelationId::fromString($incoming);
            if ($valid !== null) {
                return $valid;
            }
        }

        return CorrelationId::generate();
    }
}
