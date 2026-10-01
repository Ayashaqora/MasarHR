# S40 — Monthly Workforce Multi-Value Dimensions Semantics Foundation

Stage: S40. Baseline: `77ecb16c2c968d7f60e7041caef3b8e35041622b` (S39). Migrations: **none**. Routes, controllers, resources,
permissions, scheduler, frontend and output layers: **none**.
Governing decisions: the Architecture Authority's S40 full-implementation authorization (frozen). This document extends S37
(`monthly-workforce-reporting-semantics-foundation-specification.md`) additively and redefines nothing in it.

## §S40.1 Purpose

An INTERNAL, READ-ONLY enrichment of the canonical S37 monthly Person population with the **temporal workforce dimensions** the
future official monthly reports need as shared evidence: employment category, contract (type), job title and specialty, plus the
three temporal reference mappings specialty → cadre category, job title → administrator classification and contract type →
population category. `ListMonthlyWorkforceDimensions` returns the immutable, never-persisted
`MonthlyWorkforceDimensions` → `MonthlyDimensionPersonRow` → `MonthlyDimensionRelationship` structure.

## §S40.2 Non-goals

S40 is **not** a report and is not R1 (Monthly Human Cadre), R2 (Administrative), R4 (Support Services / Daily Workers) or R5
(Volunteers / Unemployment). It has no totals, grouping, percentages, ages, experience, organizational aggregation, route,
controller, API resource, permission, frontend, XLSX/PDF/CSV/print or dashboard, no schema object, and no report-specific
population or grouping rule. It selects **no scalar monthly value** (no first, last, month-end or majority rule).

## §S40.3 Canonical S37 dependency

S37 stays authoritative and frozen for the monthly Person population, relationship windows, status, duty classification,
workplace and work schedule. S40 calls `ListMonthlyReportingPopulation` **exactly once** (its eight-statement contract is
unchanged), reads the relationship ids and clipped windows from the result, and enriches them. It contains no inclusion,
status, duty or workplace logic, re-reads no S37 table, and neither changes nor copies S37. The Person set and order are S37's.

## §S40.4 Grain

One row per **Person** (S37 order). Each Person carries its relationships (S37 order), and each relationship carries four
**relationship-local** histories:

```
Person
  -> relationships[]
      -> categorySegments[]  contractSegments[]  jobTitleSegments[]  specialtySegments[]
```

Dimension periods are never flattened into Person rows. Same-Person reappointment is two relationships; each reads only its
own periods (nothing leaks across relationships).

## §S40.5 The four temporal dimensions (existing tables)

| Dimension | Table | Value | Notes |
|---|---|---|---|
| employment category | `hr.employment_category_periods` | `employment_category_id` | |
| contract | `hr.employment_contract_periods` | `contract_type_id` | also `contractual_effective_to`, `contract_end_knowledge_state` |
| job title | `hr.employment_job_title_periods` | `job_title_id` | also `start_knowledge_state` |
| specialty | `hr.employment_specialty_periods` | `specialty_id` | |

Every table is DATE-based, half-open `[effective_from, effective_to)`, with a PostgreSQL GiST `EXCLUDE` per relationship, so
periods of one stream cannot overlap. Each segment exposes `from`, `to` (clipped), `state`, `period_id`, the recorded
`effective_from`/`effective_to` (provenance, never rewritten), and the dimension `value` (`id`, `code`, `name_ar`, `name_en`).

## §S40.6 Clipping

Window: the relationship's S37 month window `[clipped_from, clipped_to)` = relationship ∩ `[month_start, next_month_start)`.
Every segment is half-open and the segments of a dimension **tile the window exactly** (contiguous, non-empty). A period
ending exactly at `month_start` or starting exactly at `next_month_start` does not touch the month; one ending exactly at
`next_month_start` covers it through the last day; open-ended periods run to the window end. Boundary behavior is
`TemporalConstraints` (`[)`). Gaps are never filled, and nothing is extrapolated backward or forward.

## §S40.7 Raw resolution states

- `RESOLVED` — a recorded period covers the interval.
- `NOT_RECORDED` — the dimension applies, but no period covers the interval (a gap, or nothing recorded). Two periods with the
  same value on either side of a gap do not close it.
- `NOT_APPLICABLE` — the dimension does not apply by an already-frozen domain rule. The only case: **the contract dimension on a
  relationship whose `employee_number_scheme` is not `CONTRACT`** (S21: only CONTRACT relationships record contracts). It is never
  reported as `NOT_RECORDED`.

There is no operational `AMBIGUOUS` state: the exclusion constraints already forbid overlap. Data that contradicts a database or
domain invariant (two periods of one stream or one mapping overlapping, or a contract recorded where the dimension does not apply)
raises `InconsistentDimensionHistoryException` and fails explicitly; no value is ever silently selected.

