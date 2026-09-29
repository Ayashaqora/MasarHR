# S30 — Partial Secondment Foundation / تأسيس الانتداب الجزئي

This document is the specification and ADR record for **S30 Partial Secondment Foundation**. The
Executor (Claude Code Cloud) wrote it under the Architecture Authority's "S30 Full Execution
Authorization" and the "S30 Architecture Conflict Resolution" (ADR-S30-007). Frozen documents from
earlier stages are not rewritten; this document records where S30 extends them.

- **Baseline:** `origin/develop @ ca6aad5e8fc81cc87ec6b62c7a9e17b345957b5d` (tag
  `s29-work-schedule-foundation`).

## §S30.1 Source boundary

The original HR source established general movement concepts. It did not specify Partial
Secondment weekday allocation. The frozen MasarHR architecture later refined Partial Secondment
as a distinct movement capability, and S30 implements that refinement. S29's Work Schedule
architecture is not reopened.

## §S30.2 Discovery

**Streams inspected:**
- S12 Full Secondment, S14 Transfer, S16 Workplace Assignment, S28 supersession
  (`SupersedeTemporaryWorkplaceMovement`).
- S27 as-of readers (`ActualWorkplaceAsOf`, `ListReportingPopulationAsOf`).
- S29 Work Schedule and its ADR-S29-005 extension point.
- `EndEmploymentRelationship` and the two controllers that end a relationship.
- S08 `ScopedAuthorizationChecker`, the audit executor, the concurrency harness, and
  `TemporalConstraints`.

**Stop and resolution:** discovery stopped once (S30_ARCHITECTURE_CONFLICT) because the frozen
rules did not decide Workplace Assignment ↔ Partial Secondment. The Architecture Authority
resolved it with ADR-S30-007 (supersession). No other conflict was found.

## §S30.3 ADR decisions

| ADR | Decision |
|---|---|
| ADR-S30-001 | Owned by the **Employment Relationship**, never Person. A reappointment inherits nothing. A period has a relationship, a destination unit, `effective_from`, `effective_to` and weekdays. There are no hours, percentages, attendance, shifts or payroll. |
| ADR-S30-002 | DATE business dates in half-open `[from, to)`. Adjacency is allowed. History is kept, and future periods are allowed. A period must lie inside the relationship. UNKNOWN_LEGACY never fabricates an end. |
| ADR-S30-003 | Weekdays are S29's structural `ref.weekdays` identities. At least one weekday, no duplicates, no bitmask, no localized identifiers, no hard-coded week. |
| ADR-S30-004 | Allocated weekdays must be in the recorded Work Schedule for every date of the period. NOT_RECORDED rejects and is never a default week. |
| ADR-S30-005 | Partial ↔ Partial may overlap in dates **only** with disjoint weekdays. Overlapping dates plus a shared weekday is rejected, whatever the destination. No winner, no truncation. |
| ADR-S30-006 | Full ↔ Partial overlapping in time is **rejected in both directions**. Never superseded. |
| ADR-S30-007 | Workplace Assignment ↔ Partial uses **S28 supersession** in both directions. The two are never effective together. Later history is never rewritten. (APPROVED by conflict resolution.) |
| ADR-S30-008 | Transfer at D truncates every Partial effective at D. A Partial starting on or after D rejects the transfer (the S28 conservative rule). |
| ADR-S30-009 | Relationship end closes every Partial still in force. A Partial starting on or after the end rejects the end atomically. The same applies to status-triggered endings. |
| ADR-S30-010 | A Work Schedule change is revalidated against Partial allocations **before mutation**. Removing an allocated weekday rejects atomically. |
| ADR-S30-011 | The actual workplace is weekday-aware: relationship + date + weekday → workplace. |
| ADR-S30-012 | S27's date-only reader never returns a false scalar. It adds an explicit `PARTIAL_ALLOCATION` state. R1–R5 and report totals are unchanged. |

## §S30.4 Temporal semantics

Periods use half-open DATE intervals. The effective test is:

