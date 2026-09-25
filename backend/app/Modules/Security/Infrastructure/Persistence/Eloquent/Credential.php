<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use App\Modules\Security\Domain\CredentialType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * §6 of the S03 authorization: password_hash must never be exposed through API resources, logs,
 * exceptions, frontend state, debug responses, or bootstrap output. #[Hidden] enforces this at the
 * model boundary — array/JSON serialization of a Credential never includes it, so a resource class
 * that accidentally serializes a whole model still cannot leak the hash.
 */
#[Fillable(['principal_id', 'credential_type', 'password_hash', 'password_changed_at'])]
#[Hidden(['password_hash'])]
class Credential extends Model
{
    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = 'created_at';

    protected $table = 'security.credentials';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $credential): void {
            if (! $credential->getKey()) {
                $credential->{$credential->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'password_changed_at' => 'datetime',
        ];
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'principal_id');
    }
}
