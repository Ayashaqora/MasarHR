# S25 — Specialty Catalog Administration Foundation / تأسيس إدارة كتالوج التخصصات

**RECONSTRUCTED STAGE TITLE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

This document records the specification and ADR for **ADR-S25-001 — Specialty Catalog
Administration Foundation**. The Executor (Claude Code Cloud) wrote it under the Architecture
Authority's "S25 Specialty Catalog Administration Foundation — Full Controlled Execution
Authorization". That authorization fixed every decision recorded below; this document records them
and invents none.

- **Baseline:** `origin/develop @ e3cb5080be1c400a20905a1e4332009768d59591` (tag
  `s24-person-profile-foundation`).
- **Sources:** the two original analysis sources stay conceptually separate. No merged master analysis
  exists, and this stage creates none.

## §S25.1 Reconstruction disclosure

The historical roadmap wording for S25 was not recovered. The Architecture Authority supplied the
title above as a reconstruction and it is used only as such.

## §S25.2 Source and repository evidence

| # | Question | Finding at `e3cb508` |
|---|---|---|
| Q1 | Does a specialty catalog exist? | Yes: `ref.specialties` (`2026_09_26_000011`). It uses the S05 common simple-reference shape (§S25.6) and the `Specialty` Eloquent model. |
| Q2 | Is it seeded? | No — 0 rows. S05 §5.3 marked it "DEFINED STRUCTURE / VALUES DEFERRED". S06 §12.2 seeded no mapping because it would have meant inventing specialty rows. |
| Q3 | What references it? | Only `ref.specialty_cadre_category_mappings.specialty_id` (S06 §12.2): RESTRICT FK, temporal, GiST-exclusive, with the resolver `ResolveSpecialtyCadreCategoryAsOf`. The resolver returns `null` (UNRESOLVED) for an unmapped specialty, matching Report 1's rule "never silently map unknown values to other". No `hr.*` table references it. |
| Q4 | Which routes exist? | Only the S06 sub-resource `GET`/`POST /reference/specialties/{specialty}/cadre-category-mappings`. There is no route for the catalog's own rows. |
| Q5 | Why was it excluded from S13? | The S13 authorization did not name it (S13 spec §5/§7): "not named by this authorization, zero rows … left untouched". No structural or semantic reason was recorded. Its model and migration are byte-for-byte the S13 shape. |
| Q6 | What does S13 administration consist of? | 1. The `$simpleFamilies` registry in `routes/api.php`: index/show (`reference.view`); store/PATCH metadata/activate/deactivate (`reference.manage`); no DELETE. 2. Four abstract command bases (`AbstractCreate…`, `AbstractActivate…`, `AbstractDeactivate…`, `AbstractUpdate…Metadata`). 3. `SimpleReferenceValueResource`. 4. `DuplicateReferenceCodeException` (422) and `StaleVersionException` (409). |
| Q7 | Are permissions catalog-specific? | No. The two module-wide permissions `reference.view` / `reference.manage` apply to every catalog. |
| Q8 | What is the semantic meaning of specialty? | Frozen architecture records it as the professional/cadre specialty feeding the S06 monthly-cadre mapping and Report 1 (ADR-S23-DECISIONS row 7: "`ref.specialties` … keeps its professional/cadre semantics"; academic specialty is out of scope). S25 neither narrows nor broadens this, and administration is meaning-neutral. |
| Q9 | Is there a frontend reference-admin surface? | No. The frontend has no reference-catalog administration UI, and no Employee 360 specialty field. |

**Conclusion.** No consequential semantic difference blocks the S13 pattern, so no architecture
decision is required.

## §S25.3 ADR-S25-001 — decisions (as authorized)

- **A. Existing catalog.** `ref.specialties` is the canonical specialty catalog and is reused. No
  `academic_specialties`, `professional_specialties`, `employee_specialties` or
  `person_specialties` table is created.
- **B. Scope.** S25 administers the existing catalog only. It does not assign specialties to
  employees.
