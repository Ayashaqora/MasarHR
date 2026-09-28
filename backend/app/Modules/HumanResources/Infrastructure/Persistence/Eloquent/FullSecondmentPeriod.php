<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S12's Full Secondment Foundation (spec §8): a temporal child of the EmploymentRelationship
 * aggregate, never its own aggregate root — the same shape as EmploymentStatusPeriod (S10) and
 * OrganizationalPlacementPeriod (S11). Records the relationship's "actual workplace" only while
 * it diverges from S11's original-workplace stream; the original placement stream is never
 * written by this model. Append-only — no `updated_at` column exists on purpose: the only
 * mutation a row ever receives is its own `effective_to` being set once, by EndFullSecondment, in
 * a separate, deliberate action (spec §8.1) — never as a side effect of a later insert the way
 * S10/S11's own child streams work.
 */
#[Fillable(['employment_relationship_id', 'organizational_unit_id', 'effective_from', 'effective_to'])]
class FullSecondmentPeriod extends Model
{
    protected $table = 'hr.full_secondment_periods';

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

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    public function isActive(): bool
    {
        return $this->effective_to === null;
    }
}
