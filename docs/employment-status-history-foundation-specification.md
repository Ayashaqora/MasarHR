# Employment Status History Foundation (S10) — Version 1.0

## 0. Provenance

The exact historical title of S10 is **not** asserted anywhere in this repository or by
Architecture Authority. No historical wording is claimed or invented here.

Architecture Authority's "MASARHR — FULL STAGE AUTHORIZATION — S10 — DEPENDENCY RECOVERY +
COMPLETE STAGE EXECUTION" (2026-09-26) instructs a fresh dependency-recovery pass over the
frontier S09 left, rather than asserting a title outright. Per that authorization's §19: this
document is an **Architecture/Dependency Reconstruction**, not a recovered historical title.

**Reconstructed title: S10 — Employment Status History Foundation.**

No Arabic subtitle is supplied by Architecture Authority for S10 and none is invented here (unlike
S09, where ADR-S09-001 itself supplied "المرحلة 09 — تأسيس الشخص والعلاقة الوظيفية" verbatim).

### ADR-S10-001 — RECONSTRUCTED / APPROVED FOR EXECUTION

Selecting **Employment Status History** as the S10 domain, on the dependency-DAG evidence in §1
below. Alternatives (Job/Professional History, Employment Category History, Qualification,
Placement History, and everything gated behind them) are not implemented now; §1.3 states,
per candidate, why each depends on Employment Status History or belongs later.

## 1. Dependency recovery

### 1.1 Candidates investigated

Per the authorization's §5, all of the following were investigated: Employment Status History,
Job/Professional History, Employment Category History, Organizational Placement History,
Qualification, Contract Lifecycle, Work Schedule, Transfer, Secondment, Supervisory Assignment,
Leave, Migration/Import, Reporting.

### 1.2 Reference-catalog readiness (the decisive discriminator)

Repository inspection of every `ref.*` catalog each candidate would need to consume:

| Reference catalog | Rows | Consuming candidate |
|---|---|---|
| `ref.employment_status_categories` | **4 seeded** (S05): active/non_active/ended/terminal | Employment Status History |
| `ref.employment_status_details` | **13 seeded** (S06, migration `2026_09_26_000026`) | Employment Status History |
| `ref.employment_status_detail_behaviors` | **13 open-ended periods seeded** (S06, migration `2026_09_26_000027`), each with `participates_in_active_workforce`/`is_ongoing_relationship`/`is_relationship_ending`/`is_terminal`/`allows_reappointment` already mechanically derived from category | Employment Status History |
| `ref.job_titles` | **0** — "structure only" (S05 §5.3); S06's own `job_title_administrator_classifications` migration docblock confirms: *"Structure only: ref.job_titles is still empty (S05 structure-only), so this migration seeds no rows."* | Job/Professional History |
| `ref.employment_categories` | **0** — S05 §5.3; untouched by any mapping since (S06 spec §33: "seven structure-only S05 families not touched by a mapping ... remain exactly as S05 left them") | Employment Category History |
| `ref.qualification_types`, `ref.academic_degrees` | **0** — S05 §5.3 | Qualification |
| `ref.contract_types` | **0** — S05 §5.3 | Contract Lifecycle |
| `ref.leave_types`, `ref.leave_statuses` | **0** — S05 §5.3 | Leave |
| `ref.specialties`, `ref.supervisory_titles` | **0** — S05 §5.3 | Specialty/Supervisory-adjacent work |
| `ref.decision_types` | **0** — structure seeded, "no concrete value list" (S05 spec, reference table) | Any domain needing نوع القرار |
| `org.organizational_units` | **0 rows**, but full CRUD commands exist (S07) — a different kind of gap (no missing command, only no data yet) | Placement History |

**Only Employment Status History has a fully populated, immediately consumable reference chain.**
Every other `ref.*`-dependent candidate is blocked by the same wall S05 built on purpose: "no
authoritative value list exists ... yet" (§5.3) — populating any of them now, absent an explicit
evidenced value list, would be inventing a business-rule catalog, forbidden by `CLAUDE.md`
("Do not invent HR business rules") and by this authorization's own §5 instruction to identify
"prerequisites not yet satisfied" rather than manufacture them.

### 1.3 Per-candidate disposition

- **Employment Status History** — ready now (§1.2). S09 itself named the exact, specific
  reconciliation debt this stage settles (§1.4 below). **Selected.**
- **Job/Professional History** — blocked: `ref.job_titles` has zero rows and no populating
  command exists anywhere in the repository (§8 of this authorization gives only a thin boundary,
  no field-level model, consistent with immaturity).
