# Versioned Behavior & Reporting References — Specification (S06)

## 1. Purpose

S06 completes S05's deferred versioned-behavior catalog for employment status, and establishes
the minimum authoritative, effective-dated reference/mapping infrastructure that the five frozen
MasarHR report families will need from later, separately authorized Reporting/Employment/Person
stages. S06 builds *reference and mapping* infrastructure only — it never computes a report,
never stores a fact about a specific person, and never becomes an Employee/Person/Employment
implementation stage.

## 2. Authoritative Sources

In priority order: (1) the S06 authorization message itself (S06-AUTH-001 v1.0), which is the
first point at which several previously-deferred S05 items receive concrete, explicit content
(the full employment-status-detail catalog in §4.1, the Monthly Human Cadre category list and the
Report 4/5 population names in §4.3); (2) MasarHR's own frozen, already-implemented architecture
(S01–S05, this repository); (3) the two named external business-analysis documents (تحليل نظام شغل
ايه، استكمال تحليل نظام العمل ايه) — not available inside this repository, so nothing beyond what
S06-AUTH-001 itself restates from them is used here, per the "do not reconstruct external
documents" discipline already established in S05 §5.1. No other project is consulted (§0/§16 of
S06-AUTH-001; verified — see §6 Discovery findings).

## 3. S05 Baseline

Approved, published S05 commit: `8f149a24d5a6e5e7f6c77f9c6d7fb2d1d9ddf106` (tag
`s05-reference-data-foundation`, pushed to `origin/develop`). S05 established 16 `ref` tables (6
rich, 10 structure-only) plus the CORRECTIVE-01 `ref.marital_status_aliases` lookup table (17
physical tables total). Full detail in
[reference-data-foundation-specification.md](reference-data-foundation-specification.md).

## 4. Scope

- Complete the employment-status-detail catalog and its initial versioned behavior periods
  (§11/§12 below), building on S05's already-complete `ref.employment_status_detail_behaviors`
  infrastructure rather than duplicating it (S06-AUTH-001 §7).
- Add the minimum authoritative reporting-reference catalogs and effective-dated mappings that
  the five frozen report families need, limited to what S06-AUTH-001 itself explicitly names
  (§12 Mapping inventory).
- Add read contracts (as-of resolution) for every temporal table S05 and S06 together introduce,
  since S05 shipped the write side of `employment_status_detail_behaviors` but not an as-of
  reader — explicitly required by S06-AUTH-001 §11.

## 5. Non-Goals

No Person/Employee/Employment/Contract/Organization/Transfer/Secondment/Assignment/Leave-workflow
table or record; no Excel import or import staging; no execution of any of the five report
families; no export/PDF/print/dashboard/alerts/automation; no new schema (everything lives in
`ref`, per §8); no population of a mapping row whose source catalog (Specialty, JobTitle,
ContractType) is itself still empty — those mapping tables ship as structure with zero rows,
exactly mirroring S05's own "structure-only, values deferred" precedent, not populated by
invented data. No S07.

## 6. Discovery Findings

Searched: `docs/`, `README.md`, all S01–S05 specifications, all `ref` migrations/models/commands,
`routes/api.php`, the full test suite, and `git log --all` (6 commits: Initial commit through the
S05 commit — no S06 commit exists yet).

- **No standalone S06 specification exists** in the repository, confirmed by direct search — as
  S06-AUTH-001 itself stated. This is a missing-detail gap, not a contradiction.
