# S45 — Dashboard Foundation

Stage: S45. Baseline: `49faa84fa2ba0f9330f3f26b5a94b54ab76fac1d` (S44). Business schema: **no change**. Migrations: **none**. Backend API: **unchanged** (the S44 endpoint is reused). Permission: **unchanged**
(`hr.workforce_analytics.view` is reused). Governing decisions: the Architecture Authority's S45 authorization (DB-D01..DB-D79) and the S45 pre-implementation audit. The numbered DB-D text was not supplied as a
single list; this document records the decisions as they appear in the audit draft (DB-D01..DB-D47) and the authorization (DB-D48..DB-D79 as referenced: single page-level request D48..D52, display families D53..D57,
aggregate-only D58..D62, reconciliation D63..D67, historical semantics D68..D71, flows D72..D75, S44-F1 debt D76..D79). These are **project architecture decisions**, not original-source requirements.

## §S45.1 Purpose and sequence

Reports → Analytics → **Dashboard**. S45 is the aggregate Dashboard foundation: a consumer/presentation layer over the frozen canonical S44 Workforce Analytics result. It creates no HR business interpretation, no population
engine, no KPI calculation and no identity view.

## §S45.2 Composition choice: Option A

The Dashboard page makes **one** request, `GET /api/v1/hr/workforce-analytics?month=YYYY-MM-01`, per selected reporting month and derives every widget from that response (DB-D48/DB-D49). Option A was sufficient: the S44 response
already carries every supported analytic with its denominator, display semantics and data-quality counts, so no Dashboard endpoint, backend query or reshaping layer was created (Options B/C remain reserved, DB-D52;
Option D is prohibited, DB-D51). S37 therefore executes once per Dashboard computation (proved by a statement test through HTTP).

## §S45.3 Frontend structure

Route `/dashboard` (authenticated; nav item "لوحة المؤشرات"). `pages/DashboardPage.tsx` (month selector, permission gate, states) → `features/dashboard`: `api.ts` (typed S44 response + the single fetch), `hooks.ts` (the single data hook),
`month.ts` (explicit first-day-of-month helpers), `contract.ts` (the KPI contract registry), `labels.ts` + `usePercentText.ts` (presentation only), `widgets.tsx` (scalar / distribution / exposure / section primitives),
`DashboardView.tsx` (pure rendering of the resolved response). States: loading, error with retry, forbidden (403), no permission (no request is made), zero population, data-quality-only. Arabic-first RTL; English mirrored; Western digits.
Widgets never fetch. No chart library or framework was added: distributions are accessible lists with proportional bars; **multi-value exposure is never a pie/donut** (DB-D55..D57).

## §S45.4 KPI contract (DB-D05)

Each supported analytic declares canonical source, grain, population, display family, denominator, historical semantics, reconciliation rule and data-quality dependency in `contract.ts`:

| KPI | Family | Grain | Denominator | Historical | Reconciliation |
|---|---|---|---|---|---|
| overall_headcount | SCALAR | Person | — | HISTORICAL | = HAS + NO + INDETERMINATE |
| relationship_count | SCALAR | Relationship | — | HISTORICAL | ≥ headcount; never combined |
| duty_state | MUTUALLY_EXCLUSIVE_DISTRIBUTION | Person | OVERALL_HEADCOUNT | HISTORICAL | sums to headcount |
| gender | MUTUALLY_EXCLUSIVE_DISTRIBUTION | Person | OVERALL_HEADCOUNT | CURRENT_RECORDED_ON_HISTORICAL_RERUN | sums to headcount |
| age | MUTUALLY_EXCLUSIVE_DISTRIBUTION | Person | OVERALL_HEADCOUNT | HISTORICAL | official bands sum to headcount |
| service | MUTUALLY_EXCLUSIVE_DISTRIBUTION | Person | OVERALL_HEADCOUNT | HISTORICAL | bands (incl. INCOMPLETE) sum to headcount |
| primary_qualification | MUTUALLY_EXCLUSIVE_DISTRIBUTION | Person | OVERALL_HEADCOUNT | CURRENT_RECORDED_ON_HISTORICAL_RERUN | sums to headcount |
| specialty, employment_category, contract_dimension, relationship_type | MULTI_VALUE_EXPOSURE | Person | OVERALL_HEADCOUNT | TEMPORAL_EXPOSURE | none (a Person may be in several buckets) |
| organizational_placement | HIERARCHY | Person | OVERALL_HEADCOUNT | TEMPORAL_EXPOSURE | unit exposures are never summed |
| actual_workplace | MULTI_VALUE_EXPOSURE | Person | OVERALL_HEADCOUNT | TEMPORAL_EXPOSURE | none; allocation is not attendance |
| status_exposure | MULTI_VALUE_EXPOSURE | Person | OVERALL_HEADCOUNT | TEMPORAL_EXPOSURE | none |
| relationship_starts, relationship_ends | EVENT_COUNT | Event | — | EVENT_GRAIN | event counts; ends include event-only month-start ends |
| data_quality | DATA_QUALITY | mixed | — | HISTORICAL | counts per canonical code, never hidden |

