# S31 — Movement Expiry & Follow-up Foundation / تأسيس انتهاء الحركات والمتابعة والتنبيهات

This document is the specification and ADR record for **S31 Movement Expiry & Follow-up
Foundation**. The Executor wrote it under the Architecture Authority's "S31 Full Execution
Authorization". Frozen documents from closed stages are not rewritten; this document records where
S31 builds on them.

- **Baseline:** `origin/develop @ f4f2087fbe85626263f21b1694f386b17de4296b` (tag
  `s30-partial-secondment-foundation`).

## §S31.1 Purpose and scope

S31 turns the source-derived rule "a temporary movement is warned about 7 days before it ends" into
a domain foundation:

- a **derived** 7-calendar-day follow-up for every bounded temporary workplace movement;
- a persistent, idempotent follow-up record with a two-state lifecycle;
- mandatory stale-alert protection;
- a system scanner, callable from the scheduler, tests and a future worker;
- a read-only, organizational-scope-filtered API.

It is not a notification platform. There is no delivery channel (§S31.20), no UI, no workflow
engine, and no timeline mutation.

## §S31.2 Discovery

- **Movement streams (S12, S16, S30):**
  - each has `effective_from`, a nullable `effective_to`, and a destination unit;
  - S28 supersession truncates movements; S30 Partial periods carry weekday membership.
- **Automation schema:** the `automation` schema exists since S02 but held no object.
  - S31 is its first authorized use (§S31.6).
  - No new schema was needed.
- **Scheduler and queue:**
  - `routes/console.php` defined no schedule; production targets Redis for cache and queue (S02), and tests use the `array` and `sync` drivers.
  - No job or worker existed. The scan needs none: it is one bounded, idempotent statement set per day.
- **Clock:** no clock abstraction existed. All as-of readers take an explicit date. Only
  `PersonProfileValidator` reads `Carbon::today()`. S31 introduces the smallest boundary (§S31.12).
- **Audit:**
  - `AuditAppendService` is the only write path.
  - The S04 actor model already defines SYSTEM actors with a controlled `actor_label`, and keeps `Source::System` "for forward extensibility". S31 is its first consumer.
  - `BootstrapAdminCommand` is the precedent for a system actor writing audit directly inside its own transaction.
- **Security and scope:**
  - RBAC comes from `permission:` route middleware.
  - Scope comes from the unmodified S08 `ScopedAuthorizationChecker`.
- **Observation (frozen S15, not changed by S31):**
  - `EndEmploymentRelationship` closes only an **open** Full Secondment or Workplace Assignment.
  - A bounded one can therefore outlive the employment end.
  - The follow-up recheck handles this case (§S31.7). S31 never rewrites it.
- **Result:** no frozen rule contradicts the 7-day requirement, automatic return, follow-up
  ownership, or activation semantics. There is no architecture conflict.

## §S31.3 ADR-S31-002 — Covered movements

| `movement_type` (stable code) | Stream | Source table |
|---|---|---|
| `FULL_SECONDMENT` | S12 Full Secondment | `hr.full_secondment_periods` |
| `WORKPLACE_ASSIGNMENT` | S16 Workplace Assignment | `hr.workplace_assignment_periods` |
| `PARTIAL_SECONDMENT` | S30 Partial Secondment period | `hr.partial_secondment_periods` |

Transfer is permanent and has no expiry alert. Organizational Placement periods, which are closed
by later placements or transfers, are not temporary movements. The set is closed: it is the
`TemporaryMovementType` enum.

## §S31.4 ADR-S31-003 — The 7-day rule (`MovementExpiryPolicy`)

For a bounded movement ending on business date **E** (`effective_to`, exclusive):

- `due_date = E − 7` calendar days. This is DATE arithmetic: no working week, no weekend and no
  holiday logic.
- The follow-up is actionable on business date **D** when `E − 7 <= D < E`.
- The database enforces the arithmetic:
  `CHECK (due_date = expected_effective_to - 7)`.

## §S31.5 ADR-S31-001/004/013/018 — Temporal semantics, no end date, automatic return

**ADR-S31-001.** Periods stay half-open `[from, to)`; a movement is no longer effective at `to`.
S31 writes no synthetic "return movement" and never rewrites an expired period.

**ADR-S31-004.**
- An open-ended movement has no expiry follow-up.
- No end date is invented, and no warning is generated for it.