```
effective_from <= D AND (effective_to IS NULL OR D < effective_to)
```

## §S30.5 Movement interaction summary

| New command \ existing effective movement | Partial Secondment | Full Secondment | Workplace Assignment |
|---|---|---|---|
| **Record Partial** | coexist if weekdays disjoint, else 409 | 409 (overlap anywhere in `[D, to)`) | truncated at D (rule 2); one starting in `[D, to)` → 409 |
| **Start Full** | 409 if any Partial overlaps `[D, ∞)` | unchanged S12 | S28 supersession (unchanged) |
| **Start Assignment** | every Partial effective at D truncated at D (rules 1, 3); one starting ≥ D → 409 | S28 supersession (unchanged) | S16 close-previous (unchanged) |
| **Transfer at D** | every Partial effective at D truncated; one starting ≥ D → 422 `errors.effective_to` | S14/S28 (unchanged) | S16/S28 (unchanged) |
| **End relationship at E** | every Partial in force past E closed at E; one starting ≥ E → 422 | S15 (unchanged) | S16 (unchanged) |

## §S30.6 Schema

**`hr.partial_secondment_periods`:**
- **Columns:** `id` uuid PK, `employment_relationship_id` (FK RESTRICT, indexed),
  `organizational_unit_id` (FK RESTRICT → `org.organizational_units`), `effective_from`,
  `effective_to` (nullable), `created_at`.
- **Constraints:**
  - `partial_secondment_periods_period_check`, the `TemporalConstraints` CHECK.
  - `period` daterange, `GENERATED ALWAYS AS (daterange(effective_from, effective_to, '[)')) STORED`.
    Because it is generated, a reversed interval is refused by `daterange()` itself (SQLSTATE
    22000).
  - UNIQUE `partial_secondment_periods_owner_period_unique (id, employment_relationship_id, period)`.
- **Deliberately no date-only EXCLUDE**, because overlap with disjoint weekdays is legal.

**`hr.partial_secondment_period_weekdays`:**
- **Columns:** `partial_secondment_period_id`, `weekday_id` (FK RESTRICT → `ref.weekdays`,
  indexed), `employment_relationship_id`, `period` daterange.
- **Primary key:** `(partial_secondment_period_id, weekday_id)`, which blocks a duplicate weekday.
- **`partial_secondment_period_weekdays_period_fk`:**
  - composite FK `(period_id, employment_relationship_id, period)` → the parent unique triple;
  - `ON DELETE RESTRICT`, update action NO ACTION, `DEFERRABLE INITIALLY DEFERRED`;
  - there is no `CASCADE`: the frozen S02 convention (`docs/database-persistence-foundation.md`,
    `MigrationDisciplineTest`) forbids it in any migration;
  - instead, `PartialSecondmentPeriod` re-copies owner and range onto its membership rows on every
    update (every truncation path goes through it), and PostgreSQL rejects at commit any
    transaction that leaves a copy disagreeing with its parent.
- **`partial_secondment_period_weekdays_no_overlap`:**
  - `EXCLUDE USING gist (employment_relationship_id WITH =, weekday_id WITH =, period WITH &&)`;
  - this is the authoritative "same relationship + overlapping dates + same weekday" guard.

**Other rules:**
- "At least one weekday" is enforced by the command inside its transaction. This is the S29
  precedent; a DB-level rule would need a deferred trigger.
- Tables are append-only: no version and no `updated_at`.

## §S30.7 Dates

- **Start:** `effective_from` must be strictly after the relationship's own start. This is the
  frozen S12/S16 movement rule; it is stricter than "not before", never looser.
- **End:** `effective_to` may be null (open-ended) or a planned end after the start.
- **Errors:** 422 on the offending field.
- **Ended relationship:** 409.
- **UNKNOWN_LEGACY:** not ended, so a Partial Secondment stays open. No end is guessed.

## §S30.8 The command — `RecordPartialSecondmentPeriod`

