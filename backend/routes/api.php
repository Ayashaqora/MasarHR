<?php

use App\Modules\Platform\Presentation\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — served under the /api/v1 prefix (see bootstrap/app.php)
|--------------------------------------------------------------------------
|
| Only infrastructure-level routes live here in S01. Domain modules will
| register their own routes in later, separately authorized stages.
|
*/

Route::get('/health', HealthController::class)->name('api.v1.health');
