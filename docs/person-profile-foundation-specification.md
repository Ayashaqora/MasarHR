# S24 — Person Profile Foundation / تأسيس الملف الشخصي للموظف

**RECONSTRUCTED STAGE TITLE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

This document records the specification and ADR for **ADR-S24-001 — Person Profile Foundation**. The
Executor (Claude Code Cloud) wrote it under the Architecture Authority's "S24 Person Profile
Foundation — Full Controlled Execution Authorization". That authorization fixed every decision
recorded below; this document records them and invents none.

- **Baseline:** `origin/develop @ 1ec7277` (S23 Person Qualification Foundation).
- **Sources:** the two original analysis sources stay conceptually separate. No merged master analysis
  exists, and this stage creates none.

## §S24.1 Reconstruction disclosure

The historical roadmap wording for S24 was not recovered. The Architecture Authority supplied the
title above as a reconstruction, following the post-S23 domain coverage audit. It is used only as a
reconstruction.

## §S24.2 Discovery

Findings at `1ec7277`:

| # | Question | Finding |
|---|---|---|
| Q1 | Where does Person identity live? | `hr.persons` (S09): `id`, `national_id` (unique, trimmed), `is_terminal`, `version`, timestamps. S09 §4 deliberately left it without name or demographic columns. `ScopeBoundaryTest` asserted this. |
| Q2 | Which catalogs exist for gender and marital status? | `ref.genders` (seeded `male`/`female`) and `ref.marital_statuses` (seeded `single`/`married`/`divorced`/`widowed`). Both use the S05 simple-reference shape, are administered through S05 routes, and deactivate history-safely. |
| Q3 | Does an alias resolver exist? | Yes: `ResolveMaritalStatusByArabicSourceValue` over `ref.marital_status_aliases` (S05 CORRECTIVE-01). It is read-only and returns `null` for unresolved values. There is no gender alias resolver. |
| Q4 | Is any profile value stored anywhere today? | No. No name, gender, marital status, birth date or birth place exists in any table, read model or staging area. |
| Q5 | How are Persons created? | `CreatePerson` command → `POST /api/v1/hr/persons` (`hr.persons.create`), audited as `hr.person.create`. |
| Q6 | Is there any Person update path? | No. S09 exposes `show`/`lookup`/`store` only. |
| Q7 | What does Employee 360 show? | The header shows national ID, employee number, scheme, relationship state, status and workplace. It shows no name, and states this as a disclosed S09 gap. |
| Q8 | Is a legacy start-work date present? | It is out of S24. Relationship dates (`effective_from`) are untouched. |

## §S24.3 Gap matrix

| Capability | Before S24 | After S24 |
|---|---|---|
| Arabic full name | none | `hr.persons.full_name_ar` |
| Gender | catalog only | `hr.persons.gender_id` → `ref.genders` |
| Marital status | catalog + alias resolver only | `hr.persons.marital_status_id` → `ref.marital_statuses` |
| Birth date | none | `hr.persons.birth_date` DATE |
| Birth place | none | `hr.persons.birth_place` free text |
| Profile update | none | `UpdatePersonProfile` + `POST /hr/persons/{person}/update-profile` |
| Employee 360 name | disclosed gap | shown from `full_name_ar` |

## §S24.4 ADR-S24-001 — decisions (as authorized)

1. **Ownership.** The profile belongs to the **Person**, never to an Employment Relationship. It
   survives the end of a relationship, reappointment and every employment lifecycle event.
2. **Name.** A single `full_name_ar`:
   - no name parts and no English name;
   - required for new Persons, `NULL` allowed for legacy Persons;
   - never a placeholder name.
3. **Gender.** `gender_id` → `ref.genders`:
   - required for new Persons, nullable for legacy Persons;
   - no history and no inference.
4. **Marital status.** `marital_status_id` → `ref.marital_statuses`:
   - a current snapshot, with no history;
   - required for new Persons, nullable for legacy Persons;
   - independent of gender.
5. **Birth date.** `birth_date` DATE:
   - required for new Persons, nullable for legacy Persons;
   - no fake, inferred or `01-01` dates, and no conversion from an age;
   - no knowledge-state column;
   - the future is rejected, and no age policy is applied.
