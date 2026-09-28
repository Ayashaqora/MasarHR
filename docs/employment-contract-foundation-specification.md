# S21 — Employment Contract Foundation (تأسيس عقود التوظيف)

**RECONSTRUCTED TITLE — HISTORICAL ROADMAP WORDING NOT RECOVERED.**

Specification and ADR record for ADR-S21-001, written by the Executor (Claude Code Cloud) under the
Architecture Authority's "S21 Employment Contract Foundation — Full Cloud Execution Authorization".
Baseline: `origin/develop @ 8578f95` (tag `s20-employment-category-history-foundation`). This stage
is not based on `main`, `c2fd87e`, or any main-only merge history.

## §S21.1 Reconstruction disclosure

The historical roadmap wording for S21 was not recovered from any repository artifact. The title
"S21 — Employment Contract Foundation / تأسيس عقود التوظيف" was supplied by the Architecture
Authority as a reconstruction and is used only as such.

## §S21.2 Discovery

| Area | Repository state at `8578f95` | Consequence for S21 |
|---|---|---|
| `EmploymentRelationship` (S09) | Lifecycle boundary. It has `employment_type_id` and `employee_number_scheme` (`PERMANENT`/`CONTRACT`, recorded at write time, immutable), `effective_from`/`effective_to` and `end_knowledge_state`. It has no contract columns. | Contract is a temporal child of the relationship. The scheme discriminates CONTRACT from PERMANENT. |
| Appointment type | `ref.employment_types` holds `permanent`/`contract` (ADR-S09-001). S09 §7 explicitly refused to treat `ref.contract_types` as the same concept. | Appointment type ≠ contract type. They are kept separate. |
| Contract type catalog | `ref.contract_types` has S05 structure and S13 administration (Create/UpdateMetadata/Activate/Deactivate, no hard delete, `reference.*`). It has **0 rows**: S13 §8 "Do not invent missing values … Not seeded." | It is reused and no second catalog is created. S21 seeds nothing, so contract types are populated by administrators through the existing S13 API. |
| Contract-type reporting reference | S06 `ref.contract_type_population_mappings` → `ref.contract_based_population_categories` (Report 4/5 populations), with the `ResolveContractTypePopulationCategoryAsOf` as-of reader. | Future reporting can map a contract period's type to its population as of a date. S21 does not touch it. |
| Contract status/end | `ref.employment_status_details` includes `contract_ended` / إنهاء تعاقد (category `ended`, `is_relationship_ending`). `RecordEmploymentStatusPeriod` → `EndEmploymentRelationship` (S10/S15) already ends the relationship. | The إنهاء تعاقد lifecycle already exists. S21 only keeps contract history coherent on that path. |
| Relationship ending | `EndEmploymentRelationship` is the single orchestration point (S15). It closes secondment, workplace assignment, status and category (S20). | S21 adds one contract step in the same transaction. |
| Reappointment | A new relationship row for the same Person (S09 §13). | Contracts are keyed per relationship, so nothing carries forward. |
| Temporal helpers | `TemporalConstraints` (period CHECK, GiST EXCLUDE on half-open `daterange`), btree_gist (S02). | Reused unchanged. |
| Concurrency | Every period command locks the relationship row first. EXCLUDE is the database backstop. Child periods carry no client `expected_version` (S10/S11/S16/S20). | The same pattern is used. `expected_version` stays on the relationship end only, as today. |
| Audit | `AuditedCommandExecutor` + `AuditSpec`, with call-site allowlisted `changes`/`metadata`. Lifecycle consequences are exposed as `*_closed_as_consequence` metadata (S15/S16/S20 CA-02). | Reused unchanged. |
| HR permissions / scope | Relationship-level streams (S10, S20) use plain RBAC. Unit-targeted streams (S11/S12/S14/S16) compose S08 scope. | The S10/S20 precedent applies. No scope redesign. |
| Automation | None. There is no scheduler, jobs, queue workers or outbox: `routes/console.php` only has `inspire`, and `app/Console` is absent. This was confirmed by S15 §13 (Option C) and S19 §S19.10. | Expiry automation is **deferred** (§S21.12). |
| Import | Not built. The precedent is the S05 `ResolveMaritalStatusByArabicSourceValue` (unknown → null) and the S09 `end_knowledge_state` (`UNKNOWN_LEGACY`). | The model accepts unknown legacy contract ends without fabrication. No import is built. |
| Prior specs | S09 non-goals: "contract lifecycle/renewal automation; expiry automation". S10/S11/S12/S14: "Contract Lifecycle — blocked: `ref.contract_types` empty". | The catalog-population blocker is resolved by S13 administration (runtime population). Seeding is still forbidden. |
| Source fields | No frozen document in the repository gives a field-level contract specification (Excel columns, inclusive/exclusive end dates, open-ended contracts). | The model is kept minimal. Unsettled rules are listed in §S21.22. |

