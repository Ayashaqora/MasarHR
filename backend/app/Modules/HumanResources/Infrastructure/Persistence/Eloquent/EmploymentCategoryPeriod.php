<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S20's Employment Category History (docs/employment-category-history-foundation-specification.md
 * §S20.6, ADR-S20-001): a temporal child of the EmploymentRelationship aggregate, never its own
 * aggregate root and never attached to Person. Append-only — no `updated_at` column exists on
 * purpose, exactly mirroring EmploymentStatusPeriod (S10): a row, once inserted, is never itself
 * updated; the only "mutation" any later write ever causes is setting `effective_to` on the open
 * row, done either by RecordEmploymentCategoryPeriod when recording the next period or by
 * EndEmploymentRelationship when the relationship itself ends.
 */
#[Fillable(['employment_relationship_id', 'employment_category_id', 'effective_from', 'effective_to'])]
class EmploymentCategoryPeriod extends Model
{
    protected $table = 'hr.employment_category_periods';

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

    public function employmentCategory(): BelongsTo
    {
        return $this->belongsTo(EmploymentCategory::class, 'employment_category_id');
    }

    public function isCurrent(): bool
    {
        return $this->effective_to === null;
    }
}
