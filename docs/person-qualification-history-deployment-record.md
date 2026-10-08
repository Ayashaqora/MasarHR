# Person Qualification History — deployment record (S48)

**Status: DEPLOYED / PASS WITH DOCUMENTED LIMITS.**

This is a **separate deployment record**, distinct from and additional to the frozen specification
(`docs/person-qualification-history-foundation-specification.md`) and the prior closure record
(`docs/person-qualification-history-closure-record.md`). Neither of those two documents is modified by
this record, in any part, and this record does not restate or re-decide anything they already state — it
only documents, after the fact, the real Windows execution of the S48 application-database cutover on
**2026-10-09**, against the real `masarhr` database, at code commit `b1b5d6eadb70fefc0a97674bdee39a69a29f4792`
on branch `develop`. No `git commit`, `push`, `tag`, migration, or test re-run was performed to produce this
record; it only documents, read-only, what the cutover script's own execution reports already recorded on
disk. Adding this file to Git, and any later push, are separate, later decisions.

## 1. Source of this record

Every fact below is taken directly from the cutover script's own local execution report,
`C:\Projects\s48_cutover_report_20261009_002804.txt` (the second, successful attempt), cross-checked
against `C:\Projects\s48_cutover_report_20261009_001555.txt` (the first, failed attempt) and the backup
files' own `.sha256` sidecars on disk, with one exception: the observation in §5 that October data appeared
after the restart, whose source is the text the user sent directly in this conversation, not an execution
report. Nothing else below is inferred or assumed beyond what the report files state.

## 2. First attempt — failed before any migration (2026-10-09 00:15:55–00:16:15)

- Pre-flight (branch/HEAD, PHP paths, all four migration file hashes), Laravel's own resolved DB config
  (`driver=pgsql`, `database=masarhr`, live `current_database()=masarhr`), starting-schema confirmation,
  and confirmation that none of the four S48 migrations were yet recorded — all passed.
- The maintenance window was opened for real: backend (PID 9368) and frontend (PID 42312) were stopped
  and the port release was verified.
- A full backup was taken and verified: `C:\Projects\masarhr_backups\masarhr_pre_s48_20261009_001614.dump`,
  SHA-256 `982de18eacae2885b6da585e549fd79ba68c093fc0d0dbd20b89d0ad3f71932f` (per its own `.sha256`
  sidecar), table-of-contents readable (349 entries).
- The run **stopped at step `4-create-testdb`**: creating the disposable restore-test database failed —
  `ERROR: permission denied to create database` — because the application account `masarhr` holds no
  `CREATEDB` privilege on this PostgreSQL server. **No migration had run; the `masarhr` schema was
  unchanged.** Per the script's own on-failure design, no automatic rollback or restore was attempted, and
  the maintenance window was left down rather than reopened onto an unverified state. The backup above was
  kept, not discarded.

## 3. Script correction between attempts

The script was corrected (a separate, reviewed change) to use a PostgreSQL **admin account, separate from
the application account**, for creating and dropping the disposable restore-test database — never by
granting the application account `masarhr` any new or elevated privilege. The disposable database is
created with `OWNER` set to the application account, so the application account's own, already-existing
privileges are sufficient for everything after creation. A new pre-flight step (`STEP 3c`) proves the admin
account can actually create and drop a database, for real, **before** any service is stopped. This
correction, its diff, and its hash were reviewed and delivered separately from this record.

## 4. Second attempt — succeeded (2026-10-09 00:28:04–00:30:18)

Per `C:\Projects\s48_cutover_report_20261009_002804.txt`:

- All pre-flight checks passed again for real (branch/HEAD, PHP paths, all four migration file hashes,
  Laravel's resolved DB config, starting schema, migrations-table absence, pg tool discovery, and
  pg_dump/pg_restore/psql vs. server version compatibility — all PostgreSQL 18).
- **STEP 3c**: the separate PostgreSQL admin account (`postgres`) successfully created a disposable
  database owned by the application account `masarhr`, and successfully dropped it again — proving the
  `CREATE DATABASE`/`DROP DATABASE` path for real, before any service was stopped. No service was running
  at this point (both had remained stopped since the first attempt), so step 3's own stop action had
  nothing further to do; the maintenance window was confirmed down regardless.
