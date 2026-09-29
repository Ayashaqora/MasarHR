<?php

namespace App\Modules\HumanResources\Application\Commands;

use App\Modules\HumanResources\Domain\Exceptions\EmploymentRelationshipAlreadyEndedException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidFullSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidPartialSecondmentEndDateException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidTransferDecisionTypeException;
use App\Modules\HumanResources\Domain\Exceptions\InvalidWorkplaceAssignmentEndDateException;
use App\Modules\HumanResources\Domain\TransferResult;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\EmploymentRelationship;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\FullSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\PartialSecondmentPeriod;
use App\Modules\HumanResources\Infrastructure\Persistence\Eloquent\WorkplaceAssignmentPeriod;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationalUnit;
use App\Modules\Reference\Infrastructure\Persistence\Eloquent\DecisionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transfer Foundation's single atomic command (docs/transfer-foundation-specification.md §13,
 * ADR-S14-001/ADR-S14-002): moves an Employment Relationship's original organizational placement
 * (S11) to a destination unit as of one mandatory effective date, and — as an atomic consequence of
 * the same transaction — closes an active full secondment (S12), if one exists, at that same date.
 * Both effects are achieved entirely by calling S11's and S12's own existing commands in-process,
 * exactly mirroring RecordEmploymentStatusPeriod's own established precedent for wiring one
 * command's approved consequence onto a different aggregate stream (spec §15/§16: "call the
 * existing command's own handle() in-process, inside this same transaction — never through a
 * second AuditedCommandExecutor::run(), which would double-audit and attempt a transaction the
 * executor does not support nesting"). No new persistence table is written by this command (§16 —
 * persistence-design Option B: the placement-periods-plus-audit-only design ADR-S14-001 documents);
 * "transfer" is not a new fact stored anywhere beyond the S11 placement row it writes, the S12
 * period it may close, and its own S04 audit entry.
 *
 * The EmploymentRelationship is re-fetched fresh with lockForUpdate() as the first statement here —
 * never trusted from whatever the caller passed in — exactly mirroring
 * CreateEmploymentRelationship's/RecordEmploymentStatusPeriod's/RecordOrganizationalPlacementPeriod's
 * own established discipline (spec §10). This also serializes this command against a concurrent
 * EndEmploymentRelationship/RecordOrganizationalPlacementPeriod/StartFullSecondment/
 * EndFullSecondment/TransferEmployee call on the same relationship — no new lock ordering is
 * introduced (spec §17).
 *
 * `decision_type_id` is validated against a freshly re-fetched `ref.decision_types` row — never
 * trusted from whatever the caller/controller resolved moments earlier — for the identical
 * anti-TOCTOU reason `CreateEmploymentRelationship` re-fetches `EmploymentType` fresh: a concurrent
 * `DeactivateDecisionType` call could deactivate TRANSFER between the controller's lookup and this
 * command's own write. The stable technical discriminator checked is `code === 'TRANSFER'`, never
 * `name_ar`/`name_en` display text (ADR-S14-002 explicit instruction — ref.decision_types has no
 * DB-level CHECK enforcing this, so the application layer is the sole enforcement point, exactly
 * like every other business-rule check already re-validated inside this transaction).
 *
 * S16 (docs/workplace-assignment-foundation-specification.md §S16.8, movement interaction matrix
 * pair "Assignment → Transfer": ALLOW, CLOSE-PREVIOUS-AS-CONSEQUENCE): also closes an active
 * Workplace Assignment period, if one exists, at the same effective date — exactly mirroring how
 * this command already closes an active Full Secondment. Once the underlying original placement
 * itself moves, a temporary destination override of the old placement no longer has coherent
 * meaning.
 *
 * S28 (docs/movement-temporal-integrity-corrective-specification.md §S28.8, ADR-S28-001): the
 * consequence is now INTERVAL-AWARE. A secondment or assignment EFFECTIVE at the transfer date —
 * open, or closed with a later effective_to — is truncated at that date, so no temporary movement
 * that began before the transfer continues across it. Periods that ended on or before the date are
 * never touched. The frozen S14 §20 rejection (422 errors.effective_to, via the S12/S16 end-date
 * exceptions) generalises from "the open period starts on or after the date" to "any period of
 * that stream starts on or after the date" — later history is never rewritten. The whole command
 * runs in one transaction, so a rejected consequence also rolls back the placement write.
 */
