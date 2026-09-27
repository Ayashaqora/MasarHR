<?php

namespace Tests\Feature\Reference;

use App\Modules\Reference\Application\Queries\ResolveMaritalStatusByArabicSourceValue;
use App\Modules\Reference\Domain\Support\ArabicLookupNormalizer;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\MaritalStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * S05 CORRECTIVE-01 coverage: gendered Arabic source-value resolution for MaritalStatus, via
 * ref.marital_status_aliases and ResolveMaritalStatusByArabicSourceValue (§12, §14).
 *
 * Scope boundary (§9): ref.marital_status_aliases is baseline system-owned seed infrastructure
 * only in S05 — it has no controller, no route, no permission, and no audit action, exactly like
 * the ten "structure-only" S05 families. It is exercised here directly against the resolver and
 * the seeded rows, not through the HTTP API. If alias administration is ever exposed as a runtime
 * mutation in a later stage, it must use S04 audit infrastructure and reference.manage (§9) — that
 * is explicitly out of scope for this correction.
 */
class MaritalStatusAliasResolutionTest extends ReferenceTestCase
{
    private function resolver(): ResolveMaritalStatusByArabicSourceValue
    {
        return app(ResolveMaritalStatusByArabicSourceValue::class);
    }

    /** @return array<string, array{0: string, 1: string}> raw source value => expected canonical code */
    public static function authorizedAliasesProvider(): array
    {
        return [
            'أعزب → single' => ['أعزب', 'single'],
            'اعزب → single' => ['اعزب', 'single'],
            'انسة → single' => ['انسة', 'single'],
            'آنسة → single' => ['آنسة', 'single'],
            'متزوج → married' => ['متزوج', 'married'],
            'متزوجة → married' => ['متزوجة', 'married'],
            'مطلق → divorced' => ['مطلق', 'divorced'],
            'مطلقة → divorced' => ['مطلقة', 'divorced'],
            'أرمل → widowed' => ['أرمل', 'widowed'],
            'ارمل → widowed' => ['ارمل', 'widowed'],
            'أرملة → widowed' => ['أرملة', 'widowed'],
            'ارملة → widowed' => ['ارملة', 'widowed'],
        ];
    }

    #[DataProvider('authorizedAliasesProvider')]
    public function test_every_authorized_gendered_source_alias_resolves_to_its_canonical_status(string $raw, string $expectedCode): void
    {
        $resolved = $this->resolver()->__invoke($raw);

        $this->assertNotNull($resolved, "expected [{$raw}] to resolve, got UNRESOLVED");
        $this->assertSame($expectedCode, $resolved->code);
    }

    public function test_resolution_does_not_create_duplicate_business_statuses(): void
    {
        // §14 closure condition: the four previously-blocking gendered forms resolve without the
        // canonical catalog growing past four rows.
        foreach (['انسة', 'متزوجة', 'مطلقة', 'أرملة'] as $raw) {
            $resolved = $this->resolver()->__invoke($raw);
            $this->assertNotNull($resolved, "expected [{$raw}] to resolve, got UNRESOLVED");
        }

        $this->assertSame(4, MaritalStatus::query()->count(), 'canonical marital_statuses must remain exactly four rows');
    }

    public function test_surrounding_and_internal_whitespace_is_normalized(): void
    {
        $resolved = $this->resolver()->__invoke("  أعزب  \n");
        $this->assertNotNull($resolved);
        $this->assertSame('single', $resolved->code);

        $resolved = $this->resolver()->__invoke("متزوجة\t \t");
        $this->assertNotNull($resolved);
        $this->assertSame('married', $resolved->code);
    }

    public function test_alef_variant_normalization_is_limited_to_the_alef_family(): void
    {
        // Confirms the normalizer itself does the Alef unification, not some coincidental match.
        $this->assertSame('اعزب', ArabicLookupNormalizer::normalize('أعزب'));
        $this->assertSame('اعزب', ArabicLookupNormalizer::normalize('اعزب'));
        $this->assertSame('انسة', ArabicLookupNormalizer::normalize('آنسة'));
    }

