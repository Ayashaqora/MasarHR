<?php

use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog as HrPerm;
use App\Modules\HumanResources\Presentation\Http\Controllers\EmploymentCategoryPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\EmploymentContractPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\EmploymentJobTitlePeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\EmploymentRelationshipController;
use App\Modules\HumanResources\Presentation\Http\Controllers\EmploymentSpecialtyPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\EmploymentStatusPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\FullSecondmentPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\MovementExpiryFollowUpController;
use App\Modules\HumanResources\Presentation\Http\Controllers\OrganizationalPlacementPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\PartialSecondmentPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\PersonController;
use App\Modules\HumanResources\Presentation\Http\Controllers\PersonQualificationController;
use App\Modules\HumanResources\Presentation\Http\Controllers\ReturnIntentionPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\TransferController;
use App\Modules\HumanResources\Presentation\Http\Controllers\WorkplaceAssignmentPeriodController;
use App\Modules\HumanResources\Presentation\Http\Controllers\WorkSchedulePeriodController;
use App\Modules\Organization\Infrastructure\Authorization\OrganizationPermissionCatalog as OrgPerm;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationalUnitController;
use App\Modules\Platform\Presentation\Http\Controllers\HealthController;
use App\Modules\Reference\Infrastructure\Authorization\ReferencePermissionCatalog as RefPerm;
use App\Modules\Reference\Presentation\Http\Controllers\AcademicDegreeController;
use App\Modules\Reference\Presentation\Http\Controllers\ContractBasedPopulationCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\ContractTypeController;
use App\Modules\Reference\Presentation\Http\Controllers\ContractTypePopulationMappingController;
use App\Modules\Reference\Presentation\Http\Controllers\DecisionTypeController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentStatusCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentStatusDetailBehaviorController;
use App\Modules\Reference\Presentation\Http\Controllers\EmploymentStatusDetailController;
use App\Modules\Reference\Presentation\Http\Controllers\GenderController;
use App\Modules\Reference\Presentation\Http\Controllers\JobTitleAdministratorClassificationController;
use App\Modules\Reference\Presentation\Http\Controllers\JobTitleController;
use App\Modules\Reference\Presentation\Http\Controllers\LeaveStatusController;
use App\Modules\Reference\Presentation\Http\Controllers\LeaveTypeController;
use App\Modules\Reference\Presentation\Http\Controllers\MaritalStatusController;
use App\Modules\Reference\Presentation\Http\Controllers\MonthlyCadreCategoryController;
use App\Modules\Reference\Presentation\Http\Controllers\QualificationTypeController;
use App\Modules\Reference\Presentation\Http\Controllers\SpecialtyCadreCategoryMappingController;
use App\Modules\Reference\Presentation\Http\Controllers\SpecialtyController;
use App\Modules\Reference\Presentation\Http\Controllers\SupervisoryTitleController;
use App\Modules\Security\Infrastructure\Authorization\PermissionCatalog as Perm;
use App\Modules\Security\Presentation\Http\Controllers\Auth\ChangeOwnPasswordController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\CsrfCookieController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\CurrentPrincipalController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\LoginController;
use App\Modules\Security\Presentation\Http\Controllers\Auth\LogoutController;
use App\Modules\Security\Presentation\Http\Controllers\Security\OrganizationalScopeController;
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

            // S08 (docs/organizational-access-scope-specification.md §18). 'effective' is
            // registered before the {organizationalScopeGrant} wildcard route, mirroring the exact
            // ordering discipline S07 already used for /units/roots vs /units/{organizationalUnit},
            // so it is never captured by route-model binding.
            Route::get('/principals/{principal}/organizational-scopes/effective', [OrganizationalScopeController::class, 'effective'])
                ->middleware('permission:'.Perm::ORGANIZATION_SCOPES_MANAGE)->name('principals.organizational-scopes.effective');
            Route::get('/principals/{principal}/organizational-scopes', [OrganizationalScopeController::class, 'index'])
                ->middleware('permission:'.Perm::ORGANIZATION_SCOPES_MANAGE)->name('principals.organizational-scopes.index');
            Route::post('/principals/{principal}/organizational-scopes', [OrganizationalScopeController::class, 'store'])
                ->middleware('permission:'.Perm::ORGANIZATION_SCOPES_MANAGE)->name('principals.organizational-scopes.store');
            Route::delete('/principals/{principal}/organizational-scopes/{organizationalScopeGrant}', [OrganizationalScopeController::class, 'destroy'])
                ->middleware('permission:'.Perm::ORGANIZATION_SCOPES_MANAGE)->name('principals.organizational-scopes.destroy');
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
                // S13 Reference Catalog Administration Foundation (docs/reference-catalog-administration-foundation-specification.md
                // §6): eight already-modeled-but-unadministered catalogs, added to this same explicit,
                // compile-time, allowlisted registry — never a client-selected table. ref.decision_types
                // already has full administration since S05 and needs no new entry here (spec §4.2/§7).
                'job-titles' => [JobTitleController::class, 'jobTitle'],
                'employment-categories' => [EmploymentCategoryController::class, 'employmentCategory'],
                'contract-types' => [ContractTypeController::class, 'contractType'],
                'qualification-types' => [QualificationTypeController::class, 'qualificationType'],
                'academic-degrees' => [AcademicDegreeController::class, 'academicDegree'],
                'supervisory-titles' => [SupervisoryTitleController::class, 'supervisoryTitle'],
                'leave-types' => [LeaveTypeController::class, 'leaveType'],
                'leave-statuses' => [LeaveStatusController::class, 'leaveStatus'],
                // S25 Specialty Catalog Administration Foundation
                // (docs/specialty-catalog-administration-foundation-specification.md, ADR-S25-001): the
                // existing canonical ref.specialties catalog joins the same registry. Catalog rows only —
                // the S06 /specialties/{specialty}/cadre-category-mappings sub-resource below is unchanged.
                'specialties' => [SpecialtyController::class, 'specialty'],
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

    // S09 spec §19: /hr/persons/lookup is registered before the {person} wildcard, mirroring the
    // exact ordering discipline S07/S08 already use for their own literal-segment routes.
    Route::prefix('hr')
        ->name('api.v1.hr.')
        ->middleware(['auth:web', 'principal.active', 'resolve.context'])
        ->group(function (): void {
            Route::get('/persons/lookup', [PersonController::class, 'lookup'])
                ->middleware('permission:'.HrPerm::PERSONS_VIEW)->name('persons.lookup');
            Route::get('/persons/{person}', [PersonController::class, 'show'])
                ->middleware('permission:'.HrPerm::PERSONS_VIEW)->name('persons.show');
            Route::post('/persons', [PersonController::class, 'store'])
                ->middleware('permission:'.HrPerm::PERSONS_CREATE)->name('persons.store');
            // S24: explicit Person profile action route (docs/person-profile-foundation-specification.md
            // §S24.8/§S24.13) — the HR command-route convention (/end, /transfer, /move), never a
            // generic PATCH of the Person. Cannot touch national_id or any employment data.
            Route::post('/persons/{person}/update-profile', [PersonController::class, 'updateProfile'])
                ->middleware('permission:'.HrPerm::PERSONS_UPDATE_PROFILE)->name('persons.update-profile');

            // S23: Person Qualifications, nested directly under {person} — a Person fact, never an
            // Employment Relationship one (docs/person-qualification-foundation-specification.md
            // §S23.13). Plain Person-level RBAC like S09's hr.persons.* routes (ADR-S23-001 §9).
            // Explicit record action only — no PATCH, no DELETE, no correction route.
            Route::get('/persons/{person}/qualifications', [PersonQualificationController::class, 'index'])
                ->middleware('permission:'.HrPerm::PERSON_QUALIFICATIONS_VIEW)->name('persons.qualifications.index');
            Route::post('/persons/{person}/qualifications', [PersonQualificationController::class, 'store'])
                ->middleware('permission:'.HrPerm::PERSON_QUALIFICATIONS_RECORD)->name('persons.qualifications.store');

            Route::get('/persons/{person}/employment-relationships', [EmploymentRelationshipController::class, 'index'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_RELATIONSHIPS_VIEW)->name('persons.employment-relationships.index');
            Route::post('/persons/{person}/employment-relationships', [EmploymentRelationshipController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_RELATIONSHIPS_CREATE)->name('persons.employment-relationships.store');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/end', [EmploymentRelationshipController::class, 'end'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_RELATIONSHIPS_END)->name('persons.employment-relationships.end');

            // S10: Employment Status History, nested under the same {person}/{employmentRelationship}.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/status-periods', [EmploymentStatusPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_STATUS_PERIODS_VIEW)->name('persons.employment-relationships.status-periods.index');
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/effective-status', [EmploymentStatusPeriodController::class, 'effective'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_STATUS_PERIODS_VIEW)->name('persons.employment-relationships.effective-status');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/status-periods', [EmploymentStatusPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_STATUS_PERIODS_RECORD)->name('persons.employment-relationships.status-periods.store');

            // S34: Return Intention (independent of employment status), nested under the same
            // {person}/{employmentRelationship}. Explicit record + history + effective as-of; no PATCH/DELETE.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/return-intention-periods', [ReturnIntentionPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::RETURN_INTENTION_PERIODS_VIEW)->name('persons.employment-relationships.return-intention-periods.index');
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/return-intention', [ReturnIntentionPeriodController::class, 'effective'])
                ->middleware('permission:'.HrPerm::RETURN_INTENTION_PERIODS_VIEW)->name('persons.employment-relationships.return-intention');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/return-intention-periods', [ReturnIntentionPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::RETURN_INTENTION_PERIODS_RECORD)->name('persons.employment-relationships.return-intention-periods.store');

            // S11: Organizational Placement, nested under the same {person}/{employmentRelationship}.
            // The permission: middleware is the coarse RBAC (WHAT) gate only — the controller
            // additionally composes S08 organizational scope (WHERE) via ScopedAuthorizationChecker
            // (docs/organizational-placement-foundation-specification.md §10).
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/placement-periods', [OrganizationalPlacementPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::ORGANIZATIONAL_PLACEMENT_PERIODS_VIEW)->name('persons.employment-relationships.placement-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/placement-periods', [OrganizationalPlacementPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::ORGANIZATIONAL_PLACEMENT_PERIODS_RECORD)->name('persons.employment-relationships.placement-periods.store');

            // S12: Full Secondment, nested under the same {person}/{employmentRelationship}. The
            // permission: middleware is the coarse RBAC (WHAT) gate only — the controller
            // additionally composes S08 organizational scope (WHERE) against up to two target
            // units per operation via ScopedAuthorizationChecker
            // (docs/full-secondment-foundation-specification.md §12.1).
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/full-secondment-periods', [FullSecondmentPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::FULL_SECONDMENT_PERIODS_VIEW)->name('persons.employment-relationships.full-secondment-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/full-secondment-periods', [FullSecondmentPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::FULL_SECONDMENT_PERIODS_START)->name('persons.employment-relationships.full-secondment-periods.store');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/full-secondment-periods/end', [FullSecondmentPeriodController::class, 'end'])
                ->middleware('permission:'.HrPerm::FULL_SECONDMENT_PERIODS_END)->name('persons.employment-relationships.full-secondment-periods.end');
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/actual-workplace', [FullSecondmentPeriodController::class, 'actualWorkplace'])
                ->middleware('permission:'.HrPerm::FULL_SECONDMENT_PERIODS_VIEW)->name('persons.employment-relationships.actual-workplace.show');

            // S14: Transfer Foundation, nested under the same {person}/{employmentRelationship}. An
            // explicit action route (POST .../transfer), not a generic PATCH (spec §20,
            // mirroring S12's own §21 "no generic PATCH" convention) — no new list/show route exists
            // because Transfer writes no resource of its own to read back (spec §16); its effects
            // are read via the existing placement-periods/full-secondment-periods/actual-workplace
            // routes above. The permission: middleware is the coarse RBAC (WHAT) gate only — the
            // controller additionally composes S08 organizational scope (WHERE) against up to THREE
            // target units (docs/transfer-foundation-specification.md §12.1).
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/transfer', [TransferController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_RELATIONSHIPS_TRANSFER)->name('persons.employment-relationships.transfer.store');

            // S16: Workplace Assignment, nested under the same {person}/{employmentRelationship}.
            // The permission: middleware is the coarse RBAC (WHAT) gate only — the controller
            // additionally composes S08 organizational scope (WHERE) against up to two target
            // units per operation via ScopedAuthorizationChecker
            // (docs/workplace-assignment-foundation-specification.md §S16.14/§S16.16). No new
            // "resolve actual workplace" route is added — the existing actual-workplace route
            // above already covers an active assignment, since ResolveActualWorkplaceForRelationship
            // was extended in place (spec §S16.8).
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/workplace-assignment-periods', [WorkplaceAssignmentPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::WORKPLACE_ASSIGNMENT_PERIODS_VIEW)->name('persons.employment-relationships.workplace-assignment-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/workplace-assignment-periods', [WorkplaceAssignmentPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::WORKPLACE_ASSIGNMENT_PERIODS_START)->name('persons.employment-relationships.workplace-assignment-periods.store');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/workplace-assignment-periods/end', [WorkplaceAssignmentPeriodController::class, 'end'])
                ->middleware('permission:'.HrPerm::WORKPLACE_ASSIGNMENT_PERIODS_END)->name('persons.employment-relationships.workplace-assignment-periods.end');

            // S20: Employment Category History, nested under the same {person}/{employmentRelationship}
            // (docs/employment-category-history-foundation-specification.md §S20.13). Plain RBAC
            // only — the permission: middleware is the whole gate, exactly like S10's status-period
            // routes (ADR-S20-001 §8). Explicit record action only: no PATCH, no DELETE, no end
            // route. The ref.employment_categories catalog itself stays administered solely by the
            // existing /reference/employment-categories routes above.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/employment-category-periods', [EmploymentCategoryPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_CATEGORY_PERIODS_VIEW)->name('persons.employment-relationships.employment-category-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/employment-category-periods', [EmploymentCategoryPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_CATEGORY_PERIODS_RECORD)->name('persons.employment-relationships.employment-category-periods.store');

            // S21: Employment Contract periods, nested under the same {person}/{employmentRelationship}
            // (docs/employment-contract-foundation-specification.md §S21.14). Plain RBAC only — the
            // permission: middleware is the whole gate, exactly like S10/S20 (ADR-S21-001 §10). One
            // explicit record action covers the initial contract and renewals; no PATCH, no DELETE,
            // no manual contract-end route. ref.contract_types stays administered solely by the
            // existing /reference/contract-types routes.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/employment-contract-periods', [EmploymentContractPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_CONTRACT_PERIODS_VIEW)->name('persons.employment-relationships.employment-contract-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/employment-contract-periods', [EmploymentContractPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_CONTRACT_PERIODS_RECORD)->name('persons.employment-relationships.employment-contract-periods.store');

            // S22: Employment Job Title periods, nested under the same {person}/{employmentRelationship}
            // (docs/employment-job-title-history-foundation-specification.md §S22.14). Plain RBAC
            // only, exactly like S10/S20/S21 (ADR-S22-001 §9). Explicit record action only — no
            // PATCH, no DELETE, no end route. ref.job_titles stays administered solely by the
            // existing /reference/job-titles routes; supervisory titles are not touched.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/employment-job-title-periods', [EmploymentJobTitlePeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_JOB_TITLE_PERIODS_VIEW)->name('persons.employment-relationships.employment-job-title-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/employment-job-title-periods', [EmploymentJobTitlePeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_JOB_TITLE_PERIODS_RECORD)->name('persons.employment-relationships.employment-job-title-periods.store');

            // S26: Employee Specialty periods, nested under the same {person}/{employmentRelationship}
            // (docs/employee-specialty-history-foundation-specification.md §S26.14). Plain RBAC only,
            // exactly like S22. Explicit record action only — no PATCH, no DELETE, no end or
            // correction route. ref.specialties stays administered solely by the S25
            // /reference/specialties routes.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/employment-specialty-periods', [EmploymentSpecialtyPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_SPECIALTY_PERIODS_VIEW)->name('persons.employment-relationships.employment-specialty-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/employment-specialty-periods', [EmploymentSpecialtyPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::EMPLOYMENT_SPECIALTY_PERIODS_RECORD)->name('persons.employment-relationships.employment-specialty-periods.store');

            // S29: Work Schedule periods, nested under the same {person}/{employmentRelationship}
            // (docs/work-schedule-foundation-specification.md §S29.13, ADR-S29-001). Plain RBAC only,
            // exactly like S26. Explicit record action only — no PATCH, no DELETE, no end or
            // correction route. ref.weekdays is structural and has no administration route.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/work-schedule-periods', [WorkSchedulePeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::WORK_SCHEDULE_PERIODS_VIEW)->name('persons.employment-relationships.work-schedule-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/work-schedule-periods', [WorkSchedulePeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::WORK_SCHEDULE_PERIODS_RECORD)->name('persons.employment-relationships.work-schedule-periods.store');

            // S30: Partial Secondment periods, nested under the same {person}/{employmentRelationship}
            // (docs/partial-secondment-foundation-specification.md §S30.19, ADR-S30-001). RBAC via
            // permission: middleware plus the S08 organizational scope checks in the controller,
            // exactly like S12/S16. Explicit record action only — no PATCH, no PUT, no DELETE, no
            // end or correction route.
            Route::get('/persons/{person}/employment-relationships/{employmentRelationship}/partial-secondment-periods', [PartialSecondmentPeriodController::class, 'index'])
                ->middleware('permission:'.HrPerm::PARTIAL_SECONDMENT_PERIODS_VIEW)->name('persons.employment-relationships.partial-secondment-periods.index');
            Route::post('/persons/{person}/employment-relationships/{employmentRelationship}/partial-secondment-periods', [PartialSecondmentPeriodController::class, 'store'])
                ->middleware('permission:'.HrPerm::PARTIAL_SECONDMENT_PERIODS_RECORD)->name('persons.employment-relationships.partial-secondment-periods.store');

            // S31: movement expiry follow-ups — read only (docs/movement-expiry-followup-foundation-
            // specification.md §S31.17). Written only by the system scanner: no store, no PATCH/PUT,
            // no DELETE. RBAC via permission: middleware plus S08 scope filtering in the controller.
            Route::get('/movement-expiry-followups', [MovementExpiryFollowUpController::class, 'index'])
                ->middleware('permission:'.HrPerm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW)->name('movement-expiry-followups.index');
        });
});
