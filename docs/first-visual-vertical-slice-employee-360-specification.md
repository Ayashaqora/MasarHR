# S18 — First Visual Vertical Slice: Employee 360 Foundation

**Authorization:** ADR-S18-001 — MASARHR — S18 COMPREHENSIVE CONTROLLED EXECUTION — FIRST VISUAL VERTICAL SLICE / EMPLOYEE 360 FOUNDATION
**Executor:** Claude (execution authority for this explicitly authorized scope only)
**Architecture Authority:** relayed by the user
**Baseline:** `develop` @ `cc0387a41fabeb56af80e6e1d41372178fde6370` (tag `s17-temporary-employment-status-periods-foundation`), `origin/main` @ `45259c97ca8dda8101d628bfe20bdb969c807cf4`

---

## §S18.1 Reconstruction disclosure

The historical S18 roadmap wording was **NOT recovered**. The title and scope below are an
Architecture Authority **reconstruction**, not a rediscovered original document:

> S18 — First Visual Vertical Slice — MasarHR Employee 360 Foundation

This is stated once here and is not claimed anywhere in this document, the code, or the final
report to be the original historical S18 text.

## §S18.2 Discovery inventory

### §S18.2.1 Frontend (pre-existing, from an earlier, undocumented stage)

The repository already contained a substantial, frozen frontend foundation before S18 began —
this was not built by S18, only extended by it:

- React 19 + TypeScript 6 + Vite 8, Vitest 5 + Testing Library for tests, ESLint 10 (flat config,
  `react-hooks`/`react-refresh` plugins), `react-router` 8.
- Arabic-first RTL shell (`layouts/AppShell.tsx`) with a working skip link, keyboard-focus-on-
  navigate, an authenticated-account area, and a nav list already including an `employees` item.
- `i18n` module: `ar`/`en` catalogs with a compile-time type (`Messages`) that forces every locale
  to declare the same keys, checked by `i18n/messages/messages.test.ts`. Western digits/Gregorian
  calendar forced via `toIntlLocale`. Arabic is the source of truth.
- `features/auth`: `AuthProvider`/`RequireAuth`/`PermissionGate`, session-cookie + CSRF
  authentication against the S03 backend (`/auth/csrf-cookie`, `/auth/login`, `/auth/me`,
  `/auth/logout`), a UX-only `hasPermission` helper explicitly documented as never authoritative.
- `shared/api`: a single `apiRequest()` client (credentials included, CSRF header on mutating
  requests, normalized `ApiError` with `kind`/`status`/`fieldErrors`), `describeApiError()` mapping
  every error kind to a localized, never-raw-server-text message.
- `shared/ui`: `StatePanel` (loading/success/error, correct `role`s), `PageHeader`.
- Design tokens (`styles/tokens.css`) already frozen to the exact direction ADR-S18-001 §9
  describes: navy/blue family, IBM Plex Sans Arabic, 4px spacing scale, 8px radius, RTL-safe
  logical properties throughout. `styles/components.css` already had `.card`, `.button`,
  `.data-table`, `.state-panel`, `.form-field`, a responsive breakpoint at 860px that collapses the
  sidebar into a horizontal nav.
