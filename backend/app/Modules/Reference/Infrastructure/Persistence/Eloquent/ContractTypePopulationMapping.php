<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Effective-dated mapping from a ContractType to its Report 4/5 population category (S06 spec
 * §12.5). Append-only, matching EmploymentStatusDetailBehavior exactly.
 */
#[Fillable(['contract_type_id', 'population_category_id', 'effective_from', 'effective_to'])]
class ContractTypePopulationMapping extends Model
{
    protected $table = 'ref.contract_type_population_mappings';

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

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    public function populationCategory(): BelongsTo
    {
        return $this->belongsTo(ContractBasedPopulationCategory::class, 'population_category_id');
    }
}
