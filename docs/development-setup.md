# Development setup

## Project identity

- Arabic: مسار — نظام إدارة شؤون الموظفين والقوى العاملة
- English: Masar — Human Resources & Workforce Management System
- Technical identifier: `MasarHR`
- Repository: <https://github.com/Ayashaqora/MasarHR> (monorepo, root `C:\Projects\MasarHR`)

## Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | 8.3+ (architecture target 8.5) | Extensions below |
| Composer | current 2.x | Run `composer diagnose`; do not install dependencies with a Composer flagged as outdated/insecure |
| Node.js / npm | 24.x / 11.x | |
| PostgreSQL | 18.x (any supported version) | **Mandatory** — SQLite is not supported |
| Redis | 7 | Not required to run S01 tests; see [Redis](#redis) |

### PHP extensions

Required: `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `intl`, `ctype`, `tokenizer`.
Redis runtime additionally needs `redis` (phpredis).

On Windows (winget PHP), the DLLs ship in `ext/` but are commented out in `php.ini`. Enable them
(remove the leading `;`): `extension=curl`, `extension=fileinfo`, `extension=pdo_pgsql`,
`extension=pgsql`, `extension=zip`. Check with `php -m`.

> **Multiple PHP installs:** if XAMPP is on `PATH` first, `php` and `composer` resolve to PHP 8.2,
> which cannot run Laravel 13. Put the PHP 8.3+ directory first on `PATH` (or set `PHPRC` to an
> ini that enables the extensions) before running any backend command.

## Environment configuration

Environment values come from `.env` files. Only the `*.example` files are versioned.

```powershell
Copy-Item backend\.env.example  backend\.env
Copy-Item frontend\.env.example frontend\.env   # optional; defaults work with the dev proxy
```

Never commit `.env` files. Never put secrets in `frontend/.env*` — every `VITE_*` value is public.

`backend/.env.example` ships with `APP_DEBUG=false` (debug mode exposes stack traces and
configuration). Set `APP_DEBUG=true` in your own local `backend/.env` only when debugging.

## PostgreSQL

PostgreSQL is the authoritative database. Create a dedicated role and databases (do not run the
application as a superuser):

```sql
CREATE ROLE masarhr LOGIN PASSWORD '<choose-a-strong-password>';
CREATE DATABASE masarhr      OWNER masarhr ENCODING 'UTF8';
CREATE DATABASE masarhr_test OWNER masarhr ENCODING 'UTF8';
```

Then set `DB_USERNAME` and `DB_PASSWORD` in `backend/.env`. `phpunit.xml` points the test suite at
`masarhr_test`, reusing the credentials from `backend/.env`.

## Backend (Laravel 13)

```powershell
cd backend
composer install
php artisan key:generate        # only if APP_KEY is empty
php artisan serve               # http://127.0.0.1:8000
curl http://127.0.0.1:8000/api/v1/health
```

| Task | Command |
| --- | --- |
| Tests | `composer test` (or `php artisan test`) |
| Style check | `composer lint` |
| Auto-format | `composer format` |
| DB connectivity | `php artisan db:show` |

`php artisan migrate` enables the `btree_gist` extension and creates the eight empty schema
namespaces; it creates no tables other than Laravel's `migrations` bookkeeping table. See
[database-persistence-foundation.md](database-persistence-foundation.md).

The test suite runs only against `masarhr_test` (enforced by a guard that aborts on any other
database) and needs no manual migration. Never run `migrate:fresh` or `db:wipe` against `masarhr`.

## Frontend (React 19 + Vite)

```powershell
cd frontend
npm install
npm run dev        # http://localhost:5173 — /api is proxied to the backend
```

| Task | Command |
| --- | --- |
| Tests | `npm test` |
| Typecheck | `npm run typecheck` |
| Lint | `npm run lint` |
| Production build | `npm run build` |

The API client reads `VITE_API_BASE_URL` (default `/api/v1`). In dev, Vite proxies `/api` to
`VITE_DEV_API_PROXY_TARGET` (default `http://127.0.0.1:8000`).

## Redis

Redis 7 is the architecture baseline for cache, queue and sessions. `backend/.env.example` and the
Laravel config already target it (`CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` = `redis`,
`REDIS_CLIENT=phpredis`).

**Current local limitation:** no Redis server runs on the S01 development machine, and PHP has no
`redis` extension installed, so Redis runtime verification is **DEFERRED**. The test suite
overrides cache/queue/session to `array`/`sync` (see `phpunit.xml`) so it does not need Redis.
No S01 feature depends on Redis at runtime. Do not substitute another technology; provide a real
Redis 7 (e.g. `infrastructure/docker/compose.dev.yml`, which requires a running Docker daemon) and
the phpredis extension to verify it.

## Security and data handling

- Never commit real HR data, national IDs, real Excel/CSV/PDF exports, database dumps, or secrets.
- Use synthetic data for tests and demos.
- `.gitignore` excludes `.env*`, keys, dumps, spreadsheets, PDFs and export directories. If a
  legitimate synthetic fixture must be versioned later, that requires explicit authorization.
- The `/api/v1/health` endpoint is infrastructure-only and exposes no configuration or credentials.

## Stage governance

Work proceeds in stages authorized by the Architecture Authority. The current stage is **S02 —
Database & Persistence Foundation** (S01 is closed). No later stage may start, and nothing may be committed, pushed, tagged or
released, without explicit authorization. See `CLAUDE.md`.