6. **Birth place.** `birth_place` is optional nullable free text:
   - trimmed;
   - no geographic catalog, inference or normalization.
7. **Start-work date.** Out of S24.
8. **Temporality.** These are current mutable attributes. There are no history tables; changes are
   captured by the S04 audit.
9. **Identity.** National ID and employee-number rules stay exactly as in S09.
10. **Database.** `hr.persons` is extended:
    - all five columns are nullable at the database level;
    - "required for new" is an application invariant;
    - references are FKs to the catalogs;
    - there is no profile table, name-part table, history table or birth-place catalog.
11. **Commands.**
    - `CreatePerson` requires the first four fields, whose references must exist and be active.
    - `UpdatePersonProfile` is explicit, touches only the five fields, and is atomic and audited.
    - There is no generic PATCH.
12. **Security.** Writes need an explicit HR write permission. Reads use the existing Person
    permission. `reference.*` alone is insufficient. There is no org-scope redesign.
13. **Audit.** Records the stable Person ID, the changed field names and the old/new reference IDs.
    It records no PII values and no whole-profile dump. A rejected write leaves no mutation and no
    success audit.
14. **Reporting.** Age is derived at report time; there is no age column. Unknown values are never
    counted as a canonical category.
15. **Import.** Documentation only (§S24.19).

## §S24.5 Ownership and lifecycle independence

All five attributes are columns of `hr.persons`. No employment table references them, and no
employment command reads or writes them. Ending, reappointing, transferring or seconding an
employment relationship leaves the profile untouched. `UpdatePersonProfile` cannot touch any
employment row: `PersonProfileFoundationTest::test_update_cannot_mutate_national_id_terminal_flag_or_employment_data`
covers this.

## §S24.6 Data model

Migration `2026_10_10_000001_add_profile_columns_to_hr_persons_table` adds five columns to
`hr.persons`:

| Column | Type | Null | Constraint |
|---|---|---|---|
| `full_name_ar` | varchar(255) | yes | CHECK `persons_full_name_ar_not_blank_check`: `full_name_ar IS NULL OR btrim(full_name_ar) <> ''` |
| `gender_id` | uuid | yes | FK `persons_gender_fk` → `ref.genders(id)` ON DELETE RESTRICT; index `persons_gender_id_index` |
| `marital_status_id` | uuid | yes | FK `persons_marital_status_fk` → `ref.marital_statuses(id)` ON DELETE RESTRICT; index `persons_marital_status_id_index` |
| `birth_date` | date | yes | — (the "not in the future" rule is application-level, because `CURRENT_DATE` in a CHECK is not immutable) |
| `birth_place` | varchar(255) | yes | CHECK `persons_birth_place_not_blank_check`: `birth_place IS NULL OR btrim(birth_place) <> ''` |

- **No other columns.** There is no age, name part, English name, knowledge state, start-work
  date, specialty or experience column.
- **No new tables.**

Migration `2026_10_10_000002_seed_security_person_profile_permission` seeds
`hr.persons.update_profile` (module `human_resources`).

Both migrations have a symmetric `down()` that drops only S24's objects.

## §S24.7 Command — `CreatePerson` (extended)

`handle(nationalId, fullNameAr, Gender, MaritalStatus, birthDate, ?birthPlace)`:

- `national_id` handling is exactly as S09: trimmed, and a unique violation raises
  `DuplicateNationalIdException` (409).
- `full_name_ar` is trimmed. A blank value is rejected (422, field `full_name_ar`).
- The gender and marital status are re-fetched and must be **active** (422 on `gender_id` /
  `marital_status_id`).
- `birth_date` must not be in the future (422, field `birth_date`).
- `birth_place` is optional; if supplied it is trimmed, and a blank value is rejected (422).

## §S24.8 Command — `UpdatePersonProfile`

`handle(Person, expectedVersion, changes)`, where `changes` is any non-empty subset of
`full_name_ar`, `gender`, `marital_status`, `birth_date` and `birth_place`.

