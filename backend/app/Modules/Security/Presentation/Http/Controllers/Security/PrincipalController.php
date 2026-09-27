<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Application\Commands\ChangePrincipalDisplayName;
use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Application\Commands\ChangePrincipalUsername;
use App\Modules\Security\Application\Commands\CreatePrincipal;
use App\Modules\Security\Application\Commands\ResetPasswordAdministratively;
use App\Modules\Security\Application\Commands\SetInitialPassword;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Presentation\Http\Resources\PrincipalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * §20 of the S03 authorization: minimum coherent endpoints for the approved commands, never a
 * generic table CRUD API. Every write method maps 1:1 to a named Application command; there is no
 * generic PATCH.
 *
 * S04 retrofit: every write method is now routed through AuditedCommandExecutor, so its mutation
 * and its MUTATION audit entry commit or roll back together (AUD-01/AUD-02). store() aggregates
 * CreatePrincipal+SetInitialPassword into the single operation closure ERRATA-04 requires — one
 * logical mutation, one audit entry, never a password value in it. updateStatus() additionally
 * guards the DISABLE direction with SecurityAdministrationGuard::protect() (ERRATA-02 — no
 * transaction of its own, operating inside the executor's single owned transaction) and, on
 * LastSecurityAdministratorException, records the rejection as an independent SECURITY_EVENT after
 * the executor's transaction has already rolled back (D10) before rethrowing so the 409 response is
 * unaffected.
 */
class PrincipalController
{
    public function index(): JsonResponse
    {
        $principals = Principal::query()->orderBy('username_normalized')->paginate(25);

        return PrincipalResource::collection($principals)->response();
    }

    public function show(Principal $principal): JsonResponse
    {
        return (new PrincipalResource($principal))->response();
    }

    public function store(
        Request $request,
        CreatePrincipal $createPrincipal,
        SetInitialPassword $setInitialPassword,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64'],
            'display_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.principal.create',
            targetType: 'security_principal',
            targetId: fn (Principal $created) => $created->getKey(),
            changes: fn (Principal $created) => [
                'username' => $created->username,
                'display_name' => $created->display_name,
            ],
            metadata: fn () => ['credential_established' => true],
        );

        $principal = $executor->run($context, $spec, function () use ($data, $createPrincipal, $setInitialPassword) {
            $principal = $createPrincipal->handle($data['username'], $data['display_name']);
            $setInitialPassword->handle($principal, $data['password']);

            return $principal;
        });

        return (new PrincipalResource($principal))->response()->setStatusCode(201);
    }

    public function updateUsername(
        Request $request,
        Principal $principal,
        ChangePrincipalUsername $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previousUsername = $principal->username;

        $spec = new AuditSpec(
            action: 'security.principal.username.change',
            targetType: 'security_principal',
            targetId: fn (Principal $updated) => $updated->getKey(),
            changes: fn (Principal $updated) => ['username' => ['from' => $previousUsername, 'to' => $updated->username]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($principal, $data['username'], $data['expected_version']));

        return (new PrincipalResource($updated))->response();
    }

    public function updateDisplayName(
        Request $request,
        Principal $principal,
        ChangePrincipalDisplayName $command,
        AuditedCommandExecutor $executor,
    ): JsonResponse {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $context = ResolveCommandContext::from($request);
        $previousDisplayName = $principal->display_name;

        $spec = new AuditSpec(
            action: 'security.principal.display_name.change',
            targetType: 'security_principal',
            targetId: fn (Principal $updated) => $updated->getKey(),
            changes: fn (Principal $updated) => ['display_name' => ['from' => $previousDisplayName, 'to' => $updated->display_name]],
            metadata: fn () => [],
        );

        $updated = $executor->run($context, $spec, fn () => $command->handle($principal, $data['display_name'], $data['expected_version']));

        return (new PrincipalResource($updated))->response();
    }

    public function updateStatus(
        Request $request,
        Principal $principal,
        ChangePrincipalStatus $command,
        SecurityAdministrationGuard $guard,
        AuditedCommandExecutor $executor,
        AuditSecurityEventRecorder $recorder,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in([PrincipalStatus::Active->value, PrincipalStatus::Disabled->value])],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $newStatus = PrincipalStatus::from($data['status']);
        $context = ResolveCommandContext::from($request);
        $previousStatus = $principal->status->value;

        $spec = new AuditSpec(
            action: 'security.principal.status.change',
            targetType: 'security_principal',
            targetId: fn (Principal $updated) => $updated->getKey(),
            changes: fn (Principal $updated) => ['status' => ['from' => $previousStatus, 'to' => $updated->status->value]],
            metadata: fn () => [],
        );

        // Disabling can reduce security-administration capability; reactivating cannot. Only the
        // former needs the last-security-administrator invariant (§17), enforced via
        // SecurityAdministrationGuard::protect() inside this executor's own transaction (ERRATA-02).
        $operation = $newStatus === PrincipalStatus::Disabled
            ? fn () => $guard->protect(fn () => $command->handle($principal, $newStatus, $data['expected_version']))
            : fn () => $command->handle($principal, $newStatus, $data['expected_version']);

        try {
            $updated = $executor->run($context, $spec, $operation);
        } catch (LastSecurityAdministratorException $e) {
            // §14/D10: the executor's transaction has already rolled back by the time this catch
            // runs — nothing was committed. Record the rejection via the independent mechanism,
            // then rethrow unchanged so the 409 response is unaffected.
            $recorder->record(
                context: $context,
                action: 'security.principal.status.change',
                targetType: 'security_principal',
                targetId: $principal->getKey(),
                outcome: Outcome::Rejected,
                metadata: ['rejection_reason' => 'LAST_SECURITY_ADMINISTRATOR'],
            );

            throw $e;
        }

        return (new PrincipalResource($updated))->response();
    }

    public function resetPassword(
        Request $request,
        Principal $principal,
        ResetPasswordAdministratively $command,
        AuditedCommandExecutor $executor,
    ): Response {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH],
        ]);

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.credential.password.reset_administrative',
            targetType: 'security_credential',
            targetId: fn () => $principal->getKey(),
            changes: fn () => null, // §16: no diff payload for a credential rotation — never the password value/hash itself (denylisted).
            metadata: fn () => [],
        );

        $executor->run($context, $spec, fn () => $command->handle($principal, $data['password']));

        return response()->noContent();
    }

    public function effectivePermissions(Principal $principal, EffectivePermissionsResolver $resolver): JsonResponse
    {
        return response()->json(['permissions' => $resolver->resolve($principal)]);
    }
}
