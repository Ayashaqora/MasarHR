# S20 — Employment Category History Foundation (تأسيس التاريخ الزمني للفئة الوظيفية)

Specification and ADR record for ADR-S20-001, written by the Executor (Claude Code Cloud) under the
Architecture Authority's "S20 Corrective Implementation Authorization". It is implemented from the
clean remote S19 baseline (`develop @ d113eee`, tag `s19-leave-management-foundation`). No
Cursor-local, uncommitted S20 work-in-progress was recovered, merged, or consulted; the authorization
declares that material non-authoritative.

## §S20.1 Reconstruction disclosure

The historical roadmap title of S20 was **not** recovered from any repository artifact, prior ADR, or
prior specification. "S20 — Employment Category History Foundation / تأسيس التاريخ الزمني للفئة
الوظيفية" is a **reconstructed title** supplied by the Architecture Authority's corrective
authorization. It is not historical roadmap wording, and this document makes no claim that it is.

## §S20.2 ADR-S20-001 — decisions recorded

The Architecture Authority fixed the following decisions in the S20 corrective authorization. This
Executor records them here and did not decide them:

| # | Decision | Source |
|---|---|---|
| 1 | The concept is **Employment Category History**. It uses the **existing** `ref.employment_categories` catalog. No `employee_categories`, no second category catalog, and no grade catalog. Code terminology follows the repository's existing `EmploymentCategory` naming. | Authorization §3 |
| 2 | `EmploymentCategoryPeriod` belongs to **EmploymentRelationship**. It does not belong to Person, OrganizationalUnit, or the reference-catalog aggregate. Each relationship has its own category history. | Authorization §4 |
| 3 | **Reappointment** creates a new relationship with **no** category until one is explicitly recorded. History is never copied, inferred, or carried forward. The old relationship's periods stay preserved on the old relationship. | Authorization §5 |
| 4 | **Relationship end**: a category period must never extend beyond its relationship. `EndEmploymentRelationship` closes an open category period at the same effective end date, atomically, within its existing lifecycle-consequence transaction. The earlier suggestion to leave it untouched is **rejected**. Nothing is deleted. | Authorization §6/§11 |
| 5 | **Temporal model**: DATE business dates, half-open `[from, to)`, `effective_from < effective_to`, no overlap per relationship, and the established `TemporalConstraints` GiST exclusion. The current category is derived, never stored destructively. | Authorization §7 |
| 6 | **Reference rule**: a new assignment requires a category that exists and is active **at command time**. A later deactivation never deletes, rewrites, or breaks reads of historical periods. Unknown categories are never silently mapped. | Authorization §9 |
| 7 | An **explicit command** is used, with no generic PATCH or DELETE. | Authorization §10/§13 |
| 8 | **Authorization**: new explicit `hr.*` permissions using the existing **relationship-level plain-RBAC** precedent (S10). `reference.*` never authorizes assignment. No placement-derived organizational scope is introduced in S20. | Authorization §12 |
| 9 | Reporting and import are **not** implemented. Only compatibility is ensured. No reporting denormalization. | Authorization §15/§16 |
| 10 | No frontend or visual-runtime work (DEFERRED BY OWNER). | Authorization §17 |
| CA-01 | **Day-one category allowed**: `category.effective_from >= relationship.effective_from`. A period may begin on exactly the relationship's start date and may **never** predate it. This is an S20-only exception. The strict "after" start rules of S10/S11/S12/S16 are **unchanged**. | S20 Final Corrective Order, CA-01 |
| CA-02 | **Status-triggered termination audit**: when an employment-status operation terminates the relationship and that consequence closes an open category period, the **triggering** status-period audit entry exposes the closure, using the existing metadata convention. No second audit mechanism and no duplicate event. | S20 Final Corrective Order, CA-02 |

## §S20.3 Discovery evidence

- **The catalog exists.** `ref.employment_categories` was created by S05 as a "structure-only, values
  deferred" family (`2026_09_26_000007_create_ref_employment_categories_table.php`,
  `docs/reference-data-foundation-specification.md` §5.3).
- **It was named as a candidate and blocked.** Prior discovery named "Employment Category History" as
  a candidate and marked it **blocked** because the catalog was empty:
  `docs/employment-status-history-foundation-specification.md` §1.3 (lines 44, 66–70, 128, 147),
  `docs/full-secondment-foundation-specification.md` (lines 37, 53), and
  `docs/organizational-placement-foundation-specification.md` (lines 36, 422).
