# Employment Status Lifecycle Consequences (S15) — Version 1.0

## 0. Provenance

The exact historical wording/title of S15 is **not** recovered from this repository or from
Architecture Authority. No historical numbering claim is made.

Architecture Authority's "MASARHR — S15 COMPREHENSIVE CONTROLLED EXECUTION AUTHORIZATION" /
**ADR-S15-001 — Employment Status Lifecycle Consequences** (تأسيس آثار دورة الحالة الوظيفية) is
the current, active, governing authorization for this stage. Per its own §2: this document is an
Architecture/Dependency Reconstruction, not a recovered historical title.

**Reconstructed title: S15 — Employment Status Lifecycle Consequences.**

### ADR-S15-001 — RECONSTRUCTED / APPROVED FOR EXECUTION

S15 does **not** create a second employment-status-history model (S10 already, exclusively, owns
`hr.employment_status_periods` and its behavior contract). S15 exists to close two narrow,
already-disclosed consequence gaps between the existing status stream and the two other HR
temporal streams an employment relationship can carry open child state in when it ends: S12 Full
Secondment and S10's own status-period stream itself (§4 below).

## 1. Governed baseline

Repository `C:\Projects\MasarHR`, GitHub `Ayashaqora/MasarHR`, branch `develop`. S15 baseline
verified live before any work began: `HEAD = origin/develop = s14-transfer-foundation^{commit} =
eea5f40c90c4d01daedda7bd19fec224236527f5`; `origin/main =
45259c97ca8dda8101d628bfe20bdb969c807cf4`; only untracked path `_to_delete/` (untouched). No
drift from ADR-S15-001 §1's expected values.

## 2. Stage classification

"Employment Status Lifecycle Consequences" — the coordination layer between S10's frozen status
architecture and the workplace-movement streams (S11 Organizational Placement, S12 Full
Secondment) and S09's own relationship-ending mechanism, for the narrow set of consequences
ADR-S15-001 authorizes. Not a new domain; no new aggregate; no new reference catalog.

## 3. Authoritative domain boundary

- Employment Status (`hr.employment_status_periods`, S10) and Workplace Movement
  (`hr.organizational_placement_periods` S11, `hr.full_secondment_periods` S12) remain two
  independent dimensions (ADR-S15-001 §3/§7). S15 adds no column, no table, and no application
  code that lets a status transition rewrite workplace history.
- S15's entire code change is a **single orchestration point**: `EndEmploymentRelationship`
  (S09), the one command that ever sets `end_knowledge_state = KNOWN`, is extended to close
  whichever open child periods (Full Secondment, Employment Status) the ending relationship still
  has, at the exact `effective_to` it ends with. Every path that ends a relationship — the direct
  `POST .../end` route and S10's own in-process trigger from `RecordEmploymentStatusPeriod` —
  goes through this one command, so extending it once closes the gap for every caller, present and
  future, without duplicating logic per call site (§8 below).

## 4. Discovery gate / gap matrix

Full inspection of S09–S14 code and specs was performed before any implementation (per §4's own
requirement). Findings:

