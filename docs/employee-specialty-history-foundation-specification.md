# S26 — Employee Specialty History Foundation / تأسيس التاريخ الزمني لتخصص الموظف

**RECONSTRUCTED STAGE TITLE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

This document records the specification and ADR for **ADR-S26-001 — Employee Specialty History
Foundation**, together with the Architecture Authority's corrective decision **CA-S26-01**. The
Executor (Claude Code Cloud) wrote it under two authorizations:

- the "S26 Employee Specialty History Foundation — Full Controlled Execution Authorization";
- the "S26 Resume Authorization — CA-S26-01 Resolution".

Every decision below comes from those authorizations or from the existing S20/S22 temporal
convention. None is invented here.

- **Baseline:** `origin/develop @ 44ad56e6f647bc8f6a21137666b80b54d8360646` (tag
  `s25-specialty-catalog-administration-foundation`).
- **Sources:** the two original analysis sources stay conceptually separate. No merged master
  analysis exists, and this stage creates none.

## §S26.1 Reconstruction disclosure

The historical roadmap wording for S26 was not recovered. The Architecture Authority supplied the
title above as a reconstruction, following the post-S25 domain coverage audit. It is used only as a
reconstruction.

## §S26.2 Discovery

- **Closest pattern: S22** (`hr.employment_job_title_periods`, `RecordEmploymentJobTitlePeriod`).
  S22 itself mirrors S20 (`hr.employment_category_periods`). Both are:
  - owned by the Employment Relationship;
  - exclusive, half-open DATE periods, append-only;
  - validated against an active reference;
  - auto-closing on insert;
  - closed by the relationship-end consequence;
  - plain `hr.*` RBAC with no org-scope check;
  - nested routes, with no end, correction, PATCH or DELETE.
- **S25:** `ref.specialties` is administered by `reference.*` and has 0 seeded rows.
- **S06:** `ref.specialty_cadre_category_mappings` plus `ResolveSpecialtyCadreCategoryAsOf` return
  `null` (UNRESOLVED) when a specialty is unmapped.
- **Conflict found during discovery (resolved by CA-S26-01, §S26.4).** The S26 authorization's
  original §8 contradicted the S20/S22 convention for recording the same value again.

## §S26.3 ADR-S26-001 — decisions (as authorized)

- **A. Owner.** The Employment Relationship owns the specialty, never the Person. It does not carry
  across a reappointment.
- **B. Temporal model.** Effective-dated history, `[effective_from, effective_to)`, DATE. There is
  no current-specialty column.
- **C. Cardinality.** At most one specialty is in force at any date per relationship. No
  simultaneous specialties and no primary specialty.
- **D. Optionality.** Optional. No period means UNKNOWN / UNASSIGNED, never "Other". No "Other"
  specialty exists.
- **E. Qualification.** Independent of `PersonQualification`:
  - no `qualification_id` on the period;
  - no `specialty_id` on the qualification;
  - no derivation or synchronisation.
- **F. Reference validity.** A new period requires an **active** `ref.specialties` row at command
  time. A later deactivation leaves history valid and readable, with no cascade.
- **G. Reappointment.** A new relationship inherits nothing; each relationship has its own history.
- **H. Reporting as-of.** Reports resolve the specialty in force on the report date. S26 provides
  the fact only; it implements neither R1 nor R5.
- **I. Legacy history.** No fabricated dates. Legacy/import baseline dates need a future explicitly
  authorized policy. S26 implements no import.

### Additional decisions (Resume Authorization)

1. No `start_knowledge_state` column. S22's column is an import marker, and the legacy policy is
   deferred.
2. No standalone end-specialty command.
3. No standalone correction command. A period ends only through the next recorded period or a
   relationship-end consequence. There is no transition to UNASSIGNED.
4. Backdated recording mirrors S22: `effective_from` must be strictly after the latest recorded
   period's start. No historical splitting.
5. Future-dated recording mirrors S22: future periods are allowed, and a later command must come
   after them.
6. Security mirrors S22: `hr.employment_specialty_periods.view` / `.record`. Never
   `reference.manage`, and no S26-only org-scope mechanism.
