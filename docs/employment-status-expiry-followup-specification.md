# S38 — Temporary Employment Status Expiry Follow-up

Stage: S38. Baseline: `00a9f878ad8a019344b4b016d4edd4c2fcbfa8f4` (S37). Migrations: three (2026_10_16).
Governing decisions: the Architecture Authority's S38 full-implementation authorization (frozen), including the Owner's
decision that the status warning lead time is 7 calendar days. This document rewrites no earlier stage document; it
builds on S10/S32 (status periods), S31 (the follow-up pattern) and S35 (relationship end).

## §S38.1 Scope

Persisted **follow-up state** for eligible bounded temporary employment statuses approaching their recorded end. Nothing
else: no delivery (email, SMS, push, in-app), no frontend, no dashboard, no Employee360 change, no new status code, and no
change to S32's effective-status semantics, to S31, or to any movement. A persisted follow-up is **not** a delivered
notification.

## §S38.2 Eligibility (explicit allow-list)

`EmploymentStatusExpiryPolicy::ELIGIBLE_CODES` — an explicit list, never inferred from S32's generic "bounded" behaviour:

| code | eligible | rule |
|---|---|---|
| `traveling` | yes | only when `effective_to` exists |
| `suspended` | yes | only when `effective_to` exists |
| `unpaid_leave` | yes | its end is required by S32 |
| `external_sick_leave` | yes | its end is required by S32 |
| `captive` | **no** | source: no warning |
| every other code (`on_duty`, ended/terminal codes, retired return-intention codes, unknown/legacy codes) | no | not source-authorized |

Every eligible code additionally requires a non-null `effective_to`. A legacy open-ended row of an eligible code gets no
follow-up (no end is fabricated).

## §S38.3 Lead time (Owner decision)

**7 calendar days.** It is S38's own constant (`EmploymentStatusExpiryPolicy::WARNING_LEAD_DAYS`) and kind
`EXPIRY_WARNING_7D`; it is an independent status rule and does **not** reuse S31's movement policy (the class does not
reference `MovementExpiryPolicy`; a test asserts it). For an eligible status ending at E (`effective_to`, exclusive):
`due_date = E − 7` (DATE arithmetic, no hours/timezone), enforced by a PostgreSQL CHECK.

## §S38.4 Independence and S32

Status follow-ups never alter a transfer, Full/Partial Secondment, Workplace Assignment, Work Schedule, or an S31
follow-up (a test snapshots all of them across a scan). An employee may be on a temporary status and on an active movement
at once; the status follow-up lifecycle never terminates or modifies that movement. S32 is unchanged: when a bounded status
expires with no explicit successor, `on_duty` is **derived** at read time; S38 persists no synthetic `on_duty` and is
follow-up state only.

## §S38.5 Storage

