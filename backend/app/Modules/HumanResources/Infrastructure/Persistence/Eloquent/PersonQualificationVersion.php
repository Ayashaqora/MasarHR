<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\Principal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S48 (docs/person-qualification-history-foundation-specification.md §S48.3, D10/D11/D24):
 * `hr.person_qualification_versions` — append-only in effect. The only permitted `UPDATE` through
 * this model is the single `is_current: true -> false` flip performed by
 * `CorrectPersonQualification`; `DELETE` is never performed and is rejected by the database itself
 * (MA004) regardless. `provenance` (D36/D43) is a derived, not stored, concept — it answers only
 * whether `created_by_principal_id` is known, never when or by what path the row was created.
 */
#[Fillable([
    'person_qualification_id', 'person_id', 'version_number', 'academic_degree_id',
    'qualification_type_id', 'obtained_on', 'is_current', 'reason', 'created_by_principal_id',
])]
class PersonQualificationVersion extends Model
{
    protected $table = 'hr.person_qualification_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'obtained_on' => 'date:Y-m-d',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $version): void {
            if (! $version->getKey()) {
                $version->{$version->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(PersonQualification::class, 'person_qualification_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function academicDegree(): BelongsTo
    {
        return $this->belongsTo(AcademicDegree::class, 'academic_degree_id');
    }

    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class, 'qualification_type_id');
    }

    public function createdByPrincipal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'created_by_principal_id');
    }

    /**
     * D36, corrected by D43: answers only whether this version's own recording actor is
     * evidenced — never whether, or when, the version was created relative to the S48 migration.
     * A backfilled row whose original actor was resolved from a pre-existing audit entry is
     * RECORDED too, indistinguishable by this field alone from a live, post-migration write.
     */
    public function provenance(): string
    {
        return $this->created_by_principal_id !== null ? 'RECORDED' : 'BACKFILLED_UNKNOWN_ACTOR';
    }
}
