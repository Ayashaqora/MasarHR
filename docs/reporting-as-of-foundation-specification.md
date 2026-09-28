# S27 — Reporting As-Of Foundation / تأسيس القراءة الزمنية للتقارير

**RECONSTRUCTED ARCHITECTURE STAGE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

This document records the specification and ADR for **ADR-S27-001 — Reporting As-Of Foundation**,
together with the Architecture Authority decisions **CA-S27-01** (current vs as-of semantics) and
**CA-S27-02** (historical movement overlap). The Executor (Claude Code Cloud) wrote it under two
authorizations:

- the "S27 Reporting As-Of Foundation — Full Controlled Execution Authorization";
- the "S27 Continuation — CA-S27-01 + CA-S27-02".

Every decision below comes from those two authorizations; none is invented here.

- **Baseline:** `origin/develop @ 39c121669d687c3a247d32ba55192da8915cfa88` (tag
  `s26-employee-specialty-history-foundation`).
- **Sources:** the two original analysis sources stay conceptually separate. No merged master
  analysis exists, and this stage creates none.

## §S27.1 Reconstruction disclosure

The historical roadmap wording for S27 was not recovered. The Architecture Authority supplied the
title above as a reconstruction, following the post-S26 domain coverage audit. It is used only as a
reconstruction.

## §S27.2 Discovery

**Existing as-of readers (before S27):**

- S20 category, S21 contract (actual validity), S22 job title and S26 specialty — all using the
  half-open rule.
- S06 status behavior (`ResolveEmploymentStatusDetailBehaviorAsOf`).
- S06 mappings: specialty→cadre, job title→administrator, contract type→population.

**Missing:** an as-of reader for the status period, the organizational placement and the actual
workplace.

**Existing current resolver.** `ResolveActualWorkplaceForRelationship` (S12/S16) takes no date. It
returns the **open** record (`effective_to IS NULL`) in the order secondment → assignment →
placement, and returns unresolved once the relationship end is KNOWN.

**Other facts:**

- No `reporting` schema tables and no read models exist.
- No command compares dates with today, and S16 §S16.19 accepts future-dated starts and ends.
- The S12/S16 secondment/assignment exclusion checks compare open records only.

The last two facts caused the two conflicts that CA-S27-01 and CA-S27-02 resolve.

## §S27.3 ADR-S27-001 — decisions

1. **Explicit business date.** Every S27 reader takes an explicit `as_of_date` DATE. Nothing hard-codes
   today, month end or month start. The foundation is period-neutral; a future monthly report derives
   its own date(s).
2. **Half-open rule everywhere:** `effective_from <= d AND (effective_to IS NULL OR d < effective_to)`.
3. **Live query / read model.** No materialized views, reporting tables, snapshots, ETL, caches or
   warehouse. No schema change (§S27.14).
4. **No output layer:** no PDF, XLSX, CSV, print, exports, dashboard, charts or report UI.
5. **No report business rules.** The foundation exposes resolved facts; each future report applies
   its own frozen population and grouping rule.
6. **Unknown policy.** UNKNOWN stays UNKNOWN and UNRESOLVED stays null. There is no "Other", no
   synthetic value, no fallback mapping, and no row is dropped because a dimension is null. Whether
   a report shows a «غير محدد» row is an output decision, not a foundation rule.
7. **Mapping reuse.** The existing S06 mappings are resolved on the same date. They are never
   duplicated, cached in HR tables, or created automatically. Unmapped means null.
8. **No partial secondment, work schedule or attendance** (§S27.8).
9. **No API.** S27 is an internal application read model (§S27.12).

## §S27.4 CA-S27-01 — current vs as-of (APPROVED)

- **`ResolveActualWorkplaceForRelationship` is unchanged.** It is the **latest recorded /
  open-period view**, and it may show future-recorded movements. S12/S16/S18 behavior is not
  modified, and future-dated movements stay allowed.
- **`ResolveActualWorkplaceForRelationshipAsOf` is new.** It is the **effective business-date view**:
  - a future-start movement is not effective before its start;
  - a future-end movement stays effective until its `effective_to`;
  - a future KNOWN relationship end does not end the relationship before that date.
- **Consistency when semantically equivalent.** The two resolvers agree when no future-recorded fact
  makes the latest recorded state differ from the state in force on the comparison date. The
  original unconditional-agreement requirement (§27 of the first authorization) is cancelled.
- **Tests demonstrate both cases.** In the ordinary case, current equals as-of today. With
  future-recorded facts, the two intentionally differ, and that difference is not a failure.

