# S29 — Work Schedule Foundation / تأسيس جدول العمل

This document is the specification and ADR record for **S29 Work Schedule Foundation**. The
Executor (Claude Code Cloud) wrote it under the Architecture Authority's "S29 Full Execution
Authorization". Frozen documents from earlier stages are not rewritten.

- **Baseline:** `origin/develop @ 390b03d8046f9e1830c6476b6928a910f3b23887` (tag
  `s28-movement-temporal-integrity-corrective`).

## §S29.1 Purpose and scope

A Work Schedule records **which weekdays** an Employment Relationship is scheduled to work, as
dated history. S29 lays the foundation that later features consume, notably Partial Secondment
weekday allocation (ADR-S29-005). It deliberately stores nothing else:

- no working hours, start or end times, or shifts;
- no attendance, leave or holiday calendar;
- no allocation percentage or workplace per weekday;
- no organizational or default schedule.

## §S29.2 Discovery

- **Template:** the S20/S22/S26 relationship-owned temporal streams.
  - Half-open DATE periods, a CHECK on the interval, and a GiST EXCLUDE per relationship.
  - One explicit `Record…Period` command that closes the open period on insert.
  - Relationship-end consequence in `EndEmploymentRelationship`.
  - A nested REST index/store surface with plain RBAC, and an audit spec carrying previous-period
    metadata.
- **As-of result style:** S27's `ActualWorkplaceAsOf` state object.
- **Weekday references:** discovery found none. No reference catalog, enum or constant for
  weekdays existed before S29.
- **Guard tests:** earlier scope guards (HR/Reference `ScopeBoundaryTest`, S26 and S27 foundation
  guards) forbade `work-schedule` / `WorkSchedule` / `weekday` objects. S29 removes exactly those
  entries, following the established precedent (placement S11, secondment S12, transfer S14,
  assignment S16, qualification S23).
- **Result:** no frozen business rule had to change, so there is no architecture conflict.

## §S29.3 ADR decisions (as authorized)

| ADR | Decision |
|---|---|
| ADR-S29-001 | The schedule is owned by the **Employment Relationship**, never by Person. There is no inheritance on reappointment and no organizational-unit default. |
| ADR-S29-002 | Weekdays are **data-driven reference identities** with stable keys MONDAY…SUNDAY and Arabic labels. There is no hard-coded working week, no localized string used as an identifier, and no bitmask. Membership is explicit and relational. |
| ADR-S29-003 | **No default.** A missing schedule is UNKNOWN (`NOT_RECORDED`) and is never fabricated. |
| ADR-S29-004 | **Temporal model:** half-open DATE periods, at most one effective per relationship per date, adjacency allowed, history retained. A change creates a new period. |
| ADR-S29-005 | The write path is structured so a future Partial Secondment allocation validator runs **before any mutation**. S29 adds no allocation table and no plugin framework (§S29.17). |

## §S29.4 Ownership

`hr.work_schedule_periods.employment_relationship_id` is the only owner column.

- `hr.persons` and `hr.employment_relationships` carry no schedule column, so the current schedule
  is always derived from history.
- A second relationship of the same Person starts with no schedule (§S29.10).

## §S29.5 Weekday identities — `ref.weekdays`

The table holds exactly seven structural rows, seeded by the migration that creates it:

| code | iso_day_number | name_ar | name_en |
|---|---|---|---|
| MONDAY | 1 | الاثنين | Monday |
| TUESDAY | 2 | الثلاثاء | Tuesday |
| WEDNESDAY | 3 | الأربعاء | Wednesday |
| THURSDAY | 4 | الخميس | Thursday |
| FRIDAY | 5 | الجمعة | Friday |
| SATURDAY | 6 | السبت | Saturday |
| SUNDAY | 7 | الأحد | Sunday |

**Identity and constraints:**

- `code` is the stable identifier used by the API and the audit log. Labels are display only.
- `code` is UNIQUE and CHECK-limited to the seven codes. `iso_day_number` is UNIQUE and
  CHECK-limited to 1–7.
