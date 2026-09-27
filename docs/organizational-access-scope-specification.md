# Organizational access scope specification (S08) — v1.0

## 1. Purpose

S08 gives the existing S03 RBAC model a second, independent authorization dimension: **where** a
principal's permissions apply, expressed against S07's organizational hierarchy. Permission answers
*what* a principal may do; scope answers *where*. S08 builds the engine that can answer "Does
Principal P have Permission X within Organizational Unit U?" — it does not build any of the future
HR/Reporting features that will actually ask that question.

## 2. Authority for this specification

This specification implements the S08 stage authorization together with the Architecture
Authority's `ADR-S08-001`/`ADR-S08-002` resolution of the S08 Discovery Blocker Report. Every design
choice below traces to one of: the original S08 authorization, the two ADRs, or a conservative,
documented derivation from frozen S03/S07/S04 evidence (cited inline). Nothing here is invented.

## 3. Non-goals (unchanged from the S08 authorization)

No `Person`/`Employee`/`Employment`, no national ID, no employee number, no job/employment/
placement history, no contracts/transfers/secondments/assignments/leave, no employee documents. No
report filtering (Reporting is a future consumer, not implemented here). No scope-management
frontend. No S09 work of any kind. `_to_delete/` is never touched.

## 4. S03/S07 boundary (restated, unchanged)

S03 owns RBAC (`security.principals/credentials/roles/permissions/principal_roles/role_permissions`).
S07 owns the organization hierarchy (`org.organizational_units`). S08 modifies neither: it adds one
new table to the `security` schema and reuses S07's hierarchy queries unmodified — it does not
create a second tree representation, does not add a scope column to any S03/S07 table, and does not
retrofit scoping onto S07's own hierarchy-management endpoints (§17 below).

## 5. ADR-S08-001 — scope attachment model (binding)

Organizational scope is an **independent, principal-level** authorization dimension, not an
attribute of a role, a `principal_roles` row, a `role_permissions` row, or a permission. `security.
principals` gains no column; `security.principal_roles`/`security.role_permissions` are not touched
in any way — their semantics, columns and constraints are exactly what S03 left them.

Conceptually:

```
Principal → PrincipalRole → Role → RolePermission → Permission      (S03, unmodified: WHAT)
Principal → OrganizationalScopeGrant → Organizational Unit | GLOBAL  (S08, new: WHERE)
```

A scope grant never grants a permission. Effective scoped authorization is the conjunction of the
two independent checks:

```
Authorized(principal, permission, unit) :=
    EffectivePermissionsResolver::has(principal, permission)   // existing S03, unmodified
    AND
    EffectiveOrganizationalScope::for(principal).covers(unit)  // new, S08
```

There is no pairing between which role supplied the permission and which grant supplies the scope —
by design (`ADR-S08-001`, "multiple role semantics"): if a principal holds a permission through *any*
valid RBAC path and their scope (the union of *all* their grants) covers the unit, they are
authorized, full stop.

## 6. ADR-S08-002 — unit scope semantics (binding)

A `UNIT` grant on organizational unit A covers **A and all of A's current descendants** — an
inclusive subtree, not "A only" and not "descendants only." Descendant membership is resolved
live, on every evaluation, against S07's current hierarchy (reusing the same `WITH RECURSIVE` shape
`ListOrganizationalUnitDescendants` already established — see §11) — never a materialized or cached
descendant set. If a unit is moved in S07, every principal scoped to one of its (former or new)
ancestors sees the effect on their very next authorization check, with zero S08-side state to update.

## 7. Scope kinds

Exactly two scope kinds exist in S08 v1: `GLOBAL` and `UNIT`. A `GLOBAL` grant covers every
organizational unit, present and future, without needing to know the hierarchy's shape. `GLOBAL` is
represented explicitly by a `scope_kind` column value — never by a `NULL` unit reference read as "no
restriction," never by a magic/fake root unit UUID. For a `UNIT` grant, `organizational_unit_id` is
required and must reference a real row in `org.organizational_units`. For a `GLOBAL` grant,
`organizational_unit_id` must be `NULL`. Both directions of this pairing are enforced by a database
`CHECK` constraint (§10), not only by application code.

## 8. Multiple grants and union semantics

A principal may hold any number of active scope grants simultaneously (e.g. `UNIT(A)` and `UNIT(B)`
at once, from being placed in two organizational areas). Effective scope is the union:

- `UNIT(A) + UNIT(B)` → `subtree(A) ∪ subtree(B)`.
- Any `GLOBAL` grant present at all → effective scope is `GLOBAL`, regardless of how many `UNIT`
  grants also exist (a `GLOBAL` grant is absorbing).

There is no deny-grant concept in S08 v1 (explicitly excluded by `ADR-S08-002` and by the original
authorization §10/§21) — every grant only adds coverage, never subtracts it.

## 9. Scope is not per-permission

`ADR-S08-001` explicitly rejects attaching a `permission_id` to a scope grant "merely to create
per-permission scoping." A principal's scope is one union set of units (or `GLOBAL`), applied
identically to every permission they hold. A future stage that genuinely needs per-permission scope
is out of S08 v1's frozen model and would need its own authorization.

## 10. Database: `security.organizational_scope_grants`

New table, owned by S08, in the existing `security` schema (this is a Security-module authorization
concept referencing an Organization-module identifier — the same cross-schema-FK shape already used
by `audit.audit_entries.actor_principal_id → security.principals.id`, so this is not a new pattern).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `uuid` | PK, generated application-side (UUID v7), same convention as every other table. |
| `principal_id` | `uuid` | `NOT NULL`, FK → `security.principals.id`. |
| `scope_kind` | `varchar` | `NOT NULL`, `CHECK (scope_kind IN ('GLOBAL','UNIT'))`. |
| `organizational_unit_id` | `uuid` | Nullable, FK → `org.organizational_units.id`, `ON DELETE RESTRICT` — mirrors the audit FK's own RESTRICT choice; moot in practice since S07 never hard-deletes a unit, but keeps the same defensive posture. |
| `granted_at` | `timestamptz` | `NOT NULL`. |
| `granted_by` | `uuid` | Nullable, FK → `security.principals.id` — mirrors `principal_roles.assigned_by` exactly. |

Constraints:

```sql
ALTER TABLE security.organizational_scope_grants
    ADD CONSTRAINT organizational_scope_grants_kind_unit_pairing_check
    CHECK (
        (scope_kind = 'UNIT'   AND organizational_unit_id IS NOT NULL) OR
        (scope_kind = 'GLOBAL' AND organizational_unit_id IS NULL)
    );

CREATE UNIQUE INDEX organizational_scope_grants_unique_global
    ON security.organizational_scope_grants (principal_id)
    WHERE scope_kind = 'GLOBAL';

CREATE UNIQUE INDEX organizational_scope_grants_unique_unit
    ON security.organizational_scope_grants (principal_id, organizational_unit_id)
    WHERE scope_kind = 'UNIT';
```

No `version` column. Per `ADR-S08-002` ("grant lifecycle"), a scope grant is a security
grant/assignment row — the same shape as `security.principal_roles`/`security.role_permissions`,
neither of which carries a `version` column either (§14 below expands on why). The two partial
unique indexes are what make concurrent duplicate grants safe at the database level, exactly as
`principal_roles_principal_role_unique`/`role_permissions_role_permission_unique` already do for
their own tables — a plain `UNIQUE(principal_id, scope_kind, organizational_unit_id)` would not work
here because PostgreSQL treats multiple `NULL`s in a unique constraint as distinct, which would let
a principal accumulate more than one `GLOBAL` row; the partial indexes close that gap explicitly.

Migration file: `2026_09_28_000001_create_security_organizational_scope_grants_table.php`. Following
the established pattern (S07 §13, itself following S02), the `CHECK` constraint is added via a
separate `DB::statement()` call after `Schema::create()` returns (this table has no self-referencing
FK, so the specific FK-ordering bug from S07 does not apply here, but the CHECK-after-create pattern
is followed for consistency and because Laravel's `Blueprint::check()` fluent helper does not exist).

## 11. Effective-scope resolution

New query, `App\Modules\Security\Application\Queries\ResolveEffectiveOrganizationalScope`, returns a
new value object `App\Modules\Security\Domain\EffectiveOrganizationalScope` with:

- `isGlobal(): bool`
- `unitIds(): list<string>` — the union of every granted unit's inclusive subtree (empty when
  `isGlobal()` is true — the set is meaningless once global).
- `covers(string $unitId): bool` — `true` iff `isGlobal()` or `$unitId` is in `unitIds()`.

Resolution algorithm:

1. Load the principal's scope grants. If any row has `scope_kind = 'GLOBAL'`, return
   `EffectiveOrganizationalScope::global()` immediately — no further query needed.
