# Organization Hierarchy Foundation — Specification (S07)

## 1. Purpose

S07 establishes the authoritative organizational-unit hierarchy that later HR, workforce movement,
reporting, and security-scope stages (S08 and beyond) can reference. It builds the tree structure
itself — units, parent/child relationships, lifecycle — and nothing that consumes it.

## 2. Scope

- A single new PostgreSQL table, `org.organizational_units`, holding a self-referencing adjacency
  list (nullable `parent_id`), a name, a lifecycle flag (`is_active`), and optimistic-concurrency
  `version` — the same shape family as an S05 rich reference value, plus the parent relationship.
- Explicit commands: Create, Rename, Move (re-parent), Activate, Deactivate.
- Explicit read queries: get one unit, list roots, list children, list ancestors (breadcrumb),
  list descendants (subtree).
- Cycle prevention that holds under concurrent writes (§13).
- Two new permissions (`organization.view`, `organization.manage`), audited mutations, `/api/v1`
  routes following the existing Reference-module route-registration pattern.

## 3. Non-Goals

No Person/Employee/Employment/national-ID/job-history/employment-status-history/workplace-
placement/contract/transfer/secondment/assignment/leave/employee-document content of any kind. No
organizational **scope** (principal/role/report visibility limited to a subtree) — that is S08. No
reporting, no workforce aggregation, no population of the `reporting` schema. No frontend UI (no
MasarHR stage since S03 has shipped one; §19 confirms this is expected, not an oversight). No
organization-type/level reference catalog (§11 below). No effective-dated/temporal period model
for organizational units (§16 below). No S08 work of any kind.

## 4. Authoritative Evidence

In priority order: (1) this S07 authorization's own explicit content; (2) MasarHR's frozen,
already-implemented architecture (S01–S06, this repository) — specifically
`docs/security-access-foundation.md` §16 ("S07/S08 boundary"), `docs/database-persistence-
foundation.md` §4–§7 (general temporal/migration conventions, not Reference-specific), and the
Reference/Audit module conventions (Domain/Application/Infrastructure/Presentation layering,
Create/Update/Activate/Deactivate command shape, `PermissionCatalog`-mirrors-migration pattern);
(3) nothing else — no other project is consulted (§0 of the S07 authorization; verified, §6 below).

## 5. Terminology

"Organizational unit" (not "department", "directorate", "branch", or any other fixed label) is
used throughout, deliberately generic: S07 does not assert what kind of thing a unit represents.
"Root" = a unit with `parent_id IS NULL`. "Ancestor"/"descendant" = the transitive parent/child
relationship. "Subtree" = a unit and all its descendants.

## 6. Discovery Findings

A repository-wide read-only search (docs/, `CLAUDE.md`, `README.md`, all migrations, all
`backend/app/Modules`, `routes/api.php`, the full test suite) found:

- **`org` schema is genuinely empty** — created by S02's `2026_09_20_000002_create_database_schema
  _namespaces` migration as a namespace only (`'org' => 'MasarHR namespace: organizational
  structure'`); no migration since has touched it; its emptiness is actively asserted by
  `ScopeBoundaryTest` in the Security, Audit, and Reference test suites through S06.
- **The S07/S08 boundary is stated once with real content**, `docs/security-access-foundation.md`
  §16: *"S07 owns organization hierarchy; S08 owns organization scope enforcement. S03 creates no
  `organization_units` table, no organizational-scope column on `security.principals`, and no
  branch/unit visibility rule."* Corroborated (no new content) by
  `docs/audit-command-infrastructure-specification.md` §2/§19 and
  `docs/versioned-behavior-reporting-references-specification.md` §5/§6.
- **No organizational-level vocabulary exists anywhere** in the repository — no "ministry",
  "directorate", "hospital", or "department" as an org-structure term. The one near-miss,
  `ref.monthly_cadre_categories`'s `administration`/`إدارة` code, is an S06 job-cadre
  classification, explicitly and deliberately distinguished (S06 spec §17) from any org-structure
  concept. This confirms D09: **no hierarchy levels are frozen anywhere**, and the explicit
  instruction not to assume Ministry→Administration→Directorate→Department→Section applies with
  nothing pulling the other way.
