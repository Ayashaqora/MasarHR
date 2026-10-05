# Employee 360 operations — closure record (S46)

**Status: FUNCTIONALLY CLOSED / PASS WITH DOCUMENTED LIMITS**, per explicit architecture-authority direction
("MASARHR — S46 FINAL GATE: ACCEPTED WITH DOCUMENTED LIMITS"). **S47 is NOT AUTHORIZED.** No Git
add/commit/push/tag was performed to produce this record or at any point in the review that preceded it.

## 0. Nature of this record

This is a **retrospective closure record**, not a prospective specification. Unlike the other documents in
this `docs/` directory — which were written to define a stage before or during its implementation — no
S46 spec document was authored up front in this history. The functional description, file inventory and
test evidence below were **reconstructed after the fact** from the implemented code, its tests, and git
diffs, across several explicit read-only reconciliation passes directed by the architecture authority. Where
this record states what S46 does, that is a description of the code as found, not a quotation of an original
authorizing text — **the original authorizing text, if any, is not available to this record's author**, and
none is implied here. Where evidence is incomplete or
unverifiable from what is available to this record's author (a cloud session with read/write access to the
repository via a device bridge, and no ability to execute the real Windows toolchain itself), that is stated
explicitly rather than inferred. Facts attributed below to "the architecture authority, as reported" were
communicated in the review conversation and were not independently executed or witnessed by this record's
author; they are recorded as reported, distinctly from what this record verified itself, read-only.

## 1. Scope — what S46 is

Employee 360 (first built in the S33/first-visual-vertical-slice-employee-360 work) gained **write
operations** on an employment relationship, reachable from 4 of its 6 tabs. Confirmed directly from
`frontend/src/pages/Employee360Page.tsx` (which tab renders which operation component), not from the table
alone:

| Tab | Operations |
| --- | --- |
| Employment (التوظيف) | End relationship |
| Workplace (مكان العمل) | — (read-only) |
| Status history (سجل الحالات الوظيفية) | Record status, Record return intention |
| Movement timeline (الخط الزمني للحركات) | Transfer, Start full secondment, End full secondment, Start assignment, End assignment, Record partial secondment |
| Work arrangements (ترتيبات العمل) | Record work schedule |
| Career/qualifications (المسار الوظيفي والمؤهلات) | — (read-only) |

**10 write actions in total** (1 + 2 + 6 + 1), across the 4 tabs listed with operations above.

Functional surface, each tied to its current evidence:

- **Operations**: the 10 write actions above. Evidenced by `frontend/src/features/employees/operations/*`,
  the tab-to-component wiring in `Employee360Page.tsx`, and the per-operation `it(...)` blocks in
  `Employee360Operations.test.tsx` naming each action.