- Any other key, such as `national_id`, `is_terminal` or `age`, raises `InvalidArgumentException`.
- The HTTP layer validates only the five fields, so other payload keys are silently ignored.
- Unsupplied fields are left untouched, so a legacy `NULL` stays `NULL`.
- Required fields (name, gender, marital status, birth date) can be set or changed, but never
  cleared. `birth_place` can be cleared with `null`.
- **Order (CA-S24-01).** The steps run in this order:
  1. Load the current Person.
  2. Check `expected_version`. A mismatch raises `PersonStaleVersionException` (409), even when
     the submitted values equal the stored ones.
  3. Validate and normalize every supplied value using only the existing S24 rules (trim;
     active-reference, not-blank and not-future checks).
  4. Compare the results with the stored values.
- **No-op (CA-S24-01).** When every supplied value equals the stored value, the request is a
  no-op:
  - no `UPDATE`, no `version` increment, no `updated_at` change;
  - no audit entry and no side effect;
  - the API returns 200 with the unchanged `PersonResource`.
  Examples: a name that differs only by surrounding whitespace, the same gender or marital-status
  ID, the same birth date, and `birth_place` null → null.
- **Actual change.** Otherwise a single `UPDATE … WHERE id = ? AND version = ?` writes only the
  genuinely changed columns and increments `version` once.
  - Every Person mutation increments `version`, so this guard still rejects any change made
    concurrently after the pre-check.
  - A rejected or stale request mutates nothing.
- The update is audited as `hr.person.profile.update` (§S24.14).
- Only a **newly assigned** reference must be active. An unchanged reference that has since been
  deactivated does not block updates to other fields. This is the deactivation-safety rule.

## §S24.9 Reference behavior

- Missing reference IDs return 404 before the command runs.
- Inactive references are rejected with 422 on assignment.
- Deactivating a value that Persons already carry is history-safe: the stored FK and the read model
  are unchanged.
- The RESTRICT FKs forbid deleting a referenced catalog row.

## §S24.10 Validation rules (complete list)

- **Name.** Not blank after trim, maximum 255. There is no word-count, name-part or script rule.
- **Birth date.** A valid date, not in the future. There is no minimum or maximum age.
- **Birth place.** Maximum 255, trimmed, not blank when supplied. There is no normalization.
- **Framework normalization over HTTP.** The global `TrimStrings`/`ConvertEmptyStringsToNull`
  middleware turns a whitespace-only string into `null`:
  - a whitespace-only `full_name_ar` fails `required` (422);
  - a whitespace-only `birth_place` becomes `null` ("not supplied / unknown"), never a stored blank.

## §S24.11 Legacy compatibility

- All five columns are nullable. Pre-S24 rows migrate with every profile value `NULL`, and no value
  is back-filled or fabricated (`MigrationLifecycleTest::test_s24_migrations_roll_back_and_reapply_cleanly_with_legacy_persons`).
- Legacy rows remain readable. They can be completed one field at a time through
  `UpdatePersonProfile`.

## §S24.12 Read model

`PersonResource` adds `full_name_ar`, `gender_id`, `marital_status_id`, `birth_date` (`YYYY-MM-DD`)
and `birth_place`. Legacy `NULL`s are returned as `null`. No derived age and no resolved label is
added.

## §S24.13 Security

| Action | Permission |
|---|---|
| `GET /hr/persons/{person}`, `GET /hr/persons/lookup` | `hr.persons.view` (unchanged) |
| `POST /hr/persons` | `hr.persons.create` (unchanged) |
| `POST /hr/persons/{person}/update-profile` | **`hr.persons.update_profile`** (new) |

- Plain RBAC, as in the S09 Person routes. There is no organizational-scope redesign.
- `reference.*` permissions alone grant nothing here.

## §S24.14 Audit

| Action | changes | metadata |
|---|---|---|
| `hr.person.create` | `{gender_id, marital_status_id}` | `{profile_fields_set: [field names]}` |
| `hr.person.profile.update` | `{gender_id: {from, to}}` / `{marital_status_id: {from, to}}`, only when the reference changed | `{changed_fields: [field names]}` |

