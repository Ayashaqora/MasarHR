# Security & access foundation (S03)

## 1. Principal ≠ Employee

`security.principals` is a security/authentication identity — a login. It is **not** an HR
employee record and never becomes a prerequisite for one. A principal has no national ID, no
employment relationship, no job title, and no organizational placement. HR persons/employees,
employment relationships and organizational hierarchy belong to later, separately authorized
stages (S07 owns organization hierarchy, S08 owns organization scope; the HR person/employee
domain itself is a later stage again). S03 creates no HR business table, no `organization_units`
table, and attaches no organizational-scope semantics to a principal.

The reverse boundary matters just as much: no S03 workflow requires an employee record to exist
before a principal can log in, and no S03 table references an employee/person table.

## 2. Security schema ownership

Schema `security` (created empty by S02) is owned by S03 and holds exactly six tables:

| Table | Purpose |
| --- | --- |
| `security.principals` | Login identities |
| `security.credentials` | Password (and, later, other credential types) per principal |
| `security.roles` | Named, programmatic-`code` roles |
| `security.permissions` | Fixed catalog of permission codes |
| `security.principal_roles` | Principal ↔ role assignments |
| `security.role_permissions` | Role ↔ permission grants |

All six follow S02's conventions: UUID v7 primary keys generated application-side, real foreign
keys, real `UNIQUE`/`CHECK` constraints, `timestamptz` columns, no unsafe `CASCADE` cleanup, and
migrations that roll back without silently destroying unrelated data (see
[database-persistence-foundation.md](database-persistence-foundation.md)). Laravel's own deferred
`users`/`sessions`/`cache`/`jobs` skeleton (`database/migrations/_deferred_framework/`) stays
deferred and is never activated — the two schemas never merge.

## 3. Principal (`security.principals`)

`id` (UUID), `username` (as typed), `username_normalized` (trim + lowercase, one implementation —
`App\Modules\Security\Domain\UsernameNormalizer`), `display_name`, `status` (`ACTIVE`|`DISABLED`),
`version` (optimistic-concurrency counter, `>= 1`), `created_at`/`updated_at` (`timestamptz`).

`username_normalized` carries the `UNIQUE` constraint, so `Admin` and `admin` can never become two
principals — the constraint, not an application check, is what a concurrent race actually hits
(see `PrincipalTest::test_a_concurrent_duplicate_username_insert_is_rejected_by_the_database_not_just_the_application`).
Changing a username or display name, and changing status, are separate, explicit commands, never
an implicit side effect of another action. There is no hard-delete workflow and no `DELETE`
route: a principal that should stop being usable is set `DISABLED`, never removed. Default
authorization is deny — a principal starts with zero role assignments and zero effective
permissions.

## 4. Credentials (`security.credentials`)

