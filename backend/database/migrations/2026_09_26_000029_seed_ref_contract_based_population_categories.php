<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the two contract-based report population categories given directly and explicitly by
 * S06-AUTH-001 §4.3 Report 4 ("Support Services / Daily Workers") and Report 5
 * ("Volunteers / Unemployment") — S06 spec §12.4.
 */
return new class extends Migration
{
    /** @return list<array{code: string, name_ar: string, name_en: string, display_order: int}> */
    private function rows(): array
    {
        return [
            ['code' => 'support_services_daily_worker', 'name_ar' => 'خدمات مساندة وعمال باليومية', 'name_en' => 'Support Services / Daily Workers', 'display_order' => 1],
            ['code' => 'volunteer_unemployment', 'name_ar' => 'متطوعون ومتعطلون', 'name_en' => 'Volunteers / Unemployment', 'display_order' => 2],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->rows() as $row) {
            DB::table('ref.contract_based_population_categories')->insert([
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
            DB::table('ref.contract_based_population_categories')->where('code', $row['code'])->delete();
        }
    }
};
