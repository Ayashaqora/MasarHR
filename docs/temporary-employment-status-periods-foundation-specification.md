# Temporary Employment Status Periods Foundation (S17) — Version 1.0

## S17.0 Provenance / Reconstruction Disclosure

Per ADR-S17-001 §2: the historical S17 roadmap wording was **not** recovered. The title used
throughout this document — **S17 — Temporary Employment Status Periods Foundation**
(تأسيس الفترات الزمنية للحالات الوظيفية المؤقتة) — is Architecture Authority's own reconstruction,
supplied directly in ADR-S17-001, not a claim about a historical roadmap document. This mirrors
S10's own provenance disclosure (`docs/employment-status-history-foundation-specification.md`
§0) and is repeated here per the same discipline, not because new evidence changed.

## S17.1 Source Evidence Classification (ADR-S17-001 §4)

Reproduced from the ADR, plus this stage's own classification of the ADR's specific asks against
repository evidence.

**FROZEN / SOURCE-SUPPORTED** (given directly by the ADR, traceable to the original HR analysis
or to already-implemented, already-approved S10/S15 architecture):

1. Active/inactive employment status is separate from workplace movement.
2. Transfer/Full Secondment/Workplace Assignment are workplace movements, not status.
3. Historical state must not be destroyed when current state changes.
4. Some detailed employment states are inherently temporal.
5. إيقاف عن العمل (`suspended`) requires temporal/history treatment, not a mutable scalar.
6. Leave-like states must preserve historical periods.
7. Employment status/detail can coexist with workplace movement.
8. Ending a temporary status must not erase unrelated movement history.

**PROPOSED, NOT FROZEN** (the ADR is explicit these are not automatically authoritative):

- A per-state table of "start required / end required / automatic return / end warning."
- Specific behavior proposals for مسافر / أسير / إيقاف عن العمل / إجازة بدون راتب /
  إجازة خارجية مرضية beyond their bare existence and temporal-history requirement.

**This stage's own additional classification**, derived from direct repository inspection
(§S17.2–§S17.4 below): every one of the eight FROZEN items above is **already implemented and
already tested** by S10 (`docs/employment-status-history-foundation-specification.md`) as
extended by S15 (`docs/employment-status-lifecycle-consequences-specification.md`). No FROZEN
item is unimplemented. Every PROPOSED item remains correctly unimplemented (automatic return,
alerts, per-state start/end-required metadata) — see §S17.10/§S17.11.

## S17.2 Repository Discovery

Inspected directly (source read, not inferred):