- **C. Meaning.** The meaning is not redefined beyond the frozen evidence (§S25.2 Q8).
- **D. Data.** No specialty value is seeded, and no official list is fabricated.
- **E. Unknown values.** No generic "Other". Unknown or unmapped values stay unresolved.
- **F. History.** Specialties follow the standard reference lifecycle:
  - no hard delete;
  - deactivation preserves references and history.
- **G. Employee ownership.** Out of S25. S25 does not decide:
  - Person vs Employment Relationship ownership;
  - temporality or cardinality;
  - primary, current or multiple specialty.

## §S25.4 Catalog boundary

S25 adds exactly one family to the existing S13 registry:

| Table | Model (existing) | Route segment | Controller | Route parameter |
|---|---|---|---|---|
| `ref.specialties` | `Specialty` | `specialties` | `SpecialtyController` (new) | `specialty` |

It adds four one-line command subclasses, each hardcoding `modelClass()`: `CreateSpecialty`,
`UpdateSpecialtyMetadata`, `ActivateSpecialty` and `DeactivateSpecialty`.

It adds:
- no new abstraction;
- no new resource or exception;
- no new permission;
- no migration.

The S06 sub-resource `/specialties/{specialty}/cadre-category-mappings` is unchanged. It coexists
with the new routes because the path shapes differ.

## §S25.5 Administration behavior

| Operation | Route | Permission | Result |
|---|---|---|---|
| List | `GET /api/v1/reference/specialties` | `reference.view` | Paginated (50), ordered by `display_order`, then `code`. Inactive rows stay listed. |
| Show | `GET /api/v1/reference/specialties/{specialty}` | `reference.view` | Inactive rows stay readable. |
| Create | `POST /api/v1/reference/specialties` | `reference.manage` | 201 |
| Update metadata | `PATCH /api/v1/reference/specialties/{specialty}` | `reference.manage` | Changes `name_ar`, `name_en` and `display_order` only, using `expected_version`. |
| Activate | `POST …/{specialty}/activate` | `reference.manage` | Uses `expected_version`. |
| Deactivate | `POST …/{specialty}/deactivate` | `reference.manage` | Uses `expected_version`. |
| Hard delete | none | — | 405 |

Validation is exactly the established S13 rule set, with no specialty-specific rule:

- `code`: required, max 64, `^[a-z0-9_]+$`, unique (duplicate → 422).
- `code` and `is_active` are never changed by an update.
- `name_ar`: required, max 255. Blank is converted to null by the framework and then fails
  `required`.
- `name_en`: nullable, max 255.
- `display_order`: nullable integer 0–32767, default 0.
- Stale `expected_version` → 409.
- Unknown ID → 404.

## §S25.6 Schema decision

**Zero schema changes.** `ref.specialties` already has:

- a UUIDv7 primary key;
- `code` varchar(64), `UNIQUE specialties_code_unique`;
- `name_ar` (required) and `name_en` (nullable);
- `is_active` (default true) and `display_order` smallint;
- `version` (`CHECK version >= 1`);
- `created_at` / `updated_at` (timestamptz).

This is identical to every S13-administered catalog.

## §S25.7 Security

- Reads require `reference.view` and writes require `reference.manage`, the shared S05/S13
  permissions.
- No HR permission grants specialty administration, and a principal holding every `hr.*`
  permission gets 403.
- No Person mutation permission is involved.
- No organizational-scope change is made.

## §S25.8 Audit

All writes go through `AuditedCommandExecutor` with target type `reference_specialty`:

| Action | changes |
|---|---|
| `reference.specialty.create` | `{code, name_ar, name_en, display_order}` |
| `reference.specialty.metadata.update` | `{name_ar, name_en, display_order}` as `{from, to}` |
| `reference.specialty.activate` | `{is_active: {from: false, to: true}}` |
| `reference.specialty.deactivate` | `{is_active: {from: true, to: false}}` |

- The catalog contains no employee data, so the audit carries no PII.
- A rejected write (validation, duplicate, stale version, permission) mutates nothing and writes no
  success audit.