- **Backup**: `C:\Projects\masarhr_backups\masarhr_pre_s48_20261009_002832.dump`, SHA-256
  `7e653e975c0ad0776f37b9617a34fd48f70a92e1bf1ccea7d12cf241dc1d854a` — this hash was independently
  re-read from the backup's own `.sha256` sidecar file while preparing this record, and matches exactly.
  Table of contents readable (349 entries).
- **Full test-restore**: the disposable restore-test database's table **set** matched the source database
  exactly — **51 tables across 7 non-system schemas** (no table missing, none extra) — and then every one
  of those 51 tables' row counts matched exactly between `masarhr` and the restored copy.
- **Migrations applied, in order, each confirmed against the `migrations` table** (not by exit code alone):
  - `2026_10_22_000001_create_hr_person_qualification_versions_table.php` — exit 0
  - `2026_10_22_000002_backfill_hr_person_qualification_versions.php` — exit 0
  - `2026_10_22_000003_seed_security_person_qualification_correction_permission.php` — exit 0
- **Five data-integrity checks, all 0 discrepancies**, run before the drop migration:
  `exactly-one-current-version-per-qualification`, `no-qualification-without-a-current-version`,
  `current-version-matches-legacy-columns-exactly`, `no-identity-lost-or-duplicated`, and
  `version-1-exists-and-matches-legacy-values-with-null-obtained-on`.
- **Functional check against the real workforce-analytics code path, for month 2026-08-01**, run twice
  inside an enforced `READ ONLY` transaction (in-process, no HTTP): once before the drop migration
  (`pre-drop-gate`) and once after (`post-drop-final-check`). Both runs completed with no exception and
  returned the same set of result keys (`reporting_month`, `month_start`, `next_month_start`, `month_end`,
  `metadata`, `population`, `demographics`, `employment`, `qualifications`, `organization`, `actual_work`,
  `employment_status`, `workforce_flows`, `data_quality`).
- **Drop migration applied**, only after the above held: `2026_10_22_000004_drop_legacy_identity_columns_from_hr_person_qualifications.php`
  — exit 0.
- **Final verification**: all four S48 migrations confirmed recorded in the `migrations` table; the same
  functional check repeated post-drop, as above, with the same success.
- The advisory legacy-reader scan (informational only, never a gate) flagged 11 files for human review;
  this did not block, and nothing in this record treats that scan as proof of either a defect or its
  absence.
- **Step 8e (automatic restart) had nothing to do** in this run: because nothing was stopped by *this* run
  (the application was already down from the first attempt), the script correctly restarted nothing of its
  own — it only ever restarts what it itself stopped.

## 5. Application restart and post-deployment observation

The application was restarted separately, using the project's own local launcher (`run-local.ps1`/`run.bat`),
after the cutover script finished — not by the cutover script itself (see §4, Step 8e). The user reported
that the application came back up and sent the text of the October dashboard; this was reviewed in this
conversation. **This record does not claim an independent HTTP health
check or any other independent verification of the running application by this engagement** — that
observation is recorded here as what the user reported and confirmed, not as a check this engagement
performed or re-verified itself.

## 6. Documented limits

- The advisory legacy-reader scan's 11 flagged files (§4) were not individually reviewed or dispositioned
  as part of this deployment; they remain informational findings for a human to look at, exactly as the
  script itself describes them.
- No independent HTTP-level health check of the restarted application was performed by this engagement
  (see §5) — the only functional evidence is the in-process, pre/post-drop workforce-analytics check
  described in §4, plus the user's own reported observation of October data.
- This record does not reopen, revise, or add to the frozen specification or the prior closure record; any
  further work (including S49) requires its own, separate authorization.

## 7. No Git action taken to produce this record

Writing this one file was a plain file write to disk, not a Git operation: no `git add`, `commit`, `push`,
`tag`, merge, or lock handling of any kind was performed to produce it. Whether and when to add it to Git
is a separate decision, to be made after this record is reviewed.
