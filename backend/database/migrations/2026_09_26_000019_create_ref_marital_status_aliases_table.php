<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ref.marital_status_aliases — deterministic source-value aliases for MaritalStatus resolution
 * (S05 CORRECTIVE-01 §3, see docs/reference-data-foundation-specification.md §22a). MasarHR HR
 * source data contains gendered Arabic spellings of marital status (e.g. "انسة"/"متزوجة") that are
 * NOT independent business statuses — ref.marital_statuses keeps exactly four canonical records
 * (single/married/divorced/widowed). This table lets each such source spelling resolve
 * deterministically to its one canonical record without duplicating business states and without
 * losing the ability to recognize the source form. See
 * App\Modules\Reference\Application\Queries\ResolveMaritalStatusByArabicSourceValue for the read
 * contract, and App\Modules\Reference\Domain\Support\ArabicLookupNormalizer for the exact, limited
 * normalization rule.
 *
 * Deliberately NOT shaped like the other 16 S05 reference tables: no `code`/`is_active`/
 * `display_order`/`version` — this is not a reference catalog in its own right, it is a lookup
 * table onto one (ref.marital_statuses). The UNIQUE constraint on normalized_alias is what
 * enforces, at the database level, that a normalized alias can never resolve to two different
 * canonical statuses (S05 CORRECTIVE-01 §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref.marital_status_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('marital_status_id');
            $table->string('alias_ar');
            $table->string('normalized_alias');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique('normalized_alias', 'marital_status_aliases_normalized_alias_unique');

            $table->foreign('marital_status_id', 'marital_status_aliases_marital_status_id_foreign')
                ->references('id')->on('ref.marital_statuses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.marital_status_aliases');
    }
};