- **No hierarchy-specific PostgreSQL technique exists**: only the `btree_gist` extension is
  enabled (S02), used exclusively for temporal-range exclusion constraints; `ltree` is not enabled
  and is not mentioned anywhere in the repository. No adjacency-list/closure-table/materialized-
  path code exists yet. This is genuinely open (D04) — not a frozen decision to preserve, but also
  not permission to reach for a generic tree framework (§9 of the authorization).
- **No organization-scope placeholder exists on any S03/S04 table**: `security.principals` is
  actively tested (`ScopeBoundaryTest`) to have none of `organization_unit_id`/`branch_id`/`scope`;
  `audit.audit_entries` likewise has no `organization_unit_id` and none is anticipated by that
  schema (S04 spec §19, quoted above).
- **General, stage-agnostic conventions confirmed** (not Reference- or Security-specific):
  Domain/Application/Infrastructure/Presentation layering (`CLAUDE.md`); `PermissionCatalog`
  mirrors its seed migration by convention, never shared code, identically in both
  `Security\Infrastructure\Authorization\PermissionCatalog` and
  `Reference\Infrastructure\Authorization\ReferencePermissionCatalog`; `docs/database-persistence-
  foundation.md` §4–§7 states general time/migration/transaction rules (`timestampTz()` only, half-
  open `[from,to)` DATE periods, `PostgresErrorClassifier` SQLSTATE mapping, no `CASCADE`,
  business tables never in `public`) for any future module, and explicitly notes `TemporalConstraints`
  was built in S02 with "no production table uses it yet" — a reusable primitive awaiting adoption,
  not evidence that every future table must be temporal.
- **No S06 reporting mapping references organization** — `specialty_cadre_category_mappings`,
  `job_title_administrator_classifications`, `contract_type_population_mappings` map Specialty/
  JobTitle/ContractType to reporting concepts, none of them organizational. **No compatibility
  correction to S06 is needed or made** (D24).
- **No frontend UI exists for any backend-only stage since S03** — S04 (Audit) and S05/S06
  (Reference) shipped API-only; `frontend/src/features` and `frontend/src/pages` contain no
  `reference`/`audit` directory. No doc commits to when Organization gets a UI. S07 follows the
  same precedent: backend/API only (§19 below).

## 7. S07 Scope Reconciliation Verdict

**PASS.** No contradiction found. The `org` schema's emptiness and the S07/S08 boundary statement
in `docs/security-access-foundation.md` §16 directly anticipate this stage. No genuine architecture
blocker was found during discovery (§8 answers every D01–D25 question either from direct evidence
or from a disclosed, conservative, precedent-following judgment call — see §38). Implementation
proceeds under the scope in §2/§3.

## 8. Discovery Question Answers (D01–D25)

- **D01** (dedicated bounded context?): Not yet implemented, but the `org` schema and the S07/S08
  boundary statement together establish it is *intended* as one. S07 creates it.
- **D02** (authoritative schema): `org`, confirmed — the only schema whose stated purpose
  (`'MasarHR namespace: organizational structure'`) matches, and the only one still empty and
  unclaimed by any other stage.
- **D03** (empty or partial?): Genuinely empty — no migration, no table, verified above.
- **D04** (hierarchy representation): Not frozen anywhere. Chosen here: **adjacency list**
  (nullable self-referencing `parent_id`) — the simplest representation consistent with existing
  conventions (plain FK, no new extension), explicitly preferred over a closure table (extra
  bulk-maintained table with no evidenced need) or `ltree`/materialized path (a new extension with
  no existing precedent, and §9 forbids adding infrastructure "merely for convenience"). Justified
  further in §13.
- **D05** (temporal/effective-dated?): Not required by any evidence. See §16 — S07 does not make
  organizational units temporal.
- **D06** (what specifically, if temporal?): N/A — nothing is temporal (§16). Existence, parent,
  name, and status are all plain current-state fields, consistent with the instruction "do not
  make everything temporal automatically."
- **D07** ("deactivate, not delete"): Mirrors S05's exact convention — `is_active` boolean, no hard
  delete ever exposed (no `DELETE` route; enforced by a `ScopeBoundaryTest` addition, §35).
- **D08** (org-type catalog already specified?): No. Not built — see §11 (deliberately no
  organization-type concept at all, not even structure-only).