## §S21.3 Gap matrix

| Capability | Before S21 | S21 |
|---|---|---|
| Contract-type catalog | ✅ S05/S13 (empty) | reused |
| Appointment type PERMANENT/CONTRACT | ✅ S09 | unchanged |
| Contract ↔ relationship assignment | ❌ | **added** (`hr.employment_contract_periods`) |
| Initial contract | ❌ | **added** |
| Renewal preserving history | ❌ | **added** (same explicit command) |
| Current/as-of contract | ❌ | **added** (application query) |
| Relationship-end coherence | ❌ | **added** (`EndEmploymentRelationship` step) |
| إنهاء تعاقد termination | ✅ S06/S10/S15 status path | unchanged, plus coherence and audit metadata |
| Expiry detection / automation | ❌ | **deferred**: expiry is a derivable state (§S21.12) |
| Manual "end contract" command | ❌ | **not added**: its relationship consequence is unsettled (§S21.22) |
| Import / reporting | ❌ | compatibility only |

## §S21.4 ADR-S21-001 — decisions

| # | Decision | Basis |
|---|---|---|
| 1 | `EmploymentContractPeriod` (`hr.employment_contract_periods`) belongs to **EmploymentRelationship**, never Person. | Authorization §4; S10–S20 precedent. |
| 2 | It reuses **`ref.contract_types`**. No new catalog and no seed. Contract type is distinct from appointment type (`ref.employment_types`). | Authorization §6; S09 §7; S13 §8. |
| 3 | A new assignment requires an **active** contract type at command time. Later deactivation never rewrites or hides history. Hard delete of a referenced type is blocked by a RESTRICT FK. | Authorization §6; S20 precedent. |
| 4 | Contract periods are recordable **only for CONTRACT-scheme relationships**. PERMANENT relationships never receive or require a contract. | Authorization §5; frozen PERMANENT/CONTRACT distinction. |
| 5 | A contract **may start on** the relationship's `effective_from` and **never before** it (`>=`). This does not modify the S10/S11/S12/S16 rules. | Authorization §8. |
| 6 | **Two exclusive end dates:** `contractual_effective_to` is the originally agreed term and is never changed after recording. `effective_to` is the actual validity; it may only be *temporally closed* earlier (early renewal or relationship end), never extended. The database enforces `effective_to <= contractual_effective_to` for known terms. | Authorization §7/§9/§10 (history preserved, no claim beyond the relationship). |
| 7 | **Renewal** is a new period recorded by the same explicit command. An early renewal closes the previous validity at the renewal date. A renewal at or after the term end leaves the previous period untouched, and any lapse stays visible. There is no automatic renewal. | Authorization §9. |
| 8 | **Relationship end:** validity still open or extending past the end is closed at the end date, and the agreed term is preserved. A period starting on or after the end date causes the end to be **rejected**, as in S20. | Authorization §10; S15/S20 architecture. |
| 9 | **Expiry:** domain representation only (Option A). Expiry is derivable; automation is deferred. The existing إنهاء تعاقد status path remains the way to terminate. | Authorization §11 (no automation infrastructure). |
| 10 | **Authorization:** `hr.employment_contract_periods.view` / `.record`, with plain RBAC. `reference.*` never grants assignment. | Authorization §15; S10/S20. |
| 11 | **Legacy unknown end:** `contract_end_knowledge_state` is `KNOWN` or `UNKNOWN_LEGACY`, mirroring S09's `end_knowledge_state`. The API records only `KNOWN`; `UNKNOWN_LEGACY` is reserved for future import. | Authorization §5/§18 (no fabricated legacy dates). |
| CA-S21-01 | **Contract relationship creation (Architecture Authority decision).** A CONTRACT relationship does **not** have to create its first contract period in the same command or transaction. It may exist temporarily without a recorded contract. That state means **incomplete HR data, not an indefinite contract**. No contract, contract type or contract date is fabricated. Reappointment never carries a previous relationship's contract. S09 is not reopened or redesigned. | S21 Architecture Decisions + Final Corrective Gate, CA-S21-01 |
| CA-S21-02 | **Open-ended new contracts (Architecture Authority decision).** Every **newly recorded** contract must have a known agreed term end: `contractual_effective_to` is **required**, and `contract_end_knowledge_state = KNOWN`. Open-ended newly recorded contracts are **not supported** by the current frozen requirements. Legacy historical records **may** use `UNKNOWN_LEGACY` with `contractual_effective_to = NULL` **only** when the historical end is genuinely unknown. That value means "end unknown", **never** "open-ended contract". No end date is ever fabricated. Supporting genuine open-ended contracts in future requires a separate Architecture Change Decision backed by business evidence. | S21 Architecture Decisions + Final Corrective Gate, CA-S21-02 |

