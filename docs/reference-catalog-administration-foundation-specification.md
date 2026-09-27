# Reference Catalog Administration Foundation — Specification (S13)

## 1. Document Control

- **Stage:** S13 (reconstructed — see §2).
- **Title (EN):** Reference Catalog Administration Foundation.
- **Title (AR):** تأسيس إدارة القوائم المرجعية.
- **Classification:** Architecture / Dependency Reconstruction.
- **Governing authorization:** "MASARHR — S13 RECONSTRUCTION DECISION + FULL EXECUTION
  AUTHORIZATION" (this document is referred to throughout as "the S13 authorization").
- **Baseline commit:** `e9295008096ff98001322319fda6b121b966be0b` (tag
  `s12-full-secondment-foundation`, `origin/develop`, `origin/main` unaffected at
  `45259c97ca8dda8101d628bfe20bdb969c807cf4`).
- This document is **not** a claim that "Reference Catalog Administration Foundation" is the
  historical S13 title. It is Architecture Authority's reconstruction of the stage after the prior
  S13 dependency-frontier investigation found zero ready business domains (see §2).

## 2. Reconstruction Basis (ADR-S13-001)

**Status:** Accepted.

**Context.** A first S13 authorization asked for historical-S13-title recovery followed by a live
dependency-frontier reconstruction across fourteen named candidate domains (Transfer, Work
Schedule, Partial Secondment, Job/Professional History, Employment Category History, Contract
Lifecycle, Qualification Assignment, Supervisory Assignment, Leave, Migration/Import, Reporting,
Data Quality, Follow-up/Alerts, and an open slot). Repository discovery found no historical S13
wording anywhere in `docs/`, git history, or elsewhere in the repository. The live
dependency-frontier reconstruction then found **every one of the fourteen candidates blocked** —
not the "exactly one ready candidate" pattern every prior stage (S09–S12) had found, but a
qualitatively different result: zero ready business domains. The investigation traced the common
blocker across the candidates and found the same root cause recurring: several reference catalogs
that later business domains would need to consume (job titles, employment categories, contract
types, qualification types, academic degrees, supervisory titles, leave types, leave statuses) are
already modeled at the persistence layer (S05 built their tables and Eloquent models) but have no
governed way to populate or administer them — no command, no controller, no route. A business
domain that depends on choosing "a leave type" or "a job title" cannot be built responsibly while
there is no governed way to create one. That investigation correctly stopped with **"S13 DOMAIN
BOUNDARY DECISION REQUIRED — NO IMPLEMENTATION"** and made no file changes.

**Decision.** Architecture Authority accepted that no-implementation stop as correct and resolved
the exhausted frontier by reconstructing S13 itself, rather than picking one of the fourteen
blocked candidates:

- S13 is retitled **Reference Catalog Administration Foundation** (تأسيس إدارة القوائم المرجعية),
  classified as Architecture / Dependency Reconstruction, not a historical-title recovery.
- S13's purpose is to close the demonstrated missing-capability gap directly: build the reusable
  administration capability (Create/Activate/Deactivate/UpdateMetadata-equivalent) for the specific
  already-modeled-but-unadministered catalogs the frontier analysis identified, so that a future
  stage choosing one of the fourteen candidate domains no longer has "there is no way to populate
  the reference values it needs" as a blocker.
- S13 explicitly does **not** resolve which of the fourteen candidate domains comes next, and does
  **not** implement any of them (§27, §7 non-goals below). It builds capability only.
- S13 explicitly does **not** invent or seed business content merely to unblock a future domain.
  Two things are authorized separately: **capability** (building the administration mechanism) and
  **business content** (populating actual rows), and the second is restricted to values that either
  already exist in the repository as approved S05/S06 content, or are explicitly supplied by the
  S13 authorization itself as "Known Authoritative Business Vocabulary." No fabricated defaults are
  used anywhere in this stage (§8).

