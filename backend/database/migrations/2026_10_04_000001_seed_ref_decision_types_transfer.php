<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds exactly one `ref.decision_types` row — TRANSFER / نقل — per ADR-S14-002 ("MASARHR — S14
 * BLOCKER RESOLUTION + RESUME AUTHORIZATION"), which resolves the S14 §12 decision-type discovery
 * gate documented in `docs/full-secondment-foundation-specification.md` §1.4/§7.3/§26 (Transfer's
 * own decision rule — "store ONLY نوع القرار" — was declared a formal HR decision requiring a real,
 * authoritative `ref.decision_types` value before Transfer could be implemented; none existed until
 * this migration).
 *
 * `ref.decision_types` already has a full Create/Activate/Deactivate/UpdateMetadata command set
 * (S05) — unlike `ref.employment_types` (ADR-S09-001) and `ref.employment_categories` (S13), which
 * had no command yet when their own seed migrations were written. This migration nonetheless
 * inserts directly via `DB::table()->insert()`, not through `CreateDecisionType`, because the
 * established convention for baseline reference-value seeding in this codebase does not condition
 * on whether a command exists: `2026_09_26_000018_seed_ref_baseline_values` seeded `ref.genders`
 * and `ref.marital_statuses` directly even though `CreateGender`/`CreateMaritalStatus` already
 * existed at that point, specifically so a seed migration never depends on application-layer
 * bootstrapping running inside a migration. ADR-S14-002 itself asks for "the smallest
 * architecture-compliant mechanism" and "the project's established reference-data migration/seed
 * pattern" — this is that pattern, applied identically.
 *
 * `code = 'TRANSFER'` (uppercase) is exactly the technical identifier ADR-S14-002 assigns, quoted
 * verbatim three times in that authorization. This deliberately departs from every other
 * `ref.*` `code` value in this codebase so far (`male`, `single`, `permanent`, `grade_1`, ...), all
 * lowercase `snake_case` — disclosed here, not silently normalized, because `code` is a stable
 * machine identifier Architecture Authority explicitly assigned, not a free-text label this
 * migration is choosing on its own (contrast §8.1 of the S13 specification, where `code` slugs
 * *were* this project's own invented technical necessity). No DB-level format constraint on `code`
 * exists (`ref.decision_types`' own migration only enforces `UNIQUE`), and inserting directly via
 * `DB::table()` bypasses `DecisionTypeController::store()`'s application-layer
 * `regex:/^[a-z0-9_]+$/` validation entirely — exactly as every prior direct-insert seed migration
 * in this codebase already does — so no constraint is violated by the uppercase value.
 *
 * `name_ar = 'نقل'` is the authoritative Arabic business value ADR-S14-002 supplies, verbatim.
 * `name_en` is left `null`: ADR-S14-002 supplies no English gloss, and S05 §8's binding precedent
 * (reused by every seed migration in this codebase since, including S13's employment-categories
 * seed) is that no migration fabricates an English translation that was not explicitly given.
 *
 * No Transfer sub-type is seeded (ADR-S14-002 is explicit: "No Transfer sub-types are authorized" —
 * enumerates and forbids نقل داخلي/نقل خارجي/نقل مؤقت/نقل دائم by name). No other `ref.decision_types`
 * row, and no other `ref.*` table, is touched by this migration.
 */
return new class extends Migration
{
    private const CODE = 'TRANSFER';

    private const NAME_AR = 'نقل';

    public function up(): void
    {
        DB::table('ref.decision_types')->insert([
            'id' => (string) Str::uuid7(),
            'code' => self::CODE,
            'name_ar' => self::NAME_AR,
            'name_en' => null,
            'is_active' => true,
            'display_order' => 1,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ref.decision_types')->where('code', self::CODE)->delete();
    }
};
