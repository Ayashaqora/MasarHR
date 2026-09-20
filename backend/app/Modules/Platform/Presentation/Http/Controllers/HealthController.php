<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Infrastructure-level liveness probe for GET /api/v1/health.
 *
 * Deliberately exposes nothing about configuration, credentials or the
 * state of backing services.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'masar-hr-api',
            'version' => 'v1',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
