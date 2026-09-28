<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds exactly one `ref.decision_types` row — ASSIGNMENT / تكليف — per ADR-S16-001 §14, which
 * explicitly permits an Architecture-Authority-assigned technical `code` while requiring the
 * Arabic value be taken verbatim from the source ("do not invent an Arabic label beyond the
 * source-supported 'تكليف'").
 *
 * Follows `2026_10_04_000001_seed_ref_decision_types_transfer.php`'s precedent exactly: direct
 * `DB::table()->insert()`, never through a command — the established convention for baseline
 * reference-value seeding in this codebase does not condition on whether a command exists.
 *
 * `code = 'ASSIGNMENT'` (uppercase) mirrors `TRANSFER`'s identical, deliberate departure from the
 * lowercase snake_case convention used elsewhere in `ref.*` — a stable machine identifier
 * Architecture Authority explicitly authorized assigning, not a free-text label this migration is
 * choosing on its own.
 *
 * `name_ar = 'تكليف'` is the authoritative Arabic business value ADR-S16-001 supplies, verbatim.
 * `name_en` is left `null`: no English gloss was given, and no migration in this codebase
 * fabricates one that was not explicitly supplied (S05 §8 precedent, reused verbatim by every
 * seed migration since).
 *
 * No assignment sub-type is seeded (ADR-S16-001 §14: "No assignment subtypes"). No other
 * `ref.decision_types` row, and no other `ref.*` table, is touched by this migration.
 */
return new class extends Migration
{
    private const CODE = 'ASSIGNMENT';

    private const NAME_AR = 'تكليف';

    public function up(): void
    {
        DB::table('ref.decision_types')->insert([
            'id' => (string) Str::uuid7(),
            'code' => self::CODE,
            'name_ar' => self::NAME_AR,
            'name_en' => null,
            'is_active' => true,
            'display_order' => 2,
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
