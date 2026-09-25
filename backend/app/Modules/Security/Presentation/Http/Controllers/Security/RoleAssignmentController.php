<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Security\Application\Commands\AssignRoleToPrincipal;
use App\Modules\Security\Application\Commands\RemoveRoleFromPrincipal;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class RoleAssignmentController
{
    public function store(Request $request, Principal $principal, AssignRoleToPrincipal $command): Response
    {
        // Existence is enforced by findOrFail() below, not by an `exists:` validation rule: that
        // rule's string form parses a dotted table name as "connection.table" (see
        // ValidationRuleParser::parseTable()), and "security" is a schema, not a connection.
        $data = $request->validate(['role_id' => ['required', 'uuid']]);

        $role = Role::query()->findOrFail($data['role_id']);

        /** @var Principal|null $actor */
        $actor = Auth::guard('web')->user();

        $command->handle($principal, $role, $actor);

        return response()->noContent()->setStatusCode(201);
    }

    public function destroy(Principal $principal, Role $role, RemoveRoleFromPrincipal $command, SecurityAdministrationGuard $guard): Response
    {
        // Removing a role assignment can reduce security-administration capability (§17).
        $guard->run(fn () => $command->handle($principal, $role));

        return response()->noContent();
    }
}