7. Append-only mirrors S22: no PATCH, no DELETE, no `version` column.

## §S26.4 CA-S26-01 — same-specialty re-recording (APPROVED)

S26 follows the established S20/S22 convention; there is **no same-value special case**.

The S26 authorization's original instruction "Do not create redundant specialty periods merely to
record a command" is **cancelled**. It was never implemented.

When the requested specialty equals the open period's specialty and the new `effective_from` is
otherwise valid, the command:

- closes the open period at the new `effective_from`;
- creates a new adjacent period with the same `specialty_id`;
- returns 201 and is audited exactly like any other successful record.

It is never rejected for being unchanged, never a 200 no-op, and never suppressed. There are no
specialty-specific deduplication semantics.

A specialty equal to an older historical value is not a conflict either. Validity is decided only by
the temporal rules (§S26.9).

**Worked example:**

- Record specialty A effective 2026-01-01.
- Record specialty A effective 2026-06-01.
- Result:
  - `A [2026-01-01, 2026-06-01)`
  - `A [2026-06-01, ∞)`
  - two audited records.

## §S26.5 Ownership and lifecycle independence

The period references `employment_relationship_id` only. There is no `person_id`, and nothing is
stored on `hr.persons`, `hr.employment_relationships` or `hr.person_qualifications`.

Recording a specialty touches no other stream:

- category, contract or job title;
- placement, status, secondment or assignment;
- qualification;
- the catalog;
- any S06 mapping.

## §S26.6 Data model

Migration `2026_10_11_000001_create_hr_employment_specialty_periods_table` creates
`hr.employment_specialty_periods`:

| Column | Type | Constraint |
|---|---|---|
| `id` | uuid | PK (UUIDv7) |
| `employment_relationship_id` | uuid | FK `employment_specialty_periods_relationship_fk` → `hr.employment_relationships`, RESTRICT; index |
| `specialty_id` | uuid | FK `employment_specialty_periods_specialty_fk` → `ref.specialties`, RESTRICT; index |
| `effective_from` | date | not null |
| `effective_to` | date | nullable. CHECK `employment_specialty_periods_period_check` (`effective_to > effective_from`) |
| `created_at` | timestamptz | — |

The table carries two database-level integrity rules:

- **No overlap.** EXCLUDE `employment_specialty_periods_no_overlap` (GiST, `employment_relationship_id =`,
  `daterange &&`) means no two periods of one relationship overlap. Adjacent periods are allowed.
  This is the final protection against write skew.
- **Delete protection.** Both foreign keys are RESTRICT: neither the relationship nor a referenced
  specialty can be hard-deleted.

The table has none of the following:

- `person_id`, `qualification_id`;
- a primary flag, percentage or allocation;
- notes, a text snapshot, decision fields or import metadata;
- `start_knowledge_state`;
- a cadre cache or organizational unit;
- `version` / `updated_at`.

Migration `…000002` seeds the two permissions. Both migrations have a symmetric `down()`.

## §S26.7 Reference behavior

- A missing `specialty_id` returns 404 at the controller.
- An inactive `specialty_id` returns 422 (`errors.specialty_id`). The check re-fetches the
  specialty fresh inside the command.
- An existing period whose specialty is deactivated later is unchanged, still resolves, and is
  still listed.
- S26 never creates, renames, activates, deactivates or seeds a specialty (S25 is authoritative).

## §S26.8 Concurrency

1. `RecordEmploymentSpecialtyPeriod` first re-fetches the relationship with `lockForUpdate()`, which
   serialises it against `EndEmploymentRelationship` and other recordings.
2. The controller takes the same lock first, so the audit's previous-period snapshot is race-free.
3. The EXCLUDE constraint is the independent backstop. `ConcurrencyTest` proves both paths with two
   real PostgreSQL sessions.

## §S26.9 Command — `RecordEmploymentSpecialtyPeriod` (S22 algorithm)

1. **Lock** the relationship. If it has already ended (`end_knowledge_state = KNOWN`), return 409.
2. **Reference:** the specialty must exist and be active, otherwise 422.
3. **Relationship boundary:** `effective_from` must be on or after the relationship's own
   `effective_from`, otherwise 422.
