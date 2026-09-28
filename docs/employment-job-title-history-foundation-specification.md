# S22 — Employment Job Title History Foundation (تأسيس التاريخ الزمني للمسمى الوظيفي)

**RECONSTRUCTED TITLE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

Specification and ADR record for ADR-S22-001, written by the Executor (Claude Code Cloud) under the
Architecture Authority's "S22 Employment Job Title History Foundation — Full Cloud Execution
Authorization". Baseline: `origin/develop @ 8228970` (tag `s21-employment-contract-foundation`).
This stage is not based on `main`.

## §S22.1 Reconstruction disclosure

The historical roadmap wording for S22 was not recovered. The title above was supplied by the
Architecture Authority as a reconstruction and is used only as such.

## §S22.2 Discovery

| Question (authorization §3) | Finding at `8228970` |
|---|---|
| 1. Is there a canonical job-title catalog? | **Yes: `ref.job_titles`.** It has S05 structure (`2026_09_26_000010`) and S13 administration: Create/UpdateMetadata/Activate/Deactivate, no hard delete, `reference.*`, `/api/v1/reference/job-titles`. |
| 2. Is it seeded? | **No: 0 rows.** The S13 authorization said explicitly: "Do NOT fabricate or reconstruct an incomplete job-title list. Administration capability may exist while catalog content remains empty" (S13 spec §8). S22 seeds nothing. |
| 3. Is job title stored on Person or EmploymentRelationship? | **No.** No `hr.*` column or FK references `ref.job_titles`. No HR command, query or resource mentions job title. |
| 4. Does Employee 360 expect it? | **No.** S18 is a frontend-only slice over existing endpoints and has no job-title field or contract. |
| 5. Does reporting map or consume it? | **Mapped, not consumed.** S06 `ref.job_title_administrator_classifications` (temporal: job title → `is_administrator`, for Report 2 "الإداريين"), with the `ResolveJobTitleAdministratorClassificationAsOf` as-of reader. It is structure only, with no rows. No report is executed. |
| 6. Does an import specification define it? | **No.** No import specification exists in the repository. The precedents are the S05 `ResolveMaritalStatusByArabicSourceValue` (unknown → null) and the knowledge-state columns of S09/S21. |
| 7. Is job title distinct from other concepts? | **Yes, distinct catalogs.** `ref.specialties` (with S06 cadre mapping), `ref.supervisory_titles` (S13), `ref.qualification_types`/`ref.academic_degrees` (S13), `ref.employment_categories` (grades, S20) and `ref.monthly_cadre_categories` (S06). S06 §12.3 states the administrator classification "stores a classification *of a job title*, never of a person", and is "never guessed from a job-title string". |

**`job_desc`:** no repository artifact defines its semantics (reference description, separate
classification or legacy free text). It is **DEFERRED** (§S22.19).

**Other infrastructure is reused unchanged:**
- `TemporalConstraints` / btree_gist;
- the relationship-row-lock discipline (S10–S21);
- `AuditedCommandExecutor`;
- the `*_closed_as_consequence` audit convention (S20 CA-02, S21);
- the S20 category relationship-end rule;
- plain relationship-level RBAC (S10/S20/S21);
- the migration/inventory test harness.

## §S22.3 Gap matrix

| Capability | Before S22 | S22 |
|---|---|---|
| Job-title catalog + administration | ✅ S05/S13 (empty) | reused, not seeded |
| Job-title reporting mapping | ✅ S06 (structure) | untouched |
| Job title ↔ relationship history | ❌ | **added** (`hr.employment_job_title_periods`) |
| Record / change title (explicit) | ❌ | **added** |
| Current / as-of title | ❌ | **added** (application query) |
| Relationship-end coherence | ❌ | **added** (S20 rule) |
| Status-triggered termination audit | ✅ S20/S21 flags | **extended** with a job-title flag |
| Unknown legacy start (snapshot import) | ❌ | **added** (`start_knowledge_state`, import-only) |
| Employee 360 display | ❌ | **deferred** (visual runtime deferred by owner) |
| `job_desc`, supervisory, promotion | ❌ | **excluded / deferred** |

## §S22.4 ADR-S22-001 — decisions

