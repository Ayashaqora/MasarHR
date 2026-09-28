# MASARHR — S14 — Transfer Foundation

Version 1.0 — Architecture/Dependency Reconstruction — Date: 2026-09-27

## 0. Provenance / ADR-S14-001, ADR-S14-002

The exact historical title/boundary of S14 is not asserted by Architecture Authority and is not
recovered from repository evidence — no file in this repository states a historical S14 title.

**ADR-S14-001 — RECONSTRUCTED / CONDITIONALLY AUTHORIZED, THEN BLOCKED.** Selecting **Transfer
Foundation** as the S14 domain, classified as an **Architecture/Dependency Reconstruction**. S12's
own frozen specification (`docs/full-secondment-foundation-specification.md` §1.4/§7.3/§26)
identified Transfer as the sole remaining candidate whose every *structural* prerequisite (S07 unit
hierarchy, S08 scope, S09 Employment Relationship, S11 Placement, S12 Full Secondment) was already
shipped, but whose own decision rule — store ONLY نوع القرار (the decision type) for a transfer
event — required at least one authoritative `ref.decision_types` value that did not yet exist.
`ref.decision_types` had carried zero rows since S05, by deliberate design (**DEFINED STRUCTURE /
VALUES DEFERRED**). ADR-S14-001 authorized Transfer's full implementation *conditional on* that
value being supplied by a future authorization, and named its binding requirements in full: an
explicit `TransferEmployee` command; one atomic transfer combining S11 placement transition and S12
secondment closure; historical preservation (no destructive rewrite of prior periods); no persisted
`current_workplace`/similar column; a mandatory, explicit effective date; temporal/backdated/
future-dated validation; source *and* destination organizational-scope authorization; TOCTOU
protection; real PostgreSQL concurrency tests; S04 audit coverage; no generic `PATCH`; no hard
delete; no S15 work; full regression; and an adversarial review before git finalization. Discovery
of the missing decision-type value (re-verified live against the migrated database — zero rows,
zero consumers) produced a **STOP → REPORT** with verdict "S14 BLOCKED — AUTHORITATIVE TRANSFER
DECISION TYPE VALUE REQUIRED," with zero file changes to the governed repository.

**ADR-S14-002 — "MASARHR — S14 BLOCKER RESOLUTION + RESUME AUTHORIZATION" — BLOCKER RESOLVED,
S14 RESUMED.** Architecture Authority supplied the single authoritative value: `code = TRANSFER`,
`name_ar = نقل`, `status = ACTIVE`. No Transfer sub-type (نقل داخلي/نقل خارجي/نقل مؤقت/نقل دائم,
or any `TRANSFER_*` variant) is authorized — exactly one `ref.decision_types` row represents the
transfer concept. The decision-semantics rule from ADR-S14-001 is restated unchanged: store ONLY
نوع القرار; no decision-document metadata (decision number, document/decision date, decision
subject, decision description) is stored anywhere in this stage — `ref.decision_types` and this
stage's own domain model carry no column for any of those. The new catalog row is added via the
smallest architecture-compliant mechanism — this codebase's own established direct-`DB::table()`-
insert seed-migration pattern (§16), not a new command invocation inside a migration and not a new
catalog-editing surface. ADR-S14-002 required a baseline re-verification before any modification
(performed; exact match against the last authorized S13 commit/tag) and then resumed ADR-S14-001
"from the specification/discovery boundary," with every one of its original requirements still
binding in full. S15 is explicitly named **LOCKED** — no S15 work of any kind under this or any
future resumption of this authorization chain until a separate, later authorization opens it.

## 1. Dependency recovery

### 1.1 Candidate re-examined

Per ADR-S14-001/ADR-S14-002, the only candidate under consideration in this stage is **Transfer**
itself — the blocker that stopped it in S12's dependency recovery (§1.4 there) and again at this
stage's own first pass is the subject of ADR-S14-002's resolution, not a re-opened field of
competing candidates. Every other candidate S12's own comparison table found blocked
(Job/Professional History, Employment Category/Classification History, Work Schedule Foundation,
Partial Secondment/Allocation, Contract Lifecycle, Qualification, Supervisory Assignment, Leave,
Migration/Excel Import, Reporting, Data Quality/Conflict Resolution) remains blocked by the
identical evidence — none of their own gaps (empty catalogs with no populating command, undefined
weekday/allocation semantics, no supplied rules at all) is touched by ADR-S14-002, which supplies
exactly one `ref.decision_types` value and nothing else.

### 1.2 Reference-catalog and prerequisite readiness (re-checked directly against the live database)

