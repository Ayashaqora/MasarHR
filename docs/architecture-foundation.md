# Architecture foundation (S01)

MasarHR is a **Modular Monolith**. S01 only establishes structure that can carry domain modules
later; it implements no HR functionality.

## Backend layout

```
backend/app/
└── Modules/
    └── Platform/                      infrastructure-level module (S01)
        └── Presentation/Http/Controllers/HealthController.php
```

Convention for every future module (to be confirmed by the Architecture Authority when the first
domain module is authorized):

```
app/Modules/<Module>/
├── Domain/           entities, value objects, domain rules — no framework dependencies
├── Application/      use cases / services orchestrating the domain
├── Infrastructure/   persistence, external services, framework adapters
└── Presentation/     HTTP controllers, requests, resources
```

Dependencies point inward: Presentation → Application → Domain, with Infrastructure implementing
contracts. Modules do not reach into each other's internals. Empty layer directories are not
pre-created.

## API conventions

- REST, JSON, prefix `/api/v1` (`bootstrap/app.php`, routes in `routes/api.php`).
- Responses for `api/*` are always JSON, including errors (standard Laravel error behavior; no
  domain error taxonomy is defined in S01).
- `GET /api/v1/health` → `{ "status": "ok", "service": "masar-hr-api", "version": "v1", "timestamp": "<ISO-8601>" }`.
  Liveness only; it does not report database/Redis state or any configuration.

## Database

PostgreSQL only. S01 adds no HR tables. The only migrations are Laravel's framework-standard ones:

| Migration | Tables | Purpose |
| --- | --- | --- |
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` | Framework default; not an HR/identity design |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` | Framework default |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` | Framework default |

These are skeleton artifacts, not MasarHR domain schema. Authentication/RBAC is a later stage; the
default `User` model and `users` migration exist only because Laravel ships them.

## Frontend layout

```
frontend/src/
├── app/          bootstrap: App, router, ErrorBoundary, navigation config
├── layouts/      AppShell (header, sidebar navigation, main landmark)
├── pages/        route-level pages (home, navigation placeholders, not-found)
├── features/     feature/module boundary — currently only `system-status`
├── shared/
│   ├── api/      the only code that talks to /api/v1 (client + error normalization)
│   ├── config/   env.ts — single source of runtime configuration
│   ├── hooks/    shared hooks
│   └── ui/       shared presentational components
├── i18n/         locale definitions, Arabic (source) and English catalogs, provider
├── styles/       design tokens + base + component CSS (logical properties for RTL)
└── test/         test setup and helpers
```

- Arabic-first: `<html lang="ar" dir="rtl">`; the provider keeps `lang`/`dir` in sync with the locale.
- Western digits: `Intl` formatting uses the `-u-nu-latn-ca-gregory` locale extension.
- English-ready: `en` catalog is type-checked against the Arabic catalog; no language switcher is built.
- The navigation entries (Home, Employees, Organizational structure, Reports, Settings) are
  placeholders. Their pages show no data and no controls.

## Error handling

- Frontend: `ErrorBoundary` + router `errorElement`; `ApiError` normalizes network, timeout, HTTP
  and parse failures; user-facing text is localized and never shows raw server messages.
- Backend: standard Laravel behavior with JSON rendering for API routes.

## Out of scope in S01

HR schema, reference data, employees, organization, contracts, leave, reporting, imports/exports,
authentication/RBAC, dashboards, production deployment, and every later stage.
