<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the 13 employment-status-detail values given directly and explicitly by S06-AUTH-001
 * §4.1 (S06 spec §11), each attached to its named parent category (already seeded by S05's
 * 2026_09_26_000018_seed_ref_baseline_values). This is the "future-stage work once an
 * authoritative list is confirmed" that S05 §27 left open — S06-AUTH-001 §4.1 is that
 * confirmation. Seeded as a migration, matching every prior S05/CORRECTIVE-01 seed migration
 * (module-owned reference data, not environment-specific business data).
 */
return new class extends Migration
{
    /** @return list<array{code: string, name_ar: string, category: string, display_order: int}> */
    private function rows(): array
    {
        return [
            ['code' => 'on_duty', 'name_ar' => 'على رأس عمله', 'category' => 'active', 'display_order' => 1],
            ['code' => 'wants_to_return', 'name_ar' => 'يرغب في العودة', 'category' => 'active', 'display_order' => 2],
            ['code' => 'traveling', 'name_ar' => 'مسافر', 'category' => 'non_active', 'display_order' => 3],
            ['code' => 'captive', 'name_ar' => 'أسير', 'category' => 'non_active', 'display_order' => 4],
            ['code' => 'suspended', 'name_ar' => 'إيقاف عن العمل', 'category' => 'non_active', 'display_order' => 5],
            ['code' => 'does_not_want_to_return', 'name_ar' => 'لا يرغب في العودة', 'category' => 'non_active', 'display_order' => 6],
            ['code' => 'unpaid_leave', 'name_ar' => 'إجازة بدون راتب', 'category' => 'non_active', 'display_order' => 7],
            ['code' => 'external_sick_leave', 'name_ar' => 'إجازة خارجية مرضية', 'category' => 'non_active', 'display_order' => 8],
            ['code' => 'retired', 'name_ar' => 'متقاعد', 'category' => 'ended', 'display_order' => 9],
            ['code' => 'resigned', 'name_ar' => 'استقالة', 'category' => 'ended', 'display_order' => 10],
            ['code' => 'contract_ended', 'name_ar' => 'إنهاء تعاقد', 'category' => 'ended', 'display_order' => 11],
            ['code' => 'martyred', 'name_ar' => 'شهيد', 'category' => 'terminal', 'display_order' => 12],
            ['code' => 'deceased', 'name_ar' => 'وفاة', 'category' => 'terminal', 'display_order' => 13],
        ];
    }

    public function up(): void
    {
        $now = now();

        $categoryIds = DB::table('ref.employment_status_categories')->pluck('id', 'code');

        foreach ($this->rows() as $row) {
            $categoryId = $categoryIds[$row['category']] ?? null;

            if ($categoryId === null) {
                throw new RuntimeException("S06 seed: employment_status_categories.{$row['category']} not found — S05 baseline seed must run first.");
            }

            DB::table('ref.employment_status_details')->insert([
                'id' => (string) Str::uuid7(),
                'category_id' => $categoryId,
                'code' => $row['code'],
                'name_ar' => $row['name_ar'],
                'name_en' => null,
                'is_active' => true,
                'display_order' => $row['display_order'],
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->rows() as $row) {
            DB::table('ref.employment_status_details')->where('code', $row['code'])->delete();
        }
    }
};
