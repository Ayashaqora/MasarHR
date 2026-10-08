# S48 — Person Qualification History and Correction Foundation

**STATUS: APPROVED / FROZEN.** Design accepted and this specification frozen by the Architecture
Authority's explicit decision in this conversation, dated **2026-10-06**, after two further
documentation-only corrections (round 6, below) to the RC5 text. This freeze is of the
**specification document only** — it authorizes writing this text to
`docs/person-qualification-history-foundation-specification.md` on `C:\Projects\MasarHR`,
overwriting the DRAFT revision 1 previously there (its content saved outside the project tree
first, §S48.19), and nothing beyond that: no code, no migration, no database change, no test, and
no Git action is authorized by this freeze or by the sync it calls for. Execution of S48 — writing
the migration, the commands, the routes — is a separate, independent authorization to come later,
explicitly conditioned on this sync being confirmed first.

**Stage: S48. Baseline: `develop` @ `19fcc5cccb819ac5698dd87d007047eef83c4cbb`** — re-confirmed this
round (`git rev-parse HEAD` on the connected machine), unchanged.

## §S48.0 Decision provenance — what is new today and what is not

**Pre-existing, frozen before this conversation — referenced, not re-decided here.** R1-D49; the
Person-row-lock convention `RecordPersonQualification`/`DesignateQualificationAsPrimary` already
use; S23's original identity rule; S41 §S41.6/§S41.7's schema, write behavior, and audit shape for
`is_primary`; S41 §S41.17 and the S45 dashboard's reporting semantics. None of these were decided in
this conversation, and nothing in this document re-opens them.

**Decided in this conversation, on 2026-10-06 — ADR-S48-DECISIONS, across six rounds, ending in this
document's freeze.** Round 1 produced D01–D09. Round 2 (revision 2) added D10–D21. Round 3 (RC3)
sharpened D18–D21 and added D22–D31. Round 4 (RC4) sharpened D22, D25, D27, D28, D30 and added
D32–D39. **Round 5 (RC5) corrected three errors the Architecture Authority found in RC4 while
reviewing RC3 and RC4 together:**

1. **Duplicate prevention (D39→D40).** RC4 asserted the current-identity uniqueness index was a
   cross-Person protection and required a test where two different Persons correcting to the same
   combination produces one success and one rejection. This directly contradicted the index RC4's
   own §S48.8 defines — `(person_id, academic_degree_id, qualification_type_id) ... WHERE
   is_current` is scoped *within* a Person by construction (D14, unchanged since revision 2), so two
   different Persons sharing a combination is not a duplicate and never has been. The real race is
   two *different qualifications of the same Person* — kept, corrected.
2. **Primary-history chain walk (D35→D41).** RC4 stopped the backward chain walk on a repeated
   qualification id, reasoning this safely handled `A → B → A`. It does not: a repeat can mask a
   real, earlier gap (`C → A → B → A` with C's origin unevidenced). The walk is corrected to advance
   by audit-event position, never by qualification identity, so a repeated id is never, by itself, a
   reason to stop. RC4's separate, unrelated fix — that a `record` entry has no `previous` key at
   all — was correct and is unchanged; only the id-tracking cycle-stop is withdrawn. RC4's claim that
   `UUIDv7` alone proves execution order at an exact timestamp tie is also softened here: `id` is a
   stable, deterministic tie-breaker, not a proof of true execution order.
3. **Rollback gate (D34/D30→D42).** RC4's rollback gate checked only for a correction
   (`version_number > 1`). That misses silent data loss from an ordinary post-migration recording
   that carries a real `obtained_on`/actor the pre-S48 schema cannot hold at all — no correction
   required for that loss to happen. The gate now checks for any row created by a real actor
   (`created_by_principal_id IS NOT NULL`), which subsumes the old check.

Additionally, round 5 removed one already-resolved open item from §S48.17 (the "unknown" display
string was never a backend-execution blocker — the API value has been `null` since D01, and wording
is a frontend concern explicitly out of scope, §S48.15).

**Round 6 (this revision) — design accepted; two further documentation corrections to round 5's own
text, then the freeze.** The Architecture Authority accepted round 5's duplicate-prevention and
primary-history corrections (D40, D41) as they stand, and asked for two remaining imprecisions to be
fixed before freezing:

4. **Provenance wording (D36→D43).** Round 5 described `RECORDED` as applying to versions created
   "from this point on" — reading as a marker for post-migration writes. It is not: the backfill can
   resolve a real actor from a pre-existing audit entry, in which case a *backfilled* row is
   `RECORDED` too. `provenance` answers only whether an actor is evidenced, never when or by what
   path a version was created.
5. **Rollback-gate precision claim (D42→D44).** Round 5 claimed its rollback-gate condition "is true
   only when every row is still exactly what the backfill wrote" — stated as though the condition
   precisely detects the first real S48 write. It does not, for the same reason as point 4: the gate
   can trip immediately after backfill, before any new write occurs, whenever the backfill resolved
   real actors from history. **The gate's condition itself is kept unchanged, as a deliberately
   conservative policy** — accepted explicitly as erring toward blocking rollback rather than risking
   silent data loss — only the claim of precision is withdrawn.

§S48.2 lists D39→D40, D35→D41, D34/D30→D42, D36→D43, and D42→D44 precisely, each marked as a
correction, not a restatement. **No functional decision settled in round 1 through 5, and not one of
these five corrections, is reopened here.** With these two corrections applied, the Architecture
Authority's freeze (§S48's STATUS line above) takes effect for this document.

**This round's verification was direct, not re-asserted from memory of earlier rounds.** The device
bridge to `C:\Projects\MasarHR` was reachable this time (it was not for part of round 2). Read
directly, read-only, this round: `PersonQualificationController.php` (both actions' full `AuditSpec`
construction), `Outcome.php`, `AuditSpec.php`, `AuditAppendService.php`,
`DuplicatePersonQualificationException.php`, `bootstrap/app.php`'s exception-rendering map, two
existing paginated list endpoints (`EmploymentStatusExpiryFollowUpController.php`,
`MovementExpiryFollowUpController.php`), and `PersonQualificationResource.php`. `git rev-parse
HEAD`/`--abbrev-ref HEAD` re-confirmed the baseline is unchanged. Nothing was written to the
repository; this is the same read-only discipline every prior round used, just completed this time
instead of partially blocked by connectivity.

## §S48.1 Scope

`docs/human-cadre-report-foundation-specification.md` §S41.20 names "qualification history" as a
known, explicitly deferred item. This revision replaces revision 1's correction design (a
non-participating side-table of annotations) with a **versioned logical record**: a qualification
has one stable identity for its whole life; every correction produces a new version of that same
identity; the current read path always resolves to the latest approved version; the archive shows
every prior version. This satisfies, directly: no wrong value is ever left live in a report; a
correction is never counted as an additional qualification; and duplicate prevention applies to
each qualification's current (possibly corrected) value, not only to what was first recorded.

Unchanged from revision 1: the optional, separately-tracked date of attainment; exposure of the
existing recording timestamp; a read surface for Primary-designation history sourced from the audit
trail; and the out-of-scope boundary (§S48.15).

## §S48.2 Frozen decisions (ADR-S48-DECISIONS)

**From revision 1, unchanged:**

* **D01 — Date obtained.** Optional, stored separately from the recording timestamp, `NULL`
  displayed as "unknown", never inferred.
* **D02 — Recording date.** `created_at` on the qualification's stable identity is exposed through
  the API; never confused with the date obtained.
* **D03 — Primary-change history.** Discrete events carrying only the time of execution; no
  retroactive designation.
* **D04 — Pre-existing records.** The history surface shows only what is actually known; nothing
  guessed or interpolated.
* **D06 — Reading the archive.** Under the owning Person, authorized by the existing
  `hr.person_qualifications.view` permission, through a purpose-built response shape.
* **D07 — Correction permission.** Independent of record and designate-primary.
* **D09 — Specialization / issuing authority.** Out of scope.

**Superseded in the first round of revision 2:**

* **D05 (superseded).** Was: "a correction is a new record linked to the original, kept in a
  separate table." Replaced by **D10**.
* **D08 (superseded).** Was: "this archive does not touch S41 or S45 at all." Replaced by **D13**.

**Added in the first round of revision 2, unchanged since:**

* **D10 — Qualification identity.** A qualification is a logical record with one stable identity
  for its lifetime (`hr.person_qualifications.id`, unchanged). A correction is a new version of
  that identity, not a new qualification. Every version is kept, never deleted. The current read
  path always uses the latest *current* version.
* **D11 — Correction content.** A correction records the previous values, the new values, a
  mandatory reason, who recorded it, and when.
* **D12 — Primary is identity-scoped.** `is_primary` stays on the stable identity row, never on a
  version. A correction never moves Primary status; only `designate-primary` can.
* **D13 — Consumer impact is explicit, not prohibited.** S41/S45 keep their documented semantics,
  but the specific queries reading `academic_degree_id`/`qualification_type_id` directly off
  `hr.person_qualifications` must be repointed (§S48.13).
* **D14 — Duplicate prevention follows the current value.** Applies to each qualification's
  current version, while every historical version is retained (§S48.8).
