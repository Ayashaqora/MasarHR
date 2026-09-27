# S16 — Workplace Assignment Foundation (تأسيس حركة التكليف بمكان العمل)

Specification for ADR-S16-001. Written by the Executor after discovery, before any implementation,
per ADR-S16-001 §23. Architecture self-review result is recorded at the end of this document (§S16.7).

## §S16.1 Reconstruction disclosure

No historical S16 roadmap wording was recovered from any repository artifact, prior ADR, or prior
specification. The title "S16 — Workplace Assignment Foundation" is the Executor's own reconstruction
from ADR-S16-001's own content, not a recovered historical name. This document does not claim
historical roadmap status for any wording used.

## §S16.2 Source-derived business meaning and terminology

"تكليف" (workplace assignment) is a WORKPLACE MOVEMENT — a temporary redirection of where an employee
actually works — and is explicitly NOT supervisory assignment (a managerial/title concept). Per
ADR-S16-001 §3:

- Normal employee: original organizational placement (S11) = actual/current workplace.
- Transfer (S14): original placement changes; actual/current workplace = the new original placement.
- Full Secondment (S12): original placement (S11) stays unchanged; actual/current workplace becomes
  the secondment destination while it is open.
- **Workplace Assignment (S16, new)**: original placement (S11) stays unchanged; actual/current
  workplace becomes the assignment destination while it is open. Structurally parallel to Full
  Secondment, but a conceptually distinct decision/movement type (§S16.3), independent of Employment
  Status (§S16.11).

"مكلف" is never encoded as an Employment Status value in this stage. No employment-status value is
read, written, or inferred by any S16 code.

## §S16.3 Assignment vs. supervisory-assignment separation

Strictly out of scope (ADR-S16-001 §17): supervisory title assignment, "مكلف"/"مسكن" as a supervisory
status, managerial hierarchy, acting-manager rules. Discovery (§S16.4) confirmed the only
pre-existing, tangentially-named artifact is `SupervisoryTitle` (S13) — a pure reference-catalog
lookup value (`ref.supervisory_titles`, Create/UpdateMetadata/Activate/Deactivate commands only), with
zero movement/assignment logic of its own. No code path in this stage reads, writes, or reasons about
`SupervisoryTitle`. This existing structural separation (a labelled reference value vs. an actual
movement mechanism, which did not exist for "assignment" before this stage) is preserved unmodified —
S16 introduces the movement mechanism for workplace-assignment only, touching nothing in the
`SupervisoryTitle` catalog.

## §S16.4 Discovery results and dependency/gap matrix

Full-text search `grep -rniE "assignment|تكليف|supervisory|مكلف|مسكن"` across `app`, `database`, `docs`
confirmed: no existing "workplace assignment"/"تكليف"-as-movement implementation exists anywhere in
the codebase. All "assignment" matches outside `SupervisoryTitle` belong to the unrelated Security
module's Role Assignment domain (`AssignRoleToPrincipal`, `RoleAssignmentController`, etc.) — a
completely different aggregate (Principal/Role), not touched by this stage.

| # | §6 STOP condition | Evaluated | Result |
|---|---|---|---|
| A | Materially equivalent existing workplace-assignment implementation | grep + full read of `SupervisoryTitle` and all HR movement commands | NOT triggered — genuinely new |
| B | Requires inventing supervisory semantics | §S16.3 keeps supervisory domain untouched | NOT triggered |
| C | Requires Work Schedule / weekday allocation | No percentage/weekday concept introduced anywhere in this design | NOT triggered |
| D | Assignment-vs-secondment ambiguity, data-integrity-significant | Resolved via mutual exclusion (§S16.8, pair 2/3) — a conservative reject, not an invented precedence rule | NOT triggered |
| E | Decision-type semantics cannot be safely established | §S16.12 — ADR §14 itself authorizes the technical code; Arabic value supplied verbatim | NOT triggered |
| F | Requires changing frozen S01–S15 semantics rather than integrating | All touches to S14/S15 are new, additive `closeOpen*IfAny()`-style consequences, following the exact precedent S14 (closing an open secondment) and S15 (closing an open secondment/status period) already established — no existing behavior for any pre-S16 case changes | NOT triggered |

Dependency matrix (what S16 reads from / writes to / extends):

