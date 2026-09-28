# S28 — Movement Temporal Integrity Corrective / تصحيح التكامل الزمني لحركات مكان العمل

This document records the specification and ADR for **ADR-S28-001 — New movement supersedes the
previous effective temporary workplace movement**. The Executor (Claude Code Cloud) wrote it under
the Architecture Authority's "S28 Full Execution Authorization". S28 is a bounded corrective stage.
Frozen S12/S14/S16 documents are not rewritten; this document records where S28 supersedes them.

- **Baseline:** `origin/develop @ ae94a301e4df8dd649fd454ac56f6b3a0adad973` (tag
  `s27-reporting-as-of-foundation`).

## §S28.1 Defect (from the post-S27 audit)

The S12/S16 cross-stream checks and the S14 transfer consequence looked only at **open** rows
(`effective_to IS NULL`). As a result:

- a secondment recorded with a **future** `effective_to`, followed by an assignment starting before
  that end, was accepted and left two movements in force on the same dates;
- a transfer left such a closed-but-still-in-force movement running past the transfer date;
- a cross-stream start while the other movement was open was rejected (409), contrary to the frozen
  rule "a new movement stops the previous one".

S27 detects the resulting overlaps as `AMBIGUOUS_MOVEMENT_STATE`.

## §S28.2 ADR-S28-001 (APPROVED)

When a new Full Secondment or Workplace Assignment starts at date D, and another temporary
workplace movement is **in force** at D, the command:

1. truncates that movement to end exactly at D;
2. creates the new movement from D;
3. does both atomically.

It is no longer rejected just because the other movement is active. This replaces S16 §S16.8's
conservative cross-stream REJECT: that rule was chosen only because no precedence rule had been
supplied (ADR-S16-001 §4), and ADR-S28-001 now supplies it.

## §S28.3 Interval semantics

"In force at D" means the half-open test:

```
effective_from <= D  AND  (effective_to IS NULL OR D < effective_to)
```

It never means "open row". A row with a non-null future `effective_to` is still in force. Adjacent
periods (`effective_to = D`) do not conflict, and a period that ended on or before D is never
touched.

## §S28.4 The shared rule — `SupersedeTemporaryWorkplaceMovement`

One internal application service holds the rule for both movement tables. It has four operations:

- **`effectiveAt(stream, relationship, D)`** returns the period in force at D. There is at most one
  per stream, because each table has its own EXCLUDE constraint.
- **`assertSupersedable(stream, relationship, D, conflict)`** throws `conflict` when any period of
  that stream **starts on or after D**. Rewriting that later history (or erasing a period starting
  on D) is refused. S28 is not a general historical editor.
- **`truncate(period, D, conflict)`** sets `effective_to = D`. Identity, unit and `effective_from`
  are preserved and nothing is deleted.
- **`supersedeAt(...)`** runs `assertSupersedable`, then `effectiveAt`, then `truncate`.

Callers hold the EmploymentRelationship row lock and run inside a transaction.

## §S28.5 Full Secondment (`StartFullSecondment`)

- **Same stream: unchanged** (S12 §8.1). If a secondment is open, the start is rejected with 409
  `ActiveFullSecondmentAlreadyExistsException`. Any other overlap between two secondments is
  stopped by the S12 EXCLUDE constraint (422).
- **Cross stream:** after the existing checks (relationship not ended, start after the relationship
  start), the assignment in force at D is superseded.
- **Refusal:** if an assignment starts on or after D, the start is rejected with 409
  `ActiveWorkplaceAssignmentAlreadyExistsException`, the existing contract. Its message is
  unchanged.
- The whole command runs in one transaction.

## §S28.6 Workplace Assignment (`StartWorkplaceAssignment`)

- **Same stream:** the S16 "close the previous assignment" rule is kept but made interval-aware. A
  previous assignment that is in force at D, open or closed later than D, is truncated. An
  assignment starting on or after D is still rejected with 422
  `InvalidWorkplaceAssignmentStartDateException`, as before.
- **Cross stream:** the secondment in force at D is superseded. If a secondment starts on or after
  D, the start is rejected with 409 `ActiveFullSecondmentAlreadyExistsException`.
- **Validation order:** both streams are validated before either is changed.
- The whole command runs in one transaction.

## §S28.7 Backdated starts

A backdated start is checked against the **complete** timeline:

- Truncating exactly the one directly superseded movement is allowed.
- Anything that would need later-recorded periods rewritten is rejected atomically with the
  existing 409/422 semantics.
