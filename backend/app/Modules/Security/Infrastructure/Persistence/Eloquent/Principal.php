<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use App\Modules\Security\Domain\PrincipalStatus;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A security/authentication identity — deliberately NOT an HR employee (§3 of the S03
 * authorization). No national_id, no employment relationship.
 *
 * getAuthPassword() is intentionally not backed by a `password` column: this model has none, and
 * Laravel's own Auth::attempt()/EloquentUserProvider password check is never used against it (see
 * App\Modules\Security\Application\Authentication\AuthenticateWithPassword, which verifies against
 * the related Credential explicitly). The override exists only so Authenticatable's contract is
 * satisfied without ever exposing or depending on a password attribute here.
 *
 * `status` is deliberately NOT mass-assignable (S03 correction order §1). It may only be written
 * through App\Modules\Security\Application\Commands\ChangePrincipalStatus, which performs a scoped,
 * optimistic-concurrency query-builder UPDATE and never goes through Eloquent mass assignment. Every
 * other Principal write path (ChangePrincipalUsername, ChangePrincipalDisplayName) uses the same
 * scoped query-builder pattern for the same reason: no privileged Principal field may change through
 * generic mass assignment, only through its named, explicit application command.
 */
#[Fillable(['username', 'username_normalized', 'display_name'])]
#[Hidden(['version'])]
class Principal extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'security.principals';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $principal): void {
            if (! $principal->getKey()) {
                $principal->{$principal->getKeyName()} = (string) Str::uuid7();
            }

            // The `version` column also has a DB-level DEFAULT 1 (belt and braces for rows
            // inserted outside Eloquent), but that default is never read back onto this in-memory
            // model after an INSERT, so it must be set here too or $principal->version stays null
            // until the row is re-fetched.
            if ($principal->version === null) {
                $principal->version = 1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PrincipalStatus::class,
            'version' => 'integer',
        ];
    }

    public function credential(): HasOne
    {
        return $this->hasOne(Credential::class, 'principal_id');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(PrincipalRole::class, 'principal_id');
    }

    public function isActive(): bool
    {
        return $this->status === PrincipalStatus::Active;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }
}