**Consequences.** The fourteen candidate domains from the first S13 investigation remain future
work; none of them is started, resolved, or prioritized by this stage. §27 lists exactly which
catalogs this stage administers, and disclaims that administering a catalog a future domain would
use is not authorization to implement that domain (mirrors the S13 authorization's own "S13 may
administer a catalog used by one of those future domains. That does NOT authorize implementing
that domain.").

## 3. Baseline

Re-verified immediately before this specification was authored and again immediately before
implementation began:

| Check | Value |
|---|---|
| Branch | `develop` |
| `HEAD` | `e9295008096ff98001322319fda6b121b966be0b` |
| `origin/develop` | `e9295008096ff98001322319fda6b121b966be0b` |
| `s12-full-secondment-foundation` tag (dereferenced) | `e9295008096ff98001322319fda6b121b966be0b` |
| `origin/main` | `45259c97ca8dda8101d628bfe20bdb969c807cf4` |
| ahead/behind vs `origin/develop` | 0/0 |
| Untracked | `_to_delete/` only |

Baseline matches the S13 authorization's stated baseline exactly. Not materially different — no
STOP condition triggered here.

## 4. Existing Reference Architecture Inventory

Full inventory performed by reading, not by memory: every file under
`app/Modules/Reference/{Domain,Application,Infrastructure,Presentation}`, the relevant migrations,
`routes/api.php`'s `reference` route group, `ReferencePermissionCatalog`, and both frozen S05/S06
specifications (`reference-data-foundation-specification.md`,
`versioned-behavior-reporting-references-specification.md`).

### 4.1 The existing "simple reference-value family" pattern

Four abstract command base classes exist in `app/Modules/Reference/Application/Commands/`:

- `AbstractCreateSimpleReferenceValue` — inserts `code`/`name_ar`/`name_en`/`display_order`,
  `is_active = true`, `version = 1`; converts a unique-constraint violation on `code` into
  `DuplicateReferenceCodeException`.
- `AbstractActivateSimpleReferenceValue` / `AbstractDeactivateSimpleReferenceValue` — a single
  scoped conditional `UPDATE ... WHERE id = ? AND version = ?` flipping `is_active` and
  incrementing `version`; zero rows affected → re-fetch to distinguish "not found" from "stale" and
  throw `StaleVersionException` in the stale case. No transaction of their own (`AuditedCommandExecutor`
  owns the one effective transaction — ERRATA-02 rationale, same as `ActivateRole`).
- `AbstractUpdateSimpleReferenceValueMetadata` — the same optimistic-concurrency shape, but updates
  `name_ar`/`name_en`/`display_order` only. **`code` is never touched by any of the four abstract
  commands** — codes are immutable once created, exactly as S05 §9 states ("never `code`, never
  `is_active`" for `UpdateMetadata`).

Each abstract class declares `abstract protected function modelClass(): string`. Concrete commands
(`CreateGender`, `ActivateGender`, ... ) are `final` one-line subclasses that hardcode their model
class — never resolved or dispatched by name, confirmed by direct reading of
`AbstractCreateSimpleReferenceValue`'s own docblock: "Every call site still names an explicit,
statically resolvable command class ... Never resolved or dispatched by name." This is exactly the
typed, allowlist-safe shape the S13 authorization's Core Design Requirement demands, already
established since S05 — S13 does not need to invent a new safety mechanism, only extend the
existing one.

Output is a single shared API resource, `SimpleReferenceValueResource` (`id`, `code`, `name_ar`,
`name_en`, `is_active`, `display_order`, `version`, `created_at`, `updated_at`) — "pure display code
with no dynamic behavior."

Six catalogs already use this exact pattern end-to-end (command classes + controller + routes +
tests), confirmed by direct reading of each controller:

| Catalog | Built in | Controller |
|---|---|---|
| `ref.genders` | S05 | `GenderController` |
| `ref.marital_statuses` | S05 | `MaritalStatusController` |
| `ref.decision_types` | S05 | `DecisionTypeController` |
| `ref.employment_status_categories` | S05 | `EmploymentStatusCategoryController` |
| `ref.monthly_cadre_categories` | S06 | `MonthlyCadreCategoryController` |
| `ref.contract_based_population_categories` | S06 | `ContractBasedPopulationCategoryController` |

`routes/api.php` already registers these six through a small, explicit, code-only registry — not a
generic per-request table selector:

```php
$simpleFamilies = [
    'genders' => [GenderController::class, 'gender'],
    'marital-statuses' => [MaritalStatusController::class, 'maritalStatus'],
    'decision-types' => [DecisionTypeController::class, 'decisionType'],
    'employment-status-categories' => [EmploymentStatusCategoryController::class, 'employmentStatusCategory'],
    'monthly-cadre-categories' => [MonthlyCadreCategoryController::class, 'monthlyCadreCategory'],
    'contract-based-population-categories' => [ContractBasedPopulationCategoryController::class, 'contractBasedPopulationCategory'],
];

foreach ($simpleFamilies as $segment => [$controller, $param]) {
    Route::get("/{$segment}", [$controller, 'index'])->middleware('permission:'.RefPerm::REFERENCE_VIEW)-> ...
    // show / store / updateMetadata(PATCH) / activate / deactivate
}
```

This array is evaluated once, at route-registration time, by trusted application code — no request
input ever selects a segment, controller, or table. This is already the "explicit allowlisted
catalog registry" the S13 authorization's Core Design Requirement calls for (§11). **S13 extends
this existing registry rather than building a new one** (§6, §19).

Authorization uses exactly two, already-seeded, module-wide permissions —
`reference.view` / `reference.manage` (`ReferencePermissionCatalog`) — applied identically to every
`$simpleFamilies` entry. No per-catalog permission exists anywhere in the Reference module.

### 4.2 The ten "structure-only, values deferred" families (S05 §5.3)

S05 §5.3, read verbatim: "`ref.employment_types`, `ref.contract_types`, `ref.employment_categories`,
`ref.qualification_types`, `ref.academic_degrees`, `ref.job_titles`, `ref.specialties`,
`ref.supervisory_titles`, `ref.leave_types`, `ref.leave_statuses`. Each gets the common physical
shape from §20 ... and gets an Eloquent model for the same reason. None of them gets a command,
controller, route, seed row, or test in S05 ... This is a deliberate scope decision."

Every one of these ten Eloquent models was read directly in full during this inventory. All ten are
byte-for-byte identical in shape to `Gender`/`DecisionType`/etc.: `#[Fillable(['code', 'name_ar',
'name_en', 'display_order', 'is_active'])]`, string UUIDv7 primary key, the same `creating()` boot
hook defaulting `version = 1` and `display_order = 0`, the same casts. Every one of their migrations
carries the identical docblock: "S05 common simple-reference-value shape (see
docs/reference-data-foundation-specification.md §20) ... DEFINED STRUCTURE / VALUES DEFERRED
(§5.3)," the same `code`/`name_ar`/`name_en`/`is_active`/`display_order`/`version` columns, the same
`version >= 1` CHECK constraint, and a `UNIQUE` constraint on `code`. This confirms, at the
code level and not merely by inference from the authorization's prose, that all ten were built by
S05 anticipating exactly the kind of administration capability S13 now adds — S05 built the
persistence layer ahead of time and explicitly deferred both the command/controller/route layer and
the business content.

`ref.decision_types` is **not** one of these ten — it is one of the six already-"rich" S05 families
(§4.1); S06 §33's own text calls it one of "the seven structure-only S05 families" when listing
catalogs untouched by an S06 mapping, but this is an imprecision in S06's prose (it also miscounts
eight items as "seven") that does not match the code: `DecisionType` already has full
Create/Activate/Deactivate/UpdateMetadata commands and a controller, built in S05, confirmed by
direct reading of `CreateDecisionType.php`, `ActivateDecisionType.php`,
`DeactivateDecisionType.php`, `UpdateDecisionTypeMetadata.php`, and `DecisionTypeController.php` —
all four commands are one-line subclasses of the same four abstract bases used by `Gender`. This
matters for scoping (§6): decision_types needs **zero** new capability from S13.

