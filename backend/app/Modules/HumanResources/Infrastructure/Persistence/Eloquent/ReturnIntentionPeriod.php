<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S34 Return Intention history: a temporal child of the EmploymentRelationship, independent of the
 * employment-status stream. Append-only apart from closing a covering period's `effective_to`.
 */
#[Fillable(['employment_relationship_id', 'intention', 'effective_from', 'effective_to'])]
class ReturnIntentionPeriod extends Model
{
    protected $table = 'hr.return_intention_periods';

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
}
