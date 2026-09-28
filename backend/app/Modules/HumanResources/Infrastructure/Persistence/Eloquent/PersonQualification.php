<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\AcademicDegree;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\QualificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S23's Person Qualification fact (docs/person-qualification-foundation-specification.md §S23.6,
 * ADR-S23-001): a child of the Person aggregate, never of an Employment Relationship, so it survives
 * every employment lifecycle event and reappointment unchanged. Identified by an optional academic
 * degree plus an optional qualification type (at least one). Insert-only in S23 — no `updated_at`,
 * no edit, no delete; correction semantics are deferred. Carries no date, specialty, or
 * primary/highest designation.
 */
#[Fillable(['person_id', 'academic_degree_id', 'qualification_type_id'])]
class PersonQualification extends Model
{
    protected $table = 'hr.person_qualifications';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

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

    public function academicDegree(): BelongsTo
    {
        return $this->belongsTo(AcademicDegree::class, 'academic_degree_id');
    }

    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class, 'qualification_type_id');
    }
}