**ADR-S31-013 — Automatic return is a DERIVED effective state.**
- After `to`, the S27 as-of resolver returns the remaining valid timeline, which is the original
  placement unless another movement governs.
- **Full Secondment and Workplace Assignment:** `ResolveActualWorkplaceForRelationshipAsOf`
  returns the placement.
- **Partial Secondment:** `ResolveWeekdayActualWorkplaceAsOf` returns:
  - the Partial destination on an allocated weekday before expiry;
  - the underlying workplace on that same weekday at or after expiry;
  - the underlying workplace on an unallocated weekday.
- **Newer movement covering the expiry:** if one governs the old expiry date, the resolver
  returns it (no fake return).
- **Verified by tests:** these behaviours needed **no resolver change**, which is the design
  proof that the return is derived.
- **Note:** the older S12/S16 open-period reader behind `GET …/actual-workplace` remains frozen
  (CA-S27-01). It sees only open periods.

**ADR-S31-018.** The scanner writes only `automation.movement_expiry_followups`. A test snapshots
every `hr.*` table it could touch before and after a run and proves them byte-identical.

## §S31.6 ADR-S31-007/008/009/020 — Persistence: `automation.movement_expiry_followups`

**Columns**, holding ids, dates and stable codes only (no PII, no labels):
- `id` uuid;
- `followup_kind` (`EXPIRY_WARNING_7D`);
- `movement_type`;
- three nullable movement foreign keys (see below), and a generated `movement_id`;
- `employment_relationship_id`;
- `organizational_unit_id`;
- `expected_effective_to`;
- `due_date`;
- `status`;
- `suppression_reason`;
- `created_at`;
- `suppressed_at`.

**Polymorphic reference without a fake FK.**
- PostgreSQL cannot FK one column to three tables.
- The row carries three nullable **real** FKs (RESTRICT; movement history is never deleted) and a
  CHECK that exactly one is set and agrees with `movement_type` (an "exclusive arc").
- `movement_id` is a `STORED` generated column over the arc.

**Constraints**, all in PostgreSQL:
- `movement_expiry_followups_logical_key`: UNIQUE `(followup_kind, movement_type, movement_id,
  expected_effective_to)`.
- `movement_arc_check`.
- `due_date_check`.
- `kind_check`.
- `status_check`, which keeps status, reason and `suppressed_at` coherent. A SUPPRESSED row must
  carry a reason from the fixed code set. A test found and closed a gap where a NULL reason passed.

**Copied columns** (`employment_relationship_id`, `organizational_unit_id`, expected end):
- immutable, written by `INSERT … SELECT` from the authoritative movement row in the same
  statement, so they cannot drift;
- the unit copy exists so the read API can filter by scope without a three-way join.

**Lifecycle.** Rows are never deleted or edited except the single `ACTIONABLE → SUPPRESSED`
transition. There is no `updated_at`.

## §S31.7 ADR-S31-005/006/009 — Stale recheck and states

**States** (ADR-S31-009), the smallest model that preserves idempotency and audit:
- `ACTIONABLE`: emitted; the movement still ends as expected.
- `SUPPRESSED`: recognised as stale, with a stable reason.

No "processed" state is needed: nothing consumes a row yet, because delivery is deferred. A row
whose end date has been reached stays `ACTIONABLE` in storage (the movement ended as planned).
The API derives `state = LAPSED` for it. No transition, and no audit noise, is created merely
because time passed.

**`MovementExpiryFollowUpRecheck::staleReason`** reloads the authoritative movement and
relationship and returns the first applicable reason:

1. `RELATIONSHIP_ENDED`:
   - the Employment Relationship has a KNOWN end on or before the expected end, so there is no
     return to act on;
   - an UNKNOWN_LEGACY end is not a known end and is never treated as one;
   - it also covers a bounded movement that outlives employment (the S15 observation).
2. `TRUNCATED_EARLIER` / `END_DATE_CHANGED`:
   - the movement's current `effective_to` differs from the expected one;
   - the new end date is its own, independent logical follow-up.
3. `COVERED_BY_NEWER_MOVEMENT` (ADR-S31-006: an extension is a **new** period, never an
   overwrite). The expected end is covered when:
   - for a Full Secondment or Workplace Assignment: any Full Secondment or Workplace Assignment is
     effective on that date;
   - for a Partial Secondment: likewise, or other Partial periods effective on that date together
     allocate **every** weekday of this one. A partial continuation does not suppress the warning
     for a weekday that would still return.