2. Otherwise, collect every `UNIT` grant's `organizational_unit_id` and run **one** `WITH RECURSIVE`
   query seeded from that whole set (not one query per grant), unioning each seed's descendants —
   the same recursive shape `ListOrganizationalUnitDescendants` already uses, generalized to a
   multi-row seed:

   ```sql
   WITH RECURSIVE scope_units AS (
       SELECT id FROM org.organizational_units WHERE id = ANY(?)   -- the granted unit ids themselves
       UNION ALL
       SELECT child.id
       FROM org.organizational_units child
       INNER JOIN scope_units ON child.parent_id = scope_units.id
   )
   SELECT id FROM scope_units
   ```

   This reuses S07's exact recursion pattern (§12 of the S08 authorization: reuse, never duplicate)
   and deliberately does **not** filter on `is_active` — descendant membership is a pure structural
   fact, unaffected by any unit's activity status (§13 below explains why activity is instead
   checked once, at the target, not while walking the tree).

`ScopedAuthorizationChecker` (new, `App\Modules\Security\Infrastructure\Authorization`) composes this
with the existing `EffectivePermissionsResolver` for the full check described in §5:

```php
final class ScopedAuthorizationChecker
{
    public function authorize(Principal $principal, string $permissionCode, OrganizationalUnit $target): bool
    {
        if (! $target->is_active) {
            return false; // §13: an inactive target is never an eligible authorization target
        }

        if (! $this->effectivePermissions->has($principal, $permissionCode)) {
            return false;
        }

        return $this->resolveScope->__invoke($principal)->covers($target->getKey());
    }
}
```

This class, and the query/value object it composes, are the "reusable authorization boundary for
future HR commands/queries" the S08 authorization's §4 calls for. S08 itself calls it only from its
own test suite and from the effective-scope read endpoint (§18) — there is no HR/Reporting caller yet
because there is no HR/Reporting module yet.

## 12. Commands

Exactly two mutations, `App\Modules\Security\Application\Commands`:

- **`GrantOrganizationalScope`** — `handle(Principal $principal, string $scopeKind, ?OrganizationalUnit $unit, ?Principal $grantedBy): OrganizationalScopeGrant`. Validates `$scopeKind` is `GLOBAL` or `UNIT`, that a `UNIT` grant carries a unit and a `GLOBAL` grant does not (defence in depth — the database `CHECK` is the real backstop). Inserts one row; a concurrent duplicate is caught as a `QueryException`/unique-violation and rethrown as `DuplicateOrganizationalScopeGrantException` (409) — the exact `AssignRoleToPrincipal`/`GrantPermissionToRole` pattern (§13 of the S03 authorization already established this for every other grant-shaped table).
- **`RevokeOrganizationalScope`** — `handle(OrganizationalScopeGrant $grant): void`. A real `DELETE` of the grant row — no soft-delete flag, no `version` check (§14) — mirroring `RemoveRoleFromPrincipal`'s `DELETE`.

There is deliberately no `ChangeOrganizationalScope`/update command: changing a principal's scope
from `UNIT(A)` to `UNIT(B)` is a revoke of one grant plus a grant of another, exactly as changing a
role assignment is a remove-and-reassign, not an in-place edit, everywhere else in S03.

## 13. Inactive units and target eligibility

Three distinct situations, deliberately given different treatment because they are different
questions:

1. **The grant's root unit becomes inactive.** The grant is not touched (no cascading delete — a
   grant "preserves its explicit reference identity" per the ADR) and descendant resolution in §11
   does not filter on `is_active`, so the grant continues to project scope to its subtree exactly as
   before. This mirrors S07's own established rule that a deactivated unit's active children are
   unaffected by the parent's status (S07 spec §8 D20 — "deactivated-parent-with-active-children
   allowed").
2. **An intermediate ancestor between the grant root and a descendant is inactive.** Same answer:
   descendant membership is pure tree structure, so an inactive unit in the middle of the chain does
   not sever the ones below it from being in scope.
