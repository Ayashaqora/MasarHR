# S44 — Workforce Analytics Foundation

Stage: S44. Baseline: `8b19076b45e0ed83c64082249323bce9bc0e2775` (S43). Migrations: **one** (`2026_10_21_000001`, a permission seed — no table, no column).
Governing decisions: the Architecture Authority's S44 full-implementation authorization (WA-D01..WA-D68) and the S44 pre-implementation repository audit. The full text of
WA-D01..WA-D68 was not supplied to the executor as a numbered list; this document records the decisions as they appear in the audit's draft list (WA-D01..WA-D52) and in the authorization
(WA-D53..WA-D68 as referenced there: ends WA-D53, shared Person facts WA-D54, age WA-D55, Primary Qualification WA-D56, service WA-D57, relationship type WA-D58, reuse strategy WA-D59,
percentages WA-D60..WA-D63, R3 out WA-D64, data quality WA-D65..WA-D68). These are **project architecture decisions**; none of them is an original-source requirement, and no source document
named "S44" or "R1–R4" is claimed. This document extends S37, S40 and the closed R1–R4 foundations additively and redefines nothing in them.

## §S44.1 Scope

A READ-ONLY backend analytics foundation: one canonical dataset for a month and one endpoint. It is an ANALYTICS composition layer over the existing canonical HR facts — not a fifth
monthly report. Excluded (no placeholder exists): dashboard, frontend, charts, PDF, XLSX, CSV, print, export, attendance, timesheets, absence rate, working days, leave balances /
entitlement / utilization, approved positions, vacancies, occupancy, turnover rate, FTE, payroll, productivity, forecasting, AI, new taxonomies, any change to R1–R4, S37 or S40.

## §S44.2 Population authority (WA-D03..D10, WA-D47)

`ListMonthlyReportingPopulation` (S37) is the ONLY workforce population authority and runs exactly once per request. `overall_headcount` = distinct Persons of that population; a
Person counts once however many relationships overlap the month. `relationship_count` = relationships represented in it. Duty analytics read S37's own classification (HAS_ON_DUTY,
NO_ON_DUTY, INDETERMINATE); `overall_headcount = has + no + indeterminate` is exposed as `reconciles_to_overall_headcount`. R3 is not invoked and no not-on-duty reason analytics exist.

## §S44.3 Pipeline (WA-D45, WA-D46, WA-D59)

```
S37 population (once) -> S40 fromPopulation (the precomputed population, S37 never re-runs)
                      -> existing pure Domain classes (CompletedAge, CumulativeServiceCalculator, PartialSecondmentOccurrences,
                         OrganizationalHierarchyPaths, MonthlyDimensionSegmentation)
                      -> S44-owned set-based batches -> WorkforceAnalyticsPersonRecord[] + terminal events -> WorkforceAnalyticsSections
```
No R1–R4 builder is called, no HTTP endpoint is composed, and R1/R2/R4 private logic is neither extracted nor modified. Where S44 needs an R-specific rule that is private to a closed
builder (the R2 workplace-occurrence mapping, the R4 terminal-event reason rule) it implements the same documented rule in its own class over the same Domain components; that duplication is
deliberate and disclosed (spec finding S44-F1).

## §S44.4 Single-value vs multi-value dimensions (WA-D11..D20, WA-D31..D33, WA-D60..D63)

**Single-value (Person grain; each Person in exactly one bucket; the buckets reconcile to `overall_headcount`; each bucket has a `percentage` over denominator `OVERALL_HEADCOUNT`):**
duty state; gender (CURRENT_RECORDED: MALE, FEMALE, NOT_RECORDED); age (R1 `CompletedAge` at the month's last day, WA-D69: the official bands are exactly R1's — `<25 … 65+` plus `NOT_RECORDED`. A birth date after the month end has calculation state `NOT_CALCULABLE`, official band `NOT_RECORDED`
and data quality `BIRTH_DATE_AFTER_REPORT_DATE`; NOT_CALCULABLE is never an age-band bucket. The value state, the band and the data-quality code stay distinct: the section also lists `calculation_states` (CALCULABLE / NOT_RECORDED / NOT_CALCULABLE person counts)); Primary Qualification (the current Primary only; no Primary = NOT_RECORDED; several qualifications
without a Primary are never guessed); service (R1 `CumulativeServiceCalculator` over EVERY relationship of the Person — not R1's "latest qualifying relationship" selection — with the 365-day year, the
frozen bands and INCOMPLETE for an UNKNOWN_LEGACY end).

