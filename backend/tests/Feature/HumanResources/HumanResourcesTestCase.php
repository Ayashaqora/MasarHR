<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Application\Commands\RecordOrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\OrganizationalPlacementPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Gender;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use App\Modules\Security\Application\Commands\GrantOrganizationalScope;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Base class for S09 Person & Employment Foundation, S10 Employment Status History, S11
 * Organizational Placement, and S12 Full Secondment feature tests. Inherits AuditTestCase's
 * fixture and audit-read helpers (createPrincipal/createRoleWithPermissions/assignRole/
 * latestAuditEntryFor/...), mirroring OrganizationalScopeTestCase's shape exactly (S09 spec §23,
 * reused unmodified by S10/S11/S12).
 */
abstract class HumanResourcesTestCase extends AuditTestCase
{
    /**
     * A principal with every HR permission (S09–S12) via a fresh active role, plus GLOBAL S08
     * scope — a full HR administrator, authorized everywhere for every HR permission including
     * S11's/S12's scope-gated ones (docs/full-secondment-foundation-specification.md §12).
     */
    protected function actingAsHrAdministrator()
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions(HumanResourcesPermissionCatalog::ALL);
        $this->assignRole($principal, $role);
        $this->grantGlobalScope($principal);
        $this->actingAs($principal, 'web');

