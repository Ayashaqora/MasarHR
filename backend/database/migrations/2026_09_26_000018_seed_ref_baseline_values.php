<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds only the reference values given directly and explicitly by the S05 authorization (§22):
 * Gender, Marital Status, and the four Employment Status Category tiers (§5.4). No other family
 * is seeded here — ref.decision_types, ref.employment_status_details, and the ten structure-only
 * families intentionally receive zero rows in S05 (DEFINED STRUCTURE / VALUES DEFERRED, §8/§5.3).
 * Seeded as a migration, not a seeder, for the same reason as
 * 2026_09_23_000007_seed_security_baseline_permissions: this is reference data owned by the
 * module, not environment-specific business data.
 */
return new class extends Migration
{
    /** @return list<array{table: string, code: string, name_ar: string, name_en: string, display_order: int}> */
    private function rows(): array
    {
        return [
            ['table' => 'ref.genders', 'code' => 'male', 'name_ar' => 'ذكر', 'name_en' => 'Male', 'display_order' => 1],
            ['table' => 'ref.genders', 'code' => 'female', 'name_ar' => 'أنثى', 'name_en' => 'Female', 'display_order' => 2],

            ['table' => 'ref.marital_statuses', 'code' => 'single', 'name_ar' => 'أعزب', 'name_en' => 'Single', 'display_order' => 1],
            ['table' => 'ref.marital_statuses', 'code' => 'married', 'name_ar' => 'متزوج', 'name_en' => 'Married', 'display_order' => 2],
            ['table' => 'ref.marital_statuses', 'code' => 'divorced', 'name_ar' => 'مطلق', 'name_en' => 'Divorced', 'display_order' => 3],
            ['table' => 'ref.marital_statuses', 'code' => 'widowed', 'name_ar' => 'أرمل', 'name_en' => 'Widowed', 'display_order' => 4],

            ['table' => 'ref.employment_status_categories', 'code' => 'active', 'name_ar' => 'نشط', 'name_en' => 'Active', 'display_order' => 1],
            ['table' => 'ref.employment_status_categories', 'code' => 'non_active', 'name_ar' => 'غير نشط (قائم)', 'name_en' => 'Non-Active', 'display_order' => 2],
            ['table' => 'ref.employment_status_categories', 'code' => 'ended', 'name_ar' => 'منتهي', 'name_en' => 'Ended', 'display_order' => 3],
            ['table' => 'ref.employment_status_categories', 'code' => 'terminal', 'name_ar' => 'نهائي', 'name_en' => 'Terminal', 'display_order' => 4],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->rows() as $row) {
            DB::table($row['table'])->insert([
                'id' => (string) Str::uuid7(),
                'code' => $row['code'],
                'name_ar' => $row['name_ar'],
                'name_en' => $row['name_en'],
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
            DB::table($row['table'])->where('code', $row['code'])->delete();
        }
    }
};