Re-verified live via `php artisan tinker`/`psql`, not carried forward from S12's report, immediately
before implementation began (ADR-S14-002's own explicit "re-verify the baseline" instruction):

| Table | Rows | Populating command exists? |
|---|---|---|
| `ref.decision_types` | 0 (pre-ADR-S14-002) → 1 after this stage's seed migration | Yes (`CreateDecisionType`) — the seed migration bypasses it deliberately, §16 |
| `org.organizational_units` | populated by prior stages' own tests/fixtures; command exists (S07) | Yes |
| `hr.organizational_placement_periods` | populated by S11's own command | `RecordOrganizationalPlacementPeriod` (S11, reused unmodified) |
| `hr.full_secondment_periods` | populated by S12's own command | `StartFullSecondment`/`EndFullSecondment` (S12, reused unmodified) |

### 1.3 Why Transfer is now unblocked

S12's own §1.4 finding is quoted here, not repeated in substance: implementing Transfer without an
authoritative decision-type value would have required either inventing one with no authoritative
source, or silently omitting a field ADR-S14-001 explicitly requires — both excluded by this
codebase's standing "never invent values" discipline. ADR-S14-002 removes that gap by supplying the
value directly (§0). No other prerequisite changed: S07/S08/S09/S11/S12 remain shipped, tested, and
untouched by this stage, exactly as S12's own dependency recovery already confirmed for its own
prerequisites.

### 1.4 Decision-priority checklist (mirrors S12 §1.6's structure)

1. **Already fully specified by frozen rules?** Yes, now that ADR-S14-002 supplies the missing
   value — ADR-S14-001's own requirement list (§0) is a complete, unambiguous rule set; nothing is
   left for this stage to invent.
2. **Prerequisites already implemented?** Yes — S07, S08, S09, S11, S12 are all shipped, tested,
   and unmodified by this stage.
3. **Unlocks multiple downstream domains?** Named in S12's own §26 handoff: Supervisory Assignment
   remains blocked by its own `ref.supervisory_titles`/`ref.supervisory_statuses` gaps regardless of
   this stage; no downstream domain is unlocked by Transfer specifically beyond closing S12's own
   named handoff item 2.
4. **Implementable without inventing missing catalogs/policy?** Yes, now — exactly one
   `ref.decision_types` row, supplied verbatim by ADR-S14-002, no other catalog touched.
5. **Preserves a coherent intermediate architecture?** Yes — no new persistence table (§16); the
   existing S11 placement stream and S12 secondment stream absorb this stage's entire effect,
   exactly the "additive, non-conflicting" shape S11's own §5.1 anticipated for S12 and S12's own
   §1.5 realized.

## 2. Candidate comparison

See §1 above; not duplicated. Unlike S09–S12's dependency recoveries, this stage compares no
competing candidates — ADR-S14-001/ADR-S14-002 jointly name Transfer as the sole subject.

## 3. Dependency DAG

```
S07 (org units + CRUD) ---\
S08 (RBAC WHAT + scope WHERE) --\
S09 (Employment Relationship) ----+--> S11 (Placement: original workplace) --\
                                   |                                          \
                                   +--> S12 (Full Secondment: actual workplace) +--> S14 (Transfer:
                                   |                                          /       moves original
S05 (ref.decision_types structure, values deferred) --> ADR-S14-002 --------/        placement,
                                                          (supplies TRANSFER/نقل)     closes active
                                                                                       secondment as
                                                                                       consequence)
                                                                                           |
                                                                                           v
                                                                            S15 — explicitly LOCKED
                                                                            by ADR-S14-002; no work
                                                                            of any kind this stage.

Still blocked, independent of this DAG (empty ref.* catalogs, no populating command):
  Job/Professional History, Employment Category, Contract Lifecycle, Qualification, Leave

Still blocked (undefined semantics, authorization instructs STOP rather than invent):
  Work Schedule Foundation, Partial Secondment/Allocation (gated behind it)

Still blocked (Supervisory Assignment's own additional gaps, untouched by ADR-S14-002):
  ref.supervisory_titles (empty), ref.supervisory_statuses (table does not exist)

Not automatic (unchanged since S09-S12):
  Migration/Excel Import, Reporting, Data Quality/Conflict Resolution
```

## 4. Why selected

See §0–§1. Transfer is the single subject of ADR-S14-001/ADR-S14-002; its sole blocker (a missing
`ref.decision_types` value) is resolved, and every structural prerequisite has been shipped since
S12.

## 5. Purpose

Give an **Employment Relationship** (S09) a single, atomic, auditable action — **Transfer** — that
moves its S11 original organizational placement to a destination unit as of one mandatory effective
date, formally recorded against the authoritative نقل (TRANSFER) decision type, and — as a
consequence of the same atomic operation, never a separate step the caller must remember — closes
an active S12 full secondment, if one exists, at that same date. Historical periods (S10 status,
S11 placement, S12 secondment) are never rewritten, only closed and superseded, exactly as every
prior temporal stream in this codebase already behaves.

## 6. Scope

Builds exactly one new application command — `TransferEmployee` — reusing S11's
`RecordOrganizationalPlacementPeriod` and S12's `EndFullSecondment` in-process for its two effects
(§13/§15); one new domain value object (`TransferResult`); one new domain exception
(`InvalidTransferDecisionTypeException`); one new RBAC permission
(`hr.employment_relationships.transfer`); one new API route; and exactly one new `ref.decision_types`
row (`TRANSFER`/`نقل`), seeded via migration per ADR-S14-002. Nothing else.

## 7. Non-goals (hard boundary)

Does **not** implement: any Transfer sub-type (نقل داخلي/نقل خارجي/نقل مؤقت/نقل دائم, or any
`TRANSFER_*` code — ADR-S14-002 explicit prohibition); decision-document metadata storage (decision
number, document/decision date, decision subject, decision description — ADR-S14-002 explicit
prohibition, "store ONLY نوع القرار"); Partial Secondment/Allocation, Supervisory Assignment, Work
Schedule, Contract Lifecycle, Job/Professional History, Employment Category History, Qualification,
Leave, Migration/Excel import, reporting datasets, frontend (backend/domain/API only, matching every
prior stage); any S15 work of any kind (ADR-S14-002: S15 is **LOCKED**). No modification to
S05/S07/S08/S09/S10/S11/S12 migrations, models, or commands. No new persistence table (§16). No
generic `PATCH`. No hard delete. `_to_delete/` is never touched.

### 7.1 No dedicated `hr.transfers` (or similarly named) table — disclosed, deliberate

A transfer is not, itself, a new fact this codebase stores anywhere beyond the S11 placement row it
writes, the S12 period it may close, and its own S04 audit entry (persistence-design **Option B**,
chosen over a dedicated event/history table because: the existing `OrganizationalPlacementPeriod`/
`FullSecondmentPeriod` streams are already the exact shape needed to represent a transfer's effects;
the established `RecordEmploymentStatusPeriod` → `EndEmploymentRelationship` in-process-consequence
precedent already proves this codebase does not need a new table merely to record that "command X
caused effect Y on stream Z"; and inventing a table here would be the same kind of speculative
structure S11's own §5.1 and S12's own §7.1 already declined). `decision_type_id` is a
`TransferEmployee` command input and an audit field only — it is never a column on
`hr.organizational_placement_periods` or `hr.full_secondment_periods` (§8/§16). Enforced as an
executable regression guard: `ScopeBoundaryTest::test_no_dedicated_transfer_table_was_created`.

### 7.2 Exactly one command — disclosed

`TransferEmployee` is the only S14 command. No `RecordTransferPeriod`, `CreateTransfer`, or
`TransferPerson` command exists. Enforced as an executable regression guard:
`ScopeBoundaryTest::test_transfer_employee_is_the_only_s14_command`.

## 8. Ownership and domain model

`TransferEmployee` operates entirely on the existing `EmploymentRelationship` aggregate (S09) — it
is not itself a new aggregate root or a new child entity. Its outcome is represented by
`TransferResult`, an immutable, non-persisted domain value object (mirrors
`App\Modules\HumanResources\Domain\ActualWorkplace`'s own shape exactly):

- `placement(): OrganizationalPlacementPeriod` — the new S11 placement period the transfer opened.
  Always present; a transfer always moves the original placement.
- `closedSecondment(): ?FullSecondmentPeriod` — the S12 full secondment period the transfer closed
  as its consequence. `null` when the relationship had no active secondment to close.

No new Eloquent model backs a "transfer" resource (§7.1). No `version` column, no `current_workplace`
column, no `decision_type_id` column, is added to any existing table (§7.1/§16).

## 9. References consumed

Consumes exactly one `ref.decision_types` row — `code = TRANSFER`, seeded by this stage's own
migration (§16) — and `org.organizational_units` (S07), exactly as it exists, for the destination
unit. Reads, but never writes, `hr.organizational_placement_periods` (S11) and
`hr.full_secondment_periods` (S12).

## 10. Temporal model and invariants

`TransferEmployee::handle(EmploymentRelationship $relationship, OrganizationalUnit $destination,
string $effectiveFrom, DecisionType $decisionType): TransferResult`:

1. The Employment Relationship is re-fetched fresh with `lockForUpdate()` as the very first
   statement — never trusted from whatever the caller passed in — exactly mirroring
   `CreateEmploymentRelationship`'s/`RecordEmploymentStatusPeriod`'s/
   `RecordOrganizationalPlacementPeriod`'s own established discipline. This also serializes this
   command against a concurrent `EndEmploymentRelationship`/`RecordOrganizationalPlacementPeriod`/
   `StartFullSecondment`/`EndFullSecondment`/`TransferEmployee` call on the same relationship — no
   new lock ordering is introduced (§17).
2. If the relationship is already ended (`end_knowledge_state = 'KNOWN'`), reject with
   `EmploymentRelationshipAlreadyEndedException` (409) — mirrors S11's/S12's identical
   already-ended-relationship rule for a *starting* movement.
3. `decision_type_id` is re-resolved fresh against `ref.decision_types` inside the same transaction
   — never trusted from whatever the controller resolved moments earlier, for the identical
   anti-TOCTOU reason `CreateEmploymentRelationship` re-fetches `EmploymentType` fresh. It must
   resolve to a row whose `code` is exactly `TRANSFER` and whose `is_active` is `true`; otherwise
   `InvalidTransferDecisionTypeException` (422, `errors.decision_type_id`). The stable technical
   discriminator checked is `code`, never `name_ar`/`name_en` display text (ADR-S14-002 explicit
   instruction).
4. S11's own `RecordOrganizationalPlacementPeriod::handle()` is called in-process, inside this same
   transaction — never through a second `AuditedCommandExecutor::run()`, which would double-audit
   and attempt an unsupported nested top-level transaction (§13/§15, mirroring
   `RecordEmploymentStatusPeriod`'s own established precedent for wiring one command's approved
   consequence onto a different aggregate stream). It auto-closes the currently open placement
   period (if any) at `$effectiveFrom` and opens the new destination placement — S11's own date
   validation (`InvalidPlacementPeriodDateException`, already mapped to 422 `errors.effective_from`)
   is left to propagate unmodified.
5. If — and only if — a full secondment is currently active for this relationship (an open
   `hr.full_secondment_periods` row), S12's own `EndFullSecondment::handle()` is called in-process,
   at the same `$effectiveFrom`, closing it as a consequence, not a primary effect (§15). No second,
   separate effective date is invented for this consequence — mirrors
   `RecordEmploymentStatusPeriod`'s identical choice of reusing its own `effective_from` as
   `EndEmploymentRelationship`'s `effective_to`. S12's own date validation
   (`InvalidFullSecondmentEndDateException`, already mapped to 422 `errors.effective_to`) is left to
   propagate unmodified.
6. Both effects commit atomically together with the S04 audit entry, inside
   `AuditedCommandExecutor::run()`'s own single transaction (§19) — a database error in either the
   placement write or the secondment close rolls back the whole transfer, never a partial one.

No prior period (S10 status, S11 placement, S12 secondment) is ever rewritten — only closed
(`effective_to` set) and superseded by a new open row, exactly the append-only shape every temporal
stream in this codebase already uses.

## 11. Domain exceptions

| Exception | HTTP | Source |
|---|---|---|
| `EmploymentRelationshipAlreadyEndedException` (S09, reused) | 409 | Transferring an already-`KNOWN`-ended relationship (§10 step 2). |
| `InvalidTransferDecisionTypeException` (new) | 422 (`errors.decision_type_id`) | `decision_type_id` does not resolve to the active `TRANSFER` row (§10 step 3). |
| `InvalidPlacementPeriodDateException` (S11, reused, unmodified) | 422 (`errors.effective_from`) | `effective_from` not strictly after the relationship's own `effective_from` or the currently open placement's own `effective_from` (§10 step 4). |
| `InvalidFullSecondmentEndDateException` (S12, reused, unmodified) | 422 (`errors.effective_to`) | `effective_from` not strictly after the open secondment period's own `effective_from`, only reachable when a secondment is actually being closed (§10 step 5). |

No new `StaleVersionException`-shaped class — `TransferEmployee` performs no client-versioned
update of its own; the underlying S11/S12 writes it delegates to carry no `version` column, exactly
as S11's/S12's own specifications already establish.

## 12. Authorization — RBAC (`WHAT`) + S08 scope (`WHERE`), triple-target, composed

One new permission code added to the existing `HumanResourcesPermissionCatalog`:
`hr.employment_relationships.transfer` — named on the existing `hr.employment_relationships.*`
family (`create`/`end`/`transfer`), not a new `hr.transfers.*` family, because `TransferEmployee` is
a lifecycle action on the Employment Relationship aggregate itself, writing no new resource of its
own (§7.1/§8), not the creation of a distinct "transfer" resource.

Every route additionally carries the existing `permission:` middleware (coarse `WHAT` gate,
unchanged convention).

### 12.1 Triple-scope resolution — extending S12's own §12.1 "critical adversarial point" one target further

S12's own §12.1 resolved the dual-target question for a *movement* command (source + destination).
Transfer is a movement with, potentially, **three** visibly affected rosters, not two: the
destination unit (always — a transfer always moves the original placement there); the current
placement's own "source" unit (if one is recorded — its roster is losing someone who was visibly
theirs); and an active full secondment's own unit (if one will be closed as this transfer's
consequence — that unit's roster is also losing someone who was visibly, if temporarily, theirs).
S12's own principle — "every roster this action visibly changes is checked" — is applied one target
further than S12's own operation needed, since Transfer alone (not Full Secondment start/end) can
simultaneously change all three. The controller calls
`ScopedAuthorizationChecker::authorize($principal, PERM, $unit)` up to **three** times: once for the
destination (always), once for the source placement unit (if one exists), and once for the active
secondment unit (if one will be closed). All applicable calls must return `true`.
`ScopedAuthorizationChecker` itself is never modified — it is called up to three times with three
different targets, exactly as it was designed to be called.

A `false` result from any of the (up to three) scope checks returns the same generic `403` the
codebase already uses everywhere else, with no distinguishing detail leaked between which specific
target failed.

### 12.2 Triple-scope checks are made inside the relationship's own row lock — applied from the start, not corrected after the fact

S12's own §12.2 records a post-implementation adversarial-review correction: an initial S12
implementation read the "source" unit and checked scope against it *before* any row was locked, a
genuine TOCTOU gap closed only after the fact. `TransferController::store()` applies that same
lock-first discipline from its very first line, not as a later correction: it opens one
`DB::transaction()`, locks the `EmploymentRelationship` row **first**
(`EmploymentRelationship::query()->lockForUpdate()`), and only then reads the current placement
unit and the active secondment unit and makes all (up to three) scope decisions — closing the same
window S12's own correction closed, from the start. `TransferEmployee` re-acquires the identical row
lock inside `AuditedCommandExecutor`'s own (nested/savepoint) transaction immediately afterward;
PostgreSQL row locks are reentrant within one transaction, so this is not a double-lock hazard.

**Destination-side caveat, corrected by post-implementation adversarial review (§28):** S12's own
§12.2 states its destination-side check "needs no equivalent protection" because it is a
client-supplied, request-scoped value. That reasoning is accurate for the destination unit's
*identity* (its id cannot change mid-request), but not for its `is_active` flag —
`ScopedAuthorizationChecker::authorize()` reads `$target->is_active` directly off whichever
Eloquent object it is given, and both `FullSecondmentPeriodController::store()` (S12) and
`TransferController::store()` (S14) fetch the destination `OrganizationalUnit` **before** their own
`DB::transaction()` opens, never re-fetching or locking it inside the transaction. A concurrent
`DeactivateOrganizationalUnit` call between that fetch and the `authorize()` call is therefore not
caught — a narrow window, present unchanged since S12, that this stage inherits and extends rather
than introduces or corrects. Fixing it would mean re-fetching (and, to fully close the window,
locking) the destination unit inside the transaction in both S12's and this stage's controllers;
S12 is frozen and not modified by this stage (§7), so this stage leaves the inherited behavior as
is and discloses it accurately here rather than repeating S12's own inaccurate justification. This
is a disclosed limitation, not a blocking defect: it requires a genuinely concurrent
deactivation racing a transfer within the same narrow window S12's own identical gap already
accepted, and every *other* scope/permission check in §12.1 remains fully TOCTOU-safe.

## 13. Commands

- `TransferEmployee::handle(EmploymentRelationship $relationship, OrganizationalUnit $destination, string $effectiveFrom, DecisionType $decisionType): TransferResult`

Explicitly **not** built: any command named `RecordTransferPeriod`/`CreateTransfer`/`TransferPerson`
(§7.2); any command to edit or delete a past transfer's own effects directly (no evidence
authorizes retroactive correction, mirrors S09's/S11's/S12's identical choice — correcting a
transfer's placement or secondment history, if ever authorized, would run through S11's/S12's own
existing commands, not a new one this stage invents).

