<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentStatusDetail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S10's Employment Status History (spec §5): a temporal child of the EmploymentRelationship
 * aggregate, never its own aggregate root. Append-only — no `updated_at` column exists on
 * purpose, exactly mirroring App\Modules\Reference\...\EmploymentStatusDetailBehavior (S06): a
 * row, once inserted, is never itself updated; the only "mutation" any later insert ever causes
 * is setting `effective_to` on the previously-inserted open row, done by
 * RecordEmploymentStatusPeriod as part of recording the next one.
 */
#[Fillable(['employment_relationship_id', 'status_detail_id', 'effective_from', 'effective_to'])]
class EmploymentStatusPeriod extends Model
{
    protected $table = 'hr.employment_status_periods';

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

    public function statusDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatusDetail::class, 'status_detail_id');
    }

    public function isCurrent(): bool
    {
        return $this->effective_to === null;
    }
}
