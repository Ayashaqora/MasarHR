# S23 — Person Qualification Foundation (تأسيس مؤهلات الموظف)

**RECONSTRUCTED STAGE TITLE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

Specification and ADR record for **ADR-S23-001 — Person Qualification Foundation**, written by the
Executor (Claude Code Cloud) under the Architecture Authority's "S23 Controlled Full-Stage Execution
Authorization" and its subsequent **ADR-S23-DECISIONS**.

- **Baseline:** `origin/develop @ 96a5ff3` (tag `s22-employment-job-title-history-foundation`). This
  stage is not based on `main`.
- **Sources:** the two original analysis sources remain conceptually separate. No merged master
  analysis exists or is created.

## §S23.1 Reconstruction disclosure

The historical roadmap wording for S23 was not recovered. The title above was supplied by the
Architecture Authority as a reconstruction and is used only as such.

## §S23.2 Discovery

Discovery findings at `96a5ff3`:

| # | Question | Finding |
|---|---|---|
| Q1 | What qualification-related catalogs exist? | `ref.qualification_types` (`2026_09_26_000008`), `ref.academic_degrees` (`…000009`) and `ref.specialties` (`…000011`). All three have the identical simple-reference shape. |
| Q2 | Which are administered through S13? | `qualification_types` and `academic_degrees` are. `specialties` is **not**; its only route is S06's cadre-mapping sub-resource. |
| Q3 | Which are seeded? | **None — all three have 0 rows.** S13 §8 refused to seed the partial vocabulary «بدون + دكتوراة/بورد/مهني/دورة» because no complete, ordered list could be confirmed. |
| Q4 | Is qualification data stored anywhere today? | No. No Person, relationship, read-model or import-staging storage exists. |
| Q5 | Person attribute or employment attribute? | Person. S09 §1 lists qualifications among domains built on the Person foundation. **Approved** by ADR-S23-DECISIONS §1. |
| Q6 | Can one person have several? | Yes — **approved** by ADR-S23-DECISIONS §2. The legacy single-value snapshot does not limit the domain. |
| Q7 | Is there a primary / highest / current concept? | None frozen. **Deferred**, and must not be invented (§4). |
| Q8 | How do type, degree and specialty relate? | Previously undefined. **Decided:** an optional degree plus an optional type, two independent dimensions (§5). |
| Q9 | Is professional specialty separate from academic specialty? | `ref.specialties` is professional/cadre (S06 §12.2 → Monthly Human Cadre, Report 1). Academic specialty is **out of S23** (§7). |
| Q10 | Does reporting already map qualifications? | S06 maps specialty only. Nothing maps qualification. |
| Q11 | Does Employee 360 expect qualifications? | No qualification contract exists. |
| Q12 | Is there an import specification? | None. |
| Q13 | Are there unknown legacy acquisition dates? | No source-supported date exists, so **no date is stored** (§8). |
| Q14 | Are institution / country / grade / certificate / verification fields defined? | Absent everywhere, so they are not added. |
| Q15 | Is experience modeled? | No, and it stays **out of S23** (§9). |

The first execution report stopped at the Go/Stop gate with `ARCHITECTURE_DECISION_REQUIRED`
(qualification identity, «بدون», specialty and dates). The Architecture Authority resolved all of
these in **ADR-S23-DECISIONS**, recorded in §S23.4.

## §S23.3 Gap matrix (after ADR-S23-DECISIONS)

