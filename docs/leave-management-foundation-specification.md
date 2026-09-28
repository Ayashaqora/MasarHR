# Leave Management Foundation (S19) — Version 1.0

## S19.0 Provenance / Reconstruction Disclosure

This document is written under the "MASARHR — S19 COMPLETE STAGE AUTHORIZATION — S19 — LEAVE
MANAGEMENT FOUNDATION" authorization. That authorization itself states it is a *reconstructed*
stage title based on the frozen MasarHR analysis. This document does not claim the historical
roadmap wording for S19 has been recovered, and does not claim any fact about frozen business
rules beyond what is directly evidenced in the repository (migrations, seeded reference data,
existing domain code, and prior stage specification documents, principally S06, S10, S15, S16 and
S17).

## S19.1 Source Evidence Classification

The S19 authorization names exactly two frozen temporary leave conditions:

- إجازة بدون راتب — unpaid leave — reference code `unpaid_leave`.
- إجازة خارجية مرضية — external sick leave — reference code `external_sick_leave`.

Both codes are FROZEN by direct database evidence, not by inference:

- Both are seeded rows in `ref.employment_status_details`
  (`2026_09_26_000026_seed_ref_employment_status_details.php`), category `non_active`.
- Both have open-ended, category-derived `ref.employment_status_detail_behavior_periods` rows
  (`2026_09_26_000027_seed_ref_employment_status_detail_behaviors.php`), effective from
  2026-09-26, with `participates_in_active_workforce = false`, `is_ongoing_relationship = true`,
  `is_relationship_ending = false`, `is_terminal = false`.

The authorization also names `ref.leave_types` / `ref.leave_statuses` only implicitly, via its own
prohibition on inventing "balances, accruals, payroll deductions, approval chains, attachments, or
entitlement calculations". Those two tables are S05 structure-only catalogs
(`2026_09_26_000013_create_ref_leave_types_table.php`,
`..._000014_create_ref_leave_statuses_table.php`, both documented in their own migration
docblocks as "DEFINED STRUCTURE / VALUES DEFERRED §5.3") with zero seeded rows and zero
references anywhere in the HumanResources module as of this stage — reconfirmed directly (S19
discovery) via `LeaveType::count()` / `LeaveStatus::count()`, both 0. They are DEFERRED, not
FROZEN, and are not touched by this stage.

## S19.2 Repository Discovery

Full discovery covered: all 52 backend migrations; the S10 `EmploymentStatusPeriod` domain and
`RecordEmploymentStatusPeriod` application command; the S15 lifecycle-consequences additions to
`EndEmploymentRelationship`; the S16 workplace-assignment/actual-workplace model; the S17
Temporary Employment Status Periods Foundation specification and its own closure record; `ref.*`
reference catalogs and their versioned-behavior model (S06); `hr.*` audit infrastructure;
Security/RBAC permission catalog and organizational-scope model; automation/scheduler
infrastructure (`app/Console`, `routes/console.php`, composer.json); `/api/v1` route conventions;
the existing `EmploymentStatusHistoryTest.php` suite; and the Employee 360
`Employee360StatusHistory.tsx` frontend component.

Central discovery question, directly from the authorization: **are the two S19-frozen leave
concepts already fully represented by the S10/S15/S17 employment-status-period architecture?**

## S19.3 Dependency Matrix