## 14. Queries

None new. A transfer's effects are read entirely through S11's/S12's own existing read surfaces —
`ListOrganizationalPlacementPeriodsForRelationship`, `ListFullSecondmentPeriodsForRelationship`,
`ResolveActualWorkplaceForRelationship` — and S04's own audit trail. Inventing a
`ListTransfersForRelationship`-shaped query would imply a "transfer" resource this stage's own
persistence-design decision (§7.1/§16) deliberately does not create.

## 15. Cross-stream consequences

`TransferEmployee` writes to `hr.organizational_placement_periods` (S11, via
`RecordOrganizationalPlacementPeriod::handle()`, in-process) and, conditionally, to
`hr.full_secondment_periods` (S12, via `EndFullSecondment::handle()`, in-process) — both inside the
same transaction as its own S04 audit entry, exactly mirroring `RecordEmploymentStatusPeriod`'s own
established precedent for wiring one command's approved consequence onto a different aggregate
stream ("call the existing command's own `handle()` in-process, inside this same transaction —
never through a second `AuditedCommandExecutor::run()`, which would double-audit and attempt a
transaction the executor does not support nesting"). No write reaches
`hr.employment_relationships` (S09) or `hr.employment_status_periods` (S10) — a transfer changes
*where* someone works, never *whether* they are employed or their status-history stream.

## 16. PostgreSQL schema

**No new table.** Persistence-design **Option B** (§7.1): a transfer is represented entirely by the
S11 placement row it writes, the S12 period it may close, and its own S04 audit entry.

Two new migrations, both dated after every S13 migration, following this codebase's own established
seed-migration convention of never editing a released migration:

- `2026_10_04_000001_seed_ref_decision_types_transfer` — inserts exactly one `ref.decision_types`
  row directly via `DB::table()->insert()` (`code = 'TRANSFER'`, `name_ar = 'نقل'`, `name_en =
  null`, `is_active = true`, `display_order = 1`, `version = 1`), never through
  `CreateDecisionType` — mirrors the established precedent that baseline reference-value seeding in
  this codebase is always a direct insert, regardless of whether a full CRUD command already exists
  for that catalog (the original S05 baseline seed inserted `ref.genders`/`ref.marital_statuses`
  directly despite `CreateGender`/`CreateMaritalStatus` already existing at that time).
  `code = 'TRANSFER'` (uppercase) is exactly the technical identifier ADR-S14-002 assigns, a
  deliberate departure from this codebase's otherwise-universal lowercase `snake_case` `code`
  convention (`male`, `permanent`, `grade_1`, ...) — disclosed here, not silently normalized,
  because it is a stable machine identifier Architecture Authority explicitly assigned. No
  `ref.decision_types` DB-level format constraint exists to violate (only `UNIQUE` on `code`); the
  application-layer `regex:/^[a-z0-9_]+$/` validation in `DecisionTypeController::store()` is
  bypassed entirely by the direct insert, exactly as every prior direct-insert seed migration
  already does.
- `2026_10_04_000002_seed_security_transfer_permission` — inserts the one
  `hr.employment_relationships.transfer` row into `security.permissions`, following the exact
  precedent of `2026_10_02_000002_seed_security_full_secondment_period_permissions`. Default deny is
  unaffected — no role is granted this permission by this migration.

No trigger, no new `CHECK`/`EXCLUDE` constraint, no new FK — this stage adds nothing to the schema
graph beyond one catalog row and one permission row; every temporal invariant a transfer must
respect is already enforced by S11's/S12's own existing constraints, exercised via their own
existing commands.

## 17. Concurrency

- Two concurrent `TransferEmployee` calls for the same Employment Relationship: the relationship's
  own row lock serializes them (§10 step 1) — the second, once unblocked, observes the first's own
  newly-opened placement period as "the" open period to close, never a stale pre-race snapshot
  (proved by a real two-connection race test, §24).
- A `TransferEmployee` call racing a concurrent `EndEmploymentRelationship` (S09),
  `RecordOrganizationalPlacementPeriod` (S11), `StartFullSecondment`, or `EndFullSecondment` (S12)
  call on the same relationship: all five commands lock the same relationship row, so all serialize
  against each other — no new lock ordering is introduced, exactly as S12's own §17 already
  concludes for its own four-way set, extended one command further.
- `decision_type_id`'s own TOCTOU risk (a concurrent deactivation of `TRANSFER` between the
  controller's lookup and this command's own write) is closed by re-resolving it fresh inside the
  locked transaction (§10 step 3) — the identical discipline `CreateEmploymentRelationship` already
  applies to `EmploymentType`.