`handle(relationship, destination, effectiveFrom, ?effectiveTo, weekdayCodes)` runs in one
transaction.

**Validation (no writes):**
1. Lock the relationship row. A KNOWN end → 409.
2. Check the weekdays: non-empty, canonical codes, distinct. Otherwise 422 `errors.weekdays`.
3. Check the dates (§S30.7).
4. Check the Work Schedule coverage (§S30.9).
5. No Full Secondment may overlap the period (409).
6. No Partial Secondment may overlap it with a shared weekday (409).
7. No Workplace Assignment may start inside the period (409, rule 4).

**Writes:**
1. Truncate the assignment effective at D, if any (rule 2).
2. Insert the period.
3. Insert its membership rows by `INSERT … SELECT` from the parent row. The copy of owner and
   range is taken from PostgreSQL, never from PHP, and verified at commit by the deferred FK.
4. An exclusion violation maps to 409.

There is no generic update, PATCH, end or delete command.

## §S30.9 Work Schedule dependency (ADR-S30-004)

`PartialSecondmentAllocationRules::assertCoveredByWorkSchedule` walks the recorded schedule periods
that overlap the requested `[from, to)` interval, in order:

- **Coverage:** every date must be covered with no gap.
  - An open-ended Partial needs an open-ended schedule.
  - A gap is NOT_RECORDED and rejects, even for weekdays that happen not to fall in it. This is
    conservative, and a default week is never assumed.
- **Weekdays:** every covering schedule must contain every allocated weekday.
- **Errors:** 422 `errors.weekdays`.

## §S30.10 Partial ↔ Partial (ADR-S30-005)

- **Application check:** reject when any Partial of the relationship overlaps the requested period
  in time **and** allocates one of the requested weekdays (`whereHas('weekdays')`).
- **Database check:** the weekday-level EXCLUDE (§S30.6) is the authoritative backstop.
- **Allowed cases:**
  - disjoint weekdays on the same or overlapping dates coexist;
  - adjacent periods (`to = from`) may reuse a weekday.
- **Destination is irrelevant:** a conflict is rejected even with the same destination unit.
- **No truncation:** nothing is truncated to make room.

## §S30.11 Full ↔ Partial (ADR-S30-006)

A Full Secondment consumes the whole workplace allocation, so the two are rejected in both
directions:

- **Record Partial:** rejected with the existing 409 `ActiveFullSecondmentAlreadyExistsException`
  when any Full Secondment overlaps `[from, to)`.
- **Start Full:** the minimum integration into `StartFullSecondment`. It is rejected with 409
  `ActivePartialSecondmentExistsException` when any Partial overlaps `[D, ∞)`, whether in force
  at D or starting later. This check runs before the S28 assignment supersession. Nothing else in
  S12 or S28 changes.

## §S30.12 Workplace Assignment ↔ Partial (ADR-S30-007, APPROVED / SATISFIED)

**Rule 1 — the Assignment starts second** (`StartWorkplaceAssignment`):
- Every Partial effective at D is truncated at D, atomically, before the assignment is created.
  Several Partials may be effective at once (disjoint weekdays), so this uses
  `SupersedeTemporaryWorkplaceMovement::supersedeAllAt`.
- A Partial starting on or after D → 409 `ActivePartialSecondmentExistsException` (rule 4). This
  includes one starting on D, which truncation would erase.
- All streams are validated before any stream is changed.

**Rule 2 — the Partial starts second** (`RecordPartialSecondmentPeriod`):
- The assignment effective at D is truncated at D.
- An assignment starting inside the requested `[D, to)` → 409
  `ActiveWorkplaceAssignmentAlreadyExistsException` (rule 4).
- **Refinement of rule 4:** for a *bounded* Partial, only assignments starting before its end
  conflict. A later assignment starting at or after `to` needs no rewrite, so the Partial is
  accepted. An open-ended Partial conflicts with every later assignment.

**Rules 3, 5 and 6:**
- **Rule 3:** after any successful command there is no effective overlap between the two streams.
  Assignment is never an underlying layer beneath a Partial (§S30.17).
