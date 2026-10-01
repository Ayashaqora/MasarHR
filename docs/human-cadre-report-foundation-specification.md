# S41 — REPORT-1 Monthly Human Cadre Foundation

Stage: S41. Baseline: `9b1a651faca4a8ce5fa0c9eefc9c47fa036f00bc` (S40). Migrations: **three** (`2026_10_18_000001`–`000003`).
Governing decisions: the Architecture Authority's S41 full-implementation authorization, contract R1-D01..R1-D51 (frozen). This document
extends S37 (`monthly-workforce-reporting-semantics-foundation-specification.md`) and S40 (`monthly-workforce-multi-value-dimensions-specification.md`)
additively and redefines nothing in them.

## §S41.1 Scope

A backend REPORT-1 "Monthly Human Cadre / الكادر البشري الشهري" foundation: ONE canonical record per included Person for a month,
official summaries and Person drilldowns derived from those records, one read-only endpoint, and the two business-schema additions
the contract needs (the travel pay indicator and the Primary Qualification designation). No frontend, XLSX, PDF, CSV, print, dashboard,
organizational hierarchy totals, FTE, workplace percentages or R2/R4/R5.

## §S41.2 The frozen contract (summary)

**R1-D01..D31 (core).** A Person is included when at least one Employment Relationship overlaps any part of `[month_start, next_month_start)`
(one Person = at most one headcount). The **selected relationship** is the latest qualifying relationship in the month; earlier ones are history and
feed cumulative service. **Classification date** = month end if the selected relationship is active through month end, else the last active DATE
(`effective_to − 1 day`). One official specialty (effective at the classification date); the cadre is the specialty's temporal mapping at that date:
missing specialty → `NOT_RECORDED`, specialty without an effective mapping → `UNMAPPED`; for the Cadre Summary both are the reporting-only
`UNCLASSIFIED` bucket (never persisted, never an automatic mapping, never "Other"); the Specialty Summary still counts an unmapped specialty under
its real specialty. No organizational hierarchy summary, no workplace splitting, no FTE. Dimensions: overall headcount, cadre, specialty,
employment relationship type, gender, qualification (`CURRENT_RECORDED`), age, service. Every bucket supports Person drilldown and
`bucket count == drilldown count`.

**R1-D32..D51** are implemented as described in §S41.5–§S41.12.

## §S41.3 Architecture

```
S37 canonical monthly population (computed ONCE)
        |-- S40 ListMonthlyWorkforceDimensions::fromPopulation   (additive entry point, §S41.16)
        v
BuildHumanCadreResult  -> HumanCadrePersonRecord (one per Person) -> summaries (derived from the records)
```

`BuildHumanCadreResult` neither re-derives the population nor copies any S40 algorithm. No summary reconstructs the population.

## §S41.4 Population, selected relationship, classification date

Population and relationship windows are S37's, unchanged. The selected relationship is the last of the Person's S37 relationship segments (relationships of one
Person cannot overlap — a GiST exclusion — so the last is the latest). Classification date: `effective_to` null or `>= next_month_start` → month end
(`next_month_start − 1 day`); otherwise `effective_to − 1 day`. `contractual_effective_to` is never an end.

## §S41.5 SCHEMA-01 — `hr.employment_status_periods.travel_pay_status`

`varchar(16) NULL`, no default, `CHECK (travel_pay_status IS NULL OR travel_pay_status IN ('PAID','UNPAID'))`, no native ENUM, FK, index or trigger, no
backfill: existing rows stay `NULL` (never rewritten to PAID). It is meaningful only for `traveling`; because the status code lives in
`ref.employment_status_details` a CHECK cannot enforce that, so `RecordEmploymentStatusPeriod` rejects a non-null value for any other status
(`InvalidTravelPayStatusException`, 422 `errors.travel_pay_status`). The command takes an optional trailing `?string $travelPayStatus` (omitted = NULL, so every
existing caller is unchanged); the controller validates `nullable|in:PAID,UNPAID`; the resource and the audit `changes` expose the stored value so explicit
PAID / UNPAID are distinguishable from NULL. Auto-closing a traveling period only updates `effective_to`, so its indicator is preserved. Known limitation:
status periods are append-only (no PATCH), so a legacy NULL row cannot be corrected, only superseded going forward.