### 4.3 S06-added temporal mapping catalogs (type C) sitting on top of three of the ten

S06 built versioned/temporal *mapping* catalogs for three of the ten structure-only base catalogs,
without ever building base-row administration for the catalogs they map:

| Mapping catalog | Maps onto base catalog | Command |
|---|---|---|
| `ref.contract_type_population_mappings` | `ref.contract_types` | `DefineContractTypePopulationMappingPeriod` |
| `ref.job_title_administrator_classifications` | `ref.job_titles` | `DefineJobTitleAdministratorClassificationPeriod` |
| `ref.specialty_cadre_category_mappings` | `ref.specialties` | `DefineSpecialtyCadreCategoryMappingPeriod` |

Each is paired with its own `Resolve*AsOf` query and its own explicit routes
(`/contract-types/{contractType}/population-mappings`, etc.), already live in
`routes/api.php` outside the `$simpleFamilies` loop. These are structurally distinct from the
simple-reference-value pattern (temporal, effective-dated, `EXCLUDE`-constrained) and are
**untouched by S13** (§7) — the S13 authorization is explicit that "Versioned mappings/behaviors
must continue using their existing explicit commands and temporal rules." Note that a temporal
*mapping about* a base catalog's rows (e.g., "which population category a contract type maps to as
of a date") is a different concern from *administering the base catalog's own rows* (e.g.,
"create a new contract type"); `ref.contract_types` and `ref.job_titles` have the former already but
still lack the latter, which is exactly the gap S13 closes for those two (§6).

### 4.4 `ref.employment_status_details` / `ref.employment_status_detail_behaviors`

A separate, already-complete S05/S06 pattern: `EmploymentStatusDetailController` has its own
Create/Activate/Deactivate/UpdateMetadata commands (not subclasses of the four simple-family
abstracts, because `store()` additionally requires `category_id`, a real structural difference), plus
a nested `.../behaviors` sub-resource (type E, temporal behavior periods, S06). Fully built,
routed outside `$simpleFamilies`, untouched by S13.

### 4.5 `ref.marital_status_aliases`

A resolution/lookup table (type D) onto `ref.marital_statuses`, with `ArabicLookupNormalizer` and
`ResolveMaritalStatusByArabicSourceValue`. Untouched by S13; the S13 authorization is explicit that
"Existing alias/normalization behavior must not be broadened silently."

### 4.6 Live database state (re-verified directly, this stage)

```
ref.genders = 2                                    (male/female — S05 baseline seed)
ref.marital_statuses = 4                            (S05 baseline seed)
ref.employment_status_categories = 4                (S05 baseline seed)
ref.decision_types = 0                              (structure+commands exist, no rows — S05 §5.2/§22)
ref.employment_categories = 0                       (structure only — S05 §5.3)
ref.contract_types = 0                              (structure only — S05 §5.3)
ref.qualification_types = 0                         (structure only — S05 §5.3)
ref.academic_degrees = 0                            (structure only — S05 §5.3)
ref.job_titles = 0                                  (structure only — S05 §5.3)
ref.supervisory_titles = 0                          (structure only — S05 §5.3)
ref.leave_types = 0                                 (structure only — S05 §5.3)
ref.leave_statuses = 0                              (structure only — S05 §5.3)
ref.employment_types = 2                            (permanent/contract — ADR-S09-001 seed migration)
ref.specialties = 0                                 (structure only — S05 §5.3)
ref.monthly_cadre_categories = 12                   (S06, already administered)
ref.contract_based_population_categories = 2        (S06, already administered)
ref.supervisory_statuses                            DOES NOT EXIST (no migration, no model)
```