| # | Decision | Basis |
|---|---|---|
| 1 | `EmploymentJobTitlePeriod` (`hr.employment_job_title_periods`) belongs to **EmploymentRelationship**, never Person. | Authorization §4; S10–S21. |
| 2 | It reuses **`ref.job_titles`**. There is no new catalog and no seed. | Authorization §10; S13 §8. |
| 3 | It applies to **both** appointment types (PERMANENT and CONTRACT). | Authorization §23 A; nothing restricts titles to one type. |
| 4 | It is an **exclusive** temporal stream: half-open `[from, to)` with a GiST EXCLUDE per relationship. The current title is derived from history, never stored. | Authorization §5. |
| 5 | A title **may start on** the relationship start (`>=`) and **never before** it. A relationship may have no title at all (UNRESOLVED), and nothing is fabricated. | Authorization §6. |
| 6 | A new assignment requires an **active** title at command time. Later deactivation never alters or hides history. Hard delete is blocked by a RESTRICT FK. | Authorization §10. |
| 7 | **Change = temporal closure plus a new period.** The previous period's identity, title and start are preserved, and only its `effective_to` is closed at the change date. A genuine gap in history is preserved. **Relationship end** uses the S20 category rule. | Authorization §7/§8; S20. |
| 8 | **Import marker — CA-S22-01 (Architecture Authority decision, APPROVED):** `start_knowledge_state` is exactly `KNOWN` or `UNKNOWN_LEGACY`, with the precise semantics in §S22.19a. The normal API always writes `KNOWN`; `UNKNOWN_LEGACY` exists only for controlled legacy/import representation. | Authorization §20; S09/S21 knowledge-state precedent; S22 Final Architecture Corrective Gate CA-S22-01. |
| 9 | **Authorization:** `hr.employment_job_title_periods.view` / `.record`, plain RBAC. `reference.*` never grants assignment, and there is no scope redesign. | Authorization §16. |
| 10 | Supervisory title/status, `job_desc`, category, qualification, specialty, classification, promotion and salary are **excluded**. There is no derivation in either direction. | Authorization §2/§11–§14. |

## §S22.5 Initial unknown state

A relationship of either type may have **no** recorded title. This is a valid, UNRESOLVED state
that represents incomplete HR data. No title, date or placeholder is fabricated. As-of returns
`null` before the first known title, inside a genuine gap, and after the relationship ended.

## §S22.6 Data model