* **D15 — An idempotent re-designation is never shown as a transition** (§S48.10).

**Added in the second round of revision 2 (round 2's completion):**

* **D16 — Optimistic concurrency.** A correction request carries `expected_version`. After the row
  lock is acquired, the server compares it to the actual current version; a mismatch is rejected
  (409) with no write and no automatic retry. The row lock protects the database's internal
  consistency, not a caller acting on stale information — only `expected_version` solves the second
  problem (§S48.5).
* **D17 — Database-enforced guarantees, named precisely.** Schema-level constraints and
  transactional guarantees are not interchangeable and are not described as if they were (§S48.8).
* **D18 — superseded in RC3 by D25; D25 itself sharpened in RC4 by D39** (see below).
* **D19 — sharpened in RC3 by D30; D30 itself sharpened in RC4 by D34** (see below).
* **D20 — expanded in RC3 by D31; further corrected in RC4 by D38** (file paths and a seventh
  verified consumer).
* **D21 — superseded in RC3 by D29.** Unchanged in RC4.

**Added in RC3 (round 3), most unchanged since, four sharpened this round:**

* **D22 (sharpened in RC4 by D32).** RC3's mechanism for "exactly one current version, guaranteed
  at commit" used a `DEFERRABLE` `EXCLUDE USING gist` constraint (requiring the `btree_gist`
  extension) for the "at most one" half. **That half never needed to be deferred at all** — §S48.5's
  `UPDATE`-then-`INSERT` sequence never creates two current versions simultaneously, only a
  momentary *zero*, so an ordinary, immediate, non-deferred partial unique index (revision 2's
  original design) already enforces "at most one" correctly, with no extension dependency. Only the
  "at least one" half genuinely needs a deferred mechanism, because PostgreSQL has no declarative
  way to express "at least one of these rows must exist" at all — **D32** keeps that one deferred
  trigger, drops `EXCLUDE`/`btree_gist` entirely, and extends the same guarantee to cover the
  *parent* table's own `INSERT` (so a qualification created without ever gaining a version row is
  itself rejected at commit, not just a version row created in isolation).
* **D23, D24** — unchanged since RC3.
* **D25 (sharpened in RC4 by D39; D39 itself corrected in RC5 — see below).** The unified
  Person-then-Qualification lock order itself is unchanged since RC3.
* **D26, D27 (partially sharpened in RC4 by D37)** — the request/response contract itself is
  unchanged; **D37** closes what RC3 left open: exact pagination parameters and the exact JSON
  envelope, using an existing, verified project convention rather than an invented one.
* **D28 (sharpened in RC4 by D35; D35 itself corrected in RC5 — see below).** The
  `events`/`evidence_completeness` separation is unchanged. What RC3 under-specified: deterministic
  ordering and deduplication of the underlying audit events, the chain walk's behavior when a
  qualification re-becomes Primary after losing that status (`A → B → A`), and that
  `evidence_completeness` must be computed over the *entire* chain regardless of which page of
  `events` is being returned. RC3 also stated `AUTO_FIRST`'s "previous = null" as if a `previous`
  key existed and read `null` on a `record` audit entry; direct verification in RC4 showed the
  `record` entry's `changes` payload has no `previous` key at all — RC4's wording fix for that part
  is unchanged and correct.
* **D29** — unchanged since RC3.
* **D30 (sharpened in RC4 by D34; D34's rollback-gate condition itself corrected in RC5 — see
  below).** The no-unverified-application-claim rule and the general shape of a migration/rollback
  plan are unchanged. RC3's own maintenance-window step 4 was imprecise enough to read as opening
  real traffic before the old columns are dropped — RC4's window-sequencing fix for that part is
  unchanged and correct; only RC4's rollback-gate *condition* (checking `version_number > 1` alone)
  is corrected this round.
* **D31 — expanded and partially verified in RC4 by D38** (see below).

**Added in RC4 (round 4, same conversation, same date):**

* **D32 — "At most one current version" is an ordinary, immediate, non-deferred partial unique
  index; only "at least one" needs a deferred mechanism, and it now also guards the parent table's
  `INSERT`.** No `btree_gist` extension dependency. §S48.8/§S48.16 required test: inserting a
  `hr.person_qualifications` row within a transaction that never inserts a matching version row
  must be rejected at `COMMIT`, not before — exercising the *parent-table* side of the guarantee
  specifically, which RC3's single test (scoped to the versions table alone) never did.
* **D33 — The parent qualification row's identity (`id`) and ownership (`person_id`) are
  immutable once created.** The versions-table person-match trigger (D24) only protects a
  *version* row from disagreeing with its parent; it does nothing if the *parent* row's own
  `person_id` is changed directly, which would silently re-point every one of that qualification's
  existing versions to a different Person without touching any of them. A new trigger on
  `hr.person_qualifications` itself closes this (§S48.8).
* **D34 — The maintenance window spans the entire cutover; no real traffic exists between the
  backfill and the column drop; a rollback never lets old code run against the new schema.** §S48.18
  is rewritten so that code deployment happens, and is confirmed healthy by internal checks only,
  *inside* the same window that opened for the backfill — the window does not close and reopen
  around the deploy step.
* **D35 — Primary-history ordering, deduplication, and the whole-chain scope of
  `evidence_completeness` are specified precisely; `AUTO_FIRST`'s wording is corrected to match the
  verified absence of a `previous` key on a `record` entry.** Events are ordered by `occurred_at`
  then by the audit entry's own `id` (a UUIDv7, itself time-ordered, as the stable tie-breaker for
  equal timestamps); the backward chain walk via `previous_primary_qualification_id` tracks visited
  qualification ids explicitly to terminate correctly on an `A → B → A` cycle instead of looping;
  `evidence_completeness` reflects the full history and is identical regardless of which page of
  `events` a given request asks for (§S48.10).
* **D36 — A version's provenance is its own, separately-named concept — never the primary-history
  gap code `GAP_NO_DESIGNATION_EVIDENCE`.** Whether a *qualification's recording* has a known actor
  (`created_by_principal_id`) is a different question from whether a *Primary designation* is
  evidenced, and revision 3 answered the first question by reusing the second's code name. A new
  field, `provenance` (`RECORDED` | `BACKFILLED_UNKNOWN_ACTOR`), is defined for the versions-list
  response instead, derived from `created_by_principal_id` alone, fabricating no time or actor
  (§S48.12, §S48.14). **Corrected in round 6 by D43:** `RECORDED` means only "this version's
  recording actor is evidenced" — it does not mean, and is never used to decide, whether the version
  was created before or after this migration. A backfilled row whose original actor was recoverable
  from a pre-existing audit entry is `RECORDED` too, indistinguishably from a genuinely new,
  post-migration write.
* **D37 — The pagination contract is frozen using an existing, verified project convention, not an
  invented one.** `per_page`: `nullable|integer|min:1|max:100`, defaulting to 25 — the exact rule
  already used verbatim by `EmploymentStatusExpiryFollowUpController`/`MovementExpiryFollowUpController`.
  `page`: this document adds an explicit `nullable|integer|min:1` validation rule for both new
  endpoints — stricter than those two existing endpoints, which leave `page` entirely to Laravel's
  paginator (silently clamping an invalid value to 1 rather than rejecting it); that stricter
  behavior is this round's own explicit decision, not a discovery from source, and is named as such
  (§S48.14).