## §S25.9 Reporting mapping compatibility (S06)

- Creating a specialty creates **no** cadre mapping and no cadre category.
- An unmapped specialty stays UNRESOLVED (`null`), never "Other".
- An administered specialty participates in the existing mapping through the existing S06 command
  and route. Those remain governed by `reference.manage` and are unchanged.
- Deactivating a specialty does not delete or alter its mapping periods, and as-of resolution is
  unchanged. The mapping command performs no `is_active` check on the specialty (S06 §12.2), and
  S25 does not change that.
- The RESTRICT FK forbids deleting a mapped specialty at the database level.
- Report 1 is not implemented, and no specialty is classified into a cadre automatically.

## §S25.10 Import compatibility (documentation only)

A future import will:

- resolve a known source specialty to the canonical approved `ref.specialties` row through an
  explicit, reviewed resolution rule (not designed here);
- route an unknown source specialty to an unresolved / data-quality workflow;
- never create a specialty silently during employee import, unless a future explicit import policy
  authorizes it;
- never map an unknown value to "Other" automatically.

## §S25.11 Person / Employment boundary

S25 introduces none of the following:

- `person_specialty_id` or `employment_specialty_id`;
- specialty periods or specialty history;
- a primary or current specialty;
- any qualification–specialty linkage.

Assigning a specialty to an employee requires the next architecture decision.

## §S25.12 Employee 360

There is no specialty field on Employee 360 in S25, and no frontend change. Catalog data is never
presented as an employee fact.

## §S25.13 Tests

- **`tests/Feature/Reference/SpecialtyCatalogAdministrationFoundationTest.php`** (24 tests)
  covers:
  - catalog: unseeded, list, create shape, optional fields, duplicate, validation, metadata-only
    update, stale version, activate/deactivate/readable, no delete, 404;
  - security: 401, 403, viewer read-only, HR permissions insufficient, `reference.manage`
    succeeds;
  - audit: all four actions, rejected writes;
  - S06 mapping: no auto-map, participation, survival of deactivation, RESTRICT FK, own
    permission;
  - scope guards: no specialty columns or tables beyond S06, no seed, no "Other", no reporting
    tables, no HR route, no frontend specialty.
- **`ScopeBoundaryTest` (Reference):** the structure-only guard now names only `EmploymentType`,
  and a new test asserts the Specialty administration files exist.
- **`CatalogAdministrationFoundationTest`:** the S13 "specialties remain unadministered" guard
  now covers `employment-types` only, and records S25's explicit authorization.

## §S25.14 Adversarial review

| Challenge | Result |
|---|---|
| Specialty treated as qualification | No `hr.person_qualifications` column or link. S23 is unchanged. |
| Academic/professional split invented | No new table or field. The frozen meaning (§S25.2 Q8) is not restated as a new rule. |
| Seeding an unapproved list | No seed; a test asserts zero rows and no seed migration. |
| "Other" created | None exists. Unmapped values resolve to `null`. |
| Employee-assignment or ownership leak | No `hr.*` specialty column or route, and no frontend reference (tests). |
| Temporal-history leak | No specialty history table. The only temporal object is the existing S06 mapping. |
| Automatic cadre mapping | None (test). |
| Destructive deactivation | Mappings and resolution survive (test). |
| Hard delete | No route (405). The RESTRICT FK blocks database-level deletion of a mapped row (tests). |
| S06 regression | `SpecialtyCadreCategoryMappingTest` is unchanged and green. |
| Permission overreach | Only the shared reference permissions are used; HR permissions are insufficient (test). |
| Generic framework redesign | None: one registry entry plus the S13 subclasses. |
| S26 leak | None. |

## §S25.15 Deferred

- Assigning a specialty to a Person or Employment Relationship, including ownership, temporality,
  cardinality and primary specialty.
- Any academic-specialty concept.
- Specialty import resolution and the data-quality workflow.
- Report 1 and specialty reporting.
- Employee 360 specialty display and any reference-admin frontend.
- `ref.employment_types` administration, which remains the last S13-excluded structure-only family.