final class TransferEmployee
{
    public function __construct(
        private readonly RecordOrganizationalPlacementPeriod $recordPlacement,
        private readonly SupersedeTemporaryWorkplaceMovement $supersession,
    ) {}

    /**
     * @throws EmploymentRelationshipAlreadyEndedException|InvalidTransferDecisionTypeException
     */
    public function handle(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
        DecisionType $decisionType,
    ): TransferResult {
        return DB::transaction(fn (): TransferResult => $this->transfer($relationship, $destination, $effectiveFrom, $decisionType));
    }

    private function transfer(
        EmploymentRelationship $relationship,
        OrganizationalUnit $destination,
        string $effectiveFrom,
        DecisionType $decisionType,
    ): TransferResult {
        $freshRelationship = EmploymentRelationship::query()
            ->where('id', $relationship->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        // Mirrors S11/S12's identical "reject a movement against an already-ended relationship"
        // choice (spec §14 step 2) — checked first, before any other validation, exactly as
        // RecordOrganizationalPlacementPeriod/StartFullSecondment already do.
        if ($freshRelationship->end_knowledge_state === 'KNOWN') {
            throw new EmploymentRelationshipAlreadyEndedException;
        }

        $freshDecisionType = DecisionType::query()->where('id', $decisionType->getKey())->first();

        if ($freshDecisionType === null || $freshDecisionType->code !== 'TRANSFER' || ! $freshDecisionType->is_active) {
            throw new InvalidTransferDecisionTypeException;
        }

        // S11's own existing command: validates the effective date against the relationship's own
        // effective_from and the currently-open placement period's own effective_from
        // (InvalidPlacementPeriodDateException, already mapped to 422 errors.effective_from — left
        // to propagate unmodified), auto-closes that open period, and inserts the new destination
        // placement — exactly the "close current original organizational placement... open
        // destination organizational placement" effect this stage's authorization names (spec
        // §10 step 4).
        $placement = $this->recordPlacement->handle($freshRelationship, $destination, $effectiveFrom);

        // Consequences (S14 §10 step 5, S16 §S16.8), interval-aware since S28: whatever
        // secondment / assignment is EFFECTIVE at the transfer date is truncated at it.
        $date = Carbon::parse($effectiveFrom)->toDateString();
        $secondmentConflict = fn () => new InvalidFullSecondmentEndDateException;
        $assignmentConflict = fn () => new InvalidWorkplaceAssignmentEndDateException;

        $this->supersession->assertSupersedable(FullSecondmentPeriod::class, $freshRelationship->getKey(), $date, $secondmentConflict);
        $this->supersession->assertSupersedable(WorkplaceAssignmentPeriod::class, $freshRelationship->getKey(), $date, $assignmentConflict);
        // S30 (docs/partial-secondment-foundation-specification.md §S30.13, ADR-S30-008): the same
        // S14/S16/S28 consequence for Partial Secondments — every one effective at D is truncated at
        // D (the partial override of the old workplace ends with it); one starting on or after D
        // rejects the transfer atomically instead of being rewritten.
        $partialConflict = fn () => new InvalidPartialSecondmentEndDateException;
        $this->supersession->assertSupersedable(PartialSecondmentPeriod::class, $freshRelationship->getKey(), $date, $partialConflict);

        $closedSecondment = $this->supersession->supersedeAt(FullSecondmentPeriod::class, $freshRelationship->getKey(), $date, $secondmentConflict);
        $closedAssignment = $this->supersession->supersedeAt(WorkplaceAssignmentPeriod::class, $freshRelationship->getKey(), $date, $assignmentConflict);
        $closedPartials = $this->supersession->supersedeAllAt(PartialSecondmentPeriod::class, $freshRelationship->getKey(), $date, $partialConflict);

        return new TransferResult($placement, $closedSecondment, $closedAssignment, $closedPartials);
    }
}