## 18. Authorization/scope test matrix

RBAC-absent-deny (no permission at all → denied regardless of scope); permission present, zero
scope grants → denied; permission + `UNIT` grant covering destination only (source and/or secondment
unit out of scope) → denied; permission + `UNIT` grant covering source only (destination out of
scope) → denied; permission + `UNIT` grant covering destination and source only, active secondment
unit out of scope → denied; permission + `UNIT` grant covering all three applicable units →
allowed; `GLOBAL` grant → allowed for any units; destination unit inactive → denied even with
otherwise-sufficient permission and scope; transferring with no prior placement and no active
secondment recorded yet (no source/secondment unit to check) → destination-scope-alone governs.

## 19. Audit

Every mutation runs through the existing `AuditedCommandExecutor`. `TransferEmployee`: action
`hr.transfer.execute`, target type `hr_employment_relationship`, `changes` allowlist
`employment_relationship_id`, `decision_type_id`, `organizational_placement_period_id`,
`organizational_unit_id`, `effective_from`, `closed_full_secondment_period_id` (always present as a
key, explicitly `null` when no secondment was active — disclosing that the consequence was
considered, not merely omitting the key). No National ID or other Person-identifying attribute is
placed in audit metadata. Exactly one audit entry per `TransferEmployee` call, even though it writes
to two different tables (S11 placement, S12 secondment) — both writes and the one audit entry
commit atomically together inside `AuditedCommandExecutor`'s single transaction, never two separate
audited operations for one transfer.

