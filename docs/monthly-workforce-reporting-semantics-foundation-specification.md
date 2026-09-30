# S37 — Monthly Workforce Reporting Semantics Foundation

Stage: S37. Baseline: `ff58914b529ebb8a9d3b7508d64cf4e3956cca24` (S36). Migrations: **none**. Routes, controllers,
permissions, scheduler, frontend and output layers: **none**.
Field names below are snake_case descriptions of the camelCase DTO properties (for example `endIsUncertain`, `qualificationSemantics`).
Governing decisions: the Architecture Authority's S37 implementation authorization (frozen). This document
extends S27 (`reporting-as-of-foundation-specification.md`) additively and rewrites no earlier stage document.

## §S37.1 Scope

An INTERNAL, READ-ONLY monthly workforce reporting foundation over the existing schema:
`ListMonthlyReportingPopulation` (application query) and the pure Domain helpers `MonthInterval`,
`MonthlyStatusSegmentation`, `MonthlyDutyClassification`, `MonthlyWorkplaceSegmentation`. It returns the
immutable, never-persisted `MonthlyReportingPopulation` → `MonthlyReportingPersonRow` →
`MonthlyRelationshipSegment` structure.

It is **not** a report. It has no totals, percentages, allocated-day counts, ages, exports, PDF/XLSX/print,
dashboard or HTTP API. `ListReportingPopulationAsOf` (S27) is neither extended nor changed: its cardinality
(one row per effective relationship on one date) and public contract are untouched.

## §S37.2 Month interval

A reporting month is the half-open DATE interval `[month_start, next_month_start)`; the argument must be an
explicit first day (`Y-m-01`), never derived from today. The canonical overlap, identical to the repository's
existing `[from, to)` semantics, is:

`from < next_month_start AND (to IS NULL OR to > month_start)`

Overlapping segments are clipped to `[max(from, month_start), min(to, next_month_start))`.
Boundaries: starting on the first day or the last day overlaps; ending ON `month_start` does not overlap; ending
AT `next_month_start` covers the whole month; starting AT `next_month_start` does not overlap; a one-day
interval overlaps iff `month_start <= d < next_month_start`; an open-ended interval overlaps iff
`from < next_month_start`.

## §S37.3 Canonical population grain: Person

The counting grain is the **Person**. A Person belongs to the monthly population when at least one of their
Employment Relationships overlaps the month. **Relationship effectivity is the only inclusion criterion**:
employment status is never used for membership, and `counts_in_monthly_reporting` is never read
(`ref.employment_status_detail_behaviors.counts_in_monthly_reporting` stays NULL and untouched; it is a
per-status behavior attribute, not a population rule — S06 §4.2/§33).

Exactly one row per Person. A reappointment in the same month yields one row with several isolated
relationship segments (they may touch at a boundary, which the per-person GiST EXCLUDE permits). Relationship-
owned facts are never merged across relationships. `national_id` is not exposed (PII is an output decision);
`persons_national_id_unique` keeps one Person per national id in PostgreSQL itself.

## §S37.4 Relationship segments

Each overlapping relationship is exposed as a nested segment: `employment_relationship_id`, employment type
(id, code, employee-number scheme), the original `effective_from`/`effective_to` (provenance), the clipped
`[clipped_from, clipped_to)`, `end_knowledge_state`, `ended_terminally`, and `end_is_uncertain`.

An `UNKNOWN_LEGACY` relationship (end unknown, `effective_to` NULL) stays explicitly uncertain: no end date is
fabricated, `end_is_uncertain = true`, and its `clipped_to` is merely the month bound.

## §S37.5 Status segmentation

Within a relationship's ACTIVE days inside the month each day is exactly one of:

- **EXPLICIT** — covered by a persisted `hr.employment_status_periods` row (any code, `on_duty` included);
- **DERIVED_ON_DUTY** — the S32 read-time return: after an allow-listed bounded period ends with no explicit
  successor, `on_duty` holds until the next explicit period starts (the latest period starting on/before the day
  has ended on/before it and its code is in `BoundedEmploymentStatusPolicy`). Never persisted; no id;
- **UNRESOLVED** — neither.

`UNRESOLVED` is mandatory where active time has no explicit status and no valid derived interval. "No status
row" is never read as `on_duty` and never as not-on-duty. The repository confirms gaps are real:
`CreateEmploymentRelationship` records no status and the first status must start strictly after the
relationship's own start. No synthetic `on_duty` row is ever created.

## §S37.6 Person/month duty classification

Evaluated only over days on which a relationship is effective:

| value | meaning |
|---|---|
| `HAS_ON_DUTY` | at least one explicit or valid S32-derived `on_duty` interval inside any active relationship segment |
| `INDETERMINATE` | no on_duty interval AND at least one active day is not deterministically covered (an UNRESOLVED gap, or an `UNKNOWN_LEGACY` relationship) |
| `NO_ON_DUTY` | no on_duty interval AND every active day is covered by a known non-on_duty status |

