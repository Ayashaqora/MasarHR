<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\Specialty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S26's Employee Specialty period (docs/employee-specialty-history-foundation-specification.md
 * §S26.6, ADR-S26-001): a temporal child of the EmploymentRelationship aggregate, never its own
 * aggregate root and never attached to Person or to a PersonQualification. Append-only — no
 * `updated_at`. Identity, specialty and effective_from are never changed after insert; the only
 * later mutation is TEMPORAL CLOSURE of an open period's `effective_to` — by
 * RecordEmploymentSpecialtyPeriod when the next period takes effect, or by
 * EndEmploymentRelationship when the relationship ends (the S22 discipline).
 */
#[Fillable(['employment_relationship_id', 'specialty_id', 'effective_from', 'effective_to'])]
class EmploymentSpecialtyPeriod extends Model
{
    protected $table = 'hr.employment_specialty_periods';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $period): void {
            if (! $period->getKey()) {
                $period->{$period->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employmentRelationship(): BelongsTo
    {
        return $this->belongsTo(EmploymentRelationship::class, 'employment_relationship_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id');
    }
}
