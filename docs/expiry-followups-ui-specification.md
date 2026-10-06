# S47 — Expiry Follow-up Read UI Specification

Stage: S47. Baseline: `0c353572ed1d60fc4458cf049306ef158a6cb14d` (develop, S46 closed and pushed). Governing
decision: the Architecture Authority's "MASARHR — START S47: EXPIRY FOLLOW-UP READ UI" execution
authorization. This document is written BEFORE implementation, per that authorization, and
records only what was verified by reading the actual backend source (routes, Controllers,
Resources, Eloquent models, enums, and the Feature test assertions that pin the JSON shape) — not
inferred, not assumed from file names, and not copied from the S31/S38 specification prose where
the prose and the code could in principle disagree (they did not; every citation below was cross-
checked against the code itself).

This document defines a new, independent, read-only frontend surface. It changes no Backend
code, no migration, no permission, and no business rule. S31 and S38 remain frozen.

## §1 Scope

A new authenticated page at `/follow-ups` ("المتابعات" / "Follow-ups"), with two tabs:

- **Movement expiry follow-ups** (S31) — `GET /api/v1/hr/movement-expiry-followups`.
- **Employment status expiry follow-ups** (S38) — `GET /api/v1/hr/employment-status-expiry-followups`.

Both are read-only system-maintained lists (§S31.17 / §S38.16: "no store, no PATCH/PUT/DELETE").
Nothing here writes to either table; nothing here runs the scanner. The page is independent of the
Dashboard (S45) and of any report: it is not embedded in either, per the Architecture Authority's
explicit decision.

## §2 Verified backend contract

### §2.1 Routes (`backend/routes/api.php`)

Both routes sit in the same `Route::prefix('hr')->middleware(['auth:web','principal.active',
'resolve.context'])` group as every other HR endpoint, each gated by its own dedicated
`permission:` middleware — confirmed by reading the route group directly, not assumed from the
spec documents' prose:

```php
Route::get('/movement-expiry-followups', [MovementExpiryFollowUpController::class, 'index'])
    ->middleware('permission:'.HrPerm::MOVEMENT_EXPIRY_FOLLOWUPS_VIEW)
    ->name('movement-expiry-followups.index');

Route::get('/employment-status-expiry-followups', [EmploymentStatusExpiryFollowUpController::class, 'index'])
    ->middleware('permission:'.HrPerm::EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW)
    ->name('employment-status-expiry-followups.index');
```

Both are `GET` only. A Feature test for the movement endpoint asserts 405 on POST/PATCH/PUT/DELETE
and 404 on `DELETE .../{uuid}` (`MovementExpiryFollowUpFoundationTest`, lines ~748-752); the S38
endpoint is documented identically (§S38.16) and is covered by the same no-write contract.

### §2.2 Permissions (verified in `HumanResourcesPermissionCatalog.php`)

| Constant | String | Granted to |
|---|---|---|
| `MOVEMENT_EXPIRY_FOLLOWUPS_VIEW` | `hr.movement_expiry_followups.view` | no role by default seed |
| `EMPLOYMENT_STATUS_EXPIRY_FOLLOWUPS_VIEW` | `hr.employment_status_expiry_followups.view` | no role by default seed |

Both constants exist in the catalog and are included in its registered permission list (lines
~142/145/200/201). **Independence confirmed in code, not just in prose**: a Feature test
(`EmploymentStatusExpiryFollowUpFoundationTest`, line ~760) grants only the S38 permission and
asserts the S31 endpoint still returns 403 for that same principal — "the S38 permission does not
grant S31 either." The two tabs must therefore be gated independently in the UI, never by a single
combined permission check.

**Migration-application status — explicitly NOT assumed.** The two permission-seeding migration
files exist (`database/migrations/2026_10_14_000002_seed_security_movement_expiry_followup_
permission.php`, `2026_10_16_000002_seed_security_employment_status_expiry_followup_
permission.php`), but per the Architecture Authority's explicit correction, file presence is not
evidence that a migration has actually been applied to the current database. This cannot be
checked from the Linux device-bridge used to read this repository: the backend's Postgres
connection is `127.0.0.1:5432` on the real Windows machine, and a service bound to a device's own
localhost is not reachable from the separate bridge VM. The actual check (`php artisan
migrate:status`, filtered to these two migration names) is included in the single consolidated
Windows verification block delivered with the final report (§7), run on the real machine — it is
not claimed here as already verified. If either migration is not actually applied, its permission
does not exist yet; the practical effect is that every principal gets a 403 from the backend on
that tab (never a crash), which the UI already has to handle as an ordinary authorization state.