- `features/security-admin` (S03's own admin UI: Principals/Roles/Permissions tables) was the
  established reference pattern this stage followed for its own read screens: one `useList`-style
  hook per resource, a page wrapped in one outer `PermissionGate`, a `data-table` with `<caption
  className="sr-only">`.
- `test/render.tsx` already provided `stubFetch`/`stubAppFetch`/`renderApp`/`fakeAuthValue` — a
  URL-aware fetch stub used by every existing test file; S18's new tests reuse it unchanged.

Given this, most of ADR-S18-001 §7 (login) and §8 (application shell) were **already satisfied**
before S18 started; S18's own work is the Employees/Employee 360 area itself, plus wiring it into
the existing shell and nav.

### §S18.2.2 Backend API capability matrix

| UI need | Endpoint | Resource fields | Permission | S08 scope | Verdict |
|---|---|---|---|---|---|
| Find an employee | `GET /hr/persons/lookup?national_id=` | `id, national_id, is_terminal, version` | `hr.persons.view` | none (no unit on Person) | **Sufficient**, but exact-match only — see §S18.3 |
| Show a person | `GET /hr/persons/{id}` | same as above | `hr.persons.view` | none | Sufficient |
| List a person's employment relationships | `GET /hr/persons/{id}/employment-relationships` | `id, person_id, employment_type_id, employee_number, employee_number_scheme, effective_from, effective_to, end_knowledge_state, ended_terminally, version` | `hr.employment_relationships.view` | none | Sufficient |
| Employment status history | `GET .../status-periods` | `id, employment_relationship_id, status_detail_id, effective_from, effective_to` | `hr.employment_status_periods.view` | none (S10 spec §13) | Sufficient |
| Original/placement history | `GET .../placement-periods` | `id, employment_relationship_id, organizational_unit_id, effective_from, effective_to` | `hr.organizational_placement_periods.view` | yes, against the current placement's unit | Sufficient |
| Full secondment history | `GET .../full-secondment-periods` | same shape as placement | `hr.full_secondment_periods.view` | yes | Sufficient |
| Workplace assignment history | `GET .../workplace-assignment-periods` | same shape as placement | `hr.workplace_assignment_periods.view` | yes | Sufficient |
| Actual (current) workplace | `GET .../actual-workplace` | `organizational_unit_id, source ('secondment'|'assignment'|'placement'), since` | `hr.full_secondment_periods.view` (route is registered on `FullSecondmentPeriodController`; no separate permission exists) | yes | Sufficient — note the permission-code quirk, not changed by S18 |
| Status detail names | `GET /reference/employment-status-details` | `id, category_id, code, name_ar, name_en, is_active, display_order, version` | `reference.view` | none | Sufficient |
| Organizational unit names | `GET /organization/units/{id}` | `id, parent_id, name, is_active, version` | `organization.view` | none | Sufficient (single-id only, no bulk-by-ids — fine at S18's scale) |
| Employee display **name** | — | — | — | — | **NOT AVAILABLE.** `PersonResource` carries no name field in S09 v1 (its own docblock: "No name/profile fields exist in S09 v1"). Not fabricated. Deferred. |
| Browsable "all employees" list | — | — | — | — | **NOT AVAILABLE.** No list-all-persons endpoint exists. Not added — see §S18.3. |
| Employment type **name** (permanent/contract label) | — | — | — | — | No `/reference/employment-types` route exists. **Substituted**, not fabricated — see §S18.3. |
| A distinct "Transfer" event | — | — | — | — | **NOT AVAILABLE as its own record.** `TransferResult`/`TransferResource` confirm no `hr.transfers` table exists; a transfer is entirely represented by the placement-period boundary it writes. See §S18.3/§S18.4. |

No endpoint required by this slice was missing to the point of blocking it. Every gap above is
either not needed (name) or already representable through an existing, correctly-labeled field
(`employee_number_scheme`) or an existing raw stream (placement/secondment/assignment periods).

## §S18.3 Read-model decision: **no backend changes**

ADR-S18-001 §5 allows S18 to add "the smallest read-only backend composition necessary… if
existing APIs cannot efficiently provide the page," in this explicit preference order: (1) reuse
existing APIs, (2) add a focused read model, (3) add a minimal endpoint. S18 applied that ordering
and stopped at step 1 for every part of the page:

- **Employees search** uses `GET /hr/persons/lookup?national_id=` exactly as it exists. There is
  no partial-name search and no "browse all employees" table, because Person has no name field and
  no list-all-persons endpoint exists — inventing either would be UI-convenience data the domain
  does not have (ADR-S18-001 §6 forbids collapsing/inventing domain concepts), so the Employees
  screen is, deliberately, a single exact-match lookup form, not a first step toward a browser.
- **Employee 360** fetches from six existing endpoints in parallel (person, relationships, status
  periods, placement periods, secondment periods, assignment periods, actual workplace) plus two
  small reference lookups (status-detail catalog, organizational-unit names). This is more network
  round trips than a single composed endpoint would need, but every one of those round trips is
  already permission- and S08-scope-checked correctly on the backend, and the actual-workplace
  precedence decision is never re-derived in React — it is read verbatim from
  `ResolveActualWorkplaceForRelationship`'s existing result (ADR-S18-001 §13's explicit
  instruction). Adding a composed backend endpoint purely to save round trips, with no unresolved
  business rule behind it, was judged not to meet the "if existing APIs cannot efficiently provide
  the page" bar — so no backend read model was added, and no migration, no new PHP class, no new
  permission exists from S18.
- **Employment type display.** `EmploymentRelationshipResource.employment_type_id` cannot be
  resolved to a name — no `/reference/employment-types` API exists, and the create-form's own
  validation (`employment_type_code in [permanent, contract]`) confirms the type set is fixed to
  two values, but there is no way to map a given `employment_type_id` UUID back to one of them via
  any exposed endpoint. Guessing was rejected. Instead, S18 uses `employee_number_scheme` —
  `'PERMANENT'|'CONTRACT'`, a value the resource **already returns directly**, enforced by a
  database CHECK constraint (`database/migrations/2026_09_29_000002_create_hr_employment_relationships_table.php`)
  and recorded at write time "from the resolved ref.employment_types.code" per that migration's own
  comment — so it is not a guess, it is the same fact the backend itself derived, already on the
  wire, only localized for display. This substitution is disclosed here rather than silently made.
- **Movement Timeline / Transfer.** `TransferEmployee` persists no event of its own
  (`docs/transfer-foundation-specification.md` §16, reconfirmed by reading `TransferResult`/
  `TransferResource` in this stage) — a transfer is entirely represented by the S11 placement-period
  boundary it writes (and the S12/S16 period it may close as a consequence). ADR-S18-001 §14 says:
  "If transfer has no dedicated persisted event and must be reconstructed from authoritative
  streams, perform that composition in the backend read model, not by guessing in React." S18's
  decision is **not to attempt that reconstruction at all**: which placement-period boundary was
  caused specifically by a `TransferEmployee` call, versus a direct `RecordOrganizationalPlacementPeriod`
  call, is not recoverable from period data alone (both produce an identical-shaped row), and no
  audit-log read API is exposed to the frontend to disambiguate it. Rather than add a new backend
  composition purely to synthesize a "Transfer" label — which ADR-S18-001 §5 discourages when no
  API-availability blocker forces it, and which risks mislabeling a period that was never actually
  a transfer — the Movement Timeline shows the three real streams (placement/secondment/assignment)
  merged only by date, each under its own real type. A transfer's effect is fully visible (the
  placement-period boundary appears, correctly dated), just not specially flagged as "a transfer
  happened here." This is disclosed as a deferred capability, not hidden.