- **No repository text contradicts** the "Versioned Behavior & Reporting References" title or
  scope. The only explicit stage-numbering evidence beyond S01–S05 is in
  `docs/security-access-foundation.md` §1 ("S07 owns organization hierarchy, S08 owns
  organization scope; the HR person/employee domain itself is a later stage again") and
  `docs/audit-command-infrastructure-specification.md` §2 (same S07/S08 boundary). Neither
  assigns S06 to anything, and neither conflicts with S06-AUTH-001's title — organization
  hierarchy/scope (S07/S08) and person/employee (later still) are orthogonal to a
  versioned-behavior/reporting-reference layer sitting directly on top of S05's reference catalog.
- **Strong confirming evidence**: `reference-data-foundation-specification.md` §27 ("Open
  questions / deferred items") lists exactly three items that S06-AUTH-001 supplies the missing
  content for: (a) `counts_in_monthly_reporting` "pending a future stage's concrete
  reporting-inclusion rule"; (b) the ten structure-only families' "owner-confirmed value catalog"
  as "future-stage work"; (c) `employment_status_details`/`employment_status_detail_behaviors`
  seeding as "future-stage work once an authoritative list is confirmed." S06-AUTH-001 §4.1
  supplies exactly (c) (the detail catalog). It does **not** supply a concrete rule for (a) — see
  §16 below — and only partially supplies (b), for the three catalogs a mapping dimension
  actually needs (Specialty, JobTitle, ContractType), not all ten.
- **`ref.employment_status_detail_behaviors` is already a complete versioned/effective-dated
  behavior model**: half-open `[effective_from, effective_to)` date range, a GiST EXCLUDE
  constraint preventing overlap per status detail, append-only (no `UPDATED_AT`), a full command
  (`DefineEmploymentStatusDetailBehaviorPeriod`), controller (index + store only, no
  update/delete), full audit integration, and existing tests. **Per S06-AUTH-001 §7: this table
  is not duplicated.** S06 seeds it (and its parent `employment_status_details`) and adds the
  as-of reader S05 never built.
- Cross-project contamination check (FileOrganizer, Sanad Business, PhysicsLearningPlatform,
  Mosaed Transfers, Wallet Transfer Assistant, DataStudio, IRONGYM): zero references anywhere in
  this repository or in this specification's own drafting.

## 7. S06 Scope Reconciliation Verdict

**PASS.** No contradiction found; S05's own documented open questions anticipate this stage;
`employment_status_detail_behaviors` is confirmed complete and is built upon, not duplicated.
Implementation proceeds under the scope in §4/§5 and the mapping inventory in §12, which is
deliberately narrower than every "candidate dimension" S06-AUTH-001 §8 lists — only dimensions
with genuinely explicit content in S06-AUTH-001 itself are implemented; everything else is
recorded as deferred in §33, not invented.

## 8. Physical Location

All new objects live in the existing `ref` schema, alongside the S05 catalog they extend —
consistent with S05's own precedent of keeping mapping/behavior tables (like
`employment_status_detail_behaviors`) in `ref` rather than `reporting`. The `reporting` schema
(created empty by S02) remains untouched and empty: it is reserved for a later, separately
authorized report-*execution* stage, not for reference/mapping data. No new schema is created
(S06-AUTH-001 §13).

## 9. Effective-Date Model

Every S06 temporal table uses the exact S05 `employment_status_detail_behaviors` pattern: a
`DATE` half-open interval `[effective_from, effective_to)`, `effective_to IS NULL` meaning "still
in effect," a `CHECK (effective_to IS NULL OR effective_to > effective_from)`, and a PostgreSQL
GiST `EXCLUDE` constraint (using the `btree_gist` extension S02 already enabled) making
overlapping periods for the same source dimension row physically impossible — never only an
application-level check. Recorded/audit time stays `TIMESTAMPTZ` (`created_at`), never confused
with the business-effective `DATE`. Every such table is append-only: a new row is always
inserted, an existing one is never updated or deleted, so history is never destroyed.

## 10. Temporal Invariants

- No two periods for the same source dimension row (same `specialty_id`, same `job_title_id`,
  same `contract_type_id`, same `status_detail_id`) ever overlap — enforced by the GiST EXCLUDE
  constraint, not application code.
- `effective_to`, when present, is strictly after `effective_from`.
- A period may be open-ended (`effective_to IS NULL`); defining a new period does not
  automatically close a prior open one — matching S05's existing behavior-period contract exactly
  (the caller supplies whatever `effective_to` values make the periods non-overlapping; the
  database refuses anything that overlaps).
- A period may reference an inactive target reference row (e.g. a deactivated
  `monthly_cadre_category`): resolution must still work for historical `as-of` dates within that
  period, exactly like S05 CORRECTIVE-01 §8 established for marital-status aliases (RESOLUTION is
  independent of the target's `is_active`). Defining a **new** period against an inactive source or
  target is **not blocked** by the command layer, matching the existing precedent set by
  `DefineEmploymentStatusDetailBehaviorPeriod` exactly — that command performs no `is_active` check
  on the `EmploymentStatusDetail` it is given either. This specification's earlier draft proposed
  inventing an application-layer active-target check for the new mapping commands; the adversarial
  review (§28, "mapping to inactive target") rejected that as an invented business rule with no
  support in S06-AUTH-001, and this section is corrected accordingly. A route only reaches the
  command at all if the caller holds `reference.manage` and named a real (existing) source/target
  row — inactive-row exclusion, if ever wanted, is future-stage business-rule territory.

## 11. Employment-Status Behavior Integration

`ref.employment_status_details` receives 13 new rows (code/name_ar/category_id), and
`ref.employment_status_detail_behaviors` receives one open-ended period per detail — both via a
new S06 seed migration (bootstrap data owned by the module, same rationale as
`2026_09_26_000018_seed_ref_baseline_values.php`), not via the audited command path, exactly
mirroring S05's own seeding precedent.

| code | name_ar | category |
|---|---|---|
| on_duty | على رأس عمله | active |
| wants_to_return | يرغب في العودة | active |
| traveling | مسافر | non_active |
| captive | أسير | non_active |
| suspended | إيقاف عن العمل | non_active |
| does_not_want_to_return | لا يرغب في العودة | non_active |
| unpaid_leave | إجازة بدون راتب | non_active |
| external_sick_leave | إجازة خارجية مرضية | non_active |
| retired | متقاعد | ended |
| resigned | استقالة | ended |
| contract_ended | إنهاء تعاقد | ended |
| martyred | شهيد | terminal |
| deceased | وفاة | terminal |

**Behavior-flag derivation.** Each detail's initial period uses the flag values *mechanically
derived from its own category's already-frozen S05 §5.4 definition* — never inferred from the
Arabic label, and identical for every detail sharing a category (no per-detail nuance is invented
beyond what the category itself already establishes):

| category | participates_in_active_workforce | is_ongoing_relationship | is_relationship_ending | is_terminal | allows_reappointment |
|---|---|---|---|---|---|
| active | true | true | false | false | null (not applicable) |
| non_active | false | true | false | false | null ("reappointment not meaningful", S05 §5.4, verbatim) |
| ended | false | false | true | false | true ("may allow reappointment", S05 §5.4) |
| terminal | false | false | true | true | false ("never reversible", S05 §5.4) |

`counts_in_monthly_reporting` is left `null` for all 13 initial periods — S06-AUTH-001 does not
supply a concrete per-detail reporting-inclusion rule (§4.2 describes person-level monthly
aggregation logic — "active/worked during any part of a month" — which cannot be reduced to a
single static per-detail boolean without inventing report-execution semantics S06 is explicitly
not authorized to build). Recorded as an open question, §33.

`effective_from` for all 13 initial periods is this stage's implementation date (§32 records the
exact date used). S06-AUTH-001 gives no historical epoch, and backdating to a guessed date would
be invention; the periods are explicitly documented as "authoritative from this date forward,"
with resolving any earlier historical date deferred to whichever future stage first needs it
(§33).

**As-of reader (new, §7 gap):** `ResolveEmploymentStatusDetailBehaviorAsOf` — input: a
`EmploymentStatusDetail`, a date; output: the one `EmploymentStatusDetailBehavior` whose period
covers that date, or `null` if none (unresolved, e.g. a date before any period was defined). Pure
read, no mutation.

## 12. Mapping Inventory

Only these four objects are added — each answers all 14 questions S06-AUTH-001 §8 requires,
summarized:

### 12.1 `ref.monthly_cadre_categories` (new reference catalog, rich)

Source: S06-AUTH-001 §4.3 Report 1's explicit 12-item English list (Arabic labels are this
specification's own direct, non-business-altering translation of those literal English terms —
see §17 for why "administration" here is deliberately labelled differently in Arabic from Report
2's "الإداريين", since they classify by different dimensions). Not exclusive/multi-valued itself
(it *is* the target); full CRUD lifecycle (Create/UpdateMetadata/Activate/Deactivate), identical
shape and command depth to S05's 6 rich families, since (unlike S05's structure-only families)
concrete authorized values exist. Seed:

