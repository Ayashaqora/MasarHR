<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * S29's Work Schedule period (docs/work-schedule-foundation-specification.md §S29.6, ADR-S29-001/004):
 * a temporal child of the EmploymentRelationship aggregate — never of Person — naming the weekdays
 * that relationship is scheduled to work during [effective_from, effective_to). Append-only — no
 * `updated_at`. Identity, effective_from and weekday membership never change after insert; the only
 * later mutation is TEMPORAL CLOSURE of an open period's effective_to (by RecordWorkSchedulePeriod
 * when the next schedule takes effect, or by EndEmploymentRelationship). Not attendance, not hours.
 */
#[Fillable(['employment_relationship_id', 'effective_from', 'effective_to'])]
class WorkSchedulePeriod extends Model
{
    protected $table = 'hr.work_schedule_periods';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $period): void {
            if (! $period->getKey()) {
                $period->{$period->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employmentRelationship(): BelongsTo
    {
        return $this->belongsTo(EmploymentRelationship::class, 'employment_relationship_id');
    }

    /** The selected weekdays, in ISO order (Monday first) — display ordering only. */
    public function weekdays(): BelongsToMany
    {
        return $this->belongsToMany(Weekday::class, 'hr.work_schedule_period_weekdays', 'work_schedule_period_id', 'weekday_id')
            ->orderBy('iso_day_number');
    }

    /** @return list<string> stable weekday codes, ISO order */
    public function weekdayCodes(): array
    {
        return $this->weekdays->pluck('code')->values()->all();
    }
}