**Backend Read Model gate: NOT NEEDED.** Zero backend files were changed in S18 (confirmed by the
real-repository diff in §S18.10).

## §S18.4 Information architecture

```
/login                                          (pre-existing S03 screen, reused unmodified)
/                                                (pre-existing home shell)
/employees                                       Employees search screen (new, S18)
/employees/:personId/relationships/:relationshipId   Employee 360 (new, S18)
/organization  /reports  /settings               unchanged placeholders
/security/*                                       unchanged S03 admin area
```

`employees` in `app/navigation.ts` is changed from a bare nav entry to `requiresAuth: true`
(mirrors `security`'s own existing convention exactly) — an unauthenticated visitor no longer sees
a nav link that would only redirect them to `/login` on click.

`/employees` and the Employee 360 route are both wrapped in `<RequireAuth />` at the router level
(one authentication check for the whole area, exactly mirroring the existing `security` subtree's
own shape), then each page additionally wraps its content in one outer `PermissionGate` keyed to
the coarsest permission the page needs (`hr.persons.view` for Employees, `hr.employment_relationships.view`
for Employee 360) — the same one-outer-gate convention `PrincipalsPage`/`RolesPage`/`PermissionsPage`
already use. Frontend permission hiding remains UX only; the backend re-checks every permission and
S08 scope on every request (ADR-S18-001 §17), and this stage adds nothing that weakens that.

### Component map (new files, all under `frontend/src/`)

```
shared/hooks/useApiResource.ts            generic loading/success/error/retry fetch hook
shared/security/permissions.ts (extended) HR_PERMISSIONS constants mirroring the backend catalog
features/employees/api.ts                 typed fetch functions for every endpoint in §S18.2.2
features/employees/hooks.ts               usePersonSearch, usePerson, useEmploymentRelationships,
                                           useStatusPeriods, usePlacementPeriods,
                                           useFullSecondmentPeriods, useWorkplaceAssignmentPeriods,
                                           useActualWorkplace, useEmploymentStatusDetailCatalog,
                                           useOrganizationalUnitNames
features/employees/EmployeeSearchForm.tsx
features/employees/RelationshipsList.tsx
features/employees/Employee360Header.tsx
features/employees/Employee360Overview.tsx
features/employees/Employee360Employment.tsx
features/employees/Employee360Workplace.tsx
features/employees/Employee360StatusHistory.tsx
features/employees/Employee360MovementTimeline.tsx
pages/EmployeesPage.tsx
pages/Employee360Page.tsx
```

Changed pre-existing files: `app/router.tsx`, `app/navigation.ts`, `app/App.test.tsx` (updated the
one existing test that asserted `employees` was a placeholder — see §S18.9), `i18n/messages/ar.ts`
+ `en.ts` (new `employees`/`employee360` sections), `shared/security/permissions.ts` (added
`HR_PERMISSIONS`), `styles/components.css` (added `.description-list`, `.tab-list`/`.tab-button`,
`.tab-panel-content`; no existing rule was changed).

## §S18.5 Employee 360 data contract

Header (always visible) + five tabs, exactly ADR-S18-001 §12's A–E list:

| Tab | Content | Backed by |
|---|---|---|
| Overview | current open status period + original vs. actual workplace, each with its date | status-periods, actual-workplace, placement-periods (min date) |
| Employment | full Employment Relationship record (employee number, scheme, start/end, end-knowledge-state, ended-terminally) | the relationship object itself |
| Workplace | original vs. actual workplace + full placement-period history table | placement-periods, actual-workplace |
| Status History | full status-period table, open period shown as "ongoing" | status-periods, employment-status-detail catalog |
| Movement Timeline | placement + secondment + assignment periods merged by date, each under its own real type (never a synthesized "transfer") | all three period streams |

No field is fabricated. Fields intentionally never shown, because they do not exist in the domain
or would need a business decision S18 is not authorized to make: employee name, profile photo,
email, phone, address, manager, salary, any dashboard-style aggregate metric.

## §S18.6 PII

National ID is shown only on the single record a user explicitly looked it up for (the search
result / the resulting Employee 360 page) — never in a table row alongside other records, and
never logged to the console or written to `localStorage`/`sessionStorage` (no browser storage is
used anywhere in this stage's code, per the in-conversation-preview restriction and, separately, as
a deliberate choice even for the real deployed app). No analytics/tracking was added. Server error
bodies are never rendered verbatim (`describeApiError`, reused unchanged, already guarantees this).

## §S18.7 Authorization model

Every read call is protected exactly as the backend already enforces it (§S18.2.2's permission/
scope columns) — this stage adds no new permission and changes no route. Each of the five tabs
independently surfaces its own 401/403/404/network/timeout state (`ApiError.status` checked per
section; a 403 shows the existing `securityShared.unauthorizedTitle/Description` strings rather
than a generic failure message), so one out-of-scope resource (e.g. no `hr.full_secondment_periods.view`)
degrades only that tab/section, never the whole page — verified in
`Employee360Page.test.tsx`'s "shows an unauthorized state for a section… without blanking the rest
of the page" test.

## §S18.8 RTL / accessibility / responsive

No new CSS introduces a physical (`left`/`right`/`margin-left`, etc.) property — every new rule
uses the same logical-property convention (`inset-inline-start`, `padding-block-end`, `border-inline-start`)
the pre-existing stylesheet already established, so the new screens mirror correctly in RTL for
free. The tab switcher uses `role="tablist"`/`role="tab"`/`aria-selected`/`aria-controls` and a
single `role="tabpanel"` with `aria-labelledby`, keyboard-operable buttons (native `<button>`, no
custom key handling needed since only one tab is ever focused-then-activated per click — a fuller
arrow-key roving-tabindex pattern was judged unnecessary scope creep for a first slice and is noted
as a deferred refinement). Every table has a `<caption className="sr-only">`. Every data-bearing
screen goes through the existing `StatePanel` (`role="alert"|"status"`, `aria-busy` while loading).
`.data-table` already had a horizontal-scroll fallback under 860px; nothing new was needed.

## §S18.9 Test strategy

Frontend (Vitest + Testing Library, run against the existing `test/render.tsx` stub harness,
**no mock/fake HR data survives into the shipped source** — all fixtures live only in `*.test.tsx`
files):

- `EmployeesPage.test.tsx` — empty state before search, validation on empty submit, successful
  lookup → relationship list → link to Employee 360, 404 ("not found") vs. 500 (generic, server
  text never leaked) distinction, no browsable table ever renders, unauthorized state without
  `hr.persons.view`.
- `Employee360Page.test.tsx` — header renders resolved status/workplace names; Overview tab default
  content; Status History tab; Movement Timeline tab (asserts the placement period's real label
  appears and that no "transfer" text is ever rendered); Employment tab; a 403 on one period stream
  degrades only that tab while the rest of the timeline still renders; not-found for an unknown
  relationship id and for an unknown person id; no write control of any kind is ever rendered.
- `app/App.test.tsx` — updated: the nav-item list assertion for an unauthenticated visitor now
  excludes `employees` (it requires auth like `security` does), the "placeholder" navigation test
  now exercises `organization` (still a real placeholder) instead of `employees`, and a new test
  confirms `/employees` redirects an unauthenticated visitor to `/login` and hides the nav link.
- `i18n/messages/messages.test.ts` (pre-existing, unmodified) — re-verified that `en` mirrors every
  `ar` key and that no message string is empty; this passes for the new `employees`/`employee360`
  sections without any change to that test file.

Results: **59/59 frontend tests pass** (12 files: 3 new/changed, 9 pre-existing and untouched in
behavior). TypeScript typecheck: clean. ESLint: clean (0 errors — one `react-hooks/set-state-in-effect`
finding in the new generic hook was fixed during this stage, not deferred). Production build:
succeeds (`vite build`, 150 modules).

Backend: **zero files changed**, so no new backend test was needed or added. The full existing
backend suite was re-run as a baseline reconfirmation, not because S18 touched it:
**1256/1256 tests pass, 4048 assertions**, exactly the S17 baseline. Pint: passed.
`git diff --check` on the real repository's staged S18 changes: clean, 22 files, all under
`frontend/`, zero backend files, zero migrations, `_to_delete/` untouched.

## §S18.10 Visual runtime verification — **BLOCKED, environmental, not code**

ADR-S18-001 §31 makes this gate mandatory: actually start the real backend and frontend and verify
in a browser. This session attempted it and could not, for a reason specific to the tools
available in this session, not a defect in the code above:

- The cloud container running this session's own shell has PHP 8.3 and PostgreSQL 16 (used for the
  backend regression in §S18.9), but nothing in that container is reachable by any browser this
  session can drive — it is a private sandbox with no exposed port.