        return $principal;
    }

    protected function grantGlobalScope(Principal $principal): void
    {
        app(GrantOrganizationalScope::class)->handle($principal, 'GLOBAL', null, null);
    }

    protected function grantUnitScope(Principal $principal, OrganizationalUnit $unit): void
    {
        app(GrantOrganizationalScope::class)->handle($principal, 'UNIT', $unit, null);
    }

    protected function createUnit(?string $name = null, ?string $parentId = null): OrganizationalUnit
    {
        $unit = new OrganizationalUnit([
            'name' => $name ?? 'Unit '.Str::random(8),
            'parent_id' => $parentId,
            'is_active' => true,
        ]);
        $unit->save();

        return $unit->refresh();
    }

    protected function uniqueNationalId(): string
    {
        return (string) random_int(1_000_000_000, 9_999_999_999);
    }

    /**
     * Creates a NEW Person through CreatePerson. Since S24 every new Person requires a profile
     * (docs/person-profile-foundation-specification.md §S24.6), so this supplies synthetic,
     * obviously-test values using the two S05-seeded reference catalogs.
     */
    protected function createPersonRecord(?string $nationalId = null): Person
    {
        return app(CreatePerson::class)->handle(
            $nationalId ?? $this->uniqueNationalId(),
            'موظف اختبار',
            $this->gender('male'),
            $this->maritalStatus('single'),
            '1990-05-17',
        );
    }

    /** A synthetic API payload for POST /api/v1/hr/persons (S09 + the S24 required profile). */
    protected function personPayload(?string $nationalId = null, array $overrides = []): array
    {
        return [
            'national_id' => $nationalId ?? $this->uniqueNationalId(),
            'full_name_ar' => 'موظف اختبار',
            'gender_id' => $this->gender('male')->id,
            'marital_status_id' => $this->maritalStatus('single')->id,
            'birth_date' => '1990-05-17',
            ...$overrides,
        ];
    }

    /** One of the two S05-seeded ref.genders rows (male/female), by code. */
    protected function gender(string $code): Gender
    {
        return Gender::query()->where('code', $code)->firstOrFail();
    }

    /** One of the four S05-seeded ref.marital_statuses rows, by code. */
    protected function maritalStatus(string $code): MaritalStatus
    {
        return MaritalStatus::query()->where('code', $code)->firstOrFail();
    }

    protected function employmentType(string $code): EmploymentType
    {
        return EmploymentType::query()->where('code', $code)->firstOrFail();
    }

    /** One of the 13 S06-seeded employment status details (spec S10 §6), by its code. */
    protected function statusDetail(string $code): EmploymentStatusDetail
    {
        return EmploymentStatusDetail::query()->where('code', $code)->firstOrFail();
    }

    protected function createEmploymentRelationship(
        Person $person,
        string $typeCode = 'permanent',
        ?string $employeeNumber = null,
        ?string $effectiveFrom = null,
    ): EmploymentRelationship {
        $employmentType = $this->employmentType($typeCode);

        if ($typeCode === 'permanent' && $employeeNumber === null) {
            $employeeNumber = 'PN-'.Str::upper(Str::random(10));
        }

        return app(CreateEmploymentRelationship::class)->handle(
            $person,
            $employmentType,
            $effectiveFrom ?? '2026-01-01',
            $employeeNumber,
        );
    }

    /** Records an S11 placement period directly — the "source" unit S12's dual-scope rule checks against. */
    protected function recordPlacement(
        EmploymentRelationship $relationship,
        OrganizationalUnit $unit,
        string $effectiveFrom,
    ): OrganizationalPlacementPeriod {
        return app(RecordOrganizationalPlacementPeriod::class)->handle($relationship, $unit, $effectiveFrom);
    }

    /**
     * The single S14 authoritative decision type seeded by ADR-S14-002
     * (2026_10_04_000001_seed_ref_decision_types_transfer) — code TRANSFER, name_ar نقل, active.
     */
    protected function transferDecisionType(): DecisionType
    {
        return DecisionType::query()->where('code', 'TRANSFER')->firstOrFail();
    }

    /**
     * The single S16 authoritative decision type seeded by ADR-S16-001 §14
     * (2026_10_05_000002_seed_ref_decision_types_assignment) — code ASSIGNMENT, name_ar تكليف,
     * active.
     */
    protected function assignmentDecisionType(): DecisionType
    {
        return DecisionType::query()->where('code', 'ASSIGNMENT')->firstOrFail();
    }

    /**
     * One of the seven S13-seeded ref.employment_categories grades
     * (2026_10_03_000001_seed_ref_employment_categories_grades) by its stable code — S20's
     * Employment Category History reuses this existing catalog, never a new one.
     */
    protected function employmentCategory(string $code): EmploymentCategory
    {
        return EmploymentCategory::query()->where('code', $code)->firstOrFail();
    }

    /**
     * A synthetic, test-only ref.employment_categories row (rolled back with the test's own
     * transaction) — used where a test must deactivate a category without touching the seeded
     * grades' state.
     */
    /**
     * A synthetic, test-only ref.contract_types row (rolled back with the test's own transaction).
     * ref.contract_types has NO seeded content — S13 deliberately refused to invent contract-type
     * values — so every S21 test supplies its own synthetic type rather than relying on any.
     */
    protected function createSyntheticContractType(bool $active = true): ContractType
    {
        $type = new ContractType([
            'code' => 's21_test_'.Str::lower(Str::random(8)),
            'name_ar' => 'نوع عقد اختبار',
            'name_en' => null,
            'display_order' => 99,
            'is_active' => $active,
        ]);
        $type->save();

        return $type->refresh();
    }

    /**
     * A synthetic, test-only ref.job_titles row (rolled back with the test's own transaction).
     * ref.job_titles has NO seeded content — S13 was explicitly told not to fabricate or
     * reconstruct a job-title list — so every S22 test supplies its own synthetic title.
     */
    protected function createSyntheticJobTitle(bool $active = true): JobTitle
    {
        $title = new JobTitle([
            'code' => 's22_test_'.Str::lower(Str::random(8)),
            'name_ar' => 'مسمى اختبار',
            'name_en' => null,
            'display_order' => 99,
            'is_active' => $active,
        ]);
        $title->save();

        return $title->refresh();
    }

    /**
     * Synthetic, test-only ref.academic_degrees / ref.qualification_types rows (rolled back with the
     * test's own transaction). Both catalogs are deliberately EMPTY (S13; ADR-S23-DECISIONS §6), so
     * every S23 test supplies its own values and nothing is seeded.
     */
    protected function createSyntheticAcademicDegree(bool $active = true): AcademicDegree
    {
        $degree = new AcademicDegree([
            'code' => 's23_degree_'.Str::lower(Str::random(8)),
            'name_ar' => 'درجة اختبار',
            'name_en' => null,
            'display_order' => 99,
            'is_active' => $active,
        ]);
        $degree->save();

        return $degree->refresh();
    }

    protected function createSyntheticQualificationType(bool $active = true): QualificationType
    {
        $type = new QualificationType([
            'code' => 's23_type_'.Str::lower(Str::random(8)),
            'name_ar' => 'نوع مؤهل اختبار',
            'name_en' => null,
            'display_order' => 99,
            'is_active' => $active,
        ]);
        $type->save();

        return $type->refresh();
    }

    protected function createSyntheticEmploymentCategory(bool $active = true): EmploymentCategory
    {
        $category = new EmploymentCategory([
            'code' => 's20_test_'.Str::lower(Str::random(8)),
            'name_ar' => 'فئة اختبار',
            'name_en' => null,
            'display_order' => 99,
            'is_active' => $active,
        ]);
        $category->save();

        return $category->refresh();
    }
}