- **D09** (hierarchy levels frozen?): No, confirmed by discovery. None invented.
- **D10** (stable codes beyond UUID?): Not required by evidence. UUID technical identity plus a
  required human-readable `name` is sufficient, mirroring S05 §5.E precedent (UUID, never a label,
  as the FK/identity). No separate `code` field is added — inventing an unspecified coding scheme
  (e.g., cost-center codes) would be exactly the kind of business-rule invention §0 forbids. A
  future stage can add one non-destructively if ever needed (§38).
- **D11** (required commands): `CreateOrganizationalUnit`, `RenameOrganizationalUnit`,
  `MoveOrganizationalUnit`, `ActivateOrganizationalUnit`, `DeactivateOrganizationalUnit` — the
  candidate names from §12 of the authorization, adopted because they fit the established
  Reference-module shape (four standard lifecycle verbs + one domain-specific verb, exactly how
  S05 added `DefineEmploymentStatusDetailBehaviorPeriod` as its one domain-specific verb beyond
  Create/Update/Activate/Deactivate).
- **D12** (required queries): `GetOrganizationalUnit` (show), `ListRootOrganizationalUnits`,
  `ListChildOrganizationalUnits`, `ListOrganizationalUnitAncestors` (breadcrumb), `ListOrganizationalUnitDescendants`
  (subtree) — the minimum set §23/D23 asks for, each a pure read via a recursive CTE (§14).
- **D13/D14** (permissions): No existing S03/S04/S05 permission semantically fits "manage the
  organization hierarchy" — `reference.manage` is Reference-module data, not Organization. Two new
  permissions are introduced, `organization.view`/`organization.manage`, following the exact S03/S05
  `PermissionCatalog`-mirrors-seed-migration convention (§17).
- **D15** (audit): Every mutating command (Create/Rename/Move/Activate/Deactivate) runs through the
  existing `AuditedCommandExecutor`, exactly like every S05 mutation.
- **D16** (concurrency): The `version` column (optimistic concurrency) on every mutation that
  changes a specific row's own fields (Rename/Activate/Deactivate/Move all require
  `expected_version`), *plus* a PostgreSQL advisory transaction lock for Move specifically (§13) —
  two different concerns: stale-row protection (version) and whole-hierarchy structural-integrity
  protection (advisory lock), both needed together for Move.
- **D17** (cycle prevention): `CHECK (parent_id <> id)` at the database level trivially prevents
  self-parenting always, regardless of application bugs. Indirect cycles are prevented by a
  recursive-CTE ancestor check performed inside `MoveOrganizationalUnit`'s transaction, itself made
  race-free by a `pg_advisory_xact_lock` held for the duration of that transaction (§13) — this is
  the "required transactional/database protection" the authorization's §10 asks for when
  application checks alone cannot guarantee safety under concurrency.
- **D18** (move under own descendant): Rejected — the recursive ancestor check (§13) detects it and
  the command throws `WouldCreateCycleException` → HTTP 409, before any row is touched.
- **D19** (children on deactivation): **No cascade.** Deactivating a unit does not touch its
  children's `is_active` state at all — explicitly required by the authorization itself ("do not
  invent cascade behavior"). Children remain exactly as they were.
- **D20** (deactivate parent with active children?): **Allowed, not blocked** — resolved by direct
  precedent rather than invention: S05 CORRECTIVE-01 §8 already established, for this exact
  codebase, that deactivating a reference value never destroys the validity of rows that still
  point to it (a `marital_status_alias` pointing to a deactivated `marital_status` remains fully
  resolvable). A child unit's `parent_id` pointing to a now-inactive parent is the direct structural
  analogue. Blocking deactivation while active children exist would be a *new*, unevidenced rule in
  the other direction — not adopted, for the same "don't invent" reason. Not a STOP: this is answered
  by existing precedent, not by inventing a business decision.
- **D21** (parent-change semantics): A **move** — a direct, non-temporal mutation, fully audited
  (old `parent_id` → new `parent_id`), not a "correction" and not a versioned "temporal transition"
  (S07 has no temporal period model, §16). Historical "what was the structure as of date X" is not
  supported and is recorded as deferred (§38); the audit trail is the only historical record S07
  provides.
- **D22** (future dependents, identify only): HR/Employee workplace placement (a later HR/Employee
  stage), the five frozen report families' organizational grouping (a later Reporting stage), and
  organizational-scope authorization (S08). No implementation of any of them here.