- The linked device's separate Linux VM (reached through `device_bash`, used for every frontend
  command in §S18.9) has Node 22 but **no PHP, no Composer, and no reachable PostgreSQL**
  (`127.0.0.1:5432` refuses connections in that VM — nothing has ever been started there). `apt`/
  `apt-get` cannot install anything (no write permission to `/var/lib/apt`), and this VM's outbound
  network is proxy-restricted to a short allowlist (`registry.npmjs.org`, `github.com` answered;
  `packagist.org`, `deb.nodesource.com`, `ppa.launchpadcontent.net` were all refused with HTTP 403
  from the proxy) — there is no path to install PHP/Composer/PostgreSQL there either.
- Per this project's own frozen rule, PostgreSQL is mandatory and SQLite is never substituted, so a
  quick SQLite-backed demo was not attempted as a workaround.

The result: **no tool available to this session can run the real Laravel backend and have it be
reachable by a browser at the same time.** This is reported plainly rather than worked around —
consistent with this project's standing discipline (the same disclosure standard applied to the
S16/S17 git-push credential gap).

This does **not** block the user from running the mandatory verification themselves, on their own
native Windows + PHP + PostgreSQL environment, using the exact commands in §S18.11 below — the code
itself has been fully typechecked, linted, unit/integration-tested against a realistic mocked
backend, and built for production; only the "open it in an actual browser" step could not be
performed by this session.

