<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ref.weekdays — S29's structural weekday reference (docs/work-schedule-foundation-specification.md
 * §S29.5, ADR-S29-002). Exactly seven rows, seeded here, identified by the stable machine keys
 * MONDAY…SUNDAY. Arabic/English labels are display-only; business logic never uses them, never
 * hard-codes a working week (no Sunday–Thursday, no Friday/Saturday weekend), and no
 * HOLIDAY/WEEKEND/OTHER/UNKNOWN value exists — UNKNOWN is the absence of a Work Schedule.
 *
 * Structural, not administered: no is_active / version / administration route. The code CHECK
 * pins the set to the seven identities, iso_day_number (ISO-8601, Monday = 1) is display ordering
 * only, and the RESTRICT FK from hr.work_schedule_period_weekdays makes a referenced weekday
 * undeletable, so historical schedules can never be corrupted.
 */
return new class extends Migration
{
    /** @return list<array{code: string, iso: int, name_ar: string, name_en: string}> */
    private function weekdays(): array
    {
        return [
            ['code' => 'MONDAY', 'iso' => 1, 'name_ar' => 'الاثنين', 'name_en' => 'Monday'],
            ['code' => 'TUESDAY', 'iso' => 2, 'name_ar' => 'الثلاثاء', 'name_en' => 'Tuesday'],
            ['code' => 'WEDNESDAY', 'iso' => 3, 'name_ar' => 'الأربعاء', 'name_en' => 'Wednesday'],
            ['code' => 'THURSDAY', 'iso' => 4, 'name_ar' => 'الخميس', 'name_en' => 'Thursday'],
            ['code' => 'FRIDAY', 'iso' => 5, 'name_ar' => 'الجمعة', 'name_en' => 'Friday'],
            ['code' => 'SATURDAY', 'iso' => 6, 'name_ar' => 'السبت', 'name_en' => 'Saturday'],
            ['code' => 'SUNDAY', 'iso' => 7, 'name_ar' => 'الأحد', 'name_en' => 'Sunday'],
        ];
    }

    public function up(): void
    {
        Schema::create('ref.weekdays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 16);
            $table->smallInteger('iso_day_number');
            $table->string('name_ar');
            $table->string('name_en');
            $table->timestampTz('created_at');

            $table->unique('code', 'weekdays_code_unique');
            $table->unique('iso_day_number', 'weekdays_iso_day_number_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."weekdays"
                ADD CONSTRAINT "weekdays_code_check"
                CHECK ("code" IN ('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE "ref"."weekdays"
                ADD CONSTRAINT "weekdays_iso_day_number_check"
                CHECK ("iso_day_number" BETWEEN 1 AND 7)
            SQL);

        $now = now();
        foreach ($this->weekdays() as $day) {
            DB::table('ref.weekdays')->insert([
                'id' => (string) Str::uuid7(),
                'code' => $day['code'],
                'iso_day_number' => $day['iso'],
                'name_ar' => $day['name_ar'],
                'name_en' => $day['name_en'],
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ref.weekdays');
    }
};