* **D38 — Literal `target_type`/`action`/`outcome` values, consumer file paths, and one further
  verified consumer, all confirmed directly against source this round.** `target_type` for both
  `hr.person_qualification.record` and `hr.person_qualification.designate_primary` audit entries is
  the literal string `hr_person_qualification` (snake_case, underscore — not dot-separated, unlike
  the `action` values, which are dot-separated and were already correct in RC3). `outcome` on a
  `MUTATION` entry is always the literal `SUCCEEDED` (the `Outcome` enum's backing value), never a
  lowercase `success`. Every consumer file path in §S48.13 is the real `backend/app/Modules/...`
  path, not the `app/...` placeholder RC2/RC3 used. `PersonQualificationController::store()`'s own
  `AuditSpec` `changes` closure — which reads `$qualification->academic_degree_id`/
  `qualification_type_id` directly off the Eloquent model — is added to §S48.13's *verified* table
  (not the category tail) as a seventh consumer, since the model stops exposing those attributes
  once the columns move; `designatePrimary()`'s own `AuditSpec` is confirmed, by the same read, to
  touch neither column and needs no change.

**Added in RC5 (round 5, same conversation, same date) — three corrections to RC4, nothing else
reopened:**

* **D39 (RC4; corrected in RC5 — see D40).** RC4's own text is withdrawn, not merely restated: it
  claimed the current-identity uniqueness index was "the final protection across different
  Persons" and required a test where two different Persons correcting to the same combination
  results in one succeeding and the other rejected. **That was wrong, and it was never a reading of
  this document's own schema — it contradicted it.** The index defined in §S48.8 all along is `(person_id,
  academic_degree_id, qualification_type_id) ... WHERE is_current` — `person_id` is one of the
  indexed columns, so the constraint has always been scoped *within* a Person, exactly carrying
  forward D14/the original S23 identity rule. Two different Persons sharing the same
  `(academic_degree_id, qualification_type_id)` combination is not a duplicate at all under this
  index and never has been; RC4 asserted a cross-Person business rule this document's own SQL never
  implemented. **D40** replaces D39's claim with the correct one, still required this round
  (D39's third test, `designate` against `correct` for the same Person, was not wrong and is kept
  under D40 below).
* **D40 (RC5, new).** Duplicate prevention is, and has always been, scoped within one Person — never
  across Persons (D14, unchanged since revision 2; the index itself was never wrong, only RC4's
  prose about it). Two different Persons corrected concurrently to the identical combination both
  succeed — there is no conflict, so this is a non-event, not a race to resolve. The real race the
  current-identity index exists to catch is **two different qualifications belonging to the *same*
  Person**, corrected concurrently to the same combination: because `CorrectPersonQualification`
  locks the Person row first (D25), two such corrections for the same Person cannot truly interleave
  at the row level — the second waits for the first's transaction to finish — but the Person lock by
  itself enforces only *ordering*, not the business invariant; the second correction, once admitted,
  still attempts to write the same now-taken value and is still rejected by the index's `INSERT`
  check, with its entire transaction rolled back. The Person-row lock continues to serialize
  `Record`/`Correct`/`Designate` commands for the same Person only (D25, unchanged); it was never
  meant to, and does not, say anything about two different Persons at all — there being nothing to
  say, since nothing crosses Persons in this constraint. D39's third required test
  (`designate-primary` concurrent with `correct`, same qualification, same Person) is unaffected by
  this correction and is kept (§S48.9).
* **D41 (RC5, new) — the primary-history chain walk advances by event position, never by
  qualification identity, and a repeated qualification id is not, by itself, a reason to stop.**
  RC4's walk tracked *visited qualification ids* and stopped on a repeat, reasoning that this
  safely handled `A → B → A`. That reasoning was wrong: stopping on a repeated id can hide a real,
  earlier gap that the repeat has nothing to do with — a Person whose history is `C → A → B → A`
  (C's own origin unevidenced, then A takes over, then B, then A again) has the walk, started from
  the current holder A, reach "A" a second time while tracing *back through B's own predecessor*,
  which is a different point in the Person's history than the current A entirely, and the walk must
  keep going from there to find that C's origin is unevidenced — it must not stop just because the
  qualification id "A" has been seen before. RC5 replaces the id-tracking walk with one that
  advances by the *position* of the audit entry in the Person's fully ordered event list: at each
  step, the search for "the event that accounts for qualification Q becoming Primary" looks only at
  positions strictly earlier than the current step's own position, and moves to whichever matching
  position it finds — strictly decreasing every step, so the walk is bounded by the number of events
  and provably terminates without needing to detect or special-case a repeated qualification id at
  all. `A → B → A` transitions are real, are all kept in `events`, and the walk continues past a
  repeat exactly as it would past any other step (§S48.10).
* **D42 (RC5, new) — the rollback gate checks for any real write, not only a correction.** RC4's
  gate, `NOT EXISTS (... WHERE version_number > 1)`, only detects a *correction* having happened. It
  misses a real loss: a Person's qualification recorded through the live system *after* this
  migration goes live, with a genuine `obtained_on` value and a genuine `created_by_principal_id`
  (via ordinary `RecordPersonQualification`, version 1, no correction involved at all) — data the
  pre-S48 schema has no column to hold at all. Restoring the old columns from version-1 rows and
  dropping the versions table, as RC3/RC4's rollback path described, would destroy that
  `obtained_on`/actor information silently, with zero corrections ever having occurred. The correct
  gate checks for any row ever created by a real, live-system actor, not only a corrected one: `NOT
  EXISTS (SELECT 1 FROM hr.person_qualification_versions WHERE created_by_principal_id IS NOT NULL)`
  — it strictly subsumes the old `version_number > 1` check, because every correction, by D26,
  always records a real `created_by_principal_id` too (§S48.18). **The precision this condition
  actually has is corrected in round 6 — see D44.**

**Added in round 6 (this round) — design accepted; two further documentation corrections, nothing
else reopened:**

* **D43 — `provenance` answers only "is this version's recording actor evidenced," never "was this
  version created before or after this migration."** §S48.12's correction-in-RC4 (D36) is withdrawn
  in part: it described `RECORDED` as applying to "every version created by
  `RecordPersonQualification` or `CorrectPersonQualification` from this point on," which reads as
  though `RECORDED` were a marker for post-migration writes specifically. It is not. The backfill
  resolves `created_by_principal_id` from a pre-existing audit entry whenever one matches
  (§S48.12); a backfilled row with a resolved actor is `RECORDED`, indistinguishable by this field
  alone from a version created through the live command path after go-live. `provenance` is not,
  and must not be used as, a timing signal — only as an answer to whether an actor is known.
* **D44 — The rollback gate (D42) is a deliberately conservative policy, not a precise detector of
  "the first real S48 write."** D42's own text claimed the gate's condition "is true only when
  every row in the table is still exactly what the backfill wrote and nothing else" — stated as
  though the condition precisely distinguishes the backfill's own output from a later, genuinely
  new write. It does not: because the backfill itself can populate `created_by_principal_id IS NOT
  NULL` for any row whose original recording had a matching audit entry (§S48.12, D43), the gate can
  — and, for a Person base with good audit coverage, likely will — already block a destructive
  rollback **immediately after the backfill runs, before any new S48 write has occurred at all.**
  This is accepted here explicitly, as a matter of policy, not as an oversight to fix: a gate that
  sometimes blocks rollback earlier than strictly necessary protects real data at the cost of some
  rollback flexibility, which is the correct side to err on; a gate that ever permitted a rollback
  to destroy a genuine actor/`obtained_on` value would not be. **The condition itself (D42) is
  unchanged** — `NOT EXISTS (SELECT 1 FROM hr.person_qualification_versions WHERE
  created_by_principal_id IS NOT NULL)` stays exactly as specified; only the claim about what it
  precisely detects is withdrawn (§S48.18).

## §S48.3 Data model

**`hr.person_qualifications` (existing table, altered).** Keeps `id`, `person_id`, `is_primary`,
`created_at` exactly as today. Every existing `id`, and every existing
`audit.audit_entries.target_id` referencing one, stays valid. Drops `academic_degree_id`,
`qualification_type_id`, and `person_qualifications_identity_present_check`/
`person_qualifications_identity_unique` (both move to the versions table, §S48.8) — only once every
consumer in §S48.13 is repointed. **New in RC4 (D33): `id` and `person_id` on this table become
immutable once a row is created** — enforced by a trigger, §S48.8 — closing a gap the versions-table
person-match trigger alone could not close.

**`hr.person_qualification_versions` (new table, append-only in effect — the only permitted
`UPDATE` is the single `is_current` flip, and `DELETE` is never permitted, D24):**

| Column | Type | Notes |
|---|---|---|
| `id` | uuid, PK | |
| `person_qualification_id` | uuid, FK → `hr.person_qualifications.id`, `RESTRICT` | the stable identity this is a version of |
| `person_id` | uuid, FK → `hr.persons.id`, `RESTRICT` | denormalized for the current-identity uniqueness index (§S48.8); kept in agreement with the owning qualification's `person_id` by a trigger covering `INSERT` and `UPDATE` (D24) |
| `version_number` | integer, NOT NULL | 1 for the original recording, incrementing per correction; `CHECK (version_number >= 1)`; `UNIQUE (person_qualification_id, version_number)` |
| `academic_degree_id` | uuid, nullable, FK → `ref.academic_degrees.id` | |
| `qualification_type_id` | uuid, nullable, FK → `ref.qualification_types.id` | |
| `obtained_on` | date, nullable | D01/D26; wire format `YYYY-MM-DD` or `null` — never the literal `"unknown"` (§S48.7) |
| `is_current` | boolean, NOT NULL DEFAULT true | exactly one `true` per `person_qualification_id` at `COMMIT`, via an **immediate** partial unique index ("at most one") and a **deferred** constraint trigger ("at least one") — simplified in RC4, D32 (§S48.8) |
| `reason` | text, nullable | `NULL` for `version_number = 1`; `NOT NULL`, trimmed, 1–2000 characters for every correction (D26) |
| `created_by_principal_id` | uuid, nullable, FK → `security.principals.id`, `RESTRICT` | `NULL` only for the migration-time backfill (§S48.12); surfaced in the API as `provenance` (D36), never as a primary-history gap code |
| `created_at` | timestamptz, NOT NULL | |

**Additional table-level constraints on `hr.person_qualification_versions` (unchanged since RC3):**
`UNIQUE (person_qualification_id, version_number)`; `CHECK (version_number >= 1)`;
`CHECK (academic_degree_id IS NOT NULL OR qualification_type_id IS NOT NULL)`.

**`hr.person_qualifications_current` (new view, unchanged since revision 2):**

```sql
CREATE VIEW hr.person_qualifications_current AS
SELECT pq.id, pq.person_id, pq.is_primary, pq.created_at,
       v.academic_degree_id, v.qualification_type_id, v.obtained_on, v.version_number
FROM hr.person_qualifications pq
JOIN hr.person_qualification_versions v
  ON v.person_qualification_id = pq.id AND v.is_current;