| Existing component | S16 relationship |
|---|---|
| `hr.employment_relationships` (S09) | Read-only FK parent; own end/version fields untouched |
| `hr.organizational_placement_periods` (S11) | Read-only, to resolve "source"/current original placement for scope checks; never written by S16 |
| `hr.full_secondment_periods` (S12) | Read-only, to enforce mutual exclusion with assignment (§S16.8) |
| `ResolveActualWorkplaceForRelationship` (S12) | Extended: gains one new resolution tier (assignment), ordered between secondment and placement |
| `ActualWorkplace` value object (S12) | Extended: new `fromAssignment()` factory, `source()` may now return `'assignment'` |
| `TransferEmployee` (S14) | Extended: gains a new consequence — closes an active assignment at the transfer's effective date, mirroring its existing secondment-closing consequence exactly |
| `EndEmploymentRelationship` (S15) | Extended: gains a third `closeOpen*IfAny()` consequence for assignment, mirroring the existing secondment/status-period consequences exactly |
| `ref.decision_types` (S05/S14) | New row seeded: `code = 'ASSIGNMENT'`, `name_ar = 'تكليف'` |
| `security.permissions` (S08) | Three new rows: `hr.workplace_assignment_periods.{view,start,end}` |
| `ScopedAuthorizationChecker` (S08) | Reused unmodified, called against up to two targets, mirroring S12/S14 |
| `AuditedCommandExecutor`/`AuditSpec` (S04) | Reused unmodified |
| `TemporalConstraints` (S02) | Reused unmodified for the new table's CHECK + EXCLUDE constraints |
| Job/queue/scheduler infrastructure | Confirmed absent (no `app/Console`, no `Kernel.php`, no `*Job.php` in the whole backend) — §13 alert is DEFERRED (§S16.13) |

## §S16.5 Data model

New table `hr.workplace_assignment_periods`, column-for-column identical in shape to
`hr.full_secondment_periods` (S12) — the closest structural precedent, chosen over the S11
auto-close-on-insert placement shape because Assignment, like Full Secondment, is a child movement
stream of an Employment Relationship that must support a genuinely separate, deliberate "end with no
replacement" action (§S16.9) as well as a "replace" action (§S16.9) — the table itself carries no
opinion about which one produced a given closed row; that behavior lives entirely in the two
commands built on top of it, exactly as `full_secondment_periods` carries none either:

- `id` uuid primary key
- `employment_relationship_id` uuid, FK → `hr.employment_relationships`, `restrictOnDelete()`
- `organizational_unit_id` uuid, FK → `org.organizational_units`, `restrictOnDelete()`
- `effective_from` date, not null
- `effective_to` date, nullable
- `created_at` timestampTz

No `version` column (never independently re-submitted with an expected version by any client — same
reasoning as S11/S12). No `decision_type_id` column: mirrors S14 Transfer's own precedent exactly —
`decision_type_id` is validated at command time and recorded in the S04 audit entry's `changes`, never
persisted on the period row itself (S11's placement periods and S12's secondment periods carry no
decision-type column either; only Transfer's *audit entry* carries one, and Transfer writes to the
placement table, not a table of its own). No JSON event store, no current-state snapshot column, no
hard delete — satisfies §18 verbatim.

Constraints, via the existing `TemporalConstraints` helper, keyed on `employment_relationship_id`
(identical shape to S11/S12):

- `hr_workplace_assignment_periods_period_check` — `TemporalConstraints::validPeriodCheckSql(...)`
- `hr_workplace_assignment_periods_no_overlap` — `TemporalConstraints::noOverlapConstraintSql(...)`

## §S16.6 Temporal model

Half-open `[effective_from, effective_to)` over DATE columns, per the frozen S02 convention. History
is never overwritten or deleted: `StartWorkplaceAssignment` may set a previous open row's
`effective_to` (a close, not a delete — the row and its own `effective_from` remain forever), and
`EndWorkplaceAssignment` may do the same; neither ever mutates a row's `effective_from`, and neither
ever updates an already-closed row. Current assignment is always derived (`whereNull('effective_to')`),
never stored as a separate flag/column. Every assignment period is a child of exactly one Employment
Relationship and can never start before that relationship's own `effective_from` (§S16.9), mirroring
S11/S12's identical rule.

## §S16.7 Architecture self-review — PASS