`ref.genders` was already seeded by S05's own baseline seed migration
(`2026_09_26_000018_seed_ref_baseline_values`) with exactly `male`/`ذكر`/`Male` and
`female`/`أنثى`/`Female`. This is significant for §8: the S13 authorization's "Known Authoritative
Business Vocabulary" section lists Gender (ذكر/أنثى) as "matches existing catalog exactly" — this
is read as **confirmation that the already-seeded S05 content is correct**, not as an instruction to
insert new rows into an already-seeded table. The same reading applies to Marital status (the
authorization says outright: "unchanged, already-approved S05 model. Do not alter it") and to
Appointment type (دائم/عقد), which is already fully seeded in `ref.employment_types` by an
already-existing, already-disclosed ADR-S09-001 migration
(`2026_09_29_000003_seed_ref_employment_types_permanent_and_contract.php` — its own docblock:
"Seeds exactly the two employment-form values ADR-S09-001 §7 supplies ... No CreateEmploymentType
command exists (S05 shipped no command for any of the ten deferred families), so ... this migration
inserts directly"). None of Gender, Marital Status, or Appointment Type receives any new S13 action.

## 5. Catalog Classification

Every catalog inspected in §4, classified per the S13 authorization's own A–F taxonomy:

| Catalog | Class | Notes |
|---|---|---|
| `ref.genders` | B (already administered) | S05; already seeded; no S13 action |
| `ref.marital_statuses` | A | Governed, S05-approved, "do not alter" per authorization |
| `ref.decision_types` | B (already administered) | S05; zero rows; no S13 action (no approved value supplied) |
| `ref.employment_status_categories` | B (already administered) | S05; already seeded; no S13 action |
| `ref.monthly_cadre_categories` | B (already administered) | S06; no S13 action |
| `ref.contract_based_population_categories` | B (already administered) | S06; no S13 action |
| `ref.employment_status_details` | B-variant (own pattern, extra FK) | S05; already administered; untouched |
| `ref.employment_status_detail_behaviors` | E | S06; temporal behavior; untouched |
| `ref.marital_status_aliases` | D | Alias/resolution; untouched |
| `ref.contract_type_population_mappings` | C | Temporal mapping; untouched |
| `ref.job_title_administrator_classifications` | C | Temporal mapping; untouched |
| `ref.specialty_cadre_category_mappings` | C | Temporal mapping; untouched |
| **`ref.job_titles`** | **B — in scope** | Base catalog for a type-C mapping above, but its own rows are unadministered |
| **`ref.employment_categories`** | **B — in scope** | — |
| **`ref.contract_types`** | **B — in scope** | Base catalog for a type-C mapping above, but its own rows are unadministered |
| **`ref.qualification_types`** | **B — in scope (capability only)** | — |
| **`ref.academic_degrees`** | **B — in scope (capability only)** | — |
| **`ref.supervisory_titles`** | **B — in scope (capability only)** | — |
| **`ref.leave_types`** | **B — in scope (capability only)** | — |
| **`ref.leave_statuses`** | **B — in scope (capability only)** | — |
| `ref.employment_types` | B (not in scope) | Already seeded (ADR-S09-001); not named by S13 authorization; left untouched (§7) |
| `ref.specialties` | B (not in scope) | Base catalog for a type-C mapping above; not named by S13 authorization; left untouched (§7) |
| `ref.supervisory_statuses` | — (does not exist) | Investigated (§9); deferred, not created |

Bolded rows are the S13 in-scope registry (§6).

## 6. In-Scope Catalog Registry

S13 extends the existing `$simpleFamilies` route registry (§4.1) and the existing four abstract
command bases with **eight** new concrete families. `ref.decision_types` needs no new entry (§4.2 —
already fully built). No new abstract base class, no new resource class, and no new permission is
introduced; every new family reuses `AbstractCreateSimpleReferenceValue`,
`AbstractActivateSimpleReferenceValue`, `AbstractDeactivateSimpleReferenceValue`,
`AbstractUpdateSimpleReferenceValueMetadata`, `SimpleReferenceValueResource`,
`DuplicateReferenceCodeException`, `StaleVersionException`, `reference.view`, `reference.manage` —
identically to how `CreateGender` etc. already do.

| # | Table | Model (exists) | New route segment | New controller | Route param |
|---|---|---|---|---|---|
| 1 | `ref.job_titles` | `JobTitle` | `job-titles` | `JobTitleController` | `jobTitle` |
| 2 | `ref.employment_categories` | `EmploymentCategory` | `employment-categories` | `EmploymentCategoryController` | `employmentCategory` |
| 3 | `ref.contract_types` | `ContractType` | `contract-types` | `ContractTypeController` | `contractType` |
| 4 | `ref.qualification_types` | `QualificationType` | `qualification-types` | `QualificationTypeController` | `qualificationType` |
| 5 | `ref.academic_degrees` | `AcademicDegree` | `academic-degrees` | `AcademicDegreeController` | `academicDegree` |
| 6 | `ref.supervisory_titles` | `SupervisoryTitle` | `supervisory-titles` | `SupervisoryTitleController` | `supervisoryTitle` |
| 7 | `ref.leave_types` | `LeaveType` | `leave-types` | `LeaveTypeController` | `leaveType` |
| 8 | `ref.leave_statuses` | `LeaveStatus` | `leave-statuses` | `LeaveStatusController` | `leaveStatus` |

`job-titles` and `contract-types` as new segments coexist with the already-existing, unrelated
sub-resource routes `/job-titles/{jobTitle}/administrator-classifications` and
`/contract-types/{contractType}/population-mappings` (§4.3) — different path shapes, no route
conflict, no change to either sub-resource route.

Each new family gets exactly four new one-line command subclasses (`Create<X>`, `Activate<X>`,
`Deactivate<X>`, `Update<X>Metadata`, each hardcoding `modelClass()`) and one new controller with
`index`/`show`/`store`/`updateMetadata`/`activate`/`deactivate`, structured identically to
`GenderController`/`DecisionTypeController` (§4.1), including the same validation rules, the same
`AuditedCommandExecutor` + `AuditSpec` wiring, and the same audit action naming convention
(`reference.<snake_case_singular>.create` / `.metadata.update` / `.activate` / `.deactivate`,
target type `reference_<snake_case_singular>`) — see §11/§18.

## 7. Out-of-Scope Catalogs

Explicitly excluded, with reasons:

- **`ref.employment_types`** — one of S05's original ten structure-only families, but **not** named
  in the S13 authorization's target-catalog list, and already has business content (permanent/contract,
  seeded by ADR-S09-001) despite having no command layer. Extending administration to it would be
  scope creep beyond what this authorization names. Left untouched. (Noted as a candidate for a
  future stage, not decided here.)
- **`ref.specialties`** — same reasoning: one of the ten, not named by this authorization, zero rows,
  already has a type-C mapping (`specialty_cadre_category_mappings`) but no base-row administration.
  Left untouched.
- **`ref.marital_statuses`, `ref.genders`, `ref.employment_status_categories`,
  `ref.monthly_cadre_categories`, `ref.contract_based_population_categories`** — already fully
  administered (type B, already done); no gap to close; explicitly "do not alter" for marital status.
- **`ref.employment_status_details` / `...behaviors`** — already fully administered/built (own
  pattern); not named; untouched.
- **`ref.marital_status_aliases`** (type D), **the three type-C temporal mapping catalogs** (§4.3) —
  structurally incompatible with the simple-reference-value shape; the authorization is explicit
  these "must continue using their existing explicit commands and temporal rules."
- **`ref.supervisory_statuses`** — does not exist; investigated in §9; insufficient evidence to
  create it; deferred.
- **`ref.decision_types`** — in the named target list, but inspection found it already fully
  administered since S05 (§4.2); zero new capability added; documented here rather than silently
  treated as "already done and therefore omitted," per the authorization's explicit expectation that
  the final report account for every named target catalog.

## 8. Business Content Policy

Per the S13 authorization: **capability is authorized broadly; business content is restricted** to
(a) values that already exist in the repository as approved S05/S06/S09 content (never re-seeded,
never altered), or (b) values explicitly supplied by the S13 authorization's own "Known Authoritative
Business Vocabulary" section, used only where "the corresponding catalog semantics are already
established" and never extrapolated beyond what was given. No fabricated defaults.