```

## §S48.4 Recording behavior

`RecordPersonQualification` is unchanged in its validation, Person-row lock, duplicate check, and
the automatic-first-Primary assignment (R1-D49). It never locks an existing qualification row, so it
is already consistent with the unified lock order without any change to its own logic. In the same
transaction as today's insert, it now also inserts the qualification's `version_number = 1` row
(`reason = NULL`, `created_by_principal_id` from the acting principal, `obtained_on` from the new
optional input). `PersonQualificationResource` reads from `hr.person_qualifications_current`,
gaining `obtained_on`, `created_at`, `version_number`, and `provenance` (D36). **New in RC4 (D32):**
if a future change to this command ever failed to insert the version row, the transaction would now
be rejected at `COMMIT` by the parent-table deferred trigger — a qualification can never exist with
zero versions, as a database-level backstop independent of this command's own correctness.

## §S48.5 Correction — mechanism, concurrency, contract, and no-op rejection

A new command, `CorrectPersonQualification(person, qualification, expected_version,
academic_degree_id, qualification_type_id, obtained_on, reason)`:

1. **Lock the Person row, then the qualification's own row** (`lockForUpdate` on both, in that
   order — D25; never the reverse). This serializes `CorrectPersonQualification` against
   `RecordPersonQualification`/`DesignateQualificationAsPrimary` **for the same Person only.** It is
   not a cross-Person mechanism and was never meant to be one — duplicate prevention itself is
   scoped within a Person (D14/D40), so there is nothing across Persons for any lock to protect.
2. Re-read the current version (`is_current = true`) inside the lock.
3. **Staleness check (D16).** Compare `expected_version` (a positive integer, D26) to the current
   version's `version_number`. A mismatch is rejected with `StaleQualificationVersionException`
   (409) — nothing is written, never retried automatically.
4. **No-op rejection (D11/D16).** Identical proposed `(academic_degree_id, qualification_type_id,
   obtained_on)` against the current version's, `NULL`-safely, is rejected with
   `NoOpQualificationCorrectionException` (422) — nothing is written.
5. Re-validate exactly as `RecordPersonQualification` does today, plus the D26 contract: all three
   value fields are required-but-nullable keys; `reason` is trimmed, 1–2000 characters; `obtained_on`
   is `YYYY-MM-DD` or `null` on the wire, never the string `"unknown"`; a future `obtained_on` is
   rejected the same way an invalid `academic_degree_id` is.
6. Inside the same transaction: `UPDATE` the current version's row to `is_current = false`, then
   `INSERT` the new version (`version_number = previous + 1`, `is_current = true`). Between these two
   statements the qualification briefly has *zero* current versions. **Revised in RC4 (D32):** the
   "at most one current" guarantee is now an ordinary, immediate partial unique index — it was never
   at risk here, because this sequence never produces *two* current rows at once, only a momentary
   zero. The zero-current instant is exactly what the "at least one" deferred constraint trigger
   (§S48.8) is built to tolerate until `COMMIT`.
7. **The `INSERT` itself — not a prior application check — is what actually prevents a duplicate
   under concurrency, and remains the enforcement even when the Person lock has already serialized
   the attempts (D17, D40).** Duplicate prevention is, and has always been, scoped *within* one
   Person — the current-identity index's own column list includes `person_id` (§S48.8), so two
   *different* Persons sharing the same `(academic_degree_id, qualification_type_id)` combination is
   not a duplicate at all and both succeed, concurrently or not. **The real race this step guards
   against is two *different qualifications of the same Person* corrected to the same combination.**
   Because both such corrections lock the same Person row first (step 1), they do not truly
   interleave — the second waits for the first's transaction to finish — but the lock only enforces
   *ordering*; it is still the `INSERT`'s own constraint check, not the lock, that rejects the second,
   now-redundant attempt once it is admitted, with that entire transaction rolled back. **Required
   test (D40):** two different qualifications *of the same Person* corrected concurrently to the same
   combination — one succeeds, the other is rejected with `DuplicatePersonQualificationException`
   and its transaction fully rolled back. **Required check, not a race (D40):** two different
   qualifications belonging to two *different* Persons, corrected to the identical combination
   (concurrently or not) — both succeed; this is not a conflict to resolve, only a confirmation that
   the index's own scoping is what this document has always specified.
8. The command never writes to `hr.person_qualifications.is_primary` (D12) and never inserts a new
   `hr.person_qualifications` row.
9. Audited (`hr.person_qualification.correction.record`), `targetType = 'hr_person_qualification'`
   (D38 — the literal snake_case value, confirmed against source), inside the same transaction as
   steps 6–8.

**Required test (D25):** concurrent recording and correction for the *same* Person must serialize
cleanly on the Person-row lock both now take first. **Required test (D39, unaffected by this
round's corrections):** `designate-primary` and `correct` against the same qualification, for the
same Person, issued concurrently, must be demonstrated directly — not inferred from the other two
tests, which do not exercise this pairing.

## §S48.6 Primary Qualification and corrections (D12)

`is_primary` lives only on `hr.person_qualifications`, addressed by the qualification's stable
`id`. `CorrectPersonQualification` never reads or writes it. Correcting the degree, type, or
obtained-on date of the qualification that currently holds `is_primary = true`:

* Leaves `is_primary = true` on that same stable identity, unchanged.
* Never moves `is_primary` to a different qualification — only `DesignateQualificationAsPrimary`
  can, exactly as R1-D49 already established (§S48.0), untouched by this document.
* Does change what that Primary qualification's degree/type *display as*, and can legitimately move
  the Person between the report buckets counted in §S48.13's consumers, including S41 and S45 —
  this is the intended effect of a correction, proven by D29 (§S48.16).
* Is visible in the archive as a version-history event on that qualification, never as a
  Primary-designation event (§S48.10 keeps the two kinds of history in separate response shapes).

## §S48.7 `obtained_on` — correction and reversion to "unknown" (D01, D04, D26)

`obtained_on` is a versioned field (§S48.3), corrected through the same `CorrectPersonQualification`
command as the degree/type (§S48.5) — there is no separate date-only correction path. Reverting it
to "unknown" is the ordinary case where the proposed value is `null` on the wire: a real change
whenever the current version is not already `NULL` (rejected as a no-op only when it already is),
recorded as an ordinary new version with its own mandatory reason, actor, and timestamp. A future
date is rejected the same way an invalid `academic_degree_id` is (§S48.5 step 5). Every response
that includes this field renders `null` as the display label "unknown" ("غير معروف") on the
presentation layer only — the wire value itself is `YYYY-MM-DD` or `null`, never the string
`"unknown"`, never a blank field, and never substituted with `created_at` or the correction event's
own timestamp.

## §S48.8 Database-enforced guarantees (D17, revised in RC4 by D32/D33)

* **Exactly one current version per qualification, guaranteed at `COMMIT` — simplified in RC4
  (D32).** PostgreSQL has no declarative way to express "at least one row must exist"; it has a
  perfectly ordinary way to express "no more than one," and that half never needed deferring here,
  because §S48.5 step 6 never creates two current rows at once — only a momentary zero.

  *At most one current, ordinary and immediate (reverted from RC3's deferred `EXCLUDE`, which this
  design never needed):*
  ```sql
  CREATE UNIQUE INDEX person_qualification_versions_one_current ON
  hr.person_qualification_versions (person_qualification_id) WHERE is_current;
  ```

  *At least one current, deferred constraint trigger — the one half with no declarative equivalent
  — now covering BOTH tables (D32):*
  ```sql
  CREATE FUNCTION hr.check_qualification_has_current_version() RETURNS trigger AS $$
  DECLARE
      affected_id uuid;
  BEGIN
      affected_id := COALESCE(NEW.person_qualification_id, OLD.person_qualification_id);
      IF NOT EXISTS (
          SELECT 1 FROM hr.person_qualification_versions
          WHERE person_qualification_id = affected_id AND is_current
      ) THEN
          RAISE EXCEPTION 'qualification % has no current version at commit', affected_id
              USING ERRCODE = 'MA005';
      END IF;
      RETURN NULL;
  END;
  $$ LANGUAGE plpgsql;

  CREATE CONSTRAINT TRIGGER person_qualification_versions_at_least_one_current
      AFTER INSERT OR UPDATE OR DELETE ON hr.person_qualification_versions
      DEFERRABLE INITIALLY DEFERRED
      FOR EACH ROW EXECUTE FUNCTION hr.check_qualification_has_current_version();

  -- D32, new in RC4: the SAME guarantee must also reject a qualification created on the PARENT
  -- table that never gains a version row at all — the trigger above only fires from writes to the
  -- versions table, so a parent-table INSERT with no matching version insert would otherwise pass
  -- silently.
  CREATE FUNCTION hr.check_new_qualification_has_current_version() RETURNS trigger AS $$
  BEGIN
      IF NOT EXISTS (
          SELECT 1 FROM hr.person_qualification_versions
          WHERE person_qualification_id = NEW.id AND is_current
      ) THEN
          RAISE EXCEPTION 'qualification % has no current version at commit', NEW.id
              USING ERRCODE = 'MA005';
      END IF;
      RETURN NULL;
  END;
  $$ LANGUAGE plpgsql;

  CREATE CONSTRAINT TRIGGER person_qualifications_has_current_version
      AFTER INSERT ON hr.person_qualifications
      DEFERRABLE INITIALLY DEFERRED
      FOR EACH ROW EXECUTE FUNCTION hr.check_new_qualification_has_current_version();
  ```
  Both deferred triggers fire only at `COMMIT` — still before the HTTP response is sent, since
  `AuditedCommandExecutor::run`'s `DB::transaction()` commits before the command returns.
  **Required test (D32):** a transaction that inserts a `hr.person_qualifications` row and commits
  *without* ever inserting a matching version row must be rejected at `COMMIT` with `MA005` —
  exercising the parent-table trigger specifically, distinct from RC3's versions-table-only test.
* **Current-identity duplicate prevention, `NULL`-safe (D14, D23)** — schema-level, immediate,
  unchanged since RC3:
  ```sql
  CREATE UNIQUE INDEX person_qualification_versions_current_identity_unique ON
  hr.person_qualification_versions (person_id, academic_degree_id, qualification_type_id)
  NULLS NOT DISTINCT WHERE is_current;
  ```
* **A version's `person_id` matches its qualification's owner, on `INSERT` and `UPDATE` (D24)** —
  unchanged since RC3 (trigger, `MA002`).
* **Historical versions are immutable except the single `is_current: true → false` transition, and
  can never be deleted (D24)** — unchanged since RC3 (trigger, `MA003`/`MA004`).
* **New in RC4 (D33): the parent qualification row's `id` and `person_id` are immutable once
  created.** The versions-table person-match trigger only protects a *version* from disagreeing
  with its parent; nothing before this stopped the *parent* row's own `person_id` from being changed
  directly, which would silently re-point every existing version of that qualification to a
  different Person without any of those version rows ever being touched.
  ```sql
  CREATE FUNCTION hr.enforce_person_qualification_identity_immutable() RETURNS trigger AS $$
  BEGIN
      IF NEW.id IS DISTINCT FROM OLD.id OR NEW.person_id IS DISTINCT FROM OLD.person_id THEN
          RAISE EXCEPTION 'hr.person_qualifications.id and person_id are immutable once created'
              USING ERRCODE = 'MA006';
      END IF;
      RETURN NEW;
  END;
  $$ LANGUAGE plpgsql;

  CREATE TRIGGER person_qualifications_identity_immutable
      BEFORE UPDATE ON hr.person_qualifications
      FOR EACH ROW EXECUTE FUNCTION hr.enforce_person_qualification_identity_immutable();
  ```
  This does not restrict `is_primary` (still mutated by `DesignateQualificationAsPrimary`) or
  `created_at`; RC4 was asked only to close the identity/ownership gap, not to reopen what fields on
  the parent row may change.
* **SQLSTATEs in this feature, for reference:** `MA002` (version↔parent person-match), `MA003`
  (version immutability — disallowed `UPDATE`), `MA004` (version immutability — any `DELETE`),
  `MA005` (deferred at-least-one-current-version violation, on either table), `MA006` (parent
  identity/ownership immutability, new in RC4). `MA001` (audit immutability) is pre-existing and
  unrelated to this feature.
* **The current-version transition and its audit entry commit together** — a transactional
  guarantee (D17), not a schema constraint, unchanged since revision 2.

## §S48.9 Lock ordering — unified across Record, Correct, and Designate-primary (D25, D40)

* `RecordPersonQualification` — locks the Person row, then inserts new rows. No existing
  qualification row to lock.
* `DesignateQualificationAsPrimary` — locks the Person row, then `UPDATE`s up to two existing
  qualification rows' `is_primary` (unchanged from R1).
* `CorrectPersonQualification` — locks the Person row first, then the target qualification row
  (§S48.5 step 1) — the one ordering change from revision 2, kept from RC3.

**What this ordering actually proves, stated precisely (D40, correcting RC4's D39).** The
Person-row lock serializes the three commands *against each other only when they target the same
Person* — it is a same-Person concurrency guarantee, nothing more, and it was never meant to be
anything else. **It does not need to say anything about two different Persons, because duplicate
prevention itself is scoped within one Person (D14) — two different Persons are never in conflict
under this constraint in the first place.** RC4 stated the opposite — that the uniqueness index was
"the final protection across Persons" and that a cross-Person collision should be rejected — which
directly contradicted the index this same document defines in §S48.8 (`person_id` is one of its
columns). That claim is withdrawn, not merely softened. The real concurrency concern this ordering
and the uniqueness index jointly address is two *different qualifications of the same Person* racing
to the same value (§S48.5 step 7) — the Person lock orders the attempt, the index's `INSERT` check
enforces the outcome. This document does not claim a comprehensive or exhaustive proof that no
deadlock can occur under this ordering; "coarser resource first, consistently" is a conventional
discipline, validated by required tests, not a hand-written proof.

**Required tests (D25/D39/D40):**

1. **Two different qualifications of the *same* Person, corrected concurrently to the same
   combination.** One succeeds; the other is rejected with `DuplicatePersonQualificationException`
   (409) and its transaction fully rolled back — the Person lock orders the two attempts, but it is
   the current-identity index's own constraint that rejects the second one (§S48.5 step 7).
2. **Two different qualifications belonging to two *different* Persons, with the identical
   combination** (concurrently or not). **Both succeed.** This is a confirmation that the
   current-identity index is scoped within a Person as specified, not a race with a winner and a
   loser — RC4's framing of this as a conflict to resolve is exactly what D40 withdraws.
3. **Concurrent recording and correction for the same Person.** Both commands now lock the Person
   row first; they must serialize cleanly, with neither reporting a deadlock.
4. **Concurrent `designate-primary` and `correct` against the same qualification, for the same
   Person — tested directly, not inferred from tests 1, 2, or 3 above.** RC3's acceptance matrix
   claimed this pairing was already demonstrated; no test anywhere in RC3 or RC4 actually exercised
   it. This test (D39, unaffected by this round's corrections) closes that gap.

## §S48.10 Primary-designation history — read model (D28, revised by D35, corrected by D41)

Read from `audit.audit_entries` where `action IN ('hr.person_qualification.record',
'hr.person_qualification.designate_primary')` and `target_type = 'hr_person_qualification'` (D38 —
the verified literal), scoped to the Person's qualification ids.

**Deterministic ordering (D35, qualified by D41).** Every underlying audit entry is ordered by
`occurred_at` ascending, then by the entry's own `id` as a stable, deterministic tie-breaker for
equal timestamps. **Corrected in RC5:** `id` is a UUIDv7, which carries a timestamp component, but
that is not itself a proof that two entries sharing the same `occurred_at` were actually executed in
`id` order — a UUIDv7's sub-millisecond/random bits are a tie-breaker, not a causality guarantee.
`id` is used here only because it must be *some* deterministic, repeatable order for a tie, not
because it is asserted to reconstruct true execution order. Each event's position in this ordered
list — not the qualification id it refers to — is what the chain walk below advances by. This
ordering is applied once, before either the `events` list or the `evidence_completeness` object is
built from it.

**`events` — only real events, each with a real actor and timestamp:**

1. **`DESIGNATED`** — from a `designate_primary` entry with `metadata.state_changed = true`:
   `previous_primary_qualification_id`, `new_primary_qualification_id` (both read from the entry's
   `changes`, exactly as that `AuditSpec` writes them), `actor_principal_id`, `occurred_at`. **D15,
   unchanged:** `metadata.state_changed = false` (idempotent re-designation) is never a transition.
2. **`AUTO_FIRST`** — from a `record` entry carrying `changes.is_primary = true` in that same event.
   **Corrected in RC4 (D35):** the "new" qualification for this event is the entry's own `target_id`
   (confirmed by direct source reading: `PersonQualificationController::store()`'s `AuditSpec`
   `targetId` closure returns the qualification's key) — a `record` entry's `changes` payload has no
   `previous` key at all (confirmed the same way: `changes` is exactly `{person_id,
   academic_degree_id, qualification_type_id, is_primary}`). RC3 described this as `"previous =
   null"` as though a `previous` field existed and read `null`; it does not exist on this event type
   at all, which is the correct way to say "a record event can never represent a transition from an
   existing Primary" — there being no such field, not the field being present and empty.

