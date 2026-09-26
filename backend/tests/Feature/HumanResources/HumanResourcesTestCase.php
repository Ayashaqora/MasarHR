<?php

namespace Tests\Feature\HumanResources;

use App\Modules\HumanResources\Application\Commands\CreateEmploymentRelationship;
use App\Modules\HumanResources\Application\Commands\CreatePerson;
use App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\Person;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use Illuminate\Support\Str;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Base class for S09 Person & Employment Foundation feature tests. Inherits AuditTestCase's
 * fixture and audit-read helpers (createPrincipal/createRoleWithPermissions/assignRole/
 * latestAuditEntryFor/...), mirroring OrganizationalScopeTestCase's shape exactly (spec §23).
 */
abstract class HumanResourcesTestCase extends AuditTestCase
{
    /** A principal with every S09 permission via a fresh active role — a full HR administrator. */
    protected function actingAsHrAdministrator()
    {
        $principal = $this->createPrincipal();
        $role = $this->createRoleWithPermissions(HumanResourcesPermissionCatalog::ALL);
        $this->assignRole($principal, $role);
        $this->actingAs($principal, 'web');

        return $principal;
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