- **Rule 5:** supersession is never applied between Partials (§S30.10).
- **Rule 6:** supersession is never applied between Full and Partial (§S30.11).

**Consistency, audit and scope:**
- **Atomicity:** truncation and creation share one transaction. A creation failure after the
  planned truncation rolls the truncation back (tested with a forced insert failure).
- **Audit:** the assignment start audit's `superseded_movements` lists every truncated Partial
  (`stream: partial_secondment`). The Partial record audit lists a superseded assignment
  (`stream: workplace_assignment`).
- **Scope:** each superseded unit is scope-checked with the command's own permission (the S28
  precedent).

## §S30.13 Transfer (ADR-S30-008)

`TransferEmployee` applies the S14/S16 consequence, interval-aware since S28, to Partials:

- Every Partial effective at D is truncated at D. It was a partial override of the workplace that
  the transfer moves.
- A Partial starting on or after D rejects the whole transfer atomically, including the placement
  write, with 422 `errors.effective_to` (`InvalidPartialSecondmentEndDateException`). This mirrors
  the S12/S16 transfer conflicts.
- Periods that ended on or before D are untouched.
- **Result:**
  - `TransferResult::closedPartialSecondments()`;
  - the response gains `closed_partial_secondment_periods` (`[]` when none);
  - the audit `changes` gain `closed_partial_secondment_period_ids`.
- **Scope:** the controller scope-checks the unit of every Partial effective at D.

## §S30.14 Work Schedule change revalidation (ADR-S30-010)

The ADR-S29-005 extension point in `RecordWorkSchedulePeriod` is now connected:

- **When it runs:** after every S29 validation and before the previous schedule is closed.
- **Check:** `assertScheduleChangeKeepsAllocations` requires every Partial effective on or after the
  new start date to allocate only weekdays in the new set. This covers Partials in force at the
  date and future ones.
- **Violation:** 422 `errors.weekdays` (`WorkScheduleChangeInvalidatesPartialSecondmentException`).
  - No schedule row is written or closed.
  - No Partial is changed or ended.
  - No weekday is added to the schedule.
- **No effect otherwise:** schedules of relationships without Partials behave exactly as in S29.

## §S30.15 Security

**Permissions.** Two new permissions in module `human_resources`, following the naming convention:
- `hr.partial_secondment_periods.view`;
- `hr.partial_secondment_periods.record`.

They are granted to no role. No other HR or Reference permission grants either one.

**Rules.**
- **Backend enforcement:** authorization is always enforced on the backend, by route
  `permission:` middleware plus the controller's scope checks.
- **404/403 consistency:**
  - ownership mismatch (the relationship does not belong to the person) → 404;
  - unknown destination unit → 404;
  - permission or scope failure → 403.

## §S30.16 Employment end (ADR-S30-009)

`EndEmploymentRelationship::closeEffectivePartialSecondmentsAtEnd` handles Partials when a
relationship ends at E:

- **Rejection:** a Partial starting on or after E rejects the end atomically with S09's
  `InvalidEndDateException` (422 `errors.effective_to`).
- **Closure:** every Partial whose period is open or extends past E is closed at E. This includes
  a planned end beyond E. The membership range is re-copied (deferred FK, §S30.6).
- **Already ended:** an ended period is never extended.
- **Same path everywhere:** status-triggered and terminal endings use the same path.
- **Audit:** the relationship-end and status-record audits add
  `partial_secondment_closed_as_consequence: true` and `closed_partial_secondment_period_ids`.

## §S30.17 Weekday-aware actual workplace (ADR-S30-011)

**Reader.**
- `ResolveWeekdayActualWorkplaceAsOf($relationship, $date, ?$weekdayCode)` →
  `WeekdayActualWorkplaceAsOf`.
- The weekday defaults to the date's own ISO weekday.
- An explicit code answers "under the arrangement effective on this date, where on this
  weekday?"