| Area | Finding |
|---|---|
| `ref.employment_status_categories` | 4 seeded (S05): `active`, `non_active`, `ended`, `terminal`. |
| `ref.employment_status_details` | 13 seeded (S06, migration `2026_09_26_000026`), **including all five ADR-named states**: `traveling` (مسافر), `captive` (أسير), `suspended` (إيقاف عن العمل), `unpaid_leave` (إجازة بدون راتب), `external_sick_leave` (إجازة خارجية مرضية) — all under category `non_active` — plus `on_duty` (على رأس عمله, category `active`). |
| `ref.employment_status_detail_behaviors` | 13 open-ended behavior periods seeded (S06, migration `2026_09_26_000027`), one per detail, mechanically derived from the detail's category: every `non_active` row has `participates_in_active_workforce=false`, `is_ongoing_relationship=true`, `is_relationship_ending=false`, `is_terminal=false`. Reader: `ResolveEmploymentStatusDetailBehaviorAsOf` (pure, as-of, returns `null`/UNRESOLVED rather than a guessed default). |
| `hr.employment_status_periods` (S10) | Temporal child of `EmploymentRelationship`. Half-open `[effective_from, effective_to)`. `EXCLUDE`/`CHECK` constraints (`TemporalConstraints` helper, identical to every other period table in this codebase). FK to `ref.employment_status_details` is **generic** — any of the 13 seeded codes, not a closed enum baked into application code. |
| `RecordEmploymentStatusPeriod` (S10) | The single command: locks the relationship, rejects if already ended, validates `effective_from` against the relationship's own start and the currently-open period's own start, resolves the S06 behavior as-of that date (rejecting UNRESOLVED), closes the open period (if any) at the new period's `effective_from`, inserts the new period, and — if the resolved behavior is relationship-ending or terminal — calls `EndEmploymentRelationship::handle()` in-process, same transaction. Never touches `hr.organizational_placement_periods`, `hr.full_secondment_periods`, or `hr.workplace_assignment_periods` — there is no code path that could. |
| `EndEmploymentRelationship` (S09, extended S15, extended S16) | Already closes: an open Full Secondment (S15 §8.1), an open Workplace Assignment (S16 §S16.10), and — the exact S17-relevant one — **an earlier-open Employment Status period** (S15 §8.3, `closeOpenStatusPeriodIfAny()`), all at the same `effectiveTo`, all in the same transaction, all already tested. |
| `EmploymentStatusPeriodController` | `GET`/`POST` only, nested under `{person}/{employmentRelationship}`, IDOR-checked, RBAC-checked (`hr.employment_status_periods.view`/`.record`), audited (`AuditedCommandExecutor`, action `hr.employment_status_period.record`), no PII in metadata, no PATCH/DELETE route exists. No S08 organizational-scope check — `EmploymentRelationship` carries no organizational-unit column (S10 spec §13), so there is no scope target to consume; this is documented, deliberate S10 architecture, not an S17 gap. |
| Automation infrastructure | **None exists anywhere in the backend.** No queue, no scheduler, no `app/Console` commands beyond the stock `inspire` example, no notification classes. Confirms the ADR's own expectation (§19: "If no frozen/general alert infrastructure exists: DEFER"). |
| `ref.leave_statuses`, `ref.leave_types` | S05 structure-only reference catalogs, **zero seeded rows**, zero references anywhere in the `HumanResources` module. Unrelated to `ref.employment_status_details`/`_behaviors` — a separate, still-empty catalog family. Out of S17 scope; not touched (ADR §14/§15). |
| `hr.employment_relationships` | No `current_status`/`temporary_status`/`leave_status` column, no duplicate active-state flag of any kind (verified directly against the S09 migration and Eloquent model). One authoritative as-of answer remains: derive from `hr.employment_status_periods`. |
| Decision types | `ref.decision_types` structure-only; S10 spec §10 already reasoned explicitly that employment-status transitions are not, by the original authorization's own wording, "formal HR decisions" requiring `decision_type_id` (unlike Transfer/Secondment, which more naturally correspond to an issued قرار and do carry it via S14/S12's own audit-only pattern). No new evidence in ADR-S17-001 changes this reasoning — the ADR's own §17 asks the same question this stage re-asks and reaches the same answer. |

## S17.3 Dependency Matrix

```
S05 (ref.employment_status_categories: 4 rows)
  -> S06 (ref.employment_status_details: 13 rows incl. all 5 ADR-named states + on_duty;
          ref.employment_status_detail_behaviors: 13 periods; ResolveEmploymentStatusDetailBehaviorAsOf)
  -> S09 (hr.persons; hr.employment_relationships; EndEmploymentRelationship)
  -> S10 (hr.employment_status_periods; RecordEmploymentStatusPeriod; full temporal mechanism,
          generic over any of the 13 seeded details)
  -> S15 (EndEmploymentRelationship.closeOpenStatusPeriodIfAny(): closes the open status period
          when the relationship ends via the DIRECT route, reconciling S10's own disclosed §18
          item 1 gap; also proves movement independence structurally in its own spec §7)
  -> S16 (EndEmploymentRelationship further extended for Workplace Assignment — status-period
          closing logic untouched, unaffected, still generic)
  -> S17 (THIS STAGE): every FROZEN requirement traces to an already-satisfied edge above.
```

No new dependency edge is required. S17 introduces no new consuming relationship that S10/S15
does not already have.

## S17.4 Gap Matrix

