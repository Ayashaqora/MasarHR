# Reference Data Foundation — Specification

> **Amended by S05 CORRECTIVE-01** (marital-status source-value aliasing — see §22a). This
> corrective was applied to the local, not-yet-pushed S05 commit before first publication; it is
> not a separate follow-up stage. See §5.1, §20, §22a, §23, §24 for the affected sections.

## 1. Purpose

S05 establishes the physical and application-level infrastructure for MasarHR's controlled
reference vocabularies (the `ref` PostgreSQL schema created empty by S02): named, coded lookup
values such as Gender and Marital Status, plus one temporal behavior-mapping contract
(`ref.employment_status_detail_behaviors`) that lets future application code ask "what does
employment-status-detail X mean on date D?" without parsing Arabic labels. S05 builds the
reference *vocabulary* infrastructure only. It does not build Person, Employee, Employment,
Organization, or any table that stores a fact *about* an individual. Those are later, separately
authorized stages that will hold foreign keys into the tables this stage creates.

## 2. Scope

In scope: PostgreSQL tables in the `ref` schema for the reference families listed in §5; Eloquent
models for all of them; explicitly-named application commands, controllers, routes, and
JsonResource output for the six "rich" families identified in §5.2; a new
`App\Modules\Reference\...` module mirroring the existing `Platform`/`Security`/`Audit` module
shape; two new `security.permissions` rows (`reference.view`, `reference.manage`) added through a
new S05-owned migration; full integration with the existing S04 audit infrastructure
(`CommandContext` / `AuditedCommandExecutor` / `AuditAppendService`) for every mutation; a
deterministic seed/bootstrap migration for the concrete values given directly in this
authorization (Gender, Marital Status, Employment Status Category/Detail, and the initial
Employment Status Detail Behavior periods); and a test suite covering the new lifecycle,
concurrency, temporal-overlap, security, and audit behavior.

Out of scope is listed exhaustively in §26.

## 3. Baseline / S02–S04 integration context

- S02 created the `ref` schema (empty, by design) and enabled the PostgreSQL `btree_gist`
  extension, which S05 depends on for the temporal exclusion constraint in §12.
- S03 established the Security module's permission/role/principal model. S05 adds new permission
  rows through a new migration only; it does not alter any S03 migration or model.
- S04 established the only audited-mutation execution path in the system
  (`AuditedCommandExecutor::run()` wrapping a command in the one effective transaction, then
  appending a `MUTATION` audit entry via `AuditAppendService`) and the only way to record a
  rejected/denied action (`AuditSecurityEventRecorder` → `SECURITY_EVENT`). S05 introduces no
  parallel audit or transaction mechanism; every S05 mutation is invoked exactly the way
  `RoleController` invokes Security-module commands (§14/§17 below reproduce the exact pattern).
- Repository discovery for this stage (read-only, prior to any S05 file being created) confirmed:
  the `ref` schema contains zero tables; no `docs/*.md` file contains an authoritative reference
  family/value catalog; there is no prior "reference families 01–19" list anywhere in committed
  history. This is addressed directly in §5.1.

## 4. Architecture decisions D1–D5

- **D1 — No EAV / polymorphic reference table.** A single `ref.reference_values(type, code,
  value)` table was considered and rejected per the authorization's explicit instruction (§9):
  each family has its own typed table so PostgreSQL-level constraints (uniqueness, foreign keys,
  the temporal exclusion constraint) apply per family rather than being simulated in application
  code.
- **D2 — Deactivation, never deletion.** No S05 route or command ever issues a hard `DELETE`
  against a reference-value row. A value that stops being valid going forward is deactivated
  (`is_active = false`); rows already referenced historically remain readable and unchanged.