A correction event never appears in `events` (unchanged).

**Chain walk — advances by event position, never by qualification identity (D41, correcting RC4's
D35 cycle-stop).** RC4's walk tracked *visited qualification ids* and stopped on a repeat, reasoning
this safely handled a qualification that lost and later regained Primary status (`A → B → A`). That
is wrong: stopping on a repeated id can hide a real, earlier gap the repeat has nothing to do with.
Consider `C → A → B → A` — C's own becoming-Primary is unevidenced, then `DESIGNATED(C→A)`, then
`DESIGNATED(A→B)`, then `DESIGNATED(B→A)`. Starting from the current holder (A, via the last event),
walking back to find what accounts for B leads to `DESIGNATED(A→B)`; walking back from *that* event
to find what accounts for *its own* predecessor, A, must **not** stop just because "A" was already
the id we started from — it is a different point in the Person's history (the earlier occupancy of
A, before B took over), and the walk must continue to find `DESIGNATED(C→A)`, and then continue once
more to find that C's own origin is unevidenced. **The correct rule:** the walk advances by the
*position* of the audit entry in the Person's fully ordered event list (§S48.10's ordering above),
never by the qualification id a step refers to. At each step, "what accounts for qualification Q
becoming Primary" is answered by searching strictly earlier positions in the ordered list for the
latest `DESIGNATED`/`AUTO_FIRST` event whose "new" qualification is Q, and the walk continues from
that found position — strictly decreasing every step, so it is bounded by the number of events and
terminates without ever needing to detect or special-case a repeated qualification id. `A → B → A`
transitions are real, are all kept in `events` exactly as recorded, and the walk passes through a
repeated id exactly as it would pass through any other step — never stopping because of the repeat
itself.