## 20. API

```
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/transfer
```

Body: `organizational_unit_id` (uuid, required), `effective_from` (date, required),
`decision_type_id` (uuid, required). An explicit action route, not a generic `PATCH` (mirrors S12's
own §21 "no generic PATCH" convention) — no new list/show route exists because Transfer writes no
resource of its own to read back (§7.1/§14); its effects are read via the existing
placement-periods/full-secondment-periods/actual-workplace routes S11/S12 already expose. 404 if the
destination unit does not exist, or if `decision_type_id` does not resolve to any
`ref.decision_types` row at all (existence only — the *active-TRANSFER* business check is a 422 via
`InvalidTransferDecisionTypeException`, §11). Standard `401`/`403`/`404`/`409`/`422`; no
`PATCH`/`DELETE` on this route. IDOR protection: `{employmentRelationship}` must belong to the given
Person's own resource path, mirroring S09/S10/S11/S12's existing checks exactly.

Response: the `TransferResource` shape — `organizational_placement_period` (the newly opened S11
period, in `OrganizationalPlacementPeriodResource`'s own field shape) and
`closed_full_secondment_period` (the closed S12 period in `FullSecondmentPeriodResource`'s own field
shape, or `null`).

## 21. Migration compatibility

No import pipeline is built. A relationship transferred before this stage existed simply has no
transfer-specific audit trail before its own `effective_from` — its S11/S12 history remains exactly
what S11/S12 already recorded, never a fabricated retroactive `hr.transfer.execute` audit entry.

### 21.1 Migration-rollback ordering

Both new S14 migrations are pure seed-data migrations (insert/delete by `code`) with no FK of their
own to any table — unlike S11's/S12's own `19.1`/`21.1` ratchets, no new `dropXSchemaObjects()`
ordering constraint against S07/S09 is introduced. A dedicated `dropTransferSchemaObjects()` helper
un-records both S14 migrations (matched by their shared `2026_10_04%` date prefix) so
`MigrationLifecycleTest`'s plain `migrate --force` correctly reapplies them after a test drops them,
mirroring the one-helper-per-stage convention every prior stage already established. Because the S14
permission row carries no table-based FK forcing it into any *other* stage's own rollback chain, it
is deliberately left in place across an S09–S12 rollback/reapply cycle (unlike the four S09–S12
tables' own permission-count assertions, this row's presence is orthogonal to whichever underlying
table migration is being rolled back) — reflected in the S09–S12 rollback tests' own
`module = 'human_resources'` permission-count assertions, each incremented by exactly one to account
for this stage's permanently-present row.

## 22. Reporting implications

None implemented. A future Reporting stage answering "who transferred, when, and by whose decision"
reads S04's own audit trail (`hr.transfer.execute` entries) and S11's own placement-period history —
no new dataset, table, or endpoint is built here.

## 23. Frontend boundary

None — backend/domain/API only, matching every prior stage.

## 24. Tests (minimum)

Transfer closing the current placement and opening the destination (with and without a prior
placement); transfer closing an active full secondment as a consequence (and leaving the secondment
stream untouched when none is active); `ResolveActualWorkplaceForRelationship` resolving to the new
destination after a transfer; rejection against an already-ended relationship; rejection of a
decision type that is not the active `TRANSFER` row (wrong `code`, inactive); rejection of a
backdated `effective_from`; full API surface (`201`, `404` for an unknown unit/decision type, `422`
for missing/invalid fields, IDOR protection, no `PATCH`/`DELETE` route); the full §18 triple-scope
authorization matrix, including every single- and two-of-three forbidden combination
(destination-only, source-only, secondment-unit-only, destination-and-source-but-not-secondment,
destination-and-secondment-but-not-source, source-and-secondment-but-not-destination); exactly one
audit entry per transfer with no PII, and no
double-audit despite writing to two tables; a real two-connection concurrency race proving two
racing `TransferEmployee` calls for the same relationship serialize via the relationship row lock,
with the second observing the first's own committed period, not a stale snapshot; the S13/S14
seed-migration's own content (`TRANSFER`/`نقل`/active/version 1, database-level rejection of a
duplicate `code`, no sub-type seeded); migration rollback/reapply (§21.1); regression guards for
§7.1/§7.2 (no dedicated transfer table, exactly one S14 command); full S01–S14 regression.