| ADR-S17-001 requirement | Status | Evidence |
|---|---|---|
| §7/§8 (frozen items 1–8) temporal/history treatment for the 5 named states | **NO GAP** | Generic `hr.employment_status_periods` + `RecordEmploymentStatusPeriod` already handle any of the 13 seeded details, including all 5, with full append-only history. |
| §9 status classification (no new ACTIVE/INACTIVE taxonomy) | **NO GAP — already satisfied by inaction** | S06's 4-category/13-detail taxonomy is reused as-is; nothing to add. |
| §11 on-duty semantics | **NO GAP — already correctly absent** | No code path anywhere fabricates an `on_duty` period automatically. Returning to duty is, today, an ordinary explicit `RecordEmploymentStatusPeriod(on_duty, date)` call — the same one mechanism used for every other transition. This is not a missing feature; it is the existing, correct, non-fabricating design. |
| §12 movement independence | **NO GAP — already proven structurally** | S15 spec §7 (quoted in §S17.2 table above): "independence is guaranteed by the absence of a code path, not merely by a passing test." `RecordEmploymentStatusPeriod` has zero references to any workplace-movement table. |
| §13 employment end interaction | **NO GAP — already implemented (S15) and tested** | `closeOpenStatusPeriodIfAny()`, S15 spec §8.3; existing test `EmploymentStatusHistoryTest::test_ending_the_relationship_directly_now_closes_the_open_status_period`. |
| §17 decision type | **NO GAP — not required, per S10's own already-approved reasoning** (§S17.2 table). |
| §18 automatic return | **NO GAP — correctly deferred, per the ADR's own instruction.** Proposed-only; no scheduler exists; not implemented. |
| §19 alerts | **NO GAP — correctly deferred.** No alert/notification infrastructure exists anywhere; building any here would violate §19's own instruction. |
| §14/§15 leave/absence domain expansion | **NO GAP — correctly out of scope and untouched.** `ref.leave_statuses`/`ref.leave_types` remain exactly as S05 left them. |
| §24 reporting compatibility | **NO GAP.** "Status as-of a date" is answerable today by a direct, ordinary query against `hr.employment_status_periods` (`effective_from <= date AND (effective_to IS NULL OR effective_to > date)`) — the same pattern `ResolveEmploymentStatusDetailBehaviorAsOf` already uses for behaviors. No new query class is required to make this *possible*; none is added here either, since building one with no concrete consuming report would be inventing surface no requirement names (S10 spec §12 made the identical call for "current status" and it still holds for "as-of"). |
| **Verification-coverage gap (not a production gap)** | **REAL, NARROW** | Three of the five ADR-named codes — `captive`, `suspended`, `external_sick_leave` — have **never been exercised through `RecordEmploymentStatusPeriod` or the API in any test**, only through the reference-seed classification test (`EmploymentStatusDetailSeedTest`). Movement-independence is tested against Full Secondment + Placement (`unpaid_leave`, S10-era test) but **never against Workplace Assignment** (S16 postdates this test file). No test names one of the 5 ADR states in the employment-end-closes-status-period scenario (that test uses `on_duty`). See §S17.12. |

**No FROZEN requirement is unimplemented.** The only real finding is a test-coverage gap, not a
production-code gap.

## S17.5 Core Discovery Question (ADR §6)

**Outcome A** — S10, as extended by S15, already models these temporary states correctly.
Lifecycle commands are **not** missing either: `RecordEmploymentStatusPeriod` (record/transition)
and `EndEmploymentRelationship`'s consequence (close-on-relationship-end) together already form a
complete lifecycle for every one of the 13 seeded details, the 5 ADR-named ones included. There is
no narrower sub-outcome ("only lifecycle commands are missing") that applies here — nothing at the
lifecycle-command level is missing either.

## S17.6 No Duplicate State (ADR §7)

Confirmed by direct migration/model inspection (§S17.2): no `current_status`, `temporary_status`,
`leave_status` snapshot, or duplicated active-state flag exists anywhere on
`hr.employment_relationships` or elsewhere. The single authoritative as-of answer remains: the
`hr.employment_status_periods` row with `effective_to IS NULL` (or, for a past date, the row whose
`[effective_from, effective_to)` covers it).

## S17.7 Temporal Model (ADR §8)

