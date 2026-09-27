# Person & Employment Foundation (S09) — Version 1.0

## 0. Provenance (read this first)

**S09 — Person & Employment Foundation** (Arabic: المرحلة 09 — تأسيس الشخص والعلاقة الوظيفية) is
an **Architecture-Authority reconstruction**, not a recovered historical title. Repository-based
Roadmap Recovery (delivered as "MASARHR — S09 ROADMAP RECOVERY BLOCKER REPORT" on 2026-09-26)
established that no document, ADR, or commit anywhere in this repository names an "S09" stage or
gives it an exact boundary — only a clear *direction* (the `hr` schema, reserved empty since S02,
plus repeated deferrals of "the person/employee domain" to "a later stage" across the S03/S04
specs). The two externally-named Arabic business-analysis documents this domain would normally be
specified from do not exist in this repository.

The Architecture Authority resolved that blocker on 2026-09-26 via **ADR-S09-001**, selecting
Option B: the Person/Employment/Temporal rules given conditionally in §§12–14 of the original S09
authorization are activated as authoritative content for this stage, without needing the missing
source documents. This specification documents that provenance plainly, as instructed, and must
never be read as "the historically recovered S09."

## 1. Purpose

Establish the minimum durable HR identity and employment-relationship foundation later workforce
domains (placement, transfers, leave, status history, qualifications, reporting) will build on.
S09 owns exactly two aggregates: **Person** and **Employment Relationship**. It owns no later
workforce history stream.

## 2. Non-goals (hard boundary — §21 of ADR-S09-001)

S09 does **not** implement: full Employee 360 UI; qualification/professional/job/placement
history; original/current workplace; transfer/secondment/partial-secondment; work schedules;
supervisory assignments; leave; contract lifecycle/renewal automation; expiry automation;
reporting datasets or the five official reports; Excel import; data-quality workflow; exports;
notifications; S10+. No frontend is built — backend/domain/API only, matching every prior stage's
scope discipline.

## 3. Module and schema

New module `App\Modules\HumanResources`, four layers (Domain/Application/Infrastructure/
Presentation), matching every existing module. Tables live in the `hr` schema, reserved empty by
S02 specifically for this (`docs/database-persistence-foundation.md` §2). This is the first
migration to populate it.

## 4. Person — aggregate

`hr.persons`:

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7, application-generated (`Str::uuid7()`), identical convention to every other aggregate root in the repository. |
| `national_id` | `string(64)` | The canonical, normalized business reference. `UNIQUE`. |
| `is_terminal` | `boolean not null default false` | Set exactly once, by `EndEmploymentRelationship` (§10), never unset. |
| `version` | `unsigned int default 1` | Optimistic concurrency, identical shape to `security.principals`/`org.organizational_units`. |
| `created_at`, `updated_at` | `timestamptz` | |

**Deliberately excluded from S09 v1**: name, gender, marital status, date of birth, and every
other demographic/profile attribute. §5 of ADR-S09-001 requires attributes to be "clearly required
... and evidenced by approved analysis," and requires documenting evidence before adding a name or
other basic identity attribute. The only approved use case named anywhere in this authorization is
National-ID lookup (§19), which does not require a name to function (it resolves to a Person
`id`). No other evidence for a name/profile column exists in the repository. This is a disclosed,
deliberate scope decision — see §26 (Deferred items) — not an oversight; a "Person profile" stage
adding name/demographic columns is expected, and is exactly the kind of thing that requires its own
explicit Architecture Authority evidence rather than being guessed at here.

Person has no organizational-unit column (§17 — see §16 below on why S08 scope is not consumed
here).

## 5. National ID policy

- **Opaque business identifier** (§4 of ADR-S09-001): S09 invents no digit semantics, checksum,
  length, or nationality/geography rule.
- **Normalization**: `trim()` only. No zero-padding, no digit reconstruction, no fuzzy matching,
  no DOB/gender/location derivation. This is the only normalization the ADR permits without new
  authorization ("If no stronger frozen rule exists: trim surrounding whitespace").
- **Uniqueness**: enforced by a database `UNIQUE` constraint on the trimmed, stored value — not
  merely an application pre-check, mirroring `security.principals.username_normalized`'s exact
  discipline (§3 of the original S03 spec, reconfirmed by `PrincipalTest::
  test_a_concurrent_duplicate_username_insert_is_rejected_by_the_database_not_just_the_application`).
