# MasarHR — Claude Code Operating Rules

Masar (مسار) — Human Resources & Workforce Management System. Monorepo: `backend/` (Laravel 13),
`frontend/` (React 19 + TypeScript + Vite), `docs/`, `infrastructure/`, `tests/`.

## Governance

- The Architecture Authority owns architecture, scope and stage decisions. Claude Code, Cursor and any
  other AI assistant act as **executors** and hold execution authority only for the scope that has been
  explicitly authorized to them.
- **Never open, start or advance a stage on your own.** Work only within the stage and scope explicitly
  authorized. Do not approve or freeze architecture.
- **No commit, push, tag, release, merge or database migration** without explicit authorization for that
  specific action.
- **STOP → REPORT** on any architectural ambiguity, conflicting requirements, or a need to change the
  frozen technology baseline. Do not resolve those by guessing.
- Do not invent HR business rules or broaden scope.

## Protect data and secrets

- Never commit real HR data (employee records, national IDs, real Excel/CSV/PDF exports) or database dumps.
- Never commit secrets: `.env` files, tokens, passwords, keys. Only `*.example` env files are versioned.
- Never put secrets in the frontend (`VITE_*` variables are public). Do not echo secret values in output.
- Test data must be synthetic.

## Frozen technology baseline

- Backend: Laravel 13, REST under `/api/v1`. PHP >= 8.3 is required by the application; PHP 8.5 is the
  target runtime baseline where available.
- Frontend: React 19, TypeScript, Vite; Arabic-first, RTL-first, English-ready.
- Database: PostgreSQL is mandatory. Never substitute SQLite (including in tests).
- Cache/queue: Redis 7. Never replace it with another technology as an architectural decision.
- Architecture: Modular Monolith with Domain / Application / Infrastructure / Presentation layering.

## Commands

See `docs/development-setup.md`. Summary:

- Backend (from `backend/`): `composer test`, `composer lint`, `php artisan serve`
- Frontend (from `frontend/`): `npm run dev`, `npm test`, `npm run typecheck`, `npm run lint`, `npm run build`

Run backend commands with a PHP version that satisfies the baseline above.