3. **The exact unit being checked (the authorization *target*) is inactive.** `ScopedAuthorizationChecker::authorize()` denies unconditionally, before even consulting permissions or scope (§11's code above). This is the one place activity status matters, and it is a hard gate, not a scope question: "inactive hierarchy state must NOT be used as an authorization loophole," and the authorization explicitly prefers default-deny for a target that is not eligible under the approved active-organization semantics. Reactivating the unit makes it eligible again on the very next check — nothing is cached.

This reconciles "grants survive deactivation" with "deactivation must never become a loophole": the
loophole risk is specifically about the *target* being checked, not about intermediate structure, so
that is exactly where the gate lives.

## 14. Concurrency and lifecycle model

`security.organizational_scope_grants` is a grant/assignment row, not a versioned business entity —
the same category as `security.principal_roles`/`security.role_permissions`, neither of which has a
`version` column (S03 §13/§14 of its own authorization: those two tables use a `UNIQUE` constraint
for race safety and a real `DELETE` for revocation, never optimistic concurrency). S08 follows the
same convention rather than inventing a versioned variant merely because `org.organizational_units`
happens to have a `version` column — that column belongs to the *unit*, a genuine long-lived business
entity with independent mutable fields (name, parent, active flag); a scope grant has no mutable
field to protect with a version check (§12: it is granted or revoked, never edited in place).

## 15. Administrative safety — no new lockout invariant is required

`security.organization_scopes.manage` is **not** added to
`SecurityAdministrationCapability::REQUIRED_PERMISSIONS` (`ADR-S08-002` is explicit: do not modify
that historical definition). This is deliberate, not an oversight, and here is the mechanical
reasoning for why no *new*, additive lockout guard is needed either:

- Scope-grant administration (grant/revoke) is gated by exactly one permission,
  `security.organization_scopes.manage`, itself obtained the ordinary way through S03 RBAC. Losing
  the last principal who holds that permission is the same category of risk as losing the last
  principal who holds `organization.manage`, `reference.manage`, or any other non-security-critical
  permission in the system today — and none of those get special lockout protection either. The
  S03 invariant protects exactly one thing: the ability to *recover* from a lockout at all (reactivate
  an account, reshape a role assignment) — that recovery path is untouched by S08 and remains
  sufficient: an administrator who still holds `security.users.status.manage` +
  `security.role_assignments.manage` can always grant a role carrying
  `security.organization_scopes.manage` back to someone, exactly as they could for any other
  permission today.
- Granting or revoking a scope grant can never change who is "capable of security administration" as
  `SecurityAdministrationCapability` defines it, because scope grants do not touch
  `principal_roles`/`role_permissions` at all. So `SecurityAdministrationGuard` needs no new call site
  and no modification — S08 introduces zero risk to the existing invariant.
- Scope-grant *administration* itself is never scope-restricted (§17) — it is gated by a permission
  only — so there is no scenario where revoking someone's *scope* (as opposed to their permission)
  reduces anyone's administrative capability.

Net result: S08 needs no analogous `SecurityAdministrationGuard`-style advisory-lock-and-recheck
mechanism of its own, because it introduces no new way to reach a state that mechanism exists to
prevent. This conclusion is proven, not assumed, by
`AdministrativeSafetyTest::test_revoking_the_last_scope_administrators_permission_is_allowed_and_does_not_touch_the_last_security_admin_invariant`
and its sibling tests (§21).

## 16. Audit

Both commands run through `AuditedCommandExecutor::run()` — no S08 mutation bypasses it, matching
every other mutation in the codebase. Audit specs:

- `security.organizational_scope.grant` — `targetType: 'security_organizational_scope_grant'`,
  `targetId` the new grant's id, `changes` = `{scope_kind, organizational_unit_id}` (both safe,
  non-sensitive identifiers — no principal data beyond IDs already visible elsewhere).
- `security.organizational_scope.revoke` — same target type/id, `changes` = `{}` (the grant's own
  audit-creation record already carries the before-state; the composite target id is enough context,
  the same allowlist-is-empty reasoning `RoleAssignmentController::destroy` already uses for
  `security.role_assignment.remove`).

No second audit mechanism is introduced. A rejected mutation (e.g. a duplicate-grant race) follows
the existing rejection-event pattern only where a security invariant is what caused the rejection —
there is none here (§15), so a duplicate-grant conflict is a plain `409` from
`DuplicateOrganizationalScopeGrantException`, the same as every other duplicate-grant conflict in the
codebase, with no separate `SECURITY_EVENT` needed (that pattern is reserved for invariant
rejections like `LastSecurityAdministratorException`, per `RoleAssignmentController::destroy`).

## 17. S07 endpoints stay global

`organization.view`/`organization.manage` are not touched, not narrowed, and not made
scope-aware. S07's own hierarchy-management endpoints (`/organization/units/*`) continue to be
gated purely by those two global permissions, exactly as S07 shipped them. `ScopedAuthorizationChecker`
is net-new infrastructure for a caller that does not exist yet; it is never wired into
`OrganizationalUnitController`.

