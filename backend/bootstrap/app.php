<?php

use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Domain\Exceptions\DuplicateReferenceCodeException;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Domain\Exceptions\StaleVersionException as ReferenceStaleVersionException;
use App\Modules\Security\Domain\Exceptions\CredentialAlreadyExistsException;
use App\Modules\Security\Domain\Exceptions\DuplicatePermissionGrantException;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleAssignmentException;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleCodeException;
use App\Modules\Security\Domain\Exceptions\DuplicateUsernameException;
use App\Modules\Security\Domain\Exceptions\IncorrectCurrentPasswordException;
use App\Modules\Security\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Presentation\Console\BootstrapAdminCommand;
use App\Modules\Security\Presentation\Http\Middleware\EnsurePrincipalIsActive;
use App\Modules\Security\Presentation\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withCommands([
        BootstrapAdminCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The backend is API-only (see routes/api.php); there is no 'login' web route to redirect
        // an unauthenticated request to. Every api/* response is already forced to JSON below, so
        // an unauthenticated request always gets a 401 JSON body, never a redirect.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'principal.active' => EnsurePrincipalIsActive::class,
            'permission' => RequirePermission::class,
            'resolve.context' => ResolveCommandContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API responses are always JSON; other error handling stays standard Laravel.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // These are routine, expected outcomes of normal use (a mistyped password, a stale form,
        // a duplicate click) — not system errors. Reporting them at ERROR level with a full stack
        // trace on every failed login attempt would be log noise at best and, at worst, a
        // misleading health signal. They are still rendered to the client via the render()
        // callbacks below; only server-side reporting is skipped.
        $exceptions->dontReport([
            InvalidCredentialsException::class,
            StaleVersionException::class,
            LastSecurityAdministratorException::class,
            DuplicateUsernameException::class,
            DuplicateRoleCodeException::class,
            DuplicateRoleAssignmentException::class,
            DuplicatePermissionGrantException::class,
            CredentialAlreadyExistsException::class,
            IncorrectCurrentPasswordException::class,
            InvalidPasswordException::class,
            ReferenceStaleVersionException::class,
            DuplicateReferenceCodeException::class,
            OverlappingBehaviorPeriodException::class,
        ]);

        // §22 of the S03 authorization: preserve stated HTTP semantics for Security domain
        // failures. Every message here is generic/domain-level — never a SQLSTATE, SQL fragment,
        // stack trace, hash, or session/cookie value (Laravel's own APP_DEBUG=false default
        // already suppresses trace/SQL detail on every other exception).
        $exceptions->render(fn (InvalidCredentialsException $e) => response()->json(['message' => $e->getMessage()], 401));

        $exceptions->render(fn (StaleVersionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (LastSecurityAdministratorException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicateRoleAssignmentException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicatePermissionGrantException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (CredentialAlreadyExistsException $e) => response()->json(['message' => $e->getMessage()], 409));

        // S05 Reference-module domain failures (docs/reference-data-foundation-specification.md §14).
        $exceptions->render(fn (ReferenceStaleVersionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (OverlappingBehaviorPeriodException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicateReferenceCodeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['code' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (DuplicateUsernameException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['username' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (DuplicateRoleCodeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['code' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (IncorrectCurrentPasswordException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['current_password' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (InvalidPasswordException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['password' => $e->violations],
        ], 422));
    })->create();
