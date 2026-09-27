# MASARHR — S12 — Full Secondment Foundation

Version 1.0 — Architecture/Dependency Reconstruction — Date: 2026-09-27

## 0. Provenance / ADR-S12-001

The exact historical title/boundary of S12 is not asserted by Architecture Authority and is not
recovered from repository evidence — no file in this repository states a historical S12 title.

**ADR-S12-001 — RECONSTRUCTED / APPROVED FOR EXECUTION.** Selecting **Full Secondment Foundation**
as the S12 domain, classified as an **Architecture/Dependency Reconstruction**, not historical
recovery, on the dependency-DAG evidence in §1/§2. Every other candidate the authorization names is
blocked by either an empty reference catalog with no populating command, an empty reference catalog
whose *values* are explicitly deferred by an earlier stage's own authorization, or an explicit
textual gate inside this same authorization document. Full Secondment is the only candidate that
clears every one of §7's five decision-priority criteria.

## 1. Dependency recovery

### 1.1 Candidates investigated

Per the authorization's §6: Job/Professional History, Employment Category/Classification History,
Work Schedule Foundation, Transfer, Full Secondment, Partial Secondment/Allocation, Contract
Lifecycle, Qualification, Supervisory Assignment, Leave, Migration/Excel Import, Reporting, Data
Quality/Conflict Resolution.

### 1.2 Reference-catalog and prerequisite readiness (checked directly against the live database)

Row counts below were read directly from the migrated `masarhr` database via `php artisan tinker`,
not carried forward from an earlier report (authorization §8: "Directly inspect the actual
reference catalogs. Do not rely only on previous reports.") — unchanged since S11's own equivalent
check, confirming no new catalog data or populating command has appeared in the interim.

| Table | Rows | Populating command exists? |
|---|---|---|
| `ref.job_titles` | 0 | No |
| `ref.employment_categories` | 0 | No |
| `ref.contract_types` | 0 | No |
| `ref.qualification_types` | 0 | No |
| `ref.academic_degrees` | 0 | No |
| `ref.supervisory_titles` | 0 | No |
| `ref.supervisory_statuses` | — | **table does not exist at all** |
| `ref.leave_types` | 0 | No |
| `ref.leave_statuses` | 0 | No |
| `ref.decision_types` | 0 | Yes (`CreateDecisionType`) — but see §1.4 |
| `org.organizational_units` | 0 | Yes (S07 full CRUD) — already consumed by S11, not a new gap |

### 1.3 Candidate comparison

| Candidate | Disposition | Evidence |
|---|---|---|
| A. Job/Professional History | Blocked | `ref.job_titles` empty, no populating command anywhere in the repository — a missing-capability gap, not a data-population gap. |
| B. Employment Category/Classification | Blocked | `ref.employment_categories` empty, no populating command. |
| C. Work Schedule Foundation | Blocked | No weekday/allocation-payload semantics are defined anywhere in this repository or in this authorization's own text (§13 itself: "If weekday representation/allocation semantics remain materially undefined: STOP rather than invent them"). Confirmed absent by direct search of every prior specification file — S10's own dependency recovery already found the identical absence ("no groundwork of any kind exists yet"), and nothing has supplied it since. |
| D. Transfer | Blocked — see §1.4 | Every *structural* prerequisite (Placement, S08 scope) is ready, but §12's own decision rule requires storing نوع القرار for a transfer event, and `ref.decision_types` has zero authoritative values, deliberately deferred since S05 (§1.4). |
| **E. Full Secondment** | **Selected** | No `ref.*` catalog is consumed at all; every structural prerequisite (S07, S08, S09, S11) is shipped and unmodified; no decision-type storage is named for this candidate specifically (§14/§15, contrast with §12/§16); the one open architectural question (“actual workplace” representation) is resolvable additively without a second conflicting placement truth (§1.5). |
| F. Partial Secondment/Allocation | Blocked | Authorization's own §15: "Do NOT implement Partial Secondment before its schedule/allocation prerequisites" — gated behind the already-blocked Work Schedule (C). |
| G. Contract Lifecycle | Blocked | `ref.contract_types` empty, no populating command. |
| H. Qualification | Blocked | `ref.qualification_types`/`ref.academic_degrees` empty, no populating command. |
| I. Supervisory Assignment | Blocked (doubly) | `ref.supervisory_titles` empty with no populating command; `ref.supervisory_statuses` does not exist as a table at all; §16 also names نوع القرار as a required concept — the same `ref.decision_types` gap as Transfer. |
| J. Leave | Blocked | `ref.leave_types`/`ref.leave_statuses` empty, no populating command. |
| K. Migration/Excel Import | Blocked | Authorization §25: not automatic; no evidence points to it now, unchanged since S09–S11. |
| L. Reporting | Blocked | Authorization §24: not automatic; unchanged since S09–S11. |
| M. Data Quality/Conflict Resolution | Blocked | No rules of any kind are supplied for this candidate anywhere in the authorization — selecting it would require inventing its entire scope, exactly as S11's dependency recovery already found for its identical absence. |

### 1.4 Why Transfer is blocked despite S11 making it "structurally possible" (authorization §7)

The authorization is explicit that S11 making Transfer *possible* does not make it *selected*
(§7: "S11 makes Transfer possible structurally. That does NOT automatically make Transfer S12.").
Transfer's own section (§12) states its decision rule directly, not merely by cross-reference to
the general §22 rule: *"Decision rule: store ONLY نوع القرار."* This placement — inside §12 itself,
immediately after Transfer's own "must not" list — is read as Architecture Authority declaring a
transfer event a **formal HR decision** requiring `decision_type` storage, not an optional or
deferrable field. `ref.decision_types` currently has **zero rows**, and — critically — this is not
an ordinary empty-but-populatable catalog: the S05 baseline-seed migration's own docblock states
`ref.decision_types` "intentionally receive[s] zero rows in S05 (**DEFINED STRUCTURE / VALUES
DEFERRED**, §8/§5.3)" — i.e., the *structure* (table, `CreateDecisionType`/`ActivateDecisionType`
commands) was deliberately built in S05, but the specific *authoritative values* were deliberately
withheld pending a future authorization that supplies them. No stage's authorization, including
this one, has yet supplied what value(s) نوع القرار should take for a transfer decision (e.g.,
whether the Arabic code is a single "نقل" or several transfer sub-types). This authorization's own
repeated instruction not to invent catalog values (§8: "But NEVER invent values") applies exactly
here: implementing Transfer today would require either inventing a decision-type value with no
authoritative source, or silently omitting a field this same authorization explicitly requires —
both are excluded. This is evidence, not convenience, and is the same *kind* of gap (missing
authoritative values) that has blocked every `ref.*`-dependent candidate since S09's own dependency
recovery — Transfer simply reaches that same wall one field later than the others, after every
other prerequisite is already satisfied. Supervisory Assignment (I) is blocked by the identical
`ref.decision_types` gap, in addition to its own `ref.supervisory_titles`/`ref.supervisory_statuses`
gaps.