**`evidence_completeness` — a distinct object, computed over the whole chain, identical across every
page of `events` (D35, walk corrected by D41):**

* **`GAP_NO_DESIGNATION_EVIDENCE`** — a qualification is, or was, Primary, but neither a `DESIGNATED`
  nor an `AUTO_FIRST` event accounts for how it became Primary. **This code names only this finding**
  — it is never reused for the unrelated question of whether a qualification's own *recording* has a
  known actor, which RC3 mistakenly described this way in §S48.12; that question now has its own
  name, `provenance` (D36, §S48.12/§S48.14).
* **`GAP_CHAIN_BROKEN`** — the position-based walk above, searching for what accounts for an earlier
  link in the lineage, finds no matching event at any strictly earlier position — a gap earlier in
  the history, distinct from the current holder's own origin. **Required test (D41):** `C → A → B →
  A` with C's own origin unevidenced must report `GAP_CHAIN_BROKEN` for C, specifically proving the
  gap is found *despite* A repeating later in the same walk.

Because this object is built from the *entire* ordered event list before any page boundary is
applied to `events`, requesting page 1 versus page 2 of `events` never changes
`evidence_completeness` — it is computed once per request, from the whole history, and attached
identically regardless of which page of events accompanies it.

## §S48.11 Why Primary-history stays sourced from audit, while qualification values are versioned

Two different questions, answered by two different mechanisms — not an inconsistency:

* "When did Primary move, and to what" is answered by `audit.audit_entries`: every future
  `designate_primary`/`record` event is already written there atomically and immutably, and reusing
  it touches neither already-frozen write path. The `evidence_completeness` object (§S48.10) is
  built from the same table — it is a different *shape* of answer, not a different source.
* "What are this qualification's correct current values, and what did it say before" cannot be
  answered by an audit log alone, because D14 requires those values to participate in a live
  uniqueness constraint — an append-only log nothing else queries cannot back a constraint; only a
  real, joinable table with its own index and triggers can (§S48.8). `hr.person_qualification_versions`
  is the data model D10/D14 require, independent of the audit-reuse choice above.

The version `provenance` field introduced in D36 is a related but distinct concept from both
questions above — it answers "was this *version* backfilled," never "is this Person's
Primary-designation history fully evidenced," which is what §S48.10's `evidence_completeness`
answers.

## §S48.12 Backfill — no fabrication, in either direction, and no unverified application claim (D19, D30, D36, D43)

* **The existing R1-D42 `is_primary` backfill**, defined in migration
  `2026_10_18_000002_add_is_primary_to_hr_person_qualifications_table.php`. This document states
  only that the migration file exists at the stated baseline — not that it has been applied to any
  real database; that status is unverified (D19/D30, unchanged substance since RC3). If and when it
  has run, every qualification it accounts for, with no matching `designate_primary`/`record`
  (`is_primary = true`) event, is reported by §S48.10 as `GAP_NO_DESIGNATION_EVIDENCE` — this is the
  correct, and only, use of that code name.
* **This revision's own migration-time version-1 backfill** (§S48.3): for every existing
  `hr.person_qualifications` row, one `version_number = 1` row is inserted with the row's own
  `academic_degree_id`/`qualification_type_id`/`created_at` and `obtained_on = NULL`.
  `created_by_principal_id` is resolved by matching the parent's `id` against
  `audit.audit_entries` (`action = 'hr.person_qualification.record'`, `target_type =
  'hr_person_qualification'`, `target_id = <that id>`) and taking its `actor_principal_id` — never
  the identity or time of whatever ran the migration. A row with no matching audit entry gets
  `created_by_principal_id = NULL`. **This means a backfilled row can legitimately end up with
  `created_by_principal_id IS NOT NULL`** — whenever the original recording happened to have a
  matching audit entry, the backfill resolves and carries forward that real, pre-existing actor; it
  is still a backfilled row, just one whose actor is known rather than unknown.
  **Corrected in RC4 (D36): this is never reported as `GAP_NO_DESIGNATION_EVIDENCE`.** That code
  belongs exclusively to §S48.10's Primary-*designation* evidence question. Whether a *version's
  recording* has a known actor is a separate, explicitly named concept: `provenance`, derived purely
  from `created_by_principal_id` — **corrected in RC6 to state precisely what this field does and
  does not answer (D43):**
  * `RECORDED` — `created_by_principal_id IS NOT NULL`: the version's recording actor is known and
    evidenced, full stop. **This does not mean the version was created after S48 went live, and
    `provenance` alone must never be used to infer when or by what path a version was created** —
    a backfilled version-1 row whose original `record` audit entry was found (above) is `RECORDED`
    too, exactly like a version created through the live command path after go-live. The two cases
    are indistinguishable by `provenance` alone, and this document does not claim otherwise.
  * `BACKFILLED_UNKNOWN_ACTOR` — `created_by_principal_id IS NULL`: no matching audit entry was
    found for this row at backfill time, so its actor is genuinely unknown. Still only possible for
    a migration-time version-1 row — `RecordPersonQualification`/`CorrectPersonQualification`
    always set a real `created_by_principal_id` going forward (D11/D26) — but the converse does not
    hold: not every row with a known actor is a post-migration write.
  Neither backfill creates a `reason`-bearing correction version or a fabricated
  `designate_primary` entry — both would be inventing an event, not recording one. This backfill's
  own application status is addressed procedurally by §S48.18, not asserted as already done.

## §S48.13 Consumer impact — verified table, now with real paths and a seventh entry (D13, D20, D31, D38)

Verified directly in the backend source. Every read or write touching
`hr.person_qualifications.academic_degree_id`/`.qualification_type_id` found by this discovery, with
real repository-relative paths (D38 — the `app/...` placeholder used in RC2/RC3 is replaced
throughout):

| File | Kind | Change required |
|---|---|---|
| `backend/app/Modules/HumanResources/Application/Commands/RecordPersonQualification.php` | Write | Also insert the `version_number = 1` row (§S48.4); stop writing `academic_degree_id`/`qualification_type_id` onto the parent row. |
| `backend/app/Modules/HumanResources/Application/Commands/CorrectPersonQualification.php` (new) | Write | The only other writer of these two values, ever, after this ships (§S48.5). |
| `backend/app/Modules/HumanResources/Presentation/Http/Controllers/PersonQualificationController.php::store()` **(new in RC4, D38)** | Write (audit) | Its `AuditSpec` `changes` closure reads `$qualification->academic_degree_id`/`qualification_type_id` directly off the Eloquent model (verified, §S48.0) — both attributes stop existing on the model once the columns move, so this closure must be repointed to the new version row created in the same request, not left as-is. `targetType` stays the literal `hr_person_qualification` (D38); this value is unaffected by the column move and needs no change itself. |
| `backend/app/Modules/HumanResources/Presentation/Http/Resources/PersonQualificationResource.php` | Read | Read `academic_degree_id`/`qualification_type_id`/`obtained_on`/`created_at`/`version_number`/`provenance` (D36) from `hr.person_qualifications_current` plus the version row, instead of the model's own now-dropped columns. |
| `backend/app/Modules/HumanResources/Application/Queries/ListPersonQualifications.php` | Read | Query `hr.person_qualifications_current` (or an Eloquent scope backed by it) instead of `hr.person_qualifications` directly. |
| `backend/app/Modules/HumanResources/Application/Queries/Reporting/BuildHumanCadreResult.php` (S41) | Read | Its raw SQL joining `ref.academic_degrees`/`ref.qualification_types` off `pq.academic_degree_id`/`pq.qualification_type_id` must read from `hr.person_qualifications_current` instead. `HumanCadrePersonRecord`'s shape and `DQ_PRIMARY_QUALIFICATION_REQUIRED` are unchanged — only the join target. |
| `backend/app/Modules/HumanResources/Application/Queries/Reporting/BuildWorkforceAnalyticsResult.php` (S45) | Read | The identical raw-SQL pattern, feeding `WorkforceAnalyticsSections.php`'s `qualifications.primary_qualification` section. Same fix, same scope. |

**Confirmed, by the same read this round, as touching neither column and needing no change:**
`PersonQualificationController::designatePrimary()`'s own `AuditSpec` (`changes` is exactly
`{person_id, previous_primary_qualification_id, new_primary_qualification_id}` — no degree/type
field at all).

**Not found touching these two columns, by the same reads** (bounded by what this discovery
actually grepped): `HumanCadreResult`, `HumanCadreController`, `HumanCadreResource`,
`WorkforceAnalyticsResult.php`, `WorkforceAnalyticsResource.php`, and no S45 dashboard frontend
contract.

**Expanded, category-based tail (D31) — named by category, not individually file-verified the way
the eight above were:** the Eloquent model and its relationships; test fixtures/factories; the
existing test suite's assertions against the now-dropped columns and the old constraint set.

**Fate of the original columns (D20).** Dropped in the same migration step that repoints every row
above — never left in place "for now" alongside the new table. §S48.18 sequences this against the
code deploy.

## §S48.14 API surface (contract frozen by D26; pagination frozen by D37; literals verified by D38)

* **`GET /persons/{person}/qualifications/{personQualification}/versions`** — every version, oldest
  first, ordered by `version_number` ascending (a stable tie-breaker by construction). **Query
  parameters, validated (D37):** `page` (`nullable|integer|min:1`, default 1 — this document's own
  explicit decision, stricter than the two existing endpoints this pattern is otherwise modeled on,
  which leave `page` unvalidated); `per_page` (`nullable|integer|min:1|max:100`, default 25 — the
  exact existing rule verified in `EmploymentStatusExpiryFollowUpController`/
  `MovementExpiryFollowUpController`). An invalid `page` or `per_page` is rejected with 422, in
  Laravel's standard validation-error shape: `{"message": "...", "errors": {"<field>": ["..."]}}`.
  **Success (200)** — the existing project's standard paginated `JsonResource::collection($paginator)
  ->response()` envelope (verified against `AcademicDegreeController`/the two existing
  `per_page`-validated controllers): `{"data": [{"version_number": 1, "academic_degree_id": "...",
  "qualification_type_id": null, "obtained_on": "2020-01-15"|null, "reason": null,
  "is_current": false, "provenance": "RECORDED"|"BACKFILLED_UNKNOWN_ACTOR" (D36),
  "created_by_principal_id": "..."|null, "created_at": "..."}], "links": {...}, "meta": {...}}` —
  the same `links`/`meta` shape Laravel's own paginator produces, not an invented alternative.
  **Ownership binding:** `personQualification` not belonging to `person` is 404 (`NotFoundHttpException`,
  the same message style already used by `designatePrimary()`: `'Qualification not found for this
  person.'`), never 403. Permission: `hr.person_qualifications.view` (D06).
