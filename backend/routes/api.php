<?php

use App\Modules\Platform\Presentation\Http\Controllers\HealthController;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog as Perm;
use App\Modules\Security\Presentation\Http\Controllers\Auth\ChangeOwnPasswordController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\CsrfCookieController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\CurrentPrincipalController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\LoginController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\LogoutController;
use App\Modules\Security\Presentation\Http\Controllers\Security\PermissionController;
use App\Modules\Security\Presentation\Http\Controllers\Security\PrincipalController;
use App\Modules\Security\Presentation\Http\Controllers\Security\RoleAssignmentController;
use App\Modules\Security\Presentation\Http\Controllers\Security\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — served under the /api/v1 prefix (see bootstrap/app.php)
|--------------------------------------------------------------------------
|
| /health stays stateless (the 'api' middleware group). Authentication and Security
| administration routes run through the 'web' middleware group instead — session, cookies and
| Laravel's own CSRF (Sec-Fetch-Site / token) protection — which is what gives MasarHR first-party,
| Sanctum-compatible SPA session authentication (§12 of the S03 authorization) without introducing
| JWT or a token-in-localStorage architecture.
|
*/

Route::get('/health', HealthController::class)->name('api.v1.health');

Route::middleware('web')->group(function (): void {
    Route::prefix('auth')->name('api.v1.auth.')->group(function (): void {
        Route::get('/csrf-cookie', CsrfCookieController::class)->name('csrf-cookie');
        Route::post('/login', LoginController::class)->middleware('throttle:login')->name('login');

        Route::middleware(['auth:web', 'principal.active'])->group(function (): void {
            Route::post('/logout', LogoutController::class)->name('logout');
            Route::get('/me', CurrentPrincipalController::class)->name('me');
            Route::put('/password', ChangeOwnPasswordController::class)->name('password');
        });
    });

    Route::prefix('security')
        ->name('api.v1.security.')
        ->middleware(['auth:web', 'principal.active'])
        ->group(function (): void {
            Route::get('/principals', [PrincipalController::class, 'index'])
                ->middleware('permission:'.Perm::USERS_VIEW)->name('principals.index');
            Route::get('/principals/{principal}', [PrincipalController::class, 'show'])
                ->middleware('permission:'.Perm::USERS_VIEW)->name('principals.show');
            Route::get('/principals/{principal}/permissions', [PrincipalController::class, 'effectivePermissions'])
                ->middleware('permission:'.Perm::USERS_VIEW)->name('principals.permissions');
            Route::post('/principals', [PrincipalController::class, 'store'])
                ->middleware('permission:'.Perm::USERS_CREATE)->name('principals.store');
            Route::patch('/principals/{principal}/username', [PrincipalController::class, 'updateUsername'])
                ->middleware('permission:'.Perm::USERS_UPDATE)->name('principals.username');
            Route::patch('/principals/{principal}/display-name', [PrincipalController::class, 'updateDisplayName'])
                ->middleware('permission:'.Perm::USERS_UPDATE)->name('principals.display-name');
            Route::put('/principals/{principal}/password', [PrincipalController::class, 'resetPassword'])
                ->middleware('permission:'.Perm::USERS_UPDATE)->name('principals.password');
            Route::patch('/principals/{principal}/status', [PrincipalController::class, 'updateStatus'])
                ->middleware('permission:'.Perm::USERS_STATUS_MANAGE)->name('principals.status');

            Route::post('/principals/{principal}/roles', [RoleAssignmentController::class, 'store'])
                ->middleware('permission:'.Perm::ROLE_ASSIGNMENTS_MANAGE)->name('principals.roles.store');
            Route::delete('/principals/{principal}/roles/{role}', [RoleAssignmentController::class, 'destroy'])
                ->middleware('permission:'.Perm::ROLE_ASSIGNMENTS_MANAGE)->name('principals.roles.destroy');

            Route::get('/roles', [RoleController::class, 'index'])
                ->middleware('permission:'.Perm::ROLES_VIEW)->name('roles.index');
            Route::get('/roles/{role}', [RoleController::class, 'show'])
                ->middleware('permission:'.Perm::ROLES_VIEW)->name('roles.show');
            Route::post('/roles', [RoleController::class, 'store'])
                ->middleware('permission:'.Perm::ROLES_MANAGE)->name('roles.store');
            Route::patch('/roles/{role}', [RoleController::class, 'updateMetadata'])
                ->middleware('permission:'.Perm::ROLES_MANAGE)->name('roles.update');
            Route::post('/roles/{role}/activate', [RoleController::class, 'activate'])
                ->middleware('permission:'.Perm::ROLES_MANAGE)->name('roles.activate');
            Route::post('/roles/{role}/deactivate', [RoleController::class, 'deactivate'])
                ->middleware('permission:'.Perm::ROLES_MANAGE)->name('roles.deactivate');
            Route::post('/roles/{role}/permissions', [RoleController::class, 'grantPermission'])
                ->middleware('permission:'.Perm::ROLES_MANAGE)->name('roles.permissions.store');
            Route::delete('/roles/{role}/permissions/{permission}', [RoleController::class, 'revokePermission'])
                ->middleware('permission:'.Perm::ROLES_MANAGE)->name('roles.permissions.destroy');

            Route::get('/permissions', [PermissionController::class, 'index'])
                ->middleware('permission:'.Perm::PERMISSIONS_VIEW)->name('permissions.index');
        });
});
