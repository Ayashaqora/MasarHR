<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Monthly Human Cadre reporting categories (S06 spec §12.1). */
#[Fillable(['code', 'name_ar', 'name_en', 'display_order', 'is_active'])]
class MonthlyCadreCategory extends Model
{
    protected $table = 'ref.monthly_cadre_categories';

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
