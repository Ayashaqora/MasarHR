<?php

namespace App\Modules\Reference\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Gender reference values (S05 §5.2). */
#[Fillable(['code', 'name_ar', 'name_en', 'display_order', 'is_active'])]
class Gender extends Model
{
    protected $table = 'ref.genders';

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
