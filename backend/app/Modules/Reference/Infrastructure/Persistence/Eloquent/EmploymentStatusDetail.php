<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Concrete employment status values, each belonging to one EmploymentStatusCategory (S05 §5.2/§5.4). */
#[Fillable(['category_id', 'code', 'name_ar', 'name_en', 'display_order', 'is_active'])]
class EmploymentStatusDetail extends Model
{
    protected $table = 'ref.employment_status_details';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $value): void {
            if (! $value->getKey()) {
                $value->{$value->getKeyName()} = (string) Str::uuid7();
            }

            if ($value->version === null) {
                $value->version = 1;
            }

            if ($value->display_order === null) {
                $value->display_order = 0;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'version' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatusCategory::class, 'category_id');
    }

    public function behaviors(): HasMany
    {
        return $this->hasMany(EmploymentStatusDetailBehavior::class, 'status_detail_id');
    }
}