**Multi-value temporal exposure (WA-D19/D20; each bucket counts DISTINCT person_id exposed at least once in the month, never segments or periods; a Person may be in several buckets; the buckets may
exceed `overall_headcount`; the figure is an `exposure_share` whose semantics are `SHARE_OF_OVERALL_POPULATION_EXPOSED_TO_BUCKET` over denominator `OVERALL_HEADCOUNT`, may sum above 100% and never
implies the buckets partition the population):** relationship type (`employment_types` on every overlapping relationship — distinct from category, contract and contract population mapping, WA-D58),
employment category, contract type (+ contract population mapping), specialty (+ specialty→cadre mapping), organizational placement (with hierarchy path, depth, direct and subtree counts), actual workplace,
employment status. The states RESOLVED / NOT_RECORDED / NOT_APPLICABLE / UNMAPPED of S40 stay distinct buckets and are never converted to "Other".

## §S44.5 Organization and actual work (WA-D17, WA-D18)

Organizational placement and actual workplace are independent. Placement uses the placement periods (a completed Transfer changes it prospectively; temporary movements never rewrite it) and one recursive
hierarchy batch. Actual workplace reuses S37's workplace segments and the R2 rules: full secondment, workplace assignment, partial-secondment destinations (only with at least one applicable scheduled weekday; a
destination with none yields no occurrence), the underlying PLACEMENT of a partial allocation flagged (`PLACEMENT_UNDERLYING_OF_PARTIAL_ALLOCATION`), and non-determinable segments (UNRESOLVED, AMBIGUOUS_MOVEMENT_STATE).
Partial-secondment weekdays are an ALLOCATION (`allocated_weekdays`, `allocation_is_not_attendance = true`) — never attendance, worked days, a percentage of time, FTE or a residual allocation.

## §S44.6 Employment status and workforce flows (WA-D21..D30, WA-D53)

Status exposure interprets S37's `statusSegments` with the R4 outcome rule (explicit/derived on_duty => ON_DUTY, an explicit temporary status => that status, UNRESOLVED => INDETERMINATE); no timeline is rebuilt.
Return Intention is separate and is not part of S44. **Relationship starts** are EVENT grain: `month_start <= effective_from < next_month_start` (no "hire" naming, no first-vs-reappointment taxonomy). **Relationship ends**
are EVENT grain with the R4 ES-D56..ES-D58 meaning: a KNOWN end belongs to the month iff `month_start <= effective_to < next_month_start`, dated `effective_to`, independently of the S37 population. Ends of relationships
S37 does not represent (an end dated exactly `month_start`) come from one bounded batch, are deduplicated by relationship id, are flagged `relationship_in_monthly_population = false`, and add no Person to the headcount and
no status exposure or timeline. The reason is the ending/terminal status beginning at `effective_to`, otherwise NOT_RECORDED; `ended_terminally` is a separate fact. A reappointment produces one End plus one Start while the Person counts once.

## §S44.7 Canonical dataset

`WorkforceAnalyticsPersonRecord` (one per S37 Person: duty, gender, age, service, Primary Qualification, relationships with their status / category / contract / specialty / placement / actual-workplace
segments, data-quality codes) plus the month's terminal events are the only source; `WorkforceAnalyticsSections` derives: `population`, `demographics` (gender, age), `employment` (relationship type, category, contract
dimension, service), `qualifications` (primary qualification, specialty), `organization` (organizational placement), `actual_work` (actual workplaces), `employment_status` (status exposure), `workforce_flows` (starts, ends),
`data_quality`. Nothing is persisted; there are no analytics tables.

## §S44.8 Percentages

Every percentage carries `numerator`, `denominator_type` (`OVERALL_HEADCOUNT`) and `denominator_value`. Single-value buckets expose `percentage`; multi-value buckets expose `exposure_share` with the semantics label above. The
arithmetic is exact integer basis points rounded half up (`percent` is a two-decimal string); a zero denominator yields `null`, never a division. No `distribution_percent` or similar name exists.

