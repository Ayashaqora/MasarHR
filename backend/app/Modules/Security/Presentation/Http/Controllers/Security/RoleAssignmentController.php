<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Audit\Application\AuditedCommandExecutor;
use App\Modules\Audit\Application\AuditSecurityEventRecorder;
use App\Modules\Audit\Domain\AuditSpec;
use App\Modules\Audit\Domain\Outcome;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Security\Application\Commands\AssignRoleToPrincipal;
use App\Modules\Security\Application\Commands\RemoveRoleFromPrincipal;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\PrincipalRole;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * S04 retrofit: both mutations route through AuditedCommandExecutor. destroy() additionally guards
 * via SecurityAdministrationGuard::protect() (ERRATA-02) and, on LastSecurityAdministratorException,
 * records the rejection as an independent SECURITY_EVENT after the executor's transaction has
 * already rolled back (D10) before rethrowing.
 */
class RoleAssignmentController
{
    public function store(Request $request, Principal $principal, AssignRoleToPrincipal $command, AuditedCommandExecutor $executor): Response
    {
        // Existence is enforced by findOrFail() below, not by an `exists:` validation rule: that
        // rule's string form parses a dotted table name as "connection.table" (see
        // ValidationRuleParser::parseTable()), and "security" is a schema, not a connection.
        $data = $request->validate(['role_id' => ['required', 'uuid']]);

        $role = Role::query()->findOrFail($data['role_id']);

        /** @var Principal|null $actor */
        $actor = Auth::guard('web')->user();

        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role_assignment.create',
            targetType: 'security_principal_role',
            targetId: fn () => $principal->getKey().':'.$role->getKey(),
            // §16: allowlist is {} — the composite target_id already carries the role id.
            changes: fn () => [],
            metadata: fn () => [],
        );

        $executor->run($context, $spec, fn (): PrincipalRole => $command->handle($principal, $role, $actor));

        return response()->noContent()->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        Principal $principal,
        Role $role,
        RemoveRoleFromPrincipal $command,
        SecurityAdministrationGuard $guard,
        AuditedCommandExecutor $executor,
        AuditSecurityEventRecorder $recorder,
    ): Response {
        $context = ResolveCommandContext::from($request);

        $spec = new AuditSpec(
            action: 'security.role_assignment.remove',
            targetType: 'security_principal_role',
            targetId: fn () => $principal->getKey().':'.$role->getKey(),
            // §16: allowlist is {} — the composite target_id already carries the role id.
            changes: fn () => [],
            metadata: fn () => [],
        );

        // Removing a role assignment can reduce security-administration capability (§17); guarded
        // inside the executor's own transaction (ERRATA-02).
        try {
            $executor->run($context, $spec, fn () => $guard->protect(fn () => $command->handle($principal, $role)));
        } catch (LastSecurityAdministratorException $e) {
            $recorder->record(
                context: $context,
                action: 'security.role_assignment.remove',
                targetType: 'security_principal_role',
                targetId: $principal->getKey().':'.$role->getKey(),
                outcome: Outcome::Rejected,
                metadata: ['rejection_reason' => 'LAST_SECURITY_ADMINISTRATOR'],
            );

            throw $e;
        }

        return response()->noContent();
    }
}