## §S27.5 Status and status behavior as-of

`ResolveEmploymentStatusForRelationshipAsOf(relationship, date)` returns the status period in force
on the date (with its status detail, interval and IDs), or null.

The behavior flags on the **same** date come from the existing S06
`ResolveEmploymentStatusDetailBehaviorAsOf`. The population exposes all six frozen flags:

- `participates_in_active_workforce`
- `is_ongoing_relationship`
- `is_relationship_ending`
- `is_terminal`
- `allows_reappointment`
- `counts_in_monthly_reporting`

A flag is null only when no behavior period covers the date. No new status semantics are introduced.

## §S27.6 Organizational placement as-of

`ResolveOrganizationalPlacementForRelationshipAsOf(relationship, date)` returns the S11 **original /
organizational** placement on that date. S14 transfers are already reflected in placement history.
It is never equated with the actual workplace, and it returns null when no placement covers the
date (nothing is fabricated).

## §S27.7 Actual workplace as-of (CA-S27-02)

`ResolveActualWorkplaceForRelationshipAsOf(relationship, date)` returns an `ActualWorkplaceAsOf`.

1. If the relationship is not effective on the date (start after the date, or a KNOWN end on or
   before it), the result is **UNRESOLVED**.
2. Otherwise these are resolved **independently** on the date: the placement, the full secondment
   and the workplace assignment. Each stream is exclusive per relationship, so there is at most one
   period of each.
3. `ActualWorkplaceAsOf::fromEffectiveFacts()` applies the single decision table:

| Effective temporary movements | Result |
|---|---|
| none | RESOLVED to the placement (source `placement`), or UNRESOLVED if no placement |
| exactly one full secondment | RESOLVED to the secondment destination |
| exactly one workplace assignment | RESOLVED to the assignment destination |
| more than one (secondment **and** assignment) | **AMBIGUOUS_MOVEMENT_STATE** |

In the ambiguous case no winner is chosen: not secondment-wins, not assignment-wins, not
latest-start, earliest-start or latest-recorded, and no fallback to placement.

**Precedence:** no new hierarchy is introduced. Where exactly one movement is in force, the result
matches the frozen S12/S16 semantics (a movement replaces the placement as the actual workplace).
Genuine ties, which S16 assumed impossible, are reported as ambiguous instead of being broken.

## §S27.8 Partial secondment boundary (limitation)

Partial secondment and Work Schedule are not implemented, so the actual workplace **does not claim
partial-secondment accuracy**. S27 invents no weekdays, allocations or partial destinations.

The resolver is structured for extension: partial secondment would become another independent input
to `fromEffectiveFacts()`. The reporting contract (state, unit, source, competing movements) would
not need rewriting.

## §S27.9 Reporting population read model

`ListReportingPopulationAsOf(as_of_date, ?relationshipIds)` returns
`list<ReportingPopulationRow>`, one row per relationship.

**Which relationships are included:**

- relationships with `effective_from <= d`;
- **excluding** only those with a KNOWN end on or before `d`;
- UNKNOWN_LEGACY rows are kept and flagged `relationshipAsOfState = END_UNKNOWN_LEGACY`. S09 defines
  them as "understood not to be current, end unknown"; that is not reinterpreted, and whether they
  count is a report decision;
- NOT_APPLICABLE rows and a future KNOWN end give `EFFECTIVE`.

**Fields on each row:**

- the date;
- `person_id`, `employment_relationship_id`;
- employment type ID and code, employee number scheme;
- relationship dates, end knowledge state, and as-of state;
- `gender_id`;
- status period ID, detail ID and detail code, plus the six behavior flags;
- organizational placement unit;
- `ActualWorkplaceAsOf`;
- employment category;
- contract period and contract type;
- job title;
- specialty;
- cadre category (S06), `is_administrator` (S06), population category (S06).

There is no universal "included" flag and nothing is persisted. Qualification is deliberately not
put into an as-of model: `PersonQualification` has no temporal semantics (S23).

## §S27.10 Reappointment

Each relationship is a separate episode and a separate row. A row's facts come only from that
relationship's own periods, so nothing is inherited or merged. Uniqueness by person in a headcount
is a future report rule.

## §S27.11 Query strategy and performance

- **One SQL statement** for the whole population, regardless of its size (tested: exactly 1 query
  for 6 relationships). There is no N+1.