### 1.5 Why the "actual workplace" question does not block Full Secondment

§14's closing sentence sets a real bar: *"Do not implement Full Secondment if the architecture
lacks a correct way to represent actual workplace without inventing a conflicting second placement
truth."* S11's own frozen specification (§5.1) already anticipated exactly this moment: *"A future
Secondment stage adds its own additive stream for 'actual workplace' divergence; it does not need
to alter this stage's schema or commands to do so."* This stage satisfies that anticipation: actual
workplace is never persisted as a column or a second placement table. It is a **derived read-time
value** — "the destination of the relationship's currently-open Full Secondment period if one
exists, otherwise the organizational unit of the relationship's currently-open Placement period
(S11), otherwise unknown" — computed by a query, not stored. There is exactly one place a
relationship's placement is written (`hr.organizational_placement_periods`, S11, untouched by this
stage) and exactly one new place a *temporary divergence* from it is written
(`hr.full_secondment_periods`, this stage). Neither table's meaning is ambiguous, and nothing is
ever written twice. This is the architecturally correct representation the authorization's own §14
asks for, not a workaround.

### 1.6 Decision-priority checklist (authorization §7)

1. **Already fully specified by frozen rules?** Yes — §14 gives a complete, unambiguous rule set:
   original workplace unchanged; actual workplace becomes the destination while active; no
   overlapping full/full (or full/partial, moot until Partial Secondment exists); ending returns
   actual work to the underlying placement state.
2. **Prerequisites already implemented?** Yes — S07 (hierarchy + CRUD), S08
   (`ScopedAuthorizationChecker`), S09 (Employment Relationship aggregate), and S11 (the
   "underlying placement state" a secondment ends back into) are all shipped, tested, and
   unmodified by this stage.
3. **Unlocks multiple downstream domains?** Yes — a future Transfer stage's "closes active
   secondments" consequence (§12) now has a real stream to close instead of a vacuous "nothing to
   close"; a future Reporting stage's "secondments may distribute one person across workplaces"
   requirement (§24) now has real data to read from; Partial Secondment (once Work Schedule exists)
   extends this same conceptual space.
4. **Implementable without inventing missing catalogs/policy?** Yes — no `ref.*` catalog is
   consumed at all, and neither §14 nor §15 names a decision-type or any other catalog-backed field
   for Secondment specifically (contrast Transfer §12 and Supervisory Assignment §16, both of which
   explicitly do).
5. **Preserves a coherent intermediate architecture?** Yes — one new, bounded, self-contained
   temporal child entity of the S09 aggregate, structurally the same shape as S10's and S11's own
   child streams, with "actual workplace" resolved by a query, never by a second write model.