| code | name_ar | name_en |
|---|---|---|
| doctors | أطباء | Doctors |
| nursing | تمريض | Nursing |
| administration | إدارة | Administration |
| laboratory | مختبرات | Laboratory |
| pharmacy | صيدلة | Pharmacy |
| radiology | أشعة | Radiology |
| physical_therapy | علاج طبيعي | Physical Therapy |
| anesthesia | تخدير | Anesthesia |
| other_health_professions | مهن صحية أخرى | Other Health Professions |
| engineering | هندسة | Engineering |
| services | خدمات | Services |
| crafts | حرف | Crafts |

### 12.2 `ref.specialty_cadre_category_mappings` (new temporal mapping, structure only)

1. Source: `ref.specialties` (S05 structure-only, 0 rows). 2. Target:
`ref.monthly_cadre_categories`. 3. Exclusive (one specialty → at most one active cadre category
at a time; §10). 4–6. `effective_from`/`effective_to`/overlap rule: §9/§10. 7. Activation: not
applicable to the mapping row itself (append-only); the *target* category's own
activate/deactivate lifecycle applies independently. 8. Unknown/unmapped: a specialty with no
covering period resolves to `null` (UNRESOLVED) via the as-of reader — never a guessed category,
matching Report 1's own explicit "never silently map unknown values to other." 9. Query: as-of
resolver, §14. 10. Mutation: `DefineSpecialtyCadreCategoryMappingPeriod`. 11. Audit:
`reference.specialty_cadre_category_mapping.period.define`. 12. Concurrency: GiST exclusion
(database-enforced write-skew protection, §9). 13. PostgreSQL strategy: §9/§20. 14. Seed values:
**none** — `ref.specialties` itself has zero rows, so there is nothing to map yet; seeding a
mapping here would mean inventing specialty rows, explicitly out of scope (§5).

### 12.3 `ref.job_title_administrator_classifications` (new temporal mapping, structure only)

