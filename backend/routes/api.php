<?php

use App\Modules\Organization\Infrastructure\Authorization\OrganizationPermissionCatalog as OrgPerm;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationalUnitController;
use App\Modules\Platform\Presentation\Http\Controllers\HealthController;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog as RefPerm;
use App\Modules\Reference\Presentation\Http\Controllers\ContractBasedPopulationCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\ContractTypePopulationMappingController;
use App\Modules\Reference\Presentation\Http\Controllers\DecisionTypeController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentStatusCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentStatusDetailBehaviorController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentStatusDetailController;
use App\Modules\Reference\Presentation\Http\Controllers\GenderController;
use App\Modules\Reference\Presentation\Http\Controllers\JobTitleAdministratorClassificationController;
use App\Modules\Reference\Presentation\Http\Controllers\MaritalStatusController;
use App\Modules\Reference\Presentation\Http\Controllers\MonthlyCadreCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\SpecialtyCadreCategoryMappingController;
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

        Route::middleware(['auth:web', 'principal.active', 'resolve.context'])->group(function (): void {
            Route::post('/logout', LogoutController::class)->name('logout');
            Route::get('/me', CurrentPrincipalController::class)->name('me');
            Route::put('/password', ChangeOwnPasswordController::class)->name('password');
        });
    });

    Route::prefix('security')
        ->name('api.v1.security.')
        ->middleware(['auth:web', 'principal.active', 'resolve.context'])
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

    Route::prefix('reference')
        ->name('api.v1.reference.')
        ->middleware(['auth:web', 'principal.active', 'resolve.context'])
        ->group(function (): void {
            $simpleFamilies = [
                'genders' => [GenderController::class, 'gender'],
                'marital-statuses' => [MaritalStatusController::class, 'maritalStatus'],
                'decision-types' => [DecisionTypeController::class, 'decisionType'],
                'employment-status-categories' => [EmploymentStatusCategoryController::class, 'employmentStatusCategory'],
                'monthly-cadre-categories' => [MonthlyCadreCategoryController::class, 'monthlyCadreCategory'],
                'contract-based-population-categories' => [ContractBasedPopulationCategoryController::class, 'contractBasedPopulationCategory'],
            ];

            foreach ($simpleFamilies as $segment => [$controller, $param]) {
                Route::get("/{$segment}", [$controller, 'index'])
                    ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name("{$segment}.index");
                Route::get("/{$segment}/{{$param}}", [$controller, 'show'])
                    ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name("{$segment}.show");
                Route::post("/{$segment}", [$controller, 'store'])
                    ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name("{$segment}.store");
                Route::patch("/{$segment}/{{$param}}", [$controller, 'updateMetadata'])
                    ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name("{$segment}.update");
                Route::post("/{$segment}/{{$param}}/activate", [$controller, 'activate'])
                    ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name("{$segment}.activate");
                Route::post("/{$segment}/{{$param}}/deactivate", [$controller, 'deactivate'])
                    ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name("{$segment}.deactivate");
            }

            Route::get('/employment-status-details', [EmploymentStatusDetailController::class, 'index'])
                ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name('employment-status-details.index');
            Route::get('/employment-status-details/{employmentStatusDetail}', [EmploymentStatusDetailController::class, 'show'])
                ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name('employment-status-details.show');
            Route::post('/employment-status-details', [EmploymentStatusDetailController::class, 'store'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('employment-status-details.store');
            Route::patch('/employment-status-details/{employmentStatusDetail}', [EmploymentStatusDetailController::class, 'updateMetadata'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('employment-status-details.update');
            Route::post('/employment-status-details/{employmentStatusDetail}/activate', [EmploymentStatusDetailController::class, 'activate'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('employment-status-details.activate');
            Route::post('/employment-status-details/{employmentStatusDetail}/deactivate', [EmploymentStatusDetailController::class, 'deactivate'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('employment-status-details.deactivate');

            Route::get('/employment-status-details/{employmentStatusDetail}/behaviors', [EmploymentStatusDetailBehaviorController::class, 'index'])
                ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name('employment-status-details.behaviors.index');
            Route::post('/employment-status-details/{employmentStatusDetail}/behaviors', [EmploymentStatusDetailBehaviorController::class, 'store'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('employment-status-details.behaviors.store');

            // S06 spec §15: three reporting-reference mapping sub-resources, same
            // index(list)+store(define new period) shape as employment-status-details/.../behaviors.
            Route::get('/specialties/{specialty}/cadre-category-mappings', [SpecialtyCadreCategoryMappingController::class, 'index'])
                ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name('specialties.cadre-category-mappings.index');
            Route::post('/specialties/{specialty}/cadre-category-mappings', [SpecialtyCadreCategoryMappingController::class, 'store'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('specialties.cadre-category-mappings.store');

            Route::get('/job-titles/{jobTitle}/administrator-classifications', [JobTitleAdministratorClassificationController::class, 'index'])
                ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name('job-titles.administrator-classifications.index');
            Route::post('/job-titles/{jobTitle}/administrator-classifications', [JobTitleAdministratorClassificationController::class, 'store'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('job-titles.administrator-classifications.store');

            Route::get('/contract-types/{contractType}/population-mappings', [ContractTypePopulationMappingController::class, 'index'])
                ->middleware('permission:'.RefPerm::REFERENCE_VIEW)->name('contract-types.population-mappings.index');
            Route::post('/contract-types/{contractType}/population-mappings', [ContractTypePopulationMappingController::class, 'store'])
                ->middleware('permission:'.RefPerm::REFERENCE_MANAGE)->name('contract-types.population-mappings.store');
        });

    // S07 spec §15: /organization/units/roots is registered before the {organizationalUnit}
    // wildcard so "roots" is never captured as a route-model-bound unit id.
    Route::prefix('organization')
        ->name('api.v1.organization.')
        ->middleware(['auth:web', 'principal.active', 'resolve.context'])
        ->group(function (): void {
            Route::get('/units', [OrganizationalUnitController::class, 'index'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_VIEW)->name('units.index');
            Route::get('/units/roots', [OrganizationalUnitController::class, 'roots'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_VIEW)->name('units.roots');
            Route::get('/units/{organizationalUnit}', [OrganizationalUnitController::class, 'show'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_VIEW)->name('units.show');
            Route::get('/units/{organizationalUnit}/children', [OrganizationalUnitController::class, 'children'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_VIEW)->name('units.children');
            Route::get('/units/{organizationalUnit}/ancestors', [OrganizationalUnitController::class, 'ancestors'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_VIEW)->name('units.ancestors');
            Route::get('/units/{organizationalUnit}/descendants', [OrganizationalUnitController::class, 'descendants'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_VIEW)->name('units.descendants');

            Route::post('/units', [OrganizationalUnitController::class, 'store'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_MANAGE)->name('units.store');
            Route::patch('/units/{organizationalUnit}', [OrganizationalUnitController::class, 'rename'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_MANAGE)->name('units.rename');
            Route::post('/units/{organizationalUnit}/move', [OrganizationalUnitController::class, 'move'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_MANAGE)->name('units.move');
            Route::post('/units/{organizationalUnit}/activate', [OrganizationalUnitController::class, 'activate'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_MANAGE)->name('units.activate');
            Route::post('/units/{organizationalUnit}/deactivate', [OrganizationalUnitController::class, 'deactivate'])
                ->middleware('permission:'.OrgPerm::ORGANIZATION_MANAGE)->name('units.deactivate');
        });
});
