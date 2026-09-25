<?php

namespace App\Modules\Security\Presentation\Http\Controllers\Auth;

use App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use App\Modules\Security\Presentation\Http\Resources\CurrentPrincipalPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CurrentPrincipalController
{
    public function __invoke(EffectivePermissionsResolver $resolver): JsonResponse
    {
        /** @var Principal $principal */
        $principal = Auth::guard('web')->user();

        return response()->json(CurrentPrincipalPayload::build($principal, $resolver));
    }
}