Unchanged from S10 (`docs/employment-status-history-foundation-specification.md` §5/§7/§8):
`DATE` business boundaries, half-open `[from, to)`, `TIMESTAMPTZ` audit column, full history
preservation, no hard delete, no overwriting a historical start, current state derived (never
stored redundantly). Backdated operations are validated against the relationship's own
`effective_from` and the currently-open period's own `effective_from` (§7.3 of the S10 spec,
unchanged). No "future-dated relative to today" restriction exists or is added — consistent with
every other temporal command in this codebase (S11/S12/S14/S16 apply the identical rule: validate
against the aggregate's own recorded dates, never against wall-clock "today").

## S17.8 Status Classification (ADR §9)

Unchanged. The existing 4-category/13-detail S05/S06 taxonomy is reused exactly as seeded. No new
ACTIVE/INACTIVE taxonomy is invented.

## S17.9 Individual Temporary States (ADR §10)

| State | Code | Category | Behavior (from S06, category-derived) | Start meaningful | End meaningful | Open-ended technically permitted | Employment-relationship interaction | Workplace-movement interaction | Reporting implication | Source rule status |
|---|---|---|---|---|---|---|---|---|---|---|
| مسافر | `traveling` | `non_active` | not in active workforce; ongoing relationship; not ending; not terminal | Yes — `RecordEmploymentStatusPeriod` | Yes — recording the next period (e.g. `on_duty`) closes it | Yes (`effective_to` nullable, as every period) | Relationship stays open (frozen item 3/7) | None — independent (frozen item 7/8, structurally guaranteed) | `counts_in_monthly_reporting` remains `null` (S06's own deferred item, not S17's to supply) | Existence + non-terminal temporal treatment: FROZEN. Specific start/end-required/auto-return table cells: PROPOSED, not implemented. |
| أسير | `captive` | `non_active` | (identical to `traveling`) | Yes | Yes | Yes | Relationship stays open | None — independent | Same | Existence + "requires temporal/history treatment": FROZEN (ADR frozen item 5, by direct extension — `captive` shares `suspended`'s and every other `non_active` detail's already-frozen temporal-history requirement, frozen item 6 "leave-like states"). Specific proposed-table cells: PROPOSED. |
| إيقاف عن العمل | `suspended` | `non_active` | (identical) | Yes | Yes | Yes | Relationship stays open | None — independent | Same | Existence + temporal treatment explicitly named FROZEN (ADR frozen item 5, verbatim). Proposed-table cells: PROPOSED. |
| إجازة بدون راتب | `unpaid_leave` | `non_active` | (identical) | Yes | Yes | Yes | Relationship stays open | None — independent (frozen item 6 "leave-like states must preserve historical periods" — satisfied) | Same | Existence + temporal treatment: FROZEN (frozen item 6). Proposed-table cells: PROPOSED. |
| إجازة خارجية مرضية | `external_sick_leave` | `non_active` | (identical) | Yes | Yes | Yes | Relationship stays open | None — independent | Same | Existence + temporal treatment: FROZEN (frozen item 6). Proposed-table cells: PROPOSED. |

Each state is deliberately **not** generalized to the others beyond what their shared category
(`non_active`) already, mechanically, grants them — per ADR §10's own instruction not to
generalize one state's semantics to all five. Nothing in this stage grants any of the five a
behavior beyond what their category already carries; no per-state special case is added, because
none is evidenced.

## S17.10 On-Duty State (ADR §11)

`on_duty` (على رأس عمله) is category `active`: `participates_in_active_workforce=true`,
`is_ongoing_relationship=true`. Its canonical representation is identical to every other detail —
a row in `hr.employment_status_periods`. **No automatic restoration exists or is added.** Ending a
temporary (`non_active`) period never fabricates a new `on_duty` period; the only way an `on_duty`
period is ever created is an explicit `RecordEmploymentStatusPeriod(on_duty, date)` call, made by
an authorized caller, exactly like every other transition. This matches the ADR's own instruction
("do not assume... If automatic restoration requires a consequential rule not already frozen: STOP
or defer") and no frozen rule requires it — deferred, not built, consistent with existing S10
architecture that was never a gap to begin with.

## S17.11 Automatic Return and Alerts (ADR §18/§19)

Both remain **PROPOSED, not FROZEN**, per the ADR's own explicit classification. Repository
discovery (§S17.2) confirms no scheduler, queue, or notification infrastructure exists anywhere in
this backend — building either here would mean inventing automation infrastructure this stage is
explicitly told not to build (§19: "Do NOT build notification infrastructure in S17"). Both are
recorded here as **deferred, open items**, not implemented, not blocking.

## S17.12 Movement Independence (ADR §12)

Already proven structurally, not merely by test, per S15's own specification (§S17.2 table,
quoting S15 spec §7 verbatim). `RecordEmploymentStatusPeriod`'s source contains zero references to
`hr.organizational_placement_periods`, `hr.full_secondment_periods`, or
`hr.workplace_assignment_periods` — independence is a structural absence of a code path, not a
behavioral choice that could regress silently. The one gap here is **coverage**, not correctness:
no existing test exercises this independence against a concurrently-open Workplace Assignment
(S16) specifically. See §S17.16 for the recommended (not yet authorized) closing action.

## S17.13 Employment-End Interaction (ADR §13)

Already fully implemented by S15's `EndEmploymentRelationship::closeOpenStatusPeriodIfAny()` and
already tested (`EmploymentStatusHistoryTest::test_ending_the_relationship_directly_now_closes_the_open_status_period`).
S17 adds no new temporal structure, so there is nothing new that could "survive beyond the
employment relationship" — the existing close-on-end consequence already governs every one of the
13 details generically, the 5 ADR-named ones included.

## S17.14 Decision Type (ADR §17)

Not required. S10 spec §10 already reasoned this exact question for the general employment-status
domain (not merely for the 5 named states) and concluded `decision_type_id` is not warranted
absent evidence the original authorization intended every status transition to be a "formal HR
decision" — evidence the ADR does not supply for status transitions specifically (unlike
Transfer/Secondment, which do carry it). No decision type is added; nothing here needed to be
STOPped, because nothing here requires one.