| Dependency | State found | Relevant to S19 |
|---|---|---|
| `hr.employment_status_periods` (S10) | Generic temporal child of `EmploymentRelationship`, half-open `[effective_from, effective_to)`, FK to `ref.employment_status_details` (no closed enum) | Directly — this is the candidate existing model |
| `RecordEmploymentStatusPeriod` (S10) | Single command: locks relationship, rejects if already ended, validates dates, resolves S06 behavior as-of date, closes open period, inserts new period, conditionally ends the relationship | Directly — the only write path for any status, including the two leave codes |
| `EndEmploymentRelationship` (S09/S15/S16) | Closes open Full Secondment, open Workplace Assignment, and open Employment Status period in one transaction at the same `effective_to` | Directly — governs §6 lifecycle interaction |
| `ref.employment_status_details` / behaviors (S06) | 13 seeded codes across 4 categories; `unpaid_leave` and `external_sick_leave` both present with correct `non_active` behavior | Directly — the two frozen leave codes already exist here |
| S17 spec + closure record | Asked and answered the same question for 5 named states including both S19 codes; concluded no new persistence required; closed a narrow test-coverage gap (ADR-S17-002) | Directly — S19's central question was already substantially answered by S17 |
| `ref.leave_types` / `ref.leave_statuses` (S05) | Structure-only, zero rows, zero references | Not touched — DEFERRED, out of S19 scope |
| Automation/scheduler infra | Absent (`app/Console` empty save for stock `inspire`; no Job/Notification classes; no queue/schedule packages) | Confirms §8 "automatic expiry" is correctly N/A — nothing was ever frozen to automate |
| `HumanResourcesPermissionCatalog` | `hr.employment_status_periods.view` / `.record` already exist and already gate the only relevant write/read path | Directly — no new permission needed |
| `Employee360StatusHistory.tsx` | Renders `hr.employment_status_periods` generically via reference `name_ar`/`name_en`, zero hardcoded status codes | Directly — both leave codes already display correctly, no UI change needed |

## S19.4 Gap Matrix

Cross-referencing the full §15-required test matrix against the existing
`EmploymentStatusHistoryTest.php` suite (S10/S15/S17), item by item:

| Required case | Status before S19 |
|---|---|
| Create temporary leave/status period (future-dated, backdated, general) | Covered generically (`on_duty`, `traveling`, etc.) and specifically for `unpaid_leave`/`external_sick_leave` (S17's `test_each_previously_unexercised_non_active_status_participates_correctly_in_the_stream` / `..._is_recordable_via_the_api`) |
| End/expire a period; historical record preserved; current-state derivation | Covered generically and for the S17 five-state set |
| Leave + active movement coexistence; leave end does not cancel movement | Covered structurally: `RecordEmploymentStatusPeriod` has zero references to any movement table for ANY transition (entry or exit uses the identical code path), and is directly tested against Full Secondment, Organizational Placement, and Workplace Assignment for `unpaid_leave`/`suspended` |
| Employment termination consequences | **GAP** — `test_ending_the_relationship_directly_now_closes_the_open_status_period` proves the S15 `closeOpenStatusPeriodIfAny()` consequence, but only using `on_duty`; no existing test names either S19-frozen leave code in this exact scenario |
| Newer decision defeats stale expiry; duplicate/idempotent automation | N/A — no automation exists or was ever frozen (§8 confirmed empty) |
| Overlapping/conflicting periods; inactive/ended/terminal employment restrictions | Covered generically (date-ordering and already-ended-relationship rejection tests apply to every status code uniformly) |
| Authorization; organizational scope; audit; concurrency | Covered generically; organizational scope is N/A per S10's own architecture (`EmploymentRelationship` carries no organizational-unit column) |
| Reference behavior/versioning | Covered by S06's own test suite; unaffected by S19 |
| Regression of S10/S15/S17/S16 movement behavior | Covered — the full existing suite (1256 tests before this stage) exercises exactly this |

The single genuine, narrow gap identified: **employment-termination-closes-open-status-period**,
never exercised using `unpaid_leave` or `external_sick_leave` specifically.

## S19.5 Core Discovery Question — Answered

**Yes.** The two S19-frozen leave concepts are already fully represented by the S10/S15/S17
`EmploymentStatusPeriod` architecture: seeded reference data, correct behavior semantics, a single
generic write command, existing lifecycle-consequence wiring, existing movement independence,
existing generic UI rendering, and (after this stage) complete test coverage of the one previously
narrow gap.

## S19.6 ADR-S19-001 — Leave Management Foundation Boundary

**Status:** DECIDED.

**Decision: Outcome A.** The existing S10/S15/S16/S17 `EmploymentStatusPeriod` architecture is
authoritative for both S19-frozen leave concepts. No distinct Leave aggregate, no new table, no
new migration, no new command, no new permission, no new API route, and no new UI screen is
introduced by this stage.

**Evidence for A over B** (per the authorization's own instruction that B must be justified against
an already-frozen requirement, not merely because a module named "Leave" appears in
documentation):

1. Both frozen leave codes are already seeded, with already-correct behavior semantics, since S06.
2. The single existing write command (`RecordEmploymentStatusPeriod`) already provides every
   required temporal property: half-open effective periods, no destructive overwrite, historical
   preservation, current-state derivation, transactional consistency.
3. S17 already asked and answered this exact question for a superset of states (5, including both
   S19 codes) and reached the same conclusion, independently re-verified in this stage rather than
   taken on faith.
4. No source requirement in the S19 authorization names any capability the existing architecture
   lacks: balances, accrual, approval chains, and attachments are explicitly named as things NOT
   to invent, and `ref.leave_types`/`ref.leave_statuses` (the only schema that would suggest a
   distinct aggregate) remain empty and unreferenced.
5. Movement independence is structural, not incidental: `RecordEmploymentStatusPeriod` contains
   zero references to any of the three movement tables, for any status code — a distinct Leave
   aggregate would have to re-implement this guarantee rather than inherit it for free.

**Consequence (§4 of the authorization):** "S19 may be a consolidation/completion stage and may
legitimately require little or no new persistence." This stage requires none.

## S19.7 Temporal Rules (authorization §5)

Unchanged from S10/S15: business dates are `DATE`, audit timestamps are `TIMESTAMP`, effective
periods are half-open `[effective_from, effective_to)`, historical periods are preserved (never
overwritten), backdated/future changes are validated against the relationship's own
`effective_from` and the currently-open period's `effective_from`, current state is derived (the
row with `effective_to IS NULL`), and every write is transactional. No fabricated legacy/end
dates are introduced by this stage; `UNKNOWN_LEGACY`/known-end semantics are unaffected.