| Rule (ADR-S15-001 ref) | Status | Evidence |
|---|---|---|
| §5 status taxonomy — reuse the frozen 13-value list | **ALREADY IMPLEMENTED** | S06 seed migration `2026_09_26_000026_seed_ref_employment_status_details` seeds exactly the 13 codes ADR-S15-001 §5 names as examples (`on_duty`/على رأس عمله … `deceased`/وفاة), each attached to its category. No new status is created. |
| §6 temporary status history preserved, append-only | **ALREADY IMPLEMENTED** | `RecordEmploymentStatusPeriod` only ever closes-and-inserts; never updates/deletes a past period; covered by existing S10 tests. |
| §7 movement independence (status end ≠ movement end) | **ALREADY IMPLEMENTED, structurally** | The `non_active` category's seeded behavior row has `is_relationship_ending = false`; `RecordEmploymentStatusPeriod` has no code path that ever touches `hr.organizational_placement_periods` or `hr.full_secondment_periods`. A temporary-status transition cannot reach workplace-movement tables at all — independence is guaranteed by the absence of a code path, not merely by a passing test. |
| §8 employment-end consequences — **Full Secondment** | **MISSING** (disclosed, S12 spec §7.3) | `EndEmploymentRelationship` never closes an open `hr.full_secondment_periods` row. Still open after S14: `TransferEmployee` only closes a secondment as a consequence of *transferring*, never of *ending*. This is S15's core fix. |
| §8 employment-end consequences — **Organizational Placement** | **CONSIDERED AND REJECTED — left untouched** | No disclosed-gap precedent exists for S11 (unlike S12's explicit §7.3). §7's movement-independence principle argues against a status/employment change rewriting workplace history. `ResolveActualWorkplaceForRelationship` already, correctly, resolves an ended relationship to `unresolved()` regardless of any still-open placement row (§7.3 of the S12 spec, applied identically to placement). Inventing a "close placement on employment end" consequence would be new, unauthorized structure with no evidenced requirement. See §8.2 below. |
| §8 employment-end consequences — **open Employment Status period** | **MISSING** (disclosed, S10 spec §18 item 1) | `EndEmploymentRelationship` never closes the relationship's last open `hr.employment_status_periods` row when the relationship ends via a path *other than* `RecordEmploymentStatusPeriod` — i.e. the direct S09 `POST .../end` route. Proven by the existing, dedicated regression test `EmploymentStatusHistoryTest::test_ending_the_relationship_directly_leaves_the_open_status_period_unreconciled_by_design`, whose own comment cites this as "a known, disclosed gap rather than an accidental one" and the S10 spec's own §18 item 1: *"If a future stage adds [another ending trigger], that stage must reconcile it."* S15 is that stage. |
| §8 employment-end consequences — S14 Transfer-related temporal effects | **ALREADY IMPLEMENTED / NOT APPLICABLE** | `TransferEmployee`'s own effects (open a new placement, optionally close an active secondment) are already fully applied at transfer time. Nothing further is needed at employment-end time beyond the two open-child-period fixes above, which are the only two streams that can outlive an ended relationship. |
| §9 terminal consequences | **ALREADY IMPLEMENTED** | `is_terminal` behavior flag → `EndEmploymentRelationship` marks `Person.is_terminal`; `CreateEmploymentRelationship` already rejects any new relationship for a terminal person (`PersonIsTerminalException`) — future employment state is already structurally impossible. Terminal-category details route through the same `EndEmploymentRelationship` call, so they receive the same S15 secondment/status-period closes for free — no separate terminal-specific logic is needed. |
| §10 Leave boundary | **OUT OF SCOPE — confirmed absent** | No leave request/balance/entitlement/approval-workflow/type-administration infrastructure exists anywhere in the codebase. None is created by S15. |
| §11 Partial secondment / work schedule | **OUT OF SCOPE — confirmed absent** | No such tables or abstractions exist. None created. |
| §12 Supervisory assignment | **OUT OF SCOPE — confirmed absent** | No such domain exists; no already-frozen S10 consequence references it. |
| §13 automation | **DEFERRED — Option C, no automation subsystem** | No scheduler/queue/Job infrastructure exists: `routes/console.php` has only the default `inspire` command, no `app/Console/Kernel.php`, no Job classes. `Source.php`'s own docblock states SYSTEM is "reserved/unused in v1.0 — no queue/job entry point." No schema column anywhere records an expected/scheduled end date for a temporary status, and inventing one would encroach on the Leave boundary (§10). S15 implements command-level consequence behavior only. See §13 below. |
| §15 concurrency | **PARTIALLY COVERED → extended** | The existing row-level-lock argument (an `UPDATE` still acquires an implicit row lock for the rest of the transaction) already made `EndEmploymentRelationship` safe against concurrent `lockForUpdate()`-based commands; S15 adds real two-connection tests proving this holds for the two *new* in-process writes it adds. See §12/§21. |
| §16 authorization | **NO CHANGE NEEDED** | Reused from S08, unmodified. Neither touched controller performs organizational-scope checking today (`hr.employment_relationships` carries no organizational-unit column — S10's own disclosed non-requirement, spec §13); S15 adds no new client-facing input and no new endpoint, so no new scope surface is introduced. |
| §17 audit | **MISSING → extended** | Both controllers' existing before/after re-query metadata pattern (established by `EmploymentStatusPeriodController::store()`, S10 spec §14) is extended to surface the two new consequences. See §10 below. |
| §18 API | **ALREADY SATISFIED — zero new endpoints** | Reuses the existing `POST .../end` and `POST .../status-periods` routes verbatim. |
| §19 database | **ALREADY SATISFIED — zero new migrations** | Both closes are ordinary `UPDATE ... SET effective_to = ?` statements against the existing `hr.full_secondment_periods` and `hr.employment_status_periods` tables. No new table, column, or migration. |

**S15 is not redundant with S10.** Two real, previously-disclosed gaps remain open after S14 and
are within ADR-S15-001's own boundary (§8's explicit "Full Secondment" and "future/open employment
child periods" targets); no fabricated work is added beyond closing exactly those two gaps.

## 5. Authoritative status semantics (reused, unmodified)

The frozen S06/S10 status architecture is unchanged. The 13 seeded details and their category-
derived behavior flags (`active` → participates in workforce, ongoing, not ending, not terminal;
`non_active` → not participating, ongoing, not ending, not terminal; `ended` → not participating,
not ongoing, ending, not terminal, reappointment allowed; `terminal` → not participating, not
ongoing, ending, terminal, reappointment blocked) are reused exactly as S06 seeded them. No status
is added, removed, or reclassified. No canonical code is renamed to match an Arabic label.

## 6. Temporary status history (reused, unmodified)

`RecordEmploymentStatusPeriod`'s existing close-on-transition behavior is untouched: a temporary
(`non_active`) period is never deleted, only closed by the next transition's `effective_from` — S15
adds no new "revert to `on_duty`" mechanism, since none is required (§7 below) and none is
evidenced by frozen behavior.

## 7. Movement independence (reused, unmodified; verified structurally)

A temporary status ending does **not** cancel an otherwise-valid, independently-active Full
Secondment or Organizational Placement — proven not by convention but by the absence of any code
path from `RecordEmploymentStatusPeriod` (or any `non_active`-category transition) into
`hr.full_secondment_periods` or `hr.organizational_placement_periods`. S15 introduces no such path
either: the two new closes it adds live exclusively inside `EndEmploymentRelationship`, which only
ever runs for `ended`/`terminal`-category transitions (`is_relationship_ending` true), never for
`non_active` ones. New regression test: `test_a_non_active_status_transition_does_not_touch_full_secondment_or_placement`
(§21).

## 8. Employment-end consequences

### 8.1 Full Secondment — closed

`EndEmploymentRelationship::handle()` now, after its existing scoped `UPDATE` succeeds, checks
whether the relationship has a currently-open `hr.full_secondment_periods` row (`effective_to IS
NULL`) and, if so, closes it by calling S12's own `EndFullSecondment::handle()` in-process, inside
the same transaction — never a second `AuditedCommandExecutor::run()` (would double-audit/attempt
an unsupported nested transaction), exactly mirroring the established
`RecordEmploymentStatusPeriod` → `EndEmploymentRelationship` in-process pattern (S10 spec §11).
`EndFullSecondment` itself is **never modified** — reused verbatim, including its own
`lockForUpdate()` re-acquisition, which is reentrant within the same transaction (identical
argument already documented in `TransferController`'s own docblock for its analogous reuse). When
no secondment is open, `EndFullSecondment` is never called — its own `NoActiveFullSecondmentException`
is therefore never triggered by this path (the call is conditional on an existence check, not a
blind call-and-catch).

If the relationship's own `effective_to` does not fall strictly after the open secondment's
`effective_from`, `EndFullSecondment`'s own `InvalidFullSecondmentEndDateException` propagates
unchanged (already mapped to HTTP 422 in `bootstrap/app.php`) — this is judged correct, desired
behavior (§14.2 below), not a defect to work around.

### 8.2 Organizational Placement — deliberately left untouched

No consequence is added for S11. Reasoning (recorded here per ADR-S15-001 §8's own "evaluate...
at minimum" instruction, which requires evaluation, not necessarily action):

1. Unlike S12's own spec (§7.3, explicit disclosed limitation naming a "future consequence-wiring
   stage"), S11's own specification (`docs/organizational-placement-foundation-specification.md`)
   discloses no analogous forward-reference. There is no evidenced authorization to close it.
2. §7's movement-independence principle is a caution against, not a mandate for, a status/
   employment change rewriting workplace history — closing S11 history on employment end would be
   the same kind of unauthorized rewrite §7 warns against for the *opposite* direction (status
   ending closing movement).
3. `ResolveActualWorkplaceForRelationship` already, correctly, resolves any ended relationship
   (`end_knowledge_state = KNOWN`) to `unresolved()`, regardless of any still-open placement row —
   so no caller is ever told a terminated employee's "current" workplace is still open. The
   read-time consequence ADR-S15-001 §8 cares about ("must not leave impossible active child
   state" as observed by callers) is already satisfied for Placement without any write-time change.
4. No "close without replacement" command exists for Placement, and inventing one for S15 alone,
   with no other evidenced consumer, would be new, unauthorized structure (ADR-S15-001's own
   standing "do not invent business rules/structure" constraint).

Regression-guard test added: `test_ending_a_relationship_leaves_an_open_organizational_placement_period_untouched`
(§21) — proves this is a verified, intentional boundary, not an oversight.

### 8.3 Employment Status period — closed (S10's own disclosed gap, closed here)

`EndEmploymentRelationship::handle()` additionally checks for a currently-open
`hr.employment_status_periods` row and, when its `effective_from` is **strictly before** the
relationship's own `effective_to`, closes it (`effective_to = ` the relationship's own
`effective_to`). No separate "close status period" command exists anywhere in this codebase (S10
spec §11: "No separate close command exists") — this is inlined exactly as
`RecordEmploymentStatusPeriod` already inlines its own close-on-transition step, not delegated to
a new command.

This closes S10's own disclosed gap (spec §18 item 1) for the one path that gap always described:
ending a relationship via the direct S09 route while an earlier status period is still open.

When `EndEmploymentRelationship` is instead invoked in-process by `RecordEmploymentStatusPeriod`
(the status-triggered path), that command has already inserted the new ended/terminal status
period itself — open-ended, with `effective_from` set to exactly the same date passed to
`EndEmploymentRelationship` as `effectiveTo` (both derive from the same original caller-supplied
date). That period is **not** closed: it is the relationship's correct, final historical status,
and the database's own period-check constraint (`effective_to > effective_from`, strictly) makes
closing a zero-length period impossible regardless of caller — so "equal dates → leave open" is
implemented as a general rule (correct for both callers), not a special case keyed on which path is
calling. A genuinely later open period (its `effective_from` strictly *after* the relationship's
`effective_to` — an ended relationship backdated before its own current status began) is rejected
(§14.1), never silently closed or silently ignored. One orchestration point, three well-defined
outcomes (equal → leave open; earlier → close; later → reject), correct for every caller.

### 8.4 S14 Transfer-related temporal effects

No further action needed. `TransferEmployee`'s own consequences are already fully applied at
transfer time (new placement, optional secondment close); nothing about a relationship *ending*
interacts with Transfer beyond §8.1/§8.3 above, which already cover the only two streams capable
of being left open.

## 9. Terminal consequences (reused, unmodified)

Terminal-category transitions already mark `Person.is_terminal = true` (S09/S10, unmodified) and
`CreateEmploymentRelationship` already rejects any new relationship for a terminal person. Because
terminal details also carry `is_relationship_ending = true`, they route through the exact same
`EndEmploymentRelationship` call as non-terminal `ended`-category details — §8.1/§8.3's closes
apply identically, with no separate terminal-specific branch.

## 10. Leave boundary (reused, unmodified)

S15 builds no leave request, balance, entitlement, approval workflow, leave-type administration
beyond the already-existing `ref.employment_status_details` rows, or leave module UI. Nothing in
the two consequences implemented here required inventing that domain — both are ordinary period
closes against tables that already exist.

## 11. Partial secondment / work schedule — out of scope (confirmed, unmodified)

No table, column, or abstraction for Partial Secondment, weekday allocation, Work Schedule, or
attendance is created. The authoritative source material provides no implementation semantics for
these and none are invented here.

## 12. Supervisory assignment — out of scope (confirmed, unmodified)

No Supervisory Assignment domain is implemented. No already-frozen S10 consequence references an
existing implementation of it (none exists), so nothing is deferred as a *specific* named
consequence — this boundary is simply confirmed, not implemented.

## 13. Automation decision

**Option C — no automation subsystem.** Evidence: `routes/console.php` contains only Laravel's
default `inspire` command; no `app/Console/Kernel.php` exists; no Job class exists anywhere in
`app/` (a grep for `*Job*.php` matches only the unrelated `JobTitle*` reference-catalog classes);
`App\Modules\Audit\Domain\Source`'s own docblock states the `System` case is "reserved/unused in
v1.0 — no queue/job entry point" exists yet. No schema field anywhere records an "expected" or
"scheduled" end date for a temporary status period, and inventing one — the only way a due-status
processor could know what to act on — would itself be new, unauthorized structure encroaching on
the Leave boundary (§10/§11 forbid inventing that shape here). S15 therefore implements
command-level consequence behavior only (§8); no idempotent due-status processor, no scheduler, no
queue infrastructure is added.

## 14. Temporal behavior

### 14.1 Error model — Employment Status period close

Three-way comparison between the relationship's own `effective_to` and the open status period's
`effective_from`:

- **Equal** — the period is left open, untouched, no write attempted (§8.3; this is the normal
  status-triggered-ending shape, and is also the only sound choice generally, since the database's
  own period-check constraint forbids a zero-length closed period regardless of caller).
- **Strictly before** (`effective_to` after `effective_from`) — the period is closed; this is the
  disclosed direct-route gap S15 exists to fix.
- **Strictly after** (`effective_to` before `effective_from` — an ended relationship backdated
  before its own currently-open status began) — `InvalidEndDateException` (S09's own, existing
  exception — **not** a new class) is thrown before any write is attempted, mirroring
  `RecordEmploymentStatusPeriod`'s own identical pre-check shape for the same underlying kind of
  date problem. Reused rather than invented because this is fundamentally the same question S09
  already asks of `effective_to` ("is this end date valid for this relationship's own child
  state"), now extended to cover the status-period case. Already mapped to HTTP 422 in
  `bootstrap/app.php` — no new mapping needed.

### 14.2 Error model — Full Secondment close (disclosed asymmetry)

The secondment-closing path reuses `EndFullSecondment::handle()` wholesale rather than
reimplementing its own date-validation inline — the ADR-S15-001 §8 instruction to "orchestrate/
reuse the established application boundary" applied literally. Its own, S12-owned
`InvalidFullSecondmentEndDateException` therefore propagates on the same underlying condition,
rather than `InvalidEndDateException` as in §14.1. This produces two different exception *types*
for the same *kind* of validation failure (an end date too early for open child state), one per
child-period type, depending on whether an existing command was reused (Full Secondment) or the
check was inlined because no command exists to reuse (Employment Status period, §8.3). Both map to
HTTP 422 today; this asymmetry is disclosed here as an intentional consequence of "do not
duplicate already-correct S12 logic," not an oversight.

### 14.3 Half-open periods / DATE semantics

Unchanged. `DATE` effective dates, half-open `[from, to)` periods, `created_at`/audit timestamps
remain timestamp-based. Both new closes write only `effective_to`; no `effective_from` is ever
touched on either read or write. Backdated and future-dated relationship-ending calls are supported
exactly as far as the pre-existing `EndEmploymentRelationship`/`EndFullSecondment` date checks
already allow — S15 adds no new restriction and no new allowance beyond what §14.1/§14.2 describe.

## 15. Concurrency

`EndEmploymentRelationship`'s existing scoped, optimistic-concurrency `UPDATE` (`WHERE id=? AND
version=? AND end_knowledge_state != 'KNOWN'`) is **not** changed to an explicit `lockForUpdate()`
call — the existing command's own docblock reasoning (a genuine version race and "already ended"
are the same fact for this command) is correct and untouched. The `UPDATE` statement itself still
acquires an implicit row-level lock for the remainder of the transaction under standard PostgreSQL
semantics, so the two new closes S15 adds run under that same lock, and `EndFullSecondment`'s own
`lockForUpdate()` re-acquisition inside §8.1 is reentrant within the same transaction (identical,
already-established argument used by `TransferController`'s own reuse of `EndFullSecondment`).

Race analysis, each covered by a dedicated real two-PostgreSQL-connection test (§21):

- **Status vs Status** — already covered (S10, unmodified).
- **Status vs `EndEmploymentRelationship`** — the direct `/end` route racing a concurrent
  `RecordEmploymentStatusPeriod` call; both acquire the same relationship row lock (one via
  `lockForUpdate()`, one via the scoped `UPDATE`'s implicit lock), so they serialize — new test.
- **Status vs Transfer** — already covered (S14, unmodified).
- **Status vs `StartFullSecondment`** — new test, added for completeness per ADR-S15-001 §15's
  explicit list; not directly touched by S15's own code change, but the two now share a
  transitively-reachable table (`hr.full_secondment_periods`) through `EndEmploymentRelationship`,
  so explicit proof is added rather than assumed.
- **Status vs `EndFullSecondment`** — new test, same reasoning.
- **Status automatic consequence vs newer manual status** — not applicable; no automatic
  consequence exists (§13).
- **Employment end vs movement mutation** — the core new race: a concurrent `StartFullSecondment`
  (or `EndFullSecondment`) racing `EndEmploymentRelationship`'s new §8.1 close on the very same
  open secondment row. New test proves the relationship row lock genuinely serializes these, so
  the later-committing session always observes the first session's committed state rather than a
  stale pre-race snapshot (mirrors `test_concurrent_full_secondment_start_and_end_calls_...`'s
  existing shape exactly).

## 16. Scope / security

Reused from S08, unmodified. Neither `EmploymentRelationshipController::end()` nor
`EmploymentStatusPeriodController::store()` performs organizational-scope checking today (S10 spec
§13's own disclosed non-requirement — Employment Relationship carries no organizational-unit
column), and S15 adds no new client-facing input field and no new route, so no new scope-target
surface is introduced. RBAC permission codes are unchanged (`hr.employment_relationships.*`,
`hr.employment_status_periods.*`, both S09/S10, unmodified). UUID knowledge remains never
sufficient for authorization — both existing IDOR/ownership guards (`person_id` match) are
untouched.

## 17. Audit

Both existing `AuditSpec.metadata` closures are extended using the exact before/after re-query
pattern S10 already established and tested (`EmploymentStatusPeriodController::store()`, spec
§14) — never by widening `EndEmploymentRelationship`'s or `RecordEmploymentStatusPeriod`'s own
return type. **Both controller methods now run their entire body inside an outer `DB::transaction`
that locks the relationship row first** (`lockForUpdate()`, mirroring `TransferController::store()`'s
own established TOCTOU-closing shape), *before* taking either "before" snapshot — an adversarial-
review finding on an earlier draft caught that a plain, unlocked `SELECT` snapshot could be raced by
a genuinely concurrent, non-adversarial request (e.g. a `StartFullSecondment`/`EndFullSecondment`
call landing in the gap between the snapshot and the command's own locked re-check), producing a
false-positive or false-negative `*_closed_as_consequence` audit flag without corrupting the
underlying data. Fixed, not merely disclosed — see §22 item (a) and the dedicated regression test
in `ConcurrencyTest.php`. `$executor->run()`'s own `DB::transaction()` call becomes a savepoint
within this outer transaction, the same already-established-safe nesting `TransferController` uses.

- `EmploymentRelationshipController::end()` (action `hr.employment_relationship.end`): captures
  whether an open Full Secondment / open Employment Status period existed immediately before the
  command runs; after it runs (same still-open transaction), re-checks both. When a period that
  was open is now closed, adds `full_secondment_closed_as_consequence: true` and/or
  `status_period_closed_as_consequence: true` to metadata — present only when true, absent
  (never `false`) otherwise, mirroring S10's own established convention exactly.
- `EmploymentStatusPeriodController::store()` (action `hr.employment_status_period.record`): its
  existing `relationship_closed_as_consequence`/`ended_terminally` detection is extended with the
  same before/after check for an open Full Secondment, nested inside the existing
  `$closedAsConsequence` branch (a secondment can only be closed as an S15 consequence when the
  relationship itself was actually closed this call). Adds `full_secondment_closed_as_consequence:
  true` under the same present-only-when-true convention. No new metadata is added for the status
  period itself on this path — closing the *previous* status period is already that command's own
  primary action, not a secondary consequence, and was already audited via `changes` before S15.

No National ID, Person name, or other PII is added to either closure — both new checks read only
`employment_relationship_id`/`effective_to` off already-permitted tables, matching the existing
"relationship id is the established safe target identifier" precedent (S10 spec §14).

## 18. API

No new endpoint. `POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/end`
and `POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/status-periods`
are reused verbatim — same request/response shape, same status codes, same routes. ADR-S15-001
§18's "do not create a generic employee PATCH" / "no arbitrary client-controlled endpoint for
internal consequences" constraints are satisfied by construction: nothing new is client-reachable.

## 19. Persistence / database

Zero new tables, columns, indexes, or migrations. Both new closes are ordinary
`UPDATE ... SET effective_to = ?` statements against `hr.full_secondment_periods` and
`hr.employment_status_periods`, using each table's own existing period-check constraint as the
final backstop exactly as their own owning commands (`EndFullSecondment`,
`RecordEmploymentStatusPeriod`) already do.

## 20. Error model (summary)

| Exception | Source | HTTP | Trigger |
|---|---|---|---|
| `EmploymentRelationshipAlreadyEndedException` | S09, reused | 409 | Unchanged. |
| `InvalidEndDateException` | S09, reused | 422 | Unchanged (relationship's own date) **plus** new: relationship `effective_to` not after an open status period's `effective_from` (§14.1). |
| `InvalidFullSecondmentEndDateException` | S12, reused | 422 | New trigger via reuse: relationship `effective_to` not after an open secondment's `effective_from` (§14.2), reached only through S15's new conditional call into `EndFullSecondment`. |

No new exception class is introduced anywhere in S15.

## 21. Test plan

New tests added to `EmploymentRelationshipLifecycleTest.php` (command-level, direct `/end` route):

1. Ending a relationship with an active Full Secondment closes it at the same `effective_to`.
2. Ending a relationship (direct route) with an *earlier* open Employment Status period (its
   `effective_from` strictly before the new `effective_to`) closes it at the same `effective_to`
   (supersedes/renames the old disclosed-gap regression test — see §22).
3. Ending a relationship with an open Organizational Placement period leaves it untouched
   (regression guard for §8.2's considered-and-rejected decision).
4. Ending a relationship with **no** open secondment and **no** open status period succeeds exactly
   as before (no spurious writes) — regression guard.
5. Ending a relationship whose `effective_to` falls before an open secondment's `effective_from`
   throws `InvalidFullSecondmentEndDateException` (backdated edge case, §14.2).
6. Ending a relationship whose `effective_to` falls strictly before an open status period's
   `effective_from` throws `InvalidEndDateException` (backdated edge case, §14.1).
7. Ending a relationship via the API surfaces `full_secondment_closed_as_consequence` /
   `status_period_closed_as_consequence` in the audit entry's metadata only when each actually
   closed (four cases: both, secondment-only, status-period-only, neither — the two middle cases
   added after adversarial review to prove the two flags are independently correct, not merely
   both-true/both-false by coincidence).

New tests added to `EmploymentStatusHistoryTest.php` (status-triggered path):

8. Recording an `ended`/`terminal`-category status period against a relationship with an active
   Full Secondment closes the secondment as an in-process consequence.
9. The same call's audit entry carries `full_secondment_closed_as_consequence: true`.
10. A `non_active`-category transition never touches Full Secondment or Organizational Placement
    (§7, structural independence regression guard).
11. Recording an `ended`/`terminal`-category status period leaves the newly-inserted (ending)
    status period itself open — `EndEmploymentRelationship`'s new §8.3 check does not close it,
    confirming the "equal dates → leave open" rule holds for the status-triggered path specifically
    (not just the direct-route case), and that the final, correct status remains the current one.

New tests added to `ConcurrencyTest.php` (real two-PostgreSQL-connection races, §15):

12. `Status` (`RecordEmploymentStatusPeriod`'s lock step) vs direct `EndEmploymentRelationship` —
    serialized by the shared relationship row lock.
13. `Status` vs `StartFullSecondment` — serialized (completeness, §15).
14. `Status` vs `EndFullSecondment` — serialized (completeness, §15).
15. Employment end (with S15's new secondment close) vs a concurrent `StartFullSecondment`/
    `EndFullSecondment` on the very same open secondment — the core new race; proves the
    later-committing session observes the first session's committed state, not a stale snapshot.
16. The `/end` route's own audit-metadata "before" snapshot lock vs a concurrent
    `StartFullSecondment` — proves the fix for the adversarial-review finding in §22 item (a): the
    relationship row lock genuinely blocks a concurrent secondment-opening write for the snapshot's
    entire window, not merely narrows the race.

Full backend regression (`composer test`), Pint (`composer lint` / `vendor/bin/pint --test`),
`git diff --check`, and `tests/Feature/Database/MigrationLifecycleTest.php` (no new migration is
added, but the lifecycle test itself must still pass unmodified) are all run before any git action
(§23).

## 22. Adversarial scenarios (addressed by design, verified independently)

An independent adversarial review (mirroring the S14 process) found:

**(a) [Fixed, was BLOCKING] Audit-metadata "before" snapshot raced by a concurrent, non-adversarial
request.** An earlier draft of both controllers computed `$hadOpenSecondment`/`$hadOpenStatusPeriod`
via a plain, unlocked `SELECT ... exists()` *before* any transaction opened. A genuinely concurrent
request (e.g. `StartFullSecondment`/`EndFullSecondment` on the same relationship) could commit in
the gap between that read and the command's own locked re-check, producing a false-positive or
false-negative `*_closed_as_consequence` audit flag — a silent audit-provenance bug, not a data
corruption (the actual close/no-close decision, made inside the lock by the command itself, was
always correct). Fixed by wrapping each controller method's body in an outer `DB::transaction` that
locks the relationship row first (§17), mirroring `TransferController`'s own established
TOCTOU-closing pattern. See §21 item 16 for the regression test.

**(b) [Noted, within authorized scope] Full-Secondment-vs-Status-Period date-check asymmetry**
(§14.2) — verified as a real, reachable, but disclosed and intentional tradeoff: reusing
`EndFullSecondment` verbatim means an end date exactly equal to an open secondment's own start
throws (422), while the mirror-image status-period case (§14.1) is defined to leave the period open
rather than throw, because the database's own period-check constraint makes closing it impossible
regardless. No data corruption either way; both fail loud or resolve correctly, never silently.

Also checked and found sound by design: stale status overwrite; temporal overlap; future/backdated
corruption on either new close; a Status-vs-Transfer/Secondment/EmploymentEnd race;
stale-automation (not applicable, §13); authorization bypass / IDOR (no new surface, §16); history
deletion or rewrite (both new writes are ordinary `effective_to` closes on already-open rows, never
a delete or a rewrite of a closed period); a generic-endpoint bypass (no new endpoint, §18);
Leave-scope leakage (§10); Partial-Secondment-scope leakage (§11); S16 leakage (none — S15's own
boundary is exactly the two consequences in §8, nothing beyond); multiple-historical-periods
confusion (structurally impossible — the pre-existing EXCLUDE constraint guarantees at most one
open period per relationship per stream).

Final state after the fix in (a) was independently re-verified by the same reviewer: **0 BLOCKING,
0 MODERATE.**

## 23. Deferred items / open questions (disclosed, not blocking)

1. Organizational Placement is deliberately left open on employment end (§8.2) — a future stage may
   revisit this if an explicit business requirement is ever evidenced; none exists today.
2. Automation/scheduling for temporary-status due-dates remains fully deferred (§13) pending both
   scheduler infrastructure and an explicit, non-Leave-boundary-crossing business requirement.
3. The `InvalidEndDateException`/`InvalidFullSecondmentEndDateException` asymmetry (§14.2) is
   disclosed, not resolved — unifying it would require either modifying S12's own frozen
   `EndFullSecondment` (not authorized) or introducing a new shared exception type (not evidenced
   as required); left as two existing, already-mapped exception types.
4. Leave, Partial Secondment, Work Schedule, Supervisory Assignment: unchanged, still fully
   deferred (§10/§11/§12).

None of these represents an unresolved consequential policy inside S15's own boundary; each is a
named, explicit handoff.

## 24. Acceptance criteria

- Both disclosed gaps (S12 §7.3 Full Secondment; S10 §18 item 1 Employment Status period) are
  closed for every path that ends a relationship, via one orchestration point.
- Organizational Placement is verifiably untouched by any S15 code path.
- Zero new tables/columns/migrations/endpoints/exception classes.
- Full regression, Pint, diff-check, and migration-lifecycle test all pass.
- Real two-connection concurrency tests pass for every race ADR-S15-001 §15 names.
- Independent adversarial review returns 0 BLOCKING.
- No S16-scope work (Leave, Partial Secondment, Work Schedule, Supervisory Assignment) is
  implemented, invented, or scaffolded.
