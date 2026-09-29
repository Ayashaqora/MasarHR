# S32 — Bounded Temporary Employment Status Lifecycle (specification)

Stage: S32. Baseline: `91bcfaab588edb482007e8441605c08c22e581ea` (S31). Migrations: **none**.
Governing decisions: ADR-S32-001…022 (Architecture Authority, frozen). This document does not rewrite any
earlier stage document; it extends S10 (`employment-status-history-foundation-specification.md`) additively.

## S32.1 Scope

Reuse `hr.employment_status_periods` unchanged (DATE columns, half-open `[from,to)`, per-relationship
PostgreSQL EXCLUDE, CHECK `to > from`, RESTRICT FKs, relationship row lock). The columns, CHECK and EXCLUDE
already support bounded periods, so **no schema change is required**. The gap closed here is in the
command, the API and the read model only.

## S32.2 Bounded-status allow-list (ADR-S32-002)

| code | `effective_to` |
|---|---|
| `traveling` | optional |
| `suspended` | optional |
| `unpaid_leave` | **required** |
| `external_sick_leave` | **required** |
| `captive` | rejected (stays open-ended) |
| every other code (`on_duty`, `wants_to_return`, `does_not_want_to_return`, `retired`, `resigned`, `contract_ended`, `martyred`, `deceased`) | rejected |

The allow-list is a code constant (`BoundedEmploymentStatusPolicy`), not a `ref.*` schema change. Legacy open
rows are preserved as-is; no end is ever fabricated for them.

## S32.3 Record command — validation order (deterministic)

`RecordEmploymentStatusPeriod::handle(person, relationship, statusDetail, effectiveFrom, ?effectiveTo = null)`:

1. Lock the relationship row (`FOR UPDATE`). KNOWN end → `EmploymentRelationshipAlreadyEndedException` (409). This
   also subsumes "a status must not extend beyond a KNOWN relationship end". UNKNOWN_LEGACY never fabricates an end.
2. Temporal-semantics policy: `effective_to` given for a code that does not support it → 422 (`errors.effective_to`);
   required and absent → 422.
3. `effective_to` strictly after `effective_from` → else 422.
4. `effective_from` strictly after the relationship's `effective_from` (S10).
5. Stream rules: (R2) any existing period with `effective_from >= new from` → `InvalidStatusPeriodDateException`
   (future recorded history is never silently rewritten; reject atomically). (R3) the period covering the new
   start is truncated at the new start. A new **bounded** period that would lie strictly inside a covering
   **bounded** period (`new.to < covering.to`) is rejected (it would need a split = rewrite).
6. S06 behavior resolution (unchanged; unresolved → 422 before any write).
7. Truncate covering period, insert the new period (EXCLUDE/CHECK violations → 422, DB stays authoritative).
8. Relationship-ending/terminal behaviors (only possible for unbounded codes) call S15 `EndEmploymentRelationship`
   in-process, as before.

Temporal write examples:
- `on_duty [Jan,∞)` + `unpaid_leave [Oct 1, Nov 1)` → `on_duty [Jan,Oct 1)`, `unpaid_leave [Oct 1,Nov 1)`; **no**
  persisted `on_duty` row afterwards.
- `unpaid_leave [10-01,11-01)` then explicit `traveling` from `11-01` → two persisted rows, explicit successor wins.

## S32.4 Derived return (ADR-S32-003)

After a bounded period ends with no explicit successor the effective status is **derived** `on_duty`. It is a
read-time consequence only: no persisted row, no id, recorded_at, actor or decision, no audit event when time
passes. Resolution as of date D (`ResolveEffectiveEmploymentStatusAsOf`, value object `EmploymentStatusAsOf`):

1. a persisted period covers D → that period (`derived=false`);
2. else, the relationship is effective on D (started, no KNOWN end ≤ D) and the latest period starting ≤ D ended
   ≤ D **and** its code is on the allow-list → derived `on_duty` (`derived=true`, `derived_from_period_id`);
3. else unresolved (`null`) — legacy gaps and closed non-bounded periods are never re-interpreted.

The history endpoint continues to return persisted rows only. S27 (`ListReportingPopulationAsOf`) applies the same
rule in its single SQL statement; `ReportingPopulationRow` gains `statusDerived` and `derivedFromStatusPeriodId`
(`statusPeriodId` is `null` for derived). R1–R5 rules and monthly aggregation are untouched.

## S32.5 Independence (ADR-S32-004)

A status never creates, cancels or recreates a Transfer, Full Secondment, Assignment or Partial Secondment and never
modifies the Work Schedule; expiry of a bounded status touches nothing (there is no write at expiry). Permanent tests
assert movement/schedule rows are byte-identical across record and across expiry.

## S32.6 Relationship end (ADR-S32-005)

`EndEmploymentRelationship` status consequence generalises to bounded periods: a period starting on/after the end
date is rejected (`InvalidEndDateException`, atomic) except the open ending status starting exactly at the end date
(S10, unchanged); a period spanning the end date (open or bounded) is truncated to the end date. No status can then
extend beyond the known end.

## S32.7 API (ADR-S32-006/007)

`POST …/status-periods` accepts optional `effective_to` (`Y-m-d`, must be after `effective_from`). Unknown temporal
keys (`end_date`, `effective_until`, `duration_days`, `duration`, `is_temporary`, `return_date`,
`expected_return_date`, `auto_return`) are prohibited (422). `effective_to` is never silently ignored. No generic
PATCH; no project-wide strict-JSON change. New read: `GET …/effective-status?as_of=Y-m-d` (`hr.employment_status_periods.view`)
returning persisted vs derived explicitly. No new permission.

## S32.8 Audit (ADR-S32-008)

Only the explicit record command is audited (`hr.employment_status_period.record`); `effective_to` (a DATE or null)
is added to `changes`. No event when a bounded status expires; no PII added.

## S32.9 Deferred / hard exclusions

Status-expiry alerts and S31 schema extension; leave aggregate, `ref.leave_*`, balances, accrual, approvals, payroll;
`RETURN_INTENTION_MODEL_DEBT`; `S15_BOUNDED_MOVEMENT_RELATIONSHIP_END_DEBT` (bounded/future Full/Assignment surviving a
relationship end — unchanged, documented, out of scope); monthly reporting / `counts_in_monthly_reporting`; R1–R5,
XLSX/PDF/dashboard/exports; Employee360 redesign (no frontend file consumes employment status; none changed).

## S32.10 Disclosed design decisions for Architecture Gate

- Requiring `effective_to` for `unpaid_leave`/`external_sick_leave` changes existing S10/S19/S27 tests that recorded
  them open-ended; those fixtures now pass an end date (no production data affected; legacy rows stay valid).
- Truncating a covering bounded period at a later-starting new period is allowed (supersession by an explicit new
  command); a bounded period strictly inside a bounded one is rejected rather than split.
