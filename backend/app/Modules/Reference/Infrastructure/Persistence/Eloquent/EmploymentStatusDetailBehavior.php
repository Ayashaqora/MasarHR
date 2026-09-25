<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Effective-dated behavior period for one employment status detail (S05 §11/§12). Append-only:
 * no update/deactivate is ever exposed. `updated_at` does not exist on this table on purpose —
 * a row, once inserted, never changes.
 */
#[Fillable(['status_detail_id', 'effective_from', 'effective_to', 'participates_in_active_workforce', 'is_ongoing_relationship', 'is_relationship_ending', 'is_terminal', 'allows_reappointment', 'counts_in_monthly_reporting'])]
class EmploymentStatusDetailBehavior extends Model
{
    protected $table = 'ref.employment_status_detail_behaviors';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $value): void {
            if (! $value->getKey()) {
                $value->{$value->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'participates_in_active_workforce' => 'boolean',
            'is_ongoing_relationship' => 'boolean',
            'is_relationship_ending' => 'boolean',
            'is_terminal' => 'boolean',
            'allows_reappointment' => 'boolean',
            'counts_in_monthly_reporting' => 'boolean',
        ];
    }

    public function statusDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatusDetail::class, 'status_detail_id');
    }
}