**Evaluation**, built on the two existing readers:
1. S27's date-only result: UNRESOLVED and AMBIGUOUS pass through.
2. S29's schedule: a RESOLVED schedule without the weekday → `NOT_SCHEDULED`. A NOT_RECORDED
   schedule never makes a day non-working.
3. `PARTIAL_ALLOCATION`:
   - an allocated weekday → the Partial destination (`source: partial_secondment`, with the
     period id);
   - an unallocated scheduled weekday → the underlying workplace (the effective placement,
     `source: placement`).
4. Otherwise the S27 unit and source apply.

**Result.** `scheduleState()` exposes RESOLVED / NOT_RECORDED.

**No hidden Assignment layer.**
- A superseded assignment never reappears under a Partial.
- Legacy data with both effective is reported as AMBIGUOUS_MOVEMENT_STATE.

## §S30.18 S27 date-only compatibility and the reporting read model (ADR-S30-012)

**New state on `ActualWorkplaceAsOf`: `PARTIAL_ALLOCATION`.**
- `organizationalUnitId()` is null, so no scalar is fabricated.
- `underlyingOrganizationalUnitId()` gives the placement.
- `partialAllocations()` lists each period id, destination, dates and weekdays.
- Several disjoint Partials are valid allocation, **not** ambiguity.
- A Partial effective together with a Full Secondment or an Assignment is AMBIGUOUS, with the
  Partials listed among the competing movements. This is only possible with legacy or
  directly-written data.

**Reporting read model.**
- `ResolveActualWorkplaceForRelationshipAsOf` and `ListReportingPopulationAsOf` pass Partials as
  the fourth input, exactly as §S27.8 planned.
- The population stays **one SQL statement**, one row per relationship. Partials are aggregated
  per relationship with `LEFT JOIN LATERAL (… json_agg …)`.
- R1–R5, report totals and inclusion rules are unchanged.
- The read layer now exposes what future reporting needs: the original workplace, partial
  destinations, weekday allocations, schedule state (weekday reader) and ambiguity.

**Not changed:** the S12/S16 open-period `ResolveActualWorkplaceForRelationship`, which backs
`GET …/actual-workplace` for the Employee 360 UI. It still reports the placement for a
Partial-seconded employee, which is the true underlying workplace. Weekday detail is available
through the new reader. Changing that endpoint is UI/API expansion, which is deferred (§S30.25).

## §S30.19 API

Two routes, nested under the existing person/relationship path:

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/partial-secondment-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/partial-secondment-periods
     { "organizational_unit_id": uuid, "effective_from": "YYYY-MM-DD", "effective_to": "YYYY-MM-DD"|null,
       "weekdays": ["SUNDAY", "MONDAY", …] }
