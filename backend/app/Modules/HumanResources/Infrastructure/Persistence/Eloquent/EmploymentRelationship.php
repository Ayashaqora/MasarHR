<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\Reference\Infrastructure\Persistence\Eloquent\EmploymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * S09's Employment Relationship aggregate (spec §6). Never hard-deleted — the append-only history
 * is itself the permanent PERMANENT-employee-number reservation registry (spec §8).
 */
#[Fillable([
    'person_id',
    'employment_type_id',
    'employee_number',
    'employee_number_scheme',
    'effective_from',
    'effective_to',
    'end_knowledge_state',
    'ended_terminally',
])]
class EmploymentRelationship extends Model
{
    protected $table = 'hr.employment_relationships';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $relationship): void {
            if (! $relationship->getKey()) {
                $relationship->{$relationship->getKeyName()} = (string) Str::uuid7();
            }

            if ($relationship->version === null) {
                $relationship->version = 1;
            }

            if ($relationship->end_knowledge_state === null) {
                $relationship->end_knowledge_state = 'NOT_APPLICABLE';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'ended_terminally' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class, 'employment_type_id');
    }

    public function isCurrent(): bool
    {
        return $this->end_knowledge_state === 'NOT_APPLICABLE' && $this->effective_to === null;
    }
}