## §S41.6 SCHEMA-02 — `hr.person_qualifications.is_primary`

`boolean NOT NULL DEFAULT false` plus the partial unique index `person_qualifications_one_primary_unique ON (person_id) WHERE is_primary = true`:
at most ONE Primary per Person, 0..1 (a Person with qualifications and no Primary is valid storage). Backfill (R1-D42): a Person with exactly one
qualification gets it as Primary; a Person with two or more keeps all `false` — a Primary is never inferred among several rows — so the backfill can never create a
second Primary. Rollback drops the index, then the column. The Primary Qualification is `CURRENT_RECORDED` (R1-D43): a rerun of an old month uses the current
Primary; no qualification history table exists.

## §S41.7 Primary Qualification write behavior and API

* **Recording (R1-D49).** `RecordPersonQualification` locks the Person row (`lockForUpdate`, the repository convention), then makes the Person's first qualification Primary
  automatically; a later one is never Primary and never replaces it. Two concurrent first qualifications serialize on the lock; the partial unique index is the final protection
  (a violation is reported as `PrimaryQualificationConflictException`, 409, distinct from the duplicate-identity conflict). No `is_primary` input exists on the store endpoint.
* **Designation.** `DesignateQualificationAsPrimary` runs in one transaction: lock the Person row, verify the target belongs to the Person (`firstOrFail`, 404), return an
  idempotent success when it is already Primary, otherwise unset the current Primary and then set the target (order matters: the index is not deferrable), committing atomically;
  any failure rolls back, leaving the previous Primary intact. Concurrent designations for one Person serialize on the lock. No qualification is created, updated otherwise or deleted.
* **API.** `POST /api/v1/hr/persons/{person}/qualifications/{personQualification}/designate-primary` → `PersonQualificationController@designatePrimary`, route
  `persons.qualifications.designate-primary`, permission `hr.person_qualifications.designate_primary`. 403 without the permission, 404 for a qualification of another Person,
  200 with the qualification resource (`is_primary: true`) — also when it already was Primary. No generic PATCH/PUT/DELETE.
* **Audit.** `hr.person_qualification.record` now carries `is_primary` in its `changes`; `hr.person_qualification.designate_primary` carries `person_id`,
  `previous_primary_qualification_id` and `new_primary_qualification_id` with metadata `state_changed`. An idempotent already-Primary call is audited with previous = new and
  `state_changed: false` — no state transition is fabricated.

## §S41.8 The canonical R1 record

`HumanCadrePersonRecord` (readonly, never persisted), one per included Person, ordered by Person id (the S37 order): `person_id`, `national_id`, `full_name_ar`
(`CURRENT_RECORDED`), `gender` (`RECORDED` | `NOT_RECORDED`), `selected_relationship` (id, dates, end knowledge, structural employment type), `classification_date`,
`specialty` (`RESOLVED` | `NOT_RECORDED`), `cadre` (`RESOLVED` | `UNCLASSIFIED` + reason), `qualification` (`PRIMARY` | `NOT_RECORDED`), `age`, `service`, `data_quality[]`. Every value
is single-valued, so no dimension can multiply the Person.

## §S41.9 Cadre, specialty, type, gender, qualification

* **Cadre / specialty.** From the selected relationship's S40 specialty segments at the classification date (see §S41.2). The 12 official cadre categories are listed in
  the Cadre Summary (including zero-count ones, from `ref.monthly_cadre_categories`) plus `UNCLASSIFIED` with its reasons; `overall = Σ cadre buckets + UNCLASSIFIED` and
  `overall = Σ specialty buckets + NOT_RECORDED`.