## S19.8 Lifecycle Interaction (authorization §6)

`EndEmploymentRelationship` (S09, extended S15 §8.3) already closes an open Employment Status
period — of any code, including both leave codes — at the same `effective_to` as the relationship,
in the same transaction as it closes an open Full Secondment and Workplace Assignment. This stage
adds no new automation and reuses this exact mechanism unmodified. The one prior gap — this
consequence never being tested with a leave-code period specifically — is closed by
`test_ending_the_relationship_directly_closes_an_open_leave_like_status_period` (see §S19.14).

## S19.9 Movement Interaction (authorization §7)

Leave and workplace movement remain structurally separate. `RecordEmploymentStatusPeriod` never
reads or writes `hr.organizational_placement_periods`, `hr.full_secondment_periods`, or
`hr.workplace_assignment_periods`, for any status code, entering or leaving. Ending a leave period
does not restore, alter, or fabricate any workplace state; actual workplace resolution
(`ResolveActualWorkplaceForRelationship`) remains governed entirely by the movement/placement
model, independent of the status stream. Already directly tested (S15/S17) against Full
Secondment, Organizational Placement, and Workplace Assignment.

## S19.10 Automation / Expiry (authorization §8)

No automation or scheduler infrastructure exists in the repository (`app/Console` is empty save
for the stock `inspire` command; no Job/Notification classes; no queue/schedule package in
`composer.json`). Nothing was ever frozen requiring automatic expiry of a leave-like period. This
stage creates none, consistent with S17's own explicit classification of automatic
return/alerting as PROPOSED, not FROZEN. §8's idempotency/no-stale-action safety properties are
correctly N/A.

## S19.11 Reference Data (authorization §9)

