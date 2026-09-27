<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S11's Organizational Placement Foundation (spec §6): a temporal child of the
 * EmploymentRelationship aggregate, never its own aggregate root — the same shape as
 * EmploymentStatusPeriod (S10). Append-only — no `updated_at` column exists on purpose: a row,
 * once inserted, is never itself updated; the only "mutation" any later insert ever causes is
 * setting `effective_to` on the previously-inserted open row, done by
 * RecordOrganizationalPlacementPeriod as part of recording the next one. Records the
 * relationship's "original workplace" only — spec §5.1 discloses why "actual/current workplace" is
 * deliberately not persisted here.
 */
#[Fillable(['employment_relationship_id', 'organizational_unit_id', 'effective_from', 'effective_to'])]
class OrganizationalPlacementPeriod extends Model
{
    protected $table = 'hr.organizational_placement_periods';

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

    public function isCurrent(): bool
    {
        return $this->effective_to === null;
    }
}
