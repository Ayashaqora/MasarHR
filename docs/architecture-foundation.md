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

PostgreSQL only. There are no HR or business tables yet. S02 added the extension, eight empty schema
namespaces (`hr`, `ref`, `org`, `reporting`, `security`, `audit`, `automation`, `migration`), and the
UUID, time, temporal, transaction and migration conventions; see
[database-persistence-foundation.md](database-persistence-foundation.md).

Laravel's skeleton migrations (users/sessions, cache, jobs) are preserved, unmodified, in
`backend/database/migrations/_deferred_framework/` and do not run: identity is S03's decision and
cache/queue use Redis.

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

## Out of scope (S01 and S02)

HR business tables, reference data, employees, organization, contracts, leave, reporting, imports/exports,
authentication/RBAC, dashboards, production deployment, and every later stage.