Applying that policy vocabulary-item by vocabulary-item:

| Vocabulary item | Target catalog | Disposition |
|---|---|---|
| Gender (ذكر/أنثى) | `ref.genders` | Already seeded by S05 (§4.6). Confirmation only, no S13 action. |
| Marital status | `ref.marital_statuses` | "Do not alter." No S13 action. |
| Appointment type (دائم/عقد) | `ref.employment_types` | Already seeded by ADR-S09-001 (§4.6). No S13 action (catalog also out of scope, §7). |
| Supervisory status (مكلف/مسكن) | `ref.supervisory_statuses` | Catalog does not exist; §9 investigation found insufficient evidence to create it; **values not seeded anywhere** (deferred, §26). |
| Employment grade/category (الأولى/الثانية/الثالثة/الرابعة/الخامسة/العليا/قانون قديم) | `ref.employment_categories` | Catalog is in scope (§6), currently empty, no prior content, no "do not alter" instruction, vocabulary given as a complete, unconditioned 7-value list. **Seeded** — see below. |
| Qualification (بدون + دكتوراة/بورد/مهني/دورة) | `ref.qualification_types` | Catalog is in scope (§6) but the authorization explicitly warns: "Do not seed incomplete qualification values if the exact complete approved list cannot be recovered from repository/frozen MasarHR material." No such confirmation of a complete, ordered ladder exists anywhere in the repository (searched both frozen specs — no match). **Not seeded** (deferred, §26). |
| Job titles | `ref.job_titles` | Authorization explicitly: "Do NOT fabricate or reconstruct an incomplete job-title list. Administration capability may exist while catalog content remains empty." **Not seeded.** |
| Decision types (TRANSFER/SECONDMENT, etc.) | `ref.decision_types` | Authorization explicitly: do not invent unless an approved Arabic value exists; none is supplied. **Not seeded** (and no new capability needed, §4.2). |
| Leave catalogs | `ref.leave_types` / `ref.leave_statuses` | "Do not invent missing values." No values supplied. **Not seeded.** |
| Contract types | `ref.contract_types` | "Do not invent missing values." No values supplied. **Not seeded.** |
| Academic degrees, supervisory titles | `ref.academic_degrees` / `ref.supervisory_titles` | No vocabulary supplied for either. **Not seeded.** |

**Net result: exactly one catalog receives new business content in S13 — `ref.employment_categories`,
seven rows.** Every other in-scope catalog gets capability only, with zero rows, exactly as the
authorization's job-titles instruction states ("administration capability may exist while catalog
content remains empty") applied consistently to all of them.

### 8.1 `ref.employment_categories` seed content

Seeded via a new forward migration (§23), by direct `DB::table()->insert()` — mirroring the
established S05 baseline-seed and ADR-S09-001 employment-types-seed precedent (§4.6) exactly, not
through the new command layer, so the seed does not depend on application bootstrapping inside a
migration:

| `code` | `name_ar` | `name_en` | `display_order` |
|---|---|---|---|
| `grade_1` | الأولى | `null` | 1 |
| `grade_2` | الثانية | `null` | 2 |
| `grade_3` | الثالثة | `null` | 3 |
| `grade_4` | الرابعة | `null` | 4 |
| `grade_5` | الخامسة | `null` | 5 |
| `grade_senior` | العليا | `null` | 6 |
| `grade_old_law` | قانون قديم | `null` | 7 |

`name_ar` is exactly the Arabic text the authorization supplied, verbatim, for all seven rows —
nothing paraphrased or reconstructed. `name_en` is `null` for every row: the authorization supplied
no English gloss for any of the seven values, and S05 §8 established the binding precedent for this
exact situation ("No S05 migration or seed fabricates an English translation that was not explicitly
given in this authorization; where the authorization gave only an Arabic label, `name_en` is seeded
`null` rather than invented") — S13 follows that precedent rather than inventing English labels of
its own. `display_order` mirrors the order the authorization listed the seven values in; per the
Reference module's own established convention, `display_order` is "UI sort guidance only, no
business meaning" (S05 §10), so this is a presentation choice, not a business-rule invention.

`code` values are necessary technical identifiers — every simple reference row requires one
(`code` `NOT NULL`, `UNIQUE`, ASCII `^[a-z0-9_]+$`) — and Arabic text cannot satisfy that constraint
directly. S05's own precedent (`male`/`female` for ذكر/أنثى, `single`/`married`/`divorced`/`widowed`
for أعزب/متزوج/مطلق/أرمل) establishes that choosing a literal, minimally-interpretive ASCII slug for
`code` is an accepted technical necessity, not "inventing a business value" (the protected content is
the Arabic label, not the internal identifier). The five ordinal grades use `grade_1`..`grade_5`,
directly numbering the given sequence with no added interpretation. العليا ("the upper/highest") is
rendered `grade_senior` and قانون قديم ("old law") is rendered `grade_old_law` — the most literal,
direct English-legible slugs available for each, disclosed here in full for Architecture Authority
review; no semantic meaning beyond a direct dictionary reading of the given Arabic text was added to
either.

## 9. Supervisory-Statuses Investigation

The S13 authorization requires this investigation before any decision about
`ref.supervisory_statuses`, and explicitly forbids creating the table merely because the two
approved values (مكلف, مسكن) are known.

**Method.** Full-text search of both frozen reference specifications
(`reference-data-foundation-specification.md`, S05; `versioned-behavior-reporting-references-specification.md`,
S06) for "supervisory" (Arabic and English), plus direct reading of §5 (Reference family catalog,
S05) and §33 (Open Questions, S06).

**Findings.**