- **Permissions**: every write action is gated by its own backend permission string (e.g.
  `hr.employment_relationships.transfer`, `hr.full_secondment_periods.start`); a caller with read-only
  permissions sees every read but no write affordance. Evidenced by `shared/security/permissions.ts` and the
  "Employee 360 operations — permissions (read never implies write)" test group (12 cases covering each
  action's own permission and the read-only case).
- **Review before submit**: every operation requires an explicit review step before the request is sent;
  the review step re-states the identifying facts (employee, destination/date for Transfer; date and
  terminal/non-terminal choice for End relationship) and offers Back (preserving every entered value) and
  Cancel in addition to the real submit action. Evidenced by the operation components' `inReview` branch,
  and directly re-verified in this engagement's visual-acceptance pass against the live UI for Transfer and
  End relationship at both desktop and 390px width.
- **API payloads**: each operation posts an exact, operation-specific payload (no extra fields); Transfer
  sends `organizational_unit_id` + `effective_from` + `decision_type_id`; End relationship sends
  `expected_version` + `effective_to` + `is_terminal`. Evidenced by the "posts the exact ... payload" tests
  per operation group.
- **Post-success data refresh**: a successful write re-fetches every read stream it can affect (e.g. a
  Transfer re-fetches `/placement-periods`, `/actual-workplace`, `/full-secondment-periods`,
  `/workplace-assignment-periods`, `/partial-secondment-periods`) and announces success; a failed write
  (where tested — see below) re-fetches nothing. Evidenced by the "refetches every affected stream"
  assertions in the success tests, and by the explicit before/after `REFETCHED_STREAMS` count checks in the
  error-integration tests.
- **Errors** — the properties below are each attributed only to the specific operation and error kind whose
  test actually asserts them; none is generalized beyond that. Unless noted, the evidence is from the
  **Transfer** operation's own test group; **409** is evidenced only for **End relationship**. The other 8
  operations are not separately claimed to share these properties beyond what each operation's own tests
  cover (see §1's "Operations" evidence above for what each operation's own suite asserts).
  - **401** — redirects to the sign-in page (`moves to the sign-in page when the session has ended (401)`).
    This is a navigation away from the form, not a form-preserving error state: none of the
    pending/retry/refetch/value-survival properties below are asserted for it, and none is claimed here.
  - **403** — shows the generic permission-or-scope message, never the raw server text (`This action is
    unauthorized.` does not render); exactly one POST is sent; every entered value survives a Back from the
    review step. The submit button's re-enabled state and the refetch-stream count are **not** separately
    asserted in this test.
  - **422** — shows the server's own per-field message (`A later placement already exists.`) without
    rewriting the entered value.
  - **404** — shows the generic "not found" message with no raw server text leaked via either `detail` or a
    per-field `fieldErrors` entry (the fix in §4); submit button returns to enabled; no success text; none
    of the five write-affected read streams are refetched; exactly one POST; every entered value survives
    Back; no `aria-invalid` is left on the field.
  - **409** (End relationship only) — shows the conflict message distinctly and offers an explicit Refresh
    action, which itself deliberately re-reads the relationship (a user-initiated read, not an automatic
    retry of the write); exactly one POST is sent regardless of whether Refresh is used.
  - **network failure** (`fetch` rejects) — shown with its own distinct message; submit button re-enabled;
    no success text; none of the five streams refetched; exactly one POST; value survives Back.
  - **request timeout** — shown with a message distinct from the network-failure one; otherwise the same
    pending/no-retry/no-refetch/value-survival properties as network failure, each separately asserted in
    its own test.
  - A double-submit guard (the submit button disables while pending, so a second click does not send a
    second POST) is evidenced by its own, separate happy-path test, not folded into the error list above.
- **Prevention on an ended relationship**: an ended relationship offers no write operation and states why.
  Evidenced by the "offers no operation on an ended relationship and says so" test.

## 2. Test evidence — reported Windows results (kept separate, not summed)

The following results were reported to this record's author by the architecture authority, from runs
executed on the owner's own Windows machine, across several review cycles in this engagement. This record's
author did not execute these runs and holds no captured terminal log for most of them (the one dated
test-run log artifact present in the repository is a separate, earlier run — see §3). No explicit run time
(clock time) was given alongside any of these results in the review conversation, and none is invented
here. The order below is the order in which these results were reported across the review cycles —
confirmed directly from the cycle-by-cycle review conversation, not inferred:

| Order | Layer | Result |
| --- | --- | --- |
| 1 | Full prior suite run | 225 tests / 21 files PASS. Not labeled here as the project's original baseline — it is recorded only as the fullest prior run reported to this record, with no claim about what came before it. |
| 2 | Placeholder-mask tests | 25/25 PASS — the tests affected by the UI-DATE-001 placeholder/date-field change set. |
| 3 | `Employee360Operations.test.tsx`, after the close-button-label cycle | 46/46 PASS, reported together with `lint`/`typecheck` PASS and `CLOSE_LABEL_CHECKS=PASS`. Reported in an earlier review cycle than row 4 — specifically, before the three error-integration tests in §4 were added to this file. |
| 4 | `Employee360Operations.test.tsx`, final accepted state (after the three error-integration tests in §4) | 49/49 PASS, reported together with `lint`/`typecheck` PASS and `MUTATION_ERROR_FINAL=PASS`. Reported in a later, separate review cycle than row 3. |

Rows 3 and 4 are **two distinct reported runs, not one run described two ways** — the two tags
(`CLOSE_LABEL_CHECKS=PASS`, `MUTATION_ERROR_FINAL=PASS`) are each attributed only to the run they were
explicitly reported alongside, never combined into a single run. The three error-integration tests this
record's author added in §4 sit between the two reports, raising this file's own test count from 46 to 49.
Separately, §3 records the one dated, in-repo log artifact, showing a still-earlier count of 41 — predating
both row 3 and row 4. The sequence 41 (§3, dated artifact) → 46 (row 3) → 49 (row 4) reflects three distinct,
separately-evidenced points in this engagement's history, not a single run or a smoothed progression: no run
producing exactly 46 or exactly 49 was itself executed or witnessed by this record's author — both are
recorded here exactly as reported, in the order they were reported.

`build` success was reported by the architecture authority as well, but scoped to the version current when
the 24-file reference manifest in §7 was established — i.e. before the later close-button-label and
mutation-error fixes. **No `build` run on the final, post-fix state was reported to this record, and none is
claimed here.**

## 3. The one in-repo dated test-run artifact — and why it is not used as evidence of the final state

`s46-windows-tests.log` exists at the repository root (UTF-16LE, Vitest's colored terminal output).
File modification time (read via the device bridge, UTC): **2026-10-03 11:20:41**. It records **17 test
files / 160 tests, all passed**, with `Employee360Operations.test.tsx` shown at **41 tests** — not the final
49 in §2. This is an earlier, intermediate run, predating both the close-button-label cycle and the
error-integration tests reviewed in this engagement. It is **not** treated as evidence of the closed state;
it is recorded here only as the one dated artifact that exists, for completeness, and is explicitly excluded
from the final manifest in §7/`verification/s46-final-manifest.json` as a temporary log rather than a payload
file.

## 4. Fix evidence from this engagement (already accepted; not re-tested here)

Two related defects were found and fixed in `frontend/src/shared/api/mutationError.ts` during this
engagement's error-integration test work, both confirmed via a real cloud-workspace Vitest execution (not
the Windows bridge, not the final Windows run — see this repository's prior review history) and later
accepted by the architecture authority as part of the 49/49 final Windows result in §2:

1. The 404 (`notFound`) branch was leaking the backend's raw `message` to the user via `detail`, contrary to
   this file's own doc comment scoping `detail` to 409/422 only. Fixed by returning `detail: null` for 404.
2. After fix 1, `fieldErrors` was still passed through unfiltered on 404, and `fieldErrorFor()`
   (`features/employees/operations/shared.ts`) wires it into a visible field-level error as soon as the form
   returns from review to the edit step — invisible during review (fields aren't mounted there), which is why
   this needed a dedicated after-"Back" assertion to catch. Fixed by returning `fieldErrors: {}` for 404.

Both fixes are scoped to the 404 branch only. The 401/403/409 branches pass `fieldErrors` through the same
unfiltered way — a structurally similar, **unverified** pattern, deliberately left untouched per the
architecture authority's instruction not to expand the fix beyond what the test evidence actually showed.
This is not re-opened or re-investigated here.

## 5. Live visual-acceptance check (this pass — read-only, no submit ever clicked)

Performed against an active employment relationship the user selected (status active/"سارية" throughout the
check; confirmed unchanged afterward). No identifying details about it are recorded in this document, and it
is not described as synthetic or otherwise here — its provenance was not established by this record.

| Operation | Desktop (1024px) | 390px | Review step |
| --- | --- | --- | --- |
| End relationship | PASS — no horizontal scroll, no clipped elements | PASS (DOM: `scrollWidth`=`clientWidth`) | PASS — employee, end date, terminal/non-terminal choice all clear; Back preserves date + choice exactly, on both widths |
| Transfer | PASS | PASS | PASS — employee, destination unit, start date, decision type all clear; Back preserves all three field values exactly, on both widths |
| Record status / Record return intention / Start full secondment / Start assignment / Record partial secondment / Record work schedule | PASS | PASS | not checked (review step verification was scoped to Transfer and End relationship only, per instruction) |
| End full secondment | **not verified** — action disabled (no open full-secondment period on the test relationship) | **not verified** — same reason | — |
| End assignment | **not verified** — action disabled (no open assignment period) | **not verified** — same reason | — |

No data was fabricated to reach the two disabled actions, per explicit instruction. "PASS" above means: all
fields/buttons present, no horizontal overflow (`scrollWidth`/`clientWidth` equal on the dialog), zero
elements with a clipped bounding rect, confirmed by direct DOM/accessibility-tree inspection. The Browser
pane's screenshot capture tool stopped reflecting live page state partway through this pass (repeatedly
returned a stale frame despite confirmed-correct live DOM, independent of reload/wait/click mitigation); this
is recorded as a tool limitation, not an application defect, and is why DOM inspection — not pixel
screenshots — is the primary evidence for most of the above. A small number of successfully fresh
screenshots were obtained (End relationship edit form, desktop) and showed no visual defect consistent with
the DOM findings.

## 6. Local runner script — reported execution, reuse path confirmed; cold start still not verified

`run-local.ps1` (invoked through its `run.bat` wrapper) is documented, by its own header comments, to start
Redis/Backend/Frontend **only if not already running for this project**, and never to modify `php.ini`,
`.env`, `package.json` or application source, run migrations/seed/optimize/cache-clear, or stop a process it
did not itself start.

The architecture authority reports that `run.bat` was actually executed on Windows and succeeded: it
reused the already-running Backend/Frontend/Redis services without stopping or restarting any of them, and
its own internal checks — Redis reachability, the backend health endpoint, and the CSRF-cookie endpoint —
each passed. This record's author did not execute this run and holds no captured output for it; it is
recorded exactly as reported, distinctly from the live-check browser session in §5, which only connected to
already-running services incidentally and performed none of `run.bat`'s own checks itself.

**Starting the three services from a fully stopped state remains NOT VERIFIED.** No run of either script
against a cold environment has been reported to this record.

## 7. Payload fingerprint manifest — historical manifest unchanged; the 9 differences explained; a new, separate manifest proposed

`verification/s46-windows-verification.ps1` carries a SHA-256 fingerprint manifest for 24 files, pinned to
`HEAD = 7df739f7012f2f8225351ab03a78e9001a0cd821` and described in its own comment as "as run by the author on
Linux for this exact payload" (18 test files / 162 tests expected in total — a different, larger count than
the single-file 49/49 in §2, since it covers the whole suite). **This script and its embedded manifest were
not edited at any point in this engagement, including in this update; they remain exactly as found.**

A read-only re-hash of the current 24 files against that embedded manifest found **15 of 24 match exactly; 9
differ**, and not merely by CRLF (confirmed by LF-normalization, which does not resolve the difference):

- `frontend/src/features/employees/Employee360StatusHistory.tsx`
- `frontend/src/features/employees/operations/MovementOperations.tsx`
- `frontend/src/features/employees/operations/ScheduleAndEndOperations.tsx`
- `frontend/src/features/employees/operations/StatusOperations.tsx`
- `frontend/src/i18n/messages/ar.ts`
- `frontend/src/i18n/messages/en.ts`
- `frontend/src/pages/Employee360Operations.test.tsx`
- `frontend/src/shared/api/mutationError.ts`
- `frontend/src/shared/ui/Operation.tsx`

A plain `git diff HEAD` on these 9 files is **not, by itself, proof of why each differs from the historical
manifest.** `HEAD` is the last real commit (2026-10-03, pre-dating essentially all of S46), so a HEAD-diff
shows everything uncommitted at once — both whatever existed before the manifest was generated and whatever
came after it — and cannot on its own isolate what changed specifically *after* the manifest's own
generation time (2026-10-04 12:13, per the script file's own modification time). The evidence actually used
below is more specific than a HEAD-diff:

- An independently-dated reference available to this record's author: a pristine, pre-UI-DATE-001 copy of
  the frontend (`masarhr-frontend-pristine`) and a diff of it against a later cloud-workspace snapshot
  (`UI-DATE-001.patch`, dated ~2026-10-05 01:3x–01:5x). This shows, file by file, the literal UI-DATE-001
  content change — replacing a locally-defined `<Input type="date">`/`Ltr` date handling with the shared
  `DateInput`/`DateText` components — for `MovementOperations.tsx`, `ScheduleAndEndOperations.tsx`,
  `StatusOperations.tsx`, and `Employee360StatusHistory.tsx` (which also separately gained a
  `TERMINAL_STATUS_CODES` import from the new `statusCodes.ts`, confirmed by direct reading of the import and
  its two call sites, and by `StatusOperations.tsx` being the only other file that imports the same constant
  — a cross-reference, not an inference from either file's name).
- Direct reading of current file content, cross-referenced against what it depends on: `shared/ui/Operation.tsx`
  contains the literal line `closeLabel={messages.app.close}`; `components/ui/sheet.tsx` (also part of the
  real-change payload, outside the 24-file manifest — see §9) contains the literal, self-documenting change
  `closeLabel = "Close"` with the comment "project change: the default was fixed English," which is exactly
  what `Operation.tsx` overrides with the localized message; `ar.ts`/`en.ts` carry the corresponding new
  top-level `close` key, present in the current file and absent from the `UI-DATE-001.patch` snapshot of the
  same file (i.e. added after that patch was taken). Together this is a directly-evidenced, file-to-file
  causal chain for the close-button-label cycle, not a guess from any file's name. `ar.ts`/`en.ts` also carry
  a later, separate refinement of the `dateInput` block itself (an added `invalidMonth` message and a
  placeholder-format change from localized digit placeholders to literal `dd`/`mm`/`yyyy`), visible by the
  same before/after comparison against the patch snapshot.
- Hashes this record's own author tracked directly during this engagement's error-integration test work: the
  current hashes of `Employee360Operations.test.tsx` (`ea3da743a561…`) and `mutationError.ts`
  (`d1278afd3825…`) match exactly the versions synced and accepted as the `MUTATION_ERROR_FINAL=PASS` result.

All 9 differences are accounted for by one of the three evidence types above; none is left unexplained. This
remains, in itself, only **a difference from a historical reference point** — it is not, on its own, proof
that the current content is either defective or sound. The soundness claim for the current content rests on
the evidence enumerated above (and, separately, on the Windows results in §2), not on the mismatch itself.

**A new, separate reference manifest, `verification/s46-final-manifest.json`, is proposed alongside this
record for the currently accepted file set.** It is a new file; it does not edit
`verification/s46-windows-verification.ps1` or its embedded manifest, which remain exactly as found.

## 8. Git state (read-only snapshot, taken while writing this record)

- Branch: `develop`
- HEAD: `7df739f7012f2f8225351ab03a78e9001a0cd821` (matches the verification script's pin; unchanged across
  every git-state snapshot taken in this engagement — confirms no commits were made in the interim)
- Staged: 0 files
- Modified (tracked): 52 files
- Untracked: 21 top-level entries (including this record itself; 3 of the others are directories expanding to
  more files — see §9)

## 9. File inventory, categorized

**S46 core payload** (the 24-file set pinned in `verification/s46-windows-verification.ps1`, §7):
`features/employees/Employee360StatusHistory.tsx`, `features/employees/api.ts`,
`features/employees/operations/{MovementOperations,ScheduleAndEndOperations,StatusOperations,
WeekdayPicker,api,optionHooks,options,shared}.{ts,tsx}`, `features/employees/statusCodes.ts`,
`features/employees/weekdays.ts`, `i18n/messages/{ar,en}.ts`, `pages/Employee360Operations.test.tsx`,
`pages/Employee360Page.tsx`, `pages/Employee360Tabs.test.tsx`, `shared/api/mutationError.ts`,
`shared/hooks/useOperation.ts`, `shared/security/permissions.ts`, `shared/ui/NativeSelect.tsx`,
`shared/ui/Operation.tsx`, `shared/ui/feedbackContext.ts`, `test/employee360Fixtures.ts`.

**Additional S46-scoped files carrying real content changes** (UI-DATE-001 date-field adoption and the
close-button-label cycle; each tracked file below was individually confirmed as a real change — not CRLF
noise — via a strict LF-normalized diff against `HEAD`, never `--ignore-all-space`):
`components/ui/sheet.tsx`, `features/employees/EffectiveStatusText.tsx`,
`features/employees/Employee360MovementTimeline.tsx`, `features/employees/Employee360Overview.tsx`,
`features/employees/Employee360ReturnIntention.tsx`, `features/employees/RelationshipsList.tsx`,
`features/system-status/SystemStatusCard.tsx` + `.test.tsx`, `pages/DashboardPage.tsx` + `.test.tsx`,
`pages/Employee360Page.test.tsx`, `shared/ui/DateText.tsx` (all tracked/modified); plus, untracked and new:
`shared/ui/DateInput.tsx` + `.test.tsx`, `shared/ui/MonthInput.tsx` + `.test.tsx`,
`shared/hooks/useDateFieldValidity.ts`, `shared/lib/date.ts` + `.test.ts`.

**Operational/runner files** (S46-scoped per their own in-file doc comments): `run-local.ps1`, `run.bat`,
`verification/s46-windows-verification.ps1`, `verification/bf01-readonly-diagnostic.ps1`,
`verification/bf02-console.js`.

**Documentation**: this record itself, `docs/employee-360-operations-closure-record.md`.

**Genuinely unrelated change, kept out of the S46 payload**: `backend/composer.json` — a real 3-line content
change (`"platform": {"php": "8.3"}` added under `config.platform`), confirmed by direct diff, not CRLF
noise.

**CRLF-only noise, kept out of the real-change payload and listed only here** (each file below was
individually confirmed CRLF-only — zero remaining LF-normalized diff lines against `HEAD` — not assumed by
directory or by association with a real-change sibling file): `docs/administrative-report-foundation-specification.md`,
`docs/bounded-temporary-employment-status-lifecycle-specification.md`, `docs/dashboard-foundation-specification.md`,
`docs/employee-specialty-history-foundation-specification.md`, `docs/employment-category-history-foundation-specification.md`,
`docs/employment-contract-foundation-specification.md`, `docs/employment-job-title-history-foundation-specification.md`,
`docs/employment-status-expiry-followup-specification.md`, `docs/employment-status-report-foundation-specification.md`,
`docs/human-cadre-report-foundation-specification.md`, `docs/monthly-not-on-duty-report-foundation-specification.md`,
`docs/monthly-workforce-multi-value-dimensions-specification.md`, `docs/monthly-workforce-reporting-semantics-foundation-specification.md`,
`docs/movement-expiry-followup-foundation-specification.md`, `docs/movement-temporal-integrity-corrective-specification.md`,
`docs/partial-secondment-foundation-specification.md`, `docs/person-profile-foundation-specification.md`,
`docs/person-qualification-foundation-specification.md`, `docs/reporting-as-of-foundation-specification.md`,
`docs/specialty-catalog-administration-foundation-specification.md`, `docs/work-schedule-foundation-specification.md`,
`docs/workforce-analytics-foundation-specification.md` (22 files); and, under `frontend/src/`:
`features/dashboard/api.ts`, `features/dashboard/contract.ts`, `features/dashboard/dashboard.contract.test.ts`,
`features/dashboard/hooks.ts`, `features/dashboard/labels.ts`, `features/dashboard/month.ts`,
`features/dashboard/usePercentText.ts`, `features/employees/hooks.ts`, `pages/EmployeesPage.test.tsx`,
`test/dashboardFixtures.ts` (10 files). None of these 32 files appear in any real-change category above, and
none is included in `verification/s46-final-manifest.json`.

**Pre-existing cleanup debris, untouched**: `_to_delete/` (a `.git/index.lock*` artifact set and an S13
archive/manifest pair) — present before this engagement, not created or modified by it, deliberately left
alone.

**Temporary log artifact, excluded from the payload and from the new manifest**: `s46-windows-tests.log` (see
§3) — a dated but stale/intermediate Vitest terminal-output capture, not a source or evidence file.

## 10. Historical investigation items

- **BF01** (`verification/bf01-readonly-diagnostic.ps1`): a read-only diagnostic for a historical **rejection
  of an attempt to record an employment status period**, confirmed directly from the script's own content —
  it evaluates each rejection condition of `RecordEmploymentStatusPeriod` (not-after-the-relationship's-own
  `effective_from`, already-recorded periods starting on or after the candidate date, and a covering-period
  check) against a specific person/date pair, entirely inside a rolled-back read-only transaction. **This
  corrects an earlier version of this record**, which had wrongly described BF01 as covering the 401/404
  deep-link note below; the two are unrelated historical items, and BF01 does not touch either. The
  architecture authority reports BF01 was executed on Windows and completed with `PHP_EXIT=0`; the
  investigation itself is complete and found no proven defect in the status-period rejection logic it
  examined. This record did not execute it and holds no captured output; it is recorded exactly as reported.
- **401/404 deep-link note** (historical; unrelated to BF01 — a separate item, recorded independently): the
  architecture authority reports that the specific, narrowly-scoped check for this item is **PASS**. The
  **original historical incident's root cause remains NOT VERIFIED** — a passing check against the current
  state does not itself establish what caused the earlier, historical symptom. This record did not execute
  or witness the check and holds no captured output for it; it is recorded exactly as reported.
- **BF02** (`verification/bf02-console.js`, the read-only tab-scroll-reach DOM measurement): reported as
  **PASS**, combining its own live-DOM measurement with the accepted test evidence already on record. This
  record did not execute it and holds no captured output; recorded exactly as reported.
- `act()` warnings and a `HydrateFallback`-related warning observed in earlier Vitest output remain not
  re-chased in this record.

## 11. Closing statement

Per the architecture authority's explicit direction: **S46 is functionally closed, PASS with the limits
documented above.** This record does not declare S46 closed on its own authority — it documents the
authority's decision and the evidence behind it. **S47 is not authorized.** No Git write (add/commit/push/tag)
was performed in this engagement; this document itself is an untracked file pending the architecture
authority's own decision on whether and how to commit it.