Precedence: `HAS_ON_DUTY > INDETERMINATE > NO_ON_DUTY`. Calendar days outside employment relationships are
irrelevant. `on_duty` means the status code `on_duty` (explicit or derived).

Disclosed decision: because an `UNKNOWN_LEGACY` relationship's active days cannot be asserted, it can never make a
Person `NO_ON_DUTY`; with no on_duty interval it yields `INDETERMINATE`.

## §S37.7 Reasons (per relationship, never one monthly reason)

- `last_non_on_duty_reason`: the latest EXPLICIT non-`on_duty` segment within that relationship's active days
  inside the month (segments never overlap, so the choice is deterministic). Explicit and derived `on_duty` are
  excluded; a terminal status beginning at the relationship end lies outside the active days and is never used.
  Carries `status_period_id`, `status_detail_id`, `status_code`, `segment_from`, `segment_to` (clipped).
- `relationship_end_reason`: the status period beginning exactly at this relationship's KNOWN end, exposed when
  that end falls strictly inside the month (`month_start < effective_to < next_month_start`). An end at `next_month_start` (E = N) is the first boundary of the NEXT month: the relationship stays effective through day N−1 (clipping unchanged) but its end reason is null for this month, and in the next month the relationship no longer overlaps, so nothing is attached retroactively. S10 makes the
  ending status start on the end date. It is `null` when the relationship ended without an ending status or the
  end is outside the month. Carries the period/detail ids, code, `effective_from`, `ended_terminally`.

No precedence exists between the two; reappointments keep their reasons isolated.

## §S37.8 Workplace segments

Workplace history is exposed as temporal interval segments. The month is cut at every date on which placement,
Full Secondment, Workplace Assignment or Partial Secondment changes, and each sub-interval is resolved by the
SAME single decision table S27/S30 use, `ActualWorkplaceAsOf::fromEffectiveFacts` (no competing monthly
precedence). States: `RESOLVED`, `UNRESOLVED`, `AMBIGUOUS_MOVEMENT_STATE` (competing movements listed, no
winner), `PARTIAL_ALLOCATION` (each Partial Secondment's destination and weekday codes in ISO order, plus the
underlying placement unit). Transfer is represented through placement history. Adjacent identical results are
merged. Work Schedule is exposed as separate interval segments: `RESOLVED` (weekday codes) or `NOT_RECORDED`
(never a default week).

Nothing is counted: no percentage, no allocated-day total, no assumed working day.

## §S37.9 Person facts

`gender_id`; `birth_date` as the existing DATE fact (null when unrecorded; no age, no band). Qualifications are
S36's **current recorded Person facts**, ordered `(created_at, id)` (technical order, not a ranking).

**Limitation, frozen and machine-readable:** qualifications are NOT "as of the month". They carry no dates;
`created_at` is never an effective date. The result and every Person row carry
`qualification_semantics = CURRENT_RECORDED_PERSON_FACTS` so a consumer cannot mistake a historical month for a
reconstruction.

## §S37.10 Multi-value monthly dimensions

No monthly value is selected (first, last, month-end or majority) for specialty, job title, contract, category or
cadre, because they may change inside the month. They are not exposed. The final REPORT-1/2/4/5 rules are
deferred.

## §S37.11 Query architecture and safety

Eight set-based statements, independent of population size (relationships, status periods, placements, full
secondments, workplace assignments, partial secondments with weekdays, work schedules, qualifications), each
restricted by the same relationship-overlap predicate; assembly is in memory by the pure Domain classes.
No per-person or per-relationship query (no N+1). Qualifications load separately and cannot multiply a row.
Order: persons by id; relationships by clipped start then id; segments chronological; qualifications by
`(created_at, id)`. Live query: nothing is materialized, cached or persisted, and the query writes no row and no
audit entry.

## §S37.12 Schema, API and output

No migration, table, column, view, materialized view, seed, route, controller, permission, frontend, scheduler,
export, PDF, XLSX, print or dashboard. The S27 scope question (organizational scope for a combined reporting
endpoint) is untouched: there is no principal and no HTTP surface.

## §S37.13 Tests

`tests/Feature/HumanResources/MonthlyWorkforceReportingFoundationTest.php` (real PostgreSQL, synthetic data):
interval boundaries, Person grain and reappointment, status segmentation and duty classification, reasons,
workplace/partial/schedule segments, person facts, the counts-flag guard, schema/route/output guards, S27
contract and day-by-day parity, S30/S32/S34/S35/S36 regressions, constant query count and deterministic order.
Ten controlled mutations were verified to fail the suite and restored byte-for-byte.

## §S37.14 Deferred (not opened by this document)

Final reports, aggregation, drilldown and output; any rule for multi-value dimensions; workplace day counts or
percentages; age and age bands; historical qualification reconstruction; a reporting API and its
organizational-scope rule; status-expiry follow-up; indexes (none added; plans should be measured on production-
scale data).