`credential_type` is `PASSWORD` only in S03 (the column exists so a future stage can add another
type without a schema change). `UNIQUE(principal_id, credential_type)` — one password credential
per principal. Passwords are hashed with Laravel's `Hash` facade using **argon2id**
(`backend/config/hashing.php`), chosen specifically over the framework default (bcrypt) because
bcrypt silently truncates at 72 bytes and the S03 authorization requires long passphrases to work
without silent truncation. `password_hash` is never selected into an API resource, logged, or
included in an exception message — see [§9](#9-what-is-never-exposed). Plaintext passwords exist
only as a local PHP variable for the duration of a request/command and are `unset()` explicitly in
the bootstrap command once no longer needed.

Password strength is centralized in `App\Modules\Security\Domain\PasswordPolicy`
(`MIN_LENGTH`/`MAX_LENGTH`, reused by every entry point that sets or changes a password: initial
set, self-service change, administrative reset, and the bootstrap command). There is no periodic
forced rotation.

## 5. RBAC model

Roles (`security.roles`) have a stable, programmatic `code` (never the display name) plus
Arabic/English display names, `is_system`, `is_active`, and their own `version` counter. An
inactive role contributes **no** effective permission to anyone holding it — deactivating a role
is a real, immediate authorization change, not a cosmetic flag.

Permissions (`security.permissions`) are a fixed S03 baseline, seeded by migration
`2026_09_23_000007_seed_security_baseline_permissions` and mirrored in
`App\Modules\Security\Infrastructure\Authorization\PermissionCatalog` for application code:

```
security.users.view
security.users.create
security.users.update
security.users.status.manage
security.roles.view
security.roles.manage
security.role_assignments.manage
security.permissions.view
```

No `employees.*`, `contracts.*`, `reports.*`, `imports.*` or `organization.*` permission exists
yet — those belong to the stage that owns that module.

**Effective permissions** = the union of the permissions of every **active** role assigned to a
principal (`App\Modules\Security\Infrastructure\Authorization\EffectivePermissionsResolver`). No
matching permission anywhere in that union is DENY — there is no separate "deny" rule to layer on
top, and authorization code never branches on a role's identity (`if role == 'admin'`); every
protected operation names the exact permission code it requires.

## 6. Authentication: browser session + secure cookie

S03 uses Laravel's own first-party session-cookie SPA architecture — the `laravel/sanctum`
package itself is not vendored in this environment (unreachable Packagist mirror), so the
equivalent behavior is built directly on Laravel's stock `'web'` middleware group (encrypted
cookies, session, CSRF, bound-model substitution) rather than the stateless `'api'` group used by
`/health`. This is deliberately **not** JWT and **never** stores a token in `localStorage` —
`credentials: 'include'` on every frontend request is what carries the session.

Endpoints (`routes/api.php`, all under `/api/v1/auth/`):

