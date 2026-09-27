<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the twelve Monthly Human Cadre categories given directly and explicitly by S06-AUTH-001
 * §4.3 Report 1 (S06 spec §12.1). Arabic labels are this specification's own direct translation of
 * those literal English terms — presentation only, never a business-identity source (spec §13).
 * "إدارة" here is deliberately distinct from Report 2's "الإداريين" (see spec §17/§34): they
 * classify by different dimensions (a cadre grouping vs. a per-job-title administrator flag) and
 * must not be conflated.
 */
return new class extends Migration
{
    /** @return list<array{code: string, name_ar: string, name_en: string, display_order: int}> */
    private function rows(): array
    {
        return [
            ['code' => 'doctors', 'name_ar' => 'أطباء', 'name_en' => 'Doctors', 'display_order' => 1],
            ['code' => 'nursing', 'name_ar' => 'تمريض', 'name_en' => 'Nursing', 'display_order' => 2],
            ['code' => 'administration', 'name_ar' => 'إدارة', 'name_en' => 'Administration', 'display_order' => 3],
            ['code' => 'laboratory', 'name_ar' => 'مختبرات', 'name_en' => 'Laboratory', 'display_order' => 4],
            ['code' => 'pharmacy', 'name_ar' => 'صيدلة', 'name_en' => 'Pharmacy', 'display_order' => 5],
            ['code' => 'radiology', 'name_ar' => 'أشعة', 'name_en' => 'Radiology', 'display_order' => 6],
            ['code' => 'physical_therapy', 'name_ar' => 'علاج طبيعي', 'name_en' => 'Physical Therapy', 'display_order' => 7],
            ['code' => 'anesthesia', 'name_ar' => 'تخدير', 'name_en' => 'Anesthesia', 'display_order' => 8],
            ['code' => 'other_health_professions', 'name_ar' => 'مهن صحية أخرى', 'name_en' => 'Other Health Professions', 'display_order' => 9],
            ['code' => 'engineering', 'name_ar' => 'هندسة', 'name_en' => 'Engineering', 'display_order' => 10],
            ['code' => 'services', 'name_ar' => 'خدمات', 'name_en' => 'Services', 'display_order' => 11],
            ['code' => 'crafts', 'name_ar' => 'حرف', 'name_en' => 'Crafts', 'display_order' => 12],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->rows() as $row) {
            DB::table('ref.monthly_cadre_categories')->insert([
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
            DB::table('ref.monthly_cadre_categories')->where('code', $row['code'])->delete();
        }
    }
};
