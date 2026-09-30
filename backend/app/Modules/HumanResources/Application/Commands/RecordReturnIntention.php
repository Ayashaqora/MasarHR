<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionPeriodEndException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidReturnIntentionValueException;
use App\Modules\HumanResources\Domain\ReturnIntention;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\ReturnIntentionPeriod;
use App\Modules\Platform\Infrastructure\Persistence\Postgres\PostgresErrorClassifier as Errors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * S34: records one Return Intention period for an Employment Relationship. Return Intention is NOT an
 * Employment Status: this command never reads, creates, closes or modifies a status, movement,
 * schedule or leave row. Caller owns the transaction (like every temporal command).
 *
 * Order: lock the relationship (FOR UPDATE, first statement) → known end ⇒ 409 → value → end after
 * start → start not before the relationship → (R2) any recorded period starting on/after the new start
 * ⇒ reject (recorded later history is never silently rewritten) → the period covering the new start is
 * truncated at it, except a bounded new period strictly inside a bounded covering one is rejected (it
 * would need a split) → insert. The PostgreSQL EXCLUDE remains the final backstop.
 */
final class RecordReturnIntention
{
    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidReturnIntentionValueException
     * @throws InvalidReturnIntentionPeriodDateException|InvalidReturnIntentionPeriodEndException
     */
    public function handle(
        EmploymentRelationship $relationship,
        string $intention,
        string $effectiveFrom,
        ?string $effectiveTo = null,
    ): ReturnIntentionPeriod {
        $fresh = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($fresh->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $value = ReturnIntention::tryFrom($intention);

        if ($value === null) {
            throw new InvalidReturnIntentionValueException;
        }

        $from = Carbon::parse($effectiveFrom);

        if ($effectiveTo !== null && ! Carbon::parse($effectiveTo)->gt($from)) {
            throw new InvalidReturnIntentionPeriodEndException('effective_to must be strictly after effective_from.');
        }

        if ($from->lt($fresh->effective_from)) {
            throw new InvalidReturnIntentionPeriodDateException;
        }

        $hasLaterOrEqual = ReturnIntentionPeriod::query()
            ->where('employment_relationship_id', $fresh->getKey())
            ->where('effective_from', '>=', $effectiveFrom)
            ->exists();

        if ($hasLaterOrEqual) {
            throw new InvalidReturnIntentionPeriodDateException;
        }

        $covering = ReturnIntentionPeriod::query()
            ->where('employment_relationship_id', $fresh->getKey())
            ->where('effective_from', '<', $effectiveFrom)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveFrom))
            ->first();

        if ($covering !== null && $covering->effective_to !== null && $effectiveTo !== null
            && Carbon::parse($effectiveTo)->lt($covering->effective_to)) {
            throw new InvalidReturnIntentionPeriodEndException('effective_to would end inside an already recorded bounded Return Intention period; recorded history is not rewritten.');
        }

        try {
            $covering?->update(['effective_to' => $effectiveFrom]);

            $period = new ReturnIntentionPeriod([
                'employment_relationship_id' => $fresh->getKey(),
                'intention' => $value->value,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);
            $period->save();
        } catch (QueryException $e) {
            if (Errors::isExclusionViolation($e) || Errors::isCheckViolation($e)) {
                throw new InvalidReturnIntentionPeriodDateException;
            }

            throw $e;
        }

        return $period->refresh();
    }
}