- **Employment Category History** — blocked: `ref.employment_categories` has zero rows; S06's
  `monthly_cadre_categories`/`contract_based_population_categories`/mapping tables are a
  *different*, reporting-classification-only set of catalogs (they map reference rows to other
  reference rows for future Report 1/2 purposes) and do not substitute for an authoritative
  employee-facing category catalog.
- **Qualification** — blocked: `ref.qualification_types`/`ref.academic_degrees` both empty.
- **Placement History** — prerequisites are *structurally* satisfiable (S07 org hierarchy has full
  CRUD; S08 scope exists), but, unlike Employment Status History, S09 named no specific
  reconciliation debt toward Placement — its own §17 treats organization association as a clean,
  closed non-goal boundary, not a flagged forward reference. The authorization's own §10 gives
  only a boundary paragraph for Placement (no field-level model, no consequence table), versus the
  fully worked category/detail/consequence tables given for Employment Status in §7 — evidence
  that Architecture Authority's own preparatory rule-definition is materially less mature here.
  Implementing Placement now would require inventing structural decisions (single vs. multi-row
  "current workplace," how S08 scope attaches) with no supplied evidence — deferred, not rejected.
- **Contract Lifecycle** — blocked: `ref.contract_types` empty; also explicitly gated by the
  authorization's own §16 ("Do not pull this entire domain into S10 unless dependency recovery
  selects it") and dependent on Placement in places (secondment closes).
- **Work Schedule** — no groundwork of any kind exists yet (no reference catalog, no boundary
  detail beyond "required for partial-secondment validation," §12); purely a prerequisite of a
  domain (Secondment) that is itself gated behind Placement.
- **Transfer, Secondment, Supervisory Assignment** — each explicitly gated behind Placement
  (§§11–13: "Do not implement ... before its dependencies") and, for partial secondment, Work
  Schedule. Not reachable this stage.
- **Leave** — blocked: `ref.leave_types`/`ref.leave_statuses` empty; also explicitly gated
  ("Do not implement leave merely because status details contain leave-related concepts," §15).
