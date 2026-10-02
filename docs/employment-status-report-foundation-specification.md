# S43 — R4 Monthly Employment Status Report Foundation

Stage: S43. Baseline: `b9d5ff4dfdb8cdbee44400558d343284eef3c140` (S42). Migrations: **one** (`2026_10_20_000001`, a permission seed — no table, no column).
Governing decisions: the Architecture Authority's S43 authorization (ES-D01..ES-D55) and the S43 pre-implementation repository audit. This document extends S37 additively and
redefines nothing in S33–S42.

## §S43.1 Scope

A READ-ONLY backend monthly report of the employment-status timeline: one canonical dataset over the COMPLETE canonical S37 monthly population, with the status exposure per
status, relationship starts, relationship terminal events, Return Intention, the employee/relationship timeline and data quality, and one endpoint. No frontend, dashboard, PDF, XLSX, print,
chart, attendance, timesheet, leave balance, absence/working-day calculation, payroll, new status or movement taxonomy, new Return Intention model, snapshot or analytics.

## §S43.2 Population and grain

`ListMonthlyReportingPopulation` (S37) is called exactly once per request and consumed completely: a Person is in the population when at least one Employment Relationship satisfies
`effective_from < next_month_start AND (effective_to IS NULL OR effective_to > month_start)`. There is NO duty-classification filter (HAS_ON_DUTY, NO_ON_DUTY and INDETERMINATE Persons are all
included; the S37 classification is carried on the record as information only). The Person and the Employment Relationship are distinct grains: **Overall Persons** = distinct Person of the S37 population (never increased by a terminal event);
**Relationships represented** = distinct relationship. Every qualifying relationship keeps its own timeline (a reappointment yields one Person with two relationships; there is no
"latest relationship wins"). A Person whose last relationship ended on or before `month_start` is not included.

## §S43.3 Status timeline

The S37 `statusSegments` are authoritative and are never rebuilt. Interpretation only: `EXPLICIT` on_duty and `DERIVED_ON_DUTY` => `ON_DUTY` (the segment `kind` stays visible); `EXPLICIT` temporary
status => that status (`TRAVELING`, `CAPTIVE`, `SUSPENDED`, `UNPAID_LEAVE`, `EXTERNAL_SICK_LEAVE`; any other explicit code is exposed under its own upper-cased code, no new taxonomy); `UNRESOLVED` =>
`INDETERMINATE`. All intervals are DATE half-open `[from, to)` clipped to the month; nothing is collapsed to a month-end state and no gap is coerced. The S32/S38 lifecycle (TRAVELING and
SUSPENDED optionally bounded, CAPTIVE open-ended only, UNPAID_LEAVE and EXTERNAL_SICK_LEAVE bounded; automatic derived return when a bounded period ends with no successor) is unchanged. Follow-up state
(ACTIONABLE / SUPPRESSED / derived LAPSED) is operational automation and never touches a status fact. Movements are independent of status and R4 reads none of them.

## §S43.4 Main employment state

There is no ACTIVE/INACTIVE field. A relationship is conceptually active during its employment interval whatever its detailed status; a temporary status is never a termination. Termination is
represented only as a terminal relationship event (§S43.5); no INACTIVE daily segment is manufactured from `effective_to` to the month end.

## §S43.5 Terminal events

`effective_to` is the first day no longer employed (`[effective_from, effective_to)`). The **terminal event date is `effective_to`**; `effective_to - 1` is exposed only as `last_employed_day`. A KNOWN end is a terminal
event of the month when `month_start <= effective_to < next_month_start` (`effective_to == next_month_start` belongs to the following month). The reason is the S37 `relationshipEndReason` — the status beginning exactly at
`effective_to` — accepted only when its S06 behavior, resolved on the event date, is relationship-ending or terminal (one batch over `ref.employment_status_detail_behaviors`); otherwise the reason is
`NOT_RECORDED`. `ended_terminally` is exposed as a separate fact and is never a reason. The existing reasons (`retired`, `resigned`, `contract_ended`, `martyred`, `deceased`) stay distinct. A terminal Person
cannot be reappointed (unchanged).