## 18. New permission and API surface

One new permission, seeded the same way every other module seeds its own catalog (mirrors
`2026_09_27_000002_seed_security_organization_permissions.php`):

```
security.organization_scopes.manage
```

Added to `PermissionCatalog::ALL` (the S03 catalog class, since this is a Security-module
permission — not a new module's own catalog) and to a new migration
`2026_09_28_000002_seed_security_organizational_scope_permission.php`, mirroring S07's
seed-migration-mirrors-catalog convention. This single permission gates every S08 route — both
mutations and reads — mirroring the precedent already set by `security.role_assignments.manage`
(which alone gates both creating and removing a role assignment; there is no separate "view role
assignments" permission in S03 either).

Routes, under the existing `/api/v1/security` group (`auth:web`, `principal.active`,
`resolve.context`, already applied to the whole group):

| Method | Path | Controller#method | Permission |
| --- | --- | --- | --- |
| `GET` | `/principals/{principal}/organizational-scopes` | `OrganizationalScopeController#index` | `security.organization_scopes.manage` |
| `POST` | `/principals/{principal}/organizational-scopes` | `OrganizationalScopeController#store` | `security.organization_scopes.manage` |
| `DELETE` | `/principals/{principal}/organizational-scopes/{organizationalScopeGrant}` | `OrganizationalScopeController#destroy` | `security.organization_scopes.manage` |
| `GET` | `/principals/{principal}/organizational-scopes/effective` | `OrganizationalScopeController#effective` | `security.organization_scopes.manage` |

`effective` is registered before the `{organizationalScopeGrant}` wildcard route (same
route-ordering discipline S07 already used for `/units/roots` vs `/units/{organizationalUnit}`), so
it is never captured by route-model binding.

`store` request body: `{"scope_kind": "GLOBAL"|"UNIT", "organizational_unit_id": "<uuid>|null"}`.
`effective` response: `{"is_global": bool, "organizational_unit_ids": [...]}` (empty array when
`is_global` is `true`).

## 19. Resource shape

`OrganizationalScopeGrantResource`: `id`, `principal_id`, `scope_kind`, `organizational_unit_id`,
`granted_at`, `granted_by`. No `version` (§14 — there is none).

## 20. Exceptions

- `DuplicateOrganizationalScopeGrantException` (`RuntimeException`, mapped to `409`) — mirrors
  `DuplicateRoleAssignmentException`/`DuplicatePermissionGrantException` exactly, including the
  `Errors::isUniqueViolation($e)` catch-and-rethrow pattern in the command.
- Standard `ModelNotFoundException` → `404` for a missing principal/unit/grant (unchanged framework
  behaviour, same as every other module).
- Standard `422` for validation failures (invalid `scope_kind`, missing `organizational_unit_id` for
  a `UNIT` grant caught at the request-validation layer before it ever reaches the database `CHECK`).

## 21. Required test coverage

New `tests/Feature/Security/OrganizationalScope/` (or alongside existing `Security` feature tests,
following whichever grouping the existing suite uses for sub-areas):

- **Schema/migration**: create/rollback/reapply round-trip (mirrors `MigrationLifecycleTest`'s
  established pattern); `CHECK` constraint rejects a `UNIT` row with a null unit and a `GLOBAL` row
  with a non-null unit, proven directly at the database via a raw insert (mirrors S07's
  self-parent-CHECK test style); both partial unique indexes proven directly (two GLOBAL grants for
  the same principal rejected; two UNIT grants on the same unit for the same principal rejected; a
  GLOBAL and a UNIT grant for the same principal coexist without conflict).
- **Grant/revoke lifecycle**: grant `GLOBAL`, grant `UNIT`, revoke either, each audited
  (`security.organizational_scope.grant`/`.revoke` present in `audit.audit_entries`); duplicate grant
  → `409` via `DuplicateOrganizationalScopeGrantException`; grant referencing a nonexistent unit →
  `404`; grant with an invalid `scope_kind` → `422`.
- **Effective scope resolution — coverage semantics**: `UNIT(A)` covers `A` itself, every descendant
  of `A`, and does not cover an unrelated unit; `UNIT(A) + UNIT(B)` covers the union of both subtrees;
  a `GLOBAL` grant covers an arbitrary unit with no other grants present; `GLOBAL` + any `UNIT` grants
  together still resolve to global coverage.
- **Multi-role interaction**: a principal obtaining the same permission through two different roles,
  scoped by one `UNIT` grant, is authorized within that unit regardless of which role's grant of the
  permission is inspected (proving §5's "no pairing between scope and the specific role" rule).
- **RBAC-absent-deny / scope-absent-deny**: no permission at all → denied regardless of scope;
  permission present but zero scope grants → denied (never "defaults to global"); permission and a
  `UNIT` grant present, target outside that grant's subtree → denied.
- **Inactive-unit semantics (§13)**: grant's root unit inactive, descendant still active → subtree
  membership unaffected, but the *active descendant itself* is still evaluated as a target normally
  (this proves deactivation of an ancestor is not itself a denial trigger); target unit itself
  inactive → denied even though it would otherwise be in scope; reactivating the target restores
  authorization on the next check with no other state change.
- **Unit movement (§6)**: a unit moved out of a granted subtree stops being covered on the very next
  check; a unit moved into a granted subtree starts being covered on the very next check — with zero
  writes to `organizational_scope_grants` in either case.
- **Concurrency**: two concurrent attempts to grant the same `GLOBAL` (or same `UNIT`) scope to the
  same principal — only one succeeds, the other observes the unique-violation-mapped `409` (mirrors
  the existing `AssignRoleToPrincipal`/duplicate-race test shape; a true two-connection test if the
  harness supports it, otherwise a direct proof the partial unique index exists and is what rejects
  the second row).
- **Administrative safety**: revoking the only principal's `security.organization_scopes.manage`
  permission is allowed and succeeds (not blocked by any invariant); doing so does not affect
  `SecurityAdministrationCapability::isCapable()` for any principal; granting/revoking scope grants
  never changes the result of the existing S03 last-security-administrator checks (regression proof
  that S08 did not weaken §12 of the S03 authorization).
- **Authorization on S08's own routes**: all four routes return `401` unauthenticated, `403` for an
  authenticated principal without `security.organization_scopes.manage`.
- **API error shape**: no SQL/stack-trace leakage (mirrors `ApiEndpointsTest`'s existing check),
  standardized `401`/`403`/`404`/`409`/`422`.
- **`ScopeBoundaryTest` (Security)**: `security` schema contains exactly seven tables now (the
  original six plus `organizational_scope_grants`); no S08 route lacks a `permission:` middleware
  entry; no Person/Employee/Employment leakage; no report-filtering code; no frontend files added;
  `organization.view`/`organization.manage` are unchanged and still global (regression proof for
  §17); the four forbidden columns list from the existing `Security/ScopeBoundaryTest.php` is
  extended with nothing new needed (it already checks for `organization_unit_id`/`branch_id`/`scope`
  on *other* tables — the new column now legitimately exists only on the one new S08 table, which
  the test is updated to exclude from that blanket check, mirroring exactly how S07 itself was
  excluded from the `org`-schema-emptiness checks in S06/earlier `ScopeBoundaryTest`s).
- **`MigrationDisciplineTest`**: auto-covers the two new migrations with zero code change (existing
  infrastructure).

## 22. What S08 still does not build

No HR/Reporting consumer of `ScopedAuthorizationChecker` (none exists yet — S08 only proves the
engine works via its own tests). No UI. No report filtering. No deny rules. No per-permission scope.
No scope-per-role. No new S03 last-security-admin-style advisory-lock guard (§15 shows none is
needed). No change to `organization.view`/`organization.manage`. No Person/Employee/Employment. No
S09.

## 23. Acceptance criteria

Same acceptance gate as stated in the S08 authorization's own §23, evaluated against this
specification: discovery/ADR-resolution/architecture-review PASS (this document); no unresolved
consequential policy decision remains (`D08`/`D11` resolved by the two ADRs; every question that
cascaded from them is resolved in this document, §§5–15); security model explicit and centralized in
`ScopedAuthorizationChecker` (§11); default deny preserved end-to-end (§21's RBAC-absent-deny/
scope-absent-deny tests); S03 RBAC untouched; S07 hierarchy reused, not duplicated (§6/§11); no
privilege escalation path (§21's authorization tests, plus the full adversarial review pass still to
come); administrative safety preserved with reasoning, not silence (§15); audit/concurrency/
PostgreSQL/full-tests/Pint/`git diff --check` all PASS; no Person/Employee/Employment; no S09; no
cross-project contamination; `_to_delete/` untouched.
