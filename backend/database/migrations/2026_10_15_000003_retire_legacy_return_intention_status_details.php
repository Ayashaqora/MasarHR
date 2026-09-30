<?php

use App\Modules\HumanResources\Domain\RetiredEmploymentStatusCodes;
use App\Modules\HumanResources\Infrastructure\Persistence\Postgres\LegacyReturnIntentionStatusGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S34 (M0): retires the two legacy Return Intention codes from NEW employment-status use. The
 * catalog rows are never deleted (RESTRICT FK, historical reads); they are only marked inactive
 * here, and the code-level guard in RecordEmploymentStatusPeriod is the authoritative block (an
 * administrator can reactivate a row, the guard still rejects it). Behavior rows are left in place.
 *
 * The fail-closed guard is repeated FIRST, before any data step: with any legacy status period
 * present this migration fails and changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyReturnIntentionStatusGuard::assertNoLegacyStatusPeriods();

        DB::table('ref.employment_status_details')
            ->whereIn('code', RetiredEmploymentStatusCodes::CODES)
            ->update(['is_active' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('ref.employment_status_details')
            ->whereIn('code', RetiredEmploymentStatusCodes::CODES)
            ->update(['is_active' => true, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
};