```

**Response:** `{ id, employment_relationship_id, organizational_unit_id, effective_from,
effective_to, weekdays }`. POST returns 201. The list is ordered most recent start first.

**Not provided:** no PATCH, PUT or DELETE (405); no end or correction route; no weekday endpoint;
no as-of HTTP endpoint; no UI.

## §S30.20 Error mapping

| Failure | HTTP |
|---|---|
| `InvalidPartialSecondmentWeekdaysException`, `PartialSecondmentOutsideWorkScheduleException`, `WorkScheduleChangeInvalidatesPartialSecondmentException` | 422 `errors.weekdays` |
| `InvalidPartialSecondmentPeriodDateException` | 422 on `effective_from` / `effective_to` |
| `InvalidPartialSecondmentEndDateException` (transfer) | 422 `errors.effective_to` |
| `PartialSecondmentWeekdayConflictException`, `ActivePartialSecondmentExistsException` | 409 |
| Existing `ActiveFullSecondmentAlreadyExistsException`, `ActiveWorkplaceAssignmentAlreadyExistsException`, `EmploymentRelationshipAlreadyEndedException` | 409 |

## §S30.21 Organizational scope

S30 uses the unmodified S08 `ScopedAuthorizationChecker`, with the S12/S16 shape.

**Record, with `…record`:**
- the destination unit is always checked;
- the current placement unit ("source") is checked when one is recorded;
- a superseded assignment's unit is also checked.

**Read, with `…view`:** the relationship's current resolved workplace, the same target as the
S12/S16 history reads.

**Other commands** extended by S30:
- an assignment start also scope-checks every Partial it supersedes;
- a transfer also scope-checks every Partial it truncates.

A caller can never end a movement in a unit outside their scope.

## §S30.22 Concurrency

**Serialisation.** Every writer to the Partial table takes the EmploymentRelationship row lock
first:
- `RecordPartialSecondmentPeriod`;
- `StartWorkplaceAssignment`;
- `StartFullSecondment`;
- `TransferEmployee`;
- `EndEmploymentRelationship`;
- the controllers, before their scope and audit snapshots.

`RecordWorkSchedulePeriod` takes the same lock before revalidating.

**Database backstop.** Partial ↔ Partial conflicts are also stopped by the weekday-level EXCLUDE
constraint. Assignment and Full live in other tables, so for them the row lock is the
serialisation point (the documented S28 approach).

**`ConcurrencyTest`, with two real sessions:**
- racing Partials sharing a weekday: one waits, then is rejected;
- racing Partials on disjoint weekdays: both commit;
- Assignment vs an in-flight Partial: the Assignment waits, then supersedes, leaving no overlap.

## §S30.23 Audit

**Record entry.**
- `hr.partial_secondment_period.record`, target `hr_partial_secondment_period`.
- `changes`: `{ employment_relationship_id, organizational_unit_id, effective_from, effective_to,
  weekdays }`.
- `metadata`: `superseded_movements` / `superseded_at` when an assignment was truncated, else
  `[]`.

**Consequence metadata** is added to the assignment start, transfer, relationship end and status
record audits, as described above.

**Rules.**
- Only ids, dates and stable codes: no PII and no localized labels.
- A rejected write leaves no success audit.

## §S30.24 Tests

**`PartialSecondmentFoundationTest`** (51 tests) covers the authorization matrix and the ADR-S30-007
tests:
- **Matrix A–AP:** every case except AJ and AK, which are in `ConcurrencyTest`.
- **ADR-S30-007 #1–#12:** #1, #2+#3, #4+#5, #6, #7, #8 (forced-insert-failure rollback in both
  directions), #10, #11 (inside P) and #12. #9 is in `ConcurrencyTest`.

**Other suites:**
- `ConcurrencyTest`: AJ, AK and ADR-S30-007 #9.
- `MigrationLifecycleTest`: an S30 rollback/reapply test. The S02, S07, S09 and S29 rollbacks now
  roll S30 back first (RESTRICT FKs to `org.organizational_units` and `ref.weekdays`). HR
  permission counts +2.

**Guards updated** (S30 objects are now authorized):
- HR `ScopeBoundaryTest`: 15 tables.
- `DatabaseConstraintsTest`.
- Organization `ScopeBoundaryTest`: the fifth disclosed `organizational_unit_id` exception.
- `ApiEndpointsTest`.
- S26, S27 and S29 scope guards: `partial` / `PartialSecondment` / `Allocation` no longer
  forbidden.

## §S30.25 Deferred / out of scope

- **Scheduling detail:** attendance and biometrics, clock-in/out, hours, shifts, overtime.
- **Pay and time off:** payroll, holiday calendars, leave allocation integration.
- **Other movements:** supervisory assignment; movement expiry automation and seven-day alerts;
  notification delivery.
- **Reporting and outputs:** R1–R5 aggregation, XLSX/PDF/Print, the Exports Center, dashboards,
  import.
- **UI and API expansion:** Employee360 UI expansion, and weekday detail on `GET …/actual-workplace`.
- **Editing and engine:** arbitrary timeline correction, an explicit "end partial secondment"
  command, and any generic movement-engine redesign.
- **Constraints:** a database-level "at least one weekday" rule (deferred trigger).