- **D23** (query contract): Root(s), children, ancestors, descendants/subtree, breadcrumb/path — all
  five, all read-only, all via recursive CTE (§14). No reporting query, no Employee query, no
  security-scope filtering.
- **D24** (S06 assumptions about organization): None — confirmed by discovery; no correction to S06
  is needed or made.
- **D25** (Person/Employee/Employment absent): Confirmed absent from S07's design in every section
  below.

## 9. Module Boundary

New module: `App\Modules\Organization`, same four-layer shape as Reference/Audit
(`Domain/Application/Infrastructure/Presentation`). No code is added to any other module except
the two-line permission-catalog addition pattern (a new `OrganizationPermissionCatalog`, not an
edit to `ReferencePermissionCatalog` or `PermissionCatalog`) and the routes file.

## 10. Data Ownership

`App\Modules\Organization` owns `org.organizational_units` exclusively. No other module reads or
writes it directly; a future consumer (HR/Reporting/S08) would go through this module's queries.

## 11. Organization-Unit Identity — No Type/Level Catalog

A unit has a stable UUID `id`, a required `name` (single field — no `name_ar`/`name_en` split,
unlike S05 reference values: an organizational unit's name is entered once by an administrator, not
selected from a bilingual fixed catalog, and nothing in evidence asks for a second language field
here), `parent_id` (nullable UUID FK to `org.organizational_units.id`), `is_active`, `version`,
`created_at`, `updated_at`. Deliberately **no** `type`/`level`/`code` column: §11 of the
authorization forbids inventing organization-type values, and no evidence establishes that typed
units are needed at all (§8 D08/D10 above). This can be added non-destructively later if a future
stage's authorization establishes the need (§38).

## 12. Hierarchy Representation

Adjacency list via `parent_id`. Chosen over a closure table or `ltree`/materialized path because
(a) no existing precedent or extension supports either alternative, (b) an adjacency list is the
simplest representation that satisfies every required query via a `WITH RECURSIVE` CTE, and (c)
§9 of the authorization forbids adding infrastructure (a new extension, a generic tree framework,
a bulk-maintained closure table) "merely for convenience" when a plain FK already suffices for the
evidenced query and mutation needs.

## 13. Cycle Prevention Under Concurrency

Two layers, both required:

1. **Database, always-on**: `CHECK (parent_id IS NULL OR parent_id <> id)` — self-parenting is
   physically impossible regardless of any application bug or race.
2. **Transactional, for indirect cycles**: `MoveOrganizationalUnit::handle()` begins by acquiring
   `pg_advisory_xact_lock(hashtext('org.organizational_units.hierarchy'))` — a single named
   advisory lock scoped to the whole table, held for the transaction's duration and released
   automatically at commit/rollback. Because moving a unit is a rare, administrative operation (not
   a high-frequency business transaction), fully serializing concurrent moves against each other is
   an acceptable, simple, and *correct* trade-off — no two `Move` commands can interleave their
   cycle checks and updates. After acquiring the lock, the command runs a recursive CTE from the
   proposed new parent upward through its ancestors; if the unit being moved appears in that
   ancestor chain (or is the proposed parent itself), the move is rejected with
   `WouldCreateCycleException` before any `UPDATE` runs. This is exactly the "required
   transactional/database protection" the authorization calls for when application checks alone
   cannot guarantee safety under concurrency (§10) — the advisory lock is a database-level
   primitive, not merely an application-level check racing against itself.

`CreateOrganizationalUnit` (which also sets an initial `parent_id`) needs no advisory lock: a
brand-new unit cannot yet be anyone's ancestor, so no cycle is possible at creation time regardless
of concurrent activity elsewhere in the tree — only a *change* to an existing unit's parent can
create a cycle.

## 14. Query Contracts

All five, read-only, never mutate, all under `Application/Queries/`:

- `GetOrganizationalUnit` — a plain Eloquent find (route-model-bound in the controller, matching
  every S05 `show()`).
- `ListRootOrganizationalUnits` — `WHERE parent_id IS NULL`.
- `ListChildOrganizationalUnits($unit)` — `WHERE parent_id = ?`.
- `ListOrganizationalUnitAncestors($unit)` — `WITH RECURSIVE ancestors AS (SELECT * FROM
  org.organizational_units WHERE id = :start_parent UNION ALL SELECT u.* FROM
  org.organizational_units u JOIN ancestors a ON u.id = a.parent_id) SELECT * FROM ancestors` —
  ordered root-first for breadcrumb display.
