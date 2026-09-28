<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds exactly the seven employment-grade values the S13 authorization's "Known Authoritative
 * Business Vocabulary" section supplies for ref.employment_categories
 * (docs/reference-catalog-administration-foundation-specification.md §8.1) — the value list
 * ref.employment_categories was waiting for since S05 (DEFINED STRUCTURE / VALUES DEFERRED,
 * docs/reference-data-foundation-specification.md §5.3). No CreateEmploymentCategory command
 * existed until S13 itself added one in this same stage; this migration inserts directly rather
 * than depending on application-layer command classes from inside a migration — exactly the same
 * rationale as 2026_09_26_000018_seed_ref_baseline_values and
 * 2026_09_29_000003_seed_ref_employment_types_permanent_and_contract.
 *
 * name_ar is exactly the Arabic text the S13 authorization supplied, verbatim. name_en is left
 * null for every row: the authorization supplied no English gloss for any of the seven values, and
 * S05 §8 is explicit that no S05/S06/S13 migration or seed fabricates an English translation that
 * was not explicitly given — name_en is seeded null rather than invented, exactly mirroring how
 * 2026_09_26_000018_seed_ref_baseline_values and the employment-types seed both already handle a
 * value they are not given an English gloss for.
 *
 * No other ref.* table is touched by this migration — every other in-scope S13 catalog
 * (job_titles, contract_types, qualification_types, academic_degrees, supervisory_titles,
 * leave_types, leave_statuses) receives administration capability only, zero seeded content, per
 * spec §8/§26.
 */
return new class extends Migration
{
    /** @return list<array{code: string, name_ar: string, display_order: int}> */
    private function rows(): array
    {
        return [
            ['code' => 'grade_1', 'name_ar' => 'الأولى', 'display_order' => 1],
            ['code' => 'grade_2', 'name_ar' => 'الثانية', 'display_order' => 2],
            ['code' => 'grade_3', 'name_ar' => 'الثالثة', 'display_order' => 3],
            ['code' => 'grade_4', 'name_ar' => 'الرابعة', 'display_order' => 4],
            ['code' => 'grade_5', 'name_ar' => 'الخامسة', 'display_order' => 5],
            ['code' => 'grade_senior', 'name_ar' => 'العليا', 'display_order' => 6],
            ['code' => 'grade_old_law', 'name_ar' => 'قانون قديم', 'display_order' => 7],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->rows() as $row) {
            DB::table('ref.employment_categories')->insert([
                'id' => (string) Str::uuid7(),
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
        $codes = array_column($this->rows(), 'code');

        DB::table('ref.employment_categories')->whereIn('code', $codes)->delete();
    }
};