The recheck runs in two places:
- immediately before emission, and no row is written for a stale candidate;
- on every later scan of an already emitted `ACTIONABLE` follow-up whose end is still ahead.

There is no "movement not found" reason: the FK RESTRICT and append-only history make it
structurally impossible.

## §S31.8 ADR-S31-017 — Activation boundary

- **No backfill.** The migration inserts nothing.
- **No retroactive alerts.**
  - The scan considers only movements with `E − 7 <= D < E`.
  - A movement that ended on or before D is history and never generates an alert.
- **Still relevant at first discovery.** A movement whose due date has passed but whose end is
  still ahead is discovered.
- **Future movements.** A future-dated movement inside the window is discovered.

## §S31.9 The scan (`ScanMovementExpiryFollowUps`)

For an explicit business date **D** (default: `BusinessDateClock`), one run does two things.

**1. Reconcile** existing `ACTIONABLE` follow-ups (§S31.7).

**2. Discover and emit.**
- **Discover:** one SQL statement over the three streams finds movements with `effective_to IS NOT
  NULL` and `E − 7 <= D < E` that have no follow-up for their **current** end yet. The order is
  deterministic.
- **Emit** each candidate in one transaction:
  1. lock the relationship row `FOR UPDATE` (the S10–S30 discipline; a movement command takes the
     same lock);
  2. check the window and run the recheck;
  3. `INSERT … SELECT … ON CONFLICT ON CONSTRAINT … DO NOTHING`;
  4. audit only a real insert.

**Outcomes** (`ExpiryFollowUpEmission`):
- `EMITTED`;
- `ALREADY_EXISTS`;
- `NOT_DUE`;
- `STALE`, with a reason.

**Result.** `ExpiryFollowUpScanResult` carries ids and codes only.

**Lock order everywhere:** relationship row first, then the follow-up row.

## §S31.10 ADR-S31-007 — Idempotency and concurrency

- **Logical identity:** `(followup_kind, movement_type, movement_id, expected_effective_to)` is
  unique in PostgreSQL. Scheduler retries and repeated scans are safe by construction.
- **Concurrent scans:**
  - two sessions inserting the same logical key produce **exactly one** row;
  - the second waits on the first, then its `ON CONFLICT DO NOTHING` is a silent no-op;
  - proven with two real sessions in `ConcurrencyTest`.
- **Movement race:**
  - the emission takes the same relationship lock as movement commands;
  - a movement command holding the lock with an uncommitted truncation makes the scanner wait,
    then the recheck sees the new end and the candidate is `STALE` with nothing written (also
    proven with two real sessions).

## §S31.11 ADR-S31-011 — Scheduler / worker

- **Scheduler command:** `masar:hr:scan-movement-expiry-followups` is a thin wrapper. It runs the
  service for the clock's business date and prints counts.
  - It has **no** `--date` option, so an operator cannot back-date a run into retroactive alerts.
- **Scheduler definition:** `Schedule::command(...)->dailyAt('01:00')->withoutOverlapping(60)` in
  `routes/console.php`. The definition holds **no** business rule.
  - `withoutOverlapping` only avoids redundant work; overlap would in any case be harmless.
- **No queue job.** A future worker can call the same service.

## §S31.12 ADR-S31-012 — Business date / clock

- `BusinessDateClock` is the single boundary; `today()` returns the business date at 00:00.
- `SystemBusinessDateClock` is bound in `AppServiceProvider`.
  - It uses the existing `config('app.timezone')`, which is UTC.
  - **No timezone policy is invented.**
- Tests bind a `FixedBusinessDateClock` or pass an explicit date. No domain logic calls
  `today()`/`now()` to decide a business date.
- The scan defaults to the clock only when no date is given.

## §S31.13 ADR-S31-014 — Partial Secondment

- **One period, one follow-up.** The follow-up belongs to the Partial **period**, never to a
  weekday.
- **Weekday allocation stays S30-authoritative.** Expiry removes the period from weekday
  resolution naturally; S30 coexistence rules are unchanged.
- **Partial-aware coverage.** Coverage is the only place weekdays matter, and it is
  conservative (§S31.7).