## S17.15 Security, Audit, API, Concurrency (ADR §21/§22/§23/§20)

All four are unchanged from S10 (as extended by S15), already implemented, already tested, and
sufficient without modification:

- **Security**: RBAC via `hr.employment_status_periods.view`/`.record` (existing). No S08
  organizational-scope integration, because `EmploymentRelationship` has no organizational-unit
  column to scope against (S10 spec §13) — not a gap, a documented architectural fact. Backend
  authorization is enforced on every route; UUID possession alone grants nothing (IDOR-checked).
- **Audit**: `AuditedCommandExecutor`, action `hr.employment_status_period.record`, allowlisted
  metadata (relationship id, status detail id/code, effective_from, and — only when true —
  `relationship_closed_as_consequence`/`ended_terminally`/`full_secondment_closed_as_consequence`).
  No national ID, name, or other PII. Already covered by dedicated tests for the presence and
  absence of each flag.
- **API**: `GET`/`POST` only, no PATCH/DELETE (tested directly), no generic edit/delete-history
  endpoint. No duplicate API surface is needed or added.
- **Concurrency**: two real two-PostgreSQL-connection races already exist and pass — status
  transition vs. status transition (`ConcurrencyTest::test_concurrent_overlapping_status_periods_for_the_same_relationship_are_serialised_by_the_exclusion_constraint`)
  and status transition vs. direct employment end
  (`test_concurrent_status_recording_and_direct_employment_end_for_the_same_relationship_are_serialised_by_the_relationship_row_lock`),
  plus status vs. Full-Secondment-start/end. "Backdated status vs. current transition" and "future
  status vs. relationship end" are not distinct race *mechanisms* — the date-value checks run
  strictly after the same `lockForUpdate()` acquisition already proven to serialize every other
  pairing; varying the date argument does not change which statement acquires the lock first, so a
  dedicated race test for a specific date value would not exercise any code path the existing races
  do not already exercise. No new race surface is introduced, because no new code is introduced.

## S17.16 Conditional Implementation Gate (ADR §26)

Per ADR §26: *"If no implementation is needed because S10 already fully satisfies the
source-supported S17 requirements: STOP and report S17 as NO-OP CANDIDATE for Architecture
Authority review."*

**That is this stage's own conclusion.** Every FROZEN requirement (§S17.1) is already implemented,
already correct, and already tested by S10 (as extended by S15/S16). No new migration, table,
column, command, exception, permission, route, or business rule is required. Creating any of these
now — a new `hr.temporary_employment_status_periods` table, a new `EndTemporaryStatus` command
mirroring S12/S16's Start/End shape, a new "is-temporary" flag, a new decision type — would mean
**inventing** structure this stage's own discovery found no evidence for, exactly the failure mode
§26 warns against ("Do NOT create new tables merely to make S17 appear substantial").

**This stage therefore does not proceed to implementation.** No production code, migration, or
business rule is added by S17.