* **`POST /persons/{person}/qualifications/{personQualification}/corrections`** — records one
  correction (§S48.5). **Request body (frozen, D26):** `expected_version` (positive integer,
  required), `academic_degree_id` (uuid or `null`, required key), `qualification_type_id` (uuid or
  `null`, required key), `obtained_on` (`"YYYY-MM-DD"` or `null`, required key), `reason` (trimmed
  string, 1–2000 characters, required). **Success (201):** the new version, same item shape as the
  versions list above. **Errors:** 404 (ownership mismatch, same message/mechanism as above); 409
  — two distinct causes, both using the project's existing simple-conflict shape
  `{"message": "..."}` (verified against `DuplicatePersonQualificationException`'s own rendering in
  `bootstrap/app.php`): a stale `expected_version` (`StaleQualificationVersionException`, new
  message) or a duplicate current-identity value *within the same Person* (D40 — this is, and has
  always been, scoped to one Person; it is never raised for two different Persons sharing the same
  combination, which is not a duplicate), reusing the existing `DuplicatePersonQualificationException`
  and its exact existing message, `'This qualification is already recorded for the person.'`,
  unchanged; 422 —
  either a no-op (`NoOpQualificationCorrectionException`, new, `{"message": "..."}` with no
  `errors` key, since no single field is individually invalid) or a field-validation failure
  (`InvalidPersonQualificationAcademicDegreeException`-style, `{"message": "...", "errors": {...}}`,
  the existing shape); 403 (missing `hr.person_qualifications.correct`, the existing generic
  permission-denial response, unchanged by this feature). Permission:
  `hr.person_qualifications.correct` (new, independent — D07), seeded granted to no role.
* **`GET /persons/{person}/qualifications/primary-history`** — (§S48.10). **Query parameters:**
  same `page`/`per_page` rules as the versions endpoint (D37), applied to `events` only —
  `evidence_completeness` is never paginated (D35). **Success (200):** `{"events": [{"type":
  "DESIGNATED"|"AUTO_FIRST", "qualification_id": "...", "previous_primary_qualification_id":
  "..."|null, "actor_principal_id": "...", "occurred_at": "..."}], "links": {...}, "meta": {...},
  "evidence_completeness": {"gaps": [{"code": "GAP_NO_DESIGNATION_EVIDENCE"|"GAP_CHAIN_BROKEN",
  "qualification_id": "..."}]}}` — `evidence_completeness` sits alongside the paginated `events`
  envelope, not inside it, and is identical on every page (D35). **Ownership binding:** scoped to
  the given `person`'s own qualification ids; a foreign qualification id is excluded, never
  surfaced as an error. Permission: `hr.person_qualifications.view`.
* **Audit-query scoping (D27), literals corrected (D38).** The queries backing both history
  endpoints filter explicitly by `target_type = 'hr_person_qualification'` (the verified literal —
  not `hr.person_qualification`, which RC3 guessed), `action IN
  ('hr.person_qualification.record', 'hr.person_qualification.designate_primary')`, `outcome =
  'SUCCEEDED'` (the verified literal — not a lowercase `success`), and the Person's own
  qualification ids. `audit.audit_entries` itself still has no general read API anywhere in the
  codebase; these two purpose-built, narrowly-scoped queries are the only access.
* New permission constant: `PERSON_QUALIFICATIONS_CORRECT = 'hr.person_qualifications.correct'`.
* No existing route changes. No employment-relationship requirement anywhere in this surface.

## §S48.15 Out of scope / deferred (D09, unchanged)

Specialization and issuing authority/institution fields; any frontend implementation; a general
audit-log viewer; a second-reviewer/approval step for corrections; bulk or cross-Person history
reporting; any field beyond `obtained_on` and the three versioned ones. No future stage is opened by
this document.

## §S48.16 Acceptance matrix