* **Relationship type.** The structural `employment_relationships.employment_type_id` (`permanent` / `contract`) — not the employment category, the contract type or the
  population mapping.
* **Gender.** `Person.gender_id`, current-recorded; missing = `NOT_RECORDED`.
* **Qualification.** ONLY `person_qualifications.is_primary`; none = `NOT_RECORDED`; two or more qualifications and no Primary also raise `PRIMARY_QUALIFICATION_REQUIRED`. Never
  inferred from created_at, degree, order or "latest".

## §S41.10 Age (R1-D41, R1-D50)

`CompletedAge`: completed calendar years at the last day of the report month (never `days / 365`); a Feb-29 birthday has its anniversary on Feb 29 in a leap year and on Feb 28
otherwise. `birth_date` null → age `NOT_RECORDED`, band `NOT_RECORDED`. `birth_date` after the report date → age `NOT_CALCULABLE`, band `NOT_RECORDED`, data quality
`BIRTH_DATE_AFTER_REPORT_DATE` (never negative; the Person stays included). Bands `<25, 25-34, 35-44, 45-54, 55-64, 65+, NOT_RECORDED` partition the headcount. Nothing is persisted.

## §S41.11 Service (R1-D32/D34–D38/D40/D47/D48)

`CumulativeServiceCalculator` — pure; the canonical value is `service_days`, an integer count of calendar days over half-open `[from, to)` intervals (day numbers), never a sum of years/months/days.
Only Employment Relationships documented in MasarHR count (no prior service, no first-hire estimate). Each relationship contributes `[effective_from, min(actual effective_to or the report
boundary, boundary))`; the actual end controls (the agreed contract term is not an input), gaps between relationships count for nothing, and relationships cannot overlap.
Within a relationship the explicit statuses (one at any date, by the GiST exclusion) decide: no status COUNT; `captive`, `suspended`, `on_duty` and any unknown/future status COUNT;
`unpaid_leave` DO_NOT_COUNT; `traveling` PAID COUNT (PAID_EXPLICIT), UNPAID DO_NOT_COUNT (UNPAID_EXPLICIT), NULL COUNT (PAID_BY_DEFAULT, data quality `TRAVEL_PAY_STATUS_NOT_RECORDED`);
`external_sick_leave`: **90 days per continuous run** — adjacent periods with no date gap are one run starting at its first period's start; `[start, start + 90)` counts, `[start + 90, run_end)` does not;
a date gap or a different status starts a new run; a run may start before the report month; the relationship end and the report boundary clip it. D25's COUNT precedence among overlapping
temporary statuses is dormant (overlap is impossible in storage, D40). A relationship whose actual end is `UNKNOWN_LEGACY` makes the Person's service `INCOMPLETE`
(`service_days = NOT_CALCULABLE`, band `INCOMPLETE`, reason `UNKNOWN_LEGACY_RELATIONSHIP_END`); the Person stays in R1 with every other dimension.
`completed_service_years = floor(service_days / 365)`, `remaining_service_days = service_days % 365` (display `X years + Y days`, no artificial months); bands `<5` (0–1824), `5-9` (1825–3649),
`10-14` (3650–5474), `15-19` (5475–7299), `20-24` (7300–9124), `25-29` (9125–10949), `30+` (≥10950), `INCOMPLETE`: `overall = Σ numeric bands + INCOMPLETE`.

## §S41.12 Data quality

Report-derived constants in `HumanCadreResult` (never persisted, no table): `PRIMARY_QUALIFICATION_REQUIRED`, `UNKNOWN_LEGACY_RELATIONSHIP_END`, `TRAVEL_PAY_STATUS_NOT_RECORDED`,
`BIRTH_DATE_AFTER_REPORT_DATE`; cadre `UNCLASSIFIED` reasons `NOT_RECORDED` / `UNMAPPED`.

## §S41.13 API

