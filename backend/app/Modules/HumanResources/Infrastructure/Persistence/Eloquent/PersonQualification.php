<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * S23's Person Qualification stable identity (docs/person-qualification-foundation-specification.md
 * §S23.6, ADR-S23-001; versioned by S48,
 * docs/person-qualification-history-foundation-specification.md §S48.3, D10): a child of the
 * Person aggregate, never of an Employment Relationship, so it survives every employment lifecycle
 * event and reappointment unchanged. Keeps only `id`, `person_id`, `is_primary`, `created_at` — one
 * stable identity for the qualification's whole life. `id` and `person_id` are immutable once
 * created (D33, enforced by a database trigger). The degree/type/obtained-on VALUES live on
 * `hr.person_qualification_versions` (§S48.3); this model never exposes those two columns directly
 * any more — they were dropped from this table once every consumer was repointed (§S48.13/§S48.18).
 */
#[Fillable(['person_id', 'is_primary'])]
class PersonQualification extends Model
{
    protected $table = 'hr.person_qualifications';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $qualification): void {
            if (! $qualification->getKey()) {
                $qualification->{$qualification->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /** Every version of this qualification, oldest first by construction of version_number (§S48.14). */
    public function versions(): HasMany
    {
        return $this->hasMany(PersonQualificationVersion::class, 'person_qualification_id')->orderBy('version_number');
    }

    /** The one version row with is_current = true (§S48.8's "at most one current" guarantee). */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(PersonQualificationVersion::class, 'person_qualification_id')->where('is_current', true);
    }
}
