<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\JobTitle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S22's Employment Job Title period (docs/employment-job-title-history-foundation-specification.md
 * §S22.6, ADR-S22-001): a temporal child of the EmploymentRelationship aggregate, never its own
 * aggregate root and never attached to Person. Append-only — no `updated_at`. Identity, job title,
 * effective_from and start_knowledge_state are never changed after insert; the only later mutation
 * is TEMPORAL CLOSURE of an open period's `effective_to` — by RecordEmploymentJobTitlePeriod when
 * the next title takes effect, or by EndEmploymentRelationship when the relationship ends.
 */
#[Fillable(['employment_relationship_id', 'job_title_id', 'effective_from', 'effective_to', 'start_knowledge_state'])]
class EmploymentJobTitlePeriod extends Model
{
    protected $table = 'hr.employment_job_title_periods';

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

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }
}