## §S44.9 Data quality (WA-D34..D37, WA-D65..D68)

Only existing earlier-stage code strings are reused, each only for the same semantic condition; no code is new: `INDETERMINATE_STATUS_COVERAGE`, `UNKNOWN_LEGACY_RELATIONSHIP_END` (service INCOMPLETE),
`RELATIONSHIP_END_REASON_NOT_RECORDED` (also for event-only ends), `PRIMARY_QUALIFICATION_REQUIRED`, `BIRTH_DATE_AFTER_REPORT_DATE`, `TRAVEL_PAY_STATUS_NOT_RECORDED`, `GENDER_NOT_RECORDED`,
`ORGANIZATIONAL_PLACEMENT_NOT_RECORDED`, `ACTUAL_WORKPLACE_NOT_DETERMINABLE`. `RETURN_INTENTION_NOT_RECORDED` is not a code. Each entry exposes `grain`, `person_count`, `relationship_count` (relationship-grain codes only) and the sorted
`affected_person_ids`. The distinctions NOT_RECORDED / UNMAPPED / INDETERMINATE / INCOMPLETE / NOT_CALCULABLE remain visible (bucket states, and for age the `calculation_states`); they are never merged into "Other".

## §S44.10 Query architecture (WA-D47..D49)

A CONSTANT number of statements independent of population size: S37 (8) + S40 enrichment (7) + 8 S44 batches = **23** for the representative workload: (1) the gender catalog, (2) every relationship of the Persons starting before the
month's end (+ type), (3) those relationships' status periods (service), (4) the Primary Qualifications, (5) the placement periods of the represented relationships, (6) one recursive CTE over every referenced unit and its ancestors
(skipped when no unit is referenced), (7) the supplemental terminal events (always), (8) the behavior of the ending statuses (skipped when there is no terminal event). Measured: 23 with ending statuses and units, 21 without a
terminal event or a unit, 2 for an empty population (S37's relationship statement and the supplemental batch). No per-Person, per-relationship, per-unit, per-segment or per-period query.

## §S44.11 API and permission (WA-D50..D52)

`GET /api/v1/hr/workforce-analytics?month=YYYY-MM-01` → `WorkforceAnalyticsController@index` → `WorkforceAnalyticsResource` (a pure projection of the sections: no Person rows, no identity). `month` is validated first (422 before S37). The only
public input is `month`; filters, grouping, ranges, pagination, sort and export parameters are ignored. The URI matches no route guard, so no route exception exists. Permission `hr.workforce_analytics.view` (plain RBAC), seeded by
`2026_10_21_000001`, granted to no role. (Sections §S44.13/§S44.14 in code comments refer to this API/permission pair.)

## §S44.12 Measured performance (evidence, not an SLA)

Synthetic datasets (about 5% ending mid-month, 5% ending exactly at month start, 2% UNKNOWN_LEGACY, 20% full secondment, 33% Primary Qualification, hierarchy of 57 units): ~100 Persons — 23 statements, 65 ms; ~1,000 — 23, 178 ms; ~10,000 — 23, 2.4 s.
The count is constant; no N+1.

## §S44.13 Tests

`WorkforceAnalyticsFoundationTest` (35 tests): population and duty reconciliation, single-value dimensions (gender, age with Feb-29 and band boundaries, Primary Qualification, service with gaps, statuses, travel pay, sick-leave run, bands, UNKNOWN_LEGACY),
multi-value dimensions, status exposure, starts, ends (including event-only month-start ends, deduplication and reasons), percentages, data quality, API and security, constant statement count, S37-once, source guards, exclusions. Plus mutation proofs recorded in the final report.

## §S44.14 Accepted limitations / deferred

Gender and Primary Qualification are current-recorded; identity is not exposed; the R2 workplace and R4 reason rules are duplicated in S44-owned code (finding S44-F1); `UNKNOWN_LEGACY` has no application creation path (exercised by direct insert).
Deferred: dashboard, frontend, exports, attendance, leave, vacancy, turnover, FTE, payroll, forecasting, any future stage.