- `ListOrganizationalUnitDescendants($unit)` — the same recursive shape, walking down via
  `JOIN descendants d ON u.parent_id = d.id`, used both for the public query and internally by the
  Move command's cycle check (§13).

## 15. API

`/api/v1/organization/...`, `web` middleware group, `auth:web` + `principal.active` +
`resolve.context`, `permission:organization.view` for reads and `permission:organization.manage`
for writes — identical convention to every existing Reference route.

```
GET    /organization/units
GET    /organization/units/roots
GET    /organization/units/{organizationalUnit}
GET    /organization/units/{organizationalUnit}/children
GET    /organization/units/{organizationalUnit}/ancestors
GET    /organization/units/{organizationalUnit}/descendants
POST   /organization/units
PATCH  /organization/units/{organizationalUnit}
POST   /organization/units/{organizationalUnit}/move
POST   /organization/units/{organizationalUnit}/activate
POST   /organization/units/{organizationalUnit}/deactivate
```

`GET /organization/units` lists all units (paginated, matching the S05 `index()` convention) —
`GET /organization/units/roots` is the dedicated root-listing endpoint. No report endpoint, no
generic table/PATCH-row API, no `DELETE`. Errors: 401 (unauthenticated), 403 (missing permission),
409 (stale `version`, or `WouldCreateCycleException` on Move), 422 (validation). No internal SQL
detail ever exposed.

## 16. Temporal Semantics — None

S07 introduces no effective-dated/period model. Existence, parent, name, and status are all
current-state fields with no history table. This is a deliberate, disclosed minimalism decision
(§8 D05/D06): nothing in evidence asks for "as of date X" resolution of organizational structure,
and inventing a period model for every property "automatically" is explicitly what D06 warns
against. The `audit.audit_entries` trail (S04) is the only historical record a Move/Rename produces.

## 17. Authorization

Two new permissions, `organization.view` and `organization.manage`, seeded by a new migration
mirroring `2026_09_26_000017_seed_security_reference_permissions` exactly (inserted directly via
`DB::table`, never referencing application code from inside a migration — same rule S03's
`PermissionCatalog` docblock states). `App\Modules\Organization\Infrastructure\Authorization\
OrganizationPermissionCatalog` mirrors the seed by convention (developer-maintained, not shared
code), exactly like `ReferencePermissionCatalog`. Default deny is unaffected: no existing role is
granted anything new.

## 18. Audit

New allowlisted action codes: `organization.unit.create`, `organization.unit.rename`,
`organization.unit.move`, `organization.unit.activate`, `organization.unit.deactivate`. Every
mutation routes through `AuditedCommandExecutor` in the same transaction as the mutation, with an
allowlisted `changes` payload (unit id, old/new name or old/new parent_id, as applicable) — never
credentials, tokens, or raw request data.

## 19. UI / Non-UI Boundary

Backend/API only. No frontend work — following the unbroken precedent of every backend-only stage
since S03 (S04 Audit, S05/S06 Reference all shipped with zero frontend changes; `frontend/src`
has no `reference`/`audit` directory). Nothing in any doc schedules Organization for a UI in this
stage.

## 20. Data Integrity

`CHECK (parent_id IS NULL OR parent_id <> id)` (self-parent, §13); `FOREIGN KEY (parent_id)
REFERENCES org.organizational_units(id)` (no dangling parent references — a parent must exist,
enforced by the database, not just route-model binding); `CHECK (version >= 1)` (mirrors every S05
rich family); `UNIQUE` is **not** applied to `name` — two units with the same name in different
branches of the tree is a legitimate real-world case (e.g., two different "Administration" units
under two different top-level branches), and nothing in evidence requires global name uniqueness.

## 21. Database Constraints

```
org.organizational_units:
  id            uuid primary key
  parent_id     uuid null references org.organizational_units(id)
  name          varchar not null
  is_active     boolean not null default true
  version       unsigned integer not null default 1
  created_at    timestamptz not null
  updated_at    timestamptz not null

  check (parent_id is null or parent_id <> id)     -- self-parent, always enforced
  check (version >= 1)                              -- mirrors every S05 rich family
  index on parent_id                                -- every child/ancestor/descendant query filters on it
```