- S05 §5.3 names, verbatim, the exact ten structure-only families it deferred:
  `ref.employment_types`, `ref.contract_types`, `ref.employment_categories`,
  `ref.qualification_types`, `ref.academic_degrees`, `ref.job_titles`, `ref.specialties`,
  `ref.supervisory_titles`, `ref.leave_types`, `ref.leave_statuses`. This list includes
  `ref.supervisory_titles` (a job/rank designation) and does not include any "supervisory status"
  concept.
- S06 §33 separately lists "the structure-only S05 families not touched by a mapping" —
  DecisionType, EmploymentType, EmploymentCategory, QualificationType, AcademicDegree,
  SupervisoryTitle, LeaveType, LeaveStatus. Again, `SupervisoryTitle` only; no "supervisory status."
- Neither spec's Open Questions/Deferred Items section (S05 §27, S06 §33) — the sections
  purpose-built to record exactly this kind of "we knew about X but didn't build it" disclosure —
  mentions a supervisory-status concept, an enum, or a deferred catalog under any name.
- `SupervisoryTitle`'s own Eloquent model and migration (read directly, §4.2) carry the identical
  simple `code`/`name_ar`/`name_en`/`display_order`/`is_active`/`version` shape as every other
  structure-only family, with no additional column, enum, or comment suggesting a "status" concept
  was folded into it.
- No other file in the repository (migrations, models, commands, controllers, tests) references a
  supervisory-status concept under any name.

**Conclusion.** "Supervisory title" (رتبة/لقب إشرافي — an already-modeled job/rank designation) and
"supervisory status" (a state such as acting/seconded — the concept named by the S13 authorization's
مكلف/مسكن vocabulary) are evidenced as two distinct concepts. The frozen S05/S06 material shows clear,
deliberate awareness of "supervisory title" (named, modeled, disclosed as deferred) but contains
**zero** evidence — not even a deferred/open-question mention — of "supervisory status" as a
dedicated catalog, an enum, or a concept folded into another existing catalog. This is different
from the ten structure-only families, each of which S05 explicitly named and explicitly disclosed as
intentionally deferred; "supervisory status" was never named at all.

Per the authorization's own explicit instruction ("If architectural evidence is insufficient: defer
creation and explicitly report it. Do not invent additional values"), and because building a table
S05/S06 never modeled at all would be new domain modeling rather than administration of an
already-modeled catalog (the stated purpose of this stage, §2) — **`ref.supervisory_statuses` is not
created in S13.** No migration, no model, no command, no controller, no route, and no seeded values
(مكلف/مسكن are not inserted anywhere) are added for it. This is reported as deferred content (§26)
for a future stage to resolve with its own explicit authorization, exactly as S05 §5.3 and §27
disclosed the ten structure-only families for future population.

## 10. Domain Model

No new Domain-layer concept is introduced. All eight in-scope catalogs (§6) reuse the existing
`Reference` module's simple-reference-value domain shape (§4.1) exactly: a `code`-identified,
Arabic/English-labelled, activatable/deactivatable, optimistically-concurrent, non-deletable value.
`DuplicateReferenceCodeException` and `StaleVersionException` (both already exist in
`App\Modules\Reference\Domain\Exceptions`) are reused unchanged.

## 11. Lifecycle

Identical to the existing six families (S05 §9), reused unchanged: `Create` → active by default;
`UpdateMetadata` → `name_ar`/`name_en`/`display_order` only, never `code`, never `is_active`;
`Activate`/`Deactivate` → `is_active` flip only. No hard delete route or command exists for any of
the eight new families, mirroring "no reference value is ever hard-deleted through any S05-exposed
route or command" (S05 §25 invariant, unchanged by S13). A deactivated value remains fully readable;
`index`/`show` do not filter by `is_active` (API consumers filter themselves, per existing
convention).

## 12. Command Model

Per family (×8 — job_titles, employment_categories, contract_types, qualification_types,
academic_degrees, supervisory_titles, leave_types, leave_statuses):

```php
final class Create<X> extends AbstractCreateSimpleReferenceValue
{
    protected function modelClass(): string { return <X>::class; }
}
// Activate<X>, Deactivate<X>, Update<X>Metadata — identical shape, different abstract base
```

No new abstract base class. No `RenameReferenceValue`-named command is introduced separately —
"rename" is already covered by the existing `UpdateMetadata` shape (label fields only, `code`
immutable), matching the authorization's own qualifier: "But do not force these commands onto
catalogs whose existing architecture requires different operations" — the existing operation names
(`Create`/`Activate`/`Deactivate`/`UpdateMetadata`) are kept exactly as S05 named them rather than
introduced as new, differently-named `RenameReferenceValue` commands, since renaming is already what
`UpdateMetadata` does.

## 13. Query Model

`index()` — `<Model>::query()->orderBy('display_order')->orderBy('code')->paginate(50)`, identical to
every existing simple family. `show()` — route-model-bound single fetch. No new query class; no
`Resolve*AsOf` equivalent is needed (these eight catalogs are not temporal, unlike the type-C
mapping catalogs in §4.3).

## 14. Validation

Identical, per family, to `GenderController`/`DecisionTypeController` (§4.1):

- `store`: `code` required, string, max 64, `regex:/^[a-z0-9_]+$/`; `name_ar` required, string, max
  255; `name_en` nullable, string, max 255; `display_order` nullable, integer, 0–32767.
- `updateMetadata`: `name_ar` required (as above); `name_en` nullable (as above); `display_order`
  nullable (as above); `expected_version` required, integer, min 1.
- `activate` / `deactivate`: `expected_version` required, integer, min 1.

## 15. Uniqueness

Each new table's `code` column carries a `UNIQUE` constraint already (built by S05's migrations,
§4.2) — `Errors::isUniqueViolation()` classification and `DuplicateReferenceCodeException` are
reused unchanged from `AbstractCreateSimpleReferenceValue`. No new uniqueness rule is introduced.

## 16. Concurrency