| Method & path | Purpose |
| --- | --- |
| `GET /auth/csrf-cookie` | Primes the `XSRF-TOKEN` cookie (see [§8](#8-csrf)) |
| `POST /auth/login` | Authenticate; throttled (`throttle:login`) |
| `POST /auth/logout` | Invalidate the session |
| `GET /auth/me` | Current principal + roles + effective permissions |
| `PUT /auth/password` | Self-service password change |

Login (`App\Modules\Security\Application\Authentication\AuthenticateWithPassword`,
`LoginController`): normalize the submitted username → look up the principal → verify the
password credential → verify `status = ACTIVE` → regenerate the session ID → establish the
authenticated principal. Every rejection reason — unknown username, wrong password, or a
`DISABLED` principal — throws the same `InvalidCredentialsException`, rendered as the same
`401 {"message": "Invalid credentials."}`, so the response cannot be used to enumerate which
usernames exist or which accounts are disabled. `Hash::check()` still runs (against a fixed dummy
hash when no credential exists) even for an unknown username, so the response time does not leak
that distinction either.

## 7. Session security

- `session()->regenerate()` runs **before** `Auth::login()` on a successful login (fixation
  protection), and `session()->invalidate()` + `regenerateToken()` run on logout.
- `App\Modules\Security\Presentation\Http\Middleware\EnsurePrincipalIsActive` runs on every
  protected request (not only at login) and re-checks `status = ACTIVE` against the database each
  time; a session that was valid when it was created but whose principal has since been `DISABLED`
  is invalidated and rejected with `401` the next time it is used — a stale session never grants
  permanent access.
- Cookie configuration (`backend/config/session.php`) is environment-appropriate (`secure`,
  `http_only`, `same_site`), and no response ever includes a session ID, cookie value, or CSRF
  secret in its body.

## 8. CSRF

Laravel's own CSRF middleware (`PreventRequestForgery`, the `'web'` group's replacement for the
legacy `VerifyCsrfToken`) protects every state-changing request in the `'web'` group using the
`Sec-Fetch-Site` header with a token fallback. It only attaches the `XSRF-TOKEN` cookie to a
response that completes successfully — an unauthenticated request that throws (e.g. a `401`)
never reaches that point — so `GET /auth/csrf-cookie` exists purely to guarantee a
successfully-completing response the frontend can call once, at startup, before it needs to send
the token back on `POST`/`PUT`/`PATCH`/`DELETE` (see [§20](#20-frontend-security-foundation)
below). Mirroring how Sanctum's own `/sanctum/csrf-cookie` is conventionally used, this keeps the
same "prime, then mutate" shape without depending on the package itself.

## 9. What is never exposed

No API resource, log line, or exception message ever includes: `password_hash`,
`password_changed_at` (excluded from resources though not itself secret), a session identifier, a
CSRF secret, or a raw SQL/SQLSTATE fragment. `bootstrap/app.php` routes ten expected domain
exceptions (invalid credentials, stale version, last-administrator, duplicate username/role/grant,
etc.) through `dontReport()` so a mistyped password does not get logged at `ERROR` level with a
stack trace on every attempt — they are still rendered to the client with the correct status code
via `render()` callbacks, only server-side log noise is suppressed. `APP_DEBUG=false` (the
project's existing default) already suppresses trace/SQL detail on every other exception.

`GET /auth/me` returns exactly `principal{id, username, display_name}`,
`roles{id, code, name_ar, name_en, is_active}[]`, `permissions: string[]` — never more.

## 10. Authorization: RBAC middleware

`App\Modules\Security\Presentation\Http\Middleware\RequirePermission` guards every Security
administration route with the specific permission code that route needs
(`->middleware('permission:security.users.view')`, etc. — see `routes/api.php`). It returns `401`
if there is no authenticated principal and `403` if the principal is authenticated but lacks the
permission — authentication and authorization are two different questions, and a route never
infers authorization from authentication alone. There is no generic `PATCH` endpoint and no
generic table-CRUD API: every write route maps 1:1 to one named Application command from the list
in [§13](#13-application-commands).

## 11. Self-elevation protection

There is no "edit my own profile" endpoint that can also change roles, permissions, or account
status — `PUT /auth/password` (self-service password change) touches only the credential, and
every route that touches roles, permissions, or `security.users.status.manage`-gated status
changes is a distinct, separately-permissioned Security-administration route. Every command's
constructor/handle signature takes named, typed scalar arguments — never a raw request payload —
so a client cannot smuggle a privileged field (like `is_system` or `status`) into a request meant
for something else and have it mass-assigned through.

## 12. Last-security-administrator invariant

"Capable of security administration" is defined, testably, as holding **both**
`security.users.status.manage` and `security.role_assignments.manage` in effective permissions
(`App\Modules\Security\Domain\SecurityAdministrationCapability`) — deliberately not `role ==
SUPER_ADMIN`, and deliberately the minimal pair needed to recover from a lockout (reactivate any
account, reshape any role assignment).

Four operations can reduce this capability: disabling a principal, removing a role assignment,
deactivating a role, and revoking a permission from a role. Each one runs through
`App\Modules\Security\Application\Security\SecurityAdministrationGuard::run()`, which wraps the
whole operation in a database transaction and takes a PostgreSQL `pg_advisory_xact_lock` (a
single, well-known lock key for "security administration capability") before performing the
change and re-checking, inside the same transaction, that at least one `ACTIVE` principal is still
capable. The advisory lock serializes concurrent administration attempts against each other — two
requests racing to remove "the other" administrator cannot both observe "someone else is still
capable" and proceed; the second one to acquire the lock re-evaluates against the first one's
already-committed change. If the check fails, `LastSecurityAdministratorException` is thrown, the
whole transaction rolls back, and the client receives `409`.

## 13. Application commands

Every S03 write is one of exactly these commands (`App\Modules\Security\Application\Commands\*`,
plus `AuthenticateWithPassword` in `Application\Authentication`):

```
CreatePrincipal, ChangePrincipalUsername, ChangePrincipalDisplayName, ChangePrincipalStatus,
SetInitialPassword, ChangePassword, ResetPasswordAdministratively,
CreateRole, UpdateRoleMetadata, ActivateRole, DeactivateRole,
AssignRoleToPrincipal, RemoveRoleFromPrincipal,
GrantPermissionToRole, RevokePermissionFromRole
```

Write flow: authenticate → authorize (route middleware) → validate (form request rules) → load
current state → concurrency check → security invariants → transaction → persist. Nothing here
implements S04's audit/command-log infrastructure (see [§15](#15-s04-boundary)) — the flow simply
stops after persisting.

## 14. Concurrency

`ChangePrincipalUsername`, `ChangePrincipalDisplayName`, `ChangePrincipalStatus`,
`UpdateRoleMetadata`, `ActivateRole` and `DeactivateRole` all take an `expected_version` and apply
a conditional `UPDATE ... WHERE id = ? AND version = ?`. Zero affected rows means either the row
no longer exists (checked separately, `404`) or the version was stale
(`App\Modules\Security\Domain\Exceptions\StaleVersionException`, mapped to `409`) — the same
optimistic-concurrency shape S02 already established, not a second architecture.

## 15. S04 boundary

S04 owns the complete Audit & Command Infrastructure. S03 does not create a permanent audit log,
an event-sourcing table, or a command-log table — none of `audit.*` is populated by S03. Where a
future audit trail will eventually need a hook (who performed a role assignment, for instance),
S03 stores only what its own tables already need for that purpose (e.g.
`security.principal_roles.assigned_by`) and leaves the rest to S04.

## 16. S07/S08 boundary

S07 owns organization hierarchy; S08 owns organization scope enforcement. S03 creates no
`organization_units` table, no organizational-scope column on `security.principals`, and no
branch/unit visibility rule. `RequirePermission` and `EffectivePermissionsResolver` are reusable
authorization building blocks a later stage can build organization-scoped authorization on top
of, but S03 itself never infers or enforces organizational scope.

## 17. Bootstrap: the first administrator

There is no public registration endpoint (`POST /auth/register` does not exist, and never will
under this architecture). The only way the system gets its first security-administration-capable
principal is:

```powershell
cd backend
php artisan masar:security:bootstrap-admin
```

The command interactively prompts for a username, a display name, and a password (entered twice,
hidden input via `$this->secret()`, never accepted as a command-line argument so it never lands in
shell history). It:

- refuses immediately — before asking anything — if a security-administration-capable `ACTIVE`
  principal already exists (idempotent/safe against an accidental second run; §BOOT-06/07);
- creates the `SECURITY_ADMINISTRATOR` system role (if it does not already exist), grants it every
  baseline permission, creates the principal, sets its password, and assigns the role — all inside
  **one** database transaction (§BOOT-04/05);
- never prints, logs, or persists the plaintext password anywhere but the Argon2id hash
  (§BOOT-03), and the local variable holding it is explicitly `unset()` once no longer needed;
- ships with no default password and no credential anywhere in the repository (§BOOT-01/02).

Every administrator after the first is created through the normal Security Administration API by
someone who already holds `security.users.create`/`security.role_assignments.manage` (§BOOT-08) —
the bootstrap command is not a general-purpose account-creation tool.

## 18. Security administration API

Base path `/api/v1/security/...` (also behind the `'web'` group, `auth:web` + `principal.active` +
the specific `permission:` for each route — see `routes/api.php`):

| Resource | Reads | Writes |
| --- | --- | --- |
| Principals | `GET /principals`, `GET /principals/{id}`, `GET /principals/{id}/permissions` | `POST /principals`, `PATCH /principals/{id}/username`, `PATCH /principals/{id}/display-name`, `PUT /principals/{id}/password`, `PATCH /principals/{id}/status` |
| Role assignments | — | `POST /principals/{id}/roles`, `DELETE /principals/{id}/roles/{role}` |
| Roles | `GET /roles`, `GET /roles/{id}` | `POST /roles`, `PATCH /roles/{id}`, `POST /roles/{id}/activate`, `POST /roles/{id}/deactivate` |
| Role permissions | — | `POST /roles/{id}/permissions`, `DELETE /roles/{id}/permissions/{permission}` |
| Permissions | `GET /permissions` | — (read-only in S03; the catalog is fixed by migration) |

None of these expose a database schema name, table name, or column name in a response — every
resource is a hand-written `JsonResource` with an explicit field list.

## 19. HTTP semantics

`401` — no authenticated principal, or credentials rejected. `403` — authenticated but the
specific permission is missing. `409` — a concurrency conflict (`StaleVersionException`) or a
security-state conflict (`LastSecurityAdministratorException`, a duplicate role
assignment/permission grant, an existing credential where one is not expected). `422` — validation
failure (a duplicate username/role code, an incorrect current password, a password that violates
policy). `429` — login rate limit exceeded (`throttle:login`, keyed by normalized username + IP so
one attacker IP cannot lock out a legitimate user, and one leaked credential list cannot be
sprayed unthrottled from a single IP). No production response ever includes a stack trace,
SQLSTATE code, raw SQL, password hash, credential value, or session/cookie secret.

## 20. Frontend security foundation

The frontend implements only what S03 needs (`frontend/src/features/auth/`,
`frontend/src/features/security-admin/`) — no Employee, Organization, Reports, or HR dashboard UI.

- **Session bootstrap** (`AuthProvider`): on app load, primes the CSRF cookie
  (`GET /auth/csrf-cookie`) then calls `GET /auth/me`; a `401` there is treated as "not signed in"
  (`status: 'unauthenticated'`), not an error. `status` starts at `'bootstrapping'` so protected UI
  never has to guess between "known unauthenticated" and "not checked yet".
- **Login** (`/login`, `LoginForm`): on success, navigates back to whatever route the visitor
  originally requested (`RequireAuth` records it before redirecting to `/login`). On failure, shows
  one generic, localized message regardless of whether the username or the password was wrong
  (never the raw server text) — the same enumeration-resistance property the backend response
  already has.
- **Logout**: calls `POST /auth/logout`, then always resets local auth state to unauthenticated
  even if the request itself fails.
- **Current principal state**: exposed everywhere via `useAuth()` (`AuthContext`) — `status`,
  `principal`, `roles`, `permissions`, `hasPermission(code)`.
- **Route guard** (`RequireAuth`): gates the whole `/security` section on authentication only. It
  shows a loading panel while bootstrapping, and redirects to `/login` once the app knows the
  visitor is not signed in.
- **Permission-aware UX** (`PermissionGate`): each Security page (`/security/principals`,
  `/security/roles`, `/security/permissions`) additionally gates its own content on the specific
  permission it needs, showing an accessible "you do not have access" panel instead of the data
  when it is missing. This is explicitly **UX only** — every request the page makes is still
  re-checked by the backend's own `RequirePermission` middleware regardless of what the frontend
  decided to show, and a direct API call bypasses the frontend entirely (§SEC-04/SEC-12).
- **Session-expired handling**: any Security-admin write that comes back `401` (e.g. the account
  was disabled mid-session) calls `sessionExpired()`, which flips the app straight to the
  unauthenticated state so the next render redirects to `/login`, instead of leaving a broken
  authenticated-looking screen up until the next full reload.
- **CSRF/session transport** (`shared/api/client.ts`): every request sends `credentials:
  'include'`, and every mutating request (`POST`/`PUT`/`PATCH`/`DELETE`) echoes the `XSRF-TOKEN`
  cookie back as an `X-XSRF-TOKEN` header — this is the only place in the frontend that knows about
  CSRF or cookies; feature code never touches either directly.
- Arabic-first/RTL, Western digits, and English-readiness (already established by S01) are
  unchanged and apply to every S03 screen — see `frontend/src/i18n/messages/{ar,en}.ts`.

## 21. Test-database safety

All S03 automated tests run through the same guarded `masarhr_test` connection S02 established
(`Tests\Support\TestDatabaseGuard`); no S03 test loosens that guard, and no S03 test uses SQLite.

## 22. Running the checks

| Task | Command |
| --- | --- |
| Backend tests (full suite, S02 + S03) | `cd backend && composer test` |
| Backend style check | `cd backend && composer lint` |
| Frontend tests | `cd frontend && npm test` |
| Frontend typecheck | `cd frontend && npm run typecheck` |
| Frontend lint | `cd frontend && npm run lint` |
| Frontend production build | `cd frontend && npm run build` |
| Bootstrap the first administrator (local dev) | `cd backend && php artisan masar:security:bootstrap-admin` |