`hr.employment_job_title_periods` (`2026_10_08_000001_create_hr_employment_job_title_periods_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7 |
| `employment_relationship_id` | `uuid` NOT NULL | FK → `hr.employment_relationships`, RESTRICT |
| `job_title_id` | `uuid` NOT NULL | FK → `ref.job_titles`, RESTRICT |
| `effective_from` | `date` NOT NULL | `KNOWN`: the business-effective start. `UNKNOWN_LEGACY`: an evidence/snapshot boundary, **not** the historical start (§S22.19a). |
| `effective_to` | `date` NULL | exclusive end; `NULL` means open |
| `start_knowledge_state` | `varchar(16)` NOT NULL default `KNOWN` | `KNOWN` \| `UNKNOWN_LEGACY` |
| `created_at` | `timestamptz` NOT NULL | technical |

**Constraints:**
- `employment_job_title_periods_period_check`: `effective_to IS NULL OR effective_to > effective_from`.
- `employment_job_title_periods_start_knowledge_state_check`: the state is `KNOWN` or `UNKNOWN_LEGACY`.
- `employment_job_title_periods_no_overlap`: `EXCLUDE USING gist (employment_relationship_id WITH =,
  daterange(effective_from, effective_to, '[)') WITH &&)`.
- Indexes on both FKs.

**Columns deliberately absent:** `person_id`, `job_desc`, any supervisory, category, specialty or
organizational column, `version`/`updated_at`, and any job-title column on `hr.persons` or
`hr.employment_relationships`.

**Additive migration:** it creates one table and seeds two permissions
(`2026_10_08_000002_seed_security_employment_job_title_period_permissions.php`). `down()` removes
only those.

## §S22.7 Temporal rules

- **Convention:** DATE business dates, `timestamptz` technical time, half-open intervals. The
  boundary date belongs to the new period.
- **Future titles** resolve only from their own start date.
- **Backdating** before or on the latest period's start is rejected.
- **Gaps:** the database forbids overlap, not gaps. The command never creates a gap, because it
  closes an open latest period at the new start. A genuine gap already present in imported history
  is left untouched.

## §S22.8 Concurrency

`RecordEmploymentJobTitlePeriod` begins with `SELECT … FOR UPDATE` on the relationship. This
serialises it against another recording and against `EndEmploymentRelationship`. The controller
takes the same lock first, so the temporal-closure audit snapshot is race-free.

The EXCLUDE constraint is the independent database backstop. Both properties are proven with two
real PostgreSQL sessions in `ConcurrencyTest`.

## §S22.9 Command — `RecordEmploymentJobTitlePeriod`

`handle(EmploymentRelationship, JobTitle, effectiveFrom)` runs inside `AuditedCommandExecutor`:

1. **Lock and check the relationship.** Lock the relationship row. If it has already ended, throw
   `EmploymentRelationshipAlreadyEndedException` (409).
2. **Check the title.** Re-fetch it fresh. If it is missing or inactive, throw
   `InvalidEmploymentJobTitleException` (422, `job_title_id`).
3. **Check against the relationship start.** If `effective_from` is before the relationship start,
   throw `InvalidEmploymentJobTitlePeriodDateException` (422, `effective_from`).
4. **Check against later history.** If `effective_from` is not after the latest period's start,
   throw the same 422.
5. **Close the open period.** If the latest period is open, temporally close it at `effective_from`.
6. **Insert** a new open `KNOWN` period. CHECK and EXCLUDE violations are mapped to 422.

The command has no side effect on the relationship, category (S20), contract (S21), placement (S11)
or any supervisory concept (tested).

## §S22.10 Change semantics

A title change is recorded with the same explicit command; there is no PATCH.

- **Preserved:** the previous period's identity (`id`), `job_title_id`, `effective_from` and
  `start_knowledge_state`.
- **Closed:** only its `effective_to` is temporally closed at the change date. This is temporal
  closure, not historical replacement.

## §S22.11 Relationship end

`EndEmploymentRelationship::closeOpenEmploymentJobTitlePeriodIfAny()` applies exactly the S20
category rule, in the same transaction:

1. **Reject impossible ends.** If a period starts on or after the end date, or (imported only) is
   closed after it, the end is rejected with S09's `InvalidEndDateException` (422, `effective_to`)
   and rolls back atomically. This applies to both the direct and the status-triggered path. Nothing
   is deleted or truncated.
2. **Close the open period.** Otherwise the open period is closed at the end date.
3. **Leave ended titles alone.** A title that already ended is never extended.

**Audit:** the flag `employment_job_title_period_closed_as_consequence` appears on the triggering
entry, whether `hr.employment_relationship.end` or `hr.employment_status_period.record`. It uses the
same locked before/after snapshot as S20/S21, with no separate event.

## §S22.12 Reappointment

A new relationship starts with no title (UNRESOLVED). There is no carry-forward because the Person
is the same. The old relationship keeps its full history, and the new relationship records its own
title (tested).

## §S22.13 Security

| Permission | Route | Gate |
|---|---|---|
| `hr.employment_job_title_periods.view` | `GET …/employment-job-title-periods` | plain RBAC |
| `hr.employment_job_title_periods.record` | `POST …/employment-job-title-periods` | plain RBAC |

- **Separation from the catalog:** `ref.job_titles` stays administered only by `reference.*`, and
  neither `reference.*` nor any other `hr.*` permission grants assignment (tested).
- **Architecture debt (unchanged, documented, not solved here):** relationship-level HR streams
  (S10/S20/S21/S22) are plain RBAC with no organizational scope.

## §S22.14 API

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-job-title-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-job-title-periods
     body: { job_title_id: uuid, effective_from: date }  → 201 EmploymentJobTitlePeriodResource
```

- **Resource fields:** `id`, `employment_relationship_id`, `job_title_id`, `effective_from`,
  `effective_to`, `start_knowledge_state`.
- **Ordering:** the list is ordered most recent first.
- **Ownership:** a relationship not owned by `{person}` returns 404.
- **No other verbs:** there is no PATCH, DELETE or end route.
- **As-of** is the application query `ResolveEmploymentJobTitleForRelationshipAsOf`. It has no HTTP
  route, consistent with S06/S20/S21.

## §S22.15 Error model

| Condition | HTTP |
|---|---|
| validation | 422 |
| relationship not owned / title not found | 404 |
| relationship already ended | 409 |
| title inactive / vanished | 422 `job_title_id` |
| before relationship start / not after latest period / overlap / invalid interval | 422 `effective_from` |
| relationship end incompatible with a title period | 422 `effective_to` |

## §S22.16 Audit

| Write | action | target | changes | metadata |
|---|---|---|---|---|
| record / change | `hr.employment_job_title_period.record` | `hr_employment_job_title_period` / new id | relationship id, job title id, `effective_from` | `job_title_code`; for a change also `previous_period_id` and, when it was open, `previous_period_closed_at` |
| relationship end / status-triggered end (existing) | unchanged | unchanged | unchanged | + `employment_job_title_period_closed_as_consequence` |

- **Rejected writes** roll back and write no success entry.
- **No PII** is written.

## §S22.17 Employee 360

**No change.** The frontend visual runtime is deferred by the owner, and S18 consumes existing
endpoints only. The history is available from the new list endpoint for a later, explicitly
authorized read-model extension. No mock data was added.

## §S22.18 Reporting compatibility (no reporting built)

- **Title as of X:** `ResolveEmploymentJobTitleForRelationshipAsOf(relationship, X)` gives the title
  in force on X, from history, never today's value.
- **Employees by title in a period:** select periods overlapping `[P_from, P_to)` with the same
  `daterange` operator as the EXCLUDE.
- **Change history:** the list query, or the audit trail.
- **Report 2 "administrators":** apply S06's `ResolveJobTitleAdministratorClassificationAsOf` to the
  period's title for the report date.
- **No denormalization.**

## §S22.19 Import compatibility (no import built)

| Source situation | Representation |
|---|---|
| Known current title **with** a known start | `KNOWN` period from that start |
| Known current title from a snapshot, start **unknown** | `UNKNOWN_LEGACY` period whose `effective_from` = the snapshot/evidence boundary, which is not the start (§S22.19a). The start is not fabricated, and as-of before it stays UNRESOLVED. |
| Historical titles with real dates | successive `KNOWN` periods; genuine gaps preserved |
| Unknown or unmappable title value | **no period** (UNRESOLVED / validation flow), never an "other" title |

The API never writes `UNKNOWN_LEGACY`; it is reserved for a future import stage.

## §S22.19a `start_knowledge_state` semantics — CA-S22-01 (Architecture Authority decision)

**Allowed values:** exactly `KNOWN` and `UNKNOWN_LEGACY` (database CHECK).

- **`KNOWN`:** `effective_from` is a known business-effective start date. The employee actually
  began holding this title on that date within this relationship.
- **`UNKNOWN_LEGACY`:** the actual historical start date is **unknown**. The stored
  `effective_from` is only an **evidence/snapshot boundary**, recorded so that the temporal row can
  be represented. It means *"the title is evidenced at this boundary, while its true historical
  start is unknown"*. It does **not** mean *"the employee started this title on
  `effective_from`"*, and it must never be read that way.

**Who can write which value:**
- `RecordEmploymentJobTitlePeriod` and its HTTP endpoint always write `KNOWN`.
- The request contract has no knowledge-state field. Any client-supplied `start_knowledge_state` is
  ignored, and the stored value is still `KNOWN` (tested).
- `UNKNOWN_LEGACY` is reachable only through controlled legacy/import representation, which is a
  future, separately authorized import stage.

**Temporal query semantics:**
- The as-of resolver returns only what the stored evidence supports. For an `UNKNOWN_LEGACY`
  period, a date on or after the boundary resolves to that title. A date **before** the boundary
  resolves to UNRESOLVED (`null`), not to that title and not to an invented earlier period.
- The row makes no claim about any date before its evidence boundary.
- The resolver returns the period together with its `start_knowledge_state`, so every caller can see
  whether `effective_from` is a real start or only a boundary.

**Obligations for future consumers (documentation only, not implemented in S22):**
- **Covered consumers:** reporting, Employee 360 and other read models, import, exports, and any
  historical timeline.
- **The rule:** these consumers must preserve the `KNOWN` / `UNKNOWN_LEGACY` distinction.
- **Display and reporting:** an `UNKNOWN_LEGACY` evidence boundary must never be displayed or
  reported as a verified "job title start date" / «تاريخ بدء المسمى» / «تاريخ استلام المسمى»
  without communicating that the actual historical start is unknown.
- **Duration and tenure:** computations such as "time in title" must not treat the boundary as a
  start.
- **Import:** an import that knows the true start records `KNOWN`. An import that only knows the
  title held at a snapshot records `UNKNOWN_LEGACY` at that snapshot boundary, and never backdates it
  to the relationship start or any other guessed date.

## §S22.20 Boundaries

- **Job description (`job_desc`):** deferred. Its semantics are not frozen, and no free-text column
  or subsystem was created.
- **Supervisory title / status** (مدير عام، مدير دائرة، رئيس قسم …): excluded. It has separate
  semantics (organization, dates, status, decision type, consequences) and remains a candidate
  future *Supervisory Assignment* stage. `ref.supervisory_titles` is untouched (tested).
- **Employment category (S20):** separate. A title change never changes category and vice versa
  (tested). A promotion workflow combining them is a later stage.
- **Qualification / specialty / professional classification:** separate. Nothing is inferred in
  either direction.

## §S22.21 Tests

- **`EmploymentJobTitleHistoryFoundationTest` (new, 30 tests):** basic, temporal, PostgreSQL,
  reference, lifecycle, reappointment, separation, security, audit.
- **`ConcurrencyTest` (+2):** EXCLUDE race; recording vs end on the relationship row lock.
- **`MigrationLifecycleTest`:** S22 rollback/reapply; the S02/S09 paths drop or roll back S22 first;
  permission counts updated.
- **Inventory tests updated:** HR `ScopeBoundaryTest` (nine tables), `DatabaseConstraintsTest`,
  `ApiEndpointsTest`.

## §S22.22 Non-goals

- supervisory assignment
- promotion workflow
- employment category changes
- qualifications, specialties, professional classifications
- salary/payroll, attendance
- vacancy/position management
- contract changes, transfer, secondment, workplace-assignment redesign
- reporting engine, Excel import engine, automation
- frontend redesign / Employee 360 changes
- job-title seed values
- `job_desc`
- S23
