<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Weekday;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One Partial Secondment period of an Employment Relationship
 * (docs/partial-secondment-foundation-specification.md §S30.6, ADR-S30-001): the destination unit
 * the employee works at on the allocated weekdays during [effective_from, effective_to).
 * Append-only history: no version / updated_at. `period` is a database-generated daterange used
 * only by the weekday-level exclusion constraint; it is never written by the application.
 *
 * Every update (in practice only a truncation of effective_to — supersession, transfer,
 * relationship end) re-copies the owner and the regenerated daterange onto the weekday membership
 * rows in the same transaction. The deferred composite FK
 * (partial_secondment_period_weekdays_period_fk) makes PostgreSQL reject, at commit, any
 * transaction that changes a period without that copy.
 */
class PartialSecondmentPeriod extends Model
{
    protected $table = 'hr.partial_secondment_periods';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'employment_relationship_id',
        'organizational_unit_id',
        'effective_from',
        'effective_to',
    ];

    protected $hidden = ['period'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $period): void {
            if (! $period->getKey()) {
                $period->{$period->getKeyName()} = (string) Str::uuid7();
            }
        });

        static::updated(function (self $period): void {
            DB::update(
                'update hr.partial_secondment_period_weekdays pw
                 set employment_relationship_id = p.employment_relationship_id, period = p.period
                 from hr.partial_secondment_periods p
                 where p.id = pw.partial_secondment_period_id and p.id = ?',
                [$period->getKey()],
            );
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

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    /** The allocated weekdays, in ISO order (Monday first) — display ordering only. */
    public function weekdays(): BelongsToMany
    {
        return $this->belongsToMany(Weekday::class, 'hr.partial_secondment_period_weekdays', 'partial_secondment_period_id', 'weekday_id')
            ->orderBy('iso_day_number');
    }

    /** @return list<string> stable weekday codes, ISO order */
    public function weekdayCodes(): array
    {
        return $this->weekdays->pluck('code')->values()->all();
    }
}
