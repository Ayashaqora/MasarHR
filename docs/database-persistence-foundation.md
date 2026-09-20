# Database and persistence foundation (S02)

S02 establishes PostgreSQL conventions and the safety net around them. It adds **no business
tables**: no person, employee, employment, organization, reference, reporting or identity tables.

## 1. PostgreSQL extensions

| Extension | Status | Why |
| --- | --- | --- |
| `btree_gist` | Enabled by migration `2026_09_20_000001_enable_postgresql_btree_gist_extension` | A GiST exclusion constraint that combines `owner_id WITH =` (uuid equality) and `daterange WITH &&` needs GiST operator classes for `uuid`; core PostgreSQL does not ship them. Trusted extension since PostgreSQL 13, so the non-superuser database owner can create it. |
| `uuid-ossp`, `pgcrypto` | **Not enabled** | `gen_random_uuid()` is in PostgreSQL core (13+) and application-side UUIDv7 needs no extension. Tests assert they are absent, so nothing depends on them by accident. |

The migration fails explicitly on any driver other than `pgsql`. Its `down()` uses
`DROP EXTENSION ... RESTRICT`: rollback refuses when an object (for example a temporal constraint)
still depends on the extension.

## 2. Schema namespaces

Migration `2026_09_20_000002_create_database_schema_namespaces` creates exactly eight schemas:
`hr`, `ref`, `org`, `reporting`, `security`, `audit`, `automation`, `migration`. Each carries a
`COMMENT ON SCHEMA` describing its intended ownership. They are empty by design.