**FIRST VISUAL RUN READY = NO** (blocked on §S18.10, not on any other gate). Per ADR-S18-001 §33
("If any gate fails: STOP"), git finalization (§35–§37) is **not performed** in this pass. See the
final report for what happens next.

## §S18.11 Exact local commands (for the user to run the deferred verification)

From `C:\Projects\MasarHR` on the user's own machine, in two terminals:

```powershell
cd backend
php artisan serve                     # http://127.0.0.1:8000
```

```powershell
cd frontend
npm install                           # only if node_modules is stale/missing
npm run dev                           # http://localhost:5173 — /api proxied to :8000
```

If no security administrator exists yet on that machine's local `masarhr` database:

```powershell
cd backend
php artisan masar:security:bootstrap-admin
```

(interactive; prompts for username/display name/password — nothing is printed or logged, per its
own existing behavior, unchanged by S18).

To see a non-empty Employee 360 page, at least one synthetic Person + Employment Relationship must
exist in that local database — S18 added no seeder for this (ADR-S18-001 §26: only a documented
blocker, never real or production-looking data). The safest existing mechanism is `php artisan
tinker` against the local `masarhr` database, calling the same `CreatePerson`/
`CreateEmploymentRelationship`/`RecordOrganizationalPlacementPeriod`/`RecordEmploymentStatusPeriod`
commands the test suite already uses, with an obviously-synthetic national ID (e.g. `000000000`)
— never real HR data, per this repository's own standing rule.

