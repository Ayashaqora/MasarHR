<?php

namespace Tests\Feature\Organization;

use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * S07 hierarchy coverage (spec §39): query correctness across a multi-level tree, self-parent and
 * cycle rejection (direct + indirect + under-own-direct-child), and standardized error shapes.
 */
class OrganizationalUnitHierarchyTest extends OrganizationTestCase
{
    /**
     * These routes return a plain, non-paginated AnonymousResourceCollection — with
     * JsonResource::withoutWrapping() globally set (AppServiceProvider), that response body is a
     * bare JSON array with no top-level "data" key (unlike index()'s paginated response, which
     * PaginatedResourceResponse always wraps in "data" regardless of withoutWrapping()). Also
     * sidesteps TestResponse::json() not supporting the '*' wildcard Arr::get()/assertJsonPath() do.
     */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json(), 'id');
    }

    public function test_roots_lists_only_units_with_no_parent(): void
    {
        $this->actingAsOrganizationManager();
        $root1 = $this->createUnit('Root One');
        $root2 = $this->createUnit('Root Two');
        $child = $this->createUnit('Child', $root1->id);

        $ids = $this->ids($this->getJson('/api/v1/organization/units/roots')->assertOk());

        $this->assertContains($root1->id, $ids);
        $this->assertContains($root2->id, $ids);
        $this->assertNotContains($child->id, $ids);
    }

    public function test_children_lists_only_direct_children(): void
    {
        $this->actingAsOrganizationManager();
        $root = $this->createUnit('Root');
        $child = $this->createUnit('Child', $root->id);
        $grandchild = $this->createUnit('Grandchild', $child->id);

        $ids = $this->ids($this->getJson("/api/v1/organization/units/{$root->id}/children")->assertOk());

        $this->assertSame([$child->id], $ids);
        $this->assertNotContains($grandchild->id, $ids);
    }

    public function test_ancestors_returns_the_breadcrumb_root_first(): void
    {
        $this->actingAsOrganizationManager();
        $root = $this->createUnit('Root');
        $middle = $this->createUnit('Middle', $root->id);
        $leaf = $this->createUnit('Leaf', $middle->id);

        $ids = $this->ids($this->getJson("/api/v1/organization/units/{$leaf->id}/ancestors")->assertOk());

        $this->assertSame([$root->id, $middle->id], $ids);
    }

    public function test_ancestors_of_a_root_unit_is_empty(): void
    {
        $this->actingAsOrganizationManager();
        $root = $this->createUnit('Root');

        $this->getJson("/api/v1/organization/units/{$root->id}/ancestors")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_descendants_returns_the_whole_subtree(): void
    {
        $this->actingAsOrganizationManager();
        $root = $this->createUnit('Root');
        $middle = $this->createUnit('Middle', $root->id);
        $leaf = $this->createUnit('Leaf', $middle->id);
        $unrelated = $this->createUnit('Unrelated');

        $ids = $this->ids($this->getJson("/api/v1/organization/units/{$root->id}/descendants")->assertOk());

        $this->assertContains($middle->id, $ids);
        $this->assertContains($leaf->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertNotContains($root->id, $ids);
    }

    public function test_move_to_a_new_parent_succeeds_and_is_audited(): void
    {
        $this->actingAsOrganizationManager();
        $oldParent = $this->createUnit('Old Parent');
        $newParent = $this->createUnit('New Parent');
        $unit = $this->createUnit('Movable', $oldParent->id);

        $response = $this->postJson("/api/v1/organization/units/{$unit->id}/move", [
            'parent_id' => $newParent->id,
            'expected_version' => 1,
        ])->assertOk();

        $response->assertJsonPath('parent_id', $newParent->id)->assertJsonPath('version', 2);
        $this->assertNotNull($this->latestAuditEntryFor('organization.unit.move'));
    }

    public function test_move_to_root_by_passing_a_null_parent_is_allowed(): void
    {
        $this->actingAsOrganizationManager();
        $parent = $this->createUnit('Parent');
        $unit = $this->createUnit('Child', $parent->id);

        $this->postJson("/api/v1/organization/units/{$unit->id}/move", [
            'parent_id' => null,
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('parent_id', null);
    }

    public function test_move_with_a_stale_version_is_rejected(): void
    {
        $this->actingAsOrganizationManager();
        $unit = $this->createUnit();
        $newParent = $this->createUnit('New Parent');

        $this->postJson("/api/v1/organization/units/{$unit->id}/move", [
            'parent_id' => $newParent->id,
            'expected_version' => 999,
        ])->assertStatus(409);
    }

    public function test_move_a_unit_under_itself_is_rejected_by_the_api(): void
    {
        $this->actingAsOrganizationManager();
        $unit = $this->createUnit();

        $this->postJson("/api/v1/organization/units/{$unit->id}/move", [
            'parent_id' => $unit->id,
            'expected_version' => 1,
        ])->assertStatus(409);
    }

    public function test_self_parenting_is_rejected_at_the_database_by_the_check_constraint(): void
    {
        // Spec §13 layer 1: the CHECK constraint is the always-on backstop regardless of any
        // application-level bug or bypass.
        $unit = $this->createUnit();

        $error = $this->databaseError(fn () => DB::statement(
            'update org.organizational_units set parent_id = id where id = ?',
            [$unit->id],
        ));

        $this->assertTrue(Errors::isCheckViolation($error));
    }

    public function test_direct_cycle_is_rejected(): void
    {
        // A -> B, then attempt B -> A.
        $this->actingAsOrganizationManager();
        $a = $this->createUnit('A');
        $b = $this->createUnit('B', $a->id);

        $this->postJson("/api/v1/organization/units/{$a->id}/move", [
            'parent_id' => $b->id,
            'expected_version' => 1,
        ])->assertStatus(409);

        // The tree is unchanged after the rejected move.
        $this->assertSame($a->id, $b->refresh()->parent_id);
        $this->assertNull($a->refresh()->parent_id);
    }

    public function test_indirect_cycle_is_rejected(): void
    {
        // A -> B -> C, then attempt to move A under C.
        $this->actingAsOrganizationManager();
        $a = $this->createUnit('A');
        $b = $this->createUnit('B', $a->id);
        $c = $this->createUnit('C', $b->id);

        $this->postJson("/api/v1/organization/units/{$a->id}/move", [
            'parent_id' => $c->id,
            'expected_version' => 1,
        ])->assertStatus(409);

        $this->assertNull($a->refresh()->parent_id);
    }

    public function test_moving_a_unit_under_its_own_direct_child_is_rejected(): void
    {
        $this->actingAsOrganizationManager();
        $parent = $this->createUnit('Parent');
        $child = $this->createUnit('Child', $parent->id);

        $this->postJson("/api/v1/organization/units/{$parent->id}/move", [
            'parent_id' => $child->id,
            'expected_version' => 1,
        ])->assertStatus(409);
    }

    public function test_move_with_a_nonexistent_new_parent_is_rejected(): void
    {
        $this->actingAsOrganizationManager();
        $unit = $this->createUnit();

        $this->postJson("/api/v1/organization/units/{$unit->id}/move", [
            'parent_id' => '00000000-0000-0000-0000-000000000000',
            'expected_version' => 1,
        ])->assertStatus(404);
    }

    public function test_reads_are_rejected_without_authentication(): void
    {
        $this->getJson('/api/v1/organization/units')->assertStatus(401);
    }

    public function test_reads_are_rejected_without_view_permission_for_an_authenticated_principal_with_no_role(): void
    {
        $principal = $this->createPrincipal();
        $this->actingAs($principal, 'web');

        $this->getJson('/api/v1/organization/units')->assertStatus(403);
    }
}