4. **Ordering:** `effective_from` must be strictly after the **latest recorded** period's start
   (open or closed, including a future period), otherwise 422. So there is no backdated splitting
   and no overwriting of known future history.
5. **Closure:** if the latest period is open, close it at the new `effective_from`. A latest period
   that is already closed (a real gap) is left as it is.
6. **Insert** the new open period. CHECK/EXCLUDE violations become 422, never 500.

Same-value handling follows §S26.4.

## §S26.10 Dates

- Business dates are DATE, and intervals are half-open.
- A future start is allowed and does not become current early.
- The as-of reader always takes an explicit date.

## §S26.11 Employment boundary and lifecycle consequences

`EndEmploymentRelationship` gained `closeOpenEmploymentSpecialtyPeriodIfAny()`, identical to the
S20/S22 rule:

- If any period starts on or after the end date, or is closed after it, the end is rejected with
  422 (`InvalidEndDateException`); nothing is truncated or deleted.
- Otherwise the open period is closed at the end date.
- A period that already ended is never extended.

The same path runs for a status-triggered termination (`RecordEmploymentStatusPeriod` →
`EndEmploymentRelationship`).

Both triggering audits (`hr.employment_relationship.end`, `hr.employment_status_period.record`) add
`employment_specialty_period_closed_as_consequence: true` when a closure happened. No separate event
is written.

An ended relationship rejects new periods with 409.

## §S26.12 Reappointment

A new relationship starts with no specialty periods. The old relationship keeps its own history.
Two relationships (of the same Person or of different Persons) may use the same specialty.

## §S26.13 Security

| Action | Permission |
|---|---|
| List | `hr.employment_specialty_periods.view` |
| Record | `hr.employment_specialty_periods.record` |

- Both are plain RBAC route middleware, exactly as S22.
- There is no organizational-scope check, because the S22 template has none; no S26-only mechanism
  is invented.
- `reference.*` alone, or any other `hr.*` permission, grants nothing here.
- A person/relationship ownership mismatch returns 404.

## §S26.14 API

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-specialty-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-specialty-periods
     { specialty_id, effective_from }