Then open `http://localhost:5173/employees`, sign in, and search that synthetic national ID.

## §S18.12 Deferred / out of scope (not implemented, and not silently assumed)

- Employee display name (no such field exists in the domain yet).
- A browsable "all employees" listing (no such endpoint exists; not added).
- A distinct "Transfer" movement-timeline entry type (§S18.3).
- Any write workflow (transfer, secondment, assignment, status transition, employment
  termination) — ADR-S18-001 §16/§25 explicit boundary; none of the existing backend commands for
  these are wired to any UI control in S18.
- A dashboard/landing analytics page — ADR-S18-001 §24 explicit boundary; the post-login landing
  route remains the existing minimal home shell.
- Full arrow-key roving-tabindex keyboard navigation between tabs (native per-button focus/click
  works; the fuller ARIA tabs keyboard pattern is a refinement, not a blocker).
- Live browser-based visual runtime verification (§S18.10 — environmental, not a scope choice).

## §S18.13 Acceptance criteria

| Criterion | Status |
|---|---|
| Real backend APIs only, no mock data in shipped source | PASS |
| Original vs. actual workplace visually distinct, actual workplace never recomputed in React | PASS |
| Employment status separate from placement/secondment/assignment, never collapsed | PASS |
| Read-only; no write controls | PASS |
| RTL-safe, no fabricated dashboard/KPI | PASS |
| Deep-linking works from route params alone | PASS |
| Frontend tests/typecheck/lint/build | PASS |
| Backend regression (baseline reconfirmation) | PASS |
| Backend read model | NOT NEEDED |
| Visual runtime verification | **BLOCKED — environmental, see §S18.10** |
| No unrelated files changed, `_to_delete/` untouched, no S19 work | PASS |

