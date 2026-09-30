<?php

use App\Modules\HumanResources\Domain\Exceptions\ActiveFullSecondmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActivePartialSecondmentExistsException;
use App\Modules\HumanResources\Domain\Exceptions\ActiveWorkplaceAssignmentAlreadyExistsException;
use App\Modules\HumanResources\Domain\Exceptions\DuplicateNationalIdException;
use App\Modules\HumanResources\Domain\Exceptions\DuplicatePermanentEmployeeNumberException;
use App\Modules\HumanResources\Domain\Exceptions\DuplicatePersonQualificationException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipNotContractSchemeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentCategoryException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentCategoryPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractTermException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentContractTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentJobTitleException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentJobTitlePeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentSpecialtyException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEmploymentSpecialtyPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentStartDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentWeekdaysException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonProfileException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationAcademicDegreeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPersonQualificationTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPlacementPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodEndException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionValueException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidStatusPeriodEndException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidTransferDecisionTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentDecisionTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentStartDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkSchedulePeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkScheduleWeekdaysException;
use App\Modules\HumanResources\Domain\Exceptions\NoActiveFullSecondmentException;
use App\Modules\HumanResources\Domain\Exceptions\NoActiveWorkplaceAssignmentException;
use App\Modules\HumanResources\Domain\Exceptions\OverlappingEmploymentRelationshipException;
use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentOutsideWorkScheduleException;
use App\Modules\HumanResources\Domain\Exceptions\PartialSecondmentWeekdayConflictException;
use App\Modules\HumanResources\Domain\Exceptions\PersonIsTerminalException;
use App\Modules\HumanResources\Domain\Exceptions\PersonQualificationIdentityMissingException;
use App\Modules\HumanResources\Domain\Exceptions\PersonStaleVersionException;
use App\Modules\HumanResources\Domain\Exceptions\RetiredEmploymentStatusCodeException;
use App\Modules\HumanResources\Domain\Exceptions\UnresolvedEmploymentStatusBehaviorException;
use App\Modules\HumanResources\Domain\Exceptions\WorkScheduleChangeInvalidatesPartialSecondmentException;
use App\Modules\HumanResources\Presentation\Console\ScanEmploymentStatusExpiryFollowUpsCommand;
use App\Modules\HumanResources\Presentation\Console\ScanMovementExpiryFollowUpsCommand;
use App\Modules\Organization\Domain\Exceptions\StaleVersionException as OrganizationStaleVersionException;
use App\Modules\Organization\Domain\Exceptions\WouldCreateCycleException;
use App\Modules\Platform\Presentation\Http\Middleware\ResolveCommandContext;
use App\Modules\Reference\Domain\Exceptions\DuplicateReferenceCodeException;
use App\Modules\Reference\Domain\Exceptions\OverlappingBehaviorPeriodException;
use App\Modules\Reference\Domain\Exceptions\StaleVersionException as ReferenceStaleVersionException;
use App\Modules\Security\Domain\Exceptions\CredentialAlreadyExistsException;
use App\Modules\Security\Domain\Exceptions\DuplicateOrganizationalScopeGrantException;
use App\Modules\Security\Domain\Exceptions\DuplicatePermissionGrantException;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleAssignmentException;
use App\Modules\Security\Domain\Exceptions\DuplicateRoleCodeException;
use App\Modules\Security\Domain\Exceptions\DuplicateUsernameException;
use App\Modules\Security\Domain\Exceptions\IncorrectCurrentPasswordException;
use App\Modules\Security\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Security\Domain\Exceptions\InvalidPasswordException;
use App\Modules\Security\Domain\Exceptions\LastSecurityAdministratorException;
use App\Modules\Security\Domain\Exceptions\StaleVersionException;
use App\Modules\Security\Presentation\Console\BootstrapAdminCommand;
use App\Modules\Security\Presentation\Http\Middleware\EnsurePrincipalIsActive;
use App\Modules\Security\Presentation\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withCommands([
        BootstrapAdminCommand::class,
        ScanMovementExpiryFollowUpsCommand::class,
        ScanEmploymentStatusExpiryFollowUpsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The backend is API-only (see routes/api.php); there is no 'login' web route to redirect
        // an unauthenticated request to. Every api/* response is already forced to JSON below, so
        // an unauthenticated request always gets a 401 JSON body, never a redirect.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'principal.active' => EnsurePrincipalIsActive::class,
            'permission' => RequirePermission::class,
            'resolve.context' => ResolveCommandContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API responses are always JSON; other error handling stays standard Laravel.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // These are routine, expected outcomes of normal use (a mistyped password, a stale form,
        // a duplicate click) — not system errors. Reporting them at ERROR level with a full stack
        // trace on every failed login attempt would be log noise at best and, at worst, a
        // misleading health signal. They are still rendered to the client via the render()
        // callbacks below; only server-side reporting is skipped.
        $exceptions->dontReport([
            InvalidCredentialsException::class,
            StaleVersionException::class,
            LastSecurityAdministratorException::class,
            DuplicateUsernameException::class,
            DuplicateRoleCodeException::class,
            DuplicateRoleAssignmentException::class,
            DuplicatePermissionGrantException::class,
            CredentialAlreadyExistsException::class,
            IncorrectCurrentPasswordException::class,
            InvalidPasswordException::class,
            ReferenceStaleVersionException::class,
            DuplicateReferenceCodeException::class,
            OverlappingBehaviorPeriodException::class,
            OrganizationStaleVersionException::class,
            WouldCreateCycleException::class,
            DuplicateOrganizationalScopeGrantException::class,
            DuplicateNationalIdException::class,
            OverlappingEmploymentRelationshipException::class,
            DuplicatePermanentEmployeeNumberException::class,
            PersonIsTerminalException::class,
            EmploymentRelationshipAlreadyEndedException::class,
            InvalidEndDateException::class,
            InvalidStatusPeriodDateException::class,
            InvalidStatusPeriodEndException::class,
            InvalidReturnIntentionPeriodDateException::class,
            InvalidReturnIntentionPeriodEndException::class,
            InvalidReturnIntentionValueException::class,
            RetiredEmploymentStatusCodeException::class,
            UnresolvedEmploymentStatusBehaviorException::class,
            InvalidPlacementPeriodDateException::class,
            ActiveFullSecondmentAlreadyExistsException::class,
            NoActiveFullSecondmentException::class,
            InvalidFullSecondmentStartDateException::class,
            InvalidFullSecondmentEndDateException::class,
            InvalidTransferDecisionTypeException::class,
            ActiveWorkplaceAssignmentAlreadyExistsException::class,
            NoActiveWorkplaceAssignmentException::class,
            InvalidWorkplaceAssignmentDecisionTypeException::class,
            InvalidWorkplaceAssignmentStartDateException::class,
            InvalidWorkplaceAssignmentEndDateException::class,
            InvalidEmploymentCategoryException::class,
            InvalidEmploymentCategoryPeriodDateException::class,
            EmploymentRelationshipNotContractSchemeException::class,
            InvalidEmploymentContractTypeException::class,
            InvalidEmploymentContractPeriodDateException::class,
            InvalidEmploymentContractTermException::class,
            InvalidEmploymentJobTitleException::class,
            InvalidEmploymentJobTitlePeriodDateException::class,
            InvalidEmploymentSpecialtyException::class,
            InvalidEmploymentSpecialtyPeriodDateException::class,
            InvalidWorkScheduleWeekdaysException::class,
            InvalidWorkSchedulePeriodDateException::class,
            ActivePartialSecondmentExistsException::class,
            InvalidPartialSecondmentEndDateException::class,
            InvalidPartialSecondmentPeriodDateException::class,
            InvalidPartialSecondmentWeekdaysException::class,
            PartialSecondmentOutsideWorkScheduleException::class,
            PartialSecondmentWeekdayConflictException::class,
            WorkScheduleChangeInvalidatesPartialSecondmentException::class,
            DuplicatePersonQualificationException::class,
            InvalidPersonQualificationAcademicDegreeException::class,
            InvalidPersonQualificationTypeException::class,
            PersonQualificationIdentityMissingException::class,
            InvalidPersonProfileException::class,
            PersonStaleVersionException::class,
        ]);

        // §22 of the S03 authorization: preserve stated HTTP semantics for Security domain
        // failures. Every message here is generic/domain-level — never a SQLSTATE, SQL fragment,
        // stack trace, hash, or session/cookie value (Laravel's own APP_DEBUG=false default
        // already suppresses trace/SQL detail on every other exception).
        $exceptions->render(fn (InvalidCredentialsException $e) => response()->json(['message' => $e->getMessage()], 401));

        $exceptions->render(fn (StaleVersionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (LastSecurityAdministratorException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicateRoleAssignmentException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicatePermissionGrantException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (CredentialAlreadyExistsException $e) => response()->json(['message' => $e->getMessage()], 409));

        // S05 Reference-module domain failures (docs/reference-data-foundation-specification.md §14).
        $exceptions->render(fn (ReferenceStaleVersionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (OverlappingBehaviorPeriodException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicateReferenceCodeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['code' => [$e->getMessage()]],
        ], 422));

        // S07 Organization-module domain failures
        // (docs/organization-hierarchy-foundation-specification.md §26).
        $exceptions->render(fn (OrganizationStaleVersionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (WouldCreateCycleException $e) => response()->json(['message' => $e->getMessage()], 409));

        // S08 Security-module domain failure (docs/organizational-access-scope-specification.md §20).
        $exceptions->render(fn (DuplicateOrganizationalScopeGrantException $e) => response()->json(['message' => $e->getMessage()], 409));

        // S09 HumanResources-module domain failures
        // (docs/person-employment-foundation-specification.md §11).
        $exceptions->render(fn (DuplicateNationalIdException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (OverlappingEmploymentRelationshipException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (DuplicatePermanentEmployeeNumberException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (PersonIsTerminalException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (EmploymentRelationshipAlreadyEndedException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (InvalidEndDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_to' => [$e->getMessage()]],
        ], 422));

        // S10 HumanResources-module domain failures
        // (docs/employment-status-history-foundation-specification.md §9).
        $exceptions->render(fn (InvalidStatusPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidStatusPeriodEndException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_to' => [$e->getMessage()]],
        ], 422));
        // S34 Return Intention (independent of employment status).
        $exceptions->render(fn (InvalidReturnIntentionPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidReturnIntentionPeriodEndException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_to' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidReturnIntentionValueException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['intention' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (RetiredEmploymentStatusCodeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['status_detail_code' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (UnresolvedEmploymentStatusBehaviorException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S11 HumanResources-module domain failure
        // (docs/organizational-placement-foundation-specification.md §9). Recording against an
        // already-ended relationship reuses EmploymentRelationshipAlreadyEndedException (S09),
        // already mapped above — no new mapping needed for it.
        $exceptions->render(fn (InvalidPlacementPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S12 HumanResources-module domain failures
        // (docs/full-secondment-foundation-specification.md §11). Starting against an
        // already-ended relationship reuses EmploymentRelationshipAlreadyEndedException (S09),
        // already mapped above — no new mapping needed for it.
        $exceptions->render(fn (ActiveFullSecondmentAlreadyExistsException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (NoActiveFullSecondmentException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (InvalidFullSecondmentStartDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidFullSecondmentEndDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_to' => [$e->getMessage()]],
        ], 422));

        // S14 HumanResources-module domain failure (docs/transfer-foundation-specification.md
        // §11). An already-ended relationship reuses EmploymentRelationshipAlreadyEndedException
        // (S09); an invalid effective date reuses InvalidPlacementPeriodDateException (S11) or
        // InvalidFullSecondmentEndDateException (S12) — both already mapped above, no new mapping
        // needed for either.
        $exceptions->render(fn (InvalidTransferDecisionTypeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['decision_type_id' => [$e->getMessage()]],
        ], 422));

        // S16 HumanResources-module domain failures
        // (docs/workplace-assignment-foundation-specification.md §S16.17). An already-ended
        // relationship reuses EmploymentRelationshipAlreadyEndedException (S09); the two mutual-
        // exclusion conflicts reuse ActiveFullSecondmentAlreadyExistsException (S12, raised by
        // StartWorkplaceAssignment) and the new ActiveWorkplaceAssignmentAlreadyExistsException
        // (raised by StartFullSecondment) below — both already mapped as 409s.
        $exceptions->render(fn (ActiveWorkplaceAssignmentAlreadyExistsException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (NoActiveWorkplaceAssignmentException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (InvalidWorkplaceAssignmentDecisionTypeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['decision_type_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidWorkplaceAssignmentStartDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidWorkplaceAssignmentEndDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_to' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidWorkplaceAssignmentPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S20 HumanResources-module domain failures
        // (docs/employment-category-history-foundation-specification.md §S20.14). An already-ended
        // relationship reuses EmploymentRelationshipAlreadyEndedException (S09, 409); a relationship
        // end that would leave a category period beyond the relationship reuses S09's own
        // InvalidEndDateException (422) — both already mapped above.
        $exceptions->render(fn (InvalidEmploymentCategoryException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['employment_category_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidEmploymentCategoryPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S21 HumanResources-module domain failures
        // (docs/employment-contract-foundation-specification.md §S21.15). An already-ended
        // relationship reuses EmploymentRelationshipAlreadyEndedException (S09, 409); a relationship
        // end that would leave a contract period starting beyond it reuses S09's own
        // InvalidEndDateException (422) — both already mapped above.
        $exceptions->render(fn (EmploymentRelationshipNotContractSchemeException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (InvalidEmploymentContractTypeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['contract_type_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidEmploymentContractPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidEmploymentContractTermException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['contractual_effective_to' => [$e->getMessage()]],
        ], 422));

        // S22 HumanResources-module domain failures
        // (docs/employment-job-title-history-foundation-specification.md §S22.15). An already-ended
        // relationship reuses EmploymentRelationshipAlreadyEndedException (S09, 409); a relationship
        // end incompatible with a job title period reuses S09's InvalidEndDateException (422).
        $exceptions->render(fn (InvalidEmploymentJobTitleException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['job_title_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidEmploymentJobTitlePeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S26 HumanResources-module domain failures
        // (docs/employee-specialty-history-foundation-specification.md §S26.15) — same shapes as S22.
        $exceptions->render(fn (InvalidEmploymentSpecialtyException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['specialty_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidEmploymentSpecialtyPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S29 HumanResources-module domain failures
        // (docs/work-schedule-foundation-specification.md §S29.14) — same shapes as S26.
        $exceptions->render(fn (InvalidWorkScheduleWeekdaysException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['weekdays' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidWorkSchedulePeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_from' => [$e->getMessage()]],
        ], 422));

        // S30 HumanResources-module domain failures
        // (docs/partial-secondment-foundation-specification.md §S30.20) — same shapes as S12/S16/S29.
        $exceptions->render(fn (InvalidPartialSecondmentWeekdaysException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['weekdays' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (PartialSecondmentOutsideWorkScheduleException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['weekdays' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (WorkScheduleChangeInvalidatesPartialSecondmentException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['weekdays' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidPartialSecondmentPeriodDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidPartialSecondmentEndDateException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['effective_to' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (PartialSecondmentWeekdayConflictException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (ActivePartialSecondmentExistsException $e) => response()->json(['message' => $e->getMessage()], 409));

        // S24 HumanResources-module domain failures
        // (docs/person-profile-foundation-specification.md §S24.15).
        $exceptions->render(fn (InvalidPersonProfileException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (PersonStaleVersionException $e) => response()->json(['message' => $e->getMessage()], 409));

        // S23 HumanResources-module domain failures
        // (docs/person-qualification-foundation-specification.md §S23.14).
        $exceptions->render(fn (DuplicatePersonQualificationException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (InvalidPersonQualificationAcademicDegreeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['academic_degree_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (InvalidPersonQualificationTypeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['qualification_type_id' => [$e->getMessage()]],
        ], 422));
        $exceptions->render(fn (PersonQualificationIdentityMissingException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['academic_degree_id' => [$e->getMessage()], 'qualification_type_id' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (DuplicateUsernameException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['username' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (DuplicateRoleCodeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['code' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (IncorrectCurrentPasswordException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['current_password' => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (InvalidPasswordException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => ['password' => $e->violations],
        ], 422));
    })->create();