- The table has no `is_active`, `version`, "working day" or "weekend" column. No weekday is
  special.

**Protection:**

- Weekdays are structural: there is no administration command or route.
- The membership table's RESTRICT FK makes a referenced weekday undeletable.
- The `Weekday::CODES` constant mirrors the seeded codes for input validation only. It is not a
  working-week definition.

## §S29.6 Schedule persistence

**`hr.work_schedule_periods`** columns:

- `id` uuid PK;
- `employment_relationship_id` uuid, FK RESTRICT, indexed;
- `effective_from` date;
- `effective_to` date nullable;
- `created_at`.

It has two constraints:

- `work_schedule_periods_period_check`: `effective_to IS NULL OR effective_to > effective_from`;
- `work_schedule_periods_no_overlap`: GiST EXCLUDE on (`employment_relationship_id` =,
  `daterange(effective_from, effective_to, '[)')` &&).

The table is append-only: there is no `version` and no `updated_at`.

**`hr.work_schedule_period_weekdays`** columns:

- `work_schedule_period_id`, FK RESTRICT;
- `weekday_id`, FK RESTRICT, indexed;
- composite PRIMARY KEY `(work_schedule_period_id, weekday_id)`, so the database rejects a
  duplicate weekday.

## §S29.7 Schedule content rules

Weekday rules, enforced by `RecordWorkSchedulePeriod` for every caller:

- A schedule names **at least one** weekday. An empty selection is rejected.
- Every entry must be one of the seven codes, exactly as written. Localized labels, lowercase
  forms and numbers are rejected.
- The same weekday may not appear twice (also enforced by the PK).
- Any number of weekdays (1–7) is allowed, in any combination. S29 does not define a "normal"
  week.

Violations raise `InvalidWorkScheduleWeekdaysException` → 422 `errors.weekdays`. Membership is
returned in ISO order (Monday first), whatever order it was submitted in.

## §S29.8 The command — `RecordWorkSchedulePeriod`

`handle(EmploymentRelationship, string $effectiveFrom, list<string> $weekdayCodes)` runs inside
one transaction:

1. Lock the relationship row (`SELECT … FOR UPDATE`). A KNOWN end → 409
   `EmploymentRelationshipAlreadyEndedException`.
2. Validate the weekday selection (§S29.7).
3. Validate the date (§S29.9).
4. *ADR-S29-005 extension point (§S29.17).*
5. If the latest period is open, close it at the new `effective_from`.
6. Insert the new open period and its weekday membership.

All validation completes before the first write. There is no generic update, PATCH, end, delete
or correction command.

## §S29.9 Temporal and backdated behaviour

- A schedule may start **on** the relationship's `effective_from`, never before it.
- **Later schedule:** a start strictly after the latest recorded period's start closes the open
  latest period at that date (adjacent, `[a,b) [b,…)`). Its identity, start and weekdays are
  preserved.
- **Same weekday set:** recording the same set at a later valid date still creates a **new
  period**. It is not a no-op, because the effective event is itself history (the S26 CA-S26-01
  convention).
- **Future dates** are allowed. A future period does not become effective early (as-of is by
  date, §S29.10).
- **Backdated starts are conservative:**
  - a start on or before the latest recorded period's start (open, closed or future) is rejected
    with 422 `errors.effective_from` (`InvalidWorkSchedulePeriodDateException`);
  - the system never rewrites multiple periods and never partially closes one.
- **Gaps:** a gap in imported history (a closed period followed by nothing) is preserved. It is
  not stretched, and it reads as NOT_RECORDED.
- **Database backstop:** a CHECK or EXCLUDE violation at insert maps to the same 422.

## §S29.10 Employment lifecycle, reappointment and as-of reading

**Relationship end** (`EndEmploymentRelationship::closeOpenWorkSchedulePeriodIfAny`) uses the
exact S20/S22/S26 rule:

- If a period starts on or after the end date, or is closed after it, the end is rejected
  atomically (422 `errors.effective_to`). This includes a future-dated schedule. Nothing is
  truncated or deleted.
- Otherwise the open period is closed at exactly the end date, and its weekday membership is
  untouched.
- An already-ended period is never extended.
- The same path applies to status-triggered endings.

**Audit consequence flag:** `EmploymentRelationshipController` and
`EmploymentStatusPeriodController` record `work_schedule_period_closed_as_consequence: true`
using the locked before/after snapshot pattern.

**Other lifecycle rules:**

- **Ended relationship:** recording against an ended relationship is rejected with 409.
- **UNKNOWN_LEGACY:** a relationship whose end is UNKNOWN_LEGACY is not ended.
  - It may receive a schedule, which stays open.
  - The system never closes a schedule at a guessed date and never infers one for legacy data.
- **Reappointment:** a new relationship for the same Person starts with no schedule. The old
  history stays with the old relationship.

**As-of reader** — `ResolveWorkScheduleForRelationshipAsOf($relationship, $date)`:

- It takes an explicit date and never uses `today()`.
- It applies the half-open test `effective_from <= D AND (effective_to IS NULL OR D < effective_to)`.
- It returns the immutable `WorkScheduleAsOf` object:
  - `RESOLVED` with `weekdayCodes()` and `isScheduledOn(code)`;
  - `NOT_RECORDED` with no weekdays, where `isScheduledOn()` is false for every day. It never
    returns a default week.
- `ListWorkSchedulePeriodsForRelationship` returns the full history, most recent first.

## §S29.11 Concurrency

- **Serialisation:** every schedule writer takes the EmploymentRelationship row lock first:
  - `RecordWorkSchedulePeriod`;
  - the controller, before its previous-period audit snapshot;
  - `EndEmploymentRelationship`, through its scoped UPDATE.
- **Database backstop:** the GiST EXCLUDE constraint is the final guard.
- **`ConcurrencyTest` proves, with two real sessions:**
  - racing overlapping inserts cannot both commit;
  - the command waits behind an in-flight schedule write, then applies the ordinary rules to the
    committed state;
  - a recorder waiting behind an in-flight end sees the committed end and is rejected with 409.

## §S29.12 Security

There are two new permissions in module `human_resources`, following the S20–S26 naming
convention:

- `hr.work_schedule_periods.view`, which grants the history index;
- `hr.work_schedule_periods.record`, which grants recording.

No other HR or Reference permission grants either action, and the seed migration grants them to
no role.

## §S29.13 API

Both routes are nested under the existing person/relationship path:

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/work-schedule-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/work-schedule-periods
     { "effective_from": "YYYY-MM-DD", "weekdays": ["SUNDAY", "MONDAY", …] }
