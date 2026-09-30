<?php

namespace App\Modules\HumanResources\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One logical employment-status expiry follow-up (docs/employment-status-expiry-followup-specification.md
 * §S38.5): automation.employment_status_expiry_followups. READ model — rows are created and transitioned only by
 * ScanEmploymentStatusExpiryFollowUps (raw, idempotent SQL under the relationship row lock), never through this
 * model, so nothing here is mass-assignable. LAPSED is derived at read time and never stored.
 */
class EmploymentStatusExpiryFollowUp extends Model
{
    public const ACTIONABLE = 'ACTIONABLE';

    public const SUPPRESSED = 'SUPPRESSED';

    protected $table = 'automation.employment_status_expiry_followups';

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

    public function employmentStatusPeriod(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatusPeriod::class, 'employment_status_period_id');
    }

    public function employmentRelationship(): BelongsTo
    {
        return $this->belongsTo(EmploymentRelationship::class, 'employment_relationship_id');
    }

    /** The state a reader sees on $businessDate: persisted SUPPRESSED, else ACTIONABLE before E and LAPSED at/after E. */
    public function readState(string $businessDate): string
    {
        if ($this->status === self::SUPPRESSED) {
            return 'SUPPRESSED';
        }

        return $this->expected_effective_to->toDateString() > $businessDate ? 'ACTIONABLE' : 'LAPSED';
    }
}