```

- The response fields are `id`, `employment_relationship_id`, `specialty_id`, `effective_from` and
  `effective_to`. Any other client field (for example `start_knowledge_state`, `qualification_id`,
  `is_primary`) is ignored.
- The list returns most recent first.
- There is no PATCH, PUT, DELETE, end or correction route (405).

Two queries are provided:

- `ListEmploymentSpecialtyPeriodsForRelationship`
- `ResolveEmploymentSpecialtyForRelationshipAsOf` (explicit date; returns `null` for UNRESOLVED;
  applies no `is_active` filter)

## §S26.15 Error model

| Status | Cause |
|---|---|
| 401 | unauthenticated |
| 403 | missing permission |
| 404 | unknown person, relationship or specialty, or ownership mismatch |
| 409 | relationship already ended |
| 422 | validation; inactive specialty (`errors.specialty_id`); date or ordering (`errors.effective_from`); a relationship end that conflicts with a period (`errors.effective_to`) |

## §S26.16 Audit

Every successful record writes `hr.employment_specialty_period.record` with target type
`hr_employment_specialty_period`:

- `changes`: `{employment_relationship_id, specialty_id, effective_from}`.
- `metadata`: `{specialty_code}`, plus `previous_period_id` and `previous_period_closed_at` when a
  previous period exists or was closed.

There is no PII (no national ID and no name). A rejected write leaves no audit and no partial
closure. A same-specialty re-record is audited like any other (CA-S26-01).

## §S26.17 S06 reporting compatibility and R1/R5 readiness (no report built)

- **R1:** relationship → `ResolveEmploymentSpecialtyForRelationshipAsOf(date)` → `specialty_id` →
  `ResolveSpecialtyCadreCategoryAsOf(specialty, date)` → monthly cadre category.
- **R5:** relationship → `ResolveEmploymentContractForRelationshipAsOf(date)` → contract type →
  `ResolveContractTypePopulationCategoryAsOf(date)`, combined with the specialty as-of the same date.

An unmapped specialty resolves to `null`, never "Other". Nothing duplicates or caches the cadre
category, and no mapping is created automatically. Tests demonstrate both chains; no reporting
projection exists.

## §S26.18 Employee 360

**Deferred.** Showing specialty labels would need `/reference/specialties` reads, which require
`reference.view`. HR users may not hold it, and changing reference-label permissions is out of
scope. Backend correctness is the S26 requirement. No frontend change was made.

## §S26.19 Import compatibility (documentation only)

A future import will:

- resolve a known source specialty to the canonical `ref.specialties` row, then record an employment
  specialty period;
- route an unknown value to an unresolved / data-quality workflow;
- never create a catalog row silently;
- never fall back to "Other";
- never fabricate a historical effective date. A snapshot whose real start is unknown needs a
  future, explicitly authorized baseline/legacy policy, which may add its own marker at that time.

## §S26.20 Tests

- **`EmployeeSpecialtyHistoryFoundationTest`** (39 tests) covers:
  - **basic:** contract and permanent relationships, no specialty = unresolved, 201 + list order,
    as-of;
  - **temporal:** relationship-start boundary, future period, closure, gap, backdated/same-date
    rejection, invalid payloads;
  - **PostgreSQL:** overlap vs adjacency, CHECK, independent relationships sharing a specialty,
    extra client fields ignored, foreign keys / RESTRICT, table shape;
  - **reference:** 404 / inactive, deactivation keeps history;
  - **lifecycle:** end closes the open period with audit metadata, no extension, reject an end
    before a future period, imported closed period beyond the end, ended relationship gives 409,
    status-triggered termination, terminal and rejected cases;
  - **reappointment:** no inheritance;
  - **separation:** no other stream, qualification, catalog or mapping touched;
  - **CA-S26-01:** same-specialty adjacent period with 201 and audit; historical same value; ordering
    still enforced;
  - **S06:** R1 chain, unmapped = null / no "Other", R5 chain;
  - **scope guards;**
  - **security:** 401/403, view vs record, `reference.*` and other `hr.*` insufficient, ownership
    404, no PATCH/DELETE;
  - **audit:** metadata and PII, rejected writes.
- **`ConcurrencyTest`:** an overlap race (EXCLUDE backstop) and record-vs-end (row lock).
- **`MigrationLifecycleTest`:** S26 rollback/reapply; S09 ordering; S02 cleanup; permission counts.
- **Inventory guards updated for the authorized new objects:** `ScopeBoundaryTest` (HR),
  `DatabaseConstraintsTest`, `ApiEndpointsTest`, and the S25 specialty guards.

## §S26.21 Adversarial review

| Challenge | Result |
|---|---|
| Person-vs-Employment leak | No `person_id`; no Person column (tests). |
| Reappointment inheritance | None (test). |
| Snapshot instead of history | No current column; history only. |
| Overlap / write skew | EXCLUDE plus row lock (two-session tests). |
| Future overwrite / backdated corruption | Ordering rule rejects; snapshot unchanged (tests). |
| Same-specialty | Follows CA-S26-01 exactly; no dedup or no-op code path exists. |
| Inactive reference | New periods rejected; history kept. |
| Catalog mutation leak | Catalog row unchanged (test). |
| Qualification coupling | None (test). |
| Multiple / primary | Impossible by EXCLUDE; no column. |
| Fake legacy dates | No import, no knowledge-state column. |
| Automatic "Other" / automatic mapping / cached cadre | None (tests). |
| Scope bypass | Mirrors S22 plain RBAC; no org-scope check exists to bypass. |
| Audit PII | None (test). |
| Report leakage / S27 | None. |

## §S26.22 Deferred

- Standalone end or correction of a specialty period, and any transition to UNASSIGNED.
- A legacy/import baseline-date policy and marker.
- The import engine.
- R1, R5 and any reporting projection.
- Employee 360 specialty display.
- Organizational-scope enforcement for relationship-level employment histories generally (none
  exists for S20/S22 either).
