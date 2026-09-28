<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Specialty reference values — the canonical specialty catalog (S05 §5.3 structure; administered
 * since S25, docs/specialty-catalog-administration-foundation-specification.md). VALUES DEFERRED:
 * no specialty row is seeded; rows are created by reference administrators.
 */
#[Fillable(['code', 'name_ar', 'name_en', 'display_order', 'is_active'])]
class Specialty extends Model
{
    protected $table = 'ref.specialties';

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
}