- **Lookup starts from National ID** (§3): `FindPersonByNationalId` is the canonical read path; see
  §19 (API).
- No attribute (gender, marital status, etc.) is ever inferred from the National ID or from any
  other attribute (§3).

## 6. Employment Relationship — aggregate

`hr.employment_relationships`:

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7. |
| `person_id` | `uuid not null` | FK → `hr.persons(id)`, `RESTRICT` (no hard delete of a Person with any relationship history — mirrors S08's `RESTRICT` FK to `org.organizational_units`). |
| `employment_type_id` | `uuid not null` | FK → `ref.employment_types(id)`, `RESTRICT`. Reuses the existing S05 "structure-only, values-deferred" reference family (§7 below) instead of a private enum — exactly what S05 §5.3 anticipated ("a later stage can add a foreign key into it immediately"). |
| `employee_number` | `string(64) not null` | See §8. |
| `employee_number_scheme` | `string(16) not null` | `'PERMANENT'` \| `'CONTRACT'`. Recorded at write time from the resolved employment type's `code` — see §8 for why this is a separate column rather than joining through `employment_type_id`. |
| `effective_from` | `date not null` | Business-effective start. |
| `effective_to` | `date nullable` | Business-effective end, half-open `[from, to)`, frozen S02 convention. |
| `end_knowledge_state` | `string(16) not null default 'NOT_APPLICABLE'` | `'KNOWN'` \| `'UNKNOWN_LEGACY'` \| `'NOT_APPLICABLE'`. See §12. |
| `ended_terminally` | `boolean nullable` | Only meaningful when `end_knowledge_state = 'KNOWN'`; see §10. |
| `version` | `unsigned int default 1` | Optimistic concurrency. |
| `created_at`, `updated_at` | `timestamptz` | |

Person and Employment Relationship are separate tables/aggregates (§6 of ADR-S09-001) — never
collapsed into one row, so a Person can exist with zero, one, or many (non-overlapping, historical)
Employment Relationships.

No organizational-unit column on this table — see §16.

## 7. Reference-family reuse: `ref.employment_types`

S05 shipped `ref.employment_types` as one of ten "structure-only, values-deferred" families
(`docs/reference-data-foundation-specification.md` §5.3): migration + Eloquent model only, zero
seed rows, "no authoritative value list exists ... yet." ADR-S09-001 §7 now supplies exactly the
authoritative value list S05 was waiting for: `PERMANENT` and `CONTRACT`, "the already-approved
employment forms needed for employee-number semantics."

`ref.contract_types` and `ref.employment_categories` (the other two "form"-adjacent deferred
families) are **not** used for this distinction and are **not** touched by S09: nothing in
ADR-S09-001 or any prior spec evidences that either of them is the PERMANENT/CONTRACT concept —
`contract_types` reads as a finer-grained sub-classification of contract employment (still
unevidenced), and `employment_categories` as a still-different, unevidenced grouping. Reusing them
here would be exactly the "opportunistic" scope creep §5/§7 forbid.

A new migration seeds exactly two rows into `ref.employment_types`:

| `code` | `name_ar` | `name_en` |
|---|---|---|
| `permanent` | دائم | Permanent |
| `contract` | تعاقد | Contract |

`name_ar` is a direct, standard Arabic rendering of the two English terms ADR-S09-001 itself uses
as the authoritative business concept (`PERMANENT`, `CONTRACT`) — required because
`ref.employment_types.name_ar` is `NOT NULL` (S05's frozen physical shape, §20 of the S05 spec),
and because these two forms are the load-bearing content of this entire stage, not incidental
labels. This is disclosed explicitly, mirroring S05's own §8 audit-trail discipline, rather than
silently invented. No other row, and no `name_en`/`name_ar` beyond these two literal terms, is
added.

**Correction from an earlier draft of this section**: S05 did **not** ship a `CreateEmploymentType`
command — none of the ten "structure-only, values-deferred" families received a command, per S05
§5.3 itself. Confirmed by inspecting `App\Modules\Reference\Application\Commands` directly: no
such class exists. The S09 seed migration therefore inserts the two rows the same way S05's own
`2026_09_26_000018_seed_ref_baseline_values` migration seeded `ref.genders`/`ref.marital_statuses`/
`ref.employment_status_categories` — a direct `DB::table('ref.employment_types')->insert(...)` in
`up()`, deleted by `code` in `down()` — not a command call. This is the established precedent for
migration-owned reference data in this repository, not a deviation from it.

## 8. Employee number policy

- **PERMANENT**: `employee_number` is independent of National ID, supplied by the caller,
  globally unique **for all time**, and — once a relationship ends — the number is never
  reassigned to a different Person (§8/§10 of ADR-S09-001: "historically reserved").
- **CONTRACT**: `employee_number` always equals the Person's National ID. The caller never
  supplies it; the command derives it server-side from the Person row inside the same transaction,
  eliminating any possibility of client-supplied mismatch.

**Why a separate `employee_number_scheme` column instead of branching on `employment_type_id`:**
the "never reused" invariant needs a `UNIQUE` index that PostgreSQL can enforce declaratively. A
partial unique index's predicate must be an immutable expression — it cannot contain a subquery
against `ref.employment_types` to test "is this row's type currently the one whose code is
`permanent`." Recording the resolved scheme (`'PERMANENT'`/`'CONTRACT'`) on the row itself at
write time, from `employment_types.code`, gives a stable, indexable value:

```sql
ALTER TABLE "hr"."employment_relationships"
    ADD CONSTRAINT "employment_relationships_permanent_number_unique"
    UNIQUE ("employee_number")
    -- via a partial index (see migration): WHERE employee_number_scheme = 'PERMANENT'
```

Because `hr.employment_relationships` rows are never hard-deleted (§19 — no DELETE on Person or
Employment history), this partial-unique index is automatically a permanent reservation registry:
a PERMANENT number used by any relationship, ended or not, can never be reused by another Person.
No separate reservation table is needed — the append-only, no-hard-delete row *is* the registry
(§8's "durable employee-number registry/reservation mechanism").

CONTRACT numbers need no separate uniqueness constraint: since `hr.persons.national_id` is itself
globally unique, and CONTRACT `employee_number` is always exactly that Person's National ID,
CONTRACT numbers are automatically unique-per-person and may legitimately repeat across that same
Person's own multiple contract relationships over time (reappointment, §9) — which is correct, not
a collision.

Consistency between `employee_number_scheme` and the resolved `employment_type_id`/`code`, and
between `employee_number_scheme = 'CONTRACT'` and `employee_number = person.national_id`, cannot
be expressed as a `CHECK` (PostgreSQL `CHECK` constraints cannot reference another table). Both are
revalidated by `CreateEmploymentRelationship` inside the `AuditedCommandExecutor` transaction
(§13/§15 of ADR-S09-001) — read the employment type's `code` and the Person's `national_id` inside
the same transaction that inserts the row, never trust a precomputed value passed across a
request boundary.

## 9. Temporal integrity

- Business interval: `[effective_from, effective_to)`, `DATE` columns — frozen S02 convention,
  identical to every effective-dated table already shipped (`ref.employment_status_detail_
  behaviors`, `ref.specialty_cadre_category_mappings`, etc.).
- **No overlap, same Person, database-enforced**: reuses
  `App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints`
  (`noOverlapConstraintSql('hr.employment_relationships', ..., ['person_id'])` +
  `validPeriodCheckSql(...)`) exactly as every prior effective-dated table in this repository does
  — no new temporal-integrity mechanism is invented.
- **Maximum one active relationship at a time** falls out of the same `EXCLUDE` constraint: two
  open-ended (`effective_to IS NULL`) rows for the same `person_id` always overlap (both extend
  unbounded), so the database physically forbids a second concurrently-open relationship. "Current"
  employment is read as `end_knowledge_state = 'NOT_APPLICABLE' AND effective_to IS NULL` (see §12
  for why the `end_knowledge_state` qualifier matters, not `effective_to IS NULL` alone).
- Backdated and future relationships are validated against the full interval history via the same
  constraint — it is not a "current-state-only" check.
- `CreateEmploymentRelationship` and `EndEmploymentRelationship` both run inside
  `AuditedCommandExecutor`'s transaction and revalidate there (§13/§14 of ADR-S09-001); the
  `EXCLUDE`/unique violations are the actual enforcement, translated to domain exceptions (§11), not
  merely a pre-check.

## 10. Ending a relationship, terminal conditions

A narrow, explicit `EndEmploymentRelationship` command (person, employment relationship, expected
version, `effective_to`, `is_terminal: bool`) is the only supported ending path (§9/§15 of
ADR-S09-001 — "specify a narrow explicit command for ending the relationship without pulling the
future status-history domain into S09"):

- Sets `effective_to`, `end_knowledge_state = 'KNOWN'`, `ended_terminally = $is_terminal`.
- If `$is_terminal` is `true`, also sets `hr.persons.is_terminal = true` for that Person, in the
  same transaction. There is no command to reverse this — terminal is permanent by definition
  (§10 of ADR-S09-001), matching the project-wide "no un-delete/no un-terminal" discipline.
- `CreateEmploymentRelationship` rejects with `PersonIsTerminalException` (409) if the target
  Person's `is_terminal` is already `true` — this is the entire enforcement S09 owns for شهيد/وفاة
  (§10: "S09 should establish only the enforcement integration that is actually possible within
  its boundary").
- S09 does **not** model *which* detailed status (استقالة, متقاعد, شهيد, وفاة, …) caused the
  ending. `ref.employment_status_categories`/`_details`/`_behaviors` (S06) is the correct home for
  that rich, temporal status-history stream, and it ships with zero seeded detail rows today
  (`docs/reference-data-foundation-specification.md` §5.3/§27) — building on it now would be
  exactly the "full temporal status-history stream" §11/§21 of ADR-S09-001 forbid. `is_terminal`
  is the one bit of information from that future stream S09's own reappointment invariant
  structurally needs, and it is the only bit S09 captures. The handoff for a later stage: when the
  status-history stage is authorized, it is expected to record its detailed ending reason
  (including which `ref.employment_status_details` row applies) keyed to the same
  `employment_relationship_id`, and to derive/reconcile `ended_terminally` from
  `employment_status_detail_behaviors.is_terminal` (§11 of the S05 spec) rather than duplicating a
  second source of truth — noted here as a forward-compatibility expectation, not implemented.

## 11. Domain exceptions

`App\Modules\HumanResources\Domain\Exceptions`, each mapped in `bootstrap/app.php` exactly like
every prior module's exceptions (`dontReport` + `render` → generic JSON, no SQL/stack trace):

| Exception | HTTP | Source |
|---|---|---|
| `DuplicateNationalIdException` | 409 | `security.principals`-style pre-check + DB `UNIQUE` violation on `national_id`. |
| `OverlappingEmploymentRelationshipException` | 409 | `23P01` from the `EXCLUDE` constraint, translated via `PostgresErrorClassifier::isExclusionViolation()` — identical pattern to `OverlappingBehaviorPeriodException` (S05). |
| `DuplicatePermanentEmployeeNumberException` | 409 | Unique violation on the partial `employee_number` index. |
| `PersonIsTerminalException` | 409 | §10. |
| `EmploymentRelationshipAlreadyEndedException` | 409 | `EndEmploymentRelationship` called on a relationship whose `end_knowledge_state` is already `'KNOWN'` — including a genuine version-race loser (see below). |
| `InvalidEndDateException` | 422 (`errors.effective_to`) | `23514` from `employment_relationships_period_check`, translated via `PostgresErrorClassifier::isCheckViolation()` — a supplied `effective_to` not strictly after the relationship's own `effective_from`. Caught inside `EndEmploymentRelationship`'s scoped `UPDATE` rather than left to propagate as an unmapped 500; found during adversarial review (§26 of ADR-S09-001) because the command originally had no `try/catch` around that statement. |

**No separate `StaleVersionException` in this module.** Every other module's version-checked
update can fail for a reason unrelated to any single terminal state, so it needs a distinct
"someone else changed this" exception. `EndEmploymentRelationship` is HumanResources' only
version-checked mutation of an `EmploymentRelationship`, it is one-way
(`NOT_APPLICABLE`/`UNKNOWN_LEGACY` → `KNOWN`), and it is the only thing that ever changes that
row's `version` after creation. A concurrent version mismatch on this specific command therefore
*always* means "someone already ended this relationship" — a real race and "already ended" are
the same fact here, not two distinct outcomes. A separate `StaleVersionException` class would be
unreachable dead code for S09 v1; this is disclosed here rather than shipped as an untested
branch. (`Person.is_terminal` is set unconditionally inside the same transaction, without its own
version check, for the same reason — it is a one-way, idempotent-in-effect flag with no
concurrent-editor scenario in this module's own command set.)

## 12. End knowledge state

`end_knowledge_state`, per ADR-S09-001 §12, reserved for forward compatibility with a future legacy
migration:

- `NOT_APPLICABLE` — the relationship is current/open; no end applies. `effective_to IS NULL`.
- `KNOWN` — an explicit end date is recorded. `effective_to IS NOT NULL`.
- `UNKNOWN_LEGACY` — a relationship is known to have existed (and, unlike `NOT_APPLICABLE`, is
  understood not to be current) but its historical end boundary is not known. `effective_to IS
  NULL`, distinguished from `NOT_APPLICABLE` purely by this label. **S09's own commands never
  produce this value** — no import/migration pipeline exists yet (§20) — it exists solely so a
  later legacy-migration stage has a schema-level place to put this fact without an incompatible
  migration. Consistency `CHECK`:

```sql
(end_knowledge_state = 'KNOWN' AND effective_to IS NOT NULL)
OR (end_knowledge_state IN ('NOT_APPLICABLE', 'UNKNOWN_LEGACY') AND effective_to IS NULL)
```

  and `ended_terminally IS NOT NULL` only when `end_knowledge_state = 'KNOWN'`.

  Because the `EXCLUDE` constraint treats any `effective_to IS NULL` row as open-ended regardless
  of *why*, a Person can have at most one `NOT_APPLICABLE`-or-`UNKNOWN_LEGACY` open row at a time —
  which is correct: two simultaneously "still open" interpretations for the same Person can never
  coexist.

## 13. Reappointment

Reappointment is not a separate command. It is the natural consequence of the API shape (§19): a
new Employment Relationship is created via `POST /hr/persons/{person}/employment-relationships`
against an **existing** `person` route-bound resource. There is no endpoint that creates a Person
implicitly as a side effect of creating an Employment Relationship, and `CreatePerson` itself
rejects a duplicate `national_id` (§5/§11) — so the *only* way to attach a new relationship to the
same individual is to have already looked that individual up by National ID (§19) and reused the
existing `person_id`. This is the "structural invariant" ADR-S09-001 §9 asks for, achieved through
route/command shape rather than an internal find-or-create shortcut, and it is exactly why
`CreatePerson` and `CreateEmploymentRelationship` are kept as two distinct commands (as
ADR-S09-001 §15 itself lists them) rather than one merged operation.

A new relationship for a Person whose most recent relationship already ended (non-terminally) is
permitted by the temporal model with no special-casing: the new `[effective_from, effective_to)`
interval simply does not overlap the ended one, so the `EXCLUDE` constraint allows it. If the
Person's `is_terminal` is `true`, `CreateEmploymentRelationship` rejects it (§10).

## 14. Concurrency

Every mutation revalidates inside the `AuditedCommandExecutor` transaction (never a pre-check
alone):

- Two concurrent `CreateEmploymentRelationship` calls for the same Person with overlapping
  intervals: the database `EXCLUDE` constraint rejects the second committer with `23P01`, translated
  to `OverlappingEmploymentRelationshipException`.
- Two concurrent `CreateEmploymentRelationship` calls both proposing the same PERMANENT
  `employee_number` (for different Persons): the partial unique index rejects the second committer
  with a unique violation, translated to `DuplicatePermanentEmployeeNumberException`.
- Two concurrent `EndEmploymentRelationship` calls against the same row: the existing
  `version`-scoped `UPDATE ... WHERE id = ? AND version = ? AND end_knowledge_state != 'KNOWN'`
  pattern (identical shape to every prior module's optimistic-concurrency update) yields
  `EmploymentRelationshipAlreadyEndedException` for the loser — see §11 for why this collapses
  into that one exception rather than a separate `StaleVersionException`.
- A `CreateEmploymentRelationship` call racing a concurrent `EndEmploymentRelationship(isTerminal:
  true)` on the *same Person*: `CreateEmploymentRelationship::handle()` never trusts the `Person`
  instance the caller passed in (that copy was loaded by route-model-binding **before** the
  mutating transaction opened, and is therefore stale by construction). It instead re-fetches the
  Person by id with `lockForUpdate()` as its first statement inside the transaction, and checks
  `is_terminal` on that fresh, locked copy. This closes the race rather than merely narrowing it:
  the `SELECT ... FOR UPDATE` blocks until any concurrent `EndEmploymentRelationship` transaction
  holding that Person row's lock commits or rolls back, then observes its committed result. The
  same method re-fetches the `EmploymentType` fresh by id (rather than trusting the caller's
  already-loaded `code`) for the identical reason, though no command in S09 v1 can currently
  mutate `ref.employment_types.code`, so that half is a latent-discipline fix rather than a closed
  exploit today. Found during adversarial review (§26 of ADR-S09-001); the regression test uses two
  independent Eloquent instances of the same Person row — a single shared, self-mutating reference
  would not reproduce the cross-request staleness this fix addresses.

All of the above are covered by dedicated tests (§24) that actually race two connections or
reproduce the stale-read scenario directly, not merely assert the constraint exists.

## 15. Command boundary

`App\Modules\HumanResources\Application\Commands`:

- `CreatePerson(nationalId)`
- `CreateEmploymentRelationship(person, employmentTypeCode, effectiveFrom, employeeNumber = null)`
- `EndEmploymentRelationship(person, employmentRelationship, expectedVersion, effectiveTo, isTerminal)`

No `Transfer`/`Secondment`/`Assignment`/`Leave`/`ContractRenewal`/`EmploymentStatusChange`/
`ProfessionalHistory`/`JobHistory`/`PlacementHistory`/`WorkSchedule` command exists — none is
mechanically necessary to prove S09's own invariants (§15/§21 of ADR-S09-001). All three commands
run through `AuditedCommandExecutor`.

## 16. Authorization boundary — why S08 organizational scope is not consumed here

`ScopedAuthorizationChecker` (S08) answers "does Principal P have Permission X within
Organizational Unit U?" — it requires a target organizational unit. Neither `Person` nor
`EmploymentRelationship` carries an organizational-unit column in S09 (§17 of ADR-S09-001: "Do not
implement placement/original workplace/current workplace inside S09 merely to force an
organization link... Employment Relationship should not receive speculative organization
columns"). There is therefore **no organizational target for S09 operations to scope against**, and
S09 does not fake one merely to consume S08's machinery, and does not retrofit S07's endpoints.

S09 permissions are plain RBAC (WHAT only), gated by the existing `permission:` middleware exactly
as S03–S06 operated before S08 introduced the WHERE dimension. Organizational scoping becomes
meaningful once a later placement/workplace stage adds an organizational-unit column to Employment
Relationship (or a successor table) — at that point `ScopedAuthorizationChecker` is the intended
reuse point, unchanged. This boundary is disclosed explicitly rather than guessed at.

## 17. Permissions

New `App\Modules\HumanResources\Infrastructure\Authorization\HumanResourcesPermissionCatalog`
(own catalog, mirrors `OrganizationPermissionCatalog`/`ReferencePermissionCatalog` — never added to
Security's `PermissionCatalog`, which is reserved for Security's own domain per its own docblock):

- `hr.persons.view`
- `hr.persons.create`
- `hr.employment_relationships.view`
- `hr.employment_relationships.create`
- `hr.employment_relationships.end`

Seeded by a new migration, `..._seed_security_human_resources_permissions.php`, following the exact
shape of `2026_09_27_000002_seed_security_organization_permissions` /
`2026_09_26_000017_seed_security_reference_permissions`.

## 18. Audit

Every mutation goes through `AuditedCommandExecutor` (S04), audit codes:

- `hr.person.create`
- `hr.employment_relationship.create`
- `hr.employment_relationship.end`

Audit payload is an explicit allowlist (§18 of ADR-S09-001), never a raw request dump.
`national_id` is personally identifying HR data; the audit payload for `hr.person.create` stores
only the created Person's `id` as the target — it does not duplicate `national_id` into audit
metadata (the canonical value already lives on `hr.persons`, one place, not two). Where an audit
entry must disambiguate which National ID a lookup or duplicate-rejection concerned (needed for
`DuplicateNationalIdException`'s own audit trail on the rejected attempt), only the fact of a
rejection and the existing Person's `id` are recorded — never the submitted raw value a second
time.

## 19. API

`/api/v1/hr`, `web` middleware group (session auth, matching every authenticated route in this
repository), `auth:web`, `principal.active`, `resolve.context`:

| Method | Path | Command/Query | Permission |
|---|---|---|---|
| `POST` | `/hr/persons` | `CreatePerson` | `hr.persons.create` |
| `GET` | `/hr/persons/lookup` (`?national_id=`) | `FindPersonByNationalId` | `hr.persons.view` |
| `GET` | `/hr/persons/{person}` | `GetPerson` | `hr.persons.view` |
| `GET` | `/hr/persons/{person}/employment-relationships` | `ListEmploymentRelationshipsForPerson` | `hr.employment_relationships.view` |
| `POST` | `/hr/persons/{person}/employment-relationships` | `CreateEmploymentRelationship` | `hr.employment_relationships.create` |
| `POST` | `/hr/persons/{person}/employment-relationships/{employmentRelationship}/end` | `EndEmploymentRelationship` | `hr.employment_relationships.end` |

`/hr/persons/lookup` is registered before no conflicting wildcard exists at that segment depth (no
`{person}` route shares the literal `lookup` segment position, so no S07/S08-style ordering
hazard applies here — confirmed during implementation).

Status codes: `401` unauthenticated, `403` unauthorized, `404` unknown `person`/
`employmentRelationship` or route-model-binding miss, `409` domain conflict (duplicate National ID,
overlap, duplicate permanent number, terminal Person, already-ended relationship, stale version),
`422` validation failure (missing/invalid `employment_type_code`, `employee_number` present for
`CONTRACT`, missing for `PERMANENT`, invalid dates). No generic `PATCH`. No `DELETE` anywhere in
this resource — Person and Employment Relationship history is never removed (§19/§21 of
ADR-S09-001). National-ID lookup returns only that Person's own record — no arbitrary search or
filtering surface (IDOR prevention, §19).

## 20. Legacy/migration boundary

No Excel import pipeline is built (§20). The schema supports, without modification, later
population of: a known current/baseline Person and Employment Relationship (ordinary
`CreatePerson`/`CreateEmploymentRelationship`); an unknown legacy historical boundary
(`end_knowledge_state = 'UNKNOWN_LEGACY'`, reserved in §12); provenance requirements (deferred —
no provenance column is added in S09, since none is evidenced; a future migration stage is expected
to add one rather than S09 guessing at its shape).

## 21. Migrations (in order)

1. `..._create_hr_persons_table.php`
2. `..._create_hr_employment_relationships_table.php` (table, FKs, both `CHECK`s, the partial
   unique index, the `EXCLUDE` constraint via `TemporalConstraints`)
3. `..._seed_ref_employment_types_permanent_and_contract.php` (direct `DB::table` insert,
   mirroring `2026_09_26_000018_seed_ref_baseline_values`, §7)
4. `..._seed_security_human_resources_permissions.php`

Continues the existing per-day timestamp sequence (last used: `2026_09_28_000002`); S09 migrations
use `2026_09_29_0000xx`.

## 22. Review gate (§23 of ADR-S09-001)

- **P01 Person aggregate boundary?** `hr.persons`: identity only (`id`, `national_id`,
  `is_terminal`). No profile/demographic data (§4).
- **P02 Employment aggregate boundary?** `hr.employment_relationships`: one row per relationship
  interval; no organizational, status-detail, or history-stream data (§6).
- **P03 National-ID canonicalization?** `trim()` only (§5).
- **P04 National-ID DB uniqueness?** `UNIQUE` constraint on `hr.persons.national_id` (§5).
- **P05 Person duplicate prevention?** DB uniqueness + API shape structurally prevents creating a
  relationship without an existing `person_id` (§5/§13).
- **P06 relationship temporal representation?** `[effective_from, effective_to)`, `DATE` (§9).
- **P07 overlap prevention?** `EXCLUDE USING gist`, keyed on `person_id`, via
  `TemporalConstraints` (§9).
- **P08 open interval representation?** `effective_to IS NULL` (§9/§12).
- **P09 end knowledge-state consistency?** `CHECK` tying `end_knowledge_state` to
  `effective_to`/`ended_terminally` nullability (§12).
- **P10 permanent employee-number reservation?** Partial `UNIQUE` index over all rows,
  `WHERE employee_number_scheme = 'PERMANENT'`; no hard delete ever removes a row, so this is
  permanent (§8).
- **P11 contract number = National ID enforcement?** Application-layer, revalidated inside the
  mutation transaction (cross-table, cannot be a `CHECK`) (§8).
- **P12 historical number non-reuse?** Same partial unique index as P10 — it is unconditional on
  relationship end-state (§8).
- **P13 reappointment?** New relationship row for the same, looked-up `person_id`; blocked only by
  `is_terminal` (§10/§13).
- **P14 concurrency?** `EXCLUDE`/unique-index/`version` races all resolve at the database, tested
  with real concurrent connections (§14/§24).
- **P15 status-history boundary?** Not implemented; only `ended_terminally` (a single bit) crosses
  the boundary, with the handoff documented (§10).
- **P16 terminal-state boundary?** `hr.persons.is_terminal`, set once, irreversible, enforced on
  `CreateEmploymentRelationship` (§10).
- **P17 reference reuse?** `ref.employment_types` reused for PERMANENT/CONTRACT; `ref.contract_
  types`/`employment_categories` deliberately left untouched (§7).
- **P18 authorization?** Plain RBAC; no scope integration (§16/§17).
- **P19 S08 scope boundary?** Explicitly not consumed; rationale documented (§16).
- **P20 audit PII minimization?** No duplication of `national_id` into audit payload (§18).
- **P21 API/IDOR?** Lookup returns only the matched Person; no generic search (§19).
- **P22 migration readiness?** `end_knowledge_state`/`UNKNOWN_LEGACY` reserved; no provenance
  column guessed at (§20).
- **P23 no fabricated history?** No seed rows for Person/Employment; only the two evidenced
  `ref.employment_types` rows are seeded (§7).
- **P24 S10+ exclusions?** §2/§21 — enforced by a boundary-audit test (§24) that greps for
  forbidden concepts, mirroring S08's own boundary-discipline tests.

No consequential policy remains unresolved. **PASS — proceeding to implementation.**

## 23. Tests (minimum, per §25 of ADR-S09-001)

Person UUID identity; National-ID uniqueness (app + concurrent DB-level); National-ID lookup;
duplicate-Person rejection; reappointment reuse of the same Person; PERMANENT number independence
from National ID; CONTRACT number equals National ID; PERMANENT number non-reuse after a
relationship ends; one active relationship maximum; no temporal overlap (current, backdated,
future); concurrent overlap rejected (real race); concurrent PERMANENT-number reuse rejected (real
race); end-knowledge-state DB consistency; relationship ending; reappointment after an ended
relationship; terminal Person blocks a new relationship; audit generated for all three commands;
no PII duplicated in audit payload; RBAC enforcement (401/403 per route); IDOR protection; migration
rollback/reapply; PostgreSQL constraint tests (each `CHECK`/`EXCLUDE`/unique index exercised
directly); full S01–S08 regression; boundary-audit test for §2/§21 exclusions.

## 24. Deferred items / open questions (disclosed, not blocking)

1. Person name and other demographic/profile attributes — no evidence found for their shape;
   expected to be a follow-up authorization (§4).
2. Provenance/data-lineage columns for legacy migration — deferred to the future import stage
   (§20).
3. Reconciling `ended_terminally` with the future `ref.employment_status_details`/`_behaviors`
   stream once that stream gains seeded rows — noted as a forward-compatibility expectation, not
   implemented (§10).
4. `ref.contract_types`/`ref.employment_categories` remain untouched, structure-only — any reuse of
   them needs its own evidenced authorization, not inferred from S09 (§7).

None of these represents an unresolved *consequential* policy for S09's own boundary; each is a
named, explicit handoff to a future stage.