## §S45.5 Semantics preserved (no reinterpretation)

- **Headcount** = distinct Persons overlapping the month (S37); labelled "إجمالي الأشخاص ضمن القوى العاملة خلال الشهر" with an explanation that it is not the month-end or on-duty headcount; `relationship_count` is separate.
- **Duty state** (HAS/NO/INDETERMINATE) is a mutually exclusive Person partition read from S37; INDETERMINATE is always visible.
- **Age** (WA-D69): the official bands are exactly R1's; a birth date after the month end is calculation state NOT_CALCULABLE, band NOT_RECORDED, data quality BIRTH_DATE_AFTER_REPORT_DATE; NOT_CALCULABLE is shown only
  in the informational `calculation_states` list, never as a band.
- **Service**: S44/R1 bands only (no average/median); INCOMPLETE stays explicit. **Primary Qualification**: the current Primary only. NOT_RECORDED / NOT_APPLICABLE / UNMAPPED keep their own labels and are never "Other".
- **Multi-value exposure**: always rendered with the not-a-partition note, no total, no sum of shares, no pie. **Organizational placement** is a hierarchy of temporal exposure (not a snapshot); **actual workplace** is
  exposure/allocation (never attendance, worked days, utilization, share of time or FTE).
- **Flows**: counts of events only; labelled "بدايات/نهايات علاقات العمل" — never hires or turnover; no rate or denominator.
- **Percentages**: the canonical S44 `percent` string is displayed as-is; a null (zero denominator) is shown as "غير محسوبة", never as 0%; bars are a presentation of the canonical basis points.

## §S45.6 Historical semantics (DB-D68..D71)

Gender, Primary Qualification and catalog labels are CURRENT_RECORDED_ON_HISTORICAL_RERUN; specialty, category, contract, employment status exposure (DB-D81: a Person's exposure to one or more status segments intersecting the month; a Person may be in several buckets), placement and workplace are TEMPORAL_EXPOSURE (S40 mappings stay retroactively mutable); starts and ends are
EVENT_GRAIN. Each section carries `data-historical` and a visible tag; no immutable-snapshot claim is made.

## §S45.7 Cross-report reconciliation (DB-D63..D67) and S44-F1

The Dashboard does not force S44 to equal R1/R2/R3/R4. Intentional differences are protected by tests: R2 reports only HAS_ON_DUTY Persons (S44 overall = S37 population); R1 attributes relationship type to the selected
relationship while S44 exposes every type of the month. S44-F1 remains **accepted technical debt**: narrow tests prove S44's duplicated R2 actual-workplace occurrences and R4 terminal-event/reason rules still agree with the
authoritative R2/R4 builders (so a future change there fails a test and triggers review of S44). No R2/R4 refactor was made.

## §S45.8 Unsupported KPIs (DB-D25..D34, D43, D44)

Absence rate, attendance rate, leave utilization, vacancy rate, position occupancy, turnover rate, FTE, productivity, payroll analytics and forecasting/AI have no canonical source (no attendance, absence, leave, position/vacancy,
salary or output domain exists) and are **absent**: no card, no placeholder, no zero. `unpaid_leave` / `external_sick_leave` appear only as employment-status exposure buckets; work-schedule and partial-secondment weekdays are
never read as attendance.

## §S45.9 Security and PII (DB-D58..D62)

The Dashboard is aggregate-only and reuses `hr.workforce_analytics.view` (no new permission, granted to no role). The UI shows no national id, name or identity and never renders the opaque `affected_person_ids`; there is no drilldown.
Backend tests pin: no Dashboard route/permission/migration/class, the exact response key set, no unsupported-KPI or identity keys, one S37 execution per request, a constant statement count, and 403 for report-only permissions.

## §S45.10 Measured query and performance evidence (no SLA, no cache)

The page request is the S44 endpoint: **24 statements** over HTTP (S44's 23 + the permission check), constant with population size, S37 executed **once**: ~100 Persons — 65 ms; ~1,000 — 133 ms; ~10,000 — 1.4 s (payload ≈ 78 KB, 137 KB,
608 KB). No caching, materialization or snapshot was added (DB-D40). See finding S45-F1 about the payload growing with the population.

## §S45.11 Exclusions

No identity drilldown, no new backend layer/endpoint/permission/migration, no persistence, no chart library, no demo or hard-coded statistics, no unsupported KPI, no change to S37–S44 or R1–R4.