| Capability | Class | S23 action |
|---|---|---|
| Qualification type catalog | EXISTS (empty) | reused, not seeded |
| Academic degree catalog | EXISTS (empty) | reused, not seeded |
| Specialty catalog | OUT_OF_SCOPE | `ref.specialties` untouched; not attached |
| Person qualification storage | MISSING → **added** | `hr.person_qualifications` |
| Multiple qualifications | MISSING → **added** | 0..* per Person |
| Acquisition / effective date | OUT_OF_SCOPE | none stored (§8) |
| Unknown legacy date | OUT_OF_SCOPE | no date, so no marker (§8) |
| Primary / highest designation | DEFERRED | none (§4) |
| Qualification history | OUT_OF_SCOPE | facts are not temporal periods |
| Employee 360 | DEFERRED | the visual runtime is deferred by the owner |
| Reporting | DEFERRED | facts only; "persons having X" is derivable |
| Import | DEFERRED | compatibility principles only (§S23.17) |
| Security | MISSING → **added** | `hr.person_qualifications.*` |
| Audit | EXISTS → reused | `hr.person_qualification.record` |
| Deletion / deactivation | EXISTS → reused | RESTRICT FKs, no hard delete, deactivation-safe |
| Professional classification separation | EXISTS → preserved | no inference |
| Experience separation | OUT_OF_SCOPE | not created |

## §S23.4 ADR-S23-001 — decisions

The Architecture Authority decisions are recorded verbatim in substance (ADR-S23-DECISIONS):

| # | Decision |
|---|---|
| 1 | **Owner:** a qualification belongs to **Person**. |
| 2 | **Cardinality:** Person 1 → 0..* PersonQualification. The legacy snapshot does not limit this to one. |
| 3 | **«بدون»** is not a qualification fact. It creates no row and is not seeded as a qualification type. |
| 4 | **No highest, primary or current qualification**, and no inference from the snapshot. Deferred unless future requirements define one. |
| 5 | **Identity:** `academic_degree_id` (optional) plus `qualification_type_id` (optional). At least one must be present. They are two independent dimensions, not a hierarchy, and there is no automatic mapping between them. |
| 6 | **«دكتوراة» overlap:** not resolved by inference and not seeded into `qualification_types`. The catalogs stay empty unless separately authorized. |
| 7 | **Specialty:** academic specialty is out of S23. `ref.specialties` is not attached and keeps its professional/cadre semantics. No new catalog is created. |
| 8 | **Dates:** no acquisition, graduation or effective date, no `UNKNOWN_LEGACY` marker, and no temporal interval. |
| 9 | **Experience** is out of S23. |
| 10 | **Employment independence:** a qualification survives every employment lifecycle event and reappointment. |
| 11 | **Reporting:** S23 prepares facts only. "Persons having qualification X" is derivable. Highest, primary, ranking and specialty reporting are not defined. |
| 12 | **Legacy import:** «بدون» becomes zero facts. A known value maps only through an approved canonical mapping. An unknown value is an unresolved migration issue, never "other", and nothing is fabricated. |

Executor decisions, all derived from the above without new business rules:

| # | Decision | Basis |
|---|---|---|
| E1 | The duplicate key is the exact identity `(person_id, academic_degree_id, qualification_type_id)` with NULL treated as a value. A duplicate is rejected with **409**, not handled idempotently, which mirrors S09's `DuplicateNationalIdException`. | §5; authorization §14 |
| E2 | Recording is insert-only. Correction and removal are **deferred**; no edit, delete, or hard-delete path exists. | Authorization §13 |
| E3 | Person-level plain RBAC, the same as S09's `hr.persons.*`. | Authorization §18 |

## §S23.5 Ownership and lifecycle independence

`hr.person_qualifications` references `hr.persons` only and never an employment relationship. No
command in S09–S22 reads or writes it.

Qualifications are therefore untouched by any of these (tested):
- relationship creation;
- placement, secondment, workplace assignment and transfer;
- category, job-title and contract recording and renewal;
- status changes;
- status-triggered termination, including a contract-end status and terminal statuses;
- a direct relationship end;
- reappointment.

Reappointment reuses the same Person's facts and never copies them.

## §S23.6 Data model