### §2.3 Query parameters (verified in both Controllers' `index()`)

| Param | Movement (S31) | Status (S38) | Validation |
|---|---|---|---|
| `state` | yes | yes | `in:ACTIONABLE,LAPSED,SUPPRESSED,ALL`, nullable, default `ACTIONABLE` |
| `movement_type` | yes | — (S38 has no such field) | `in:` the `TemporaryMovementType` enum values |
| `employment_relationship_id` | yes | yes | `uuid`, nullable |
| `per_page` | yes | yes | `integer`, `min:1`, `max:100`, nullable, default 25 |

A validation failure on any of these returns `422` (verified: both Feature test suites assert this
for `state=BOGUS`, an out-of-enum `movement_type`, a non-UUID `employment_relationship_id`, and
`per_page=0` / `per_page=101`).

**This UI implements only the `state` filter**, per the Architecture Authority's explicit decision
("فلتر الحالة يُبنى من العقد الفعلي... ولا فلاتر جديدة"). `movement_type` and
`employment_relationship_id` are real, validated, supported filters in the contract, but no UI
control is authorized for them in this stage — adding one would be scope expansion. `per_page` is
used internally for pagination (§5.6), not exposed as a user-facing filter.

### §2.4 `state` semantics (identical logic in both Controllers, verified line-by-line)

```php
match ($data['state'] ?? 'ACTIONABLE') {
    'ACTIONABLE' => $query->where('status', ACTIONABLE)->where('expected_effective_to', '>', $today),
    'LAPSED'     => $query->where('status', ACTIONABLE)->where('expected_effective_to', '<=', $today),
    'SUPPRESSED' => $query->where('status', SUPPRESSED),
    default      => null, // ALL: no extra constraint
};
```

`$today` is `BusinessDateClock::today()` (server business date, UTC — §S31.12/§S38 reuse the same
clock), not the browser's local date. `LAPSED` and `SUPPRESSED` are both real, contract-supported
values (not UI inventions) — `ALL` is also real and supported. All four values are offered in the
filter control (§5.4).

### §2.5 Ordering and pagination (verified in both Controllers)

```php
$query->orderBy('due_date')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));
```

Both endpoints order by `due_date` ascending, then `id` ascending as a deterministic tie-breaker,
and return Laravel's standard paginated-resource envelope (`data`, `meta.total`,
`meta.current_page`, `meta.last_page`, `links…`) — confirmed by the Feature tests reading
`response->json('meta.total')` and `response->json('data.0...')` directly, and by
`MovementExpiryFollowUpResource::collection($page)->response()` / the S38 controller's identical
call, which is Laravel's standard `AnonymousResourceCollection::response()` pagination wrapper.

**Pagination approach for this UI**: page-at-a-time with Prev/Next, never "load all pages." §S41's
own `fetchAllPages` helper (walking every page of a bounded reference catalog) is NOT reused here
— a catalog of employment categories or units is small and bounded; a follow-up feed is not, and
the Architecture Authority's "read-only ≠ zero risk" correction applies directly: looping pages
automatically on every list view would turn one user action into an unbounded number of requests
as the underlying table grows. `per_page` is fixed at 50 (mid-range of the 1-100 contract) and the
page number is a `page` query param, read from `meta.current_page` / `meta.last_page` in the
response.

### §2.6 Response fields (verified against the Resource `toArray()` AND the Feature tests'
`array_keys()` assertions pinning the exact key order)

**Movement (S31)** — `array_keys($response->json('data.0'))` (test, line ~804):

```
id, followup_kind, movement_type, movement_id, employment_relationship_id,
organizational_unit_id, expected_effective_to, due_date, status, state,
suppression_reason, created_at, suppressed_at
```

**Status (S38)** — `array_keys($actionable->json('data.0'))` (test, line ~779):

```
id, followup_kind, employment_status_period_id, employment_relationship_id,
expected_effective_to, due_date, status, state, suppression_reason, created_at, suppressed_at
```

Both Resources are documented in their own doc comments as exposing "ids, dates and stable codes
only — no employee data, no localized labels," and a Feature test for the movement endpoint
(line ~808) asserts the string `"national"` never appears anywhere in the JSON payload. **No
version of either resource carries a person's name, national ID, or any other direct employee
identifier.** This is the single most important, verified fact driving §4.