- No supervisory semantics invented (§S16.3). PASS.
- No Work Schedule/percentage/weekday concept introduced (§S16.4 row C). PASS.
- Smallest model consistent with frozen architecture: one child table, shaped identically to an
  existing precedent, no new persistence concepts (§S16.5). PASS.
- Every §6 STOP condition evaluated and not triggered (§S16.4 table). PASS.
- All frozen S01–S15 behavior for existing cases (no active assignment involved) is unchanged —
  every touch to S14/S15 is a new, additive, no-op-when-nothing-is-open consequence. PASS.
- Decision-type value is the ADR-supplied Arabic string verbatim, with an Architecture-Authority-
  permitted technical code (§14's own explicit exception), no invented business value. PASS.

Self-review PASS. Proceeding to implementation.

## §S16.8 Actual workplace resolution and movement interaction matrix

`ResolveActualWorkplaceForRelationship` (§9: "ONE authoritative answer") is extended with one new
resolution tier, checked after secondment and before placement:

1. Active Full Secondment → `ActualWorkplace::fromSecondment(...)` (unchanged, existing).
2. Active Workplace Assignment (new) → `ActualWorkplace::fromAssignment(...)`.
3. Current Organizational Placement → `ActualWorkplace::fromPlacement(...)` (unchanged, existing).
4. None of the above, or relationship ended → `ActualWorkplace::unresolved()` (unchanged, existing).

This ordering is defensive rather than load-bearing: tier 1 and tier 2 can never both be true for the
same relationship at the same time, because §S16.8's own interaction matrix (below) enforces mutual
exclusion between an active secondment and an active assignment at write time — there is never a
genuine tie for the resolver to break. The order is documented so a future reader does not need to
re-derive this invariant.

Movement interaction matrix (ADR-S16-001 §10, every consequential pair analyzed with justification):

| Pair | Decision | Mechanism | Justification |
|---|---|---|---|
| Assignment → Assignment (new assignment while one is active) | ALLOW | CLOSE-PREVIOUS-THEN-START-NEW, atomic | ADR §11's own wording ("close previous assignment at the new effective boundary then open the new assignment atomically... if effective-date semantics create overlap or invalid chronology: reject") directly authorizes this shape. A new تكليف decision is inherently sequential/superseding — mirrors S11 Organizational Placement's own atomic close-then-open pattern exactly. |
| Assignment → Full Secondment (start secondment while assignment active) | REJECT | `ActiveWorkplaceAssignmentAlreadyExistsException`-style 409 raised by `StartFullSecondment` (extended) | Avoids inventing a cross-domain precedence rule between two ADR-kept-separate (§4) movement mechanisms. Neither is silently overridden. Consistent with §11's own fallback stance ("if...creates ambiguity: reject"). |
| Full Secondment → Assignment (start assignment while secondment active) | REJECT | `ActiveFullSecondmentAlreadyExistsException` (existing, S12) raised by `StartWorkplaceAssignment` (new) | Symmetric to the row above — same justification, same conservative no-precedence-invented choice. |
| Assignment → Transfer (transfer while assignment active) | ALLOW | CLOSE-PREVIOUS-AS-CONSEQUENCE — `TransferEmployee` closes the open assignment at the transfer's effective date, exactly mirroring its existing secondment-closing consequence | Once the underlying original placement itself moves, a temporary destination override of the old placement no longer has coherent meaning; closing it is the same reasoning already applied to secondment by S14, now extended one step further. |
| Transfer → Assignment (start assignment after a prior transfer) | ALLOW | No special handling | An ordinary `StartWorkplaceAssignment` call; "current" original placement is simply whatever S11 placement TransferEmployee already left in place. No interaction. |
| Employment End → Assignment (ending a relationship with an open assignment) | ALLOW | CLOSE-PREVIOUS-AS-CONSEQUENCE — `EndEmploymentRelationship` closes the open assignment at the same `effective_to`, mirroring its existing secondment/status-period consequences exactly | Prevents an "impossible" state (open assignment on an ended relationship), satisfying §15. Same single-orchestration-point pattern S15 already established for secondment and status period. |

No pair required inventing business semantics; every REJECT is a conservative "do not decide a
precedence you were not given," and every CLOSE-PREVIOUS is a mechanical repetition of an
already-established, already-reviewed precedent (S11's atomic replace, S14/S15's additive
consequence-closing). §6 STOP condition D is therefore resolved, not triggered.

## §S16.9 Start / End / Replacement / Extension

Two commands, mirroring S12's Start/End shape (a deliberate pair, not S11's single upsert), because
Assignment needs both a "replace" operation (§S16.8, pair 1) and a genuinely separate "end, no
replacement" operation:

