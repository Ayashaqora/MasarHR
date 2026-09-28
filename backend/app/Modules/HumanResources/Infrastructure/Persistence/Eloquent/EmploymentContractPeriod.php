<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\ContractType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S21's Employment Contract period (docs/employment-contract-foundation-specification.md §S21.6,
 * ADR-S21-001): a temporal child of the EmploymentRelationship aggregate, never its own aggregate
 * root and never attached to Person. Append-only — no `updated_at`. The agreed term
 * (contractual_effective_to) is written once and never rewritten; the only later mutation is
 * moving `effective_to` EARLIER — by RecordEmploymentContractPeriod when a renewal takes effect
 * before the previous term's end, or by EndEmploymentRelationship when the relationship ends
 * before it.
 */
#[Fillable([
    'employment_relationship_id', 'contract_type_id', 'effective_from', 'effective_to',
    'contractual_effective_to', 'contract_end_knowledge_state',
])]
class EmploymentContractPeriod extends Model
{
    protected $table = 'hr.employment_contract_periods';

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
            'contractual_effective_to' => 'date',
        ];
    }

    public function employmentRelationship(): BelongsTo
    {
        return $this->belongsTo(EmploymentRelationship::class, 'employment_relationship_id');
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    /** True when the actual validity ended before the agreed term (early renewal or relationship end). */
    public function endedBeforeContractualTerm(): bool
    {
        return $this->effective_to !== null
            && $this->contractual_effective_to !== null
            && $this->effective_to->lt($this->contractual_effective_to);
    }
}
