# Audit & command infrastructure specification (S04)

**Status:** Specification v1.0 — pre-implementation. This document is a specification only; S04
has not been implemented. No migration, model, controller, or other production code described
here exists yet. It supersedes nothing already built; it defines what S04 implementation will
build, on top of the S03 baseline (`develop` = `origin/develop` = tag `s03-security-access` =
`9141121a8607806364bb091600f4730a7a4a9dc7`).

This document resolves Architecture Authority decisions D1–D13 and specification questions
Q1–Q15 issued for S04. It does not freeze itself — freeze is an Architecture Authority action
taken after review (see [§28](#28-freeze-readiness)).

---

## 1. Purpose

S03 introduced principals, credentials, roles and permissions, and the session/RBAC/last-
administrator machinery that protects them — but no S03 write, successful or rejected, leaves any
trace beyond `updated_at`/`version` on the row itself (confirmed by the S04 Architecture
Discovery Report, §6: zero logging, event, or audit infrastructure exists in the repository).

S04 exists to close that gap generically, once, rather than per-module later: a `CommandContext`
that carries *who*/*what triggered this*/*how do requests correlate* independent of HTTP: an
audit trail that durably and immutably records what committed and, separately, what was rejected
and why; and a transaction-boundary contract that guarantees a committed mutation and its audit
record can never disagree with each other. S04 is infrastructure — it retrofits every S03 security
mutation as its first (and, at this stage, only) consumer, but it is deliberately built so that
S05 and later modules can adopt the same `CommandContext`/audit contract instead of each
reinventing one.

## 2. Scope

**In scope:**

- `Modules/Audit` — the audit module: logical model, PostgreSQL physical model, append service,
  immutability enforcement, redaction policy.
- `CommandContext` — actor, correlation id, source — resolvable from both HTTP and CLI.
- A minimal command-execution/transaction-boundary contract (`AuditedCommandExecutor`) tying a
  command's mutation and its successful audit entry into one outer transaction.
- A `SECURITY_EVENT` recording path, independent of the mutation-transaction boundary, for
  authentication and rejected/denied security operations.
- Correlation-id propagation: HTTP middleware + CLI.
- Redaction/data-minimization policy for everything written into `audit.audit_entries`.
- The full S03 retrofit plan: all 15 S03 Application commands plus the S03 authentication/
  authorization event surface.
- Test matrix, invariants, and a migration/implementation plan for the later, separately
  authorized S04 implementation stage.

**Out of scope (see [§24](#24-non-goals) for the complete, non-exhaustive-by-design list):** HR/
Person/Employee/Organization modules and S05 reference data; any S49 audit *administration UI* or
read/query API; organization scope (S07/S08); a generic Command Bus, Event Bus, Outbox, or
idempotency framework; event sourcing; queues.

## 3. Existing baseline / S03 integration context

Summarized from the S04 Architecture Discovery Report (full detail there; not restated here):

- 15 S03 Application commands exist under `app/Modules/Security/Application/Commands/` (plus
  `AuthenticateWithPassword`). None receives a `CommandContext`-shaped input; `Auth::guard('web')
  ->user()` is read directly, inline, wherever an actor is currently needed at all (only
  `AssignRoleToPrincipal` records one, via `assigned_by`).
- Transaction boundaries are inconsistent: 6 of 15 commands open their own `DB::transaction`; 9 do
  not. `ChangePrincipalStatus` (disable) and `DeactivateRole` are always invoked from inside
  `SecurityAdministrationGuard::run()`, which already opens its own `DB::transaction` and takes a
  `pg_advisory_xact_lock` — producing a genuine nested transaction (Laravel `SAVEPOINT`) today.
- The `audit` PostgreSQL schema exists (created empty by S02) and is unused — no table, trigger,
  or function inside it.
- No logging, domain-event, correlation-id, idempotency, or outbox infrastructure exists anywhere
  in the repository.
- 273 backend tests / 625 assertions pass on the S03 baseline (verified in this session).

S04 changes none of this by itself — this document is specification only (see
[§25](#25-migrationimplementation-plan-for-later-execution) for what a later, separately
authorized implementation stage will change).

## 4. Architecture decisions D1–D13

These are the Architecture Authority's decisions as issued for S04; this specification adopts
each one as binding and shows, in the sections that follow, exactly how it is realized. They are
not re-litigated here.

| # | Decision | Disposition |
|---|---|---|
| D1 | `CommandContext` — application-level, HTTP/Auth-independent, HTTP+CLI resolvable | Adopted — [§6](#6-commandcontext) |
| D2 | One outer transaction: mutation + invariants + concurrency + successful audit append; guard semantics/lock preserved, guard operates inside the outer transaction; no generic Unit of Work | Adopted — [§9](#9-command-execution--transaction-boundary) |
| D3 | HUMAN actor = `security.principals.id`, required | Adopted — [§7](#7-actor-model) |
| D4 | SYSTEM actor ≠ a Principal row; `actor_principal_id = NULL`; controlled `actor_label` only | Adopted — [§7](#7-actor-model) |
| D5 | Audit is an independent module (`Modules/Audit`, `audit` schema); Security is a consumer | Adopted — [§5](#5-module-boundaries) |
| D6 | Immutability at both application and PostgreSQL level; trigger/function, not `REVOKE`-only | Adopted — [§12](#12-append-only--immutability-design) |
| D7 | Correlation id is S04 scope; HTTP header in/out; CLI generates; tracing only, never authorization evidence | Adopted — [§8](#8-correlation-model) |
| D8 | All 15 S03 mutations retrofitted, not just HIGH ones; auth events handled separately as `SECURITY_EVENT` | Adopted — [§16](#16-s03-retrofit-matrix), [§17](#17-authenticationsecurity-event-matrix) |
| D9 | Allowlist-first redaction; denylist is defense-in-depth only, never primary | Adopted — [§15](#15-redaction--data-minimization) |
| D10 | Split policy: MUTATION = committed only; rejections/security events recorded separately, never inside a transaction that then rolls back | Adopted — [§14](#14-security-event-semantics) |
| D11 | No generic Command Bus/dispatcher/mediator/service locator; a small explicit executor is allowed for exactly `CommandContext` propagation + outer transaction + audit append | Adopted — [§9](#9-command-execution--transaction-boundary) |
| D12 | No Outbox/Event Bus/Message Bus/Kafka/Event Sourcing in S04 | Adopted — [§24](#24-non-goals) |
| D13 | No generic idempotency infrastructure in S04 | Adopted — [§24](#24-non-goals) |

## 5. Module boundaries

`app/Modules/Audit/` — a new, independent module, following the existing convention documented in
`architecture-foundation.md` (`Domain/Application/Infrastructure/Presentation`, dependencies
pointing inward, no cross-module reach into internals):

```
app/Modules/Audit/
├── Domain/            AuditEntry value semantics, Category/Outcome/ActorType/Source enums,
│                       redaction contracts — no framework dependencies
├── Application/        AuditAppendService, AuditSecurityEventRecorder, AuditedCommandExecutor
├── Infrastructure/     Eloquent/Query-Builder persistence for audit.audit_entries
└── Presentation/        empty in S04 — no HTTP surface is added (no read API; see §19)
```

Audit owns the `audit` PostgreSQL schema exclusively, exactly as Security owns `security`.
**Security is a consumer of Audit**, not the reverse: Security's commands and controllers depend
on Audit's `Application` contracts (`AuditedCommandExecutor`, `AuditSecurityEventRecorder`); Audit
never imports anything from `Modules/Security` except the one narrow, justified coupling analyzed
in [§11](#11-physical-postgresql-design) (the foreign key from `actor_principal_id` to
`security.principals.id`). `CommandContext` itself (actor/correlation/source) is cross-cutting,
not audit-specific, and lives in `Modules/Platform/Application/` (Platform already exists as the
repository's infrastructure-level module, per `architecture-foundation.md`) so that a future
module can depend on `CommandContext` without depending on the entire Audit module.

Audit Trail (`audit.audit_entries`) remains distinct from: **Application Log** (none exists;
out of scope), **Domain/Event Store** (none exists; D12 keeps it that way for S04), and
**Employee/business temporal history** (does not exist before S05+; S04 never reaches into it).
This distinction is an explicit non-goal boundary — see [§24](#24-non-goals) and invariant AUD-11.

## 6. CommandContext

`App\Modules\Platform\Application\Execution\CommandContext` (final, immutable value object;
resolves Q1):

```php
final class CommandContext
{
    public function __construct(
        public readonly Actor $actor,
        public readonly CorrelationId $correlationId,
        public readonly Source $source,   // enum: Http, Cli, System
    ) {}
}
```

`Actor` (Domain-layer value object, `Modules/Platform/Domain/`):

```php
final class Actor
{
    private function __construct(
        public readonly ActorType $type,        // enum: Human, System
        public readonly ?string $principalId,     // security.principals.id — required iff Human
        public readonly ?string $label,            // controlled value — allowed iff System
    ) {}

    public static function human(string $principalId): self { /* type=Human, label=null */ }
    public static function system(string $label): self { /* principalId=null */ }
}
```

`CorrelationId` wraps a validated UUID string (`Modules/Platform/Domain/CorrelationId.php`).

`CommandContext` and its constituents contain **no** reference to `Illuminate\Http\Request` or
`Illuminate\Support\Facades\Auth` — they are plain PHP value objects, constructible in a unit test
with no framework bootstrap. Coupling to Laravel is confined entirely to the two resolution paths
below; the Application-layer contracts (`AuditedCommandExecutor`, S03 commands once retrofitted)
depend only on `CommandContext`.

**HTTP resolution** — a new middleware, `App\Modules\Platform\Presentation\Http\Middleware\
ResolveCommandContext`, registered in the same protected route groups as `principal.active`/
`permission:*` (after `auth:web`, so `Auth::guard('web')->user()` is guaranteed non-null when it
runs), builds: `Actor::human(Auth::guard('web')->user()->id)`, the effective correlation id (see
[§8](#8-correlation-model)), `Source::Http`. It attaches the resulting `CommandContext` to the
request (`$request->attributes`) for controllers to read via a typed accessor — controllers are
not modified to "manually construct arbitrary context details" (per D1); they read one already-
built value.

**CLI resolution** — `BootstrapAdminCommand` (the only non-HTTP entry point in the repository
today) builds its own `CommandContext` explicitly and directly, at the start of `handle()`:
`Actor::system('CLI_BOOTSTRAP')`, a freshly generated `CorrelationId` (`Str::uuid7()`),
`Source::Cli`. This is the "CLI execution will establish it explicitly" path D1 requires — there
is no shared "non-HTTP resolver" abstraction built for a single call site (would be speculative
generality); if a second CLI/non-HTTP entry point is authorized later, the two call sites can be
factored together then.

## 7. Actor model

Resolves D3/D4/Q3 (actor half)/Q4.

| `actor_type` | `actor_principal_id` | `actor_label` | Meaning |
|---|---|---|---|
| `HUMAN` | required, `security.principals.id` | always `NULL` | A real, currently-or-formerly-active security principal performed or attempted this |
| `SYSTEM` | always `NULL` | required, one of a fixed, application-controlled set | An internal execution context, never a login-capable identity |

**Controlled `actor_label` values (S04 v1.0 — the only values any code path may emit):**

| Label | Emitted by |
|---|---|
| `CLI_BOOTSTRAP` | `BootstrapAdminCommand` |
| `UNAUTHENTICATED` | A `SECURITY_EVENT` describing an attempt where no principal was ever established (failed login before credential resolution) — see [§17](#17-authenticationsecurity-event-matrix) |

No code path accepts an `actor_label` value from an HTTP request body, query string, or header —
`actor_label` is always chosen by application code from this fixed set, never passed through from
client input (this is the "do not accept arbitrary actor_label text supplied by an HTTP client"
requirement in D4, made concrete). Extending the set is a specification change, not a runtime
configuration.

SYSTEM is never written to `security.principals` and never receives a `security.credentials` row
— this is enforced structurally (nothing in the Audit or retrofitted Security code ever
constructs a `Principal` for a SYSTEM actor), not by a database constraint, since the constraint
that matters (`audit.audit_entries` itself never permitting `actor_type = 'SYSTEM'` with a non-
null `actor_principal_id`) is enforced at the audit table (AUD-06, [§10](#10-audit-entry-logical-model)).

## 8. Correlation model

Resolves D7/Q5.

- **Generation:** `Str::uuid7()` (matching the repository's existing UUID convention — every
  other generated identifier in the codebase is a v7 UUID).
- **HTTP inbound:** `ResolveCommandContext` middleware reads `X-Correlation-ID`. It is accepted
  only if it is a syntactically valid UUID (any RFC 4122 version — a client-supplied trace id from
  an upstream system is not required to be v7). Missing, empty, malformed, or multiple values
  (Laravel's `Request::header()` returns the first when multiple are sent, which is treated as the
  single candidate) are all replaced with a freshly generated v7 UUID — a bad correlation header
  must never fail the business request.
- **HTTP outbound:** the effective correlation id (client-supplied-and-valid, or generated) is
  always echoed back on the response as `X-Correlation-ID`, regardless of success or failure,
  so a caller can always find the id that was actually used.
- **CLI:** generated explicitly at the start of the command invocation (`Str::uuid7()`), never
  read from an environment variable or argument in S04 v1.0.
- **Trust boundary (AUD-09):** correlation id is tracing metadata only. Nothing in `RequirePermission`,
  `EnsurePrincipalIsActive`, or any command authorizes or identifies on the basis of a correlation
  id, and no future code may start doing so without revisiting this invariant explicitly.
- Future queue/job propagation of the same `CorrelationId` value object is anticipated but not
  built — no queue exists yet (D12).

## 9. Command execution / transaction boundary

Resolves D2/D11/Q6.

`App\Modules\Audit\Application\AuditedCommandExecutor` — the one small, explicit orchestration
abstraction D11 permits. It is not a dispatcher: callers still construct and know exactly which
command they are calling; the executor's only job is the three things D2 lists.

```php
final class AuditedCommandExecutor
{
    public function run(CommandContext $context, AuditSpec $spec, Closure $operation): mixed
    {
        return DB::transaction(function () use ($context, $spec, $operation) {
            $result = $operation();                          // 1. mutation (+ invariant/
                                                               //    concurrency checks, already
                                                               //    inside this transaction)
            $this->appendService->append($context, $spec, $result);  // 2. successful audit append
            return $result;                                   // commits together with the append
        });                                                    // — or rolls back together with it
    }
}
```

`AuditSpec` is a small, per-call-site value describing what to audit (action code, target type,
a closure/callable producing the redacted `changes`/`metadata` from `$result` — see
[§15](#15-redaction--data-minimization)); it is supplied by the retrofitted command's call site
(controller), not inferred generically, keeping the executor itself free of per-action knowledge.

**Composition with `SecurityAdministrationGuard` (retrofit normalization):** today,
`ChangePrincipalStatus` (disable) and `DeactivateRole` are invoked as `$guard->run(fn () =>
$command->handle(...))`, where `$guard->run()` itself opens the outermost `DB::transaction` and
takes the advisory lock. After retrofit, the call site becomes `$executor->run($context, $spec,
fn () => $guard->run(fn () => $command->handle(...)))` — **`AuditedCommandExecutor`'s
transaction becomes the outermost one**, and `SecurityAdministrationGuard::run()`'s own
`DB::transaction()` call becomes a nested transaction (Laravel `SAVEPOINT`) inside it, exactly the
nesting behavior already independently confirmed to work correctly in this codebase today (S04
Architecture Discovery Report, §8) — no change to Laravel's or PostgreSQL's transaction handling
is required, only a change to which caller sits outermost. The guard's advisory lock is
transaction-scoped to whichever transaction is live when it is taken; because that transaction is
now a savepoint inside the executor's transaction, the lock is held for the lifetime of the
*entire* outer transaction (executor's), which is what D2's "guard must operate inside the
effective outer transaction" requires — the lock protects the mutation and its audit append
together, not just the mutation.

For the 9 commands with no internal `DB::transaction` today, retrofit simply wraps the existing
single-statement write inside the executor's transaction — no behavior change to the write itself,
only that it now always executes inside an explicit transaction (previously implicit, single-
statement-atomic by virtue of being one `INSERT`/`UPDATE`).

For `CreatePrincipal` + `SetInitialPassword`, which are already invoked together inside one
`DB::transaction` in `PrincipalController::store`, retrofit replaces that controller-level
`DB::transaction(...)` with a single `$executor->run($context, $spec, fn () => { ... both calls
... })` call — one outer transaction, one audit entry (see [§16](#16-s03-retrofit-matrix)).

This is **not** a generic Command Bus: `AuditedCommandExecutor` has exactly one public method,
takes a concrete closure the caller already assembled, and does not route, resolve, or dispatch
commands by name or type — it satisfies D11 by construction.

## 10. Audit entry logical model

Resolves the mandatory model in the authorization, refined with explicit constraints.

**`AuditEntry`** (Domain-layer value object mirrored 1:1 by the `audit.audit_entries` row):

| Field | Type | Nullable | Notes |
|---|---|---|---|
| `id` | UUID (v7) | no | Application-generated, matching repo convention |
| `occurred_at` | `timestamptz` | no | Set by the append service at construction time (not by a DB default — mirrors `Principal`'s explicit in-memory `version` assignment so the in-memory object always matches the persisted row without a re-fetch) |
| `category` | `MUTATION` \| `SECURITY_EVENT` | no | |
| `action` | text, stable dotted code | no | See [§17](#17-authenticationsecurity-event-matrix)/[§16](#16-s03-retrofit-matrix) for the full catalog; naming convention below |
| `actor_type` | `HUMAN` \| `SYSTEM` | no | |
| `actor_principal_id` | UUID | yes | Required iff `HUMAN` (AUD-05/06) |
| `actor_label` | text | yes | Required iff `SYSTEM`, from the fixed set in [§7](#7-actor-model) |
| `source` | `HTTP` \| `CLI` \| `SYSTEM` | no | `SYSTEM` is reserved/unused in S04 v1.0 (no queue/job entry point exists yet); kept in the enum for forward extensibility rather than added later as a breaking change |
| `correlation_id` | UUID | no | |
| `target_type` | text, stable code | no | Naming convention below |
| `target_id` | text | yes | See target-identifier format below; `NULL` only for the one documented case in [§17](#17-authenticationsecurity-event-matrix) (unresolved failed-login username) |
| `outcome` | `SUCCEEDED` \| `REJECTED` | no | |
| `changes` | `jsonb` | yes | Semantic delta only — [§15](#15-redaction--data-minimization) |
| `metadata` | `jsonb` | yes | Allowlisted supplementary context only — [§15](#15-redaction--data-minimization) |

**Action code convention (Q2):** `<module>.<entity>.<verb>` (or `.<sub-entity>.<verb>` for a
relationship), lowercase, dot-separated, stable — never a PHP class or method name, so renaming a
Command class does not change recorded history. Full catalog: [§16](#16-s03-retrofit-matrix)/
[§17](#17-authenticationsecurity-event-matrix).

**`target_type` convention (Q3):** stable snake_case entity codes, not PHP FQCNs:
`security_principal`, `security_credential`, `security_role`, `security_principal_role`,
`security_role_permission`, `security_permission` (used only as the *target* of an authorization
denial, identifying the permission code that was required — see [§17](#17-authenticationsecurity-event-matrix)).

**`target_id` format:** a single entity's UUID as a string for single-row targets
(`security_principal`, `security_credential`, `security_role`). For relationship targets with no
single natural id column (`security_principal_role`, `security_role_permission`):
`"<left-uuid>:<right-uuid>"` — `"<principal_id>:<role_id>"` and `"<role_id>:<permission_id>"`
respectively, documented here as the one and only composite format the audit trail uses. For
`security_permission` (authorization-denial target), `target_id` is the permission code string
itself (e.g. `"security.users.update"`) — permission codes are already the stable identifier for
that entity (S03 never assigns them a UUID).

**Required invariant (CHECK constraint, AUD-05/AUD-06):**

```sql
CHECK (
  (actor_type = 'HUMAN'  AND actor_principal_id IS NOT NULL AND actor_label IS NULL) OR
  (actor_type = 'SYSTEM' AND actor_principal_id IS NULL     AND actor_label IS NOT NULL)
)
```

No speculative reference tables for `action`, `target_type`, or `actor_label` (per the
authorization's explicit instruction) — all three are plain, indexed `text` columns; the fixed
sets they draw from live in application code (PHP enums/constants), the same pattern S03 already
uses for `PermissionCatalog`.

## 11. Physical PostgreSQL design

`audit.audit_entries` (DDL sketch — no migration is created by this specification; see
[§25](#25-migrationimplementation-plan-for-later-execution)):

```sql
CREATE TABLE audit.audit_entries (
    id                  uuid PRIMARY KEY,
    occurred_at         timestamptz NOT NULL,
    category            text NOT NULL CHECK (category IN ('MUTATION', 'SECURITY_EVENT')),
    action              text NOT NULL,
    actor_type          text NOT NULL CHECK (actor_type IN ('HUMAN', 'SYSTEM')),
    actor_principal_id  uuid NULL REFERENCES security.principals(id) ON DELETE RESTRICT,
    actor_label         text NULL,
    source              text NOT NULL CHECK (source IN ('HTTP', 'CLI', 'SYSTEM')),
    correlation_id      uuid NOT NULL,
    target_type         text NOT NULL,
    target_id           text NULL,
    outcome             text NOT NULL CHECK (outcome IN ('SUCCEEDED', 'REJECTED')),
    changes             jsonb NULL,
    metadata            jsonb NULL,
    CONSTRAINT audit_entries_actor_check CHECK (
      (actor_type = 'HUMAN'  AND actor_principal_id IS NOT NULL AND actor_label IS NULL) OR
      (actor_type = 'SYSTEM' AND actor_principal_id IS NULL     AND actor_label IS NOT NULL)
    )
);
```

**Foreign key analysis (required by §H of the authorization):** `actor_principal_id` references
`security.principals.id`. S03 principals are never hard-deleted (`security-access-foundation.md`
§3: "There is no hard-delete workflow and no `DELETE` route"; disabling, never removal, is how a
principal stops being usable) — so a real `FOREIGN KEY` here carries none of the "referenced row
might disappear out from under recorded history" risk a FK to a soft/hard-deletable table would.
`ON DELETE RESTRICT` (not `CASCADE`, consistent with the "no unsafe `CASCADE`" convention
established in S02/S03) makes the coupling explicit and safe in the only direction that matters:
if principal hard-deletion is ever introduced by a future stage, it will fail loudly against any
principal with audit history rather than silently deleting or orphaning that history. No `ON
UPDATE` action is specified — principal ids are immutable UUIDs, never reassigned. This is the one
deliberate, narrow coupling from `audit` to `security` described in [§5](#5-module-boundaries);
it does not require the Audit module's PHP code to depend on the Security module — it is a
database-level constraint declared in a migration Audit owns.

**Indexes (Q15 — only for concrete expected query patterns, no speculative JSONB GIN index):**

```sql
CREATE INDEX audit_entries_occurred_at_idx ON audit.audit_entries (occurred_at);
CREATE INDEX audit_entries_actor_principal_occurred_idx
    ON audit.audit_entries (actor_principal_id, occurred_at) WHERE actor_principal_id IS NOT NULL;
CREATE INDEX audit_entries_action_occurred_idx ON audit.audit_entries (action, occurred_at);
CREATE INDEX audit_entries_target_idx
    ON audit.audit_entries (target_type, target_id, occurred_at);
CREATE INDEX audit_entries_correlation_id_idx ON audit.audit_entries (correlation_id);
```

These map directly to the query shapes a future S49 audit UI (or an operator investigating one
incident) would need: "history for this principal," "history of this action," "history of this
target," "everything under one correlation id," and plain chronological listing. No `metadata`/
`changes` JSONB index is added — no concrete query against JSONB contents has been specified; one
can be added later, backed by a real query requirement, without a breaking change.

## 12. Append-only / immutability design

Resolves D6/Q9.

**Application level:** `AuditAppendService::append()` is the *only* write path into
`audit.audit_entries` (called by `AuditedCommandExecutor` for `MUTATION` rows and by
`AuditSecurityEventRecorder` for `SECURITY_EVENT` rows — [§14](#14-security-event-semantics)).
Neither exposes update or delete; there is no `AuditRepository` with generic `update()`/`delete()`
methods, and no Eloquent model for `AuditEntry` is ever used with `->update()`/`->delete()`/
`->save()` on an existing row anywhere in the codebase — application code cannot accidentally
mutate a row it already wrote (satisfies "no generic mutable audit repository").

**PostgreSQL level (defense in depth — required by D6, not optional):**

```sql
CREATE FUNCTION audit.reject_audit_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit.audit_entries is append-only; % is not permitted', TG_OP
        USING ERRCODE = 'MA001';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER audit_entries_immutable
    BEFORE UPDATE OR DELETE ON audit.audit_entries
    FOR EACH ROW EXECUTE FUNCTION audit.reject_audit_mutation();
```

`MA001` is a deliberately-chosen, non-standard, application-owned SQLSTATE (PostgreSQL permits
`RAISE EXCEPTION ... USING ERRCODE` to set any 5-character code not already claimed by a built-in
class), so it can never be confused with a real constraint/connection/data error and can be
matched precisely. `PostgresErrorClassifier` gains one new method, `isAuditImmutabilityViolation
(QueryException $e): bool`, checking for SQLSTATE `MA001`, mirroring how `isUniqueViolation()`
already works — this is implementation, not specification, but the contract is fixed here so the
later implementation stage has no ambiguity.

**Migration up/down semantics:** the trigger/function are created by their own migration, separate
from the table-creation migration (mirroring the pattern where S03 added `CHECK` constraints via
raw `DB::statement()` inside migrations). Down migration for the trigger migration: `DROP TRIGGER`
then `DROP FUNCTION`, in that order — no `CASCADE`. Down migration for the table migration: a
standard `Schema::dropIfExists('audit.audit_entries')`, following the same "roll back only what
this migration created" discipline already used throughout S02/S03; there is no special data-
retention concern for a `down()` used only in development/CI, since it drops the whole table
including its data, exactly like every other migration's `down()` in this repository.

**Test expectation:** immutability must be exercised against real PostgreSQL — a direct `UPDATE`
and a direct `DELETE` against a committed row, both expected to raise (asserted via
`databaseError()`/`PostgresIntegrationTestCase`, the same pattern `DatabaseConstraintsTest`
already uses for S03's `CHECK` constraints) — not mocked, not asserted only at the application
layer (test matrix items 2–3, [§21](#21-test-matrix)).

## 13. Mutation audit semantics

A `MUTATION` audit entry represents a **committed** state change and only that — by construction
of [§9](#9-command-execution--transaction-boundary), a `MUTATION` row can never exist for an
operation that rolled back, because the row is inserted inside the same transaction as the
mutation and both commit or both roll back together (AUD-01/AUD-02).

`category = 'MUTATION'`, `outcome` is therefore always `'SUCCEEDED'` for this category in S04 v1.0
— there is no `MUTATION`/`REJECTED` combination (a mutation that was rejected never reaches the
append call at all; see [§14](#14-security-event-semantics) for how rejections are represented
instead).

`changes` is the **minimum semantic delta**, not a snapshot, per action-specific allowlist ([§15](#15-redaction--data-minimization)):

```json
{ "status": { "from": "ACTIVE", "to": "DISABLED" } }
```
```json
{ "username": { "from": "jsmith", "to": "j.smith" } }
```

No complete before/after entity serialization, no raw `Request`/Eloquent-model dump, ever
(AUD-14).

**Password operations** (`SetInitialPassword`, `ChangePassword`, `ResetPasswordAdministratively`):
`changes = null`. The fact that a credential operation occurred is fully captured by `action` +
`target_id`; no representation of old password, new password, or password hash is safe to store
even as a "changed" marker with a value, so none is attempted (AUD-08).

## 14. Security event semantics

Resolves D10/Q10 — the split policy.

`category = 'SECURITY_EVENT'` records things that are important to know happened but that are
**not** committed state changes: successful/failed login, logout, authorization denial, and
rejection of a mutation attempt (most notably the last-security-administrator invariant). A
`SECURITY_EVENT` has `outcome = 'SUCCEEDED'` (login succeeded, logout happened) or `outcome =
'REJECTED'` (login failed, access denied, invariant blocked the attempt) — `REJECTED` never
implies any state changed (AUD-10).

**Persistence mechanism — `AuditSecurityEventRecorder::record()`:** always writes in its own,
independent unit of work — either no surrounding transaction exists at all (the common case,
covered below), or, for the one case where a rejection is discovered *inside* an in-flight
mutation transaction, the recorder is invoked only **after** that transaction has already
unwound:

1. **Pre-transaction rejections** (authorization denial in `RequirePermission` middleware,
   disabled-principal session rejection in `EnsurePrincipalIsActive`) — these happen before any
   `DB::transaction` opens at all (S03 Architecture Discovery Report §10: authorization/
   authentication failures happen "before the transaction, in fact before the controller/command
   is reached"). The recorder's insert is the only write happening; nothing to reconcile.

2. **Authentication events** (login success/failure, logout) — `AuthenticateWithPassword`/
   `LoginController`/`LogoutController` mutate no `security.*` row in S03 (login only reads
   `Principal`/`Credential` and mutates the session; logout only invalidates the session). The
   recorder's insert has no mutation transaction to conflict with.

3. **In-transaction rejection** (the last-security-administrator invariant, raised inside
   `SecurityAdministrationGuard::run()`, itself nested inside `AuditedCommandExecutor`'s outer
   transaction per [§9](#9-command-execution--transaction-boundary)): `AuditedCommandExecutor`
   does **not** catch this inside the `DB::transaction()` closure — it lets the exception
   propagate, which is what causes Laravel to roll back the entire outer transaction
   automatically (existing, already-verified behavior). Only in the calling code **outside** that
   closure — in a `catch` block wrapping the `$executor->run(...)` call — is
   `AuditSecurityEventRecorder::record(...)` invoked, in a fresh, independent transaction, to
   persist the `REJECTED` `SECURITY_EVENT`. The original exception is then rethrown unchanged so
   the HTTP response is unaffected by anything audit-related.

**Recorder-failure isolation (required explicitly by Q10):** the `record()` call in case 3 (and,
defensively, in cases 1–2 as well) is wrapped in its own `try`/`catch`; if writing the
`SECURITY_EVENT` itself fails (a database error unrelated to the original rejection), that
secondary failure is swallowed (never allowed to replace or suppress the original exception, and
never allowed to retroactively affect the already-completed rollback of the mutation attempt) —
the caller always sees the original rejection (403/409/401), with or without a corresponding audit
row. A missing `SECURITY_EVENT` row is an operational gap to be monitored, not a reason to change
what the caller sees or to hold the original transaction open.

This achieves exactly what D10 requires: a mutation that rolls back never produces a `MUTATION`
row (case never arises — [§13](#13-mutation-audit-semantics)), and a rejection is recorded without
ever living inside — or being able to reopen — the transaction that rejected it.

## 15. Redaction / data minimization

Resolves D9/Q8.

**Allowlist-first (primary mechanism):** every `action` code has an explicit, hand-written
allowlist of the `changes` keys and `metadata` keys it may ever populate, defined alongside that
action's `AuditSpec` in the retrofitted command's call site — never a generic
`array_diff`/reflection-based "everything except denylisted fields" serializer. Nothing is
written to `changes`/`metadata` unless a specific action's allowlist names it. This is why
`Request` objects, Eloquent models, and full entity snapshots are never accepted as input to the
append service — the append service's input type is already the narrow, pre-built `changes`/
`metadata` arrays the allowlist produced, not a raw model or request (structurally impossible to
"blindly serialize" one, per the requirement).

**Denylist (defense in depth only, never primary):** a runtime assertion inside
`AuditAppendService::append()` rejects (throws, fails loudly in tests/dev, logs-and-strips in
would-be production use — exact behavior to be pinned down at implementation time) any `changes`/
`metadata` payload whose keys match a small fixed denylist, as a last-resort catch for an
allowlist authored incorrectly:

- plaintext password / old password / new password
- password hash
- session identifier / session cookie value
- CSRF token
- authentication/bearer token
- `Authorization` header value
- application/environment secret values
- a complete credential-bearing request body

**Size/shape constraints:** `changes` and `metadata` are each a flat or shallow (≤ 2 levels) JSON
object of primitive values (string/number/boolean/null) — no arrays of arbitrary objects, no
nested nulls-standing-in-for-omission. This keeps every payload trivially reviewable against its
action's allowlist and prevents the field from becoming, per the requirement, "an unstructured
dumping ground."

**Deterministic examples** (also shown in [§13](#13-mutation-audit-semantics)):

```json
// ChangePrincipalStatus — changes
{ "status": { "from": "ACTIVE", "to": "DISABLED" } }

// GrantPermissionToRole — metadata (ERRATA-05: corrected; see §29)
{}

// SetInitialPassword — changes
null
```

## 16. S03 retrofit matrix

Resolves Q11. Every command executes via `$executor->run($context, $spec, ...)` per
[§9](#9-command-execution--transaction-boundary); "Txn" below states what the *outer* transaction
boundary becomes after retrofit (always: the executor's).

| Command | `action` | `target_type` | `target_id` | Actor | Source | `changes` (allowlist) | `metadata` (allowlist) | Txn | Concurrency | Sensitive-data rule |
|---|---|---|---|---|---|---|---|---|---|---|
| `CreatePrincipal` + `SetInitialPassword` (one audit entry — always invoked together, §9) | `security.principal.create` | `security_principal` | new principal UUID | HUMAN | HTTP | `{username, display_name}` | `{credential_established: true}` | executor (replaces controller-level txn) | n/a (create) | no password value anywhere |
| `ChangePrincipalUsername` | `security.principal.username.change` | `security_principal` | principal UUID | HUMAN | HTTP | `{username: {from, to}}` | `{}` | executor (was: command-internal) | `expected_version` | — |
| `ChangePrincipalDisplayName` | `security.principal.display_name.change` | `security_principal` | principal UUID | HUMAN | HTTP | `{display_name: {from, to}}` | `{}` | executor | `expected_version` | — |
| `ChangePrincipalStatus` (disable) | `security.principal.status.change` | `security_principal` | principal UUID | HUMAN | HTTP | `{status: {from, to}}` | `{}` | executor outermost; guard nested (savepoint) | `expected_version` + advisory lock | — |
| `ChangePrincipalStatus` (activate) | `security.principal.status.change` | `security_principal` | principal UUID | HUMAN | HTTP | `{status: {from, to}}` | `{}` | executor | `expected_version` | — |
| `ChangePassword` (self-service) | `security.credential.password.change` | `security_credential` | principal UUID | HUMAN (self) | HTTP | `null` | `{}` | executor (new — was none) | none | no password value |
| `ResetPasswordAdministratively` | `security.credential.password.reset_administrative` | `security_credential` | principal UUID | HUMAN (administrator) | HTTP | `null` | `{}` | executor (new) | none | no password value |
| `CreateRole` | `security.role.create` | `security_role` | new role UUID | HUMAN | HTTP | `{code, name_ar, name_en}` (safe display fields only) | `{}` | executor (new) | n/a | — |
| `UpdateRoleMetadata` | `security.role.metadata.update` | `security_role` | role UUID | HUMAN | HTTP | `{name_ar: {from,to}?, name_en: {from,to}?}` | `{}` | executor | `expected_version` | — |
| `ActivateRole` | `security.role.activate` | `security_role` | role UUID | HUMAN | HTTP | `{is_active: {from: false, to: true}}` | `{}` | executor | `expected_version` | — |
| `DeactivateRole` | `security.role.deactivate` | `security_role` | role UUID | HUMAN | HTTP | `{is_active: {from: true, to: false}}` | `{}` | executor outermost; guard nested (savepoint) | `expected_version` + advisory lock | — |
| `AssignRoleToPrincipal` | `security.role_assignment.create` | `security_principal_role` | `"<principal_id>:<role_id>"` | HUMAN (already recorded via `assigned_by` — now also via `CommandContext`) | HTTP | `{}` | `{}` | executor (new) | `UNIQUE` DB constraint | — |
| `RemoveRoleFromPrincipal` | `security.role_assignment.remove` | `security_principal_role` | `"<principal_id>:<role_id>"` | HUMAN | HTTP | `{}` | `{}` | executor outermost; guard nested | none (delete) | — |
| `GrantPermissionToRole` | `security.role_permission.grant` | `security_role_permission` | `"<role_id>:<permission_id>"` | HUMAN | HTTP | `{}` | `{}` | executor (new) | none | — |
| `RevokePermissionFromRole` | `security.role_permission.revoke` | `security_role_permission` | `"<role_id>:<permission_id>"` | HUMAN | HTTP | `{}` | `{}` | executor outermost; guard nested | none | — |

All 15 are covered (D8: no HIGH-only shortcut). `BootstrapAdminCommand`'s internal calls to
`CreatePrincipal`/`SetInitialPassword`/`AssignRoleToPrincipal` use the same commands and therefore
the same audit entries, but with `Actor::system('CLI_BOOTSTRAP')`/`Source::Cli` from its own
explicitly-constructed `CommandContext` ([§6](#6-commandcontext)) — no special-casing inside the
commands or the executor.

## 17. Authentication/security event matrix

Resolves Q12.

| Event | `action` | `category` | `outcome` | Actor | `target_type` / `target_id` | `metadata` | Correlation | Txn placement |
|---|---|---|---|---|---|---|---|---|
| Login succeeded | `security.authentication.login.succeeded` | `SECURITY_EVENT` | `SUCCEEDED` | HUMAN, the now-authenticated principal | `security_principal` / that principal's UUID | `{}` | request's | outside any mutation txn — recorded after session established |
| Login failed | `security.authentication.login.failed` | `SECURITY_EVENT` | `REJECTED` | SYSTEM, `actor_label = UNAUTHENTICATED` (no principal is ever verified for a failed attempt — see [§7](#7-actor-model)) | `security_principal` / resolved principal UUID **if** the submitted username matched an existing principal, else `NULL` (never the raw submitted username) | `{}` | request's | outside any mutation txn |
| Logout | `security.authentication.logout` | `SECURITY_EVENT` | `SUCCEEDED` | HUMAN, the principal who was logged in | `security_principal` / same | `{}` | request's | outside any mutation txn |
| Authorization denial (403 from `RequirePermission`) | `security.authorization.denied` | `SECURITY_EVENT` | `REJECTED` | HUMAN (already authenticated — `auth:web` passed) | `security_permission` / the required permission code | `{}` | request's | before any transaction — middleware runs pre-controller |
| Disabled-principal session rejection (`EnsurePrincipalIsActive`) | `security.authentication.session_rejected` | `SECURITY_EVENT` | `REJECTED` | HUMAN, the (now being logged out) principal | `security_principal` / same | `{reason: "PRINCIPAL_DISABLED"}` | request's | before any transaction |
| Last-security-administrator rejection | the mutation's own `action` code from [§16](#16-s03-retrofit-matrix) (e.g. `security.principal.status.change`) | `SECURITY_EVENT` | `REJECTED` | HUMAN, the caller who attempted it | same target as the attempted mutation | `{rejection_reason: "LAST_SECURITY_ADMINISTRATOR"}` | request's | recorded after the outer mutation transaction has rolled back — [§14](#14-security-event-semantics) |

**Anti-enumeration (explicit requirement):** the audit internals above do not change or weaken any
existing S03 client-facing response — `InvalidCredentialsException` still renders the same generic
message regardless of whether the username resolved (S03 Architecture Discovery Report §14). The
only place a real principal id ever appears for a failed login is inside `audit.audit_entries`
itself, which has no read/query API in S04 ([§19](#19-permission--scope-boundary)) — it is not
reachable through any response the caller sees. No plaintext credential is ever persisted (AUD-08).
`security.authentication.login.failed`'s `target_id` is deliberately `NULL` rather than the raw
attempted username specifically so that an audit row never becomes a place where arbitrary,
unvalidated client input is stored as if it were a stable identifier.

## 18. Error / failure semantics

Synthesizing [§13](#13-mutation-audit-semantics)/[§14](#14-security-event-semantics) against the
S03 failure-timing table (Discovery Report §10):

| Failure | Audit outcome |
|---|---|
| Validation failure (before any transaction) | No audit entry — the request never reached a command |
| Authentication failure (401) | `SECURITY_EVENT` per [§17](#17-authenticationsecurity-event-matrix) (`login.failed`) or no entry at all if it's simply "no session" (not a rejected *attempt*, just absence) |
| Authorization failure (403) | `SECURITY_EVENT`, `authorization.denied` |
| Stale-version conflict (`StaleVersionException`) | No `SECURITY_EVENT` in S04 v1.0 — this is a concurrency/client-retry condition, not a security-relevant rejection; it is not in the six examples the authorization lists, and treating every optimistic-concurrency retry as a security event would flood the audit trail with routine client races. (Non-blocking observation, not a decision reversal — see [§26](#26-risks)) |
| Last-security-administrator rejection | `SECURITY_EVENT`, `REJECTED`, per [§17](#17-authenticationsecurity-event-matrix) |
| Database constraint violation (e.g. duplicate username) | Same as validation-equivalent domain exception (`DuplicateUsernameException` etc.) — no audit entry; the write never committed and no S04 requirement asks for a `SECURITY_EVENT` for ordinary data-integrity rejections |
| Unexpected exception inside the executor's transaction | Transaction rolls back automatically (existing Laravel behavior); no `MUTATION` entry is written (correct, by construction); no `SECURITY_EVENT` is written either, since it is not one of the categorized security rejections — an operational/application error, not a security decision |

## 19. Permission / scope boundary

Resolves Q13.

**No audit read permission is introduced in S04.** S04 is infrastructure-first: it builds the
append path and the immutability guarantee, not a read/query API. S49 owns the later
administrative/audit UI (and, with it, whatever permission model that UI needs); S04 does not
anticipate or reserve a permission code for it, since the shape of that future read API (filters,
pagination, scope) is not yet specified and inventing a permission code now risks naming something
that doesn't match what S49 actually needs. This is an explicit deferral, not an oversight.

No organization-scope concept (S07/S08) is attached to `audit.audit_entries` — there is no
`organization_unit_id` column and none is anticipated by this schema; scoping audit visibility, if
ever required, is entirely a concern for whatever later stage adds organization scope generally.

## 20. Retention boundary

Resolves Q14.

Audit entries have no application-level delete path in S04 (or ever, under normal operation) —
[§12](#12-append-only--immutability-design) makes this a hard guarantee, not a policy convention.
A distinction is drawn explicitly:

- **Immutable operational audit records** — what S04 builds. No duration, no expiry, no automatic
  pruning.
- **Future retention/archive policy** (e.g., "archive audit entries older than N years to cold
  storage") — not decided here. No duration is invented; if a later stage needs one, it is a new,
  separately authorized specification, not an assumption baked into the S04 schema.
- **Exceptional administrative maintenance** (e.g., a legally mandated deletion) — explicitly out
  of "normal application behavior" (AUD-04 already excludes it from the normal path); if ever
  required, it would be a manually-operated, out-of-band, separately-authorized DBA action, not a
  feature this specification builds.

## 21. Index strategy

See [§11](#11-physical-postgresql-design) — five indexes tied to concrete query shapes
(chronological, per-actor, per-action, per-target, per-correlation). No JSONB GIN index is added
without a demonstrated query requirement (Q15's explicit instruction).

## 22. Test matrix

All 28 required tests, matched to home location (mirroring `Feature/Security`/`Unit/Security`
convention, per the S04 Architecture Discovery Report §15's recommendation):

| # | Test | Location |
|---|---|---|
| 1 | Audit append on real PostgreSQL | `tests/Feature/Audit/AuditAppendServiceTest.php` |
| 2 | `UPDATE` on a committed audit row is rejected | `tests/Feature/Audit/AuditImmutabilityTest.php` |
| 3 | `DELETE` on a committed audit row is rejected | `tests/Feature/Audit/AuditImmutabilityTest.php` |
| 4 | HUMAN actor constraint (`actor_principal_id` required) | `tests/Unit/Audit/AuditEntryActorConstraintTest.php` + DB-level in `AuditImmutabilityTest`/`DatabaseConstraintsTest`-style |
| 5 | SYSTEM actor constraint (`actor_principal_id` forbidden) | same as #4 |
| 6 | Correlation id generation (missing header) | `tests/Feature/Audit/CorrelationIdTest.php` |
| 7 | Valid incoming correlation id propagation | `tests/Feature/Audit/CorrelationIdTest.php` |
| 8 | Malformed incoming correlation id fallback | `tests/Feature/Audit/CorrelationIdTest.php` |
| 9 | Mutation + audit atomic commit | `tests/Feature/Audit/AuditedCommandExecutorTest.php` |
| 10 | Audit insert failure ⇒ mutation rollback | `tests/Feature/Audit/AuditedCommandExecutorTest.php` |
| 11 | Mutation rollback ⇒ no successful `MUTATION` audit | `tests/Feature/Audit/AuditedCommandExecutorTest.php` |
| 12 | Last-admin invariant preserved | `tests/Feature/Security/LastAdminTest.php` (extended) |
| 13 | Last-admin rejection event semantics | `tests/Feature/Audit/SecurityEventRecorderTest.php` |
| 14 | Password audit redaction | `tests/Feature/Audit/RedactionTest.php` |
| 15 | Session/cookie/token/CSRF redaction | `tests/Feature/Audit/RedactionTest.php` |
| 16 | `CreatePrincipal` retrofit | `tests/Feature/Security/PrincipalTest.php` (extended) |
| 17 | `ChangePrincipalStatus` retrofit | `tests/Feature/Security/PrincipalTest.php` (extended) |
| 18 | Role assignment/removal retrofit | `tests/Feature/Security/RbacTest.php` (extended) |
| 19 | Permission grant/revoke retrofit | `tests/Feature/Security/RbacTest.php` (extended) |
| 20 | Password change/reset retrofit | `tests/Feature/Security/PasswordTest.php` (extended) |
| 21 | Authentication regression | `tests/Feature/Security/AuthenticationTest.php` (must still pass unmodified in behavior) |
| 22 | Authorization regression | `tests/Feature/Security/RbacTest.php`/`PrivilegeEscalationTest.php` |
| 23 | Optimistic concurrency regression | `tests/Feature/Security/ConcurrencyTest.php` |
| 24 | `SecurityAdministrationGuard` regression (savepoint nesting) | `tests/Feature/Security/LastAdminTest.php` |
| 25 | Bootstrap SYSTEM actor | `tests/Feature/Security/BootstrapAdminCommandTest.php` (extended) |
| 26 | CLI correlation id | `tests/Feature/Security/BootstrapAdminCommandTest.php` (extended) |
| 27 | Test DB isolation | inherited — `TestDatabaseGuard`/`PostgresIntegrationTestCase`, unchanged |
| 28 | S03 complete regression suite | full `composer test` run, 273+ tests, 0 failures |

Real PostgreSQL is used throughout (`PostgresIntegrationTestCase`), never SQLite, for every test
touching trigger/constraint behavior — matching S03's own existing discipline.

## 23. Invariants

AUD-01 through AUD-15 as issued, adopted verbatim and unweakened:

- **AUD-01:** Every committed audited mutation has its required successful `MUTATION` audit entry.
- **AUD-02:** A successful `MUTATION` audit entry cannot commit if its associated mutation rolls back.
- **AUD-03:** Audit entries cannot be updated.
- **AUD-04:** Audit entries cannot be deleted through normal application behavior or direct PostgreSQL `UPDATE`/`DELETE`.
- **AUD-05:** HUMAN actor requires a Principal UUID.
- **AUD-06:** SYSTEM actor cannot reference a Principal.
- **AUD-07:** SYSTEM is never represented by a login-capable Principal.
- **AUD-08:** Sensitive credentials/secrets are never persisted in audit payloads.
- **AUD-09:** Correlation id is tracing metadata, never authorization evidence.
- **AUD-10:** Rejected security events never imply committed mutations.
- **AUD-11:** Audit Trail is not an event store and cannot be used to reconstruct authoritative domain state.
- **AUD-12:** S03 authorization and last-admin protections remain authoritative.
- **AUD-13:** Audit append failure for a required committed mutation causes the mutation transaction to fail.
- **AUD-14:** No arbitrary HTTP request/model serialization enters audit JSONB.
- **AUD-15:** Audit action and target codes are stable application contracts, not PHP implementation names.

One refinement added by this specification, not a weakening of any of the above:

- **AUD-16 (new):** A failure to persist a `SECURITY_EVENT` (the *secondary* recording described
  in [§14](#14-security-event-semantics)) never alters, masks, or reopens the outcome of the
  mutation attempt it describes — the original exception/response is always what the caller sees.

## 24. Non-goals

Adopted verbatim from the authorization — S04 does not implement or expand into: Person, Employee,
Employment, HR temporal history, Organization hierarchy, Organization scope, S05 reference data,
Reporting, Import, Export, Dashboard, a generic Repository, a generic Unit of Work, a generic
Command Bus, a generic Event Bus, Message Bus, Outbox, Kafka, Event Sourcing, microservices, queue
architecture, S49 audit administration UI, or generic idempotency infrastructure.

## 25. Migration/implementation plan for later execution

Not authorized by this specification — listed here so a later, separately authorized S04
IMPLEMENTATION instruction has a concrete checklist, in a suggested sequence:

1. `Modules/Platform/Domain/{ActorType,Source}.php` (enums), `Modules/Platform/Domain/Actor.php`,
   `Modules/Platform/Domain/CorrelationId.php`, `Modules/Platform/Application/Execution/
   CommandContext.php`.
2. Migration: `audit.audit_entries` table + `CHECK` constraints + indexes ([§11](#11-physical-postgresql-design)).
3. Migration: `audit.reject_audit_mutation()` function + `audit_entries_immutable` trigger ([§12](#12-append-only--immutability-design)).
4. `Modules/Audit/Domain/AuditEntry.php`, `Category`/`Outcome` enums.
5. `Modules/Audit/Infrastructure/Persistence/Eloquent/AuditEntry.php` (or query-builder-only
   persistence, matching the query-builder-for-updates pattern S03 already uses for guarded
   writes).
6. `Modules/Audit/Application/AuditAppendService.php` (with the allowlist/denylist redaction
   boundary, [§15](#15-redaction--data-minimization)).
7. `Modules/Audit/Application/AuditSecurityEventRecorder.php` ([§14](#14-security-event-semantics)).
8. `Modules/Audit/Application/AuditedCommandExecutor.php` ([§9](#9-command-execution--transaction-boundary)).
9. `PostgresErrorClassifier::isAuditImmutabilityViolation()`.
10. `Modules/Platform/Presentation/Http/Middleware/ResolveCommandContext.php` + registration in
    `bootstrap/app.php`; `X-Correlation-ID` request/response wiring ([§8](#8-correlation-model)).
11. Retrofit order, most-sensitive first (matching the S04 Discovery Report §14's HIGH
    classification, itself now folded into "all 15" per D8): `ChangePrincipalStatus`,
    `DeactivateRole`, `RemoveRoleFromPrincipal`, `RevokePermissionFromRole`,
    `ResetPasswordAdministratively`, `ChangePassword`, then the remaining 9.
12. `AuthenticateWithPassword`/`LoginController`/`LogoutController`/`RequirePermission`/
    `EnsurePrincipalIsActive` — wire `AuditSecurityEventRecorder` calls ([§17](#17-authenticationsecurity-event-matrix)).
13. `BootstrapAdminCommand` — explicit `CommandContext` construction ([§6](#6-commandcontext)).
14. Full test matrix ([§22](#22-test-matrix)), ending with the full regression suite.

## 26. Risks

- **Transaction-boundary change touches all 15 commands** — the retrofit is broad by D8's own
  choice (deliberate, not accidental scope creep), so its blast radius is real; the full
  regression suite (test #28) is the safety net, not a formality.
- **Savepoint composition correctness** — `AuditedCommandExecutor` becoming outermost around
  `SecurityAdministrationGuard` relies on Laravel/PostgreSQL nested-transaction (`SAVEPOINT`)
  behavior already observed working in this codebase; it has not been exercised with a third
  layer (executor → guard → command-internal transaction, if any) — test #24 specifically targets
  this.
- **`actor_principal_id` foreign key couples `audit` to `security`** — narrow and justified
  ([§11](#11-physical-postgresql-design)), but it is the one place Audit's independence
  ([§5](#5-module-boundaries)) is not absolute; documented explicitly rather than hidden.
- **Correlation-id trust boundary is a discipline, not a mechanism** — nothing technically stops
  a future contributor from branching on `correlation_id` for authorization; AUD-09 is a
  documented invariant, enforced by review, not by a type system guarantee.
- **`SECURITY_EVENT` recorder-failure isolation is best-effort** — a secondary insert failure is
  swallowed by design ([§14](#14-security-event-semantics)/AUD-16); this trades perfect audit
  completeness for never letting audit infrastructure influence a security decision, which this
  specification judges to be the correct trade-off, but it does mean a `SECURITY_EVENT` gap is
  possible under database-level failure conditions coincident with a rejection.
- **Stale-version conflicts are not `SECURITY_EVENT`s** ([§18](#18-error--failure-semantics)) — a
  deliberate scope-narrowing interpretation of the six named examples in D10, flagged for
  Architecture Authority confirmation on review rather than silently assumed.

## 27. Open questions

**OPEN QUESTIONS = NONE.** Every D1–D13 decision and every Q1–Q15 question issued for S04 is
resolved concretely above. The one interpretive judgment call not explicitly dictated by the
authorization (stale-version conflicts excluded from `SECURITY_EVENT`, [§18](#18-error--failure-semantics))
is flagged as a risk ([§26](#26-risks)) for explicit Architecture Authority confirmation on
review, rather than left as an unresolved question blocking this specification.

## 28. Freeze readiness

This specification resolves all required decisions and questions, defines a concrete logical and
physical model, a concrete transaction/execution contract, a complete S03 retrofit matrix, a
complete authentication/security-event matrix, a test matrix, and an implementation plan, with no
outstanding open question. It is **ready for Architecture Authority review**. This document does
not declare itself frozen — freeze is the Architecture Authority's action, taken after review, and
S04 IMPLEMENTATION remains a separate, not-yet-authorized stage regardless of this specification's
completeness.

## 29. Errata

Post-freeze corrections identified during S04 implementation/closure review, each confirmed by
Architecture Authority and binding once recorded here.

- **ERRATA-05:** `GrantPermissionToRole`'s `metadata` follows [§16](#16-s03-retrofit-matrix)/Q11 —
  `{}` (empty), never `{permission_code: ...}`. The permission identity is already carried by the
  `target_type`/`target_id` contract (`security_role_permission` /
  `"<role_id>:<permission_id>"`), so duplicating it in `metadata` is unnecessary. The deterministic
  example previously shown in [§15](#15-redaction--data-minimization) —
  `{"permission_code": "security.users.update"}` — was a documentation erratum, not an
  authoritative requirement; it has been corrected in place to match §16, which is authoritative
  for this and every other action's `changes`/`metadata` allowlist.