**`StartWorkplaceAssignment->handle(relationship, destination, effectiveFrom, decisionType)`**
1. Re-fetch `EmploymentRelationship` fresh with `lockForUpdate()` (never trust the caller's copy) —
   serializes against every other command touching this relationship (mirrors S11/S12/S14 verbatim).
2. Reject with `EmploymentRelationshipAlreadyEndedException` (existing, S09) if already ended.
3. Re-fetch the destination `OrganizationalUnit` fresh.
4. Re-fetch `decisionType` fresh; reject with a new `InvalidWorkplaceAssignmentDecisionTypeException`
   unless `code === 'ASSIGNMENT'` and `is_active` — mirrors `TransferEmployee`'s identical anti-TOCTOU
   re-check verbatim.
5. Reject with `ActiveFullSecondmentAlreadyExistsException` (existing, S12) if an active Full
   Secondment exists (§S16.8, pair 3).
6. Validate `effectiveFrom` strictly after the relationship's own immutable `effective_from` — reject
   with a new `InvalidWorkplaceAssignmentStartDateException` otherwise (mirrors S11 §8 step 3 /
   S12 §8.3 verbatim).
7. If an open Workplace Assignment period exists: validate `effectiveFrom` strictly after that open
   period's own `effective_from` (same exception otherwise), then close it (`effective_to =
   effectiveFrom`) — mirrors `RecordOrganizationalPlacementPeriod` verbatim (§S16.8, pair 1).
8. Insert the new period, open-ended. A `QueryException` translated via
   `PostgresErrorClassifier::isExclusionViolation()`/`isCheckViolation()` also becomes
   `InvalidWorkplaceAssignmentStartDateException` (mirrors S11/S12 verbatim).

Two separate date exceptions are used, not one shared exception, mirroring S12's own
`InvalidFullSecondmentStartDateException`/`InvalidFullSecondmentEndDateException` split exactly —
the error response's `errors` key names a different field (`effective_from` vs `effective_to`)
depending on which command failed, so one shared exception class would blur that distinction at
the `bootstrap/app.php` mapping layer.

**`EndWorkplaceAssignment->handle(relationship, effectiveTo)`**
1. Re-fetch `EmploymentRelationship` fresh with `lockForUpdate()`.
2. Find the open Workplace Assignment period; reject with a new
   `NoActiveWorkplaceAssignmentException` if none — mirrors `EndFullSecondment` verbatim, including
   its deliberate choice to NOT reject an already-ended relationship (closing a stale open row is
   administrative correction, not "starting something new," same reasoning as S12 §7.3/§8.4).
3. Validate `effectiveTo` strictly after the open period's own `effective_from` — reject with
   `InvalidWorkplaceAssignmentEndDateException` otherwise.
4. Close it (`effective_to = effectiveTo`). No other table is written — "actual workplace" reverting
   to the underlying S11/S12 state is achieved purely by `ResolveActualWorkplaceForRelationship` no
   longer finding an open assignment row (mirrors S12 §15 verbatim).

No separate "extension" command: per ADR §12's own instruction ("extension is a new decision/movement
— model per that source rule"), extending an assignment is simply a new `StartWorkplaceAssignment`
call with the same destination and a later `effectiveFrom` — it closes the previous period and opens
an equivalent new one, which is what "a new تكليف decision, even to the same destination" means under
§S16.2's own decision-driven model. No period boundary is silently mutated; the history shows two
periods, exactly reflecting that two decisions were made.

## §S16.10 Employment-end consequence integration

`EndEmploymentRelationship` gains a third private method, `closeOpenWorkplaceAssignmentIfAny()`,
called alongside the existing `closeOpenFullSecondmentIfAny()` and `closeOpenStatusPeriodIfAny()`,
inside the same transaction, at the same `effectiveTo`, using the same "never called when nothing is
open" no-op discipline. No new lock is taken — the existing scoped UPDATE's implicit row lock already
covers the whole method (mirrors the doc-comment reasoning already on `EndEmploymentRelationship`
verbatim for its two existing consequences).

## §S16.11 Status independence

No S16 code reads or writes `hr.employment_status_periods` or any employment-status value. A
Workplace Assignment being open is entirely independent of the relationship's current Employment
Status — a non-on-duty status does not auto-close an assignment (no such coupling is written), and an
assignment being open does not affect status. The only place assignment and the relationship's overall
lifecycle interact is the one already-covered terminal case: employment ending (§S16.10).

## §S16.12 Decision type

Exactly one new `ref.decision_types` row, seeded via a new migration, direct `DB::table()->insert()`
(never through a command), mirroring `2026_10_04_000001_seed_ref_decision_types_transfer.php` verbatim:

- `code = 'ASSIGNMENT'` — uppercase technical code, Architecture-Authority-permitted under §14's own
  explicit clause ("a technical stable code MAY be architecture-assigned"), mirroring `TRANSFER`'s
  identical uppercase departure from the lowercase-snake_case convention used elsewhere.
- `name_ar = 'تكليف'` — the ADR's own source-supplied Arabic value, verbatim, no variation.
- `name_en = null` — no English gloss given; none fabricated (S05 §8 precedent, reused verbatim).
- No assignment sub-types are seeded (mirrors ADR §14's "No assignment subtypes" instruction
  verbatim, itself mirroring ADR-S14-002's identical Transfer instruction).

The stable discriminator checked in code is always `code === 'ASSIGNMENT'`, never `name_ar`/`name_en`
display text (identical discipline to Transfer's `code === 'TRANSFER'` check).

## §S16.13 Alerts / deferred automation

Confirmed absent: no `app/Console` directory, no `Kernel.php`, no `*Job.php` file anywhere in the
backend. Per §13, the 7-day alert is explicitly DEFERRED — no scheduler, queue job, or notification
subsystem is built in this stage. The core domain (`StartWorkplaceAssignment`/
`EndWorkplaceAssignment`/the read model) has zero dependency on any such future automation; nothing in
this design would need to change when that infrastructure is eventually built.

## §S16.14 Scope / security

Reuses S08's `ScopedAuthorizationChecker` unmodified. Three new permission codes, mirroring the
`hr.full_secondment_periods.*` family exactly:

- `hr.workplace_assignment_periods.view`
- `hr.workplace_assignment_periods.start`
- `hr.workplace_assignment_periods.end`

`StartWorkplaceAssignmentController`: checks scope against the destination unit (always) and, if a
current S11 placement is recorded, against that "source" unit too — identical two-target composition
to `FullSecondmentPeriodController::store()`. `EndWorkplaceAssignmentController`: when an open period
exists, checks scope against its own unit and against the current S11 placement's unit — identical to
`FullSecondmentPeriodController::end()`. Every scope decision happens inside the same transaction that
locks the relationship row first (the S12/S14 TOCTOU-closing shape, applied from the first draft here,
not corrected after adversarial review — per ADR §21's explicit instruction to apply the S15 lesson
proactively). Backend authorization is mandatory on every route; UUID possession alone never
authorizes (route model binding never substitutes for the scope check).

## §S16.15 Audit

Reuses S04's `AuditedCommandExecutor`/`AuditSpec` unmodified. `hr.workplace_assignment_period.start`
and `hr.workplace_assignment_period.end` actions, `changes` payload limited to safe identifiers and
consequence metadata only — `employment_relationship_id`, `organizational_unit_id`, `effective_from`/
`effective_to`, `decision_type_id` (start only) — never national ID, never employee name, never any
secret. Every audit snapshot is taken from data already read/locked inside the same transaction that
performed the write (the relationship row is locked first, in the controller, before any scope or
audit-payload read) — applying the S15 TOCTOU-lock-boundary fix proactively, per ADR §21, rather than
as a post-review correction.

## §S16.16 API

Two explicit command routes and one read route, nested under the existing
`/persons/{person}/employment-relationships/{employmentRelationship}` prefix, mirroring S12's
resource shape exactly (no generic PATCH, no arbitrary period mutation):

- `GET .../workplace-assignment-periods` — list history (`ListWorkplaceAssignmentPeriodsForRelationship`, mirrors `ListFullSecondmentPeriodsForRelationship`).
- `POST .../workplace-assignment-periods` — start (`StartWorkplaceAssignmentController::store`).
- `POST .../workplace-assignment-periods/end` — end (`EndWorkplaceAssignmentController::end`... actually combined into one controller, see §S16.17).

`GET .../actual-workplace` (existing S12 route) is NOT duplicated — `ResolveActualWorkplaceForRelationship`
is extended in place (§S16.8), so the existing endpoint automatically now also reports an active
assignment; no new "resolve" route is added, per §22's "reuse Employee 360/read-model conventions."

## §S16.17 Error model

| Exception | HTTP | Body shape |
|---|---|---|
| `EmploymentRelationshipAlreadyEndedException` (existing) | 409 | `{message}` |
| `ActiveFullSecondmentAlreadyExistsException` (existing) | 409 | `{message}` (raised by Start, pair 3) |
| `InvalidWorkplaceAssignmentDecisionTypeException` (new) | 422 | `{message, errors: {decision_type_id}}` |
| `ActiveWorkplaceAssignmentAlreadyExistsException` (new) | 409 | `{message}` (raised by `StartFullSecondment`, pair 2) |
| `NoActiveWorkplaceAssignmentException` (new) | 409 | `{message}` |
| `InvalidWorkplaceAssignmentStartDateException` (new) | 422 | `{message, errors: {effective_from}}` |
| `InvalidWorkplaceAssignmentEndDateException` (new) | 422 | `{message, errors: {effective_to}}` |

## §S16.18 Concurrency

Real two-PostgreSQL-connection tests (mirroring `ConcurrencyTest.php`'s existing shape) for every pair
ADR §19 names: StartAssignment vs StartAssignment; StartAssignment vs EndAssignment; StartAssignment
vs Transfer; StartAssignment vs StartFullSecondment; EndAssignment vs Transfer; EndEmploymentRelationship
vs StartAssignment. Every command re-fetches the relationship with `lockForUpdate()` as its first
statement — the same reentrant-lock discipline already established (a controller's lock and a nested
`AuditedCommandExecutor::run()`'s own re-acquisition are the same PostgreSQL row lock within one
transaction, not a double-lock hazard).

## §S16.19 Backdated / future-dated operations

No "today" comparison is ever made. `effectiveFrom`/`effectiveTo` are compared only against the
relationship's own `effective_from` and the relevant open period's own `effective_from` — identical to
every existing S11/S12/S14 command. A future-dated start or end is accepted exactly like any other
valid date (mirrors existing behavior verbatim — this stage introduces no new date-vs-now check
anywhere).

## §S16.20 Adversarial cases (input to §S16 adversarial review, Task #85)

Temporal overlap on Start/End; movement contradiction (attempting Assignment+Secondment
simultaneously open); actual-workplace ambiguity (tie between tiers); backdated/future-state
corruption; Assignment-vs-Transfer race; Assignment-vs-Secondment race; Employment-End race;
authorization bypass (missing/insufficient scope on either target); IDOR (relationship belonging to
another person); audit TOCTOU; history mutation attempt; decision-type bypass (inactive/wrong-code
id); PII leakage in audit payload; supervisory-domain leakage; partial-secondment leakage; S17
leakage.

## §S16.21 Deferred domains

Partial secondment, weekday allocation, Work Schedule, attendance, percentage allocation (§16);
supervisory title assignment, managerial hierarchy, acting-manager rules (§17); 7-day alert automation
(§13, §S16.13); any S17 work.

## §S16.22 Acceptance criteria

All ADR-S16-001 §24 tests pass; Pint clean; `git diff --check` clean; migration up/down/up verified;
zero BLOCKING adversarial findings; full backend regression green; no file outside S16 scope touched;
`_to_delete/` untouched.

## §S16.23 Test plan

See ADR-S16-001 §24 verbatim — implemented as `WorkplaceAssignmentFoundationTest.php` (feature tests,
mirrors `FullSecondmentFoundationTest.php`'s structure) plus additions to `ConcurrencyTest.php` for the
six named races, plus interaction-matrix tests added to `TransferFoundationTest.php` and
`EmploymentRelationshipLifecycleTest.php` for the two consequence-closing extensions.