- `public` remains the default for framework tables (`migrations`, and later Laravel's own).
- Rollback runs `DROP SCHEMA ... RESTRICT` in reverse order. It never uses `CASCADE`; if a schema
  contains any object the rollback throws a `RuntimeException` and (transactional DDL) leaves every
  schema in place.

Later stages create tables inside these schemas explicitly (`Schema::create('hr.employees', ...)` or
qualified SQL); nothing relies on `search_path` tricks.

## 3. UUID persistence convention

- Primary keys of business tables are `uuid` (native PostgreSQL type, never `char(36)`).
- The application generates identifiers: UUIDv7 via `Illuminate\Support\Str::uuid7()` /
  Eloquent `HasUuids`. Time-ordered v7 keeps B-tree inserts append-mostly.
- `gen_random_uuid()` (core) may be a column default for rows inserted outside Eloquent, but is not
  the primary generation path.
- Identifiers carry no business meaning and are never derived from business data.
- Foreign keys use `uuid` too and always name their target schema-qualified table.

## 4. Time conventions

| Concept | Column type | Notes |
| --- | --- | --- |
| Business date (hire date, effective date, period boundary) | `date` | No time zone, so it never shifts with the session zone. |
| Audit / system instant (created, recorded, logged) | `timestamptz` | In Laravel migrations use `timestampTz()`. `timestamp()` is *without* time zone and is forbidden by `MigrationDisciplineTest`. |
| Period `[from, to)` | `date`, `date NULL` | Half-open: `effective_from` inclusive, `effective_to` exclusive. `NULL effective_to` means open-ended. |

`config/database.php` pins the PostgreSQL session time zone to `'UTC'` (fixed, not env-driven). A
business date is therefore never derived from a `timestamptz` implicitly. `TimeSemanticsTest` proves
both directions: the derived date depends on the session zone (which is why business dates are
`date` columns) and a `date` column is stable across zones.

Half-open periods let adjacent periods meet without gap or overlap: `[2026-01-01, 2026-03-01)` and
`[2026-03-01, NULL)`. Inclusive end dates are not used.

## 5. Temporal primitives

`App\Modules\Platform\Infrastructure\Persistence\Postgres\TemporalConstraints` builds SQL for the
canonical pattern. It only produces SQL strings (pinned by unit tests) and is exercised on
test-only tables in real PostgreSQL by `TemporalRangeTest`. No production table uses it yet.

```sql
ALTER TABLE "hr"."example" ADD CONSTRAINT "example_no_overlap"
  EXCLUDE USING gist ("owner_id" WITH =, daterange("effective_from", "effective_to", '[)') WITH &&);

ALTER TABLE "hr"."example" ADD CONSTRAINT "example_valid_period"
  CHECK ("effective_from" IS NOT NULL AND ("effective_to" IS NULL OR "effective_to" > "effective_from"));
```

**Both constraints are required.** An empty range (`effective_to = effective_from`) overlaps
nothing, so the exclusion constraint alone would silently allow it. The CHECK closes that hole;
tests prove each constraint rejects what it is responsible for. Also covered: adjacent periods
accepted, overlaps rejected, open-ended vs. later period rejected, different owners independent,
`[]` input canonicalised to `[)`, and violation SQLSTATE `23P01` (exclusion) versus `23514` (check).

## 6. Transactions and concurrency

There is no generic repository, unit-of-work, event bus or outbox. The foundation is:

- `Illuminate\Support\Facades\DB::transaction()` for atomicity; nesting maps to savepoints.
- `PostgresErrorClassifier` maps exceptions to stable SQLSTATE classes: `23505` unique, `23P01`
  exclusion, `23514` check, `23503` foreign key, `23502` not-null, `40001` serialization, `40P01`
  deadlock, `55P03` lock not available.
- `isRetryable()` is true **only** for `40001` and `40P01`. Constraint violations are never
  retried; a retry cannot fix them.
- **No automatic retries are installed.** A caller may retry a whole transaction only when it is
  idempotent; that decision belongs to the use case. Tests demonstrate row locks (`FOR UPDATE
  NOWAIT`, `SKIP LOCKED`), a repeatable-read serialization failure, and `lock_timeout` on real
  concurrent connections.

## 7. Migration discipline

1. A released migration is immutable. Fix forward with a new, later migration.
2. No `CASCADE` in any migration or rollback (enforced by `MigrationDisciplineTest`).
3. No secrets, credentials or real HR/personal data in migrations or seeders.
4. `timestamp()` is not used; use `timestampTz()` (see section 4).
5. Migrations must be PostgreSQL-native; SQLite constructs are forbidden.
6. `down()` must be safe: it refuses to destroy data rather than dropping it.
7. Business tables must be created inside their owning schema (section 2), never in `public`.

## 8. Framework migrations decision

Laravel's skeleton migrations (`users`/`password_reset_tokens`/`sessions`, `cache`, `jobs`) were
moved, unmodified, to `backend/database/migrations/_deferred_framework/`. The migrator scans only
the top level of `database/migrations`, so they no longer run:

- Identity/authentication is S03's decision; S02 must not claim the `security` schema or a `users`
  table by accident.
- Cache and queue run on Redis; the database `cache`/`jobs` tables are unused.

Nothing was deleted; the folder's README documents the boundary, and S03 may restore or replace
what it needs (via a new migration, not by editing these).

## 9. Test-database safety

- `phpunit.xml` forces `DB_CONNECTION=pgsql`, `DB_DATABASE=masarhr_test` and an empty `DB_URL`
  with `force="true"`, so a developer's `.env` cannot redirect tests.
- `tests/Support/TestDatabaseGuard` runs on every test (`TestCase::setUp`): driver must be `pgsql`,
  the database name must match `^masarhr_test(_[0-9]+)?$`, and `APP_ENV` must be `testing`. Integration
  tests re-verify the *connected* database with `select current_database()` before touching it.
  Any mismatch aborts the test; there is no override.
- Integration tests never call `migrate:fresh`, `db:wipe` or `DROP DATABASE`. They roll back inside
  a transaction or use uniquely named test-only objects. The lifecycle test that migrates down/up
  runs only after the guard passes and only touches the eight S02 schemas.
- `DB::prohibitDestructiveCommands()` is enabled in production (`AppServiceProvider`).
- Never point tests at `masarhr`, and never run destructive commands against it.

## 10. Running the checks

```powershell
cd backend
php artisan migrate           # against masarhr (non-destructive: extension + 8 schemas)
composer test                 # against masarhr_test only
composer lint
```

The suite requires a reachable PostgreSQL with both databases and a non-superuser owner role
(see [development-setup.md](development-setup.md)).
