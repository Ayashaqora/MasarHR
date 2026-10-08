# Person Qualification History — closure record (S48)

**Status: S48 Backend = CLOSED / PASS WITH DOCUMENTED LIMITS**, per explicit architecture-authority
direction recorded in this engagement's review conversation on 2026-10-08 ("S48 — DOCUMENT APPROVED
CLOSURE ONLY" / "قرار المراجعة ... S48 Backend = CLOSED / PASS WITH DOCUMENTED LIMITS"). **S49 is NOT
AUTHORIZED.** This record documents a decision already made by the architecture authority; it does not
itself constitute the closure decision. No `git commit`, `push`, or `tag` was ever run at any point in
this stage, including to produce this record. Earlier review rounds' isolated cloud clone did make two
real local `.git/index` writes (`git add -N` followed by `git reset`, net-reverted) solely to generate
diff artifacts for review — never a commit/push/tag, and never against the real Windows repository (see
§3). Producing this closure record itself used no Git write of any kind, on either side — see §6.

## 0. Nature of this record

This is a **separate closure record**, distinct from and additional to the frozen specification
(`docs/person-qualification-history-foundation-specification.md`). That specification is **not modified**
by this record, in any part, and this record does not restate it as authority — it only documents, after
the fact, what was executed, what was proven by test, and what limits remain. Where this record states
what S48 does, that is a description of the implemented code and its test evidence as found during this
engagement's review rounds, not a quotation of the frozen specification. Facts about tooling, device
reachability, or sync status are reported as directly observed by this record's author during this
documentation pass (a cloud session with read/write access to the repository via a device bridge, and no
ability to execute the real Windows toolchain itself) — never inferred beyond what was directly checked.

## 1. Scope — what was executed

S48 moves qualification facts for a Person off a single mutable row and onto an append-only version
history, while keeping a stable identity anchor for each qualification. The following areas were executed
and are each backed by the implementation files and tests named:

- **Stable qualification identity.** The parent row `hr.person_qualifications` (id, person_id) is now an
  immutable identity anchor only — it no longer carries the fact values (`academic_degree_id`,
  `qualification_type_id`) directly. Its own id and person_id are immutable once created. Evidenced by
  `backend/app/Modules/HumanResources/Infrastructure/Persistence/Eloquent/PersonQualification.php` and
  `PersonQualificationHistoryFoundationTest::test_the_parent_rows_id_and_person_id_are_immutable_once_created`.

- **Historical versions.** `hr.person_qualification_versions` is a new, append-only table: every version
  row is immutable except the single `is_current` flip, and no version row can ever be deleted (enforced
  by the unconditional `person_qualification_versions_immutable` trigger, DELETE-blocking). Evidenced by
  `backend/database/migrations/2026_10_22_000001_create_hr_person_qualification_versions_table.php`, the
  new `PersonQualificationVersion.php` Eloquent model, and
  `test_version_rows_are_immutable_except_the_current_flip_and_can_never_be_deleted_even_when_backfilled`.

- **Correction with `expected_version`.** `CorrectPersonQualification` applies a correction only when the
  caller's `expected_version` matches the currently committed version (optimistic concurrency); a mismatch
  raises `StaleQualificationVersionException` (HTTP 409) and writes nothing; a correction matching the
  current values exactly is rejected as a no-op (`NoOpQualificationCorrectionException`). Evidenced by
  `backend/app/Modules/HumanResources/Application/Commands/CorrectPersonQualification.php`,
  `PersonQualificationCorrection.php`, and
  `test_correction_with_a_stale_expected_version_is_rejected_with_409_and_writes_nothing` /
  `test_a_correction_matching_the_current_values_exactly_is_rejected_as_a_no_op`.

- **Current and historical reads.** `hr.person_qualifications_current` is a view that always resolves to
  each qualification's current version; it is what every current-state read now goes through.
  `ListPersonQualificationVersions` exposes the full, ordered version history of a given qualification for
  historical reads. Evidenced by `PersonQualificationCurrent.php`, `ListPersonQualificationVersions.php`,
  and the version-listing and current-read test groups in `PersonQualificationHistoryFoundationTest.php`.

- **Primary qualification history.** `BuildPersonPrimaryQualificationHistory` /
  `PersonPrimaryQualificationHistory` / `PrimaryQualificationHistoryEvent` build an audit-trail-derived
  event history of primary-qualification designation changes for a Person, with evidence-completeness gap
  detection (flags a current holder's own unevidenced designation, an earlier no-longer-current gap, an
  A→B→A cycle that keeps every transition, and a gap behind a repeated qualification id) and validated
  pagination that is stable and identical across pages. Evidenced by the three new
  `Application/Queries/*PrimaryQualificationHistory*` files and the corresponding
  `test_evidence_completeness_*` / `test_pagination_is_validated_on_both_the_versions_and_primary_history_endpoints`
  tests.