S19 is not opened.

## §S18.14 Independent adversarial review (ADR-S18-001 §32) and post-review fixes

A dispatched, isolated review (a fresh agent with no prior context, instructed to verify every
claim against the real source files rather than trust this specification, and to empirically run
the test suite in a disposable scratch copy) checked 17 attack vectors against the S18 diff.
Result: 14 clean, 1 BLOCKING finding, 2 non-blocking findings.

**BLOCKING — unbounded refetch loop.** `useApiResource`'s effect depended on
`[attempt, fetcher, ...deps]`; every caller passes a freshly-constructed, unmemoized fetcher
function on each render, so a completed fetch's own re-render produced a new `fetcher` reference,
re-firing the effect indefinitely. The review proved this empirically: 63 duplicate GET requests
to one endpoint within 850ms. **Fix:** `fetcher` removed from the dependency array — the values
that matter are already carried explicitly through `deps`.

**Non-blocking #1 — null-date sort order.** A period with a missing `effective_from` sorted as the
earliest ("original") placement instead of last. **Fix:** an explicit `UNKNOWN_DATE_SORTS_LAST`
sentinel added to the original-placement sort comparator in both `Employee360Overview.tsx` and
`Employee360Workplace.tsx`.

**Non-blocking #2 — dangling `aria-controls`.** Only the active tabpanel was mounted, so an
inactive tab button's `aria-controls` referenced a DOM id that did not exist yet. **Fix:** all five
tabpanels are now mounted unconditionally, with visibility toggled by the native `hidden`
attribute instead of conditional rendering. Every S18 data hook was already called unconditionally
regardless of the active tab, so this changes only DOM node count, not data-fetching behavior.

**Regression discovered while re-verifying these three fixes.** Spreading `deps` directly into
`useEffect`'s own dependency array (`[attempt, ...deps]`) is invalid once a caller's `deps` array
can change LENGTH between renders — which `useOrganizationalUnitNames`'s `uniqueIds` does, growing
from 0 to however many distinct organizational-unit ids the other S18 resources resolve to. React
warned ("The final argument passed to useEffect changed size between renders") and 4 of the 9
`Employee360Page.test.tsx` tests failed deterministically, stuck in a perpetual loading state.
**Fix:** `deps` is now serialized to a single, fixed-shape string key (`JSON.stringify(deps)`)
before it enters `useEffect`'s own array, so that array is always exactly two elements
(`[attempt, depsKey]`) regardless of how many entries the caller's `deps` itself has.

**One further, unrelated adjustment.** `Employee360Page.test.tsx`'s header assertions (previously
an unscoped `screen.getByText('EMP-001')`) had to be scoped to the header `<section>` once all five
tabpanels became permanently mounted, since the Employment tab also displays the employee number
and the unscoped query then matched it twice.

**Result after all of the above.** The full gate sequence was rerun from a clean state and is
green: **59/59 frontend tests**, ESLint clean, TypeScript typecheck clean, production build
succeeds. Backend is unaffected (zero backend files in the diff), so the S17 baseline (1256/1256,
Pint clean) stands unchanged. `git diff --check`: clean — the same 22 frontend-only files §S18.9
already reports; no file was added or removed by these fixes. §S18.10's environmental blocker on
live visual verification is unchanged by any of this — it was, and remains, unrelated to code
correctness.
