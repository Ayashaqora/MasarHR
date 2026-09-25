<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['role_id', 'permission_id'])]
class RolePermission extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'security.role_permissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $row): void {
            if (! $row->getKey()) {
                $row->{$row->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
