<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ref.marital_status_aliases — deterministic source-value aliases for MaritalStatus resolution
 * (S05 CORRECTIVE-01 §3). Not a reference catalog in its own right: no code/is_active/
 * display_order/version — those belong to the canonical ref.marital_statuses row this alias
 * resolves to. See App\Modules\Reference\Application\Queries\ResolveMaritalStatusByArabicSourceValue
 * for the read contract this table serves.
 */
#[Fillable(['marital_status_id', 'alias_ar', 'normalized_alias'])]
class MaritalStatusAlias extends Model
{
    protected $table = 'ref.marital_status_aliases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $value): void {
            if (! $value->getKey()) {
                $value->{$value->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    public function maritalStatus(): BelongsTo
    {
        return $this->belongsTo(MaritalStatus::class, 'marital_status_id');
    }
}