- **D3 — Shared lifecycle via an abstract command base, disclosed.** Gender, Marital Status,
  Decision Type, and Employment Status Category share an identical four-operation lifecycle
  (Create / UpdateMetadata / Activate / Deactivate) over an identical column shape (`code`,
  `name_ar`, `name_en`, `display_order`). Rather than hand-duplicating four near-identical classes
  four times, S05 introduces one abstract base per operation
  (`AbstractCreateSimpleReferenceValue`, `AbstractUpdateSimpleReferenceValueMetadata`,
  `AbstractActivateSimpleReferenceValue`, `AbstractDeactivateSimpleReferenceValue`), each with a
  single abstract `modelClass(): string` hook, and one named concrete subclass per family (e.g.
  `CreateGender extends AbstractCreateSimpleReferenceValue`). This is disclosed here because the
  authorization (§18) is explicit that a generic Command Bus, generic CRUD service, or generic
  repository is forbidden; the base classes below are not that — every command a controller or
  test depends on is still a distinct, explicitly named, statically resolvable class
  (`CreateGender`, `ActivateMaritalStatus`, …) with its own constructor-injectable type, and no
  call site ever names a command by string or resolves one dynamically. The base class only
  factors out the identical *body*, the same way `PostgresErrorClassifier` factors out SQLSTATE
  parsing without becoming a "generic error service." Employment Status Detail does not extend the
  create base (its create signature additionally requires `category_id`), but does reuse the
  Update/Activate/Deactivate bases, since those operations do not touch `category_id`.
- **D4 — Employment Status Detail Behavior is append-only history, not a mutable row.** §12
  describes this in full: a behavior period is *defined*, never edited or deactivated; a change in
  behavior is expressed by defining a new period with a later `effective_from`.
- **D5 — Ten families are DEFINED STRUCTURE / VALUES DEFERRED.** See §5.3.

## 5. Reference family catalog

### 5.1 Why this is 16 tables, not a reconstructed "01–19" list

