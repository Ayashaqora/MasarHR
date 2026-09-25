<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['code', 'name_ar', 'name_en', 'description', 'is_system', 'is_active'])]
class Role extends Model
{
    protected $table = 'security.roles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $role): void {
            if (! $role->getKey()) {
                $role->{$role->getKeyName()} = (string) Str::uuid7();
            }

            // See Principal::boot() for why the DB-level DEFAULT 1 on `version` is not enough on
            // its own: it is never read back onto this in-memory model after an INSERT.
            if ($role->version === null) {
                $role->version = 1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PrincipalRole::class, 'role_id');
    }
}