New table `automation.employment_status_expiry_followups` (S31's table is not extended). Columns: `id`, `followup_kind`,
`employment_status_period_id`, `employment_relationship_id`, `expected_effective_to`, `due_date`, `status`,
`suppression_reason`, `created_at`, `suppressed_at`. No `updated_at`, no `organizational_unit_id`. Persisted states:
`ACTIONABLE`, `SUPPRESSED`. **`LAPSED` is derived at read time** (`ACTIONABLE` and business date ≥ `expected_effective_to`)
and never stored (the CHECK rejects it). Rows are append-only apart from the single `ACTIONABLE → SUPPRESSED` transition.

## §S38.6 Suppression reasons

`RELATIONSHIP_ENDED`, `TRUNCATED_EARLIER`, `SUCCESSOR_RECORDED` (`StatusFollowUpSuppressionReason`). S31's movement-only
`COVERED_BY_NEWER_MOVEMENT` is not copied. `ACTIONABLE` ⇒ reason and `suppressed_at` NULL; `SUPPRESSED` ⇒ a reason from
this set and `suppressed_at` required (CHECK).

## §S38.7 Successor at E

If an explicit status period of the same relationship already starts exactly at E when the scanner evaluates the period, **no
follow-up is created**. If an `ACTIONABLE` follow-up exists and such a successor is recorded later, it is suppressed with
`SUCCESSOR_RECORDED`. This is follow-up lifecycle only: S32's resolver is untouched (the explicit successor still wins).
A successor starting before E truncates the old period and is handled as truncation (§S38.9).

## §S38.8 Relationship end

If the relationship has a KNOWN end on or before E (including exactly at E), an existing `ACTIONABLE` follow-up is
suppressed with `RELATIONSHIP_ENDED` and none is created. An `UNKNOWN_LEGACY` end is not a known end. S38 uses
scanner/reconciliation only: it adds no eager writer inside `EndEmploymentRelationship` and S35/S32 are unchanged.

Deterministic priority (`EmploymentStatusExpiryFollowUpRecheck`): `RELATIONSHIP_ENDED`, then `TRUNCATED_EARLIER`, then
`SUCCESSOR_RECORDED`. A terminal status starting at E is both a successor and a relationship end; the root cause wins.

## §S38.9 Truncation

A follow-up whose period now ends earlier than expected (an ordinary truncation, not the relationship end) is suppressed
with `TRUNCATED_EARLIER`. Its `expected_effective_to` and `due_date` are never rewritten. The new end is its own logical
follow-up only when the frozen rules and window allow it (every command-produced truncation is followed by a successor at
the new end, so none is created; a test simulates a successor-less truncation to prove the rule). A period whose end became
null or later cannot arise (S32 has no PATCH) and never yields an unsupported reason.

## §S38.10 Lazy daily scanner

`ScanEmploymentStatusExpiryFollowUps` (nothing is written when a status is written; `RecordEmploymentStatusPeriod` is
untouched). One run for one explicit business date D (default: `BusinessDateClock`):

1. reconcile every `ACTIONABLE` follow-up whose end is still ahead (stale → `SUPPRESSED`, audited);
2. discover, in one set-based statement, eligible bounded periods with E in `(D, D + 7]` and no follow-up for
   `(kind, period, E)`; recheck each under lock; insert.

Window: `business_date >= E − 7 AND business_date < E`. At E or after, no new `ACTIONABLE` follow-up is created; an existing
one reads as `LAPSED`. The scanner never mutates a status period, a relationship or any other HR timeline.

## §S38.11 Database invariants

Composite RESTRICT FK `(employment_status_period_id, employment_relationship_id)` → the period's
`(id, employment_relationship_id)` (so the relationship can never disagree with the period's), a plain RESTRICT FK to the
period, a RESTRICT FK to the relationship, UNIQUE `(followup_kind, employment_status_period_id, expected_effective_to)`,
CHECKs for kind, `due_date = expected_effective_to − 7`, and state/reason/`suppressed_at` coherence. The composite FK needs one
redundant `UNIQUE (id, employment_relationship_id)` on `hr.employment_status_periods` (no restriction, no data change).
Migrations are forward-deterministic, roll back cleanly, backfill nothing and leave S31 untouched.

## §S38.12 Scheduler

`masar:hr:scan-employment-status-expiry-followups`, daily at 01:15 with `withoutOverlapping(60)`, independent of S31's
command and schedule (01:00 is unchanged). The definition holds no business rule; the service is idempotent.

## §S38.13 Idempotency and concurrency

`INSERT … SELECT … ON CONFLICT ON CONSTRAINT …_logical_key DO NOTHING` from the authoritative period row; repeated or
concurrent scans yield exactly one row, and only a real insert or transition writes an audit entry. Lock order everywhere:
EmploymentRelationship row → EmploymentStatusPeriod row → follow-up row (the relationship lock is the one every status and
relationship command takes first). Real cross-session tests cover two scanners, and a scanner against a successor, a
relationship end and a truncation.

## §S38.14 Indexing

The candidate query is a plain range on `effective_to`, but `hr.employment_status_periods` had no standalone `effective_to`
index, so discovery scanned all status history. Measured on 180,000 synthetic periods (60,000 relationships): Parallel Seq Scan,
2,046 buffers, ~16 ms → Index Scan, 355 buffers, ~0.9 ms, same 352 candidates. One **partial** index
`employment_status_periods_effective_to_index (effective_to) WHERE effective_to IS NOT NULL` was added (open-ended rows, the
majority, are not indexed). The follow-up table has only the indexes its queries need: the logical-key UNIQUE, `employment_relationship_id`,
and `(status, expected_effective_to)`. No speculative index.

## §S38.15 Security

Dedicated permission `hr.employment_status_expiry_followups.view`, granted to no role by the seed. **Plain RBAC**: a status period
has no organizational unit (S10 §13), so no scope is derived from placement or movement. `hr.movement_expiry_followups.view` does
not grant it (and it does not grant S31). Status-period write permissions are unchanged. There is no write permission:
follow-ups are written only by the system scanner.

## §S38.16 Read API

`GET /api/v1/hr/employment-status-expiry-followups` — one read-only list (no store/update/delete): filters `state`
(`ACTIONABLE` default, `LAPSED`, `SUPPRESSED`, `ALL`), `employment_relationship_id`, `per_page`. Fields: ids, kind, dates, persisted
`status`, derived `state`, `suppression_reason` (non-null only when SUPPRESSED), timestamps. No employee data or labels.

## §S38.17 Audit

`hr.employment_status_expiry_followup.emit` and `.suppress`, system actor `SCHEDULER_STATUS_EXPIRY` with `Source::System`, only for a real
insert or a real transition. Payloads hold ids, dates and stable codes (the suppression metadata also carries the current period end and the
business date); no PII.

## §S38.18 Delivery exclusion

No email, SMS, push, mobile, WhatsApp, webhook or notification center. A later stage may consume `ACTIONABLE` rows.

## §S38.19 Known discrepancy, deferred

The source says `captive` may have an optional end, while frozen ADR-S32-002 rejects `effective_to` for `captive`. S38 excludes
`captive` (no warning), does not change ADR-S32-002 and does not add captive end-date support. The discrepancy remains recorded
debt outside S38.

## §S38.20 Tests

`EmploymentStatusExpiryFollowUpFoundationTest` (eligibility, 7-day window and boundaries, derived `LAPSED`, successor, relationship
end and priority, truncation, idempotency, audit, reappointment isolation, independence, scheduler, API/security, database guards),
S38 cases in `ConcurrencyTest` (real cross-session races), and S38 cases in `MigrationLifecycleTest` (rollback/reapply; existing
permission counts and rollback ordering updated). Controlled mutation proofs were run for every frozen predicate and restored.

## §S38.21 Deferred / out of scope

Notification delivery; frontend alerts, dashboard, Employee360; eager follow-up writing; a generic follow-up framework; new status codes;
captive end semantics; leave balances, attendance and absence management; R1–R5 reports, monthly aggregation; age, experience, positions,
promotion, supervisory assignment. No future stage is opened by this document.
