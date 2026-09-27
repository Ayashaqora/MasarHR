<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Effective-dated mapping from a Specialty to its Monthly Human Cadre reporting category (S06
 * spec §12.2). Append-only: no update/deactivate is ever exposed, matching
 * EmploymentStatusDetailBehavior exactly. `updated_at` does not exist on this table on purpose.
 */
#[Fillable(['specialty_id', 'cadre_category_id', 'effective_from', 'effective_to'])]
class SpecialtyCadreCategoryMapping extends Model
{
    protected $table = 'ref.specialty_cadre_category_mappings';

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
        ];
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id');
    }

    public function cadreCategory(): BelongsTo
    {
        return $this->belongsTo(MonthlyCadreCategory::class, 'cadre_category_id');
    }
}