- **Migration/Import** — explicitly excluded ("Do NOT implement Excel import unless selected stage
  is actually migration," §28); S09 §20 already established the same boundary.
- **Reporting** — explicitly excluded ("NOT authorization to implement reports in S10," §18).

No two candidates remain materially reasonable: exactly one — Employment Status History — clears
the reference-readiness bar every other candidate fails, and it alone carries an explicit,
named S09 handoff. This is not a convenience or size choice; it is decided by which prerequisite
chain is actually populated in the database today.

### 1.4 The explicit S09 handoff

`docs/person-employment-foundation-specification.md` §24, item 3 (written *after* S06 had already
seeded the 13 detail/behavior rows — S06's migrations are dated 2026-09-26, S09's 2026-09-29):

> "Reconciling `ended_terminally` with the future `ref.employment_status_details`/`_behaviors`
> stream once that stream gains seeded rows — noted as a forward-compatibility expectation, not
> implemented."

That stream already had its seeded rows when S09 wrote this — S09 knew the precondition was met
and still deferred the reconciliation by name to whichever stage came next. This is the dependency
frontier S09 left, and S10 settles it.

### 1.5 Dependency DAG (text form)

```
S05 (ref.employment_status_categories: 4 rows)
   -> S06 (ref.employment_status_details: 13 rows; ref.employment_status_detail_behaviors: 13 periods;
           ResolveEmploymentStatusDetailBehaviorAsOf reader)
   -> S09 (hr.persons; hr.employment_relationships; end_knowledge_state/ended_terminally bridge fields;
           explicit deferred-item naming the reconciliation below)
   -> S10 (THIS STAGE): hr.employment_status_periods — ties a concrete Employment Relationship to
           the S06 catalog over time; consequences (relationship-ending / terminal) reuse S09's own
           EndEmploymentRelationship rather than re-deriving them.

[not yet reachable — each requires a ref.* catalog or a domain this stage does not touch]
   Job/Professional History      <- ref.job_titles (0 rows)
   Employment Category History   <- ref.employment_categories (0 rows)
   Qualification                 <- ref.qualification_types / ref.academic_degrees (0 rows)
   Placement History              <- no S09 reconciliation debt; boundary only, no field model
      -> Transfer / Secondment / Supervisory Assignment (gated behind Placement + Work Schedule)
   Contract Lifecycle             <- ref.contract_types (0 rows)
   Leave                          <- ref.leave_types / ref.leave_statuses (0 rows)
   Migration/Import, Reporting    <- explicitly excluded by this very authorization
```

## 2. Purpose

Give each **Employment Relationship** (S09) a temporal record of which employment-status detail
(S06) applied to it and when, and wire the two already-approved consequence rules (a status whose
behavior is relationship-ending closes the relationship; one that is terminal closes it and blocks
reappointment) through the existing S09 machinery — never a second, parallel terminal/ending
mechanism.

## 3. Non-goals (hard boundary)

S10 does **not** implement: Job/Professional History; Employment Category History; Placement,
Transfer, Secondment, or Supervisory Assignment; Work Schedule; Leave; Contract Lifecycle;
Qualification; Excel import/migration; reporting datasets; frontend (backend/domain/API only,
matching every prior stage). No `decision_type` field is added (§9 below explains why). No new
`ref.*` value is invented; the 13 details/behaviors S06 already seeded are consumed exactly as
seeded, not extended.

## 4. Module and schema

Extends the existing `App\Modules\HumanResources` module (Domain/Application/Infrastructure/
Presentation) rather than opening a new module: the new entity is a temporal child of the
Employment Relationship aggregate S09 already owns in this module, tightly coupled to
`EndEmploymentRelationship`. New table lives in the existing `hr` schema:
`hr.employment_status_periods`.

## 5. Aggregate ownership and domain model

`EmploymentStatusPeriod` is a child temporal entity of `EmploymentRelationship` — not its own
aggregate root, exactly mirroring how `EmploymentStatusDetailBehavior` (S06) is a child of
`EmploymentStatusDetail`, not an independent root. Fields:

- `id` (uuid, technical PK)
- `employment_relationship_id` (uuid, FK → `hr.employment_relationships`, `RESTRICT`)
- `status_detail_id` (uuid, FK → `ref.employment_status_details`, `RESTRICT`)
- `effective_from` (`DATE`)
- `effective_to` (`DATE`, nullable — half-open `[from, to)`, `NULL` = still in effect)
- `created_at` (`TIMESTAMPTZ`)

No `version` column: a period, once inserted, is never updated directly by this module (append-only,
exactly like `ref.employment_status_detail_behaviors`); the *only* mutation a later insert ever
causes is closing the immediately-prior open period's `effective_to` as part of recording the next
one — done by the same command, in the same transaction, never as a separately callable action.

## 6. References consumed (never duplicated)

Consumes `ref.employment_status_categories` (S05) and `ref.employment_status_details`/
`_behaviors` (S06) exactly as seeded. `ResolveEmploymentStatusDetailBehaviorAsOf` (S06) is reused
unmodified to resolve a detail's behavior as of a given date — S10 adds no second resolution path.
No new reference row, category, or behavior flag is added; if a future stage needs an S10-facing
per-detail flag S06 did not seed (e.g. a concrete `counts_in_monthly_reporting`), that is that
stage's own authorization to obtain, not S10's to infer.

## 7. Temporal semantics and consequences

Recording a new status period for an Employment Relationship is one atomic "transition," not two
separate "close" and "open" actions (the authorization's own wording: "Status change is
temporal... consequences must follow already-approved rules rather than controller logic," §7):

1. The Employment Relationship is re-fetched fresh with `lockForUpdate()` inside the transaction
   (S09's own established discipline, §14 of the S09 spec) and must not already be ended
   (`end_knowledge_state = 'KNOWN'`) — an ended relationship's status history is closed too; no
   reactivation.
2. The target `EmploymentStatusDetail` is re-fetched fresh by id; no `is_active` gate is applied,
   matching the explicit S06 precedent (S06 spec §10: "no application-layer active-target check ...
   inactive-row exclusion, if ever wanted, is future-stage business-rule territory" — the same
   reasoning applies unchanged here).
3. `effective_from` must be strictly after the Employment Relationship's own `effective_from`
   (validated against the freshly-locked, already-immutable value — `effective_from` is set once
   at creation and never mutated by any command in this codebase, so this check carries no
   concurrency risk despite being application-level, not a GiST/CHECK constraint; disclosed here
   rather than left implicit).
4. If a currently-open period exists for this relationship (`effective_to IS NULL`), it is closed
   at exactly the new period's `effective_from` in the same transaction — periods are contiguous,
   never gapped, by command discipline (the database only forbids *overlap*; the command itself
   guarantees no gap).
5. The new period is inserted.
6. `ResolveEmploymentStatusDetailBehaviorAsOf($detail, $effectiveFrom)` resolves the behavior in
   effect on that date. `null` (unresolved — no behavior period covers that date; S06's periods
   are "authoritative from 2026-09-26 forward" only) is rejected, never defaulted.
7. If the resolved behavior's `is_relationship_ending` or `is_terminal` is true, this command calls
   `EndEmploymentRelationship::handle()` **directly, in-process, inside the same transaction** —
   not through a second `AuditedCommandExecutor::run()` call (which would double-audit and open a
   nested transaction the executor does not support) — with `effectiveTo` = the new period's
   `effective_from` and `isTerminal` = the resolved `is_terminal`. This is the reconciliation named
   in S09 §24(3): `hr.employment_relationships.end_knowledge_state`/`ended_terminally` become the
   single, already-existing terminal truth; S10 never introduces a second one.
8. If neither flag is true (an `ACTIVE` or `NON_ACTIVE` detail), the relationship stays open — "a
   NON_ACTIVE ongoing status does not necessarily terminate the Employment Relationship" (§7 of the
   authorization), consistent by construction: only `is_relationship_ending`/`is_terminal` drive
   closure, and every `NON_ACTIVE` detail S06 seeded has both `false`.

Reappointment after an `ENDED` (non-terminal) relationship closes exactly as S09 already
established (§13 of the S09 spec) — a new `CreateEmploymentRelationship` call, unmodified by S10.

## 8. PostgreSQL constraints

- `employment_status_periods_period_check`: `effective_to IS NULL OR effective_to > effective_from`
  (via `TemporalConstraints::validPeriodCheckSql()`, identical helper S09/S06 already use).
- `employment_status_periods_no_overlap`: `EXCLUDE USING gist (employment_relationship_id WITH =,
  daterange(effective_from, effective_to, '[)') WITH &&)` (via
  `TemporalConstraints::noOverlapConstraintSql()`), requiring `btree_gist` (already enabled, S02).
- `employment_relationship_id` FK → `hr.employment_relationships.id`, `ON DELETE RESTRICT` (no
  hard-delete of a relationship while it has status history — matches every other FK in this
  codebase).
- `status_detail_id` FK → `ref.employment_status_details.id`, `ON DELETE RESTRICT`.

No DB trigger is introduced anywhere in this stage — the codebase has no precedent for one, and
the one cross-table check this stage needs (§7.3) is safe at the application layer without one
(§7.3's own reasoning).

## 9. Domain exceptions

| Exception | HTTP | Source |
|---|---|---|
| `EmploymentRelationshipAlreadyEndedException` (S09, reused) | 409 | Recording a status period against an already-`KNOWN`-ended relationship. |
| `InvalidStatusPeriodDateException` (new) | 422 (`errors.effective_from`) | `effective_from` not strictly after the relationship's own `effective_from`, or not strictly after the currently-open period's own `effective_from` (surfaced by the DB `CHECK`/`EXCLUDE` on the closing `UPDATE`, translated via `PostgresErrorClassifier`, exactly like S09's `InvalidEndDateException` pattern). |
| `UnresolvedEmploymentStatusBehaviorException` (new) | 422 (`errors.effective_from`) | `ResolveEmploymentStatusDetailBehaviorAsOf` returns `null` for the given date — never defaulted. |
| `PersonIsTerminalException` (S09, reused) | 409 | Defense-in-depth only: unreachable in practice once `EndEmploymentRelationship` is called with `isTerminal: true`, since that path never re-invokes `CreateEmploymentRelationship`; kept mapped because it is S09's existing exception, not because S10 introduces a new path to it. |

No new `StaleVersionException`-shaped class: there is no client-supplied `expected_version` on a
status period (it is not independently mutable — see §5), so there is nothing to race against
except the relationship's own end-state, already covered by
`EmploymentRelationshipAlreadyEndedException`, and the no-gap/no-overlap invariant, covered by
`InvalidStatusPeriodDateException`.

## 10. Why no `decision_type` field (§14 of the authorization)

The authorization's §14 states formal HR decisions store only `نوع القرار` (decision type) and
warns against reintroducing decision number/date/description. `ref.decision_types` (S05) exists
structurally but, like every other candidate catalog in §1.2, has **zero seeded rows** — "no
concrete value list" exists. §14 does not explicitly state that *every* employment-status
transition is itself a "formal HR decision" requiring this field (unlike §7, which gives Employment
Status a full, explicit, field-level rule set) — reading it as such would both (a) require
inventing a business-rule value list for an empty catalog, forbidden, and (b) add a "speculative
nullable column" pointing at that empty catalog, forbidden by this authorization's own §22. This is
therefore read as a standing rule for whichever *future* domain (most plausibly Transfer/
Secondment/Supervisory Assignment, which more naturally correspond to an issued
administrative قرار) actually needs it, not as an implicit S10 requirement. Disclosed here rather
than silently decided.

## 11. Command boundary

- `RecordEmploymentStatusPeriod` — the single command implementing §7's transition + consequence
  logic. No separate "close" command exists (closing only ever happens as a side effect of
  recording the next period, or of the relationship ending independently via S09's own
  `EndEmploymentRelationship`, which already leaves the last open status period untouched — a
  disclosed, deferred reconciliation gap, §17).

Explicitly **not** built: any command to edit or delete a past status period (no evidence
authorizes retroactive correction of status history in this stage — "Correction/cancel/end are
explicit semantics when the selected domain requires them," §27 — no requirement was evidenced
for correction here); any command against `ref.employment_status_details`/`_behaviors` themselves
(S06 already owns those in full).

## 12. Queries

- `ListEmploymentStatusPeriodsForRelationship` — ordered by `effective_from` desc (mirrors S09's
  `ListEmploymentRelationshipsForPerson` exactly).

No separate "current status" query is added: the first row of the ordered list (or, equivalently,
the row with `effective_to IS NULL`) is the current status; inventing a second read path for the
same fact would duplicate, not add, information.

## 13. Authorization

RBAC only, two new permission codes added to the existing `HumanResourcesPermissionCatalog`:
`hr.employment_status_periods.view`, `hr.employment_status_periods.record`. No S08 organizational
scope integration — Employment Relationship carries no organizational-unit column in S09 and S10
does not add one (§3), so there is no scope target to consume, matching this authorization's own
§25 allowance ("If selected S10 domain has no organization target: do not fabricate one merely to
consume S08").

## 14. Audit

Every mutation of `RecordEmploymentStatusPeriod` runs through `AuditedCommandExecutor`
(action `hr.employment_status_period.record`), allowlisted metadata only (relationship id, status
detail id/code, effective_from, and — when triggered — that the relationship was also closed as a
consequence, plus whether terminally). No National ID or other Person-identifying attribute is
placed in audit metadata (the relationship id is already the established safe target identifier,
per S09 precedent).

The "also closed as a consequence, plus whether terminally" metadata is derived by the audit
`metadata` closure comparing the relationship's `end_knowledge_state` immediately before
`RecordEmploymentStatusPeriod::handle()` runs against its freshly-read state immediately after,
inside the same still-open `AuditedCommandExecutor` transaction — not by widening the command's own
return type, which stays exactly `EmploymentStatusPeriod` (§11). `relationship_closed_as_consequence`
and `ended_terminally` are present in metadata only when a closure actually happened this call;
absent (not `false`) otherwise. Covered by dedicated tests for the non-terminal-closure,
terminal-closure, and no-closure cases (added after adversarial review found the first
implementation only recorded `status_detail_code`, silently omitting the consequence fact this
section always required).

## 15. API

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/status-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/status-periods
```

(Nested under `{person}`, mirroring `EmploymentRelationshipController`'s own existing `end` route
exactly — corrected after adversarial review flagged that an earlier draft of this section
illustrated the route without the `{person}` segment, which is not actually satisfiable together
with the IDOR requirement stated two sentences below. The implementation was already correct; only
this illustration was fixed.)

`POST` body: `status_detail_code` (string, resolved to an `EmploymentStatusDetail` the same way
S09 resolves `employment_type_code` — 404 if the code does not exist), `effective_from` (date).
Standard 401/403/409/422; no PATCH/DELETE (no-hard-delete, no correction command, §11). IDOR
protection: the route's `{employmentRelationship}` must belong to the given Person's own resource
path, mirroring S09's existing `end` route check exactly.

## 16. Migration/legacy compatibility

No import pipeline is built (§3). The temporal model supports later backfill without alteration:
a relationship with zero recorded status periods simply has no known status history yet — this
*is* the honest "unknown" representation (never a fabricated retroactive default), satisfying the
authorization's §28 spirit ("never manufacture start/end dates") without adding a parallel
knowledge-state column the way S09's `end_knowledge_state` was explicitly, separately authorized
for the relationship's own end. A future legacy-import stage backfilling pre-2026-09-26 history
will need its own explicitly authorized correction/backdate command — not invented here.

## 17. Concurrency

- Two concurrent `RecordEmploymentStatusPeriod` calls for the same Employment Relationship: the
  relationship's own row lock (`lockForUpdate()`, step 1 of §7) serializes them — the second
  waits, then observes the first's committed close-and-insert before proceeding, so no overlap is
  even attempted at the DB layer; the `EXCLUDE` constraint is the final backstop regardless.
- A `RecordEmploymentStatusPeriod` call racing a concurrent `EndEmploymentRelationship` call on the
  same relationship: both acquire the same row lock via the identical `lockForUpdate()` pattern
  S09 established, so they serialize; whichever commits second observes the first's committed
  `end_knowledge_state` and is rejected via `EmploymentRelationshipAlreadyEndedException` if it
  tried to record a period against an already-ended relationship.

All of the above are covered by dedicated tests that race two connections, not merely assert the
constraint exists (mirroring S09 §14/§24 discipline exactly).

## 18. Deferred items / open questions (disclosed, not blocking)

1. `EndEmploymentRelationship` (S09, unmodified) does not itself close the relationship's last open
   status period when a relationship ends via a path other than `RecordEmploymentStatusPeriod`
   (there is none in S09/S10 today — S09 has no other ending trigger). If a future stage adds one,
   that stage must reconcile it; not needed now since no such path exists.
2. `counts_in_monthly_reporting` remains `null` on every S06-seeded behavior row (S06's own
   deferred item, §33) — S10 does not supply it either; no reporting logic is implemented here
   (§3).
3. `decision_type` — see §10.
4. Retroactive correction of a previously-recorded status period — no command exists; deferred to
   a future, separately authorized stage if ever needed.
5. Job/Professional History, Employment Category History, Placement History, Qualification,
   Contract Lifecycle, Leave, Work Schedule, Transfer, Secondment, Supervisory Assignment,
   Migration/Import, Reporting — all explicitly deferred per §1.3/§3. S11+ is not started.

None of these represents an unresolved *consequential* policy for S10's own boundary; each is a
named, explicit handoff to a future stage.

## 19. Review gate (internal, mirroring S09 §22's P01–P24 pattern)

- Does S10 infer any business rule from stage numbering alone? No — every rule traces to §7 of the
  authorization, the already-seeded S06 catalog, or explicit S09 handoff language (§1.4).
- Does S10 invent any reference value? No — the 13 S06-seeded details/behaviors are consumed as-is;
  no new code/name/flag is added anywhere.
- Does S10 create a second terminal/ending truth? No — `EndEmploymentRelationship` is reused
  in-process; `hr.employment_relationships` remains the sole ending/terminal record.
- Does S10 touch `_to_delete/`, any frozen S01–S09 migration/model/command, or any other project's
  terminology? No.
- Does S10 require an organizational-scope target that doesn't exist? No (§13).
- Does S10 require Excel import or reporting? No (§3/§16).

No consequential policy remains unresolved. **PASS — proceeding to implementation.**

## 20. Tests (minimum)

Status-period recording (first period, subsequent transition, auto-close of prior open period);
overlap rejection (impossible by construction, tested anyway at the DB layer directly); backdated
transition rejected (`effective_from` not after relationship's own `effective_from`); transition
predating the currently-open period rejected; `ACTIVE`/`NON_ACTIVE` details leave the relationship
open; `ENDED` details close the relationship non-terminally, permitting reappointment; `TERMINAL`
details close the relationship terminally, blocking reappointment (reusing S09's own terminal-block
test pattern); recording against an already-ended relationship rejected; unresolved behavior date
rejected; concurrent same-relationship transitions (real race, `lockForUpdate()` serializes);
concurrent transition vs. concurrent `EndEmploymentRelationship` (real race); RBAC on both routes;
IDOR protection; audit entry generated, no PII duplicated; migration rollback/reapply; PostgreSQL
constraint tests (`CHECK`/`EXCLUDE`/FK exercised directly); full S01–S09 regression; boundary-audit
test for §3's exclusions.

## 21. Acceptance criteria

Specification PASS (§19); implementation matches §5–§17 exactly; full test suite (S10-specific +
full regression) passing; Pint clean; `git diff --check` clean; adversarial review with all
BLOCKING findings resolved; boundary audit (no forbidden domain/table/route leaked in); no S11+
work; no cross-project contamination; `_to_delete/` untouched.