**Self-review of this conclusion** (mirroring S09 §22/S10 §19's review-gate pattern):

- Does this conclusion infer a business rule from stage numbering alone? No — every claim traces
  to already-shipped S10/S15/S16 code, the S06 seed data, or the ADR's own text.
- Does it invent a reference value? No — zero new rows, zero new codes.
- Does it create a second authoritative status truth? No — `hr.employment_status_periods` remains
  the sole one.
- Does it touch `_to_delete/`, any frozen migration/model/command, or another project's
  terminology? No.
- Does it require Excel import, reporting datasets, or a frontend change? No.
- Does it silently drop a FROZEN requirement instead of implementing it? No — each FROZEN item is
  traced to specific, already-shipped, already-tested code in §S17.2/§S17.4.

**PASS — NO-OP CANDIDATE confirmed. Reporting to Architecture Authority per §26 rather than
proceeding further.**

### S17.16.1 Recommended (not authorized) follow-up

The one real finding — the narrow test-coverage gap at §S17.4's last row — is **not** a production
gap and does not block this conclusion. If Architecture Authority wants it closed, the minimal
action is: add tests (zero production code) to `EmploymentStatusHistoryTest.php` exercising
`captive`, `suspended`, and `external_sick_leave` through `RecordEmploymentStatusPeriod` and the
API (transition, movement-independence against an open Workplace Assignment specifically,
employment-end interaction using one of the 5 named codes). This stage does **not** perform that
addition unilaterally: per ADR §26's own instruction for a NO-OP outcome, execution stops here for
Architecture Authority's review, rather than substituting a smaller action of this stage's own
choosing and proceeding to git finalization on that basis.

## S17.17 Deferred Domains (ADR §14/§15)

Explicitly not built, per the ADR's own boundary: leave balances, annual entitlement, accrual,
carry-over, approval workflow, medical documentation, leave payroll calculations, leave
substitution, holiday calendars, time clocks, daily attendance, shift scheduling, late arrival,
overtime, absence deductions. None of these has any evidence of being FROZEN; none is touched.

## S17.18 Acceptance Criteria

Given the NO-OP conclusion, acceptance is: discovery PASS (§S17.2–§S17.5), source evidence
classification PASS (§S17.1), gap matrix shows zero production gaps (§S17.4), architecture
self-review PASS (§S17.16), no invented business rule, no new migration/table/route/permission, no
regression risk (nothing changed), `_to_delete/` untouched, no S18 work. All satisfied by
construction, since no code changes.

## S17.19 Test Plan

**Executed by this stage**: none (no production code changed; the existing S10/S15/S16 regression
suite already covers every FROZEN requirement generically, and re-running it against an unchanged
codebase would prove nothing new).

**Recommended, pending authorization** (§S17.16.1): targeted test-only additions naming the three
previously-unexercised states and Workplace-Assignment-specific movement independence.

## S17.20 Closure Record (ADR-S17-002 §3)

Authorized by ADR-S17-002 ("S17 NO-OP Closure Authorization — Test & Specification Closure
Only"), which accepted this document's own discovery conclusion and authorized exactly two
things: placing this specification in the real repository, and closing the confirmed
test-coverage gap with test-only regression coverage. Restated explicitly, for a single-place
record, as ADR-S17-002 §3 requires:

1. **Historical S17 roadmap title was not recovered.** No historical wording is claimed or
   invented anywhere in this document (§S17.0).
2. **The S17 title used throughout is Architecture Authority's own reconstruction** —
   "Temporary Employment Status Periods Foundation" / تأسيس الفترات الزمنية للحالات الوظيفية
   المؤقتة — supplied directly by ADR-S17-001 §2, not asserted as a recovered historical name
   (§S17.0).
3. **Production implementation = NO-OP.** No migration, table, column, command, exception,
   permission, route, or business rule was added for S17. The only real-repository changes this
   stage makes are this specification document and test-only regression coverage (§S17.16).
4. **S10 owns temporal employment-status history.** `hr.employment_status_periods` and
   `RecordEmploymentStatusPeriod` (S10) remain the single, unmodified, authoritative temporal
   mechanism for every employment-status detail, the five ADR-S17-001-named states included
   (§S17.2, §S17.5).
5. **S15 owns the employment-end closure consequence.** `EndEmploymentRelationship`'s
   `closeOpenStatusPeriodIfAny()` (S15, unmodified by S17) remains the sole mechanism closing an
   open status period when a relationship ends via the direct route (§S17.13).
6. **No duplicate temporary-status structure is permitted, and none was added.** No
   `current_status`/`temporary_status`/`leave_status` snapshot or second active-state flag exists
   anywhere in the schema; the single authoritative as-of answer remains derived from
   `hr.employment_status_periods` (§S17.6).
7. **Automatic return behavior remains deferred/unfrozen.** The ADR classifies it PROPOSED, not
   FROZEN; no automatic-return code exists or was added (§S17.10, §S17.11).
8. **Alerts remain deferred.** PROPOSED only; no notification/scheduling infrastructure exists in
   this backend, and none was added (§S17.11).
9. **Full Leave Management is out of scope and untouched.** `ref.leave_statuses`/`ref.leave_types`
   remain exactly as S05 left them — zero seeded rows, zero new references (§S17.17).
10. **Attendance/absence management is out of scope and untouched.** No time-clock, shift,
    overtime, or absence-deduction concept exists or was added (§S17.17).
11. **S18 is not opened.** No S18-scoped file, table, route, or concept was touched at any point
    in S17's discovery, specification, or closure work.
