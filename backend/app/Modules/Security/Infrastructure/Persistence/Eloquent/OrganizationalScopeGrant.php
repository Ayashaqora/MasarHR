<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single organizational-scope grant (spec §10). A grant/assignment row — same category as
 * PrincipalRole/RolePermission, not a versioned entity like OrganizationalUnit: no `version`
 * column, no soft-delete flag. Revocation is a real DELETE (RevokeOrganizationalScope).
 */
#[Fillable(['principal_id', 'scope_kind', 'organizational_unit_id', 'granted_at', 'granted_by'])]
class OrganizationalScopeGrant extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'security.organizational_scope_grants';

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
        return ['granted_at' => 'datetime'];
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'principal_id');
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'granted_by');
    }
}
