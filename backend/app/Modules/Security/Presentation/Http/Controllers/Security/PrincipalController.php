<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Security\Application\Commands\ChangePrincipalDisplayName;
use App\Modules\Security\Application\Commands\ChangePrincipalStatus;
use App\Modules\Security\Application\Commands\ChangePrincipalUsername;
use App\Modules\Security\Application\Commands\CreatePrincipal;
use App\Modules\Security\Application\Commands\ResetPasswordAdministratively;
use App\Modules\Security\Application\Commands\SetInitialPassword;
use App\Modules\Security\Application\Security\SecurityAdministrationGuard;
use App\Modules\Security\Domain\PasswordPolicy;
use App\Modules\Security\Domain\PrincipalStatus;
use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Presentation\Http\Resources\PrincipalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * §20 of the S03 authorization: minimum coherent endpoints for the approved commands, never a
 * generic table CRUD API. Every write method maps 1:1 to a named Application command; there is no
 * generic PATCH.
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

    public function store(Request $request, CreatePrincipal $createPrincipal, SetInitialPassword $setInitialPassword): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64'],
            'display_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH],
        ]);

        $principal = DB::transaction(function () use ($data, $createPrincipal, $setInitialPassword) {
            $principal = $createPrincipal->handle($data['username'], $data['display_name']);
            $setInitialPassword->handle($principal, $data['password']);

            return $principal;
        });

        return (new PrincipalResource($principal))->response()->setStatusCode(201);
    }

    public function updateUsername(Request $request, Principal $principal, ChangePrincipalUsername $command): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $command->handle($principal, $data['username'], $data['expected_version']);

        return (new PrincipalResource($updated))->response();
    }

    public function updateDisplayName(Request $request, Principal $principal, ChangePrincipalDisplayName $command): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $command->handle($principal, $data['display_name'], $data['expected_version']);

        return (new PrincipalResource($updated))->response();
    }

    public function updateStatus(
        Request $request,
        Principal $principal,
        ChangePrincipalStatus $command,
        SecurityAdministrationGuard $guard,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in([PrincipalStatus::Active->value, PrincipalStatus::Disabled->value])],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $newStatus = PrincipalStatus::from($data['status']);

        // Disabling can reduce security-administration capability; reactivating cannot. Only the
        // former needs the last-security-administrator invariant (§17).
        $updated = $newStatus === PrincipalStatus::Disabled
            ? $guard->run(fn () => $command->handle($principal, $newStatus, $data['expected_version']))
            : $command->handle($principal, $newStatus, $data['expected_version']);

        return (new PrincipalResource($updated))->response();
    }

    public function resetPassword(Request $request, Principal $principal, ResetPasswordAdministratively $command): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH],
        ]);

        $command->handle($principal, $data['password']);

        return response()->noContent();
    }

    public function effectivePermissions(Principal $principal, EffectivePermissionsResolver $resolver): JsonResponse
    {
        return response()->json(['permissions' => $resolver->resolve($principal)]);
    }
}