## §S31.14 Relationship lifecycle

- **Known end respected.** A KNOWN end on or before the expected end → `RELATIONSHIP_ENDED`
  (§S31.7).
- **UNKNOWN_LEGACY.** It is not a known end, and no date is fabricated (tested).
- **Status-triggered termination.** It behaves like a direct end (tested).
- **Reappointment.** A new relationship inherits nothing: follow-ups belong to their relationship
  (tested).

## §S31.15 Movement scope guards

- Transfer semantics are unchanged.
- S12, S16, S27, S28, S29 and S30 code paths are not modified.
- Regression tests anchor S27 population and as-of results before and after scans.

## §S31.16 ADR-S31-015 — Security

**Permission.** One new permission, in module `human_resources`, granted to no role:
`hr.movement_expiry_followups.view`.
- There is no record or manage permission, because follow-ups are written only by the system
  scanner.
- Backend enforcement is by `permission:` route middleware.

**System actor.** Audit provenance for the scanner is `Actor::system('SCHEDULER_MOVEMENT_EXPIRY')`
with `Source::System`.
- This is a controlled label added to the S04 fixed set. That is a specification change, recorded
  here, not runtime configuration.
- The label is chosen by application code and is never accepted from input.
- It is provenance only and grants no authority (S04 ERRATA-01). No SYSTEM principal is created.

## §S31.17 ADR-S31-015 — API and organizational scope

```
GET /api/v1/hr/movement-expiry-followups
    ?state=ACTIONABLE|LAPSED|SUPPRESSED|ALL   (default ACTIONABLE)
    &movement_type=…&employment_relationship_id=…&per_page=1..100 (default 25)
```

**States.**
- `ACTIONABLE`: emitted, and the end date is still ahead of the request's business date.
- `LAPSED`: emitted, and the end date has been reached (history).
- `SUPPRESSED`: recognised as stale, with its reason code.

**Response.** A standard paginated collection. Each item carries ids, dates, `status`, the derived
`state`, `suppression_reason`, `created_at` and `suppressed_at`.

**Scope.**
- The S08 `ScopedAuthorizationChecker` is applied per **destination unit** of each follow-up (the
  temporary workplace, the same unit S12/S16 reads are scoped to while a movement is in force).
- Rows outside scope are **absent**: no per-row 403/404, and the total never counts them, so
  existence does not leak.
- An inactive unit is never an eligible target (S08).

**Not provided.**
- No store, no PATCH/PUT/DELETE (405).
- No scheduler mechanics are exposed, and there is no UI.