## 25. Adversarial review focus

Overlap between a transfer's own placement write and a concurrent S11/S12 write; write skew; stale
route-bound relationship/destination/decision-type; permission-present-scope-absent bypass on any
of the (up to) three targets specifically; scope-present-permission-absent bypass; inactive-unit
bypass; a decision type whose `code` is not `TRANSFER` or whose `is_active` is `false` being
accepted; a Transfer sub-type being silently accepted or seeded; decision-document metadata
(decision number/date/subject/description) being silently stored anywhere; IDOR; audit omission or
double-audit; PII leakage; hard-delete; backdated/future-date corruption; cross-stream inconsistency
with S09's own end-state, S11's own placement stream, and S12's own secondment stream after a
transfer; a partial transfer (placement written but secondment left open, or vice versa) surviving a
mid-transaction failure; S15 leakage of any kind (explicitly **LOCKED** by ADR-S14-002); cross-project
leakage; empty-catalog assumption (verified live immediately before implementation, §1.2, not
assumed from an earlier report).

## 26. S15+ handoff (disclosed, not blocking)

S15 is explicitly **LOCKED** by ADR-S14-002 — no S15 work of any kind is performed or planned by
this stage, and none of the items below authorizes any. They are named only because S12's own §26
already named them and this stage's own completion is the natural point to note whether anything
changed:

1. Partial Secondment/Allocation remains gated behind Work Schedule Foundation, itself gated behind
   materially undefined weekday/allocation semantics — unchanged since S10's/S12's own identical
   findings.
2. Supervisory Assignment remains gated behind `ref.supervisory_titles` (empty, no populating
   command) and `ref.supervisory_statuses` (table does not exist) — its own additional
   `ref.decision_types`-shaped gap (S12 §26 item 3) is not resolved by this stage, since
   ADR-S14-002 supplies only the single `TRANSFER` value, nothing for a supervisory-assignment
   decision.
3. Job/Professional History, Employment Category History, Qualification, Contract Lifecycle, Leave
   all remain blocked by an empty catalog with no populating command — unchanged since S09–S12's own
   identical findings.
4. Migration/Import, Reporting, Data Quality/Conflict Resolution — untouched, per every prior
   stage's own identical deferral and the absence of any supplied rules for the last.
5. S12's own §7.3 disclosed limitation (ending an Employment Relationship does not proactively close
   an open secondment row) is now partially, but not fully, addressed: `TransferEmployee` closes an
   active secondment as an explicit consequence of a *transfer*, but `EndEmploymentRelationship`
   (S09, unmodified by this stage) still does not — the gap S12 named remains exactly where S12 left
   it for the *ending* case specifically; `ResolveActualWorkplaceForRelationship`'s own
   already-ended-relationship-always-nulls-out behavior (S12 §7.3/§12) remains the mitigation.

None of these represents an unresolved *consequential* policy for S14's own boundary; each is a
named, explicit handoff — and none opens S15 work under this authorization.

## 27. Internal review (P01–P25, mirrors S12 §27's structure)

- **P01 provenance truthful** — §0: no historical title claimed; both ADR-S14-001's original
  conditional authorization and ADR-S14-002's resolution are labelled and dated accurately.
- **P02 candidate comparison complete** — §1: the single named candidate re-checked against live
  repository/database evidence; every other candidate's own blocker re-confirmed unchanged.
- **P03 dependency DAG valid** — §3.
- **P04 stage boundary minimal** — §6/§7: one command, zero new queries, one value object, one
  exception, one permission, one catalog row; explicitly not any Transfer sub-type or S15 concept.
- **P05 prerequisites complete** — §1.3: S07/S08/S09/S11/S12 all shipped and unmodified.
- **P06 no invented catalog values** — §0/§9/§16: the one `ref.decision_types` value inserted is
  exactly the value ADR-S14-002 supplies, not one this stage chose.
- **P07 aggregate ownership** — §8: no new aggregate root; `TransferEmployee` operates on the
  existing `EmploymentRelationship` aggregate exclusively.
- **P08 temporal semantics** — §10: half-open `[from, to)` periods preserved via S11's/S12's own
  reused commands; no period is ever rewritten, only closed and superseded.
- **P09 PostgreSQL invariants** — §16: no new constraint is added because none is needed — every
  invariant a transfer must respect is already enforced by S11's/S12's own existing schema,
  exercised through their own existing commands.
- **P10 future/backdated correctness** — §10 steps 4/5; §11 exception table.
- **P11 concurrency** — §17, real multi-connection race test (§24).
- **P12 cross-stream consequences** — §15: S11 placement write and conditional S12 secondment
  close, both in-process, both disclosed.
- **P13 status interaction** — unchanged from S12's own §7.3/§14: an ended relationship is rejected
  outright by `TransferEmployee` (§10 step 2), never silently transferred.
- **P14 placement/secondment interaction** — §10 steps 4/5: both effects fully specified, including
  the "consequence, not primary effect" framing for the secondment close.