- **S13 unblocked it.** S13 seeded exactly seven authoritative grade values into the catalog
  (`2026_10_03_000001_seed_ref_employment_categories_grades.php`;
  `docs/reference-catalog-administration-foundation-specification.md` §8.1) and gave it full
  Create/UpdateMetadata/Activate/Deactivate administration with no hard delete. S13 itself still
  listed "Employment Category History" as deferred (§24, line 709).
- **No HR-side assignment existed before S20.** No `hr.*` table, FK, command, query, route, resource,
  or test referenced `ref.employment_categories` at `d113eee` (verified by repository-wide search).
- **Concept separation, confirmed by prior specs:**
  - `ref.employment_categories` (grades الأولى … قانون قديم) is **not** `ref.employment_types`
    (PERMANENT/CONTRACT; S09 §7 explicitly refused to conflate them).
  - It is **not** S06's reporting-only `ref.monthly_cadre_categories` /
    `ref.contract_based_population_categories` (S10 §1.3).
  - It is **not** `ref.employment_status_categories` (the S05 four-tier status grouping).

**Why it is unblocked now:** the S05 blocker was "no authoritative value list". S13 supplied that list
and its administration. The HR aggregate (S09) and the temporal-child pattern (S10/S11/S12/S16) it
attaches to are both closed and stable.

## §S20.4 Scope

**In scope:**
- one new table, `hr.employment_category_periods`;
- one command, `RecordEmploymentCategoryPeriod`;
- a minimal extension of `EndEmploymentRelationship`;
- one list query and one as-of query;
- two HTTP routes (list, record);
- two `hr.*` permissions;
- two domain exceptions;
- audit integration;
- tests;
- this document.

**Non-goals (explicit):** see §S20.21.

## §S20.5 Terminology