- **Permissions and audit.** Current reads (`GET .../qualifications`) and both history reads
  (`GET .../qualifications/{id}/versions` and `GET .../qualifications/primary-history`) are gated by the
  **same** permission, `hr.person_qualifications.view` — reading history carries no separate permission of
  its own. `record`, `correct`, and `designate_primary` are each gated by their own distinct permission
  (`hr.person_qualifications.record` / `.correct` / `.designate_primary`), independent of `.view` and of
  each other — holding one of these four permissions does not thereby grant any other. Evidenced by
  `routes/api.php`'s `permission:` middleware on each of the five routes and by
  `HumanResourcesPermissionCatalog.php`'s four constants, and proven by
  `test_each_qualification_permission_authorizes_only_its_own_action`. Corrections and recordings are
  attributed to a real actor principal (see §4's actor-requirement fix) and are visible through the
  existing audit-command infrastructure. Evidenced further by the actor-provenance tests
  (`test_a_backfilled_version_shows_unknown_actor_provenance_never_a_primary_history_gap_code`,
  `test_provenance_alone_never_distinguishes_a_backfilled_write_from_a_live_one`).

- **Reporting-consumer redirection.** Beyond the consumers the frozen specification's own table had
  already verified, this stage's own discovery (a grep of the whole backend tree for direct reads of the
  legacy `academic_degree_id` / `qualification_type_id` columns against `hr.person_qualifications`, not
  `_current`) found two further direct readers —
  `Application/Queries/Reporting/ListMonthlyReportingPopulation.php` and
  `Application/Queries/Reporting/ListReportingPopulationAsOf.php` — and repointed both to
  `hr.person_qualifications_current`, with no other change to selected columns, joins, or ordering. Full
  detail, including the diff and the specific regression tests that verify report semantics were
  preserved, is recorded separately in `additional-consumers-appendix.md` (part of the accepted review
  package; not duplicated here).

- **Database guarantees and rollback.** "Exactly one current version per qualification" is enforced by two
  mechanisms together, not by the deferred triggers alone. A partial unique index,
  `person_qualification_versions_one_current` (`ON hr.person_qualification_versions (person_qualification_id)
  WHERE is_current`), enforces immediately — not deferred — that **no more than one** version can be
  current for a given qualification. Two separate `DEFERRABLE INITIALLY DEFERRED` constraint triggers,
  `person_qualification_versions_at_least_one_current` and `person_qualifications_has_current_version`,
  enforce at real transaction commit that a current version is **not missing**, raising `MA005` when
  violated — proven under a real top-level `COMMIT` (not only a forced `SET CONSTRAINTS ALL IMMEDIATE`
  inside a savepoint; see `acceptance-matrix-mapping.md` row 9 for the full methodology). The "exactly one"
  guarantee is the combination of both: the unique index rules out more than one, the deferred triggers
  rule out zero. The backfill migration
  (`2026_10_22_000002_backfill_hr_person_qualification_versions.php`) and the legacy-column-drop migration
  (`2026_10_22_000004_drop_legacy_identity_columns_from_hr_person_qualifications.php`) both carry
  verify-before-act logic and documented, reversible `down()` paths. This round's own fix work (§4) made
  that rollback path safe for the non-empty, all-unknown-actor backfill case specifically, and closed a gap
  where the rollback-refusal gate was checked late in two of the three migrations.

## 2. Test evidence — recorded separately, not summed

Three separate data points, from three separate points in this engagement, are recorded here exactly as
run. **They are not summed, and the 2442-test full run is not described as having been re-run after the
most recent fixes** — it was not; only the narrower runs listed under "after the most recent fixes" below
were executed following those fixes, per the explicit instruction not to re-run the full suite without a
new reason.

- **Baseline, before S48:** 2390 tests, **2389 passed, 1 failed**.
- **Full run, before the most recent (rollback/actor) fixes:** 2442 tests, **2441 passed**, the
  **same** one pre-existing failure as the baseline (not a new or different failure).
- **After the most recent (rollback/actor) fixes** — four separate, narrower runs, each its own data
  point:
  - The fix's own new tests, run isolated: **6/6 passed**.
  - `MigrationLifecycleTest.php`, run in full: **39/39 passed**.
  - The seven affected record/correction/concurrency test files, run combined: **229/229 passed**.
  - Lint (Pint): **PASS**.

The one failure present in both the baseline and the pre-fix full run is named here explicitly:
`Tests\Feature\Reference\SpecialtyCatalogAdministrationFoundationTest::test_no_hr_route_and_no_employee_360_surface_exposes_specialty`.
It is a frontend-fixture assertion unrelated to the HumanResources module or to person-qualification code,
it is present in the baseline (i.e. it predates S48 entirely), and it was not introduced, touched, or
fixed by any S48 work. It is recorded here as a known, pre-existing, unrelated condition — not resolved by
this closure.