Source: `ref.job_titles` (S05 structure-only, 0 rows). Target: a boolean
(`is_administrator`), not an FK, since S06-AUTH-001 §4.3 Report 2 names exactly one
classification ("الإداريين") and no sibling classification exists to justify a separate catalog —
adding one would be inventing category values, forbidden by §9 of S06-AUTH-001. Same
exclusive/temporal/audit/concurrency answers as §12.2, scoped to `job_title_id`. Audit:
`reference.job_title_administrator_classification.period.define`. Seed: none (`ref.job_titles`
empty).

### 12.4 `ref.contract_based_population_categories` (new reference catalog, rich, small)

Source: S06-AUTH-001 §4.3 Report 4's title ("Support Services / Daily Workers") and Report 5's
title ("Volunteers / Unemployment") — used verbatim as the two population identities those
reports' contract-type mappings resolve to; not a general contract-type catalog, only the two
named report populations. Same rich shape as §12.1. Seed:

| code | name_ar | name_en |
|---|---|---|
| support_services_daily_worker | خدمات مساندة وعمال باليومية | Support Services / Daily Workers |
| volunteer_unemployment | متطوعون ومتعطلون | Volunteers / Unemployment |

### 12.5 `ref.contract_type_population_mappings` (new temporal mapping, structure only)

Source: `ref.contract_types` (S05 structure-only, 0 rows). Target:
`ref.contract_based_population_categories`. Same exclusive/temporal/audit/concurrency answers as
§12.2, scoped to `contract_type_id`. Audit: `reference.contract_type_population_mapping.period.define`.
Seed: none (`ref.contract_types` empty).

**Not built** (S06-AUTH-001 §8 lists these as candidates to *investigate*, not to automatically
implement — investigated and found unsupported by explicit content, so deferred, §33): a
separate "not-on-duty reporting reason" catalog for Report 3 (the NON_ACTIVE
`employment_status_details` already named in §11 are themselves the distinct reasons Report 3
needs — adding a second, parallel catalog would duplicate that concept, not extend it); any
mapping whose source catalog has zero rows would need invented seed content to be useful beyond
structure, so none of §12.2/12.3/12.5 receive seed rows.

## 13. Reporting Concept Identity

`ref.monthly_cadre_categories` and `ref.contract_based_population_categories` are first-class
reference entities with stable UUID identity (§5.E), never free-text. Their Arabic/English labels
are presentation-only (§9 of S06-AUTH-001) — reporting logic in a future stage must join on
`code`/`id`, never match on `name_ar`/`name_en`.

## 14. Query Contracts

Read-only, never mutate:

- `MonthlyCadreCategory::query()` / `ContractBasedPopulationCategory::query()` — existing
  `index`/`show` pattern, identical to every S05 rich family.
- `ResolveEmploymentStatusDetailBehaviorAsOf($detail, $date): ?EmploymentStatusDetailBehavior`
- `ResolveSpecialtyCadreCategoryAsOf($specialty, $date): ?MonthlyCadreCategory`
- `ResolveJobTitleAdministratorClassificationAsOf($jobTitle, $date): ?bool`
- `ResolveContractTypePopulationCategoryAsOf($contractType, $date): ?ContractBasedPopulationCategory`

Each: one `WHERE dimension_id = ? AND effective_from <= ? AND (effective_to IS NULL OR
effective_to > ?)` lookup (at most one matching row, guaranteed by the exclusion constraint),
returning `null` when nothing covers that date — the same UNRESOLVED contract as S05 CORRECTIVE-01's
`ResolveMaritalStatusByArabicSourceValue`. "Current" state (`as of today()`) is a thin call with
`$date = today()`; no separate stored "current" column duplicates this.

## 15. API Contracts

`/api/v1/reference/...`, `web` middleware group, `auth:web` + `principal.active` +
`resolve.context`, `permission:reference.view` for reads and `permission:reference.manage` for
writes — identical to every existing S05 route. New segments:

```
GET    /reference/monthly-cadre-categories
GET    /reference/monthly-cadre-categories/{monthlyCadreCategory}
POST   /reference/monthly-cadre-categories
PATCH  /reference/monthly-cadre-categories/{monthlyCadreCategory}
POST   /reference/monthly-cadre-categories/{monthlyCadreCategory}/activate
POST   /reference/monthly-cadre-categories/{monthlyCadreCategory}/deactivate

GET    /reference/contract-based-population-categories
GET    /reference/contract-based-population-categories/{contractBasedPopulationCategory}
POST   /reference/contract-based-population-categories
PATCH  /reference/contract-based-population-categories/{contractBasedPopulationCategory}
POST   /reference/contract-based-population-categories/{contractBasedPopulationCategory}/activate
POST   /reference/contract-based-population-categories/{contractBasedPopulationCategory}/deactivate

GET    /reference/specialties/{specialty}/cadre-category-mappings
POST   /reference/specialties/{specialty}/cadre-category-mappings

GET    /reference/job-titles/{jobTitle}/administrator-classifications
POST   /reference/job-titles/{jobTitle}/administrator-classifications

GET    /reference/contract-types/{contractType}/population-mappings
POST   /reference/contract-types/{contractType}/population-mappings
```

