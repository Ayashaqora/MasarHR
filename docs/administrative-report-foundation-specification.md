# S42 — R2 Administrative / Job Title / Gender / Actual Work Report Foundation

Stage: S42. Baseline: `267d288fe31ca9d7a5d1a8d61ad8970ae7a99347` (S41). Migrations: **one** (`2026_10_19_000001`, a permission seed — no table, no column).
Governing decisions: the Architecture Authority's S42 authorization, frozen R2 contract D01..D41. This document extends S37, S40 and S41 additively and
redefines nothing in them.

## §S42.1 Scope

A READ-ONLY backend monthly report: one canonical dataset of the HAS_ON_DUTY Persons of the canonical S37 population, with organizational placement
(full hierarchy), actual workplace, official job titles, the administrator classification, gender and data quality, and one endpoint. No frontend, dashboard, PDF,
XLSX, print, chart, attendance, timesheet, FTE, payroll, historical gender, leave taxonomy, or redesign of organization, movement or job title.

## §S42.2 Population

The population is the Persons classified `HAS_ON_DUTY` by the existing S37 semantics (S37 is computed ONCE per request and its classification is only read here).
`NO_ON_DUTY` is excluded. `INDETERMINATE` is excluded from the population and exposed only as the data-quality code `INDETERMINATE_DUTY_STATE` (count and affected
Persons) — never guessed. The overall headcount is the count of DISTINCT Person records.

## §S42.3 Pipeline

```
S37 population (once) -> HAS_ON_DUTY filter -> S40 fromPopulation (job-title segments + temporal administrator mapping)
                     -> R2 enrichment (identity/gender, organizational placement periods, hierarchy; actual workplace from S37's segments)
                     -> canonical AdministrativeReportPersonRecord[] -> AdministrativeSections (every official section) + data quality
```

No third population engine; S37 and S40 semantics are unchanged (S40 is reused through the additive `fromPopulation`, called with the filtered population).

## §S42.4 Organizational placement vs actual workplace

Independent concepts, never collapsed into one field. **Organizational placement**: the relationship's placement periods clipped to its month window and tiled by
`MonthlyDimensionSegmentation` (`RESOLVED` | `NOT_RECORDED`); a completed Transfer changes it prospectively, and temporary movements never rewrite it; every applicable segment
is preserved. **Actual workplace**: S37's workplace segments (the single S27/S30 decision table), kept as occurrences with their movement type — `PLACEMENT`, `FULL_SECONDMENT`,
`WORKPLACE_ASSIGNMENT`, `PARTIAL_SECONDMENT` — or as a non-determinable segment (`UNRESOLVED`, `AMBIGUOUS_MOVEMENT_STATE` with its competing movements, no winner).

## §S42.5 Temporal contract

DATE half-open `[from, to)` everywhere; applicable monthly segments are preserved, never collapsed to a "last value"; no history or movement fact is fabricated.

## §S42.6 Job title and administrator classification

Official job titles are the S40 temporal segments, every applicable one preserved (a Person with title A then B appears in both title buckets and counts once in each). The
administrator classification is ONLY the S40 temporal Job Title → Administrator mapping, resolved as-of each job-title segment: `ADMINISTRATOR`, `NOT_ADMINISTRATOR`
(a resolved `false`), `UNMAPPED` (a recorded title without a mapping — never "NO") or `NOT_RECORDED` (no title). A change inside the month yields several segments. A Supervisory
Assignment is a separate concept that R2 never reads: it cannot overwrite a title, create title history, imply promotion or change the classification.

## §S42.7 Partial secondment weekdays

A partial-secondment destination creates an actual-workplace occurrence only when at least one of its configured weekdays occurs inside
Report-segment ∩ Movement-effective-period (`PartialSecondmentOccurrences`, half-open dates); a destination with zero applicable scheduled occurrences creates none. The occurrence keeps the
configured `weekdays` and the `scheduled_weekdays_in_period` that occur — an ALLOCATION, never attendance: no count, FTE, percentage or worked-day figure exists. While a segment is a partial
allocation the organizational placement is unchanged and the original (base) placement stays represented as the underlying `PLACEMENT` occurrence flagged `underlying_of_partial_allocation`, distinguishable from the destination occurrences (R2-D42). Nothing is inferred from it: it carries no weekdays, and the report does not infer that unallocated weekdays were worked at the base placement, nor attendance, worked days, percentages, FTE or any residual weekday allocation; the configured partial-secondment weekdays are the only explicit weekday allocations.