The authorization's own text references an external "01–19" family numbering and external analysis
documents. Repository discovery found no trace of either in `docs/`, in git history, or anywhere
else in this repository. Per §8 of the authorization ("If the repository does NOT contain enough
authoritative evidence to reconstruct an exact reference family/value, DO NOT INVENT IT"), that
external numbering is not reconstructed or guessed at here. What *is* used as authoritative content
is the concrete, explicit business content given directly, in this repository's authorization
message, by the Architecture Authority: Gender, Marital Status, Employment Status (Category +
Detail + Behavior), and Decision Type (structure only — see §5.3). Ten additional named-but-
value-less categories were also named directly in the authorization (employment type, contract
type, employment category, qualification type, academic degree, job title, specialty, supervisory
title, leave type, leave status); their *existence as a future FK target* is treated as authorized
content, but no specific row of any of them is invented, per §5.3.

**CORRECTIVE-01 addendum:** a 17th table, `ref.marital_status_aliases`, was added on top of this
16-table catalog (§22a). It is not a 17th reference-*family* — it introduces no new business
concept and is not counted in "16 tables, not 19 families" reasoning above — it is a lookup/mapping
table onto the existing `marital_statuses` family, in the same structural category as
`employment_status_detail_behaviors` (§3 of the verification report that required this
correction): a supporting table, not an independent numbered family.

### 5.2 Six "rich" families — full command/controller/audit/API/test depth

| Table | Purpose |
|---|---|
| `ref.genders` | Gender reference values. |
| `ref.marital_statuses` | Marital status reference values. |
| `ref.decision_types` | Administrative decision type reference values (structure seeded; no rows — §5.3/§22). |
| `ref.employment_status_categories` | The 4-tier grouping from §5.4. |
| `ref.employment_status_details` | Concrete employment status values (e.g. "on duty", "resigned"), each belonging to one category. |
| `ref.employment_status_detail_behaviors` | Temporal, effective-dated behavior contract per status detail (§12). |

### 5.3 Ten "structure-only, values deferred" families — migration + Eloquent model only

`ref.employment_types`, `ref.contract_types`, `ref.employment_categories`,
`ref.qualification_types`, `ref.academic_degrees`, `ref.job_titles`, `ref.specialties`,
`ref.supervisory_titles`, `ref.leave_types`, `ref.leave_statuses`.

Each gets the common physical shape from §20, so a later stage can add a foreign key into it
immediately, and gets an Eloquent model for the same reason. None of them gets a command,
controller, route, seed row, or test in S05: no authoritative value list exists for any of them,
and exposing empty CRUD surface area for ten tables nobody can populate correctly yet would
violate §20's "expose only the minimum necessary" instruction more than deferring them would. This
is a deliberate scope decision, disclosed here and in the final report, not an oversight.

### 5.4 Employment status: reconciling §4G and §5 of the authorization

The authorization's §4G describes a 3-tier employment-status grouping; §5 describes a more
detailed breakdown that separates a fourth, stronger tier (شهيد/وفاة) out of the "ended" group.
Read together, §5's 4-tier model is the more specific and more recent statement and is followed
here as authoritative; §4G's 3-tier summary is treated as a looser gloss of the same model, not a
conflicting instruction. The four categories seeded into `ref.employment_status_categories` are:

1. **ACTIVE** — participates in the active workforce.
2. **NON_ACTIVE** — an ongoing relationship that is not currently active service (e.g. secondment,
   unpaid leave), reappointment not meaningful because the relationship never ended.
3. **ENDED** — the relationship has ended in a way that may allow reappointment (e.g. resignation,
   retirement, contract completion).
4. **TERMINAL** — the relationship has ended in a way that is never reversible (شهيد/وفاة only).

## 6. Stable identifier strategy

Every `ref` table's primary key is `id uuid`, generated application-side with `Str::uuid7()` at
creation time (identical convention to `security.roles`/`security.principals`). `id` is never
exposed as meaningful and is never guessed at by client code; application and future-domain code
must address a reference value by its stable `code` (§7), not by `id`, wherever a code is available
(e.g. in a specification or migration); `id` is the FK-join key once a value is persisted.

## 7. Code strategy

`code` is a short, stable, machine-addressed identifier (`string(64)`, unique per table, pattern
`^[a-z0-9_]+$`), set once at creation and never changed by any S05 command (there is no
"rename code" operation). Application logic branches on `code`, never on `name_ar`/`name_en`, so
relabeling a value's display text can never silently change behavior. For
`ref.employment_status_details`, `code` values are stable snake_case English transliterations
(e.g. `on_duty`, `resigned`, `deceased`), not the Arabic label and not a numeric legacy code that
would itself need reconstruction.

## 8. Arabic/English label strategy

Every simple reference table has `name_ar` (`string`, required) and `name_en` (`string`,
nullable). No S05 migration or seed fabricates an English translation that was not explicitly
given in this authorization; where the authorization gave only an Arabic label, `name_en` is
seeded `null` rather than invented, consistent with §8's "DEFINED STRUCTURE / VALUES DEFERRED"
principle applied at the field level.

## 9. Activation/deactivation lifecycle

Every simple reference value has `is_active boolean not null default true`. The only lifecycle
operations are `Create`, `UpdateMetadata` (label/display-order fields only — never `code`, never
`is_active`), `Activate`, `Deactivate` — mirroring `security.roles` exactly. No route or command
performs a hard delete. A deactivated value remains fully readable (list/show endpoints do not
filter it out by default; API consumers filter on `is_active` themselves), and any row that already
references it (a future stage's FK) is unaffected.

## 10. Sort/display ordering

Every simple reference table has `display_order smallint not null default 0` (a new convention;
no existing table in the repository has a comparable column, confirmed during discovery). It is
UI-facing sort guidance only, carries no business meaning, is settable only through
`UpdateMetadata`, and is not unique (ties broken by `code` ascending at the query layer).

## 11. Employment status behavior model

`ref.employment_status_detail_behaviors` answers, for a given `status_detail_id` and effective
date, five boolean/nullable questions without any code ever parsing an Arabic label:

- `participates_in_active_workforce` (bool)
- `is_ongoing_relationship` (bool)
- `is_relationship_ending` (bool)
- `is_terminal` (bool)
- `allows_reappointment` (nullable bool — `true` for resignation/retirement/contract-end-type
  endings, `false` for شهيد/وفاة, `null` where reappointment is not a meaningful question, i.e. for
  ACTIVE/NON_ACTIVE details)
- `counts_in_monthly_reporting` (nullable bool — deliberately seeded `null` for every S05 row; no
  per-status reporting-inclusion rule was given by the authorization, so none is invented here.
  This is a disclosed, deferred item — see §27.)

## 12. Temporal mapping rules

`ref.employment_status_detail_behaviors` is effective-dated history, not a mutable settings row:

- `effective_from date not null`, `effective_to date nullable` — half-open interval `[from, to)`;
  `effective_to = null` means "still in effect."
- A PostgreSQL `EXCLUDE` constraint (via the `btree_gist` extension already enabled by S02) on
  `(status_detail_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)` makes
  overlapping periods for the same status detail physically impossible, independent of any
  application-level check.
- There is exactly one command, `DefineEmploymentStatusDetailBehaviorPeriod`: it inserts a new
  period row. It never updates or deletes an existing period. A "correction" is expressed as
  defining a new period; the exclusion constraint is what actually enforces that periods for one
  status detail never overlap, and a `23P01` violation (`PostgresErrorClassifier::
  isExclusionViolation()`) is translated into a 409-style domain rejection at the controller.
- "Current" behavior for a detail on date D is `WHERE status_detail_id = ? AND effective_from <= D
  AND (effective_to IS NULL OR effective_to > D)`.

## 13. Reporting-mapping foundation

S05 does not build a reporting engine (§26). `counts_in_monthly_reporting` on the behavior table
(§11) is the entire reporting-mapping foundation this stage lays: a nullable boolean a future
reporting stage can read instead of re-deriving "does this status count" from Arabic text. Every
S05-seeded row leaves it `null` (undetermined), which a future stage must resolve; S05 does not
guess at it.

## 14. Mutation commands

All commands live in `App\Modules\Reference\Application\Commands`. Every one follows the exact
optimistic-concurrency shape already used throughout Security (`Role`/`Principal`): a single scoped
`UPDATE ... WHERE id = ? AND version = ?`, zero-rows-updated distinguished from not-found via a
`firstOrFail()` fallback, `StaleVersionException` otherwise — reusing that exact pattern (a new
`App\Modules\Reference\Domain\Exceptions\StaleVersionException`, identical in shape to Security's,
since S05 is an independent module and must not import Security's domain exception). Per D3, four
of these are abstract bases with one named subclass per simple family; commands with a different
shape (`CreateEmploymentStatusDetail`, `DefineEmploymentStatusDetailBehaviorPeriod`) are concrete,
standalone classes. No command opens its own `DB::transaction()` — exactly ERRATA-02's rationale:
`AuditedCommandExecutor` already owns the one effective transaction.

## 15. Query/read contracts

Each rich family gets `index` (paginated, `orderBy('display_order')->orderBy('code')`, 50/page,
matching `RoleController::index()`) and `show`. Reads are not audited (S04 §9 — only mutations and
explicit security events are audited); reads require only `reference.view`.

## 16. Authorization

Two new permission codes are added, via one new S05-owned migration that never edits any S03
migration, following the exact S03 precedent (`security.permissions` seeded by migration, never by
a seeder that might not run):

- `reference.view` — list/show any `ref` table exposed by S05.
- `reference.manage` — create/update-metadata/activate/deactivate/define-period on any `ref` table
  exposed by S05.

A single pair of codes, not one pair per family, was chosen: the authorization's own scope-
minimization instruction (§20) and the fact that all six rich families are equally low-risk
lookup data (unlike, say, principal status) make a granular per-family permission matrix
unjustified complexity for this stage; a future stage can split them if a real need appears.

## 17. Audit contracts

Every mutation is invoked through `AuditedCommandExecutor::run()` exactly as `RoleController`
invokes Security commands (§17 pattern, reproduced here as the binding contract). Stable action
codes (dotted, `reference.<entity>.<verb>` per §17 of the authorization):

| Action code | Target type | Command |
|---|---|---|
| `reference.gender.create` | `reference_gender` | `CreateGender` |
| `reference.gender.metadata.update` | `reference_gender` | `UpdateGenderMetadata` |
| `reference.gender.activate` | `reference_gender` | `ActivateGender` |
| `reference.gender.deactivate` | `reference_gender` | `DeactivateGender` |
| `reference.marital_status.create` | `reference_marital_status` | `CreateMaritalStatus` |
| `reference.marital_status.metadata.update` | `reference_marital_status` | `UpdateMaritalStatusMetadata` |
| `reference.marital_status.activate` | `reference_marital_status` | `ActivateMaritalStatus` |
| `reference.marital_status.deactivate` | `reference_marital_status` | `DeactivateMaritalStatus` |
| `reference.decision_type.create` | `reference_decision_type` | `CreateDecisionType` |
| `reference.decision_type.metadata.update` | `reference_decision_type` | `UpdateDecisionTypeMetadata` |
| `reference.decision_type.activate` | `reference_decision_type` | `ActivateDecisionType` |
| `reference.decision_type.deactivate` | `reference_decision_type` | `DeactivateDecisionType` |
| `reference.employment_status_category.create` | `reference_employment_status_category` | `CreateEmploymentStatusCategory` |
| `reference.employment_status_category.metadata.update` | `reference_employment_status_category` | `UpdateEmploymentStatusCategoryMetadata` |
| `reference.employment_status_category.activate` | `reference_employment_status_category` | `ActivateEmploymentStatusCategory` |
| `reference.employment_status_category.deactivate` | `reference_employment_status_category` | `DeactivateEmploymentStatusCategory` |
| `reference.employment_status_detail.create` | `reference_employment_status_detail` | `CreateEmploymentStatusDetail` |
| `reference.employment_status_detail.metadata.update` | `reference_employment_status_detail` | `UpdateEmploymentStatusDetailMetadata` |
| `reference.employment_status_detail.activate` | `reference_employment_status_detail` | `ActivateEmploymentStatusDetail` |
| `reference.employment_status_detail.deactivate` | `reference_employment_status_detail` | `DeactivateEmploymentStatusDetail` |
| `reference.employment_status_detail_behavior.period.define` | `reference_employment_status_detail_behavior` | `DefineEmploymentStatusDetailBehaviorPeriod` |

`changes` allowlists mirror `RoleController`'s pattern exactly: create → the display fields set;
metadata update → `{from, to}` pairs for each changed display field; activate/deactivate →
`{is_active: {from, to}}`; period-define → `{effective_from, effective_to, ...behavior booleans}`
(no `from`, since it is a new row, not a transition).

## 18. Concurrency

Every simple reference value carries `version integer not null default 1` with the same
`roles_version_check`-style `CHECK (version >= 1)` constraint, and every mutating command (other
than Create and the append-only period-define) requires the caller's `expected_version` and uses
the same conditional-UPDATE pattern described in §14.
`ref.employment_status_detail_behaviors` rows are immutable once inserted (§12) and carry no
`version` column — there is nothing to optimistically update.

## 19. Validation

Standard Laravel `Request::validate()` at the controller, matching Security's conventions:
`code` — `required|string|max:64|regex:/^[a-z0-9_]+$/` on create only; `name_ar` —
`required|string|max:255`; `name_en` — `nullable|string|max:255`; `display_order` —
`nullable|integer|min:0|max:32767`; `expected_version` — `required|integer|min:1` on every
version-checked mutation. `CreateEmploymentStatusDetail` additionally validates `category_id` as
`required|uuid|exists:ref.employment_status_categories,id` — expressed as an `exists:` rule with an
explicit connection-qualified table string is avoided per the exact reasoning already documented in
`RoleController::grantPermission()` (a dotted schema.table string is misparsed as
"connection.table"); instead the category is loaded with `findOrFail()` in the controller, exactly
as `RoleController::grantPermission()` loads `Permission` before invoking the command.
`DefineEmploymentStatusDetailBehaviorPeriod` validates `effective_from` —
`required|date_format:Y-m-d`; `effective_to` — `nullable|date_format:Y-m-d|after:effective_from`;
the five behavior fields — `required|boolean` for the first four, `nullable|boolean` for
`allows_reappointment` and `counts_in_monthly_reporting`.

## 20. PostgreSQL physical design

Common shape for every simple reference table (16 tables, including the 10 structure-only ones):

```
id            uuid primary key
code          varchar(64) not null,  unique
name_ar       varchar(255) not null
name_en       varchar(255) null
is_active     boolean not null default true
display_order smallint not null default 0
version       integer not null default 1,  check (version >= 1)
created_at    timestamptz not null
updated_at    timestamptz not null
```

`ref.employment_status_details` additionally has `category_id uuid not null references
ref.employment_status_categories(id)`.

`ref.employment_status_detail_behaviors`:

```
id                                 uuid primary key
status_detail_id                   uuid not null references ref.employment_status_details(id)
effective_from                     date not null
effective_to                       date null
participates_in_active_workforce   boolean not null
is_ongoing_relationship            boolean not null
is_relationship_ending             boolean not null
is_terminal                        boolean not null
allows_reappointment               boolean null
counts_in_monthly_reporting        boolean null
created_at                         timestamptz not null

exclude using gist (status_detail_id with =, daterange(effective_from, effective_to, '[)') with &&)
check (effective_to is null or effective_to > effective_from)
```

Every migration follows the exact S02/S03 convention: `Schema::create()` for columns/simple
constraints, a separate `DB::statement()` for anything Blueprint cannot express (the version CHECK,
the GiST EXCLUDE constraint), and a `down()` that only drops what `up()` created — no `CASCADE`.

**`ref.marital_status_aliases` (S05 CORRECTIVE-01 §22a)** — deliberately not the common shape
above; it is a lookup table onto `ref.marital_statuses`, not a reference catalog of its own, so it
carries none of `code`/`is_active`/`display_order`/`version`:

```
id                  uuid primary key
marital_status_id   uuid not null references ref.marital_statuses(id)
alias_ar            varchar(255) not null
normalized_alias    varchar(255) not null,  unique
created_at          timestamptz not null
updated_at          timestamptz not null
```

The `unique` constraint on `normalized_alias` is the physical enforcement of "a normalized alias
must never resolve to two different canonical statuses" — independent of any application-level
check, the same design principle as the GiST EXCLUDE constraint above.

## 21. API contracts

Rich families only, mounted under `/api/v1/reference/...`, `permission:reference.view` on reads and
`permission:reference.manage` on writes, mirroring the Security route-group shape exactly:

```
GET    /reference/genders
GET    /reference/genders/{gender}
POST   /reference/genders
PATCH  /reference/genders/{gender}
POST   /reference/genders/{gender}/activate
POST   /reference/genders/{gender}/deactivate
```
... identically for `marital-statuses`, `decision-types`, `employment-status-categories`,
`employment-status-details` (create additionally requires `category_id`), plus for
employment-status-detail behaviors:
```
GET    /reference/employment-status-details/{detail}/behaviors
POST   /reference/employment-status-details/{detail}/behaviors
```

## 22. Bootstrap/seed policy

Seeded via migration (same rationale as `2026_09_23_000007_seed_security_baseline_permissions`:
this is reference data owned by the module, not environment-specific business data, so it must not
depend on a seeder running). Seeded content is limited strictly to what this authorization gave
explicitly:

- `ref.genders`: `male` (ذكر), `female` (أنثى).
- `ref.marital_statuses`: `single` (أعزب), `married` (متزوج), `divorced` (مطلق), `widowed` (أرمل).
- `ref.decision_types`: no rows (structure seeded by migration existing; §5.1/§5.2 — no concrete
  decision-type catalog was given).
- `ref.employment_status_categories`: the four rows in §5.4 (`active`, `non_active`, `ended`,
  `terminal`).
- `ref.employment_status_details`: none invented beyond what would require fabricating an Arabic HR
  status catalog not given in the authorization — deferred, same as decision types. (This is a
  scope narrowing versus the authorization's illustrative examples: `on_duty`/`resigned`/`deceased`
  were used above only as *code-naming* illustrations, not as a claim that these exact rows are
  seeded. No `employment_status_details` or `employment_status_detail_behaviors` rows are seeded in
  S05; the tables, commands, and API are fully implemented and tested with synthetic test data.)
- The 10 structure-only families: no rows (no commands exist to create any in S05 either).

### 22a. CORRECTIVE-01 — Marital-status source-value aliases

**Problem this corrects.** The original S05 authorization's concrete example spellings for marital
status were masculine-form only (أعزب، متزوج، مطلق، أرمل), and those four forms were seeded as the
canonical `ref.marital_statuses` rows with no discussion of gendered variants anywhere in this
specification. MasarHR's actual HR source data uses gendered Arabic spellings (e.g. انسة، متزوجة،
مطلقة، أرملة for a female employee), and the original S05 implementation had no mechanism to
resolve those to a canonical record — a silent, undisclosed narrowing (unlike every other
scope-narrowing decision in this document, §5.1/§5.3/§14/§22/§27, which are each explicitly
disclosed here). A subsequent read-only architecture verification of the locally committed,
not-yet-pushed S05 commit flagged this as BLOCKING before the commit could be published. This
section records the Architecture Authority's resolution.

**Decision.** MasarHR has exactly four canonical marital-status business concepts — single,
married, divorced, widowed — never eight. Gender remains an independent Person attribute (a future
stage's concern); marital status is never inferred from it, and marital status is never modeled
per-gender. The gendered Arabic spellings in the source data are *source aliases* of the four
canonical concepts, not additional business states.

**Mechanism: an explicit relational alias table**, `ref.marital_status_aliases` (physical design:
§20), rather than JSON aliases, rather than hardcoding spellings in a future import script's switch
statement, and rather than inferring marital status from employee gender. Each alias row resolves
deterministically to exactly one canonical `ref.marital_statuses` row via a unique-constrained
`normalized_alias` column (§20).

**Normalization** (`App\Modules\Reference\Domain\Support\ArabicLookupNormalizer`) is limited to
three harmless, meaning-preserving rules: trim surrounding whitespace, collapse internal
whitespace, and unify the Alef family (أ/إ/آ → ا). It never strips diacritics, never removes
tatweel, and never fuzzy-matches — an unmatched value resolves to `UNRESOLVED` (`null`), never to a
guessed or `OTHER` value. Because two authorized raw spellings can collapse to the same normalized
key for the same canonical status (e.g. أعزب and اعزب both normalize to اعزب), only the minimum
non-duplicated alias row is stored per unique normalized key (§20) — 8 alias rows cover the 12
authorized raw spellings (see the seed migration's docblock for the exact mapping).

**Resolver contract:** `App\Modules\Reference\Application\Queries\ResolveMaritalStatusByArabicSourceValue`
— input: a raw Arabic source value; output: the canonical `MaritalStatus`, or `null` for
`UNRESOLVED`. It never creates a reference value, never returns a placeholder, never fuzzy-matches,
never consults gender, and never mutates state (one read against the alias table, then one lookup
of the matched status).

**Lifecycle interaction.** Resolution is independent of `is_active`: a deactivated canonical status
must remain resolvable for historical source data (deactivation is not the same question as
"selectable for new business use", and this table never blocks the former to enforce the latter).

**Scope boundary (§9 of the correction).** `ref.marital_status_aliases` is baseline system-owned
seed infrastructure only in S05 — like the 10 structure-only families, it has no controller, no
route, no permission, and no audit action. If alias administration is ever exposed as a runtime
mutation in a later stage, it must use S04's audit infrastructure and `reference.manage`, exactly
like every other S05 mutation (§16/§17).

**Explicitly out of scope for this correction** (§18 of the authorization): Excel import itself,
Person, Employee, Employment, gender-dependent business logic, fuzzy matching, and any `OTHER`/
`UNKNOWN` fallback value. S05 CORRECTIVE-01 establishes reference-resolution infrastructure only;
the raw imported value remaining available for a future Data Quality subsystem is a requirement on
that future subsystem, not built here.

## 23. Testing matrix

Feature tests under `tests/Feature/Reference/`, mirroring `tests/Feature/Security/` and
`tests/Feature/Audit/` conventions (`DatabaseTransactions`, a shared test-case base with
`createPrincipal()`/`createSecurityAdministrator()`-style helpers). Coverage per rich family:
create (success, duplicate code rejected, missing permission rejected), update-metadata (success,
stale version rejected), activate, deactivate, unauthenticated rejected, each mutation's audit
entry recorded with the exact action code from §17 and an allowlist-only `changes` payload, and a
regression that a rejected mutation appends no audit entry. `ref.employment_status_detail_behaviors`
additionally covers: defining a first open-ended period; defining a second period that closes the
first (`effective_to` set) and opens a new one with no gap; an overlapping period rejected with a
409 traced to `PostgresErrorClassifier::isExclusionViolation()`; "current behavior for date D"
resolves correctly across a period boundary. The exact test count is sized to this coverage, not to
an arbitrary target.

**CORRECTIVE-01 addendum (`MaritalStatusAliasResolutionTest`):** every authorized raw spelling
resolves to its canonical code; resolution never grows the canonical catalog past four rows;
whitespace and Alef-variant normalization; an unmatched value is `UNRESOLVED` and creates no row;
no `OTHER`/`UNKNOWN` fallback exists; a duplicate `normalized_alias` targeting a different status is
rejected at the database level; alias foreign-key integrity is enforced; a deactivated canonical
status remains resolvable while excluded from active-selection queries. Migration rollback/reapply
for the two corrective migrations is covered separately in `MigrationLifecycleTest`, following that
file's own established direct-down()/up() pattern rather than the transaction-wrapped Reference
test base.

## 24. Migration/rollback rules

16 new migrations (one per table) plus one for the two new permission rows, plus one for seeding
the baseline values — 18 total — all dated after the S04 migrations, each independently reversible
(`down()` drops only what `up()` created), none of them ever editing a released S01–S04 migration
file.

**CORRECTIVE-01 addendum:** two further migrations (create `ref.marital_status_aliases`, seed its 8
rows), dated in the same S05 migration window per §10/§16 of the correction — added as new files
rather than rewriting `2026_09_26_000002_create_ref_marital_statuses_table.php` or
`2026_09_26_000018_seed_ref_baseline_values.php`, since the architectural correction should remain
visible as its own step even though the local S05 commit itself was amended (not published, so no
S01–S04-style "never edit a released migration" concern applies — these are still pre-publication
S05 files).

## 25. Invariants

- No reference value is ever hard-deleted through any S05-exposed route or command.
- No reference value's `code` is ever changed after creation.
- No two behavior periods for the same status detail overlap (enforced physically, not just in
  application code).
- Every S05 mutation produces exactly one `MUTATION` audit entry, in the same transaction as the
  mutation itself.
- No S05 table, command, or field stores a fact about a specific person, employee, or
  organizational unit.

## 26. Non-goals

No Person/Employee/Employment/Contract/Organization table or record; no transfer, secondment,
leave-transaction, or reappointment *transaction* (the leave/employment *type* reference tables are
in scope as empty lookup tables only); no import/export, PDF/print, notification, report-execution,
or dashboard functionality; no organization-security-scope model; no generic
Command-Bus/mediator/CRUD-service/repository/Unit-of-Work infrastructure; no reference values for
any of the ten structure-only families; no frontend work (not required merely because a table or
endpoint exists).

## 27. Open questions / deferred items

- `counts_in_monthly_reporting` is structurally present but left `null` for every S05 row pending a
  future stage's concrete reporting-inclusion rule.
- The ten structure-only families have no owner-confirmed value catalog yet; populating them (and
  deciding whether they need the same command depth as the six rich families) is future-stage work.
- `ref.employment_status_details`/`ref.employment_status_detail_behaviors` ship with zero seeded
  rows for the reasons in §22; seeding the real status catalog is future-stage work once an
  authoritative list is confirmed.

## 28. Freeze readiness

This specification is internally consistent with the frozen MasarHR architecture baseline
(`CLAUDE.md`), integrates with S02's schema/extension baseline, S03's permission model, and S04's
audit/execution model without modifying any of their released migrations or contracts, invents no
unconfirmed HR business values, and discloses every scope-narrowing judgment call made while
authoring it (§5.1, §5.3, §14/D3, §22, §27). It is considered frozen for S05 implementation as of
this document's commit.
