<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Security;

use App\Modules\Security\Infrastructure\Persistence\Eloquent\Permission;
use App\Modules\Security\Presentation\Http\Resources\PermissionResource;
use Illuminate\Http\JsonResponse;

/** Read-only (§8/§20): S03 never creates or deletes permissions through the API. */
class PermissionController
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('code')->paginate(100);

        return PermissionResource::collection($permissions)->response();
    }
}