Each new table's `version` column already carries a `version >= 1` CHECK constraint (S05
migrations). `Activate`/`Deactivate`/`UpdateMetadata` use the existing scoped conditional-`UPDATE`
optimistic-concurrency pattern (`WHERE id = ? AND version = ?`) — zero rows affected re-fetches to
distinguish "not found" (404) from "stale" (`StaleVersionException` → 409), identical to every
existing simple family. No row locking is introduced or needed (no multi-statement invariant to
protect beyond the single scoped `UPDATE`, exactly as the existing six families require none).

## 17. Database

No new migration creates a table — all eight target tables already exist (S05). Exactly one new
migration is added: the `ref.employment_categories` seed (§8.1, §23). No existing migration is
edited. No schema change of any kind to any of the eight tables (columns, constraints, indexes all
already match the shape the new commands need, confirmed by direct reading in §4.2).

## 18. Authorization

Every new route uses the existing `permission:reference.view` (reads) / `permission:reference.manage`
(writes) middleware — no new permission is created, per the authorization's explicit "do not create
unnecessary per-catalog permissions unless existing frozen architecture requires them," and nothing
here requires one (all eight catalogs are global, not organization-scoped, exactly like the existing
six families — no `ScopedAuthorizationChecker` involvement, consistent with "no organization scope is
required merely to administer global reference catalogs"). Default deny (no `permission:` middleware
match → 403) is unchanged framework behavior.

## 19. Audit

Every mutation runs through the existing `AuditedCommandExecutor` + `AuditSpec`, identical wiring to
`GenderController`/`DecisionTypeController` (§4.1) — the mutation and its `MUTATION` audit entry
commit or roll back together in the one transaction `AuditedCommandExecutor` owns. Per family:

| Action | Audit action code | Target type |
|---|---|---|
| Create | `reference.job_title.create` (etc., per family — snake_case singular) | `reference_job_title` (etc.) |
| UpdateMetadata | `reference.job_title.metadata.update` | `reference_job_title` |
| Activate | `reference.job_title.activate` | `reference_job_title` |
| Deactivate | `reference.job_title.deactivate` | `reference_job_title` |

`changes` payloads mirror `GenderController` exactly: `create` records `code`/`name_ar`/`name_en`/
`display_order`; `metadata.update` records each field as `{from, to}`; `activate`/`deactivate` record
`is_active: {from, to}` only. No arbitrary request payload is ever put into audit metadata (`metadata:
fn () => []` throughout, matching every existing family) — satisfying the authorization's explicit
"Use allowlisted metadata. Do not put arbitrary request payloads into audit metadata. No
PII/secrets" (none of these catalogs ever contain PII).

## 20. Error Semantics

Unchanged, reused from the existing pattern: 404 when the route-bound model does not exist; 403 when
`permission:` middleware denies; 422 on validation failure; 409 (`StaleVersionException`) on an
optimistic-concurrency conflict; 201 on successful create; 200 on successful read/update/
activate/deactivate. Unknown catalog segment (a URL that doesn't match any registered
`$simpleFamilies`/explicit route) falls through to Laravel's standard 404 — there is no
"resolve a catalog by client-supplied name" code path anywhere to return a different error from
(§4.1 — the registry is compile-time, not a runtime lookup keyed by request input).

## 21. Historical Resolution

No new historical/as-of resolution behavior — none of the eight new catalogs is temporal (§13).
Where a future domain stores a foreign key to one of these catalogs' rows, deactivating the
referenced row does not retroactively change that historical fact — deactivation only prevents the
value from being *newly selected* by a future write, exactly as S05 §9's existing invariant already
guarantees for every simple reference family. S13 adds no new mechanism here; it relies on the
already-frozen invariant.

## 22. Inactive Value Semantics

Unchanged from §11/§20: inactive values remain fully readable via `index`/`show` (no default
filtering); a future domain's own write-side validation is responsible for excluding inactive values
from new selections (out of S13's scope — no such domain exists yet, §7 non-goals); no route or
command ever silently reactivates a value (`Activate` is always an explicit, individually-authorized,
audited call).

## 23. Migration Strategy

Exactly one new forward migration:

```
2026_10_03_000001_seed_ref_employment_categories_grades.php
```

(next available timestamp after the current latest migration,
`2026_10_02_000002_seed_security_full_secondment_period_permissions.php`). Direct `DB::table()->insert()`
of the seven rows in §8.1, `down()` deletes the same seven `code`s — mirroring
`2026_09_26_000018_seed_ref_baseline_values` and
`2026_09_29_000003_seed_ref_employment_types_permanent_and_contract` exactly. No existing migration
is edited. Rollback/reapply is required and tested (§24).

## 24. Tests (required, per the S13 authorization's list)

For each of the eight new families, and once for the shared registry/cross-cutting behavior:

- Catalog registry allowlist: the eight new segments are reachable; an arbitrary/unknown segment
  (e.g. `/reference/not-a-real-catalog`) 404s.
- Read authorization: `reference.view` required for `index`/`show`; denied without it (403).
- Write authorization: `reference.manage` required for `store`/`updateMetadata`/`activate`/
  `deactivate`; denied without it (403).
- Create: valid payload creates an active, `version = 1` row (201); duplicate `code` → 409/422 per
  `DuplicateReferenceCodeException` handling (matches existing family's exact status code).
- Rename/metadata update: `name_ar`/`name_en`/`display_order` change; `code` and `is_active` are
  never affected by `updateMetadata` even if supplied in the request body (mirrors S05 §9's explicit
  "never `code`, never `is_active`").
- Activate / Deactivate: flips `is_active`, increments `version`.
- Duplicate prevention: creating a second row with an existing `code` fails.
- Stale-version conflict: `updateMetadata`/`activate`/`deactivate` with a stale `expected_version` →
  409 `StaleVersionException`.
- Inactive value remains readable via `index`/`show` after deactivation.
- No hard-delete route exists (route-list assertion, mirroring existing family tests).
- Audit entries: exactly one `MUTATION` audit row per mutation, correct `action`/`target_type`/
  `changes` shape, no extraneous fields.
- API validation: each invalid-payload case (missing required field, bad `code` format, oversized
  string) → 422.
- Arbitrary-table-injection attempt: a request that tries to smuggle a table/model name (e.g. as a
  route segment or payload field) is proven to have no effect — the registry is compile-time, so
  this is a structural, not runtime, guarantee; the test documents that guarantee rather than
  attempting to bypass a runtime check that does not exist.
- Cross-catalog ID misuse: a valid UUID belonging to a *different* catalog's row (e.g. a `Gender` id
  passed to `/reference/leave-types/{leaveType}`) resolves to 404 (route-model binding scopes to the
  bound table).
- Migration rollback/reapply: the new seed migration's `down()` then `up()` again reproduces exactly
  the seven §8.1 rows.
- Existing S05/S06 reference regression: the full existing Reference-module test suite (all six
  already-built families, `employment-status-details`, behaviors, mappings, aliases) still passes
  unmodified.
- Full S01–S13 regression: the entire existing suite passes.

Exact test/assertion counts are reported in the S13 Final Report (§28 authorization requirement),
after the tests are written and run (§25).

## 25. Acceptance Criteria

- Every item in §24 implemented and passing.
- `ref.decision_types` confirmed to need no new file (§4.2) — documented, not silently skipped.
- `ref.employment_categories` seeded with exactly the seven §8.1 rows, `name_en = null` throughout,
  no other table seeded with any new row.
- `ref.supervisory_statuses` not created; §9's investigation and conclusion appear in the Final
  Report verbatim.
- No generic `POST /reference/{table}` / `PATCH /reference/{table}/{id}` exists anywhere in
  `routes/api.php`; every route is an explicit, named segment bound to an explicit, named controller.
- Pint clean; `git diff --check` (or equivalent whitespace check) clean.
- Full S01–S13 regression passes with an exact count.
- Adversarial review (§28) has zero unresolved BLOCKING/MODERATE findings.
- `_to_delete/` untouched; no file outside the Reference module's expected surface (commands,
  controllers, one migration, `routes/api.php`, tests, this specification) is modified.