Both leave codes are governed entirely by the existing `ref.employment_status_details` /
`ref.employment_status_detail_behaviors` versioned-behavior model (S06). No hard-coded Arabic
label participates in domain logic anywhere in the status-period write path; behavior is always
resolved as-of the transition date from the reference catalog. No historical reference value is
destructively deleted by this stage.

## S19.12 API (authorization §10)

The existing `/api/v1/hr/persons/{person}/employment-relationships/{relationship}/status-periods`
endpoints (S10) already fully satisfy S19: `POST` records a period (used for either leave code by
passing its `status_detail_code`), `GET` lists history, and no `PATCH`/`DELETE` route exists
(confirmed unchanged by `test_no_patch_or_delete_route_exists_for_status_periods`). No `/leave`
route is introduced. Every write already requires authentication, the
`hr.employment_status_periods.record` permission, relationship-scoped 404 (IDOR) protection, and
full validation; every read already requires `hr.employment_status_periods.view`. No new API
surface is added by this stage.

## S19.13 Security (authorization §13)

No new permission is introduced. The existing `hr.employment_status_periods.view` and
`hr.employment_status_periods.record` permissions already gate the entire read/write surface for
both leave codes, with no wildcard grant. No local auth bypass and no default credential is
introduced or relied upon by this stage.

## S19.14 UI (authorization §11)

No visual-runtime effort is resumed and no new screen is introduced. Employee 360's
`Employee360StatusHistory.tsx` already renders `hr.employment_status_periods` generically via the
reference catalog's `name_ar`/`name_en` fields, with zero hardcoded status codes — both
`unpaid_leave` and `external_sick_leave` already display correctly with no code change, satisfying
the authorization's explicit preference to reuse an existing suitable read-only place over adding
a competing screen. No frontend file is touched by this stage.

## S19.15 Reporting Compatibility (authorization §12)

No reporting wave is implemented by this stage (none was in scope). The existing model already
keeps reporting-relevant temporal state (participates-in-active-workforce, ongoing-relationship,
relationship-ending, terminal — all derived from the S06 versioned-behavior model rather than
denormalized into the transactional tables), so nothing in this stage narrows or breaks
compatibility with the frozen reporting rules referenced in the authorization (monthly workforce
classification, not-on-duty reasons, historical as-of reporting, unique headcount, temporal status
reasoning). No report-specific field is added to any transactional table.

## S19.16 Migrations (authorization §14)

None. Zero schema change, per the authorization's own explicit preference ("prefer zero schema
change if architecture already satisfies the model") — directly satisfied here, not merely
invoked as an excuse: every fact in §S19.6 above is independently evidenced, not assumed.

## S19.17 Test Plan / Matrix (authorization §15)

See the full item-by-item Gap Matrix at §S19.4. One new test was added, test-only, zero production
code:

- `test_ending_the_relationship_directly_closes_an_open_leave_like_status_period`
  (`backend/tests/Feature/HumanResources/EmploymentStatusHistoryTest.php`) — exercises the
  existing, unmodified S15 `closeOpenStatusPeriodIfAny()` consequence with both
  `unpaid_leave` and `external_sick_leave`, asserting the open period is closed at the
  relationship's own `effective_to` and preserved as history (not deleted or replaced), via the
  real `/end` HTTP endpoint.

All other required test-matrix items were confirmed already covered by the pre-existing S10/S15/
S16/S17 suite, or confirmed structurally/architecturally N/A (automation, organizational scope),
per the reasoning in §S19.4 — no redundant or invented test was added.

## S19.18 Adversarial Review (authorization §16)

1. **Are we duplicating `EmploymentStatusPeriod`?** No. ADR-S19-001 explicitly chose Outcome A;
   zero new table, aggregate, or persistence path is introduced.
2. **Can leave expiry resurrect an obsolete state?** No automatic expiry exists or is introduced;
   every transition is an explicit, authorized `RecordEmploymentStatusPeriod` call, which always
   validates against the currently-open period's own date before writing.
