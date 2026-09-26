<?php

namespace Tests\Feature\Security;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S08 schema coverage (spec §10): the kind/unit pairing CHECK constraint and the two partial
 * unique indexes are proven directly at the database, the same way S07 proved its own self-parent
 * CHECK constraint directly rather than only through the API.
 */
class OrganizationalScopeMigrationTest extends OrganizationalScopeTestCase
{
    public function test_a_unit_grant_with_no_unit_is_rejected_by_the_check_constraint(): void
    {
        $principal = $this->createPrincipal();

        $error = $this->databaseError(fn () => DB::table('security.organizational_scope_grants')->insert([
            'id' => (string) Str::uuid7(),
            'principal_id' => $principal->id,
            'scope_kind' => 'UNIT',
            'organizational_unit_id' => null,
            'granted_at' => now(),
        ]));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_a_global_grant_with_a_unit_is_rejected_by_the_check_constraint(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();

        $error = $this->databaseError(fn () => DB::table('security.organizational_scope_grants')->insert([
            'id' => (string) Str::uuid7(),
            'principal_id' => $principal->id,
            'scope_kind' => 'GLOBAL',
            'organizational_unit_id' => $unit->id,
            'granted_at' => now(),
        ]));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_an_invalid_scope_kind_is_rejected_by_the_check_constraint(): void
    {
        $principal = $this->createPrincipal();

        $error = $this->databaseError(fn () => DB::table('security.organizational_scope_grants')->insert([
            'id' => (string) Str::uuid7(),
            'principal_id' => $principal->id,
            'scope_kind' => 'BRANCH',
            'organizational_unit_id' => null,
            'granted_at' => now(),
        ]));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_a_second_global_grant_for_the_same_principal_is_rejected_by_the_partial_unique_index(): void
    {
        $principal = $this->createPrincipal();
        $this->grantGlobalScope($principal);

        $error = $this->databaseError(fn () => DB::table('security.organizational_scope_grants')->insert([
            'id' => (string) Str::uuid7(),
            'principal_id' => $principal->id,
            'scope_kind' => 'GLOBAL',
            'organizational_unit_id' => null,
            'granted_at' => now(),
        ]));

        $this->assertTrue(Errors::isUniqueViolation($error));
    }

    public function test_a_second_unit_grant_on_the_same_unit_for_the_same_principal_is_rejected_by_the_partial_unique_index(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();
        $this->grantUnitScope($principal, $unit);

        $error = $this->databaseError(fn () => DB::table('security.organizational_scope_grants')->insert([
            'id' => (string) Str::uuid7(),
            'principal_id' => $principal->id,
            'scope_kind' => 'UNIT',
            'organizational_unit_id' => $unit->id,
            'granted_at' => now(),
        ]));

        $this->assertTrue(Errors::isUniqueViolation($error));
    }

    public function test_a_global_grant_and_a_unit_grant_coexist_for_the_same_principal_without_conflict(): void
    {
        $principal = $this->createPrincipal();
        $unit = $this->createUnit();

        $this->grantGlobalScope($principal);
        $this->grantUnitScope($principal, $unit);

        $this->assertSame(2, DB::table('security.organizational_scope_grants')->where('principal_id', $principal->id)->count());
    }

    public function test_unit_grants_on_different_units_for_the_same_principal_coexist(): void
    {
        $principal = $this->createPrincipal();
        $unitA = $this->createUnit('A');
        $unitB = $this->createUnit('B');

        $this->grantUnitScope($principal, $unitA);
        $this->grantUnitScope($principal, $unitB);

        $this->assertSame(2, DB::table('security.organizational_scope_grants')->where('principal_id', $principal->id)->count());
    }

    public function test_the_same_unit_can_be_granted_to_two_different_principals(): void
    {
        $unit = $this->createUnit();
        $principalA = $this->createPrincipal();
        $principalB = $this->createPrincipal();

        $this->grantUnitScope($principalA, $unit);
        $this->grantUnitScope($principalB, $unit);

        $this->assertSame(1, DB::table('security.organizational_scope_grants')->where('principal_id', $principalA->id)->count());
        $this->assertSame(1, DB::table('security.organizational_scope_grants')->where('principal_id', $principalB->id)->count());
    }
}