## §S42.8 Hierarchy

The organizational drill-down preserves the full hierarchy (`root -> … -> unit -> Person`): the section lists every referenced unit and its ancestors with `path` (root → unit), `depth`,
`direct_person_count` and `subtree_person_count` (distinct Persons placed at the unit or any descendant). The hierarchy is read in one batch (a recursive CTE over the referenced units);
a cycle fails explicitly (`OrganizationalHierarchyPaths`). The actual-workplace drill-down is `workplace -> movement type -> period -> weekdays where applicable -> Person` (the occurrences on each record).

## §S42.9 Counting rules

The overall headcount is a distinct-Person count. Every bucket counts DISTINCT person_id within itself. The temporal / multi-value dimensions (job title, administrator classification, organizational
placement, actual workplace) may contain the same Person in several buckets, so their sums are NOT required to equal the overall headcount and no percentage is ever produced. Gender is the one
single-valued dimension and reconciles: `MALE + FEMALE + NOT_RECORDED = overall headcount`.

## §S42.10 Canonical dataset

`AdministrativeReportPersonRecord` (one per Person: identity, gender, relationships with job-title / placement / actual-workplace segments, data-quality codes) is the only source; the sections —
general summary, administrator classification, official job titles, gender, organizational placement, actual workplaces, employee drill-down, data quality — are derived from it by `AdministrativeSections`
and never recalculate the population or a rule. It is the intended future source for screen/print/XLSX/PDF; none of those is built.

## §S42.11 Data quality

`NOT_RECORDED` (a required source value is absent), `UNMAPPED` (the source exists, the mapping does not) and `INDETERMINATE` (not safely determinable) stay distinct. The codes — `INDETERMINATE_DUTY_STATE`,
`JOB_TITLE_NOT_RECORDED`, `ADMINISTRATOR_MAPPING_UNMAPPED`, `GENDER_NOT_RECORDED`, `ORGANIZATIONAL_PLACEMENT_NOT_RECORDED`, `ACTUAL_WORKPLACE_NOT_DETERMINABLE` — are always listed, each with a person count and
the affected Persons (id, national id, name). No warning is manufactured for optional data. Corrupt history (overlapping periods, a cyclic hierarchy) raises `InconsistentDimensionHistoryException`
instead of selecting a row.

## §S42.12 Gender

`CURRENT_RECORDED` (`MALE`, `FEMALE`, `NOT_RECORDED`); no gender history.

## §S42.13 API

`GET /api/v1/hr/administrative-report?month=YYYY-MM-01` → `AdministrativeReportController@index` → `AdministrativeReportResource` (a pure projection of the canonical result). `month` is validated first (422 before S37).
The only public input is `month`: no pagination, filter, sort or export. The mandated URI contains the word the S27, S37 and HR route guards forbid, so each carries ONE exact-URI exception for
`api/v1/hr/administrative-report` (the HR scope guard only for the `report` segment); every other route is still checked. The S40 and S41 name guards gained an exact-path allowlist of the five S42 files
(`AdministrativeReportPersonRecord`, `AdministrativeReportResult`, `BuildAdministrativeReportResult`, `AdministrativeReportController`, `AdministrativeReportResource`); the output-class ban still applies to them.

## §S42.14 Permission

`hr.monthly_administrative_report.view` (plain RBAC, no organizational scope), seeded by `2026_10_19_000001`; it exists but is granted to no role.

## §S42.15 Query architecture

A constant **18** statements, independent of population size: S37 (8); S40 enrichment (7: the four period streams and three mappings); and three R2 batches — (1) Person identity + gender label for the included and the indeterminate Persons,
(2) the organizational placement periods of the included relationships, (3) one recursive CTE reading every referenced unit and its ancestors. The job-title segments, administrator mapping and actual-workplace
segments come from S40 and S37 with no extra statement. No per-Person, per-relationship, per-workplace, per-title or per-node query; no index added. (An empty population skips the batches.)

## §S42.16 Accepted limitations

Gender, identity and labels are current-recorded; cadre/administrator mappings are retroactively mutable (S40); `NOT_RECORDED` placements and unresolved workplaces are reported, not inferred; the one-payload drilldown is not paginated by design.

## §S42.17 Deferred

Frontend, print, XLSX, PDF, dashboard, charts; attendance and FTE; payroll; organization/movement/job-title redesign; R4/R5; any future stage.
