<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the deterministic source-value aliases authorized by S05 CORRECTIVE-01 §6. Each row
 * covers one or more raw source spellings that normalize (via ArabicLookupNormalizer — bare-Alef
 * unification only) to the same lookup key; where two authorized spellings collapse to the same
 * normalized key for the same canonical status (e.g. "أعزب" and "اعزب" both normalize to "اعزب"),
 * only the minimum non-duplicated row is stored, per §6 — the alias_ar column keeps one
 * representative raw spelling, never a synthetic or merged value.
 *
 * Coverage (raw source spelling(s) → normalized key), all resolving to the row's canonical
 * marital_status code:
 *   single:   أعزب, اعزب   → اعزب   |   انسة, آنسة   → انسة
 *   married:  متزوج        → متزوج  |   متزوجة       → متزوجة
 *   divorced: مطلق         → مطلق   |   مطلقة        → مطلقة
 *   widowed:  أرمل, ارمل   → ارمل   |   أرملة, ارملة → ارملة
 *
 * Seeded as a migration, not a seeder — same rationale as
 * 2026_09_26_000018_seed_ref_baseline_values.php.
 */
return new class extends Migration
{
    /** @return list<array{code: string, alias_ar: string, normalized_alias: string}> */
    private function aliases(): array
    {
        return [
            ['code' => 'single', 'alias_ar' => 'أعزب', 'normalized_alias' => 'اعزب'],
            ['code' => 'single', 'alias_ar' => 'انسة', 'normalized_alias' => 'انسة'],

            ['code' => 'married', 'alias_ar' => 'متزوج', 'normalized_alias' => 'متزوج'],
            ['code' => 'married', 'alias_ar' => 'متزوجة', 'normalized_alias' => 'متزوجة'],

            ['code' => 'divorced', 'alias_ar' => 'مطلق', 'normalized_alias' => 'مطلق'],
            ['code' => 'divorced', 'alias_ar' => 'مطلقة', 'normalized_alias' => 'مطلقة'],

            ['code' => 'widowed', 'alias_ar' => 'أرمل', 'normalized_alias' => 'ارمل'],
            ['code' => 'widowed', 'alias_ar' => 'أرملة', 'normalized_alias' => 'ارملة'],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->aliases() as $alias) {
            $maritalStatusId = DB::table('ref.marital_statuses')->where('code', $alias['code'])->value('id');

            DB::table('ref.marital_status_aliases')->insert([
                'id' => (string) Str::uuid7(),
                'marital_status_id' => $maritalStatusId,
                'alias_ar' => $alias['alias_ar'],
                'normalized_alias' => $alias['normalized_alias'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->aliases() as $alias) {
            DB::table('ref.marital_status_aliases')->where('normalized_alias', $alias['normalized_alias'])->delete();
        }
    }
};