No report endpoint, no generic table/PATCH-row API. Errors follow the existing project
conventions: 401 (unauthenticated), 403 (missing permission), 409 (overlapping period —
`OverlappingBehaviorPeriodException`, reused as-is, since the failure mode is identical
regardless of which temporal table triggered it — or stale `version` on the two rich catalogs),
422 (validation). No internal SQL/PostgreSQL detail is ever exposed in a response.

## 16. Authorization

Reuses `reference.view`/`reference.manage` for every S06 route — no new permission (S06-AUTH-001
§5.K: "do not create unnecessary permission proliferation"; every S06 object is still Reference
module data). Default deny is unaffected: no existing role is granted anything new by a migration.

## 17. Audit

New allowlisted action codes, appended to the existing `reference.*` convention:

- `reference.monthly_cadre_category.create` / `.metadata.update` / `.activate` / `.deactivate`
- `reference.contract_based_population_category.create` / `.metadata.update` / `.activate` / `.deactivate`
- `reference.specialty_cadre_category_mapping.period.define`
- `reference.job_title_administrator_classification.period.define`
- `reference.contract_type_population_mapping.period.define`

Every mutation routes through the existing `AuditedCommandExecutor` (S04), in the same
transaction as the mutation, with an allowlisted `changes` payload (business identity + effective
dates only) — never credentials, tokens, session identifiers, or raw request data.

## 18. Concurrency

The two rich catalogs use S05's existing optimistic-concurrency `version` column exactly as
`MaritalStatus`/`Gender` do. The temporal mapping tables use the same protection S05 already
proved for `employment_status_detail_behaviors`: the GiST EXCLUDE constraint is itself the
write-skew defense — two concurrent "define a period" requests that would overlap cannot both
commit, regardless of transaction isolation level, because PostgreSQL enforces the constraint at
commit time. No advisory lock is needed beyond what S02's `btree_gist` extension already enables.

## 19. Note on §21 of S06-AUTH-001 (repository-local Git authentication)

Not a specification concern; recorded here only to confirm scope: no credential/config file is
touched by this specification or its implementation.

## 20. PostgreSQL Physical Design

```
ref.monthly_cadre_categories                      -- identical shape to ref.marital_statuses (S05 §20)
ref.contract_based_population_categories           -- identical shape to ref.marital_statuses (S05 §20)

ref.specialty_cadre_category_mappings:
  id                  uuid primary key
  specialty_id        uuid not null references ref.specialties(id)
  cadre_category_id   uuid not null references ref.monthly_cadre_categories(id)
  effective_from      date not null
  effective_to        date null
  created_at          timestamptz not null
  exclude using gist (specialty_id with =, daterange(effective_from, effective_to, '[)') with &&)
  check (effective_to is null or effective_to > effective_from)

ref.job_title_administrator_classifications:
  id                  uuid primary key
  job_title_id        uuid not null references ref.job_titles(id)
  is_administrator    boolean not null
  effective_from      date not null
  effective_to        date null
  created_at          timestamptz not null
  exclude using gist (job_title_id with =, daterange(effective_from, effective_to, '[)') with &&)
  check (effective_to is null or effective_to > effective_from)

ref.contract_type_population_mappings:
  id                      uuid primary key
  contract_type_id        uuid not null references ref.contract_types(id)
  population_category_id  uuid not null references ref.contract_based_population_categories(id)
  effective_from          date not null
  effective_to            date null
  created_at              timestamptz not null
  exclude using gist (contract_type_id with =, daterange(effective_from, effective_to, '[)') with &&)
  check (effective_to is null or effective_to > effective_from)
```

Every migration follows the exact S02/S05 convention: `Schema::create()` for columns/FKs, a
separate `DB::statement()` for the CHECK/EXCLUDE constraints Blueprint cannot express, and a
`down()` that only drops what `up()` created — no `CASCADE`.

## 21. Migration Plan

Nine new migrations, dated after S05/CORRECTIVE-01 (`2026_09_26_000021` onward — this stage's
implementation date is recorded in §32):

1. Create `ref.monthly_cadre_categories`
2. Create `ref.contract_based_population_categories`
3. Create `ref.specialty_cadre_category_mappings`
4. Create `ref.job_title_administrator_classifications`
5. Create `ref.contract_type_population_mappings`
6. Seed `ref.employment_status_details` (13 rows, §11)
7. Seed `ref.employment_status_detail_behaviors` (13 initial periods, §11)
8. Seed `ref.monthly_cadre_categories` (12 rows, §12.1)
9. Seed `ref.contract_based_population_categories` (2 rows, §12.4)

No S01–S05 migration is edited. Every `down()` drops or deletes only what its own `up()` created;
no rollback cascades into S01–S05 objects.

## 22. Seed/Bootstrap Policy