`GET /api/v1/hr/human-cadre?month=YYYY-MM-01` → `HumanCadreController@index` → `HumanCadreResource` (a pure projection). `month` is validated first (422 before S37). One computation returns
`metadata`, `overall_headcount`, `cadre_summary`, `specialty_summary`, `employment_type_summary`, `gender_summary`, `qualification_summary`, `age_summary`, `service_summary` and `rows` (the canonical
records). No pagination, filter or export: every summary bucket is reproducible by filtering `rows`. The URI and class names avoid every term the route guards forbid.

## §S41.14 Permissions

`hr.human_cadre.view` (the report; plain RBAC, no organizational scope) and `hr.person_qualifications.designate_primary`, seeded by `2026_10_18_000003`, granted to no role.

## §S41.15 Query budget and performance

A **constant 20 statements** (target 19; the one extra is justified below), independent of population size: S37 (8), S40 enrichment (7), and five R1 batches — Person identity + gender label, every documented
relationship starting before the boundary (+ type label), those relationships' status periods (+ code, + `travel_pay_status`), the Primary Qualifications (+ labels), and the cadre category catalog. The catalog read
is what lets the Cadre Summary list all 12 official buckets including zero-count ones; deriving it from the records could only show non-empty categories. No per-Person, per-relationship or per-segment query; no index added
(the existing `person_id`, `employment_relationship_id` and GiST indexes serve every batch). Measured on synthetic data in a rolled-back transaction: 1,000 Persons 92 ms, 10,000 Persons 1.5 s, always 20 statements.

## §S41.16 S40 additive entry point (R1-D39)

`ListMonthlyWorkforceDimensions::fromPopulation(MonthlyReportingPopulation $canonical)` holds the post-S37 half of `__invoke`, unchanged; `__invoke` delegates to it. The existing path keeps its 15 statements and its
result; `fromPopulation` runs only the 7 S40 statements; R1 therefore executes S37 exactly once.

## §S41.17 Historical reproducibility and accepted limitations

Population, selected relationship, specialty, service and status modifiers derive from business-dated history (late or back-dated entry can change a re-run); the cadre mapping is retroactively mutable (S40
limitation); gender, Primary Qualification, `national_id`, `full_name_ar` and labels are current-recorded; age derives from the current-recorded `birth_date`. Legacy NULL travel pay cannot be corrected (§S41.5).
No snapshotting is introduced. The one-payload drilldown is not paginated by design.

## §S41.18 Guards and mechanical test updates

The S40 name guard (`MonthlyWorkforceDimensionsFoundationTest`) keeps its R1/R2/R4/R5 pattern for every file of `app/` except an **exact-path allowlist** of the five S41 files
(`BuildHumanCadreResult`, `HumanCadrePersonRecord`, `HumanCadreResult`, `HumanCadreController`, `HumanCadreResource`); the output-class ban still applies to allowlisted files, and regression tests prove a future, unlisted,
relocated or export-named HumanCadre file still fails. Mechanical accommodations of S41's migrations: the S37 post-S34 migration list/count, the S39 newest-migration pin, the S40 migration count/latest pin,
`MigrationLifecycleTest` (arrays, ordering, permission counts, a new S41 rollback test) and `ApiEndpointsTest`. Three S23 qualification tests that asserted the absence of a primary flag are updated to the S41 shape.

## §S41.19 Tests

`HumanCadreEnginesTest` (pure age/service), `HumanCadreFoundationTest` (population, classification, dimensions, reconciliation, drilldown, query budget, API, guards), `PrimaryQualificationAndTravelPayTest`
(writes, API, audit, rollback, backfill), `ConcurrencyTest` (real two-session Primary races), `MigrationLifecycleTest` (S41 rollback/re-apply). Twenty-six controlled mutations each failed the suite and were restored byte-for-byte.

## §S41.20 Deferred

R2/R4/R5 and their semantics; frontend, XLSX/PDF/CSV/print, dashboard; organizational hierarchy totals; pagination/filtering; correction of legacy travel pay; qualification history; pre-system service. No future stage is opened by this document.