`state` (derived, not stored) is one of `ACTIONABLE | LAPSED | SUPPRESSED`:
- `SUPPRESSED` if `status === 'SUPPRESSED'`;
- else `ACTIONABLE` if `expected_effective_to > business_date`, else `LAPSED`.

`suppression_reason` is `null` unless `state === 'SUPPRESSED'` (database CHECK enforces this
server-side; the UI never has to guess).

**Movement-only field**: `organizational_unit_id` (the movement's destination unit — §S31.17: "the
same unit S12/S16 reads are scoped to"). Status follow-ups carry no organizational unit at all
(§S38.5: "a status period has no organizational unit").

### §2.7 Suppression reason codes (verified in the two Domain enums, not inferred from English
prose)

**Movement** — `App\Modules\HumanResources\Domain\FollowUpSuppressionReason`:

| Code | Meaning (from the enum's own doc comment) |
|---|---|
| `TRUNCATED_EARLIER` | the movement now ends earlier than expected (a newer movement, a transfer, or a relationship end truncated it) |
| `END_DATE_CHANGED` | the movement's end date changed in any other way |
| `RELATIONSHIP_ENDED` | the Employment Relationship ends on or before the expected end — no return to act on |
| `COVERED_BY_NEWER_MOVEMENT` | a newer movement already covers the expected end date |

**Status** — `App\Modules\HumanResources\Domain\StatusFollowUpSuppressionReason` (deliberately a
*different* set — "the movement-only `COVERED_BY_NEWER_MOVEMENT` does not exist here", per the
enum's own doc comment):

| Code | Meaning |
|---|---|
| `RELATIONSHIP_ENDED` | the Employment Relationship ends on or before the expected end |
| `TRUNCATED_EARLIER` | the status period now ends earlier than expected (an ordinary truncation) |
| `SUCCESSOR_RECORDED` | an explicit successor status already starts exactly at the expected end |

Movement and status use two distinct enums with two distinct code sets; the UI must translate each
independently and must never show a status-only or movement-only code on the other tab.

### §2.8 Organizational scope (movement tab only — verified in `MovementExpiryFollowUpController`)

The movement endpoint additionally filters rows by the S08 `ScopedAuthorizationChecker` applied
per-row to `organizational_unit_id` (the destination unit), computed server-side; out-of-scope
rows are silently absent from both the list and `meta.total` (§S31.17: "existence does not leak").
This needs no UI handling beyond trusting the response as the complete, authorized result for the
caller — exactly like every other S08-scoped list already in the frontend.

The status endpoint has no organizational scope at all (§S38.15: "plain RBAC... no scope is
derived from placement or movement").

## §3 Movement types (verified in `TemporaryMovementType` enum)

```
FULL_SECONDMENT, WORKPLACE_ASSIGNMENT, PARTIAL_SECONDMENT
```

Transfer and Organizational Placement are not temporary movements and never appear here (§S31.3:
"Transfer is permanent and has no expiry alert"). The UI never invents a fourth value.

## §4 Names and employee linking — verified result: NO permitted, executable method exists

The authorization required checking, before building anything, whether `employment_relationship_
id` can be resolved to a person's name and an Employee 360 link through a PERMITTED and EXECUTABLE
read path — explicitly ruling out national-ID search, personId guessing, a full bulk person load,
an unbounded per-row request, and any endpoint the viewer's permission set might not actually
cover.

**Every route that can resolve an Employment Relationship is nested under a known Person id**:
`backend/routes/api.php` has no standalone `GET /employment-relationships/{id}` and no reverse
lookup from a relationship id to its person. Every relevant route is
`/hr/persons/{person}/employment-relationships[...]` — the person id must already be known before
any of these can be called. There is no route that accepts only `employment_relationship_id` and
returns the person.

**The one existing "lookup" endpoint is closed by the authorization's own constraint.**
`GET /hr/persons/lookup` (`PersonController::lookup`) is verified, by reading its implementation
directly, to accept exactly one parameter: `national_id` (`required|string|max:64`) — a pure
national-ID search, calling `FindPersonByNationalId`. This is precisely the method the
authorization forbids using.

**No bulk/catalog resolution is available either.** Unlike a bounded reference catalog
(employment categories, job titles, organizational units — resolved elsewhere in the app via
`fetchAllPages`-style helpers because those lists are small and finite), the set of Employment
Relationships is not a catalog: walking every person to build a relationship→person map would be
exactly the unbounded bulk load the authorization explicitly forbids, and would still require a
permission (`hr.persons.view` and/or `hr.employment_relationships.view`) that a viewer holding only
`hr.movement_expiry_followups.view` or `hr.employment_status_expiry_followups.view` is not
guaranteed to hold — which would make list success conditional on an extra permission, also
explicitly forbidden.

**Conclusion, per the authorization's own instructions for exactly this outcome**: linking is not
possible under the given constraints. The list is still shown, with every field the two Resources
actually expose, and:

- `employment_relationship_id` is **never presented as, or in place of, an employee name**. It is
  shown as a labelled technical reference (see §5.5), not as "the employee."
- There is **no link to Employee 360** from either tab. Attempting to build
  `/employees/:personId/relationships/:relationshipId` would require a `personId` this surface
  never has and cannot obtain under the allowed methods.
- This gap is documented here and restated in the final report (§7); the Backend is not modified
  to work around it, per the explicit instruction.
- The list remains comprehensible without a name: due date, expiry date, derived state,
  suppression reason (when applicable), movement type (movement tab), and the relationship
  reference are shown exactly as the two Resources expose them. The limitation is stated directly,
  not worked around by assumption: this list shows follow-up records, but it does not identify the
  employee by name and it does not provide navigation to that employee's profile. No permitted,
  executable method to close that gap was found (§4 above), and no other means of identifying the
  employee or acting on a row — offline or otherwise — is assumed or verified to exist. This does
  not require a STOP under the authorization's own "if resource fields aren't even sufficient for
  a comprehensible list" test — the fields are sufficient for a comprehensible, if identity-light,
  list; only the name/link specifically is unavailable.

**Organizational unit name (movement tab)**: resolving `organizational_unit_id` to a display name
would require `GET /organization/units/{id}` (`OrganizationPermissionCatalog::ORGANIZATION_VIEW`)
— again a *different* permission than the one gating this tab. For the same reason as above (list
success must not depend on an extra permission), this stage does **not** resolve the unit name.
The unit is shown the same way the relationship is: as a labelled technical reference, not a name.
This is a deliberate, narrower decision than Employee360CareerHistory's catalog-id resolution
pattern (which depends on reference-catalog endpoints already required by the page it's on) —
copying that pattern here would introduce exactly the cross-permission dependency this section
rules out, so it is not reused for this stage.

## §5 UI decisions

### §5.1 Route and navigation

- New top-level route `/follow-ups`, inside the existing authenticated `<RequireAuth />` wrapper
  pattern (same shape as `/dashboard`), lazy-loaded like every other real page.
- New `NAV_ITEMS` entry, label key `followUps` → "المتابعات" / "Follow-ups", using the existing
  `anyPermission` mechanism (`isNavItemVisible`) with
  `[HR_PERMISSIONS.movementExpiryFollowupsView, HR_PERMISSIONS.employmentStatusExpiryFollowupsView]`
  — visible to a principal holding **either** permission, per the authorization. This is UX only;
  the backend re-checks both permissions independently per endpoint, exactly like every other nav
  item already does.
- Direct URL access without either permission is blocked the same way every other permission-
  gated page in this codebase already blocks it: the page itself renders the existing
  unauthorized `StatePanel` (the same visual/copy contract `PermissionGate` uses) rather than
  relying on the nav item being hidden. The backend remains the authority.

### §5.2 Page structure

Two tabs, built with the existing `Tabs`/`TabsList`/`TabsTrigger`/`TabsContent` (shadcn, already
used by `Employee360Page`) — no new dependency:

- A principal holding **both** permissions sees both tabs (`Tabs` with two triggers).
- A principal holding **exactly one** permission sees that one section rendered directly, with no
  tab chrome at all (no single-item tab list) — there is nothing to switch between.
- A principal holding **neither** permission (reached the page via a bookmarked URL, say) sees the
  existing unauthorized `StatePanel`, not an empty page.

Unlike `Employee360Page` (which mounts every tab's data with `forceMount` because it is one
person's already-fetched profile), **each tab's data hook here is only ever called with fetching
enabled when that tab is the active one AND the viewer holds that tab's permission.** This is the
direct implementation of "no request is ever sent for a non-permitted or inactive tab" — not an
incidental side effect of hiding content, but an explicit `enabled` condition passed into the data
hook, using `useApiResource`'s own existing support for a `null` fetcher (already relied on
elsewhere in this codebase to skip a fetch conditionally). Switching tabs unmounts the previous
tab's content (no `forceMount`), so there is no stale in-memory data left behind either.

### §5.3 Record framing (wording)

Every row is a **system-issued tracking record**, not a statement that the employee or movement
has already ended. Copy is written to reflect exactly what `state` means:

- `ACTIONABLE`: "متابعة مستحقة" / "an actionable follow-up" — the end is still ahead; this is a
  heads-up, not a past event.
- `LAPSED`: "انتهى التاريخ المتوقع" / "the expected date has passed" — the record is now
  historical; it does NOT assert the employee's actual situation now (a relationship can have
  since ended, been transferred, etc. — this follow-up table does not re-derive that; §S31.7/§S38
  equally apply only through a later scan).
- `SUPPRESSED`: "تم تعليقها" / "suppressed", with its reason shown as a short, factual phrase
  (§5.5) — framed as "the system recognized this follow-up no longer applies," never as a
  judgment about the employee.

No copy anywhere says "الموظف عاد" / "the employee has returned" or similar — the derived
`state` and the suppression reason are the only facts rendered, exactly as the contract defines
them.

### §5.4 State filter

One `NativeSelect` (reused — no new dependency), options exactly `ACTIONABLE | LAPSED | SUPPRESSED
| ALL`, default `ACTIONABLE`, independent per tab (switching the status tab's filter never affects
the movement tab's, and vice versa — they are two independent pieces of component state). Changing
the filter re-issues the request with the new `state` value and the page reset to 1; the previous
response is discarded (React state is replaced, not merged) so a user can never see a response for
a different filter value than the one currently selected — `useApiResource`'s existing
`attempt`/`depsKey` guard already prevents a late, stale response from a previous filter value from
overwriting a newer one, because its `deps` includes the filter value itself.

### §5.5 Fields shown

Shared columns (both tabs): due date, expected end date, state (as a `StatusBadge`: `warning` for
`ACTIONABLE`, `information` for `LAPSED`, `inactive` for `SUPPRESSED` — reusing the existing
generic status-badge vocabulary rather than inventing new tokens), suppression reason (shown only
when `state === 'SUPPRESSED'`, translated per §2.7, tab-specific), created-at, suppressed-at (shown
only when set), and the relationship reference — labelled "مرجع علاقة العمل" / "Employment
relationship reference", rendered as the raw id in a monospace/LTR span (via the existing `Ltr`
helper, the same one used for every other id-like value in this codebase) — **never** under a
"name" or "employee" column heading, consistent with §4.

Movement tab only: movement type (translated: `FULL_SECONDMENT` → "انتداب كلي" / "Full
secondment", `WORKPLACE_ASSIGNMENT` → "تكليف" / "Workplace assignment", `PARTIAL_SECONDMENT` →
"انتداب جزئي" / "Partial secondment" — reusing the exact Arabic terms already established for Full
Secondment and Workplace Assignment in `Employee360MovementTimeline` (`sourceSecondment`,
`sourceAssignment`), and matching that same "انتداب" root for Partial Secondment for internal
consistency) and the organizational unit reference (same raw-id, labelled, no-name treatment as
the relationship reference — §4).

All dates render through the existing `DateText` component (dd/MM/yyyy, kept LTR) — the same
component used everywhere else in the app, per the S46-approved date-display convention. Payloads
are read and displayed exactly as the API returns them; nothing is reformatted into a different
unit or recomputed client-side (e.g., `state`, `due_date` and `expected_effective_to` are rendered
as given, never recomputed from `created_at`/`suppressed_at` or a client-side date).

### §5.6 Pagination

Simple Prev/Next using `meta.current_page` / `meta.last_page` and a "X–Y of Z" total count from
`meta.total`, `per_page=50` fixed. No new dependency: two existing `Button`s (`variant="outline"`,
the same ones `RetryButton` already uses) suffice; no pagination component exists in the codebase
today and none is added.

### §5.7 Loading / empty / error / retry

Exactly the established `StatePanel` contract (`loading` / `error` with `RetryButton` / the table's
own `emptyText`), identical to every other list in this codebase (`RelationshipsList`,
`DashboardPage`):

- Loading: `StatePanel tone="loading"`.
- 403 (permission not actually granted, including the "migration not applied yet" case from §2.2):
  the existing unauthorized copy (`messages.securityShared.unauthorizedTitle/Description`), no
  retry action — matches `PermissionGate`'s own precedent exactly, since a 403 here is architecturally
  identical to a missing permission anywhere else in the app.
- Any other error (network/timeout/server/client): `StatePanel tone="error"` with
  `describeApiError` and a `RetryButton`.
- Empty result set: `DataTable`'s own built-in `emptyText` row (reused, not a new component),
  wording specific to the active tab and filter (e.g., "لا توجد متابعات مستحقة" for `ACTIONABLE`).

### §5.8 Localization, RTL and viewport

Both locales (Arabic source of truth, English mirror, per `Messages`/`types.ts`'s own enforced
parity) and both desktop and the existing 390px mobile breakpoint are supported using only
existing utilities: `DataTable` already handles horizontal overflow at narrow widths, and no new
breakpoint or layout primitive is introduced.

## §6 Components reused / new files

**Reused as-is, no modification**: `Tabs`/`TabsList`/`TabsTrigger`/`TabsContent`, `DataTable`,
`EmptyState`, `StatusBadge`, `StatePanel`, `RetryButton`, `DateText`, `Ltr`, `NativeSelect`,
`PageHeader`, `useApiResource`, `apiRequest`, `describeApiError`, `useAuth`, `NAV_ITEMS`/
`isNavItemVisible` mechanism, `Button`.

**New files**:
- `frontend/src/features/followUps/api.ts` — `fetchMovementExpiryFollowUps` /
  `fetchEmploymentStatusExpiryFollowUps`, typed response interfaces mirroring §2.6 exactly, and the
  suppression-reason/movement-type union types from §2.3/§2.7.
- `frontend/src/features/followUps/hooks.ts` — `useMovementExpiryFollowUps` /
  `useEmploymentStatusExpiryFollowUps`, each taking `{ state, page, enabled }` and returning
  `useApiResource(... enabled ? fetcher : null, [state, page, enabled])`.
- `frontend/src/features/followUps/MovementFollowUpsTable.tsx` and
  `StatusFollowUpsTable.tsx` — the per-tab list body (filter control + `DataTable` + pagination
  controls + state panels), each independently permission-and-fetch-gated.
- `frontend/src/pages/FollowUpsPage.tsx` — the page shell (tab selection, the "neither permission"
  guard, `PageHeader`).
- Small, additive edits to `frontend/src/app/router.tsx` (one route), `frontend/src/app/
  navigation.ts` (one `NAV_ITEMS` entry), `frontend/src/shared/security/permissions.ts` (two new
  `HR_PERMISSIONS` keys, the exact backend strings from §2.2), and `frontend/src/i18n/messages/
  ar.ts` + `en.ts` (+ nothing in `types.ts`, which derives its shape from `ar.ts` automatically).

No new npm dependency of any kind.

## §7 Verification plan

- Unit/component tests (mock-only, no real data, no POST/PATCH/DELETE in any test): both
  permissions separately; both absent (neither tab, unauthorized panel); active-tab behavior (no
  request fires for the inactive tab); the `state` filter's exact outgoing query string per
  option; empty-result rendering; a simulated read failure plus `RetryButton` recovery; and a
  regression test proving a filter change never renders a response for the previously-selected
  filter value (mirroring `DashboardPage.test.tsx`'s own "exactly ONE request" assertion style).
- `lint` / `typecheck` / affected `vitest` / `build`, run on the real Windows machine in one
  consolidated PowerShell block together with the read-only `php artisan migrate:status` check
  from §2.2 (filtered to the two named migrations) — both Linux-run and Windows-run results, if
  any step had to run on the Linux bridge instead, will be clearly labeled as such in the final
  report; nothing is claimed as Windows-verified unless it actually ran there.
- Visual check in both languages and both viewports where real data availability allows it;
  absence of real `ACTIONABLE`/`SUPPRESSED` rows in the current database is not a reason to
  generate any, per the authorization.

## §8 Explicit boundaries (restated)

Frontend plus this one document only. No Backend, data, or runtime-config change of any kind — in
particular, the organizational-unit-name and employee-name gaps in §4 are documented, not patched
around with a new Backend endpoint. `backend/composer.json` and `_to_delete/` are untouched. No
Git add/commit/push/tag. No Employment Contract or Qualification recording work (explicitly out of
scope for S47, despite having been read during S47's own scope-discovery phase for unrelated
reasons). Closure and Git remain for the final review, per the authorization's own closing line.

## §9 Closure log (S47)

### §9.1 Acceptance decision

This closure reflects the review decision reached in this conversation on 2026-10-06, following
confirmation of the Windows results recorded in §9.2. This log records that decision and the
evidence it rests on; it does not itself authorize any Backend, data, or Git action — those
remain separate, explicit authorizations per the project's standing governance model. This entry
deliberately does not attribute the decision to a named role, and keeps it distinct from whoever
executed the verification commands reported below — both are part of the conversation record
this log summarizes, not an inferred identity.

### §9.2 Windows results

- **Final consolidated run** — `246/246` tests PASS across `22` test files, plus `lint`,
  `typecheck`, and `build` all PASS, on the real Windows machine, ending with the printed marker
  `S47_FINAL_WINDOWS_CHECKS=PASS`. This is the authoritative, most recent Windows result for S47's
  final state, superseding the narrower file-scoped checks below.
- **Prior, separate evidence — `frontend/src/pages/FollowUpsPage.test.tsx`**: `12/12 PASS`,
  from an earlier, broader Windows run that stopped at the `DashboardPage.test.tsx` failure
  recorded below, before that run reached its own `lint` / `typecheck` / `build` steps — not a
  run scoped to this file alone, and not followed by a completed `lint` / `typecheck` / `build`
  pass within that same run. The 12 tests are: 5 permission-gating, 2 state filter, 1
  pagination, 2 stale-response-race (the AbortController cancellation case and the
  resolves-after-cancellation case), and 2 errors/retry.
- **Prior, separate evidence — `frontend/src/pages/DashboardPage.test.tsx`** (not an S47 file;
  S45 Dashboard Foundation): `26/26 PASS`, from an earlier standalone Windows run
  (`npx vitest run src/pages/DashboardPage.test.tsx --reporter=verbose`), kept here as its own,
  separate record rather than folded into the final tally above. This check exists specifically
  to confirm that the shared `frontend/src/shared/hooks/useApiResource.ts` fix made during S47's
  consolidated review (an abort guard added to the `.then` branch, mirroring the pre-existing
  `.catch` guard) — used directly by S45 Dashboard and S18 Employee 360 as well as S47 — did not
  regress a pre-existing test outside S47's own file set. No change was made to
  `DashboardPage.test.tsx` or `DashboardPage.tsx` themselves.
- The `DashboardPage.test.tsx` run that originally surfaced a missing-heading failure (the
  regression investigated earlier in this engagement) did not recur in this or the final run. No
  code change was made in response to that original report, and its cause remains undetermined —
  it is recorded here as an open, unresolved observation, not as something this closure explains
  or attributes to any specific mechanism.

### §9.3 Evidence limits (recorded explicitly, not implied)

- Every `vitest` / `vite build` invocation attempted through the device bridge during S47 failed
  at startup, deterministically, with an environment-only error (`Cannot find native binding ...
  Cannot find module '@rolldown/binding-wasm32-wasi'`), confirmed across multiple independent
  attempts and device reconnections. The bridge was never able to execute a real test run end to
  end. Every result in §9.2 was obtained and reported from the real Windows machine, not
  independently reproduced by Claude.
- `tsc -b --force` and `eslint` were additionally confirmed clean through the device bridge in
  earlier S47 rounds, ahead of the Windows runs in §9.2; `lint` and `typecheck` were later run for
  real on Windows as part of the final consolidated run recorded there.
- The `useApiResource.ts` `.then`-guard fix was additionally proven, independently of any test
  runner, via a standalone dependency-free Node script (`race_logic_check.mjs`, included in the
  delivered S47 packages) that reproduces only the exact `.then` / `.catch` control-flow fragment.
  This is explicitly **not** a substitute for any Windows result in §9.2 — it is narrower,
  supplementary evidence kept for traceability.
- The 390px tab-clipping fix (§5.8) and the §4 limitation wording were verified live against the
  real local dev server via the built-in browser, in both Arabic and English. The follow-ups table
  itself was never visually checked with real, non-empty business data — the database held no
  `ACTIONABLE` / `SUPPRESSED` rows for either follow-up kind throughout S47 — which remains an open
  visual gap, not assumed resolved by any test-count result above.
- No Backend, data, or runtime-configuration change was made at any point during S47.
  `backend/composer.json` and `_to_delete/` remain untouched and are excluded from the S47 closure
  package.
