<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use App\Modules\HumanResources\Domain\TemporaryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One logical movement-expiry follow-up (docs/movement-expiry-followup-foundation-specification.md
 * §S31.6): automation.movement_expiry_followups. READ model — rows are created and transitioned
 * only by ScanMovementExpiryFollowUps (raw, idempotent SQL under the relationship row lock), never
 * through this model, so nothing here is mass-assignable. `movement_id` is a database-generated
 * column over the three real movement foreign keys.
 */
class MovementExpiryFollowUp extends Model
{
    public const ACTIONABLE = 'ACTIONABLE';

    public const SUPPRESSED = 'SUPPRESSED';

    protected $table = 'automation.movement_expiry_followups';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expected_effective_to' => 'date',
            'due_date' => 'date',
            'suppressed_at' => 'datetime',
        ];
    }

    public function movementType(): TemporaryMovementType
    {
        return TemporaryMovementType::from($this->movement_type);
    }

    public function employmentRelationship(): BelongsTo
    {
        return $this->belongsTo(EmploymentRelationship::class, 'employment_relationship_id');
    }
}