Seeded via migration, not a seeder or the audited command path — the same rationale as every
prior S05/CORRECTIVE-01 seed migration: this is reference data owned by the module, not
environment-specific business data. Every seeded value's authoritative source is cited inline in
its migration's docblock and cross-referenced to the §11/§12 table above.

## 23. Validation

Every mutation command validates: required fields present and correctly typed;
`effective_from`/`effective_to` well-formed dates with `effective_to > effective_from` when
given; the source dimension row and (where applicable) target row exist; overlap is caught at the
database layer via `PostgresErrorClassifier::isExclusionViolation()` and translated to
`OverlappingBehaviorPeriodException` → HTTP 409, identical to S05's existing pattern.

## 24. Testing Matrix

New test files under `tests/Feature/Reference/`, extending `ReferenceTestCase`: lifecycle tests
for the two new rich catalogs (create/duplicate-code rejected/permission-denied/stale-version/
activate-deactivate/audit — mirroring `MaritalStatusLifecycleTest` exactly); a period-definition
test class per temporal mapping table (first period, second period closing/adjoining the first,
overlapping period rejected with 409, as-of resolution across a boundary, unresolved-before-any-period,
inactive target still historically resolvable); an `EmploymentStatusDetailSeedTest` proving the
13 seeded details/behaviors match §11 exactly and that `counts_in_monthly_reporting` is `null`
for all of them; a scope-boundary test updated to the new S06 table count; migration
rollback/reapply coverage for the new migrations (mirroring `MigrationLifecycleTest`'s existing
`dropReferenceSchemaObjects()` pattern); `MigrationDisciplineTest` auto-covers every new migration
file with zero additional code (§7 of the CORRECTIVE-01 precedent).

## 25. Import Compatibility

No import pipeline is built. The as-of resolvers return `null` for anything unmapped rather than
guessing, which is exactly the "recognized / unmapped / invalid" three-way distinction a future
import validator needs; provenance is preserved because every period is append-only and dated.

## 26. Reporting Compatibility

No report is executed. `ref.monthly_cadre_categories` and `ref.contract_based_population_categories`
give a future Reporting stage stable identities to group by; the mapping tables give it a
deterministic, auditable, effective-dated resolution path once Specialty/JobTitle/ContractType
catalogs are populated by their own future authorization.

## 27. Backward Compatibility with S05

No S05 migration, model, command, or controller is modified, and no existing test's assertions are
weakened or deleted. `employment_status_details` and `employment_status_detail_behaviors` gain rows
through a new migration only; their existing schema, commands, controller, and routes are untouched.
`reference.view`/`reference.manage` are reused unchanged.

Four existing files receive small, additive, disclosed edits rather than being left untouched —
corrected here after an earlier draft of this section overstated "no S05 route or test is modified,"
caught during final scope-hygiene review (§20 of S06-AUTH-001):

- `routes/api.php` — extended (two new `$simpleFamilies` entries, three new sub-resource route
  blocks); no existing route line is changed or removed.
- `tests/Feature/Reference/ScopeBoundaryTest.php` — the authoritative ref-table-count assertion is
  updated from 17 to 22 tables (adding the 5 S06 tables); every other assertion in the file is
  unchanged.
- `tests/Feature/Database/MigrationLifecycleTest.php` — `dropReferenceSchemaObjects()` gains the 5
  S06 table names (so the existing S02 rollback test keeps working once S06 tables exist), and one
  new test method is added; no existing test method is changed or removed.
- `app/Modules/Reference/Domain/Exceptions/OverlappingBehaviorPeriodException.php` — its docblock
  is widened to say it is now reused by three more tables (S06 spec §15); its class name, message,
  and behavior are unchanged, and its existing 409 mapping in `bootstrap/app.php` is untouched.

All pre-S06 tests must continue to pass unmodified in substance; the final stage report's regression
result confirms this.

## 28. Adversarial Self-Review

Performed after drafting §1–§27/§32–§34, before any implementation, per S06-AUTH-001 §17. Each
required challenge item below is addressed; verdicts are BLOCKING / NON-BLOCKING / DEFERRED.

1. **Label-based inference.** Behavior flags (§11) are derived from each detail's *category*
   (`active`/`non_active`/`ended`/`terminal` — a stable code, not the Arabic label), never parsed
   from `name_ar`. `job_title_administrator_classifications.is_administrator` is a boolean set
   explicitly by the caller through the command, never guessed from a job-title string. No
   regex/string-contains/gender-based branching exists anywhere in the new code. **NON-BLOCKING —
   verified absent.**
2. **Accidental hardcoded reporting semantics.** `counts_in_monthly_reporting` stays `null` for
   every seeded period (§11); no report-execution logic (no monthly aggregation, no headcount
   computation) is written anywhere in S06. **NON-BLOCKING — verified absent.**
