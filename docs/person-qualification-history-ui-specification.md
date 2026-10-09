# Person Qualification History — UI Specification (S49)

**Status: APPROVED / CLOSED (2026-10-09). See §9 for the closure record. Originally approved for
execution by conversation authorization, not by review of a document the user had not seen.**

## 0. Authorization and its limited scope

This stage ("S49") is authorized directly by the user, in this conversation, after four rounds of
read-only scope review (initial scope analysis, an architecture-review correction round, a
literal-evidence addendum, and a controlled multi-agent verification round). The user explicitly
exempted **S49 alone** from the standing condition that the two external analysis documents
(تحليل نظام شغل ايه، استكمال تحليل نظام العمل ايه) be reviewed before a stage proceeds. This
document does **not** claim those two documents were read, and this exemption is **not** general —
it does not carry over to any later stage (S50 or otherwise), which still needs its own explicit
authorization and remains subject to that same standing condition unless the user says otherwise
again, for that stage, at that time.

The reference for this stage's content is: the frozen S48 backend specification
(`docs/person-qualification-history-foundation-specification.md`), the real, already-shipped
backend code and its Feature tests, and the scope corrections and literal-evidence findings
accepted by the user earlier in this conversation. Nothing here reopens or restates the frozen S48
specification or its closure/deployment records.

## 1. Scope

Read-only frontend UI inside Employee 360, for one person at a time:

1. The current qualifications list (already shown today, under S23's older contract) — updated to
   the real S48 `PersonQualificationResource` shape.
2. Per-qualification version history, opened from that qualification's own row.
3. Primary-qualification-designation history, opened by one button scoped to the whole
   qualifications section (not per row).

Out of scope, explicitly: `record` (add a qualification), `correct` (submit a correction), and
`designate-primary` (change the primary qualification) — no write request of any kind is issued by
any S49 component. No backend change, no migration, no database write, no change to any
permission's grant in the database. No S48 backend test is re-run. No change to
`useApiResource.ts` (the shared fetch/abort state machine) unless a proven defect blocks this scope
and cannot be worked around — none was found; S49 uses its existing, already-reviewed
nullable-fetcher and deps-array mechanisms as they already work today.

## 2. The real API contract (re-stated here only as context for the UI; S48 owns the contract)

| Method | Path | Permission |
|---|---|---|
| GET | `/hr/persons/{person}/qualifications` | `hr.person_qualifications.view` |
| GET | `/hr/persons/{person}/qualifications/{id}/versions` | `hr.person_qualifications.view` |
| GET | `/hr/persons/{person}/qualifications/primary-history` | `hr.person_qualifications.view` |

All three reads share **one** permission. `record`/`correct`/`designate_primary` are separate,
independent permissions and are not touched by S49.

Current shape (`PersonQualificationResource`): `id, person_id, academic_degree_id,
qualification_type_id, obtained_on, created_at, version_number, provenance, is_primary`.

Version shape (`PersonQualificationVersionResource`), ordered **ascending by `version_number`** by
the backend (oldest first) — the UI never re-sorts this: `version_number, academic_degree_id,
qualification_type_id, obtained_on, reason, is_current, provenance, created_by_principal_id,
created_at`.

Primary-history event shape (`PrimaryQualificationHistoryEventResource`), ordered **ascending by
`occurred_at`, then by the underlying audit entry's own `id` as a tie-breaker** by the backend — the
backend's own code comment disclaims this ordering as a deterministic tie-breaker only, **never a
proof of true execution order**, and the UI repeats that distinction nowhere does it claim causal
ordering: `type, qualification_id, previous_primary_qualification_id, actor_principal_id,
occurred_at`.

Pagination envelope — identical `meta` shape for both paginated reads (Laravel's
`LengthAwarePaginator`): `current_page, last_page, per_page, total` (plus `links`, unused by this
UI). `versions` wraps rows in `data`; `primary-history` wraps rows in `events` and adds a sibling
key `evidence_completeness: { gaps: [{ code, qualification_id }] }` — a sibling of the paginated
envelope, not nested inside it, and identical on every page. Exactly two gap codes exist in the
whole backend: `GAP_NO_DESIGNATION_EVIDENCE`, `GAP_CHAIN_BROKEN`. A gap entry has exactly two
fields, `code` and `qualification_id` — no date, no actor, no event type. The UI never invents a
date, an actor, or an event for a gap, and never renders a gap as if it were an event row.

`provenance` (`'RECORDED' | 'BACKFILLED_UNKNOWN_ACTOR'`) depends **only** on whether
`created_by_principal_id` is known — never on `obtained_on`, and never a proof that a row was
created after or during S48 (a resolved-backfill row legitimately reads `RECORDED` too, proven by
the backend's own test). The UI treats "date unknown" (`obtained_on === null`) and "actor unknown"
(`provenance === 'BACKFILLED_UNKNOWN_ACTOR'`) as two independent facts, each shown on its own,
never merged into one label and never inferred from `created_at`.

## 3. Component composition — the permission gate prevents mounting the fetch, not just hiding a table

Today, `usePersonQualifications(personId)` is called directly in `Employee360Page`'s own function
body (line 121, before its `return`), so the request fires on every mount regardless of any
permission check rendered later. S49 changes this specifically for qualifications:

- `Employee360Page.tsx` **stops** calling `usePersonQualifications`, stops deriving
  `degreeIds`/`qualificationTypeIds` from it, stops calling `useReferenceValues('academic-degrees' |
  'qualification-types', …)`, and stops passing `qualifications`/`degreeNames`/
  `qualificationTypeNames` as props. It passes `personId` to `Employee360CareerHistory` instead
  (already available there as a prop of the page itself).
- `Employee360CareerHistory.tsx` renders, where the old qualifications block used to be:
  ```tsx
  <PermissionGate permission={HR_PERMISSIONS.personQualificationsView}>
    <Employee360QualificationsSection key={personId} personId={personId} />
  </PermissionGate>
  ```
- `Employee360QualificationsSection` (new) is the **only** place that calls
  `usePersonQualifications`, `useReferenceValues('academic-degrees'|'qualification-types', …)`,
  `useQualificationVersions`, and `usePrimaryQualificationHistory`.

Because `PermissionGate` returns either its own unauthorized panel or `{children}`, and React does
not invoke a component function (and therefore does not run the hooks inside it) for an element it
never returns, a principal lacking `hr.person_qualifications.view` causes
`Employee360QualificationsSection` to never execute at all — no request to any of the three
qualification endpoints is ever issued, while the rest of Employee 360 (every other tab/section)
renders exactly as it does today, each still governed by its own, independent permission. This is
verified directly against `PermissionGate.tsx`'s real source, not assumed.

`key={personId}` on `Employee360QualificationsSection` is deliberate: it forces a full remount (all
local state — the open version-history panel, its page, the primary-history panel's open state and
page — reset to nothing) whenever the person being viewed changes, so no state or in-flight
request from the previous person can ever be shown against the new one. This is the chosen
mechanism for "لا تبقي بيانات أو طلبات من الشخص السابق" — simpler and more robust than resetting
several independent pieces of state by hand.

`degreeNames`/`qualificationTypeNames` (and the `academic-degrees`/`qualification-types` reference
fetches behind them) were confirmed, by direct grep, to have **no other consumer** anywhere in the
frontend — moving them is safe and loses no other section's data.

## 4. New files (owned by Agent UI) and their contracts

- `frontend/src/features/employees/Employee360QualificationsSection.tsx` — owns: the current-list
  table (via the existing, unmodified `Employee360HistorySection`, since `PersonQualification` rows
  still carry an `id`), a "primary-designation history" trigger button placed above/below that table
  (not inside a shared file), and the two `Sheet`s (per-row version history; section-level primary
  history) using the exact same `Sheet`/`SheetContent` building blocks `shared/ui/Operation.tsx`
  already uses for S46 write actions. Props: `{ personId: string }`.
- `frontend/src/features/employees/QualificationVersionHistoryPanel.tsx` — the content of the
  per-row Sheet. Props: `{ personId: string; qualificationId: string }`. Calls
  `useQualificationVersions(personId, qualificationId, page)` with its own local `page` state,
  reset to `1` whenever `qualificationId` changes (`useEffect(() => setPage(1), [qualificationId])`).
  Renders loading/error(403 no-retry, other manual-retry)/empty/table + the new pagination
  component. Each row shows: version number, degree/type name, obtained-on date or
  "تاريخ الحصول غير معروف" (independently of actor), correction reason (or "—" for version 1),
  is-current indicator, and the actor shown **only** as a labelled, LTR, raw `created_by_principal_id`
  reference (never a resolved name, never fetched via any Security/Principal endpoint — none is
  called from any S49 file) — labelled clearly as a technical reference, never "رقم الموظف" (employee
  number) or any similar misleading label, and never with a time-based claim ("RECORDED" is shown,
  if at all, as "تم تحديد منفّذ التسجيل" with no claim about when the row was created).
- `frontend/src/features/employees/PrimaryQualificationHistoryPanel.tsx` — the content of the
  section-level Sheet. Props: `{ personId: string }`. Calls
  `usePrimaryQualificationHistory(personId, page)` with its own local `page` state (reset to `1`
  only by the `key={personId}` remount above — primary-history has no narrower "changed" dimension
  than the person). Renders events in the order the backend returns them (never re-sorted), each
  showing: event type (`DESIGNATED`/`AUTO_FIRST`, translated), the qualification id and, when
  present, the previous-primary qualification id (both as labelled LTR technical references), the
  actor id (same treatment as above), and `occurred_at`. Renders the `evidence_completeness.gaps`
  list separately and visibly on every page (identical content every page, per the backend's own
  guarantee) — each gap shows only its `code` (translated to the two fixed strings for
  `GAP_NO_DESIGNATION_EVIDENCE`/`GAP_CHAIN_BROKEN`) and its `qualification_id` as a labelled
  reference — never a date, never an actor, never rendered as if it were an event row.
- `frontend/src/features/employees/QualificationArchivePagination.tsx` — a new, qualifications-only
  Prev/Next pagination control, structurally mirroring `FollowUpsPagination.tsx` (same props shape:
  `currentPage, lastPage, total, pageSize, onPageChange`) but reading its four labels from a new
  `employee360`-namespaced i18n key set, not from `messages.followUps` — `FollowUpsPagination.tsx`
  itself is not modified. `pageSize` is fixed at 25 (`QUALIFICATIONS_ARCHIVE_PER_PAGE` in `api.ts`),
  matching the backend's own default.

Both panels fetch only once their Sheet is open (`{open ? <Panel .../> : null}` inside
`SheetContent`, mirroring `Operation.tsx`'s own `{open ? children(...) : null}`) — the panel
component is not mounted, and so its fetch hook never runs, until the Sheet is opened. Switching
the open qualification while the version panel stays open (clicking a second row's trigger before
the first row's request has settled) changes only the `qualificationId` prop of the same mounted
`QualificationVersionHistoryPanel` instance — `useApiResource`'s existing `deps`-keyed effect
(unmodified) aborts the first request and starts the second as it already does for every other S18
resource; no new logic is written for this.

## 5. New API/hook contracts (owned by the Lead, in `api.ts`/`hooks.ts`)

`PersonQualification` gains `obtained_on: string | null`, `created_at: string`,
`version_number: number`, `provenance: 'RECORDED' | 'BACKFILLED_UNKNOWN_ACTOR'`,
`is_primary: boolean` (additive; existing fields unchanged). New types: `PersonQualificationVersion`,
`PrimaryQualificationHistoryEvent`, `EvidenceGap`, `QualificationPage<T>` (`{ data: T[]; meta:
{current_page, last_page, per_page, total} }`), `PrimaryQualificationHistoryPage` (`{ events:
PrimaryQualificationHistoryEvent[]; meta: {...}; evidence_completeness: { gaps: EvidenceGap[] } }`).
New fetchers: `fetchQualificationVersions(personId, qualificationId, page, signal)`,
`fetchPrimaryQualificationHistory(personId, page, signal)` — both send `per_page=25`
(`QUALIFICATIONS_ARCHIVE_PER_PAGE`). New hooks in `hooks.ts`: `useQualificationVersions(personId,
qualificationId, page)`, `usePrimaryQualificationHistory(personId, page)` — both built on the
existing, unmodified `useApiResource`, exactly like every other S18/S31 hook.

## 6. Visual behavior, pagination, failure states

- Both panels are page-at-a-time (never "load every page" — mirrors the explicit, already-reviewed
  S47/FollowUps decision against unbounded auto-pagination); the version panel's page and the
  primary-history panel's page are two fully independent pieces of state, never shared.
- Loading: `StatePanel tone="loading"`. Empty: `EmptyState`. 403: unauthorized panel, **no** retry
  action. Any other failure: failure panel with a **manual-only** `RetryButton` — no automatic retry
  loop anywhere.
- Closing either Sheet (or losing the permission, which unmounts
  `Employee360QualificationsSection` entirely) discards the panel's data and state — there is
  nothing left to show stale data from.
- Arabic and English strings for every new label (added to both `ar.ts` and `en.ts`;
  `types.ts` needs no edit — it is a mapped type derived from `typeof ar`). Dates and every id
  reference (`created_by_principal_id`, `actor_principal_id`, `qualification_id`,
  `previous_primary_qualification_id`) render inside the existing `<Ltr>`/`<DateText>` primitives, so
  they stay left-to-right inside Arabic text, unchanged from the rest of the app.
- 390px and desktop: no new breakpoint is invented; the panels reuse `Sheet` (already
  mobile-safe app-wide) and `DataTable` (already horizontally scrollable, `overflow-auto`, within a
  fixed-height region) exactly as every other S46/S18 panel and table does. No horizontal overflow
  is introduced on the page itself; a wide table scrolls inside its own bordered region, as today.
  Buttons and headings keep their existing accessible names/roles; no new keyboard trap is
  introduced (`Sheet` already provides standard focus-trap/Escape-to-close behavior used elsewhere
  in the app).

## 7. Acceptance criteria -> file ownership (summary; full test list in the test-plan section of the
final delivery report, not duplicated here)

| # | Acceptance criterion | Primarily proven in |
|---|---|---|
| 1 | Missing `hr.person_qualifications.view` blocks all three qualification requests; other sections still work | `Employee360Page.test.tsx` |
| 2 | Holding the permission renders the section using the real S48 fields | `Employee360Page.test.tsx` / new section test |
| 3 | History is never fetched before its panel opens | new component test (Sheet mount timing) |
| 4 | Unknown date vs. unknown actor shown independently; `RECORDED` + `obtained_on=null` renders correctly | new component test |
| 5 | Versions/events rendered in server order; gaps shown, never turned into events | new component test |
| 6 | Independent pagination per panel, bounds, page resets on qualification change | new component test |
| 7 | Person switch leaves no previous person's data/requests (the `key={personId}` remount) | `Employee360Page.test.tsx` |
| 8 | Stale request (A then B) never overwrites the newer one, even completing with data after abort | new component test, mirroring `FollowUpsPage.test.tsx`'s own existing stale-response test |
| 9 | 403 -> no retry; other failure -> manual retry only | new component test |
| 10 | Arabic/English strings; LTR for dates and all technical references | all of the above, bilingually |
| 11 | No write request issued by any S49 component | all of the above (asserted via the fixture's recorded POSTs/method log) |

## 8. Out of scope, explicitly (unchanged from the user's own list)

No `record`/`correct`/`designate-primary` UI. No backend/migration/data/permission-grant change. No
re-run of S48 backend tests. No change to `useApiResource.ts`. No cutover, no repository cleanup, no
new dependency. No resolution of `created_by_principal_id`/`actor_principal_id` into a display name
(no call to any Security/Principal endpoint from any S49 file, bulk or single). No self-closure of
S49 and no opening of S50 by this document.


## 9. Closure record (S49 — APPROVED / CLOSED, 2026-10-09)

This section is the closure record for S49, added once execution was reviewed and accepted by the
user. It does not reopen or restate any decision made earlier in this document; it only records
what was verified and accepted at closure.

**Scope closed:** the read-only Employee 360 qualification-history UI described in §1 above — the
current qualifications list, per-qualification version history, and primary-designation history —
and nothing else. No `record`/`correct`/`designate-primary` UI was built. No backend change, no
migration, and no qualification write request of any kind was issued by any S49 component, at any
point during execution or closure.

**Verification history — two distinct Windows runs, not combined:**

- An earlier real Windows run surfaced 3 test failures (2 from a page-query-parameter
  substring-matching trap, 1 from an ambiguous text match between the primary-history trigger
  button and its panel title) out of the targeted and full suites. Those were test-authoring
  defects in `Employee360QualificationsSection.test.tsx` only; no production file was changed to
  address them, confirmed at the time by `git status`.
- After both fixes, a separate, later real Windows run is the one accepted as closing evidence:
  targeted S49 tests 45/45 passed (exit 0); full frontend suite 264/264 passed across 24 files
  (exit 0); typecheck, lint and build all exit 0. These two runs are kept distinct here
  deliberately — their pass/fail counts are never summed or averaged together, since they ran
  against different code.
- The application itself was run successfully after starting the backend the documented way (the
  project's own local launcher), confirming the frontend's earlier "could not connect to the
  server" symptom was a stopped backend process, not a defect in S49's own code.

**Visual check:** the user confirmed the Arabic and English visual check at 390px and at desktop
width, against the checklist presented for that purpose. This confirmation is the accepted source
of the visual-check sign-off; no subagent ("Agent") ran or verified the visual check itself.

**Fixes accepted as part of this closure:**

1. `PrimaryQualificationHistoryPanel.tsx` — evidence gaps (`evidence_completeness.gaps`) now
   render independently of `events`, so a response with an empty `events` list but non-empty
   `gaps` shows both the empty state and the gap codes together, instead of the gaps being
   silently dropped by the earlier early-return structure.
2. `Employee360QualificationsSection.test.tsx` — the page-query-parameter checks were rewritten to
   read the `page` parameter exactly (`new URL(url, 'http://localhost').searchParams.get('page')`)
   instead of substring-matching, which had falsely matched `page=2` inside `per_page=25`; and the
   read-only-guarantee test now scopes to the open dialog and its heading role instead of an
   ambiguous `findByText` lookup, since the primary-history trigger button and its panel title are
   the same Arabic/English string by design. Both fixes are test-only; no production file changed
   for either.

**Scope of the exemption in §0:** the exemption from reviewing the two external analysis
documents (تحليل نظام شغل ايه، استكمال تحليل نظام العمل ايه) applied to S49 alone and ends with this closure. This record
makes no claim that either document was read, now or at any earlier point, and this exemption is
not carried forward to any later stage (S50 or otherwise) — each later stage remains subject to
the standing condition on its own, unless the user grants it its own exemption at that time.

**Final accepted file set (9 modified + 7 new, unchanged from execution):**

Modified: `frontend/src/features/employees/Employee360CareerHistory.tsx`,
`frontend/src/features/employees/api.ts`, `frontend/src/features/employees/hooks.ts`,
`frontend/src/i18n/messages/ar.ts`, `frontend/src/i18n/messages/en.ts`,
`frontend/src/pages/Employee360Page.test.tsx`, `frontend/src/pages/Employee360Page.tsx`,
`frontend/src/shared/security/permissions.ts`, `frontend/src/test/employee360Fixtures.ts`.

New: `frontend/src/features/employees/Employee360QualificationsSection.tsx`,
`frontend/src/features/employees/Employee360QualificationsSection.test.tsx`,
`frontend/src/features/employees/PrimaryQualificationHistoryPanel.tsx`,
`frontend/src/features/employees/QualificationArchivePagination.tsx`,
`frontend/src/features/employees/QualificationVersionHistoryPanel.tsx`,
`frontend/src/shared/hooks/useApiResource.test.ts`, and this document itself
(`docs/person-qualification-history-ui-specification.md`).

`backend/composer.json` carries an unrelated, pre-existing modification that is not part of S49 and
is deliberately excluded from this stage's commit.

No push, tag, or S50 opening is authorized or implied by this closure record.