- Every dimension is a `LEFT JOIN` with the half-open predicate, using the date bound once through a
  `WITH p AS (SELECT CAST(? AS date) AS d)` CTE.
- Every joined stream has a PostgreSQL `EXCLUDE` no-overlap constraint on its owner:
  - status, placement, secondment, assignment, category, contract, job title and specialty periods
    per relationship;
  - behaviors per status detail;
  - each S06 mapping per source.
  So at most one row matches per dimension, and relationships are never multiplied (tested: one row
  per relationship).
- The single-relationship readers use the same predicate. A test checks at 12 boundary dates that
  the population agrees with the existing S20/S21/S22/S26 resolvers.
- **Performance** is expected to rely on the existing `employment_relationship_id` / source-ID
  indexes and GiST constraints; no measurement was taken. Further optimization waits for evidence.

## §S27.12 Security and API

- **No endpoint** was added: no existing pattern requires one, and S27 is an internal read model.
  Nothing is reachable over HTTP, so no new permission is introduced and `reference.view` exposes
  nothing.
- A future reporting endpoint must reuse the existing HR read permissions and choose its
  organizational-scope rule.
- **Open question for a future reporting endpoint.** Relationship-level history readers (S10,
  S20–S22, S26) use plain RBAC without org-scope checks, by their own ADR decisions (for example
  ADR-S20-001 §8). The S11/S12/S14/S16 movement surfaces are scope-gated through
  `ScopedAuthorizationChecker`. A reporting endpoint that combines both kinds of fact must choose a
  single rule. That choice is not made or changed here.

## §S27.13 R1–R5 readiness (facts only; no report implemented)

| Report | Facts shown resolvable on one date (tests) |
|---|---|
| R1 Monthly Human Cadre | status + behaviors, specialty, specialty→cadre, placement, actual workplace |
| R2 Administrative by Job Title/Gender/Actual Work | job title, job title→administrator, gender, status, actual workplace (assignment destination), placement |
| R3 Not On Duty | status detail (the frozen reason), behaviors, placement, actual workplace |
| R4 Support Services / Daily Workers | contract period, contract type, type→population, status, workplace |
| R5 Volunteers / Unemployment by Specialty | type→population, specialty, status, placement |

Month aggregation, grouping, report inclusion and output are all deferred.

## §S27.14 Database

No migration and no persisted structure. The as-of semantics are fully expressible over the
existing schema.

## §S27.15 Write-side debt (recorded, not fixed)

Existing S12/S16 conflict checks can permit historical interval overlap after an earlier movement
has been closed: the checks compare open records, not full interval intersection. A future
corrective stage may strengthen temporal conflict validation. S27 only **detects** the resulting
ambiguity on reads; it modifies no write command.

## §S27.16 Tests

`tests/Feature/HumanResources/ReportingAsOfFoundationTest.php` (25 tests):

- **As-of readers:** status plus same-date behavior; placement across a transfer; full secondment;
  workplace assignment; relationship start and KNOWN end.
- **CA-S27-01:**
  - the ordinary case agrees;
  - a future secondment start;
  - a future secondment end;
  - a future assignment start and end;
  - a future relationship end.
- **CA-S27-02:** secondment [2026-02-01, 2026-06-01) plus an assignment from 2026-03-01. Results are
  RESOLVED on 01-31 and 02-28, AMBIGUOUS on 03-01, 04-01 and 05-31, and RESOLVED again on 06-01.
  Placement is still exposed separately.
- **Professional facts** at 12 boundary dates.
- **Mappings:** change boundaries for all three S06 mappings; unmapped gives null; a deactivated
  specialty still resolves.
- **Unknown:** legacy null gender, missing specialty, unmapped specialty/job title/contract type, no
  "Other", and an UNKNOWN_LEGACY relationship kept and flagged.
- **Reappointment isolation.**
- **R1–R5 readiness.**
- **Query strategy:** one query and one row per relationship; an explicit valid date is required.
- **Scope guards:** no materialized view, reporting table, snapshot, migration, route, output class,
  or partial-secondment / schedule / weekday / allocation table.

## §S27.17 Deferred

- **Reports:** R1–R5 queries, month aggregation, output (PDF/XLSX/CSV/print), Exports Center,
  dashboard, charts, report UI.
- **Movements:** partial secondment and Work Schedule (as-of workplace extension), and the
  write-side temporal-overlap validation corrective (§S27.15).
- **Access:** any reporting endpoint and permission, and the org-scope consistency debt.
- **Performance:** optimization based on measurement.