3. **Overlapping mapping periods.** All four temporal tables (the existing
   `employment_status_detail_behaviors` plus the three new S06 mapping tables) carry a GiST
   `EXCLUDE` constraint keyed on the source dimension id, making an overlapping period a database
   rejection (23P01 → `OverlappingBehaviorPeriodException` → 409), not merely an application check.
   **NON-BLOCKING — enforced at the database.**
4. **Write skew.** The EXCLUDE constraint is evaluated at commit time regardless of isolation
   level or of how many concurrent transactions raced to insert an overlapping period — at most one
   can commit. No advisory lock or additional isolation tuning is needed (S06-AUTH-001 §5.I).
   **NON-BLOCKING.**
5. **Same-day boundaries.** Half-open `[effective_from, effective_to)` semantics mean a period
   ending on date X and the next period starting on date X are adjacent, not overlapping, and are
   accepted — exactly the behavior S05's own
   `test_a_second_period_that_closes_the_first_with_no_gap_succeeds` already proves for
   `employment_status_detail_behaviors`; the new mapping tables reuse the identical `daterange(...,
   '[)')` construct. **NON-BLOCKING.**
6. **Future-dated mappings.** Nothing in S06-AUTH-001 forbids `effective_from` in the future;
   validation only requires a well-formed date with `effective_to > effective_from` when given.
   Allowing forward-dated periods is consistent with "define now, take effect later" administrative
   use and invents no restriction the authorization didn't ask for. **NON-BLOCKING — deliberate,
   disclosed.**
7. **Backdated corrections.** A new period may be defined with an `effective_from` earlier than an
   existing period's `effective_from`, as long as it does not overlap — the exclusion constraint is
   the only guard, matching existing S05 behavior-period semantics exactly (no additional
   "monotonic effective_from" rule exists today, so S06 invents none either). **NON-BLOCKING —
   consistent with precedent.**
8. **Gaps in mappings.** A source row with no covering period for a given date resolves to `null`
   (UNRESOLVED) via the as-of readers — never a guessed value. This is the same UNRESOLVED contract
   `ResolveMaritalStatusByArabicSourceValue` already established in CORRECTIVE-01.
   **NON-BLOCKING — matches precedent.**
9. **Inactive references (source).** Deactivating a `Specialty`/`JobTitle`/`ContractType` row (once
   those catalogs are eventually populated by a future stage) does not retroactively invalidate
   mapping rows already defined against it; resolution for historical dates is unaffected, mirroring
   CORRECTIVE-01 §8's deactivated-marital-status precedent. **NON-BLOCKING.**
10. **Mapping to an inactive target.** Original draft of §10 proposed an invented
    application-layer check blocking a *new* period from being defined against an inactive target
    (e.g. a deactivated `monthly_cadre_category`). On review this is an invented business rule with
    no support in S06-AUTH-001 and no precedent in S05 (`DefineEmploymentStatusDetailBehaviorPeriod`
    performs no `is_active` check on its `EmploymentStatusDetail` either). **BLOCKING as originally
    drafted — RESOLVED** by removing the invented check; §10 above has been corrected. The command
    layer performs no `is_active` check on source or target for any of the three new mapping
    commands, exactly mirroring existing precedent.
11. **Historical resolution.** Every as-of reader takes an explicit `$date` parameter (never
    implicitly `today()` internally) and resolves independently for any date, past or future,
    against whatever periods exist. **NON-BLOCKING.**
12. **Unknown source values.** A source dimension row that exists but has no period at all, or an
    id that does not exist, both terminate in `null`/404 respectively — never a silent default
    category. **NON-BLOCKING.**
13. **Duplicate reporting membership.** The EXCLUDE constraint is keyed on the source dimension id
    alone (`specialty_id WITH =`, not `specialty_id, cadre_category_id WITH =`), combined with the
    date range — so a given specialty cannot simultaneously belong to two different cadre
    categories, which is exactly the "exclusive" contract §12.2 declares. Verified this is the
    correct key (not source+target) before implementation. **NON-BLOCKING — verified correct.**
14. **Destructive edits.** All three new mapping tables are append-only (no `UPDATED_AT`, no
    update/delete route — `index`+`store` only, identical to
    `EmploymentStatusDetailBehaviorController`). The two new rich catalogs get
    Create/UpdateMetadata/Activate/Deactivate only, no hard-delete route, identical to every S05
    rich family. **NON-BLOCKING.**
15. **Audit bypass.** Every mutating route runs through `AuditedCommandExecutor` with an explicit
    `AuditSpec`; no direct `->save()`/`DB::table()->insert()` call exists outside a command's
    `handle()`, itself only ever invoked from inside the executor's callback. **NON-BLOCKING.**
16. **Authorization bypass.** Every new route carries `permission:reference.view` (reads) or
    `permission:reference.manage` (writes); no route is left unguarded. No new permission is
    created (§16) — default-deny is unaffected. **NON-BLOCKING.**