| Scenario | Required outcome |
|---|---|
| Repeated correction | Correcting the same qualification three times in sequence produces `version_number` 1, 2, 3, 4 in order; exactly one `is_current = true` at every commit; all four rows remain readable through §S48.14's versions endpoint. |
| Concurrency — stale write | A correction submitted with a no-longer-current `expected_version` is rejected with 409 and writes nothing; never retried automatically. |
| **Concurrency — two qualifications, same Person, same combination (D40, corrected in RC5, required test 1)** | Correcting two *different* qualifications **belonging to the same Person** concurrently to the same combination results in exactly one succeeding and the other rejected with `DuplicatePersonQualificationException` (409), its entire transaction rolled back — the Person lock orders the two attempts, but it is the current-identity index's own `INSERT` check that rejects the second. **RC4 wrongly described this scenario as happening across two different Persons; it is, and has always been, within one Person (D14/D40).** |
| **Concurrency — two Persons, same combination (D40, corrected in RC5, required check)** | Two *different* Persons corrected to the identical combination, concurrently or not, both succeed — there is no duplicate under the current-identity index, which is scoped by `person_id` (§S48.8). **This is a non-event, not a race with a winner and a loser; RC4's "required test 1," which expected one rejection, is withdrawn as factually wrong.** |
| Concurrency — record and correct, same Person (D25, required test) | Both now locking the Person row first, they serialize cleanly: one proceeds, the other waits, both complete, neither deadlocks. |
| Concurrency — designate and correct, same Person (D39, required test, unaffected by RC5) | Tested directly: both lock the Person row first and serialize cleanly, with neither reporting a deadlock. Not inferred from the other concurrency tests, none of which exercise this pairing. |
| Concurrency — same-qualification correction race | Two correction requests for the same qualification serialize on the row lock; the second is evaluated against the first's already-committed version. |
| Deferred exactly-one-current, versions-table side | Within a correction's transaction, the qualification briefly has zero current versions between the `UPDATE` and the `INSERT` without error; the transaction still commits with exactly one. |
| Deferred exactly-one-current, parent-table side (D32, required test, new in RC4) | A transaction that inserts a `hr.person_qualifications` row and commits without ever inserting a matching version row is rejected at `COMMIT` with `MA005` — the parent-table trigger, not only the versions-table one. |
| No-op rejection | Submitting a correction whose three proposed values exactly match the current version is rejected with 422 and writes nothing. |
| Person-match guard, versions table, insert and update | An insert or update of a version row whose `person_id` disagrees with its parent's is rejected by the trigger in both cases. |
| **Parent-row identity/ownership immutability (D33, required test, new in RC4)** | Any attempt to `UPDATE` `hr.person_qualifications.id` or `.person_id` on an existing row is rejected with `MA006`, regardless of what the calling code intended. |
| Historical immutability | Any `UPDATE` on a version row other than the single `is_current: true → false` flip is rejected with `MA003`; any `DELETE` is rejected with `MA004`, including on a backfilled version-1 row. |
| Corrected Primary — buckets may shift, population does not (D29) | A correction to the currently-Primary qualification's degree/type may legitimately move the Person between S41/S45 report buckets; `overall_headcount` and the reconciliation total stay exactly as they were. |
| Unknown date | `obtained_on = null` (never recorded, or reverted) renders as "unknown" on the presentation layer only; the wire value is never the string `"unknown"`. |
| Evidence-completeness — current holder's own gap | A Primary qualification whose own designation is unaccounted for is reported with `GAP_NO_DESIGNATION_EVIDENCE`. |
| Evidence-completeness — earlier, no-longer-current gap | A Person whose current Primary is fully evidenced but whose earlier Primary (reached via the chain walk) is not, is reported with `GAP_CHAIN_BROKEN`, distinct from the previous scenario. |
| **Evidence-completeness — `A → B → A` keeps all transitions (D41)** | A Person whose Primary status moved `A → B → A` has every transition kept in `events`; the walk passes through the repeated id `A` without stopping there. |
| **Evidence-completeness — gap behind a repeat is still found (D41, corrected in RC5, required test)** | `C → A → B → A`, with C's own becoming-Primary unevidenced: `evidence_completeness` reports `GAP_CHAIN_BROKEN` for C. **RC4's id-tracking walk would have stopped at the second `A` and missed this gap entirely — this test exists specifically to prove that failure mode is closed.** |
| **Evidence-completeness — stable across pages (D35, required test)** | Requesting page 1 versus page 2 of `events` for the same Person returns an identical `evidence_completeness` object in both responses. |
| `AUTO_FIRST` requires field-level evidence | A `record` event not carrying `changes.is_primary = true` is never classified `AUTO_FIRST`. |
| **Version provenance, distinct from primary-history gaps (D36, required test)** | A backfilled version-1 row (`created_by_principal_id IS NULL`) is reported in the versions list with `provenance = "BACKFILLED_UNKNOWN_ACTOR"` — never with the code `GAP_NO_DESIGNATION_EVIDENCE`, which appears only in the separate primary-history `evidence_completeness` object and never in a versions-list response. |
| **Version provenance does not imply write timing (D43, required test, new in round 6)** | A backfilled version-1 row whose original `record` audit entry *was* found (so `created_by_principal_id IS NOT NULL`) is reported with `provenance = "RECORDED"` — the same value a genuinely new, post-migration write gets — proving `provenance` alone cannot and does not distinguish the two. |
| **Pagination validation (D37, required test, new in RC4)** | `page=0`, `page=-1`, a non-integer `page`, `per_page=0`, and `per_page=101` are each rejected with 422 on both paginated endpoints; `page` and `per_page` omitted default to 1 and 25 respectively. |
| Lock ordering | Demonstrated by the three required tests in §S48.9/above, not asserted as a proof covering every possible interleaving. |
| Ownership binding | Reading another Person's qualification's versions is 404; another Person's primary-history is silently filtered, never a 403 that would confirm existence under the wrong Person. |
| Audit-query scoping, literals verified (D38, new in RC4) | The internal query backing primary-history filters on the verified literals `target_type = 'hr_person_qualification'` and `outcome = 'SUCCEEDED'` — not the guessed `hr.person_qualification`/`success` RC3 used — and can never be coerced into returning a different `target_type`, `action`, or a failed attempt. |
| Permissions | Holding exactly one of `hr.person_qualifications.{view,record,designate_primary,correct}` in isolation authorizes only that one action; each of the other three is rejected with 403. |

## §S48.17 Items still requiring sign-off before an execution authorization

**None, as of RC5, for backend execution.** The pagination defaults/maximums and the
backfilled-row-distinguishability question listed here in RC3 were resolved in RC4 (D37, D36). The
one item still listed as of RC4 — the exact wording of the "unknown" display string — is removed
this round: the API-level value has been settled as `null` since D01/revision 1, and the wording of
a *display* string is a frontend concern, explicitly out of scope for this document (§S48.15); it
was never a condition for backend execution and should not have gated it.

## §S48.18 Migration, deployment, and rollback plan (D30, revised in RC4 by D34, rollback gate corrected in RC5 by D42, precision corrected in round 6 by D44)

Documentation only — no migration file is written by this document.

**Maintenance window — now spans the whole cutover, with no real traffic anywhere inside it
(D34).** RC3's sequencing could be read as reopening traffic before the old columns were dropped;
that reading is explicitly closed here. The window opens once, before step 1, and closes once,
after step 5 — nothing in between serves real user or worker traffic:

1. Halt inbound HTTP requests and background workers for this module.
2. Create `hr.person_qualification_versions`, its constraints and triggers (§S48.3, §S48.8), and
   the `hr.person_qualifications_current` view. Old columns and old code both still exist, but
   nothing is serving traffic to exercise them.
3. Run the version-1 backfill (§S48.12).
4. **Verify, transactionally, before proceeding:** for every existing `hr.person_qualifications`
   row, exactly one `version_number = 1` row exists, and its `academic_degree_id`,
   `qualification_type_id`, and `created_at` exactly match the parent row's own values — a value
   comparison, not a row-count comparison alone. Failure aborts the migration here; the old columns
   are never dropped against unverified data.
5. Deploy the application code that reads/writes `hr.person_qualifications_current` and the
   versions table (§S48.13), and confirm it healthy by **internal checks only** — not by opening
   real user or worker traffic, which stays halted. Then, still inside the same window, drop
   `academic_degree_id`, `qualification_type_id`, and their superseded constraints from
   `hr.person_qualifications`. Only once both the new code and the column drop are in place does the
   window close and traffic resume.

**No real request is ever served against a database that has the new versions table but still has
the old columns, nor against a database that has dropped the old columns while old code might still
be running** — both are excluded by construction, because nothing serves real traffic at any point
between step 1 and the window's close after step 5.

**Rollback / recovery policy — gate corrected in RC5 (D42).** A `down()` migration must never
silently delete real S48 data. **RC4's gate, `NOT EXISTS (SELECT 1 FROM
hr.person_qualification_versions WHERE version_number > 1)`, is insufficient and is withdrawn.** It
only detects a *correction* having happened; it misses an ordinary recording made through the live
system after this migration goes live — plain `RecordPersonQualification`, `version_number = 1`, no
correction at all — that carries a genuine `obtained_on` value and a genuine
`created_by_principal_id`, neither of which the pre-S48 schema has any column to hold. Restoring the
old columns from version-1 rows and dropping the versions table, as RC3/RC4 described, would destroy
that information silently even with zero corrections ever having occurred — exactly the silent loss
the Architecture Authority flagged.

**Corrected gate (D42):** `NOT EXISTS (SELECT 1 FROM hr.person_qualification_versions WHERE
created_by_principal_id IS NOT NULL)`, checked at rollback time. It strictly subsumes the old
`version_number > 1` check, since D26 requires every correction to record a real
`created_by_principal_id` too. **This condition is a deliberately conservative policy, not a
precise detector of "no real S48 write has yet occurred" (D44, correcting an overclaim made when
this gate was introduced):** because the backfill itself can resolve and store a real
`created_by_principal_id` for any row whose original recording has a matching audit entry
(§S48.12), the gate can block rollback immediately after the backfill runs, before any genuinely
new write has happened. That is accepted here as the correct side to err on — blocking a rollback
unnecessarily costs flexibility; letting one proceed when it should not destroys real
`obtained_on`/actor data permanently.

* **While the gate holds** (no row in the table has a non-`NULL` `created_by_principal_id` — true
  immediately after a backfill that found no matching audit entries for any row, and possibly only
  then): the old columns may be restored from the version-1 rows and the versions table dropped —
  this reversal happens inside its own halted-traffic window, by the same discipline as the forward
  migration, so old code is deployed and confirmed healthy internally *before* traffic resumes,
  never running concurrently with a database state it does not expect.
* **Once the gate fails** (any row has a known actor, whether from the backfill resolving one or
  from a genuinely new write): rollback is blocked outright, with no silent partial reversal. The
  recovery plan is forward-only — a new migration, never discarding the versions table.

## §S48.19 Freeze and sync record

This section records the one action this freeze authorizes beyond the document text itself: writing
this specification to `docs/person-qualification-history-foundation-specification.md` on
`C:\Projects\MasarHR`, replacing the DRAFT revision 1 previously there. No other write to that
machine, and no code/migration/test/Git action of any kind, is authorized by this section or by
this document.

**Procedure followed:** (1) the existing on-disk revision 1
(SHA-256 `f3be38166deef0ae0384d86408a09a3b8f6f51c285618e78c0b203e4d2e43cb7`) was saved outside the
project tree before being overwritten, so it remains recoverable independent of Git history; (2)
the baseline (`develop @ 19fcc5cccb819ac5698dd87d007047eef83c4cbb`) was re-confirmed immediately
before writing; (3) this file was written to
`docs/person-qualification-history-foundation-specification.md`; (4) the written file was read back
and hashed, and that hash was compared against this document's own hash computed in the cloud
workspace before the write.

**Result — filled in by the delivery report accompanying this freeze, not asserted here in
advance:** the pre-sync SHA-256, the post-sync SHA-256 read back from the Windows file, and whether
they matched. A mismatch would mean the sync must not be treated as complete and is reported as
such rather than papered over, consistent with this engagement's standing rule never to claim a file
is synced before that is verified.
