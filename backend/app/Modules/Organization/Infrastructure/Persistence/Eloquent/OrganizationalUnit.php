<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An organizational unit in the S07 hierarchy (spec §11): UUID identity, a required `name`,
 * a nullable self-referencing `parent_id`, `is_active`, and optimistic-concurrency `version`.
 * Deliberately no `type`/`level`/`code` column and no temporal/effective-dated fields (spec §16).
 */
#[Fillable(['name', 'parent_id', 'is_active'])]
class OrganizationalUnit extends Model
{
    protected $table = 'org.organizational_units';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $unit): void {
            if (! $unit->getKey()) {
                $unit->{$unit->getKeyName()} = (string) Str::uuid7();
            }

            if ($unit->version === null) {
                $unit->version = 1;
            }

            if ($unit->is_active === null) {
                $unit->is_active = true;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