## §S21.5 Permanent vs contract

- **PERMANENT:** `RecordEmploymentContractPeriod` rejects it with
  `EmploymentRelationshipNotContractSchemeException` (409). No period is ever created for it, so
  as-of resolves to UNRESOLVED.
- **CONTRACT:** contract periods are representable from the relationship's first day.
- **Creation is not coupled to a contract (CA-S21-01, Architecture Authority decision):** a
  CONTRACT relationship does not have to create its first contract period in the same command or
  transaction, and `CreateEmploymentRelationship` (S09) is unchanged. A CONTRACT relationship
  without a recorded contract represents **incomplete HR data**, never an indefinite contract, and
  nothing is fabricated to fill it.

## §S21.6 Data model

`hr.employment_contract_periods` (migration `2026_10_07_000001_create_hr_employment_contract_periods_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7 |
| `employment_relationship_id` | `uuid` NOT NULL | FK → `hr.employment_relationships`, RESTRICT |
| `contract_type_id` | `uuid` NOT NULL | FK → `ref.contract_types`, RESTRICT |
| `effective_from` | `date` NOT NULL | contract start |
| `effective_to` | `date` NULL | actual validity end (exclusive) |
| `contractual_effective_to` | `date` NULL | agreed term end (exclusive); NULL only for `UNKNOWN_LEGACY` |
| `contract_end_knowledge_state` | `varchar(16)` NOT NULL default `KNOWN` | `KNOWN` \| `UNKNOWN_LEGACY` |
| `created_at` | `timestamptz` NOT NULL | technical |

**Constraints:**
- `employment_contract_periods_period_check`: `effective_to IS NULL OR effective_to > effective_from`.
- `employment_contract_periods_contractual_period_check`:
  `contractual_effective_to IS NULL OR contractual_effective_to > effective_from`.
- `employment_contract_periods_known_term_bounds_check`: either `KNOWN` with both dates set and
  `effective_to <= contractual_effective_to`, or `UNKNOWN_LEGACY` with no contractual date.
- `employment_contract_periods_no_overlap`: `EXCLUDE USING gist (employment_relationship_id WITH =,
  daterange(effective_from, effective_to, '[)') WITH &&)`.
- Indexes on both FKs.

**Columns deliberately absent:** `person_id`, organizational unit, `version`/`updated_at`, any
renewal or automation flag, and any contract column on `hr.employment_relationships`.

**Additive migration:** it creates one table and seeds two permissions
(`2026_10_07_000002_seed_security_employment_contract_period_permissions.php`). It alters no
existing object. `down()` drops only what the migration created.

## §S21.7 Temporal model

- **Convention:** DATE business dates, `timestamptz` technical time, half-open `[from, to)` for
  **both** end dates.
- **Term end is the exclusive day after the last contract day.** A contract whose last day is
  2026-12-31 has `contractual_effective_to = 2027-01-01`. A future import of inclusive "last day"
  source values must add one day at the import boundary (§S21.19).
- **Recording:** a KNOWN-term period is recorded with `effective_to = contractual_effective_to`.
- **As-of** uses actual validity. There is no contract in force outside it: before the first
  contract, during a lapse between an expired term and a later renewal, after the relationship
  ended, or on a PERMANENT relationship.
- **Future periods** never resolve before their own start date.
- **Backdating** before or on top of the latest period is rejected.

## §S21.8 Concurrency

`RecordEmploymentContractPeriod` begins with `SELECT … FOR UPDATE` on the relationship. This
serialises it against another recording or renewal and against `EndEmploymentRelationship`, which
takes the same row lock through its scoped UPDATE. A stale renewal therefore always re-reads the
committed latest period.

The controller takes the same lock first, so the renewal audit snapshot is race-free.

The EXCLUDE constraint is the independent database backstop. Both properties are proven with two
real PostgreSQL sessions in `ConcurrencyTest`.

## §S21.9 Command — `RecordEmploymentContractPeriod`

`handle(EmploymentRelationship, ContractType, effectiveFrom, contractualEffectiveTo)` runs inside
`AuditedCommandExecutor`:

1. **Lock and check the relationship.** Lock the relationship row. If it has already ended, throw
   `EmploymentRelationshipAlreadyEndedException` (409).
2. **Check the scheme.** If the relationship is not CONTRACT-scheme, throw
   `EmploymentRelationshipNotContractSchemeException` (409).
3. **Check the contract type.** Re-fetch it fresh. If it is missing or inactive, throw
   `InvalidEmploymentContractTypeException` (422, `contract_type_id`).
4. **Check against the relationship start.** If `effective_from` is before the relationship's
   `effective_from`, throw `InvalidEmploymentContractPeriodDateException` (422, `effective_from`).
5. **Check the term.** If `contractual_effective_to` is not after `effective_from`, throw
   `InvalidEmploymentContractTermException` (422, `contractual_effective_to`).
6. **Check against later history.** If `effective_from` is not after the latest period's
   `effective_from`, throw the same 422 as step 4.
7. **Close the previous validity if needed.** If the latest period's validity is open or extends
   beyond the new start, set its `effective_to` to the new start. Its contractual term is not
   touched.
8. **Insert** the new `KNOWN` period with `[effective_from, contractual_effective_to)`. CHECK and
   EXCLUDE violations are mapped to 422, never a 500.

## §S21.10 Renewal

- **Mechanism:** a renewal is the same explicit command with a later `effective_from`, possibly
  with a different contract type. No `PATCH` is used.
- **What is preserved:** the previous period's identity (`id`), `contract_type_id`,
  `effective_from` and `contractual_effective_to` (the originally agreed term) are always kept.
- **Early renewal** (before the previous term's end) **temporally closes** the previous period: its
  actual `effective_to` is set to exactly the renewal's `effective_from`. Its
  `contractual_effective_to` still records the originally agreed term. This is temporal closure,
  the same mechanism every MasarHR period stream uses, **not destructive historical replacement**.
- **Adjacent renewal** (at the term end) leaves the previous period untouched.
- **Late renewal** (after a lapse) also leaves the previous period untouched, and the lapse remains
  visible to as-of.
- **Audit:** the metadata records `renewal_of_period_id`, plus `previous_period_closed_at` when the
  validity was closed early.
- **No automatic renewal.**

## §S21.11 Relationship-end interaction

`EndEmploymentRelationship::closeEmploymentContractValidityAtEndIfAny()` runs in the existing single
transaction:

1. **Reject impossible ends.** If any period starts on or after the end date, the end is rejected
   with S09's `InvalidEndDateException` (422, `effective_to`) and rolls back atomically, including
   when the end was status-triggered. This covers, for example, a scheduled renewal. Nothing is
   deleted.
2. **Close extending validity.** Otherwise, every period whose validity is still open or extends
   past the end date is closed at exactly the end date. `contractual_effective_to` is preserved, so
   history records both the agreed term and the real end, and never claims a contract in force
   after the relationship ended.
3. **Leave expired contracts alone.** A contract that already expired before the end date is never
   extended.

**Audit:** `employment_contract_period_closed_as_consequence: true` is added to the metadata of the
**triggering** entry, whether that is `hr.employment_relationship.end` or, for status-triggered
endings including إنهاء تعاقد, `hr.employment_status_period.record`. This follows the same
locked before/after snapshot convention as S20 CA-02. There is no separate audit event.

## §S21.12 Expiry / automation boundary

**Chosen scope: A (domain representation only).**

- **What "expired" means:** a CONTRACT relationship with no contract in force on date D, while its
  latest KNOWN period's term ended on or before D. This is fully derivable from the stored model,
  and a later automation stage can use it to find candidates.
- **What already exists:** terminating a relationship because its contract expired uses the
  existing إنهاء تعاقد (`contract_ended`) status path. S21 makes that path keep contract history
  coherent.
- **Why nothing more is built:**
  - There is no scheduler, queue or outbox infrastructure.
  - Frozen material requires automation to re-read current state, be idempotent, respect later
    renewals, never end an already-ended relationship and never overwrite later decisions. Building
    that ad hoc would violate the authorization.
  - The S21 model already supports those guarantees: an automation job would re-lock the
    relationship, re-read the latest period, and do nothing if a renewal or end exists.

## §S21.13 Security

| Permission | Route | Gate |
|---|---|---|
| `hr.employment_contract_periods.view` | `GET …/employment-contract-periods` | plain RBAC |
| `hr.employment_contract_periods.record` | `POST …/employment-contract-periods` | plain RBAC |

- **Separation from the catalog:** `ref.contract_types` stays administered by `reference.*` only,
  and `reference.*` grants nothing here (tested).
- **No scope change:** there is no organizational-scope redesign and no existing protection is
  weakened.
- **Architecture debt, carried over from S20, not redesigned:** relationship-level HR streams (S10,
  S20, S21) remain plain RBAC even though S11 placement now makes a relationship's unit resolvable.

## §S21.14 API

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-contract-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/employment-contract-periods
     body: { contract_type_id: uuid, effective_from: date, contractual_effective_to: date }
     → 201 EmploymentContractPeriodResource
```

- **Resource fields:** `id`, `employment_relationship_id`, `contract_type_id`, `effective_from`,
  `effective_to`, `contractual_effective_to`, `contract_end_knowledge_state`.
- **Ordering:** the list is ordered most recent first.
- **Ownership:** a relationship not owned by `{person}` returns 404.
- **No other verbs:** there is no PATCH, DELETE or manual contract-end route.
- **As-of** is the application query `ResolveEmploymentContractForRelationshipAsOf`. It has no HTTP
  route, consistent with S06/S20.

## §S21.15 Error model

| Condition | HTTP |
|---|---|
| validation (missing/malformed) | 422 |
| relationship not owned / contract type not found | 404 |
| relationship already ended | 409 (`EmploymentRelationshipAlreadyEndedException`) |
| relationship is PERMANENT | 409 (`EmploymentRelationshipNotContractSchemeException`) |
| contract type inactive / vanished | 422 `contract_type_id` |
| start before relationship / not after latest period / overlap | 422 `effective_from` |
| empty or reversed agreed term | 422 `contractual_effective_to` |
| relationship end with a contract period starting on/after it | 422 `effective_to` (`InvalidEndDateException`) |

## §S21.16 Reappointment and employee number

- **Reappointment:** it creates a new relationship with no contract. Nothing is copied or inferred.
  The old relationship's periods stay with the old relationship. A new CONTRACT relationship records
  its own contract. A new PERMANENT relationship can never receive one.
- **Employee number:** the rules are unchanged, because S21 does not touch
  `CreateEmploymentRelationship` or the employee-number logic. CONTRACT uses the National ID;
  PERMANENT uses an independent, never-reused number. Regression tests are included.

## §S21.17 Reporting compatibility (no reporting built)

- **Contract as of X:** `ResolveEmploymentContractForRelationshipAsOf(relationship, X)` gives the
  contract (type, start, agreed term, actual end) in force on X, using actual validity and never
  today's value.
- **Population during a reporting period:** periods overlapping `[P_from, P_to)` can be selected
  with the same `daterange` operator the EXCLUDE uses.
- **Renewed vs historical periods:** these are distinguishable through ordering and
  `effective_to < contractual_effective_to`.
- **Report 4/5 population:** apply S06's `ResolveContractTypePopulationCategoryAsOf` to the
  period's type.
- **No denormalization:** no reporting tables and no denormalized data.

## §S21.18 Audit

| Write | action | target | changes | metadata |
|---|---|---|---|---|
| record / renewal | `hr.employment_contract_period.record` | `hr_employment_contract_period` / new period id | relationship id, contract type id, `effective_from`, `contractual_effective_to` | `contract_type_code`; for a renewal also `renewal_of_period_id` and, when closed early, `previous_period_closed_at` |
| relationship end (existing) | `hr.employment_relationship.end` | unchanged | unchanged | + `employment_contract_period_closed_as_consequence` |
| status-triggered end (existing) | `hr.employment_status_period.record` | unchanged | unchanged | + `employment_contract_period_closed_as_consequence` |

- **Rejected writes** roll back and write no success entry.
- **No PII** is written.

## §S21.19 Import compatibility (no import built)

| Source field | Maps to |
|---|---|
| Appointment type | `ref.employment_types` through S09 `CreateEmploymentRelationship` (unchanged) |
| Contract type | an existing `ref.contract_types` id; an unknown value stays UNRESOLVED / in the validation flow and is never mapped to an "other" row |
| Contract start | `effective_from` (≥ the relationship start) |
| Contract end, known | `contractual_effective_to`, **exclusive** (inclusive "last day" + 1) |
| Contract end, unknown | `contract_end_knowledge_state = UNKNOWN_LEGACY` with `contractual_effective_to = NULL`; never fabricated |

## §S21.20 UI

None. The visual runtime is deferred by the owner, and no existing frontend contract changes.

## §S21.21 Tests

- **`EmploymentContractFoundationTest` (new):** basic, temporal/renewal, PostgreSQL, reference,
  lifecycle (end, إنهاء تعاقد, terminal, reappointment), employee number, security, audit.
- **`ConcurrencyTest` (extended):** two-session EXCLUDE race; renewal serialisation on the
  relationship row lock.
- **`MigrationLifecycleTest` (extended):** S21 rollback/reapply; S02/S09 paths drop or roll back S21
  first; permission counts updated.
- **Inventory tests updated:** HR `ScopeBoundaryTest` (eight tables), `DatabaseConstraintsTest`,
  `ApiEndpointsTest`.

## §S21.22 Resolved and deferred questions

1. **Mandatory contract at creation: resolved by CA-S21-01.** It is not required; a CONTRACT
   relationship may temporarily exist without a recorded contract (incomplete data). The
   implementation already behaved this way, so no code change was needed.
2. **Open-ended contracts: resolved by CA-S21-02.** New contracts require a known agreed term.
   `UNKNOWN_LEGACY` is for genuinely unknown historical ends only, and never means "open-ended".
   The implementation already behaved this way, so no code change was needed.
3. **Manual contract end: DEFERRED** by the Architecture Authority. It is not an S21 blocker.
4. **Expiry automation: DEFERRED** by the Architecture Authority. It is not an S21 blocker. No
   scheduler, job, queue or outbox is introduced in S21.

## §S21.23 Non-goals / deferred

- Contract-type seed values.
- An automation scheduler or expiry job.
- A manual contract-end command.
- Contract correction or void.
- Mandatory contract at relationship creation (not required, per CA-S21-01).
- Open-ended newly recorded contracts (not supported, per CA-S21-02; any future support needs a
  separate Architecture Change Decision).
- Reporting and import implementation.
- UI.
- Organizational-scope redesign.
- S22.