## 22. Indexes

A btree index on `parent_id` (children lookups and the recursive CTE's join condition both filter
on it directly); the primary key index on `id` already covers ancestor-walk lookups. No other
index is justified by the query contract in §14.

## 23. Migration Strategy

Three new migrations, dated after S06 (`2026_09_26_000030` onward — see §31 for the exact list).
Deterministic `up()`/`down()`, no `CASCADE`, `down()` drops or deletes only what its own `up()`
created — identical discipline to every prior stage, mechanically checked by the unchanged
`MigrationDisciplineTest` (self-scaling, zero code change needed for new files).

## 24. Seed Strategy

Only the two `organization.view`/`organization.manage` permission rows are seeded (mirroring the
S05/S03 permission-seed pattern exactly). **Zero** `organizational_units` rows are seeded — S07
introduces no production organizational data; every unit is created later, by an authorized
administrator, through the API. This mirrors S05's own "structure-only, values deferred" discipline
even more strictly, since here not even the *catalog* is pre-defined — the whole tree is
administrator-authored from an empty table.

## 25. Validation

`CreateOrganizationalUnit`: `name` required non-empty string; `parent_id` optional UUID, must
reference an existing unit (enforced by the FK; the command additionally does a `findOrFail` so a
missing parent surfaces as 404, exactly like S05's `RoleController::grantPermission` precedent, not
as an opaque FK violation). `RenameOrganizationalUnit`/`Activate`/`Deactivate`: `expected_version`
required. `MoveOrganizationalUnit`: new `parent_id` (nullable — moving to root is allowed) plus
`expected_version` of the unit being moved; rejects self-parent and indirect cycles per §13.

## 26. Error Semantics

401/403/422 follow the existing global conventions unchanged. 409 covers two distinct causes on
Move (stale `version` via the existing `StaleVersionException`, or a would-be cycle via the new
`WouldCreateCycleException`) — both map to 409 because both are "the operation conflicts with the
current state of the resource," consistent with how S05 already uses 409 for two different causes
(`OverlappingBehaviorPeriodException` and stale version) on the same status code.

## 27. Security Permissions

`organization.view` (all `GET` routes), `organization.manage` (all mutating routes) — see §17.

## 28. Organizational Scope Exclusion

S07 adds no column, no filter, no query parameter, no middleware that limits what a principal can
see or do based on which subtree they belong to. Every `organization.view`/`organization.manage`
grant is global (see the whole tree, manage the whole tree) — subtree-limited authorization is
S08's entire subject matter and is not approximated, stubbed, or partially built here.

## 29. Audit Integration

See §18.

## 30. Data Integrity

(Combined with §20 — no separate content; kept as its own numbered section per the required
34→40-item spec outline, cross-referenced rather than duplicated.)

## 31. Migration Plan

1. Create `org.organizational_units` (table + both CHECK constraints + FK + index)
2. Seed `security.permissions` with `organization.view`/`organization.manage`
3. (reserved — no third schema migration is needed; kept as its own file only if the seed above
   needs a companion — see the actual migration list in §35/the final report; this specification
   places both a create-table and a seed-permissions migration, matching S05's own precedent of a
   dedicated `..._seed_security_reference_permissions` migration separate from the table-creation
   migrations)

## 32. Explicit Deferred Decisions

- No `type`/`level` column (§11) — addable later without breaking anything, once a future stage's
  authorization actually needs it.
- No `code` field (§8 D10) — same rationale.
- No temporal/effective-dated model (§16) — "as of date X" org-structure queries are not supported;
  a future stage would need to add its own explicitly authorized versioning if ever required.
- No cascade-deactivation of children (§8 D19) — explicitly required by the authorization itself.
- No restriction on deactivating a parent with active children (§8 D20) — resolved by direct S05
  CORRECTIVE-01 precedent, not invention.
- No global uniqueness on `name` (§20).

## 33. Acceptance Criteria

Discovery PASS; zero unresolved architecture blocker; this specification complete; implementation
matches it exactly; hierarchy integrity proven (self-parent + indirect-cycle rejection tests);
concurrent-move cycle protection proven (a dedicated concurrency test); authorization PASS; audit
PASS; full backend suite PASS on real PostgreSQL; Pint PASS; `git diff --check` PASS; no S08
content; no Person/Employee/Employment content; no unrelated scope changes; no cross-project
contamination; `_to_delete/` untouched.