- History is never fabricated or silently rewritten.

## §S28.8 Transfer (`TransferEmployee`)

- At the transfer date D, the secondment or assignment **in force at D** is truncated at D, whether
  open or closed with a later end.
- Periods that ended on or before D are never touched.
- `TransferResult.closedSecondment` / `closedAssignment` now report a truncated future-closed
  period too.
- **Rejection:** S14 §20 rejected a transfer when an *open* secondment started on or after D
  (422 `errors.effective_to`). That rule is generalised to "a period of either stream starts on or
  after D", using the same S12/S16 end-date exceptions. Later-recorded history is never rewritten.
- The command now runs in one transaction, so a rejected consequence also rolls back the placement
  write.
- **Scope:** the controller checks scope for the units of the movements **in force at D**, which
  are exactly the ones it truncates, instead of only open rows.

## §S28.9 Legacy ambiguity preservation

- There is **no** migration, data rewrite or repair. Overlaps already stored stay stored.
- `ResolveActualWorkplaceForRelationshipAsOf` still reports them as `AMBIGUOUS_MOVEMENT_STATE`.
- The S27 test now constructs its legacy overlap by direct insert, because the commands can no
  longer produce it.
- `ResolveActualWorkplaceForRelationship` (the open-period view) is unchanged.
- The S27 current-vs-as-of divergence for future-recorded facts still holds.

## §S28.10 Concurrency and atomicity

- **Serialisation:** every writer to either movement table takes the EmploymentRelationship row lock
  first: Start and End for both streams, `TransferEmployee`, `EndEmploymentRelationship`. The
  controllers take the same lock before their scope and audit snapshots.
- **No cross-table constraint:** the two streams live in two tables, so no single PostgreSQL EXCLUDE
  constraint can span them. The row lock is the serialisation point; no schema change and no new
  locking architecture are introduced.
- **Proof:** `ConcurrencyTest` shows, across two real sessions, that an in-flight secondment blocks
  the assignment, and that the assignment then supersedes it without overlap.
- **Atomicity:** each command wraps lock → validate → truncate → insert in one transaction (nested
  inside the controller's audit transaction). If the insert fails, the truncation rolls back
  (tested).

## §S28.11 Audit

The existing S04 audit actions and their `changes` are unchanged. The consequence appears in
`metadata`, with stable IDs and dates only (no PII):

```
superseded_movements: [{stream, period_id, previous_effective_to, effective_to}]
superseded_at: D
```

- The metadata is empty (`[]`) when nothing was superseded.
- For assignments it also covers the unchanged same-stream "previous assignment" closure.
- The transfer audit keeps its existing `closed_*_period_id` fields.

## §S28.12 Security

- There is no new permission and no RBAC change.
- **Superseding across streams scope-checks the unit of the movement being ended**, using the
  command's own start permission, as S14 does for transfers. A caller can never end a movement in a
  unit outside their scope (tested: 403 with no change and no audit).
- The same-stream S16 closure keeps its original authorization shape.
- Ownership checks and 404 behavior are unchanged.

## §S28.13 Tests

- **`MovementTemporalIntegrityCorrectiveTest`** (23 tests) covers:
  - **Cross-stream supersession:** A–D (open and future-closed, both directions); E adjacency; F an
    earlier-ended movement.
  - **Backdated starts:** G simple; H complex (rejected atomically, cross-stream and same-stream);
    a start exactly on another movement's start is rejected; same-stream secondment is unchanged.
  - **Transfer:** I–L (open and future-closed, both streams); M after an end; a transfer before a
    later movement is rejected atomically.
  - **Atomicity and compatibility:** N atomic rollback; P legacy ambiguity kept and not repaired;
    Q S27 current vs as-of.
  - **HTTP:** audit metadata; empty metadata when nothing is superseded; out-of-scope supersession
    gives 403; transfer scope covers a future-closed movement.
- **`ConcurrencyTest`:** O cross-stream race.
- **Updated for the approved behavior change:**
  - two S16 tests, "…is_rejected" becoming "…supersedes_it", plus a later-history rejection test;
  - the S27 overlap test fixture, built by direct insert.

## §S28.14 Deferred

- **Legacy data:** a reviewed historical-repair mechanism for existing overlaps (S27 keeps detecting
  them). The messages of the reused 409 exceptions still say "end it before…".
- **Other movement features:** partial secondment and Work Schedule; supervisory assignment.
- **Automation:** expiry automation and 7-day alerts.
- **Reports and outputs:** reports and exports.
