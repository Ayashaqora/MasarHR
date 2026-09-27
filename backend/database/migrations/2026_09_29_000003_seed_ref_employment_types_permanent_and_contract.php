<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds exactly the two employment-form values ADR-S09-001 §7 supplies as authoritative — the
 * value list ref.employment_types was waiting for since S05 (DEFINED STRUCTURE / VALUES DEFERRED,
 * docs/reference-data-foundation-specification.md §5.3). No CreateEmploymentType command exists
 * (S05 shipped no command for any of the ten deferred families), so — exactly like S05's own
 * 2026_09_26_000018_seed_ref_baseline_values — this migration inserts directly.
 *
 * ref.contract_types and ref.employment_categories are deliberately NOT touched here: neither is
 * evidenced anywhere as the PERMANENT/CONTRACT concept (spec §7).
 */
return new class extends Migration
{
    /** @return list<array{code: string, name_ar: string, name_en: string, display_order: int}> */
    private function rows(): array
    {
        return [
            ['code' => 'permanent', 'name_ar' => 'دائم', 'name_en' => 'Permanent', 'display_order' => 1],
            ['code' => 'contract', 'name_ar' => 'تعاقد', 'name_en' => 'Contract', 'display_order' => 2],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->rows() as $row) {
            DB::table('ref.employment_types')->insert([
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
        $codes = array_column($this->rows(), 'code');

        DB::table('ref.employment_types')->whereIn('code', $codes)->delete();
    }
};