No other candidate clears more than one of these five; most clear none. Full Secondment is
selected. Per authorization §2 item 2, this STOP condition ("two or more materially different S12
domains remain genuinely competitive") does not apply — exactly one candidate clears the bar.

## 2. Candidate comparison

See §1.3's table above; this section is not duplicated.

## 3. Dependency DAG

```
S07 (org units + CRUD) ---\
S08 (RBAC WHAT + scope WHERE) --\
S09 (Employment Relationship) ----+--> S11 (Placement: original workplace) --> S12 (Full Secondment:
                                                                                 actual workplace,
                                                                                 derived)
                                                                                     |
                                                                                     v
                                                                      future: Partial Secondment
                                                                              (needs Work Schedule too)
                                                                      future: Transfer
                                                                              (needs ref.decision_types
                                                                               values; can now also
                                                                               close a real secondment)
                                                                      future: Reporting
                                                                              (needs real secondment data)

Blocked, independent of this DAG (empty ref.* catalogs, no populating command):
  Job/Professional History, Employment Category, Contract Lifecycle, Qualification, Leave

Blocked (undefined semantics, authorization instructs STOP rather than invent):
  Work Schedule Foundation

Blocked (no supplied rules at all):
  Data Quality/Conflict Resolution

Not automatic (authorization's own explicit deferral, unchanged since S09-S11):
  Migration/Excel Import, Reporting
```

## 4. Why selected

See §1.4–§1.6. Full Secondment is the only candidate with zero missing-catalog, missing-value, or
undefined-semantics blockers, and it clears every item of the authorization's own §7
decision-priority checklist.

## 5. Purpose

Give each **Employment Relationship** (S09) a temporal record of a *temporary* administrative
placement that diverges from its S11 **original workplace** — a "full secondment" — while leaving
the original-workplace stream itself completely untouched, and expose a single, derived "actual
workplace" read that resolves to the secondment's destination while one is active, or back to the
original placement once it ends.

## 6. Scope

Builds exactly one new temporal child entity — `FullSecondmentPeriod` — two commands
(`StartFullSecondment`, `EndFullSecondment`), two queries (`ListFullSecondmentPeriodsForRelationship`,
`ResolveActualWorkplaceForRelationship`), RBAC + S08-dual-scope-gated API endpoints nested under the
existing `{person}/{employmentRelationship}` route shape, and audit coverage. Nothing else.

## 7. Non-goals (hard boundary)

Does **not** implement: Transfer, Partial Secondment/Allocation, Supervisory Assignment, Work
Schedule, Contract Lifecycle, Job/Professional History, Employment Category History, Qualification,
Leave, Migration/Excel import, reporting datasets, frontend (backend/domain/API only, matching every
prior stage). No `decision_type` field (§1.4 — this stage's events are not declared formal HR
decisions by the authorization the way Transfer's and Supervisory Assignment's are; `ref.decision_types`
remains untouched). No `type`/discriminator column distinguishing "full" from "partial" — see §7.1.
No modification to S07/S08/S09/S10/S11 migrations, models, or commands. `_to_delete/` is never
touched.

### 7.1 Table is named `full_secondment_periods`, not `secondment_periods` — disclosed

Partial Secondment is a materially different data shape (it needs per-weekday allocation columns
this stage has no evidence to define — §13/§15) gated behind a still-blocked prerequisite (Work
Schedule). Adding a speculative `type` discriminator column to a single shared table now, with only
one type ever exercisable, would itself be the kind of speculative structure this codebase's
discipline forbids elsewhere (S11 spec §5.1; authorization §29: "No speculative JSONB" — the same
principle applied to a speculative enum). The table is named specifically and unambiguously
`hr.full_secondment_periods`. A future Partial Secondment stage adds its own additively-migrated
table (or, if that stage's own dependency recovery finds good reason, a schema change to unify them)
— it does not need this stage's schema to anticimate its shape now.

### 7.2 "Actual workplace" is a query, never a column — disclosed

See §1.5. No table anywhere in this stage has a column named `current_workplace`,
`actual_workplace`, or similar. `ResolveActualWorkplaceForRelationship` (§12) is the only place this
concept is computed, and it is computed fresh on every read from the two underlying streams
(`hr.full_secondment_periods`, this stage; `hr.organizational_placement_periods`, S11).

### 7.3 Ending an Employment Relationship does not automatically close an active secondment — disclosed limitation, not a defect

S09's `EndEmploymentRelationship` is frozen and is not modified by this stage (authorization
forbids modifying S07–S11). If a relationship is ended while a Full Secondment period is still
open, that period is **not** automatically closed — it remains administratively open as a data row.
This is not silently ignored: `ResolveActualWorkplaceForRelationship` (§12) checks the relationship's
own `end_knowledge_state` first and returns "no current actual workplace" for an ended relationship
regardless of any still-open secondment or placement row, so no read in this stage ever reports a
person as "currently working" somewhere once their employment has ended. `EndFullSecondment` itself
remains callable against an already-ended relationship purely as an administrative correction (§8.5)
— closing a stale row is not "starting something new against a dead episode" the way a *new*
placement or secondment would be, so S11's own "reject if already ended" rule is not mirrored for
the *ending* operation. A future consequence-wiring stage (most plausibly Transfer, whose own §12
already names "closes active secondments" as one of its effects) may choose to close this
proactively; this stage does not invent that consequence for itself.

## 8. Ownership and domain model

`FullSecondmentPeriod` is a child temporal entity of `EmploymentRelationship` — not its own
aggregate root, exactly mirroring how `OrganizationalPlacementPeriod` (S11) and
`EmploymentStatusPeriod` (S10) are children of the same aggregate. Fields:

- `id` (uuid, technical PK)
- `employment_relationship_id` (uuid, FK → `hr.employment_relationships`, `RESTRICT`)
- `organizational_unit_id` (uuid, FK → `org.organizational_units`, `RESTRICT`) — the **destination**
  unit for the duration of the secondment
- `effective_from` (`DATE`)
- `effective_to` (`DATE`, nullable — half-open `[from, to)`, `NULL` = currently active)
- `created_at` (`TIMESTAMPTZ`)

No `version` column — append-only, exactly like `hr.organizational_placement_periods` (S11) and
`hr.employment_status_periods` (S10): the only mutation a later command ever causes is setting the
open period's own `effective_to` when it is explicitly ended by `EndFullSecondment` — never as an
automatic side effect of starting a new one (§8.1 — this is the one place this stage's transition
shape differs from S10's/S11's "close-then-open" pattern).

### 8.1 Starting rejects an already-active secondment instead of auto-closing it — disclosed, deliberate difference from S10/S11's shape

S10's `RecordEmploymentStatusPeriod` and S11's `RecordOrganizationalPlacementPeriod` both
**auto-close** any currently-open period as part of recording a new one — appropriate for those
domains because a relationship always has *some* status and (once recorded once) always has *some*
placement; a transition from one to the next is the normal case. Full Secondment is different in
kind: a relationship ordinarily has **no** active secondment, and starting a second one while the
first is still open is not a "transition" — the authorization's own §14 says plainly *"No
overlapping: full/full."* `StartFullSecondment` therefore **rejects** the call with
`ActiveFullSecondmentAlreadyExistsException` (409) if an open period already exists, rather than
silently closing it and starting the new one. Ending the current secondment is a distinct,
deliberate act (`EndFullSecondment`), never an implicit side effect of starting another.

### 8.2 No `is_active` gate on the destination unit — disclosed, mirrors S10/S11 precedent

Mirrors the explicit S10 precedent (itself citing S06 precedent) and S11's own identical choice
(spec §8 step 2): no application-layer active-target check is added here. Authorization-layer
inactive-target denial is supplied for free by reusing `ScopedAuthorizationChecker` unmodified
(§10 below) — the same outcome, reached without inventing a second, domain-level active check.

### 8.3 `effective_from`/`effective_to` validated against the relationship's own `effective_from` only

Mirrors S10/S11's identical reasoning: the relationship's `effective_from` is set exactly once, at
`CreateEmploymentRelationship`, and never mutated afterward by any command in this codebase, so
comparing against it carries no concurrency risk despite being an application-level check rather
than a database constraint.

### 8.4 Starting rejects an already-ended relationship; ending does not — disclosed, see §7.3

`StartFullSecondment` reuses S09's `EmploymentRelationshipAlreadyEndedException` (409) exactly as
S11 does for `RecordOrganizationalPlacementPeriod` — there is no active employment episode left to
second anywhere. `EndFullSecondment` does not perform this check (§7.3).

## 9. References consumed

Consumes `org.organizational_units` (S07) exactly as it exists — no duplicate hierarchy, no new
column on that table. No `ref.*` catalog is touched.

## 10. Temporal model and invariants

**Starting** a Full Secondment (`StartFullSecondment::handle`):

1. The Employment Relationship is re-fetched fresh with `lockForUpdate()` inside the transaction
   (S09/S10/S11's established discipline) and must not already be ended
   (`end_knowledge_state = 'KNOWN'`) — §8.4.
2. The destination `OrganizationalUnit` is re-fetched fresh by id; no `is_active` gate (§8.2).
3. If an open Full Secondment period already exists for this relationship, reject with
   `ActiveFullSecondmentAlreadyExistsException` (§8.1) — no auto-close.
4. `effective_from` must be strictly after the Employment Relationship's own `effective_from`
   (§8.3); otherwise `InvalidFullSecondmentStartDateException` (422, `errors.effective_from`).
5. The new period is inserted with `effective_to = NULL`.
6. No consequence to the S11 placement stream or the Employment Relationship — original workplace
   is untouched, exactly as §14 requires.

**Ending** a Full Secondment (`EndFullSecondment::handle`):

1. The Employment Relationship is re-fetched fresh with `lockForUpdate()` — serializes against a
   concurrent `StartFullSecondment`/`EndFullSecondment` call on the same relationship. No
   already-ended check (§7.3/§8.4).
2. The currently open Full Secondment period for this relationship is located; if none exists,
   reject with `NoActiveFullSecondmentException` (409) — §8.5.
3. `effective_to` must be strictly after the open period's own `effective_from`; otherwise
   `InvalidFullSecondmentEndDateException` (422, `errors.effective_to`).
4. The period's `effective_to` is set. "Actual workplace" reverting to the underlying S11
   placement state (§14: "returns actual work to the appropriate underlying placement state") is
   achieved purely by `ResolveActualWorkplaceForRelationship` (§12) no longer finding an open
   secondment row — no extra write is needed or performed.

### 8.5 Ending is idempotent-safe by rejection, not by silent success

Calling `EndFullSecondment` when no secondment is active is rejected (409), not silently
accepted as a no-op — mirrors this codebase's established convention (e.g.,
`EmploymentRelationshipAlreadyEndedException` for a second `EndEmploymentRelationship` call) of
surfacing "this action does not apply to the current state" as an explicit conflict rather than a
silently-successful no-op.

## 11. Domain exceptions

| Exception | HTTP | Source |
|---|---|---|
| `EmploymentRelationshipAlreadyEndedException` (S09, reused) | 409 | Starting a secondment against an already-`KNOWN`-ended relationship (§8.4). |
| `ActiveFullSecondmentAlreadyExistsException` (new) | 409 | Starting a secondment while one is already active for the same relationship (§8.1). |
| `NoActiveFullSecondmentException` (new) | 409 | Ending a secondment when none is active (§8.5). |
| `InvalidFullSecondmentStartDateException` (new) | 422 (`errors.effective_from`) | `effective_from` not strictly after the relationship's own `effective_from` (§10 step 4), or the DB `CHECK`/`EXCLUDE` on insert. |
| `InvalidFullSecondmentEndDateException` (new) | 422 (`errors.effective_to`) | `effective_to` not strictly after the open period's own `effective_from` (§10 step 3), or the DB `CHECK` on the closing `UPDATE`. |

No new `StaleVersionException`-shaped class — no `version` column exists (§8). No
`UnresolvedBehavior`-shaped exception — this stage consumes no `ref.*` behavior catalog.

## 12. Authorization — RBAC (`WHAT`) + S08 scope (`WHERE`), dual-target, composed

Three new permission codes added to the existing `HumanResourcesPermissionCatalog`:
`hr.full_secondment_periods.view`, `hr.full_secondment_periods.start`,
`hr.full_secondment_periods.end` — separate `start`/`end` permissions mirror S09's own
`EMPLOYMENT_RELATIONSHIPS_CREATE`/`EMPLOYMENT_RELATIONSHIPS_END` split (starting and ending a
secondment are materially different, independently grantable actions).

Every route additionally carries the existing `permission:` middleware (coarse `WHAT` gate,
unchanged convention).

### 12.1 Dual-scope resolution — the authorization's §26 "critical S12 adversarial point," resolved explicitly

Authorization §26: *"For movement operations involving source + destination units, specification
MUST explicitly decide which organizational scopes are required. Do not casually authorize a
transfer merely because destination is in scope while source is not."* Unlike S11's
`RecordOrganizationalPlacementPeriod` (which deferred the analogous question for the not-yet-built
Transfer, since it was not implementing a movement command), this stage **is** implementing a
movement command and must resolve this now, not defer it again.

**Starting** a secondment moves a person's effective location from their current placement
("source") to the named destination unit. Both units' visibility is materially affected: the source
unit's roster is (temporarily) losing someone who was visibly theirs; the destination unit's roster
is gaining someone. The controller therefore calls
`ScopedAuthorizationChecker::authorize($principal, PERM, $unit)` **twice** — once for the
destination unit (always), and once for the "source" unit, defined as the relationship's currently
open S11 placement period's `OrganizationalUnit`, **if one exists**. Both calls must return `true`;
if the relationship has no placement recorded yet, there is no source unit to check, and the
destination check alone governs (mirroring S11's own "no unit yet" disclosed default, extended to
the source side). `ScopedAuthorizationChecker` itself is not modified — it is called twice with two
different targets, exactly as it was designed to be called ("the same, unmodified checker" per
authorization §11/§26 intent).

**Ending** a secondment is the symmetric reverse: it returns the person from the secondment's own
destination unit (whose roster loses them) back toward their underlying placement unit (whose
roster regains them). The controller checks scope over the secondment's own `organizational_unit_id`
(always — there is always exactly one open period being ended) and, if the relationship has an S11
placement recorded, over that placement's unit too.

**Reading** (`index`, `actual-workplace`): the scope-check target is whichever single unit
`ResolveActualWorkplaceForRelationship` resolves to (secondment's destination if active, else
placement's unit, else none) — a read never needs the dual-target rule, since it discloses only the
one unit that is, in fact, the answer, exactly mirroring S11's own single-target read rule.

A `false` result from either scope check returns the same generic `403` the codebase already uses
everywhere else, with no distinguishing detail leaked between "missing permission," "destination
out of scope," "source out of scope," or "an inactive target."

### 12.2 Source-scope check is made inside the relationship's own row lock — corrective, post-implementation adversarial finding

An initial implementation read the "source" unit (§12.1) and checked scope against it *before* any
transaction opened or any row was locked. Adversarial review (authorization §36) found this a
genuine TOCTOU gap, not merely theoretical: `RecordOrganizationalPlacementPeriod` (S11) itself locks
the same `hr.employment_relationships` row before writing a new placement, so a concurrent S11
placement change could move the relationship to a different unit in the window between this stage's
unlocked source-scope read and its own later, locked command execution — authorizing a start/end
against a "source" that was, by the time of the actual write, no longer the real one. Both
`FullSecondmentPeriodController::store()` and `::end()` now open one transaction that locks the
`EmploymentRelationship` row **first** (via `EmploymentRelationship::query()->lockForUpdate()`),
and only then reads the current placement unit and makes the scope decision — closing the window by
genuinely serializing against `RecordOrganizationalPlacementPeriod`'s own identical lock, not merely
narrowing it. `StartFullSecondment`/`EndFullSecondment` re-acquire the identical row lock inside
`AuditedCommandExecutor`'s own nested (savepoint) transaction immediately afterward; PostgreSQL row
locks are reentrant within one transaction, so this is not a double-lock hazard. No change to the
destination-side check (§12.1) was needed — the destination is a client-supplied, request-scoped
value, not state read from a concurrently-mutable stream, so it carries no equivalent staleness
risk.

## 13. Commands

- `StartFullSecondment::handle(EmploymentRelationship $relationship, OrganizationalUnit $destination, string $effectiveFrom): FullSecondmentPeriod`
- `EndFullSecondment::handle(EmploymentRelationship $relationship, string $effectiveTo): FullSecondmentPeriod`

Explicitly **not** built: any command named `Transfer`/`PartialSecondment`/`Assignment` (out of
scope, §7); any command to edit or delete a past secondment period (no evidence authorizes
retroactive correction, mirrors S11's identical choice).

## 14. Queries

- `ListFullSecondmentPeriodsForRelationship` — ordered by `effective_from` desc, mirrors
  `ListOrganizationalPlacementPeriodsForRelationship` exactly.
- `ResolveActualWorkplaceForRelationship(EmploymentRelationship $relationship): ?ActualWorkplace` —
  a small read-model value object (`organizational_unit_id`, `source` = `'secondment'` |
  `'placement'`, `since` = the authoritative period's own `effective_from`), or `null` when the
  relationship has ended (§7.3) or has neither an active secondment nor any recorded placement.

## 15. Cross-stream consequences

None written. `StartFullSecondment`/`EndFullSecondment` never write to
`hr.organizational_placement_periods` (S11) or `hr.employment_relationships` (S09).
`ResolveActualWorkplaceForRelationship` **reads** both S11's placement stream and this stage's own
secondment stream, and reads the relationship's own `end_knowledge_state` — three reads, zero
cross-stream writes.

## 16. PostgreSQL schema

New table `hr.full_secondment_periods`, in the existing `hr` schema:

- `full_secondment_periods_period_check`: `effective_to IS NULL OR effective_to > effective_from`
  (via `TemporalConstraints::validPeriodCheckSql()`).
- `full_secondment_periods_no_overlap`: `EXCLUDE USING gist (employment_relationship_id WITH =,
  daterange(effective_from, effective_to, '[)') WITH &&)` (via
  `TemporalConstraints::noOverlapConstraintSql()`) — the final backstop behind §10 step 3's
  application-level "reject if already active" check, for the identical concurrent-race reason S11
  spec §15 gives for its own analogous check.
- `employment_relationship_id` FK → `hr.employment_relationships.id`, `ON DELETE RESTRICT`.
- `organizational_unit_id` FK → `org.organizational_units.id`, `ON DELETE RESTRICT` — no hard-delete
  of a unit while it has secondment history.

No DB trigger — no precedent for one, and this stage's cross-table checks are read-only (§15), safe
at the application layer exactly as S10/S11's identical reasoning already concluded for FK-adjacent
checks of this shape.

## 17. Concurrency

- Two concurrent `StartFullSecondment` calls for the same Employment Relationship: the
  relationship's own row lock serializes them; the `EXCLUDE` constraint is the final backstop
  regardless (a real two-connection race test, mirroring `ConcurrencyTest`'s established pattern).
- A `StartFullSecondment` call racing a concurrent `EndFullSecondment` call on the same
  relationship: both acquire the same row lock, so they serialize (also proved by a real
  two-connection race test, added post-implementation — §12.2).
- A `StartFullSecondment`/`EndFullSecondment` call racing a concurrent `EndEmploymentRelationship`
  (S09) or `RecordOrganizationalPlacementPeriod` (S11) call on the same relationship: all four
  commands lock the same relationship row, so all serialize against each other — no new lock
  ordering is introduced.

## 18. Authorization/scope test matrix

RBAC-absent-deny (no permission at all → denied regardless of scope); permission present, zero
scope grants → denied; permission + `UNIT` grant covering destination only (source out of scope,
placement exists) → denied; permission + `UNIT` grant covering source only (destination out of
scope) → denied; permission + `UNIT` grant covering both → allowed; `GLOBAL` grant → allowed;
destination unit inactive → denied even with otherwise-sufficient permission and scope; starting
with no placement recorded yet (no source unit to check) → destination-scope-alone governs; ending
with no placement recorded yet (no unit to "return" scope-check against) → secondment-unit-scope-alone
governs; reading with an active secondment → scope checked against the secondment's unit; reading
with no active secondment but a placement exists → scope checked against the placement's unit;
reading with neither → allowed on permission alone; reading an ended relationship → `null` result,
still requires the base permission.

## 19. Audit

Every mutation runs through the existing `AuditedCommandExecutor`. `StartFullSecondment`: action
`hr.full_secondment_period.start`, `changes` allowlist `employment_relationship_id`,
`organizational_unit_id`, `effective_from`. `EndFullSecondment`: action
`hr.full_secondment_period.end`, `changes` allowlist `id`, `effective_to`. No National ID or other
Person-identifying attribute is placed in audit metadata for either.

## 20. API

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/full-secondment-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/full-secondment-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/full-secondment-periods/end
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/actual-workplace
```

`POST .../full-secondment-periods` body: `organizational_unit_id` (uuid), `effective_from` (date).
`POST .../full-secondment-periods/end` body: `effective_to` (date) — mirrors S07's
`.../activate`/`.../deactivate` action-route convention rather than a generic `PATCH` (authorization
§28: "No generic PATCH"). 404 if the destination unit does not exist. Standard
`401`/`403`/`404`/`409`/`422`; no `PATCH`/`DELETE` on the periods resource (no hard-delete, no
correction command, §13). IDOR protection: the route's `{employmentRelationship}` must belong to
the given Person's own resource path, mirroring S09/S10/S11's existing checks exactly.

## 21. Migration compatibility

No import pipeline is built (§7). A relationship with zero recorded secondment periods simply has
no known secondment history — never a fabricated retroactive default, mirroring S10/S11's identical
reasoning.

### 21.1 Migration-rollback ratchet (new cross-dependency)

`hr.full_secondment_periods` carries **two** `RESTRICT` FKs — to `hr.employment_relationships` (S09)
and to `org.organizational_units` (S07) — the identical shape S11's own `19.1` already established
a ratchet pattern for. It must be rolled back before *either*
`MigrationLifecycleTest::dropHumanResourcesSchemaObjects()` *or* `dropOrganizationSchemaObjects()`
runs, and before either isolated stage rollback test
(`test_s07_migrations_roll_back_and_reapply_cleanly`, `test_s09_migrations_roll_back_and_reapply_cleanly`).
A new `dropFullSecondmentSchemaObjects()` helper is added, called before both of the existing
helpers it must precede, and before S11's own `dropOrganizationalPlacementSchemaObjects()` is called
relative to those same two (order between the two new-in-S11/S12 helpers themselves does not matter,
since neither references the other's table).

## 22. Reporting implications

None implemented (§7). The derived, read-time "actual workplace" concept (§1.5/§12/§14) keeps the
door open for a future Reporting stage to answer "which unit was this relationship actually working
in as of date D" without needing this stage's schema to change, but no reporting logic, dataset, or
endpoint is built here.

## 23. Frontend boundary

None — backend/domain/API only, matching every prior stage (authorization §33/§34).

## 24. Tests (minimum)

Secondment starting (first period, rejection of a second overlapping start, rejection against an
already-ended relationship, backdated-start rejection); secondment ending (successful close,
rejection when none active, backdated-end rejection); `ResolveActualWorkplaceForRelationship`
correctness (no placement/no secondment → null; placement only → placement's unit; active
secondment → secondment's unit; secondment ended → reverts to placement's unit; relationship ended
→ null regardless of open rows, §7.3); organizational-unit-not-found → 404; concurrent
start/start and start/end races (real two-connection races); RBAC on all four routes (401/403);
S08 dual-scope enforcement per the §18 matrix; IDOR protection; audit entries generated for both
commands, no PII; migration rollback/reapply (including the new ratchet ordering, §21.1); PostgreSQL
constraint tests (`CHECK`/`EXCLUDE`/FK exercised directly); full S01–S12 regression; boundary-audit
test for §7's exclusions.

## 25. Adversarial review focus

Overlap; write skew; stale route-bound relationship/unit; permission-present-scope-absent bypass on
either the source or destination target specifically; scope-present-permission-absent bypass;
inactive-unit bypass; IDOR; audit omission; PII leakage; hard-delete; backdated/future-date
corruption; cross-stream inconsistency with S09's own end-state and S11's own placement stream;
the §7.3 orphaned-secondment-after-relationship-ended scenario specifically (confirm
`ResolveActualWorkplaceForRelationship` truly nulls it out, not just "usually" does); S13+ leakage
(Transfer/PartialSecondment/Assignment/WorkSchedule/Contract/Qualification/Leave/Migration/Reporting);
cross-project leakage; empty-catalog assumption (none exist here — verified, not assumed).

## 26. S13+ handoff (disclosed, not blocking)

1. Partial Secondment/Allocation remains gated behind Work Schedule Foundation, itself gated behind
   materially undefined weekday/allocation semantics — unchanged since this stage's own §1.3
   finding.
2. Transfer remains gated behind `ref.decision_types` receiving at least one authoritative value
   for a transfer decision from a future authorization — §1.4. Once unblocked, Transfer's own
   "closes active secondments" consequence (authorization §12) now has this stage's real
   `hr.full_secondment_periods` stream to act on.
3. Supervisory Assignment remains gated behind `ref.supervisory_titles` (empty, no populating
   command), `ref.supervisory_statuses` (table does not exist), and the same `ref.decision_types`
   gap as Transfer.
4. Job/Professional History, Employment Category History, Qualification, Contract Lifecycle, Leave
   all remain blocked by an empty catalog with no populating command — unchanged since S09–S11's
   own identical findings.
5. Migration/Import, Reporting, Data Quality/Conflict Resolution — untouched, per authorization
   §24/§25 and the absence of any supplied rules for the last.
6. §7.3's disclosed limitation (ending an Employment Relationship does not proactively close an
   open secondment row) remains a named, explicit handoff, not a defect — mitigated at read time,
   not write time.

None of these represents an unresolved *consequential* policy for S12's own boundary; each is a
named, explicit handoff.

## 27. Internal review (P01–P25, authorization §32)

- **P01 provenance truthful** — §0: no historical title claimed; reconstruction explicitly labelled.
- **P02 candidate comparison complete** — §1.3 checks every one of the 13 named candidates against
  live repository/database evidence.
- **P03 dependency DAG valid** — §3.
- **P04 stage boundary minimal** — §6/§7: two commands, two queries, one entity; explicitly not
  Transfer/PartialSecondment/Assignment.
- **P05 prerequisites complete** — §1.6 item 2: S07/S08/S09/S11 all shipped and unmodified.
- **P06 no invented catalog values** — no `ref.*` catalog touched at all (§9); §1.4 explains
  precisely why Transfer's catalog gap disqualifies it rather than being worked around.
- **P07 aggregate ownership** — §8: child of `EmploymentRelationship`, not a new root.
- **P08 temporal semantics** — §10: half-open `[from, to)`; §8.1 discloses the one deliberate
  divergence from S10/S11's auto-close shape.
- **P09 PostgreSQL invariants** — §16: `CHECK`+`EXCLUDE`+two `RESTRICT` FKs.
- **P10 future/backdated correctness** — §10 steps 4/step 3 of ending; §11 exception table.
- **P11 concurrency** — §17, real multi-connection races planned.
- **P12 cross-stream consequences** — §15: none written; three reads disclosed.
- **P13 status interaction** — §7.3/§14 step 4 of `ResolveActualWorkplaceForRelationship`: an ended
  relationship always resolves to `null`, regardless of any lingering open row.
- **P14 placement interaction** — §1.5/§12.1/§14: placement is read, never written, by this stage;
  "actual workplace" derivation is fully specified.
- **P15 RBAC** — §12: three new permission codes, existing `permission:` middleware.
- **P16 organizational scope** — §12.1: dual-target composition explicitly resolved, the
  authorization's own flagged "critical adversarial point" addressed head-on, not deferred; §12.2:
  a post-implementation adversarial-review finding (source-scope TOCTOU) was corrected by locking
  the relationship row before making the scope decision, not merely disclosed as a known gap.
- **P17 source/destination scope if movement** — §12.1 (this *is* the movement case §26 anticipates).
- **P18 IDOR** — §20: same nested-route check as S09/S10/S11.
- **P19 audit** — §19: `AuditedCommandExecutor`, allowlisted metadata for both commands.
- **P20 PII** — §19: no National ID or Person attribute in metadata.
- **P21 migration compatibility** — §21.1: new ratchet ordering explicitly planned before
  implementation, not discovered after.
- **P22 reporting compatibility** — §22: derived-read shape preserved, nothing implemented.
- **P23 no S13 leakage** — §7/§26: every downstream domain named and explicitly deferred.
- **P24 no cross-project contamination** — no terminology outside this repository's own established
  vocabulary is used anywhere in this document.
- **P25 acceptance testability** — §24: full concrete test list, each traceable to a numbered
  section above.

No consequential issue is unresolved. **PASS — proceeding to implementation.**

## 28. Post-implementation adversarial review and corrections

A full adversarial review was conducted against the completed implementation, covering every item
in §25. One **MODERATE** finding (§12.2: source-scope check made outside any row lock, a TOCTOU
gap against a concurrent S11 placement change) was corrected — both `store()` and `end()` now lock
the relationship row before making any scope decision. Two **MINOR** test-coverage gaps were also
closed: a real two-connection Start-vs-End race test (§17) and the missing asymmetric-forbidden
cases for `end()`'s own dual-scope check (§18 matrix — secondment-unit-only and source-unit-only,
mirroring the ones `store()` already had). Zero **BLOCKING** findings. Full S01–S12 regression
(1001/1001), Pint, and the diff-check equivalent all pass after the corrections. **PASS — proceeding
to git finalization.**