## §S40.8 Contract: agreed term versus actual validity

A contract segment keeps **both** the actual validity (`effective_from`/`effective_to`, and the clipped `from`/`to`) and the agreed
term end `contractual_effective_to` plus `contract_end_knowledge_state`. They are different facts: a renewal or a relationship end
closes the actual validity early while the agreed term is untouched, and the term end is never equated with the relationship end. A
contract that expires while employment continues is a `NOT_RECORDED` gap that S40 never fills automatically.

## §S40.9 Job title: UNKNOWN_LEGACY

Each job-title segment carries `start_knowledge_state`. For `UNKNOWN_LEGACY` the period's `effective_from` is an evidence boundary,
not the true historical start (S22 §S22.19a); S40 keeps it visible and does not back-fill before it.

## §S40.10 Temporal mappings: RESOLVED / UNMAPPED

Mappings are resolved **as of each sub-interval**, never with today's mapping for the whole month. A `RESOLVED` dimension segment is
cut at every date on which a mapping of its value starts or ends; each sub-segment carries a block
(`cadre_category` | `administrator_classification` | `population_category`) with `state`:

- `RESOLVED` — a mapping covers the sub-interval (`mapping_id`, its period, and the `target` — or `is_administrator`, a boolean
  where `false` is a resolved classification);
- `UNMAPPED` — no mapping covers it. It stays explicit and is never mapped to "other", and no target or boolean is invented.

`NOT_RECORDED`/`NOT_APPLICABLE` segments carry no mapping (`null`). The mappings are loaded only for the values in play. S40 adds no
snapshot or versioning infrastructure.

### Accepted limitation (not repaired here)

A mapping can currently be recorded **retroactively** (the three `Define*` mapping commands validate no `effective_from`; only the
exclusion constraint guards overlap). Re-running a historical month after such a write can change its classification. S40 reports the
mapping as recorded today, as-of each sub-interval; it does not freeze what was true when a month was first reported.

## §S40.11 Labels: CURRENT_RECORDED_LABEL

Segments expose stable ids and codes and, for convenience, `name_ar`/`name_en` joined from the catalog at read time. Every label
carries `label_semantics = CURRENT_RECORDED_LABEL` (also on the result): labels are current catalog values, editable by catalog
administration, not historical snapshots. They are never copied into any persistence.

## §S40.12 Query architecture

A **constant 15 statements**, independent of population size: S37's 8, the four period streams (each `JOIN`ed one-to-one to its
catalog) and the three mapping reads (each joined to its target catalog), every one bounded by `= ANY(CAST(? AS uuid[]))` over the
relationship ids (or the dimension value ids in play) and the same half-open overlap predicate S37 uses. Segmentation is pure,
deterministic and in memory (`MonthlyDimensionSegmentation`). No per-Person, per-relationship or per-segment query, no use of the
single-relationship S27 resolvers, and no flat join across streams. No index is added (the existing `employment_relationship_id`
indexes cover the streams; no measurement shows a need).

## §S40.13 Schema

None: no migration, table, column, index, view, materialized view or reporting persistence. The S39 permission-seed migration remains
the latest of the 86 migrations.

## §S40.14 Scope guards

Tests assert that no route, controller, resource or permission mentions the S40 classes; no frontend file consumes them; no
XLSX/PDF/CSV/print/dashboard/export class and no R1/R2/R4/R5 report class exists; the S40 query contains no population logic, S37
table reads, aggregation, percentage, age or scalar-selection word; and S37's production file knows nothing of S40 and still issues
eight statements.

## §S40.15 Tests

`tests/Feature/HumanResources/MonthlyWorkforceDimensionsFoundationTest.php` (44 tests): month boundaries, clipping, one value, a
mid-month change, several changes, gaps, `NOT_APPLICABLE`, contract expiry gap, term preserved, `UNKNOWN_LEGACY`, mappings (resolved,
unmapped, changing mid-month, never "other"), multiple relationships, reappointment, Person uniqueness and S37 parity, no leakage,
explicit failure on corrupt history, S37 reused once, constant 15 statements and bounded batches, read-only, and the schema/scope
guards. Twelve controlled mutations (scalar collapse, gap filling, current-mapping-for-all, Person duplication, N+1, `NOT_APPLICABLE`
collapse, dropped contractual term, hidden `UNKNOWN_LEGACY`, unmapped-as-mapped, a second S37 call, tolerated overlap, an accidental
route) each failed the suite and were restored byte-for-byte.

## §S40.16 Deferred (not opened by this document)

R1/R2/R4/R5 final semantics, grouping, totals and drilldown; any rule choosing a representative value for a month; the retroactive
mapping limitation (§S40.10); mapping snapshots; a reporting API, permission and scope rule; frontend and output layers; age and
experience; organizational and workplace aggregation. No future stage is opened by this document.