`hr.person_qualifications` (migration `2026_10_09_000001_create_hr_person_qualifications_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7 |
| `person_id` | `uuid` NOT NULL | FK → `hr.persons`, RESTRICT |
| `academic_degree_id` | `uuid` NULL | FK → `ref.academic_degrees`, RESTRICT |
| `qualification_type_id` | `uuid` NULL | FK → `ref.qualification_types`, RESTRICT |
| `created_at` | `timestamptz` NOT NULL | technical record time (not an acquisition date) |

**Constraints:**
- `person_qualifications_identity_present_check`:
  `academic_degree_id IS NOT NULL OR qualification_type_id IS NOT NULL`.
- `person_qualifications_identity_unique`:
  `UNIQUE NULLS NOT DISTINCT (person_id, academic_degree_id, qualification_type_id)`
  (PostgreSQL ≥ 15).
- Indexes on the three FKs.

**Columns deliberately absent:**
- any date or knowledge state;
- specialty;
- primary/highest/current;
- institution, university, country, certificate number, grade or verification;
- notes or JSON;
- `employment_relationship_id`;
- `version`/`updated_at`;
- any qualification column on `hr.persons`.

**Additive migration:** it creates one table and seeds two permissions
(`2026_10_09_000002_seed_security_person_qualification_permissions.php`). `down()` removes only
those.

## §S23.7 Reference behavior

Both catalogs are reused as-is and stay empty. Administrators populate them through the existing S13
`reference.*` API. S23 seeds nothing, and in particular not «بدون» or «دكتوراة».

- **New record:** each supplied reference must exist (404 otherwise) and be **active** (422
  otherwise).
- **Later deactivation:** never alters, hides or invalidates existing facts, and they remain listed.
- **Hard delete** of a referenced degree or type is blocked by the RESTRICT FKs. A Person with
  qualifications cannot be hard-deleted either.

## §S23.8 Duplicates and concurrency

- **What counts as a duplicate:** the exact same identity for the same Person is rejected (409).
  The same degree with a different type, the same type with or without a degree, and the same fact
  on another Person are all allowed.
- **Enforcement:** the database is the source of truth through `UNIQUE NULLS NOT DISTINCT`. Two
  concurrent identical requests therefore cannot both succeed, and no lock or application pre-check
  is involved. This is proven with two real PostgreSQL sessions in `ConcurrencyTest`.

## §S23.9 Command — `RecordPersonQualification`

`handle(Person, ?AcademicDegree, ?QualificationType)` runs inside `AuditedCommandExecutor`:

1. **Check the identity.** If neither reference is supplied, throw
   `PersonQualificationIdentityMissingException` (422). The database CHECK enforces the same rule.
2. **Check each supplied reference.** Re-fetch it fresh. If it is inactive or vanished, throw
   `InvalidPersonQualificationAcademicDegreeException` or `InvalidPersonQualificationTypeException`
   (422 on the respective field).
3. **Insert.** A unique violation maps to `DuplicatePersonQualificationException` (409).

It never edits, replaces or deletes a fact. It infers nothing, and it changes nothing on the Person,
relationships, or any other stream (tested).

## §S23.10 Separation boundaries

- **Job title, employment category, contract, supervisory title, cadre/professional classification:**
  no inference in either direction, and recording a qualification creates or changes none of them
  (tested).
- **Specialty:** `ref.specialties` (professional/cadre) is not attached. Academic specialty is out of
  scope, and no catalog was created.
- **Experience, supervisory assignment, promotion, vacancy and position:** out of scope and not
  created.

## §S23.11 Dates

None. Because nothing is stored, nothing can be fabricated. `created_at` is the technical record
time only and must never be shown or reported as an acquisition or graduation date.

## §S23.12 Security

| Permission | Route | Gate |
|---|---|---|
| `hr.person_qualifications.view` | `GET /api/v1/hr/persons/{person}/qualifications` | plain RBAC |
| `hr.person_qualifications.record` | `POST /api/v1/hr/persons/{person}/qualifications` | plain RBAC |

- **Separation from the catalogs:** `reference.*` administers the catalogs only and grants nothing
  here. No other `hr.*` permission grants these either (tested).
- **Scope:** Person-level visibility follows S09's plain-RBAC precedent (Person carries no
  organizational unit).
- **Architecture debt (unchanged, documented):** HR Person and relationship-level streams have no
  organizational scope.

## §S23.13 API

```
GET  /api/v1/hr/persons/{person}/qualifications
POST /api/v1/hr/persons/{person}/qualifications
     body: { academic_degree_id?: uuid, qualification_type_id?: uuid }  (at least one)
     → 201 PersonQualificationResource { id, person_id, academic_degree_id, qualification_type_id }
```

- **Ordering:** the list is in recording order. That order is **not** a ranking.
- **Empty list:** means no recorded qualification, which is also how «بدون» is represented.
- **No other verbs:** there is no PATCH, DELETE or correction route.
- **No reporting or import API.**

## §S23.14 Error model

| Condition | HTTP |
|---|---|
| neither reference / malformed id | 422 |
| unknown person / reference not found | 404 |
| reference inactive | 422 (`academic_degree_id` / `qualification_type_id`) |
| exact duplicate | 409 |

## §S23.15 Audit

| Field | Value |
|---|---|
| action | `hr.person_qualification.record` |
| target | `hr_person_qualification` / new id |
| changes | `person_id`, `academic_degree_id`, `qualification_type_id` |
| metadata | `academic_degree_code` and/or `qualification_type_code` (stable codes, only those supplied) |

- **Actor and correlation** come from `AuditedCommandExecutor`.
- **Rejected writes** leave no row and no success entry.
- **No PII** is written.

## §S23.16 Reporting compatibility (no reporting built)

S23 stores facts only.

- **Derivable now:** "persons having qualification X", by degree or by type.
- **Required of any future report:** it must declare its unit: qualification facts, or persons
  having X, or a unique person headcount. Without that, a person with several facts is
  double-counted.
- **Not defined:** highest, primary, ranking and specialty reporting.

## §S23.17 Import compatibility (no import built)

| Source situation | Representation |
|---|---|
| «بدون» | zero facts |
| Known value with an **approved** canonical mapping | one fact with the mapped degree and/or type |
| Unknown or unmappable value | an unresolved migration issue; nothing is stored and nothing becomes "other" |
| Any date in the source | not stored (§S23.11) |

A snapshot never becomes fabricated history.

## §S23.18 Employee 360

**Deferred.** The visual runtime is deferred by the owner. Adding a section would expand S18's
visual slice, and no mock data is added. The list endpoint is ready for a later, authorized
read-model extension.

## §S23.19 Adversarial review

- **Ownership:** a qualification belongs to Person, not an employment relationship; there is no
  `employment_relationship_id`.
- **No confusion with professional specialty:** `ref.specialties` is not attached.
- **No inference with job title or category.**
- **No single-qualification assumption:** a Person can have many facts.
- **No fake "highest" qualification.**
- **No fabricated dates:** no date column exists.
- **Duplicate races:** blocked by the database UNIQUE constraint.
- **Deactivation-safe, and no hard delete** of referenced values or Persons.
- **Reappointment:** no duplication.
- **No PII** in audit.
- **No reference/HR permission confusion.**
- **Reporting double counting:** the unit must be declared (§S23.16).
- **Import:** a snapshot never becomes history.
- **No scope leakage** into experience, supervisory work or promotion.

## §S23.20 Tests

- **`PersonQualificationFoundationTest` (new, 18 tests):** basic, reference, duplicates, lifecycle,
  reappointment, separation, legacy/no-date, security, audit.
- **`ConcurrencyTest` (+1):** two-session duplicate race.
- **`MigrationLifecycleTest`:** S23 rollback/reapply; S02/S09 paths drop or roll back S23 first;
  permission counts updated.
- **Inventory tests:** HR `ScopeBoundaryTest` (ten tables; the `qualification` route segment is no
  longer forbidden, the same precedent as S11/S12/S14/S16), `DatabaseConstraintsTest`,
  `ApiEndpointsTest`.

## §S23.21 Deferred / non-goals

- qualification correction or removal
- highest, primary or current qualification
- academic specialty, and any specialty attachment
- acquisition or graduation dates
- institution and similar fields
- catalog seed values (including «دكتوراة» and «بدون»)
- Employee 360 display
- reporting and import engines
- experience
- supervisory assignment, promotion, vacancy and position
- organizational-scope redesign
- S24