## 34. Architecture Invariants

No hard delete, ever. No destructive migration rollback beyond what a migration's own `up()`
created. No generic CRUD/PATCH/repository/Unit-of-Work infrastructure (mirrors S05 §9's explicit
prohibition, reused here). No JSONB. No new PostgreSQL extension. No organization-type catalog. No
organizational scope. No Person/Employee/Employment. No S08. No frontend. Every mutation audited.
Every route authorized. `_to_delete/` never touched.

## 35. Adversarial Self-Review

Performed after drafting §1–§34, before implementation, per the S07 authorization's §22. Each
required challenge item is addressed; verdicts are BLOCKING / NON-BLOCKING / DEFERRED.

1. **Cycle race conditions.** Two concurrent `Move` requests both targeting positions that would
   individually be safe but jointly create a cycle: the `pg_advisory_xact_lock` (§13) fully
   serializes all `Move` transactions against each other — the second request's cycle check runs
   only after the first has committed (or rolled back), against the now-current tree, so it always
   sees a consistent state. **NON-BLOCKING — proven by lock semantics, verified by a concurrency
   test (§39).**
2. **Stale `expected_version` acceptance.** `Move`/`Rename`/`Activate`/`Deactivate` all use the same
   conditional `UPDATE ... WHERE id = ? AND version = ?` pattern as every S05 command (via the same
   `Abstract*` bases where applicable, or an equivalent inline conditional update for `Move`, which
   cannot reuse the generic Update base because it also needs the advisory lock and cycle check
   first). **NON-BLOCKING.**
3. **Lost updates / write skew.** Covered by #1 (structural) and #2 (per-row); no other mutation
   path exists. **NON-BLOCKING.**
4. **Broken tree after concurrent moves.** Impossible by construction: the advisory lock means only
   one `Move` transaction can be mutating the tree at any instant, and every reparenting is a single
   atomic `UPDATE` of one row's `parent_id` — there is no multi-row tree-rewrite operation (no
   closure table to keep in sync) that could be left half-done. **NON-BLOCKING.**
5. **Deactivated-parent inconsistencies.** Resolved explicitly by §8 D20 as matching S05
   CORRECTIVE-01 precedent — not an inconsistency, a deliberate, precedented design.
   **NON-BLOCKING.**
6. **Authorization bypass.** Every route carries `organization.view` or `organization.manage`;
   verified by a scope-boundary test asserting no route lacks a `permission:` middleware entry.
   **NON-BLOCKING.**
7. **Audit gaps.** Every one of the five commands is wrapped by `AuditedCommandExecutor` with an
   explicit `AuditSpec`, matching every S05 controller method exactly — none bypassed.
   **NON-BLOCKING.**
8. **Transaction/audit separation.** No command opens its own `DB::transaction()` outside the
   advisory-lock case: `MoveOrganizationalUnit` acquires the advisory lock and performs its cycle
   check and `UPDATE` *inside* the transaction `AuditedCommandExecutor` already opens (the lock
   call and the CTE/UPDATE all happen inside the executor's callback, so they share its one
   transaction — `pg_advisory_xact_lock` is itself transaction-scoped, releasing automatically at
   that same commit/rollback boundary, so no separate transaction is ever opened). **NON-BLOCKING —
   verified against `AuditedCommandExecutor`'s actual transaction boundary before implementation.**
9. **Accidental S08 implementation.** Re-checked against §28 — no scope column, no filter, no
   subtree-authorization exists anywhere in this design. **NON-BLOCKING.**
10. **Accidental Employee implementation.** No Person/Employee/national-ID/job-history field exists
    anywhere in `org.organizational_units` or any command/query. **NON-BLOCKING.**
11. **Hard deletes.** No `DELETE` route, no `->delete()` call anywhere in the Organization module.
    **NON-BLOCKING.**
12. **Hidden assumptions about hierarchy levels.** Re-checked against §11 — no `type`/`level` field,
    no fixed depth, no assumed root count (multiple simultaneous roots are explicitly allowed by
    `ListRootOrganizationalUnits`' plain `WHERE parent_id IS NULL`, with no uniqueness constraint
    forcing exactly one). **NON-BLOCKING.**
