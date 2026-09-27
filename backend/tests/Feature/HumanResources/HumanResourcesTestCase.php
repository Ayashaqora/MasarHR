<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use App\Modules\Security\Application\Commands\GrantOrganizationalScope;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Support\Str;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Base class for S09 Person & Employment Foundation, S10 Employment Status History, and S11
 * Organizational Placement feature tests. Inherits AuditTestCase's fixture and audit-read helpers
 * (createPrincipal/createRoleWithPermissions/assignRole/latestAuditEntryFor/...), mirroring
 * OrganizationalScopeTestCase's shape exactly (S09 spec §23, reused unmodified by S10/S11).
 */
abstract class HumanResourcesTestCase extends AuditTestCase
{
    /**
     * A principal with every HR permission (S09–S11) via a fresh active role, plus GLOBAL S08
     * scope — a full HR administrator, authorized everywhere for every HR permission including
     * S11's scope-gated ones (docs/organizational-placement-foundation-specification.md §10).
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

    protected function createPersonRecord(?string $nationalId = null): Person
    {
        return app(CreatePerson::class)->handle($nationalId ?? $this->uniqueNationalId());
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
}