**Terminal events are their own grain (ES-D56..ES-D58, Architecture Gate correction).** A KNOWN end belongs to month M iff `month_start <= effective_to < next_month_start`, whether or not the ended
relationship overlaps S37's monthly population. S37 is unchanged and still defines the Monthly Employment Population: a relationship ending exactly on `month_start` is not in it, so its event is supplied by one
bounded batch (KNOWN ends dated in the month and not among the S37 relationships, with Person identity, employment type and the status beginning at `effective_to`), deduplicated by relationship id. Such an
event is flagged `relationship_in_monthly_population = false`; it adds no Person to Overall Persons, creates no Person record, no status timeline and no Return Intention, and its reason / data quality follow the same
rules (ending or terminal status at `effective_to`, otherwise `NOT_RECORDED` and `RELATIONSHIP_END_REASON_NOT_RECORDED`). `UNKNOWN_LEGACY` has no `effective_to` and can never enter this batch. Example: an end dated
2026-12-01 is in December's terminal events and not in November's, and is not part of December's S37 population.

## §S43.6 UNKNOWN_LEGACY

`end_knowledge_state = UNKNOWN_LEGACY` stays explicit: `effective_to` is null, no end is inferred, no terminal event is produced, the S37 segments are unchanged and the window is merely the month bound.
It yields the data-quality code `UNKNOWN_LEGACY_RELATIONSHIP_END` (and `INDETERMINATE_STATUS_COVERAGE`, because the active days cannot be asserted).

## §S43.7 Return Intention

An independent temporal dimension with the two existing values only (`WANTS_TO_RETURN`, `DOES_NOT_WANT_TO_RETURN`); no period is the reporting state `NOT_RECORDED` (never stored). The periods intersecting
each relationship's window are batch-loaded (one statement) and tiled by the existing `MonthlyDimensionSegmentation` (a self-overlapping history is corrupt and raises
`InconsistentDimensionHistoryException`); nothing is collapsed to a single value and nothing is inferred from status, movement, absence or reappointment. The domain has no applicability rule, so
`RETURN_INTENTION_NOT_RECORDED` is deliberately NOT a data-quality code.

## §S43.8 Counting rules

Overall Persons = distinct Person. Each status exposure bucket = DISTINCT person_id exposed to that outcome at least once in the month (two TRAVELING periods are one exposure); a Person may be in several buckets,
so the buckets may exceed Overall Persons. Terminal events and relationship starts are relationship grain. Return Intention is its own dimension. No percentage, no normalization.

## §S43.9 Canonical dataset

`EmploymentStatusReportPersonRecord` (one per Person: identity, S37 duty classification, relationships with status segments, Return Intention segments, terminal event, data-quality codes) is the only
source; `EmploymentStatusSections` derives from it: general summary, status exposure, relationship starts, relationship terminal events, Return Intention, employee/relationship timeline and data quality.
Starts (`effective_from` in the month) make no FIRST/RE-appointment distinction.

## §S43.10 Data quality

`INDETERMINATE_STATUS_COVERAGE` (an S37 `UNRESOLVED` segment or an uncertain end), `UNKNOWN_LEGACY_RELATIONSHIP_END` (`end_knowledge_state`), `RELATIONSHIP_END_REASON_NOT_RECORDED` (a KNOWN end in the
terminal-event window with no ending status at `effective_to`). Always listed, each with a person count, a relationship count and the affected Persons. No warning is manufactured for optional data.

## §S43.11 Query architecture

A constant number of statements independent of population size: S37 (8) + Person identity (1) + Return Intention periods of every represented relationship (1) + the supplemental terminal events (1) + the behavior of
the ending statuses found at a terminal event (1; skipped when there is none) = **12** (11 without a terminal event; with an empty S37 population identity and Return Intention are skipped). No per-Person, per-relationship, per-segment or per-period query; no S37 re-execution.

## §S43.12 API

`GET /api/v1/hr/employment-status-report?month=YYYY-MM-01` → `EmploymentStatusReportController@index` → `EmploymentStatusReportResource` (a pure projection). `month` is validated first (422 before S37). The only
public input is `month`. The URI contains the word the S27 / S37 / HR scope route guards forbid, so each carries ONE exact-URI exception (`api/v1/hr/employment-status-report`, next to S42's).

## §S43.13 Permission

`hr.monthly_employment_status_report.view` (plain RBAC), seeded by `2026_10_20_000001`; it exists but is granted to no role.

## §S43.14 Accepted limitations

Statuses and Return Intention are read as recorded; identity is current-recorded; the one-payload timeline is not paginated by design; `UNKNOWN_LEGACY` rows have no application creation path today (only an import or direct
SQL), so the case is exercised with direct inserts.

## §S43.15 Deferred

Frontend, print, XLSX, PDF, dashboard, charts; attendance, timesheets, leave balances; payroll; new taxonomies; analytics; any future stage.