13. **Invented reference values.** Zero `organizational_units` rows seeded (§24) — the whole catalog
    is administrator-authored via the API, not invented here. **NON-BLOCKING.**
14. **Generic abstractions not justified by MasarHR.** No generic tree framework, no closure table,
    no `ltree`, no JSONB, no CRUD/repository/Unit-of-Work layer — re-checked against §9/§34.
    **NON-BLOCKING.**
15. **Cross-project contamination.** Zero references to any other project anywhere in this document
    or the implementation that follows it. **NON-BLOCKING.**

**Summary: zero BLOCKING findings.** No drafting-stage issue required correction this time (unlike
S06's item 10) — the D20 precedent-reuse and the advisory-lock transaction-boundary check (#8) were
verified correct on first draft. Implementation is authorized to proceed (§23 of the S07
authorization).

## 36. Non-Blocking Findings

Items 1–15 above (§35), all verified safe by construction.

## 37. Deferred Findings

Same as §32 (Explicit Deferred Decisions) — no additional items surfaced by the adversarial review
beyond what discovery already identified as open.

## 38. S08 Handoff

S08 ("Organizational Access Scope") will need: a stable way to resolve "which subtree(s) does
principal X have access to" — the `ListOrganizationalUnitDescendants`/`ListOrganizationalUnitAncestors`
queries this stage builds are directly reusable for that (a scope check is fundamentally "is unit Y
in the descendant set of principal X's assigned unit(s)"), without S07 needing to anticipate S08's
own authorization model, permission design, or data model for "principal → unit" assignment (none
of which exists yet and none of which S07 invents).

## 39. Testing Strategy

New tests under `tests/Feature/Organization/`, extending a new `OrganizationTestCase` (mirroring
`ReferenceTestCase`'s `actingAsOrganizationManager()`/`actingAsOrganizationViewer()` shape): schema/
migration tests (create, rollback, reapply — mirroring `MigrationLifecycleTest`'s established
direct-`down()`/`up()` pattern); unit lifecycle (create, rename with stale-version rejection,
activate/deactivate round-trip, audit on each); root/child/ancestor/descendant query correctness
across a multi-level tree fixture; self-parent rejection (both via the API and directly at the
database via the CHECK constraint); direct-cycle rejection (A→B, then attempt B→A); indirect-cycle
rejection (A→B→C, then attempt moving A under C); moving a node under its own direct child;
deactivated-parent-with-active-children allowed (proving §8 D20); no-cascade-on-deactivate (a
child's `is_active` is unaffected by its parent's deactivation); authorization (403 without
`organization.manage`); a genuine **concurrency test**: two overlapping `Move` attempts against a
shared tree, using two separate DB connections/processes (or, if the test harness cannot drive true
concurrency, a test that directly proves the advisory lock is acquired and held for the transaction
via `pg_advisory_xact_lock`/`pg_locks` inspection); standardized 422/409 error shapes; a
`ScopeBoundaryTest` (no hard-delete route, `org` schema contains exactly one table, no
Person/Employee/Organization-scope column exists, S08 not implemented, unrelated modules
unchanged); `MigrationDisciplineTest` auto-covers the new migrations with zero code change.

## 40. Performance Expectations

No performance requirement is evidenced or invented. The recursive CTEs are bounded by the actual
size of a real ministry's organizational tree (expected to be at most a few hundred units,
consistent with the "General Directorate of Hospitals" domain this system serves) — no pagination
or depth-limiting is added speculatively; if a future stage's real data proves this insufficient,
that is its own, separately justified optimization.

## 41. Future Integration Boundaries

See §38 (S08 handoff) and §8 D22 (HR/Employee workplace placement, Reporting organizational
grouping) — identified as future consumers, none implemented here.

## 42. Freeze Readiness

This specification is internally consistent with the frozen MasarHR architecture baseline
(`CLAUDE.md`), builds on S01–S06 without modifying any of their released migrations, models,
commands, controllers, routes, or tests, invents no organization-type value, hierarchy level, or
production data, and discloses every scope-narrowing judgment call made while authoring it (§8
D04/D08/D09/D10/D19/D20, §11, §16, §20). It is considered frozen for S07 implementation as of this
document's commit.