- **P15 RBAC** — §12: one new permission code, existing `permission:` middleware.
- **P16 organizational scope** — §12.1: triple-target composition explicitly resolved, extending
  S12's own §12.1 resolution one target further, not deferred; §12.2: S12's own post-implementation
  correction (lock before scope decision) applied from the start, not discovered after the fact.
- **P17 source/destination/consequence-target scope** — §12.1 (this *is* S12's own §26-anticipated
  movement case, extended by one target).
- **P18 IDOR** — §20: same nested-route check as S09/S10/S11/S12.
- **P19 audit** — §19: `AuditedCommandExecutor`, allowlisted metadata, exactly one entry per
  transfer despite two underlying writes.
- **P20 PII** — §19: no National ID or Person attribute in metadata.
- **P21 migration compatibility** — §21.1: ordering impact explicitly assessed (none needed beyond
  the new drop helper) rather than assumed.
- **P22 reporting compatibility** — §22: nothing implemented; audit trail and S11 history remain the
  future read surface.
- **P23 no S15 leakage** — §7/§26: S15 explicitly named LOCKED; no S15 concept is touched anywhere
  in this implementation.
- **P24 no cross-project contamination** — no terminology outside this repository's own established
  vocabulary is used anywhere in this document.
- **P25 acceptance testability** — §24: full concrete test list, each traceable to a numbered
  section above.

No consequential issue is unresolved. **PASS — proceeding to implementation.**

## 28. Post-implementation adversarial review and corrections

A full adversarial review was conducted against the completed implementation, covering every item in
§25. Findings:

- **Regression risk, not a defect in this stage's own code (CONFIRMED, corrected):** the new S14
  permission-seed migration (`2026_10_04_000002`) grows `security.permissions`' total
  `module = 'human_resources'` row count from 12 to 13. Four pre-existing hardcoded permission-count
  assertions in `tests/Feature/Database/MigrationLifecycleTest.php` (the S09/S10/S11/S12 rollback/
  reapply tests) and one hardcoded permission-code list in
  `tests/Feature/Security/ApiEndpointsTest.php::test_permissions_index_lists_the_baseline_catalog`
  assumed the pre-S14 count/list and would have failed the first time this stage's migrations ran
  against a shared test database. All five were corrected (§21.1; `hr.employment_relationships.transfer`
  added to the `ApiEndpointsTest` baseline-catalog list) and re-verified passing.
- **Test-coverage gaps closed (proactive, before independent review):** a real two-connection
  concurrency race test for `TransferEmployee` (§17/§24, added to `ConcurrencyTest.php`, mirroring
  S12's own start-versus-end race test), proving two racing transfers for the same relationship
  serialize via the relationship row lock and that the second, once unblocked, observes the first's
  own committed placement period rather than a stale snapshot; and this specification document
  itself (ADR-S14-002's own explicit instruction to update the specification to reference
  ADR-S14-002 as the resolution of the Decision Type blocker).
- **Independent adversarial review** (a reviewer with no involvement in writing this stage's code,
  working strictly from this specification and the completed implementation) was then run against
  every item in §25. Findings:
  - **1 MODERATE — destination-unit `is_active` TOCTOU, inherited from S12, propagated with an
    inaccurate spec justification (CONFIRMED, documentation corrected, code left as inherited):**
    both `FullSecondmentPeriodController::store()` (S12) and `TransferController::store()` (S14)
    fetch the destination `OrganizationalUnit` before their own `DB::transaction()` opens, and
    `ScopedAuthorizationChecker::authorize()` reads `is_active` directly off that object rather than
    re-fetching it under lock — a concurrent deactivation between the fetch and the `authorize()`
    call is not caught. This stage's original §12.2 text repeated S12's own §12.2 claim that the
    destination check "needs no equivalent protection," which is accurate for the destination's
    identity but not for its `is_active` flag. Corrected: §12.2 now discloses the gap accurately
    instead of repeating the inaccurate claim. The underlying behavior is not changed — it is
    identical, pre-existing S12 behavior this stage inherits via the same controller shape, and S12
    is frozen and not modified by this stage (§7); re-fetching/locking the destination unit in both
    controllers would be a cross-stage change outside this authorization's scope. Not blocking: it
    requires a genuinely concurrent deactivation racing a transfer within a narrow window, and no
    *other* scope/permission check has this gap.
  - **1 MINOR — incomplete §18 triple-scope test matrix (CONFIRMED, corrected):** the original test
    suite covered the "one target out of scope" and "all three in scope" cases but not the
    remaining two-of-three combinations (destination+secondment without source,
    source+secondment without destination) or the single-target secondment-only case. Three tests
    were added to `TransferFoundationTest.php` closing all of them; §24 above reflects the completed
    matrix.
  - **Zero BLOCKING findings.** Authorization reachability (all three targets independently
    gated), decision-type re-validation, atomicity (single transaction, no partial-transfer
    survival), exactly-one-audit-entry with no PII, absence of any Transfer sub-type or
    decision-document-metadata storage, IDOR protection, and absence of S15+/cross-project leakage
    were all independently verified with no finding.

Zero **BLOCKING** findings from either review pass. Full S01–S14 regression (1157/1157), including
`ConcurrencyTest.php` in full (24/24, covering the new Transfer race test) and the completed §18
scope matrix, Pint, and the diff-check equivalent (no trailing whitespace, no tabs, every changed
file ends with exactly one trailing newline) all pass after the corrections. **PASS — proceeding to
git finalization.**