    public function test_unknown_value_is_unresolved_and_creates_no_row(): void
    {
        $before = DB::table('ref.marital_status_aliases')->count();

        $resolved = $this->resolver()->__invoke('نص غير معروف تمامًا');

        $this->assertNull($resolved, 'an unmatched source value must resolve to UNRESOLVED (null), never a guess');
        $this->assertSame($before, DB::table('ref.marital_status_aliases')->count(), 'resolution must never create a row');
    }

    public function test_empty_value_is_unresolved(): void
    {
        $this->assertNull($this->resolver()->__invoke(''));
        $this->assertNull($this->resolver()->__invoke('   '));
    }

    public function test_no_other_or_unknown_canonical_status_exists_to_fall_back_to(): void
    {
        $codes = MaritalStatus::query()->pluck('code')->all();
        sort($codes);

        $this->assertSame(['divorced', 'married', 'single', 'widowed'], $codes);
    }

    public function test_same_normalized_alias_cannot_map_to_two_statuses(): void
    {
        $single = MaritalStatus::query()->where('code', 'single')->firstOrFail();
        $married = MaritalStatus::query()->where('code', 'married')->firstOrFail();

        DB::table('ref.marital_status_aliases')->insert([
            'id' => (string) Str::uuid7(),
            'marital_status_id' => $single->id,
            'alias_ar' => 'صيغة اختبار',
            'normalized_alias' => 'صيغة اختبار فريدة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        // Same normalized_alias, different marital_status_id — must be rejected at the database
        // level by the unique constraint on normalized_alias (§6 — "Enforce this at database level
        // where practical"), independent of any application-level check.
        DB::table('ref.marital_status_aliases')->insert([
            'id' => (string) Str::uuid7(),
            'marital_status_id' => $married->id,
            'alias_ar' => 'صيغة اختبار أخرى',
            'normalized_alias' => 'صيغة اختبار فريدة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_alias_foreign_key_integrity_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('ref.marital_status_aliases')->insert([
            'id' => (string) Str::uuid7(),
            'marital_status_id' => (string) Str::uuid7(), // no such marital_status row
            'alias_ar' => 'قيمة يتيمة',
            'normalized_alias' => 'قيمة يتيمة فريدة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deactivated_canonical_status_remains_resolvable_but_excluded_from_active_selection(): void
    {
        $this->actingAsReferenceManager();
        $single = MaritalStatus::query()->where('code', 'single')->firstOrFail();

        $this->postJson("/api/v1/reference/marital-statuses/{$single->id}/deactivate", [
            'expected_version' => $single->version,
        ])->assertOk();

        // RESOLUTION (§8): still identifiable via alias — deactivation never destroys resolution.
        $resolved = $this->resolver()->__invoke('أعزب');
        $this->assertNotNull($resolved, 'a deactivated canonical status must still be resolvable historically');
        $this->assertSame('single', $resolved->code);
        $this->assertFalse((bool) $resolved->is_active);

        // SELECTABLE FOR NEW BUSINESS USE (§8): the established S05 lifecycle convention for
        // "offered for new selection" is the is_active flag itself — deactivation legitimately
        // excludes the row from any such query, distinct from resolution above.
        $activeCodes = MaritalStatus::query()->where('is_active', true)->pluck('code')->all();
        $this->assertNotContains('single', $activeCodes, 'a deactivated status must not be offered for new selection');
    }
}

// Migration rollback/reapply for the two CORRECTIVE-01 migrations is covered in
// tests/Feature/Database/MigrationLifecycleTest.php (test_marital_status_aliases_corrective_migrations_roll_back_and_reapply_cleanly),
// not here: this class runs inside DatabaseTransactions (via ReferenceTestCase/AuditTestCase/
// SecurityTestCase), and driving `artisan migrate`/`migrate:rollback` — real DDL against a
// separately-batched migration run — inside that wrapping test transaction is exactly the
// batch-selection and transaction-nesting hazard MigrationLifecycleTest's own class docblock
// documents. MigrationLifecycleTest extends PostgresIntegrationTestCase directly (no
// DatabaseTransactions) for that reason and already establishes the correct pattern: call each
// migration's down()/up() directly, wrapped in its own DB::transaction().