| Term | Meaning |
|---|---|
| Employment Category (الفئة الوظيفية) | A row of `ref.employment_categories` (S13's seven grades today). Identity is `id` (UUID) and the stable `code`. `name_ar`/`name_en` are display text only, never identity. |
| Employment Category Period | One half-open interval during which one Employment Relationship held one Employment Category. |
| Current category (as of D) | Derived: the category of the period whose `[effective_from, effective_to)` contains D, or UNRESOLVED (`null`) when none does. |

## §S20.6 Data model

`hr.employment_category_periods` (migration `2026_10_06_000001_create_hr_employment_category_periods_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7, generated by the model |
| `employment_relationship_id` | `uuid` NOT NULL | FK → `hr.employment_relationships(id)` `ON DELETE RESTRICT` (`employment_category_periods_relationship_fk`) |
| `employment_category_id` | `uuid` NOT NULL | FK → `ref.employment_categories(id)` `ON DELETE RESTRICT` (`employment_category_periods_category_fk`) |
| `effective_from` | `date` NOT NULL | business effective date |
| `effective_to` | `date` NULL | exclusive end; `NULL` = open |
| `created_at` | `timestamptz` NOT NULL | technical timestamp |

**Constraints and indexes:**
- `employment_category_periods_period_check`: `effective_to IS NULL OR effective_to > effective_from`
  (`TemporalConstraints::validPeriodCheckSql`).
- `employment_category_periods_no_overlap`:
  `EXCLUDE USING gist (employment_relationship_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)`
  (`TemporalConstraints::noOverlapConstraintSql`, requires btree_gist from S02).
- Indexes on `employment_relationship_id` and `employment_category_id`.

**Columns deliberately absent:**
- No `person_id`: ownership is by relationship (§S20.2 #2).
- No `organizational_unit_id`: plain RBAC (§S20.12).
- No `version`/`updated_at`: the table is append-only, like `hr.employment_status_periods` (S10).
- No "current category" column on `hr.employment_relationships`.

The shape mirrors S10's `hr.employment_status_periods` / S11's `hr.organizational_placement_periods`.

**Migration character:** purely additive. It creates one table and seeds two permission rows
(`2026_10_06_000002_seed_security_employment_category_period_permissions.php`). It alters no existing
table, rewrites no existing row, and seeds zero period rows. `down()` drops only the new table and
deletes only the two new permission rows.

**Consequence of the RESTRICT FK:** a referenced `ref.employment_categories` row can no longer be hard
deleted. This is consistent with S13's no-hard-delete lifecycle. S13's own seed migration `down()` (a
raw delete of the seven grades) would now fail if any period references a grade. That is the
intended protection of history, and it is disclosed here.

## §S20.7 Temporal model

- **Types:** business dates are `DATE`; technical time is `timestamptz`.
- **Intervals:** half-open `[effective_from, effective_to)`, so the boundary date belongs to the later
  period.
- **Contiguity:** history is contiguous by command discipline (auto-close-on-insert). The database
  forbids overlap, not gaps. Gaps can only arise from direct/imported data.
- **Current value:** derived by `ResolveEmploymentCategoryForRelationshipAsOf($relationship, $date)`,
  with an explicit date that is never an implicit `today()`.
- **Future-dated periods** are recorded normally and resolve only from their own `effective_from`.
  They never become "current" early.
- **Backdated periods** are accepted only when strictly after the latest existing period's
  `effective_from`. They close that period at the new date. Any backdating before or on top of later
  history is rejected (§S20.9), so later history is never rewritten.

## §S20.8 Concurrency

`RecordEmploymentCategoryPeriod` begins with `SELECT … FOR UPDATE` on the relationship row. This
serialises it against another recording and against `EndEmploymentRelationship`, whose scoped UPDATE
takes the same row lock. The EXCLUDE constraint is the independent database-level backstop:
overlapping inserts from two sessions cannot both commit. Both properties are proven with two real
PostgreSQL sessions in `ConcurrencyTest`.

## §S20.9 Command — `RecordEmploymentCategoryPeriod`

`handle(EmploymentRelationship, EmploymentCategory, string $effectiveFrom)`, inside
`AuditedCommandExecutor`'s transaction:

1. **Lock the relationship.** Re-fetch it fresh with `lockForUpdate()`. If
   `end_knowledge_state = KNOWN`, throw `EmploymentRelationshipAlreadyEndedException` (409). An ended
   relationship's category history is closed. This mirrors S10/S11.
2. **Check the category.** Re-fetch it fresh. If it is missing or `is_active = false`, throw
   `InvalidEmploymentCategoryException` (422, `errors.employment_category_id`).
3. **Check against the relationship start (CA-01).** `effective_from` must be **on or after** the
   relationship's own `effective_from` (a day-one category is allowed); a date before it throws
   `InvalidEmploymentCategoryPeriodDateException` (422, `errors.effective_from`). This deliberately
   differs from the strict "after" rule of S10/S11/S12/S16, which is **not** modified by S20.
4. **Check against later history.** `effective_from` must be strictly after the **latest** recorded
   period's `effective_from`; otherwise throw the same 422.
5. **Close the open period.** If the latest period is open, set its `effective_to` to the new
   `effective_from`.
6. **Insert.** Insert the new open period. CHECK/EXCLUDE violations are translated to the same 422,
   never a 500.

Recording has no downstream consequence and never mutates the relationship.

Recording the same category value again is not specially treated. No rule was supplied, so none is
invented.

## §S20.10 Reference rule

- **Existence:** at the controller, a missing `employment_category_id` returns 404, mirroring S16's
  `decision_type_id`. The command re-validates on a fresh re-fetch.
- **Active at command time:** enforced in the command (§S20.2 #6). This is an Architecture Authority
  decision, and it is intentionally **stricter** than the S06/S10/S11 "no `is_active` gate" precedent
  for reference targets.
- **After deactivation:** periods are untouched. The list endpoint still returns them. The as-of
  reader still resolves the now-inactive category, because it applies no `is_active` filter.
- **Identity:** always `id`, never `name_ar`/`name_en`. No mapping of unknown values exists: an id
  either resolves or is rejected.

## §S20.11 Relationship-end integration

`EndEmploymentRelationship` gains one private step, `closeOpenEmploymentCategoryPeriodIfAny()`. It
runs after the existing S12/S16/S10 consequences in the same transaction.

1. **Bounds check before any write.** If **any** period of the relationship starts on or after the
   end date, or is already closed at a date after the end date, the end is rejected with S09's own
   `InvalidEndDateException` (422, `errors.effective_to`). The whole end transaction, including the
   relationship UPDATE and the other consequences, rolls back atomically. Nothing is truncated,
   deleted, or left extending beyond the relationship.
   - "Equal dates" is rejected too. Unlike the S10 ending status, a category period starting on the
     end date would lie entirely outside the relationship.
2. **Close the open period.** Otherwise, the open period (if any) is closed at exactly the end date.
3. **Earlier periods are untouched.** A period that already ended on or before the end date is never
   extended.

This applies uniformly to every ending path:
- the direct `/end` route;
- status-triggered ending through `RecordEmploymentStatusPeriod` (S10/S15), which calls
  `EndEmploymentRelationship` in-process.

`EmploymentRelationshipController::end()` records
`employment_category_period_closed_as_consequence: true` in the audit metadata. It uses the identical
locked before/after snapshot pattern S16 used for `workplace_assignment_closed_as_consequence`.

**CA-02.** When the ending is status-triggered, `EmploymentStatusPeriodController::store()` records the
same `employment_category_period_closed_as_consequence: true` key in the **triggering**
`hr.employment_status_period.record` entry. It sits alongside the existing
`relationship_closed_as_consequence` and `full_secondment_closed_as_consequence` keys, and uses the
same before/after snapshot taken under the same relationship lock. If the termination is rejected
(for example, a category period would extend beyond the end date), the entire status operation rolls
back and no success audit entry is written.

**Interaction with CA-01.** A day-one category (starting on the relationship start) is always
strictly before any valid relationship end, because the database enforces that the relationship's
`effective_to` is after its `effective_from`. So it is always closed within bounds.

## §S20.12 Authorization

| Permission | Route | Gate |
|---|---|---|
| `hr.employment_category_periods.view` | `GET …/employment-category-periods` | plain RBAC |
| `hr.employment_category_periods.record` | `POST …/employment-category-periods` | plain RBAC |

- **Target:** the write target is the employment fact (`hr`), so the permissions are `hr.*`.
  `reference.view`/`reference.manage` continue to administer the catalog only and grant nothing
  here, which is tested.
- **No organizational scope:** there is no S08 composition, per the S10 relationship-level precedent
  (ADR-S20-001 §8). No existing scope protection is changed or weakened.
- **Default deny:** no role is granted either permission by the migration.

## §S20.13 API

Both routes sit under `/api/v1/hr` with `auth:web`, `principal.active`, `resolve.context`, and
`permission:`, and are nested exactly like S10:

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-category-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-category-periods
     body: { employment_category_id: uuid, effective_from: date }  → 201 EmploymentCategoryPeriodResource
```

- **Resource:** `id`, `employment_relationship_id`, `employment_category_id`, `effective_from`,
  `effective_to`.
- **Ordering:** the list is ordered most recent first.
- **Ownership:** a relationship not owned by `{person}` returns 404.
- **No other verbs:** there is no PATCH, DELETE, or end route. The catalog is not duplicated; it
  stays under `/api/v1/reference/employment-categories`.

**As-of API.** S10/S11/S12/S16 expose no as-of HTTP read, and the S06 as-of readers are
application-layer queries. S20 follows that precedent: `ResolveEmploymentCategoryForRelationshipAsOf`
is an application query with no route.

## §S20.14 Error model

| Condition | Exception | HTTP |
|---|---|---|
| validation (missing/malformed) | Laravel validation | 422 |
| relationship not owned by person | `NotFoundHttpException` | 404 |
| category id does not exist | `NotFoundHttpException` | 404 |
| category inactive / vanished at command time | `InvalidEmploymentCategoryException` | 422 `employment_category_id` |
| date not after relationship start / latest period; CHECK/EXCLUDE | `InvalidEmploymentCategoryPeriodDateException` | 422 `effective_from` |
| relationship already ended | `EmploymentRelationshipAlreadyEndedException` (S09) | 409 |
| relationship end would leave a category beyond it | `InvalidEndDateException` (S09) | 422 `effective_to` |

## §S20.15 Reporting compatibility (no reporting implemented)

"What Employment Category did this EmploymentRelationship have as of X?" is answered by
`ResolveEmploymentCategoryForRelationshipAsOf`:
- it takes an explicit date and uses half-open resolution;
- it returns `null` (UNRESOLVED) outside any period;
- it applies no `is_active` filter.

Historical dates therefore never use today's category. A monthly or unique-headcount report can join
relationship-scoped periods by date without any denormalization. No reporting table, view, or
materialization is created.

## §S20.16 Import compatibility (no import implemented)

A future import can map a legacy grade value to an existing `ref.employment_categories` row and
record it through the same command, or through an equivalent validated path. The model imposes:
- **Identity:** an explicit category id, never display text.
- **No hidden fallback:** there is no "other" row and no default. Unknown source values must remain
  UNRESOLVED / validation-flow items under the future import architecture. The precedent for
  Arabic-source resolution is `ResolveMaritalStatusByArabicSourceValue` (S05 CORRECTIVE-01), which
  returns null for unknown values.
- **Gaps are allowed:** history need not start at the relationship's first day.

S20 adds no resolver of its own, because that would be import work.

## §S20.17 Audit

| Write | action | target_type | changes | metadata |
|---|---|---|---|---|
| record | `hr.employment_category_period.record` | `hr_employment_category_period` (target_id = new period id) | `employment_relationship_id`, `employment_category_id`, `effective_from` | `employment_category_code` |
| relationship end (existing) | `hr.employment_relationship.end` | unchanged | unchanged | + `employment_category_period_closed_as_consequence` when it applied |
| status-triggered end (existing, CA-02) | `hr.employment_status_period.record` | unchanged | unchanged | + `employment_category_period_closed_as_consequence` when the triggered termination closed an open category period |

- **Actor, correlation, and timestamps** come from the existing `AuditedCommandExecutor` /
  `CommandContext`. No second audit mechanism exists.
- **Rejected writes** roll back and record no MUTATION entry, per the S04 convention.
- **No PII:** no person identifiers are written.

## §S20.18 Frontend

No change. The visual runtime is deferred by the owner. No existing frontend contract requires a
category read model.

## §S20.19 Adversarial review summary

The complete review is in the S20 execution report. In summary:
- **No duplicate or misplaced aggregate.** There is no duplicate aggregate and no Person ownership.
  Category is not confused with employment type, reporting cadre categories, or status categories.
- **Overlap and write skew are blocked** by the row lock and the EXCLUDE constraint.
- **No early "current" value.** A future period never becomes current early.
- **No backdated corruption.** Backdating before later history is rejected.
- **No impossible period after an end.** Rejection is used, not deletion.
- **No reappointment carry-forward**, because history is keyed per relationship.
- **Inactive categories** are rejected for new assignment and remain readable historically.
- **Permissions are separated** between `hr.*` and `reference.*`, and scope policy is unchanged.
- **No invented reporting or import behavior.**

## §S20.20 Test plan (implemented)

**`tests/Feature/HumanResources/EmploymentCategoryHistoryFoundationTest.php` (new)** covers:
- **Basic:** record, history, as-of.
- **Temporal:** future, backdated, day-one accepted / as-of day one / before-start rejected (CA-01),
  adjacent/bounded, preservation, validation.
- **PostgreSQL:** EXCLUDE, CHECK, FKs, RESTRICT delete, column shape.
- **Reference:** nonexistent, inactive, later deactivation.
- **Lifecycle:** ended relationship, end closes open, earlier end not extended, future beyond end
  rejected, closed-beyond-end rejected, backdated end, status-triggered end, day-one closed within
  bounds, reappointment.
- **CA-02:** the status-triggered termination's triggering audit entry exposes the category closure;
  no claim without an open category; no claim or closure on a non-terminating transition; a rejected
  termination rolls back with no success audit entry.
- **Security:** 401, 403 without permission, view vs record, `reference.*` alone, other `hr.*` alone,
  ownership 404, no PATCH/DELETE.
- **Audit:** success entry, rejected writes leave no entry.

**`ConcurrencyTest` (extended):** two-session EXCLUDE race; recording vs. employment-end row-lock
serialisation.

**`MigrationLifecycleTest` (extended):** S20 rollback/reapply. The S02/S09 rollback paths now drop or
roll back S20 first because of its FKs, and the permission counts are updated.

**Inventory tests updated:**
- `ScopeBoundaryTest` (hr: seven tables);
- `DatabaseConstraintsTest` (hr table list);
- `ApiEndpointsTest` (permission catalog).

## §S20.21 Deferred / non-goals

- Reporting of any kind (monthly, headcount, cadre); reporting tables or views.
- Import/migration pipelines and any source-value resolver.
- An "end category" command, correction/void of a recorded period, and deletion.
- Carrying category across reappointment (explicitly forbidden).
- Organizational-scope redesign for relationship-level writes.
- An as-of HTTP endpoint (no HR precedent).
- Frontend / Employee 360 display of category.
- Any change to `ref.employment_categories` content or lifecycle.
- The stale "VALUES DEFERRED" docblock on the `EmploymentCategory` model. It predates S13 and is
  unrelated to S20, so it is left unchanged.

## §S20.22 Resolved questions

Both open questions from the first S20 execution report were resolved by the Architecture
Authority's S20 Final Corrective Order:

1. **Day-one category → CA-01.** Allowed (`>=`); a category may never predate the relationship. The
   exception applies to S20 only; the S10/S11/S12/S16 start rules are unchanged.
2. **Status-triggered-end audit → CA-02.** The category closure is exposed in the triggering
   status-period audit entry (§S20.11, §S20.17).