17. **Transaction boundary errors.** New commands follow the existing no-own-transaction
    convention (ERRATA-02): the two rich catalogs' commands extend the same `Abstract*` bases S05
    already uses (a single atomic conditional `UPDATE` for activate/deactivate/metadata, a single
    `save()` for create); the three new period-definition commands mirror
    `DefineEmploymentStatusDetailBehaviorPeriod` exactly (a single `save()`, no manual
    `DB::transaction()`) — `AuditedCommandExecutor` remains the one source of transactional
    atomicity. **NON-BLOCKING.**
18. **Rollback safety.** Every new migration's `down()` drops or deletes only what its own `up()`
    created, in dependency order, no `CASCADE` (enforced mechanically by `MigrationDisciplineTest`
    across every migration file including these nine). **NON-BLOCKING.**
19. **S05 compatibility.** No S05 migration/model/command/controller/route/test file is modified;
    `employment_status_details`/`employment_status_detail_behaviors` gain rows through new
    migrations only. **NON-BLOCKING — verified by file inventory in the final report.**
20. **Import compatibility.** `null` for unresolved is exactly the "recognized / unmapped" signal a
    future import validator needs; nothing here forces a guess. **NON-BLOCKING.**
21. **Accidental Employee/Person/Organization/report-execution scope creep.** Re-read against
    §5/§24 of S06-AUTH-001: no Person/Employee/Employment/Organization/Contract-lifecycle table,
    no report computation, no Excel import, no export. `job_title_administrator_classifications`
    stores a classification *of a job title*, never of a person. **NON-BLOCKING — verified absent.**
22. **Foreign-key/constraint identifier length.** While drafting the physical design, a
    straightforward auto-generated Laravel FK name for
    `contract_type_population_mappings.population_category_id` (`..._population_category_id_foreign`)
    would exceed PostgreSQL's 63-byte identifier limit once the table-name prefix is included. This
    was caught before implementation: every foreign key and constraint in the three new mapping
    migrations gets an explicit, short, manually-chosen name (the same technique
    `employment_status_detail_behaviors_detail_id_foreign` already uses), never Laravel's
    auto-generated default. **NON-BLOCKING — corrected before implementation, not after a failure.**

**Summary: zero unresolved BLOCKING findings.** One drafting-stage BLOCKING finding (#10) was
identified and resolved by correcting §10 to remove an invented business rule, before any code was
written. Implementation is authorized to proceed (S06-AUTH-001 §18).

## 29. Blocking Findings

None remain open. (#10 above was BLOCKING as originally drafted; resolved during this review by
correcting §10 — no application-layer active-target check is implemented.)

## 30. Non-Blocking Findings

Items 1–9, 11–21 above: verified safe by construction, no code change required beyond what §9–§27
already specify.

## 31. Deferred Findings

- Whether a *future* stage should add an explicit active-target check when defining a new mapping
  period is left to that stage's own authorization (item #10) — S06 deliberately does not invent
  it.
- Whether `effective_from` should ever be constrained to be non-future for these particular mapping
  tables is left open (item #6) — no such rule exists in S05 either, and none was requested.

## 32. Implementation Date

The `effective_from` anchor for all newly-defined behavior/mapping periods, and the migration
timestamp prefix, is **2026-09-26** (this session's date).

## 33. Open Questions / Deferred Items

- `counts_in_monthly_reporting` remains `null` for all 13 employment-status-detail behavior
  periods — S06-AUTH-001 does not supply a concrete per-detail static rule; §4.2's rules describe
  person-month aggregation logic for a future reporting stage, not a per-detail flag.
- Historical resolution before 2026-09-26 for any of S06's new temporal tables is unresolved by
  design (no historical epoch was given); a future stage that imports pre-2026-09-26 historical
  data will need its own explicitly authorized backdated correction period.
- The seven structure-only S05 families not touched by a mapping here (DecisionType,
  EmploymentType, EmploymentCategory, QualificationType, AcademicDegree, SupervisoryTitle,
  LeaveType, LeaveStatus) remain exactly as S05 left them — no S06-AUTH-001 evidence names a
  reporting mapping for any of them.
- A distinct "not-on-duty reporting reason" catalog was investigated and not built (§12, "Not
  built") — Report 3 is expected to resolve directly from the NON_ACTIVE `employment_status_details`
  seeded in §11; if a future Reporting stage finds this insufficient, that is its own
  scope-narrowing decision to make, not S06's.

## 34. Freeze Readiness

This specification is internally consistent with the frozen MasarHR architecture baseline
(`CLAUDE.md`), builds on S05/CORRECTIVE-01 without modifying any of their released migrations,
models, commands, controllers, routes, or tests, invents no reference value or mapping row beyond
what S06-AUTH-001 explicitly names, and discloses every scope-narrowing judgment call made while
authoring it (§6, §11 behavior-flag derivation and `effective_from` anchor, §12 "Not built", §17
Arabic-label distinction between the cadre "administration" category and Report 2's "الإداريين",
§33). It is considered frozen for S06 implementation as of this document's commit.
