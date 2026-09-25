<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['principal_id', 'role_id', 'assigned_at', 'assigned_by'])]
class PrincipalRole extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'security.principal_roles';

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

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'principal_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'assigned_by');
    }
}