3. **Can stale automation override a newer status?** N/A — no automation exists.
4. **Can leave incorrectly change workplace?** No — structurally impossible, per §S19.9:
   `RecordEmploymentStatusPeriod` contains zero references to any movement table, verified by
   direct code reading and by existing tests asserting unchanged `effective_to`/destination/count
   on Full Secondment, Placement, and Workplace Assignment across a leave-code transition.
5. **Can terminated employees retain active leave?** No — `RecordEmploymentStatusPeriod` rejects
   any write against an already-ended relationship
   (`EmploymentRelationshipAlreadyEndedException`), and `EndEmploymentRelationship` itself closes
   any still-open status period (including a leave-code one) at the same `effective_to`, now
   directly tested for both leave codes (§S19.17).
6. **Can historical leave disappear?** No — no delete/overwrite path exists for
   `hr.employment_status_periods`; every closure is an `effective_to` update on the existing row,
   the new test explicitly asserts the row count is preserved (not deleted or replaced) after
   closure.
7. **Can backdated changes corrupt later periods?** No — `RecordEmploymentStatusPeriod` rejects
   any `effective_from` not after the relationship's own `effective_from` and not after the
   currently-open period's own `effective_from` (`InvalidStatusPeriodDateException`), unchanged by
   this stage and covered by pre-existing tests.
8. **Can two concurrent writes bypass temporal constraints?** No — `RecordEmploymentStatusPeriod`
   locks the relationship row before validating/writing (unchanged S10 behavior); this stage
   introduces no new write path that could bypass that lock.
9. **Can authorization/scope be bypassed?** No — the existing `hr.employment_status_periods.view`/
   `.record` permission checks and relationship-scoped IDOR protection are unchanged and apply
   identically to both leave codes; no new route bypasses them because no new route is introduced.
10. **Did we invent a business rule not present in frozen analysis?** No. No new leave category,
    balance, accrual, payroll deduction, approval chain, attachment, or entitlement calculation was
    introduced. The only change is one additional test exercising already-frozen, already-shipped
    behavior with the two already-frozen leave codes.

No blocker was found. All ten questions resolve cleanly under Outcome A.

## S19.19 Deferred

Unchanged from S05/S17: `ref.leave_types`/`ref.leave_statuses` remain structure-only and
unreferenced — reserved for a future, not-yet-frozen fuller leave-management expansion (balances,
accrual, approval chains, attachments, entitlement calculations). Automatic return/alerting on
leave expiry remains PROPOSED, not FROZEN (S17 §S17.11), and is not introduced by this stage. None
of this is authorized or begun by S19.

## S19.20 Acceptance Criteria

- Both S19-frozen leave concepts are fully representable via the existing, unmodified
  `RecordEmploymentStatusPeriod` write path and `hr.employment_status_periods` read path. **Met**
  (pre-existing, reconfirmed).
- Employment-termination correctly closes an open leave-like period, preserving history. **Met**,
  newly test-covered by this stage.
- No duplicate domain model, no new migration, no new permission, no new API route, no new UI
  screen. **Met** — zero of each introduced.
- Full backend regression suite passes. **Met** — 1257/1257 tests, 4054 assertions, verified on
  the mirror environment (real PostgreSQL, real PHP).
- Pint (code style) passes. **Met**.
- No static-analysis tool (phpstan/larastan) is configured in this repository as of this stage —
  N/A, not skipped.
- Frontend: no frontend file was changed by this stage — per the authorization's own §17
  conditional ("frontend tests/typecheck/lint/build only if frontend changed"), frontend gates are
  correctly not run.

## S19.21 Closure Record

ADR-S19-001 = Decision A, PASS. Final Architecture Gate (authorization §19): PASS — every frozen
source requirement is satisfied by the pre-existing architecture, no duplicate domain model was
introduced, temporal semantics remain coherent and unchanged, lifecycle/movement interactions are
correct and were re-verified, the full test suite (including one new, narrowly-scoped test) passes,
no unresolved blocker exists per §S19.18, and no business rule beyond the frozen analysis was
invented.