Home-unit visibility (the employee's original placement) is deferred.

**Staleness between scans.** A follow-up made stale after its last scan stays `ACTIONABLE` until the
next scan suppresses it (at most one scheduling interval). Reads never write.

## §S31.18 ADR-S31-016 — Audit

Two actions, target `hr_movement_expiry_followup`:

- `hr.movement_expiry_followup.emit`:
  - `changes` carry the kind, movement type and id, relationship, unit, expected end, due date and
    status;
  - `metadata` carry the business date.
- `hr.movement_expiry_followup.suppress`:
  - `changes` carry the new status and the reason code;
  - `metadata` carry the movement identity, expected and **current** end dates, due date and
    business date.

The actor is the SYSTEM label above, and the payloads hold no PII. Only a real insert or a real
transition writes audit, so a scan that finds nothing writes none, and a stale candidate writes no
success entry.

## §S31.19 Reporting compatibility

R1–R5 are not implemented and no total or inclusion rule changes. Follow-up persistence is a
separate table with no effect on `ListReportingPopulationAsOf` or the as-of resolvers; tests
compare their output before and after scans.

## §S31.20 ADR-S31-010 — Delivery boundary

A due follow-up is surfaced only through the internal read API. There is no email, SMS, WhatsApp,
push, Firebase, mobile delivery or provider. The table carries no channel, recipient or delivery
state. A later delivery stage can consume `ACTIONABLE` rows.

## §S31.21 ADR summary

| ADR | Implemented as |
|---|---|
| 001 | Half-open semantics; automatic return derived (§S31.5) |
| 002 | Three streams; Transfer and Placement excluded (§S31.3) |
| 003 | `E − 7` calendar days, DB-checked (§S31.4) |
| 004 | Open-ended → no follow-up (§S31.5) |
| 005 | Recheck under lock before emission and on later scans (§S31.7) |
| 006 | New period, never overwrite; coverage recheck (§S31.7) |
| 007 | Logical key UNIQUE + `ON CONFLICT DO NOTHING` (§S31.10) |
| 008 | Minimal read model, no PII (§S31.6) |
| 009 | Two states (§S31.7) |
| 010 | No delivery (§S31.20) |
| 011 | Service, thin command, schedule (§S31.11) |
| 012 | `BusinessDateClock` (§S31.12) |
| 013 | Derived return (§S31.5) |
| 014 | One follow-up per Partial period (§S31.13) |
| 015 | Permission, scope (§S31.16/17) |
| 016 | System-actor audit (§S31.18) |
| 017 | No backfill, no retroactive alerts (§S31.8) |
| 018 | Scanner never mutates HR timelines (§S31.5) |

## §S31.22 Out of scope

- **Delivery channels:** email, SMS, WhatsApp, push, Firebase, mobile.
- **UI:** the frontend notification center.
- **Attendance and pay:** attendance, biometrics, hours, shifts, overtime, payroll, holidays,
  leave allocation.
- **Other movements and reporting:** supervisory assignment, R1–R5, exports, dashboards, import.
- **Engines:** generic workflow or notification engine.
- **Timeline and semantics:** historical correction, new Transfer semantics, changes to Partial
  coexistence rules.

## §S31.23 Deferred

- A consumer and delivery for `ACTIONABLE` rows (including per-recipient routing and
  acknowledgement).
- Home-unit visibility of follow-ups.
- A frontend surface for follow-ups.
- Closing bounded Full and Assignment movements when a relationship ends (a pre-existing S15 gap,
  handled here only by the recheck).

## §S31.24 Concurrency and atomicity summary

- Per-relationship serialization by the row lock; PostgreSQL uniqueness as the backstop.
- Emission and suppression are each one transaction. The audit entry commits with the state
  change, or neither does.

## §S31.25 Tests

**`MovementExpiryFollowUpFoundationTest`** (40 tests) covers matrix A–AP:
- **Eligibility:** bounded Full Secondment, Assignment and Partial each get a follow-up; open-ended
  movements get none; a Partial with several weekdays still gets one.
- **7-day rule:** the due date is exactly E − 7 across weekdays, months, years and leap years;
  nothing is emitted before the due date; the window excludes the end date; a late scan still
  emits.
- **Idempotency:** repeated scans and the `ON CONFLICT` path create no duplicate and no extra
  audit.
- **Stale recheck and suppression:**
  - truncation by a transfer and by an assignment;
  - end date changed later;
  - coverage by a newer movement, including Partial-versus-weekday coverage;
  - a known relationship end, a bounded Full outliving employment, and status-triggered
    termination.
- **Legacy and reappointment:** UNKNOWN_LEGACY, and reappointment isolation.
- **Activation boundary:** no retroactive alerts; a future or in-window movement is discovered.
- **Automatic return:** a derived state for Full, Assignment and Partial (allocated,
  after-expiry and unallocated weekdays), with no timeline mutation by the scanner.
- **Transfer:** a permanent transfer is outside the alert semantics.
- **Audit:** the emitted and suppressed payloads, the SYSTEM actor, and no audit noise.
- **Scheduler and clock:** the command, the schedule, and the business clock.
- **Database:** every uniqueness, CHECK and FK invariant.
- **API:** 401/403, read-only, scope isolation, states, filters and shape.
- **Regression anchors:** S12, S16, S27, S28, S29 and S30 behaviour.

**`ConcurrencyTest`** has three real-session cases:
- one row from concurrent identical scans;
- independence of a different logical end;
- the scanner waiting on an in-flight movement change and then rechecking stale.

**`MigrationLifecycleTest`:**
- an S31 rollback/reapply test;
- S31 rolled back first in the S02, S07, S09, S12, S16, S29 and S30 drain/rollback paths;
- `automation` is now non-empty, so the "refuses to destroy a non-empty schema" test names it.

**Guards updated** (S31 objects are now authorized):
- `automation` is claimed in `PostgresFoundationTest`;
- the Organization scope guard gets a sixth disclosed `organizational_unit_id` exception;
- the `ApiEndpointsTest` permission list.
