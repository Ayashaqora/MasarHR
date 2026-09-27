# MASARHR — S11 — Organizational Placement Foundation

Version 1.0 — Architecture/Dependency Reconstruction — Date: 2026-09-27

## 0. Provenance / ADR-S11-001

The exact historical title/boundary of S11 is not asserted by Architecture Authority and is not
recovered from repository evidence — no file in this repository states a historical S11 title.

**ADR-S11-001 — RECONSTRUCTED / APPROVED FOR EXECUTION.** Selecting **Organizational Placement
Foundation** as the S11 domain, classified as an **Architecture/Dependency Reconstruction**, not
historical recovery, on the dependency-DAG evidence in §1. No two candidate domains remain
materially reasonable once reference-catalog readiness and prerequisite-implementation state are
checked against the actual repository (§1.2) — Organizational Placement is the only candidate that
clears every bar the authorization sets, and the authorization's own §11–§13 supply the exact
structural rules (original vs. actual workplace, S08 scope integration) that were the specific
reason this same domain was deferred, not rejected, when S10's own dependency recovery considered it
(`docs/employment-status-history-foundation-specification.md` §1.3: "Implementing Placement now
would require inventing structural decisions... with no supplied evidence — deferred, not
rejected").

## 1. Dependency recovery

### 1.1 Candidates investigated

Per the authorization's §6: Job/Professional History, Employment Category/Classification History,
Organizational Placement History, Work Schedule Foundation, Contract Lifecycle, Qualification,
Transfer, Full Secondment, Partial Secondment/Allocation, Supervisory Assignment, Leave,
Migration/Excel Import, Reporting, Data Quality/Conflict Resolution.

### 1.2 Reference-catalog and prerequisite readiness (checked directly against the repository)

| Candidate | Blocking gap | Evidence |
|---|---|---|
| Job/Professional History | `ref.job_titles` — **0 rows**, no populating command anywhere in the repository | confirmed via `DB::table('ref.job_titles')->count()` against the migrated `masarhr` database |
| Employment Category History | `ref.employment_categories` — **0 rows** | same |
| Qualification | `ref.qualification_types`/`ref.academic_degrees` — **0 rows each** | same |
| Contract Lifecycle | `ref.contract_types` — **0 rows** | same |
| Leave | `ref.leave_types`/`ref.leave_statuses` — **0 rows each** | same |
| Supervisory Assignment | `ref.supervisory_titles` — **0 rows**; also needs Organization targeting (below) | same |
| Work Schedule | no independent value — authorization's own §14 frames it purely as a prerequisite of partial secondment, itself gated behind Placement | authorization §14/§15 |
| Transfer, Full/Partial Secondment | explicitly gated behind Placement (and, for partial secondment, Work Schedule) by the authorization itself | authorization §13/§15: "Do not implement Transfer until its prerequisites exist" / "Do not implement partial secondment before those prerequisites" |
| Migration/Import | explicitly not automatic; no evidence points to it now | authorization §23; unchanged since S09/S10 |
| Reporting | explicitly not automatic; `counts_in_monthly_reporting` still null (S06's own deferred item) | authorization §21/§22 |
| Data Quality/Conflict Resolution | no rules of any kind supplied for this candidate anywhere in the authorization — selecting it would require inventing its entire scope | authorization body has no section for it (unlike A–L, each given §9–§23) |
| **Organizational Placement** | `org.organizational_units` — **0 rows**, but, unlike every `ref.*` catalog above, S07 already ships full CRUD commands (`CreateOrganizationalUnit`/`RenameOrganizationalUnit`/`MoveOrganizationalUnit`/`Activate`/`DeactivateOrganizationalUnit`) — a data-population gap, not a missing-capability gap (§1.3) | confirmed via repository read of `app/Modules/Organization/Application/Commands/*` and `DB::table('org.organizational_units')->count()` |

`ref.employment_types` (2 rows, S09) is unrelated to any S11 candidate — already fully consumed by
S09/S10.

### 1.3 Why the Placement gap is different in kind

Every `ref.*`-dependent candidate is blocked by the same wall S05 built on purpose: no authoritative
value list exists for job titles, employment categories, qualification types, contract types, leave
types/statuses, or supervisory titles, and populating any of them now would mean inventing a
business-rule catalog — forbidden by `CLAUDE.md` and by this authorization's own §25 ("If a candidate
stage requires an empty catalog and no approved values exist: that is evidence AGAINST selecting it
now").

`org.organizational_units` is not that kind of gap. It is not an enumerated business-rule catalog —
it is operational data (actual hospitals/departments) that real system usage populates through S07's
own already-shipped CRUD API, the same way `hr.persons` starts empty and is populated through S09's
own `CreatePerson` command. The authorization's own §11 treats `org.organizational_units` as
authoritative and ready ("S07 organization hierarchy is authoritative") without caveating it as
blocked by current emptiness — consistent with `docs/employment-status-history-foundation-
specification.md` §1.2's own prior conclusion that this is "a different kind of gap (no missing
command, only no data yet)."

### 1.4 Why Placement was reachable now when it was not for S10

S10's own dependency recovery considered Placement and explicitly deferred it (not rejected it) for
one stated reason: implementing it would have required *inventing* structural decisions — "single vs.
multi-row 'current workplace,' how S08 scope attaches" — with no supplied evidence at the time. This
S11 authorization supplies exactly those missing decisions directly (§11–§13): original workplace vs.
actual/current workplace are distinct concepts; S07 is authoritative for the hierarchy; S08's
`RBAC = WHAT, Organizational Scope = WHERE` model is how the write/read boundary attaches. The
specific evidentiary gap that blocked selection in S10 no longer exists in S11.

### 1.5 Decision-priority checklist (authorization §7)

1. **Already fully specified by frozen rules?** Yes for the domain S11 actually builds (§4 below) —
   §11/§12 give a complete, unambiguous structural rule set for the original-workplace stream; §26
   gives the exact authorization composition rule.
2. **Prerequisites already implemented?** Yes — S07 (hierarchy + CRUD), S08 (`ScopedAuthorizationChecker`,
   `EffectiveOrganizationalScope`), S09 (Employment Relationship aggregate to attach placement to) are
   all shipped, tested, and unmodified by this stage.
3. **Unlocks multiple downstream domains?** Yes — Transfer, Full/Partial Secondment, Supervisory
   Assignment, and (transitively) Work Schedule all name Placement as their own prerequisite
   (authorization §13/§15/§16).
4. **Implementable without inventing missing catalogs/policy?** Yes — no `ref.*` catalog is consumed
   at all; the one FK target (`org.organizational_units`) needs no seeded business-rule values, only
   ordinary operational rows created through S07's own existing, unmodified commands.
5. **Preserves a coherent intermediate architecture?** Yes — a single, bounded, self-contained
   temporal child entity of the S09 aggregate, exactly mirroring S10's own shape.

No other candidate clears more than one of these five; most clear none. Organizational Placement is
selected.

## 2. Why this precedes every alternative

Every `ref.*`-dependent alternative requires inventing a business-rule catalog to be minimally
useful — forbidden. Work Schedule, Transfer, and both Secondment variants are explicitly,
textually gated behind Placement by the authorization itself. Migration/Import and Reporting are
explicitly excluded from automatic selection. Data Quality/Conflict Resolution has no supplied rules
at all. Organizational Placement is the only candidate this authorization both permits and fully
specifies.

## 3. Purpose

Give each **Employment Relationship** (S09) a temporal record of which **Organizational Unit** (S07)
is its administrative origin — the "original workplace" — over time, authorized by the conjunction
of S03 RBAC (`WHAT`) and S08 organizational scope (`WHERE`), reusing S08's existing
`ScopedAuthorizationChecker` exactly as built, with no modification to S07, S08, S09, or S10.

## 4. Scope

Builds exactly one new temporal child entity — `OrganizationalPlacementPeriod` — one command to
record a period, one query to list a relationship's placement history, RBAC + S08-scope-gated API
endpoints nested under the existing `{person}/{employmentRelationship}` route shape, and audit
coverage. Nothing else.

## 5. Non-goals (hard boundary)

Does **not** implement: Transfer, Full Secondment, Partial Secondment/Allocation, Supervisory
Assignment, Work Schedule, Contract Lifecycle, Job/Professional History, Employment Category
History, Qualification, Leave, Migration/Excel import, reporting datasets, frontend
(backend/domain/API only, matching every prior stage). No `decision_type` field (authorization §20 —
`ref.decision_types` remains empty; this is not a formal HR decision in the authorization's sense).
No modification to S07/S08/S09/S10 migrations, models, or commands. `_to_delete/` is never touched.

### 5.1 "Actual/current workplace" is explicitly not persisted in S11 — disclosed, not silently decided

The authorization's §11/§12 require *keeping the two concepts distinct* ("do not prematurely collapse
the concepts... Do not model placement as a mutable `current_workplace` field") — a warning against a
specific data-modelling mistake, not an instruction to build a second, currently-unused table. S11
satisfies the actual warning by building `OrganizationalPlacementPeriod` as a genuinely temporal,
history-preserving stream (never a mutable "current" field) — nothing here would need to change
shape for a future stage to add an "actual/current workplace" concept additively.

Building a second, parallel "actual workplace" table now, with **zero command in S11 ever writing to
it and zero exercisable behavior beyond schema existence**, would itself be exactly the kind of
speculative structure this codebase's own discipline forbids elsewhere (S10 spec §10: "adding a
speculative nullable column... forbidden"; authorization §29: "No speculative JSONB"). The
authorization's own §12 explains *why* the two concepts stay conceptually distinct: "Transfer changes
original workplace. Secondment does NOT change original workplace" — i.e., "actual" only ever
diverges from "original" once a future Secondment stage introduces the mechanism that creates that
divergence. Until that mechanism exists, "actual" is definitionally identical to "original" (§12: "A
normal employee with no movement may have both resolving to the same organization") — there is
nothing for a second table to record yet. `RecordOrganizationalPlacementPeriod` in this spec records
the **original workplace** stream only. A future Secondment stage adds its own additive stream for
"actual workplace" divergence; it does not need to alter this stage's schema or commands to do so.

## 6. Aggregate ownership and domain model

`OrganizationalPlacementPeriod` is a child temporal entity of `EmploymentRelationship` — not its own
aggregate root, exactly mirroring how `EmploymentStatusPeriod` (S10) is a child of the same
aggregate. Fields:

- `id` (uuid, technical PK)
- `employment_relationship_id` (uuid, FK → `hr.employment_relationships`, `RESTRICT`)
- `organizational_unit_id` (uuid, FK → `org.organizational_units`, `RESTRICT`)
- `effective_from` (`DATE`)
- `effective_to` (`DATE`, nullable — half-open `[from, to)`, `NULL` = still in effect)
- `created_at` (`TIMESTAMPTZ`)

No `version` column — append-only, exactly like `hr.employment_status_periods` (S10 spec §5): the
only mutation a later insert ever causes is closing the immediately-prior open period's
`effective_to`, done by the same command, in the same transaction, never as a separately callable
action.

### 6.1 Column naming vs. the existing S07→S08 boundary test — disclosed

`tests/Feature/Organization/ScopeBoundaryTest.php::test_no_organizational_scope_column_exists_
outside_s08s_own_table` currently forbids a column literally named `organizational_unit_id` (among
three other names) anywhere outside `security.organizational_scope_grants`. That test's own docblock
scopes its concern precisely: "no organizational-scope column (**S08's subject matter**) exists
anywhere" — i.e., no second table encoding an *authorization-scope grant* (S08's own concept: "which
subtree can this principal see"). `hr.organizational_placement_periods.organizational_unit_id` is not
an authorization-scope grant; it is ordinary domain data recording *where an employment relationship
is placed* — the same category of fact as `hr.persons`/`hr.employment_relationships` themselves,
which needed, and received, their own explicit carve-out in the sibling test
(`test_no_hr_person_employee_or_transaction_tables_leaked_in_via_s07`) once S09 was authorized to
create them. This specification updates that one test to add a second, equally explicit exception
for this table — the same additive-disclosure pattern the test's own comment already documents
("mirrors the exact additive-disclosure pattern S06→S07 already used elsewhere"). The column keeps
the literal name `organizational_unit_id` (matching every other FK-naming convention in this codebase
— `employment_relationship_id`, `status_detail_id`, `person_id`) rather than being renamed solely to
dodge an unrelated test, since the mismatch is in the test's forbidden-word list, not in the schema.

## 7. References consumed

Consumes `org.organizational_units` (S07) exactly as it exists — no duplicate hierarchy, no new
column on that table. No `ref.*` catalog is touched.

## 8. Temporal model and invariants

Recording a new placement period for an Employment Relationship is one atomic operation (mirrors
S10 spec §7 exactly, without any consequence-wiring step, since Placement has no downstream effect on
the relationship's own end-state):

1. The Employment Relationship is re-fetched fresh with `lockForUpdate()` inside the transaction
   (S09/S10's established discipline) and must not already be ended
   (`end_knowledge_state = 'KNOWN'`) — disclosed design decision, §8.1 below.
2. The target `OrganizationalUnit` is re-fetched fresh by id; **no `is_active` gate is applied in the
   domain command** — mirrors the explicit S10 precedent (S10 spec §7.2, itself citing S06 precedent):
   "no application-layer active-target check... inactive-row exclusion, if ever wanted, is
   future-stage business-rule territory." Authorization-layer inactive-target denial is instead
   supplied for free by reusing `ScopedAuthorizationChecker` unmodified (§10 below) — the same
   outcome, reached without inventing a second, domain-level active check.
3. `effective_from` must be strictly after the Employment Relationship's own `effective_from`
   (mirrors S10 spec §7.3's reasoning: `effective_from` is set once at creation and never mutated by
   any command in this codebase, so this application-level check carries no concurrency risk).
4. If a currently-open period exists for this relationship (`effective_to IS NULL`), it is closed at
   exactly the new period's `effective_from` in the same transaction — periods are contiguous, never
   gapped, by command discipline; the database forbids only overlap.
5. The new period is inserted.
6. No consequence step. Placement never closes, reopens, or otherwise mutates the Employment
   Relationship — it is a pure record of location, unlike S10's status stream.

### 8.1 Why an already-ended relationship rejects a new placement period — disclosed

Not stated explicitly for Placement anywhere in the authorization. Modelled after the direct,
explicit S10 precedent for the sibling stream: "an ended relationship's status history is closed too;
no reactivation" (S10 spec §7.1). Recording a *new* placement against a relationship whose employment
has already ended has no coherent meaning under the frozen S09 model (there is no active employment
episode left to be located anywhere), and rejecting it is the same, minimal, non-inventive default
S10 already established for the sibling stream — reusing S09's own
`EmploymentRelationshipAlreadyEndedException` (409), not a new exception.

## 9. Domain exceptions

| Exception | HTTP | Source |
|---|---|---|
| `EmploymentRelationshipAlreadyEndedException` (S09, reused) | 409 | Recording a placement period against an already-`KNOWN`-ended relationship (§8.1). |
| `InvalidPlacementPeriodDateException` (new) | 422 (`errors.effective_from`) | `effective_from` not strictly after the relationship's own `effective_from`, or not strictly after the currently-open period's own `effective_from` — surfaced by the DB `CHECK`/`EXCLUDE` on the closing `UPDATE`/insert, translated via `PostgresErrorClassifier`, exactly like S09/S10's own pattern. |

No new `StaleVersionException`-shaped class — no `version` column exists (§6). No
`UnresolvedBehavior`-shaped exception — Placement consumes no `ref.*` behavior catalog, unlike S10.

## 10. Authorization — RBAC (`WHAT`) + S08 scope (`WHERE`), composed

Two new permission codes, added to the existing `HumanResourcesPermissionCatalog`:
`hr.organizational_placement_periods.view`, `hr.organizational_placement_periods.record`.

Every route additionally carries the existing `permission:` middleware (coarse `WHAT` gate — "does
this principal hold this permission at all," with its own `security.authorization.denied` audit
event on denial, unchanged, mirroring the existing convention and satisfying
`ScopeBoundaryTest::test_every_hr_route_carries_a_permission_middleware`).

The controller additionally composes the fine-grained `WHERE` gate by calling S08's own, unmodified
`ScopedAuthorizationChecker::authorize(Principal $principal, string $permissionCode, OrganizationalUnit
$target): bool` — reused exactly as S08 built it, with no new authorization class:

- **`store` (record a period):** `$target` is the `OrganizationalUnit` named by the request's
  `organizational_unit_id` — the destination the period is being recorded against. A `false` result
  (missing permission, out-of-scope, or an inactive target — `ScopedAuthorizationChecker` denies all
  three uniformly) returns the same generic `403` the codebase already uses everywhere else, with no
  distinguishing detail leaked between the three causes.
- **`index` (list a relationship's history):** if the relationship currently has an open placement
  period, `$target` is that period's `OrganizationalUnit`. If the relationship has **no** placement
  recorded yet, there is no unit to check scope against — the read is authorized on the permission
  check alone (the coarse `WHAT` gate the route middleware already performed), since the result is
  necessarily an empty list and nothing is disclosed by allowing it through. This is disclosed here,
  not silently decided.

### 10.1 Deferred policy: source-unit scope on a move — disclosed, not invented

`RecordOrganizationalPlacementPeriod` only checks scope against the **destination** unit, never
additionally against whatever unit an existing open period is being closed out of. Requiring
authorization over *both* the source and destination for a move is a real, plausible policy — but it
is squarely a **Transfer** semantics question (Transfer is explicitly not implemented in S11:
authorization §13), not a Placement-primitive question. Inventing that dual-scope rule now, for a
command that Transfer itself does not yet exist to call, would be inventing consequential policy this
authorization does not supply. Deferred to whichever future stage implements Transfer as a
policy-aware command (§24 below).

## 11. Commands

- `RecordOrganizationalPlacementPeriod::handle(EmploymentRelationship $relationship,
  OrganizationalUnit $unit, string $effectiveFrom): OrganizationalPlacementPeriod` — the single
  command implementing §8's algorithm. No separate "close" command (mirrors S10 spec §11 exactly).

Explicitly **not** built: any command named `Transfer`/`Secondment`/`Assignment` (authorization §13:
"Do not implement those movement commands merely to create the placement foundation"); any command to
edit or delete a past placement period (no evidence authorizes retroactive correction).

## 12. Queries

- `ListOrganizationalPlacementPeriodsForRelationship` — ordered by `effective_from` desc, mirrors
  `ListEmploymentStatusPeriodsForRelationship` exactly.

No separate "current placement" query — the first row of the ordered list (equivalently, the row
with `effective_to IS NULL`) is the current placement.

## 13. Consequences

None. Placement never closes, reopens, or mutates the Employment Relationship, unlike S10's status
stream. This is the one structural way S11 is simpler than S10.

## 14. PostgreSQL schema

New table `hr.organizational_placement_periods`, in the existing `hr` schema:

- `organizational_placement_periods_period_check`: `effective_to IS NULL OR effective_to >
  effective_from` (via `TemporalConstraints::validPeriodCheckSql()`, identical helper S06/S09/S10
  already use).
- `organizational_placement_periods_no_overlap`: `EXCLUDE USING gist (employment_relationship_id
  WITH =, daterange(effective_from, effective_to, '[)') WITH &&)` (via
  `TemporalConstraints::noOverlapConstraintSql()`, `btree_gist` already enabled since S02).
- `employment_relationship_id` FK → `hr.employment_relationships.id`, `ON DELETE RESTRICT`.
- `organizational_unit_id` FK → `org.organizational_units.id`, `ON DELETE RESTRICT` — no hard-delete
  of a unit while it has placement history.

No DB trigger — no precedent for one, and §8's one cross-table check (relationship-ended gate) is
safe at the application layer without one, exactly as S10's own equivalent reasoning (S10 spec §8)
concluded for the identical shape of check.

## 15. Concurrency

- Two concurrent `RecordOrganizationalPlacementPeriod` calls for the same Employment Relationship:
  the relationship's own row lock (`lockForUpdate()`, §8 step 1) serializes them — the `EXCLUDE`
  constraint is the final backstop regardless. Proven with a real two-connection race, mirroring
  `ConcurrencyTest`'s established pattern exactly.
- A `RecordOrganizationalPlacementPeriod` call racing a concurrent `EndEmploymentRelationship` (S09)
  call on the same relationship: both acquire the same row lock, so they serialize; whichever
  commits second observes the first's committed state and is rejected via
  `EmploymentRelationshipAlreadyEndedException` if it tried to place against an already-ended
  relationship.

## 16. Authorization/scope test matrix

RBAC-absent-deny (no permission at all → denied regardless of scope); permission present, zero scope
grants → denied; permission + `UNIT` grant present, target org unit outside that grant's subtree →
denied; permission + `UNIT` grant present, target inside the subtree → allowed; `GLOBAL` grant →
allowed for any unit; target unit inactive → denied even with otherwise-sufficient permission and
scope (§10, via `ScopedAuthorizationChecker`'s own existing rule, not a new one); read with no
placement recorded yet → allowed on permission alone (§10).

## 17. Audit

Every mutation runs through the existing `AuditedCommandExecutor` (action
`hr.organizational_placement_period.record`), allowlisted metadata only: `employment_relationship_id`,
`organizational_unit_id`, `effective_from` (all in `changes`, mirroring S10's exact shape). No
National ID or other Person-identifying attribute is placed in audit metadata. No downstream
consequence exists to disclose (§13), so, unlike S10's own audit fix, no extra metadata field is
needed here.

## 18. API

```
GET  /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/placement-periods
POST /api/v1/hr/persons/{person}/employment-relationships/{employmentRelationship}/placement-periods
```

`POST` body: `organizational_unit_id` (uuid — resolved directly by id, not by a `code`, since
`org.organizational_units` deliberately has no `code` column, unlike S09/S10's reference-catalog
lookups by code — disclosed, intentional difference), `effective_from` (date). 404 if the unit does
not exist. Standard `401`/`403`/`409`/`422`; no `PATCH`/`DELETE` (no-hard-delete, no correction
command, §11). IDOR protection: the route's `{employmentRelationship}` must belong to the given
Person's own resource path, mirroring S09/S10's existing checks exactly.

## 19. Migration/legacy compatibility

No import pipeline is built (§5). A relationship with zero recorded placement periods simply has no
known placement history yet — the honest "unknown" representation, never a fabricated retroactive
default, mirroring S10 spec §16's identical reasoning. A future legacy-import stage backfilling
pre-existing placement history needs its own explicitly authorized command.

### 19.1 Migration-rollback ratchet (new cross-dependency)

`hr.organizational_placement_periods` carries **two** RESTRICT FKs — to `hr.employment_relationships`
(S09) and to `org.organizational_units` (S07). It must therefore be rolled back before *either*
`MigrationLifecycleTest::dropHumanResourcesSchemaObjects()` *or*
`MigrationLifecycleTest::dropOrganizationSchemaObjects()` runs, and before either isolated stage
rollback test (`test_s07_migrations_roll_back_and_reapply_cleanly`,
`test_s09_migrations_roll_back_and_reapply_cleanly`). A new
`dropOrganizationalPlacementSchemaObjects()` helper is added (mirroring the existing separate-helper
precedent for `dropSecurityOrganizationalScopeSchemaObjects()`, itself kept separate for the identical
cross-schema-dependency reason), called before both of the existing helpers it must precede.

## 20. Reporting implications

None implemented (§5). The temporal, history-preserving shape (never a mutable "current" field)
keeps the door open for a future Reporting stage to answer "which unit was this relationship placed
in as of date D," but no reporting logic, dataset, or endpoint is built here.

## 21. Frontend boundary

None — backend/domain/API only, matching every prior stage (authorization §33/§34).

## 22. Tests (minimum)

Placement recording (first period, subsequent transition, auto-close of prior open period); overlap
rejection (impossible by construction, tested anyway at the DB layer directly); backdated transition
rejected (`effective_from` not after relationship's own `effective_from`); transition predating the
currently-open period rejected; recording against an already-ended relationship rejected;
organizational-unit-not-found → 404; concurrent same-relationship transitions (real race,
`lockForUpdate()` serializes); RBAC on both routes (401/403); S08 scope enforcement — in-scope
allowed, out-of-scope denied, `GLOBAL` allowed, inactive target denied, no-placement-yet read allowed
on permission alone; IDOR protection; audit entry generated, no PII; migration rollback/reapply
(including the new ratchet ordering, §19.1); PostgreSQL constraint tests (`CHECK`/`EXCLUDE`/FK
exercised directly); full S01–S11 regression; boundary-audit test for §5's exclusions, including the
disclosed, deliberate update to `Organization/ScopeBoundaryTest.php` (§6.1).

## 23. Adversarial review focus

Overlap; write skew; stale route-bound relationship/unit; permission-present-scope-absent bypass;
scope-present-permission-absent bypass; inactive-unit bypass; IDOR; audit omission; PII leakage;
hard-delete; backdated/future-date corruption; cross-stream inconsistency with S09's own end-state;
S12+ leakage (Transfer/Secondment/Supervisory/WorkSchedule/Contract/Qualification/Leave/Migration/
Reporting); cross-project leakage; empty-catalog assumption (none exist here — verified, not
assumed).

## 24. S12+ handoff (disclosed, not blocking)

1. "Actual/current workplace" is not persisted in S11 (§5.1) — a future Secondment stage owns
   introducing whatever structure it needs, additively.
2. Source-unit scope on a move is not checked (§10.1) — deferred to a future Transfer stage's own
   policy.
3. Transfer, Full/Partial Secondment, Supervisory Assignment, Work Schedule all remain gated behind
   their own stated prerequisites (authorization §13/§14/§15/§16) — none started.
4. Every `ref.*`-dependent candidate (Job/Professional History, Employment Category History,
   Qualification, Contract Lifecycle, Leave) remains blocked by an empty catalog with no populating
   command — unchanged since S09/S10's own identical finding.
5. Migration/Import, Reporting, Data Quality/Conflict Resolution — untouched, per authorization
   §21/§22/§23 and the absence of any supplied rules for the last.

None of these represents an unresolved *consequential* policy for S11's own boundary; each is a
named, explicit handoff.

## 25. Internal review (P01–P24, authorization §32)

- **P01 provenance truthful** — §0: no historical title claimed; reconstruction explicitly labelled.
- **P02 dependency DAG complete** — §1.2 checks every one of the 14 candidates against live
  repository/database evidence.
- **P03 stage boundary minimal/coherent** — §4/§5: one entity, one command, one query; explicitly not
  Transfer/Secondment/Assignment.
- **P04 no missing prerequisite** — §1.5 item 2: S07/S08/S09 all shipped and unmodified.
- **P05 no invented reference values** — no `ref.*` catalog touched at all (§7).
- **P06 aggregate ownership** — §6: child of `EmploymentRelationship`, not a new root.
- **P07 temporal semantics** — §8: half-open `[from, to)`, no-gap-by-command/no-overlap-by-DB, exactly
  S09/S10's model.
- **P08 DB invariants** — §14: `CHECK`+`EXCLUDE`+two `RESTRICT` FKs.
- **P09 backdated/future behavior** — §8 steps 3–4; §9 exception table.
- **P10 concurrency** — §15, real two-connection race planned.
- **P11 cross-stream consequences** — §13: none exist; §8.1 discloses the one cross-stream *read*
  (relationship end-state) this stage depends on.
- **P12 RBAC** — §10: two new permission codes, existing `permission:` middleware.
- **P13 organizational scope** — §10: `ScopedAuthorizationChecker` reused unmodified, the first real
  caller since S08 built it.
- **P14 IDOR** — §18: same nested-route check as S09/S10.
- **P15 audit** — §17: `AuditedCommandExecutor`, allowlisted metadata.
- **P16 PII** — §17: no National ID or Person attribute in metadata.
- **P17 API semantics** — §18: standard error codes, no `PATCH`/`DELETE`.
- **P18 migration compatibility** — §19.1: new ratchet ordering explicitly planned before
  implementation, not discovered after.
- **P19 no fabricated history** — §19: absent history stays absent, never defaulted.
- **P20 reporting compatibility** — §20: temporal shape preserved, nothing implemented.
- **P21 no generic CRUD** — one named command, one named query, no generic PATCH/repository.
- **P22 no S12 leakage** — §5/§24: every downstream domain named and explicitly deferred.
- **P23 no cross-project contamination** — no terminology outside this repository's own established
  vocabulary is used anywhere in this document.
- **P24 acceptance testability** — §22: full concrete test list, each traceable to a numbered
  section above.

No consequential issue is unresolved. **PASS — proceeding to implementation.**