```

**Request and response:**

- **Response shape:** `{ id, employment_relationship_id, effective_from, effective_to, weekdays: [codes] }`.
- **Created:** POST returns 201.
- **Unknown fields ignored:** hours, shift, allocation, unit and knowledge-state fields are
  dropped.

**Errors and routes:**

- **Ownership:** a relationship that does not belong to `{person}` returns 404.
- **Methods:** PATCH, PUT and DELETE are not routed (405).
- **No other endpoints:** there is no as-of endpoint (no previous stage exposes one) and no
  weekday endpoint.

## §S29.14 Error mapping

| Failure | HTTP |
|---|---|
| `InvalidWorkScheduleWeekdaysException` | 422 `errors.weekdays` |
| `InvalidWorkSchedulePeriodDateException` | 422 `errors.effective_from` |
| `EmploymentRelationshipAlreadyEndedException` (existing) | 409 |
| Request shape (`effective_from` required date, `weekdays` present array of strings) | 422 |

## §S29.15 Audit

Each successful record writes one `hr.work_schedule_period.record` entry, with target
`hr_work_schedule_period` / period id:

- `changes`: `{ employment_relationship_id, effective_from, weekdays: [codes, ISO order] }`;
- `metadata`: `previous_period_id` (the superseded period, when one exists) and
  `previous_period_closed_at` (only when this command closed it).

The entry contains stable ids, dates and codes only: no PII and no localized labels. A rejected
write leaves no audit entry and no partial closure.

## §S29.16 Organizational scope

S29 uses plain RBAC only, exactly like S20/S21/S22/S26. A schedule is a property of the
relationship, not of an organizational unit, so no `ScopedAuthorizationChecker` check is added. A
unit-scoped schedule policy would be a separate Architecture Authority decision.

## §S29.17 ADR-S29-005 — Partial Secondment extension point

`RecordWorkSchedulePeriod` orders its work as:

1. lock;
2. all S29 validation;
3. **[future: validate dependent Partial Secondment weekday allocations against the resulting
   schedule timeline]**;
4. close the previous period;
5. insert.

A future validator therefore runs after every S29 check and before any mutation, inside the same
transaction and under the same relationship lock. It can reject atomically without restructuring
the write path. The spot is marked in code with an `ADR-S29-005` comment.

S29 adds no allocation table, no hook or event system, and no plugin framework.
`ResolveWorkScheduleForRelationshipAsOf` is the read side such a consumer would reuse.

## §S29.18 Compatibility

- **S27 (unchanged):** reports R1–R5, `ListReportingPopulationAsOf`, `ResolveActualWorkplace…`
  and the population are untouched. The actual workplace is not weekday-aware in S29 (tested).
- **S28 (unchanged):** the movement supersession rules are untouched. Recording a schedule changes
  no placement, secondment, assignment, status, category, contract, title or specialty row
  (tested).

## §S29.19 Tests

**New tests:**

- **`WorkScheduleFoundationTest`** (38 tests), which covers:
  - weekday identities, their constraints and protection;
  - both appointment types, and all seven weekdays;
  - no default / NOT_RECORDED, the HTTP index and store, and as-of boundaries;
  - weekday validation, both in the command and over HTTP, with ignored extra fields;
  - start bounds, later-schedule closure, the same-set new period, future dates, backdated
    rejection and gap preservation;
  - EXCLUDE, CHECK, PK and FK integrity, the table shape, and relationship independence;
  - relationship end: closure, no extension, rejection before a future schedule, imported-beyond
    rejection, and 409 after an end;
  - status-triggered and terminal endings, and UNKNOWN_LEGACY;
  - reappointment isolation;
  - S27/S28 separation and scope guards;
  - 401/403 and the permission split, foreign permissions, ownership 404, and no
    PATCH/PUT/DELETE;
  - audit weekday codes, superseded period and no PII, and a rejected write leaving no audit.
- **`ConcurrencyTest`:** three S29 cases (§S29.11).
- **`MigrationLifecycleTest`:**
  - an S29 rollback/reapply test;
  - the S02/S09 drain and rollback now include S29;
  - hr permission counts +2.

**Updated guards** (S29 objects are now authorized):

- HR `ScopeBoundaryTest`: 13 hr tables; `work-schedule` / `WorkSchedule` no longer forbidden.
- `DatabaseConstraintsTest`: hr table list.
- Reference `ScopeBoundaryTest`: 23 ref tables, adding `weekdays`.
- `ApiEndpointsTest`: permission list.
- S26/S27 foundation scope guards.

## §S29.20 Out of scope / deferred

- **Scheduling detail:** attendance, time tracking, working hours, shifts, rosters and overtime.
- **Calendars:** holidays and calendars.
- **Allocation:** Partial Secondment and weekday allocation (only the extension point exists).
- **Defaults:** organizational or default schedules, and inheritance.
- **History editing:** correction or rewrite of historical schedule periods, or ending a schedule
  independently of the relationship.
- **Reporting:** as-of HTTP endpoints, and report or export use of schedules.
- **Scope:** unit-scoped authorization for schedules.