## 26. Deferred Business Content

Explicitly not seeded in S13, with the authorization-supplied vocabulary noted for a future stage:

- **Supervisory status (مكلف / مسكن)** — `ref.supervisory_statuses` does not exist; §9 found
  insufficient S05/S06 evidence to create it; a future stage with its own explicit authorization may
  revisit this with either stronger evidence for a dedicated catalog or an explicit decision to fold
  it into an existing one.
- **Qualification vocabulary (بدون, دكتوراة, بورد, مهني, دورة)** — `ref.qualification_types` gets
  administration capability in S13 but zero seeded rows; the authorization's own explicit warning
  against seeding an incomplete list, and the absence of any repository confirmation of a complete,
  ordered ladder, means this is left for a future stage once the complete approved list is
  confirmable.
- **Job titles, academic degrees, supervisory titles, leave types, leave statuses, contract types,
  decision types** — administration capability only; zero business content in any of them; no
  authoritative value list was supplied by this authorization or found in the repository for any of
  them.
- **`ref.employment_types`, `ref.specialties`** — out of S13's named scope entirely (§7); not
  administered, not seeded.

## 27. S14+ Boundary

S13 builds administration capability only for the catalogs in §6. It does **not** implement, and
this specification makes no design decision toward: Transfer, Work Schedule, Partial Secondment, Job
History, Professional History, Employment Category History, Contract Lifecycle, Qualification
Assignment to a Person, Supervisory Assignment, Leave transactions, Reporting, Import, Data Quality
workflow, Alerts, or S14 generally. That a future domain (e.g., a future Contract Lifecycle or Leave
stage) will eventually consume one of the catalogs S13 now makes administrable does not authorize,
imply, or pre-decide that domain's design — matching the authorization's explicit "S13 may administer
a catalog used by one of those future domains. That does NOT authorize implementing that domain."
The fourteen candidate domains from the first S13 investigation remain exactly as blocked/undecided
as that investigation left them, except that "no way to populate the reference values this domain
needs" is no longer true for the eight catalogs in §6.

## 28. Adversarial Checklist (pre-implementation self-check; independent review in §"Adversarial Review" of the implementation report)

- [ ] No arbitrary-table API: confirmed by construction (§4.1, §6) — every route is an explicit
      `$simpleFamilies` entry or an explicit named route; no controller ever receives a table/model
      name from the request.
- [ ] No SQL identifier from user input: confirmed — `modelClass()` is a hardcoded string literal in
      each final command subclass, never derived from request data.
- [ ] No invented business values: confirmed by §8's item-by-item accounting; the only seeded content
      (§8.1) traces directly to authorization-supplied, unmodified Arabic text.
- [ ] No hard delete: confirmed — no `destroy`/`delete` method or route added anywhere.
- [ ] No historical-reference breakage: confirmed — no existing row, FK, or table is altered.
- [ ] No modification of temporal mapping semantics: confirmed — the three type-C catalogs (§4.3) and
      the type-E behavior catalog (§4.4) are not touched by any S13 file.
- [ ] No weakening of S05/S06 behavior: confirmed — every existing file this stage reuses
      (abstracts, resource, exceptions, permissions) is read but not modified.
- [ ] No generic mass assignment: confirmed — each `store`/`updateMetadata` validates an explicit,
      fixed field list (§14), identical to the existing families.
- [ ] Proper optimistic concurrency: confirmed — `expected_version` required on every mutating
      non-create route (§16).
- [ ] Backend authorization: confirmed — `permission:` middleware on every new route (§18).
- [ ] Audited writes: confirmed — every mutation routes through `AuditedCommandExecutor` (§19).
- [ ] No S14 leakage: confirmed — §27; no file touches Person/Employment/Organization or any of the
      fourteen non-goal domains.

This checklist is re-verified against the actual implementation (not just this design) before the
git acceptance gate, and independently re-attempted by an adversarial reviewer per the authorization's
own "Adversarial Review" requirement.