- Both actions use target `hr_person` / Person ID.
- The name, birth date, birth place and national ID values are **never** written to the audit
  payload.
- A request that fails validation, reference checks or version checks writes no audit entry.
- A semantic no-op writes no audit entry (CA-S24-01). For an actual update, `changed_fields` lists
  only the fields that genuinely changed.

## §S24.15 API

- `POST /api/v1/hr/persons` requires `national_id`, `full_name_ar`, `gender_id`,
  `marital_status_id` and `birth_date`; `birth_place` is optional. It returns 201.
- `POST /api/v1/hr/persons/{person}/update-profile` takes `expected_version` plus at least one of
  the five fields, and returns 200 with the updated `PersonResource`.
- There is no `PATCH`/`PUT` on `/hr/persons/{person}`; both return 405.

**Error codes:**

| Status | Cause |
|---|---|
| 401 | unauthenticated |
| 403 | missing permission |
| 404 | unknown Person or reference |
| 409 | duplicate national ID, or stale version |
| 422 | validation, inactive reference, blank value, future birth date, or empty update (`errors.profile`) |

## §S24.16 Reporting compatibility (no reporting built)

- Age is computed at query time, for example `age(<as-of date>, birth_date)`. A `NULL` birth date
  yields an unknown age, never 0.
- Group-bys over gender or marital status must treat `NULL` as "unknown", never as a canonical
  category.
- `PersonProfileFoundationTest::test_age_is_derivable_at_query_time_and_unknowns_are_never_a_canonical_category`
  demonstrates both.

## §S24.17 Employee 360

The header now shows **الاسم الكامل** (`full_name_ar`), or "غير مسجَّل / Not recorded" for a legacy
`NULL`.

Gender, marital status, birth date and birth place are **deferred**. Displaying gender and marital
status labels would need new reference-catalog fetches, and those depend on `reference.*` read
permissions an HR user may not hold. Showing birth date or birth place while skipping them would
break the authorized display priority. No redesign and no mock data.

## §S24.18 Out of scope

- start-work date;
- experience, specialty, supervisory, promotion, job description, partial secondment;
- automation, reporting and import engines;
- new seeds;
- name parts, English name, geographic catalog, demographic history.

## §S24.19 Import compatibility (documentation only)

A future import will:

- trim `full_name_ar` and never split it;
- resolve marital status through `ResolveMaritalStatusByArabicSourceValue`, where an unresolved
  value stays `NULL` and is flagged, never guessed;
- map gender only through an explicit, reviewed mapping, never inferred from a name or from a
  gendered marital-status spelling;
- import `birth_date` only from a full, valid date, never from an age or a year alone (no `01-01`);
- copy `birth_place` trimmed and verbatim;
- write legacy gaps as `NULL`.

A record created through `CreatePerson` must satisfy the four required fields. Incomplete legacy
rows need a separate, future legacy-load path. That path is not designed here.

## §S24.20 Tests

- `PersonProfileFoundationTest` (36 tests, including five CA-S24-01 no-op tests): create validation; each required field; blank/trim;
  future date; national-ID regression; references (missing → 404, inactive → 422,
  deactivation-safe, RESTRICT delete); gender/marital independence; legacy nulls and completion;
  database CHECKs; single- and multi-field atomic update; rejected-update integrity; clear rules;
  empty update; stale version; no mutation of national ID or employment data; command key
  allow-list; security (401/403, `reference.*` insufficient, view vs update_profile); create and
  update audit (reference IDs, field names, no PII, legacy `from: null`); schema boundaries; no
  PATCH/PUT; reporting derivation.
- `MigrationLifecycleTest`: S24 rollback and reapply with a legacy Person; S09 rollback ordering;
  permission counts.
- `ScopeBoundaryTest`: `hr.persons` has exactly the S09 identity columns plus the five S24 columns.
- `ApiEndpointsTest`: permission inventory.
- Frontend: `Employee360Page.test.tsx` (name shown; legacy "not recorded").

## §S24.21 Deferred

- Employee 360 display of gender, marital status, birth date and birth place.
- Import engine and legacy-load path.
- Reporting.
- Every §S24.18 item.