## 3. Documented limits

- **Tests ran on an isolated PostgreSQL instance inside a Linux cloud sandbox**
  (`masarhr_test` at `127.0.0.1:5432`, PostgreSQL 16), entirely separate from the real application
  database and from the Windows toolchain. No test in this engagement was executed on Windows itself;
  "passing" throughout this record means passing in that isolated Linux/PostgreSQL environment, not a
  Windows-side test run.
- **The Windows file sync is attested, not independently re-executed, by this record.** The sync of the
  52-file S48 payload to the real repository (`C:\Projects\MasarHR`) was performed and recorded in this
  engagement's own execution report (device-bridge file sync, hash-verified both before and after). The
  review side separately verified the full-file package against the manifest and confirmed **52/52**.
  This closure record does not re-run that sync; it records that it was done and verified.
- **No migration was applied to the real application database.** `php artisan migrate` was not run
  against the actual MasarHR application database at any point in this stage. The four new migration files
  exist on disk (synced to the real repository per the point above) and were exercised only inside the
  isolated test database described above.
- **The qualification-archive interface (واجهة أرشيف المؤهلات) is not implemented in this stage.** No
  Frontend archive/history-browsing UI for qualifications was built or scoped as part of S48 backend work;
  this remains explicitly out of scope for this closure.
- **Git write distinction.** An earlier review round's isolated cloud clone used `git add -N` followed by
  `git reset` to generate this stage's diff artifacts for review — two real local writes to that clone's
  own `.git/index` (net-reverted, verified via file-count equality, never a `commit`/`push`/`tag`). The
  **real Windows repository** (`C:\Projects\MasarHR`) received **zero Git writes of any kind** at any
  point in this stage's review or in producing this closure record — every command run against it was one
  of `git status`, `git diff`, `git log`, `git rev-parse`, confirmed again while writing this record (see
  §6). This record itself was produced and delivered under a documentation-only authorization; no
  `git add`, `commit`, `push`, or `tag` was run to produce it, and none is implied by its existence —
  committing and pushing this file (or any other) requires a separate, explicit authorization.

## 4. Fix evidence from the most recent round (already accepted; not re-tested here)

Three confirmed defects were fixed and accepted in the review round immediately preceding this closure
record, and are summarized here for completeness (full detail in that round's own review package, not
restated):

1. A safe rollback path (trigger disable/enable bracketing) for the non-empty backfill rollback case,
   proven against a real non-empty, all-unknown-actor backfill with triggers active.
2. The rollback-refusal gate check moved to before the first change in all three of
   `2026_10_22_000002`/`000003`/`000004`'s `down()` methods (rollback runs most-recent-first), proven by
   three separate refusal tests (known actor from backfill, new record, new correction).
3. `RecordPersonQualification` and `CorrectPersonQualification` now reject a missing/null
   `actorPrincipalId` before any write in their normal command path; `NULL` remains reserved only for the
   documented backfill migration path. All test callers were updated to pass a real synthetic principal.

## 5. Git state before this record was created

Checked read-only against the real repository (`C:\Projects\MasarHR`) immediately before this record's
file was written to it — **not** a full accounting of repository state after its creation; once this
record's own file is written, `git status` additionally shows it as one more untracked item, which is
not re-enumerated here:

- Branch: `develop`
- `HEAD`: `19fcc5cccb819ac5698dd87d007047eef83c4cbb` (unchanged from every prior round in this stage)
- `git status --porcelain`, at that point: 31 modified + 21 untracked files matching the accepted S48
  payload exactly, plus exactly 3 pre-existing items outside that payload (`backend/composer.json`,
  `_to_delete/`, and the frozen specification file itself,
  `docs/person-qualification-history-foundation-specification.md`) — none of which this closure record
  touches.
- No command other than `git status`/`rev-parse` was run against the real repository to write this
  record, before or after.

## 6. Delivery of this record

This file (`docs/person-qualification-history-closure-record.md`) was written in the isolated cloud
workspace and delivered to the real repository (`C:\Projects\MasarHR\docs\`) via the device bridge as a
plain filesystem write — the same mechanism used for every prior S48 file sync in this stage, and, like
those, **not a Git operation of any kind**. Its SHA-256 is recorded in the final report accompanying this
record. No `git add`, `commit`, `push`, or `tag` was run on either side to deliver it. Committing and
pushing this record — and, separately, S48's own payload — requires its own explicit authorization, not
yet given.

## 7. Closing statement

S48 Backend is recorded here as **CLOSED / PASS WITH DOCUMENTED LIMITS**, per the architecture authority's
explicit direction of 2026-10-08. This status rests on: the scope described in §1, each item backed by
named implementation files and tests; the three separate, unsummed test data points in §2, with the one
pre-existing, unrelated failure named and attributed to the baseline; and the limits in §3, none of which
is treated here as resolved. S49 is not authorized by this record or by anything in it.
