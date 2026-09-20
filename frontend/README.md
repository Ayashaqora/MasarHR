# MasarHR — frontend

React 19 + TypeScript + Vite administrative web application. Arabic-first, RTL-first, English-ready.

> Stage S01 (Project Foundation): app shell and infrastructure only. No HR functionality exists.
> Navigation entries are placeholders for later, separately authorized stages.

## Commands

Run from `frontend/` (Node.js 24.x, npm 11.x):

| Task | Command |
| --- | --- |
| Install dependencies | `npm install` |
| Dev server (proxies `/api` to the backend) | `npm run dev` |
| Tests (Vitest + Testing Library) | `npm test` |
| Type check | `npm run typecheck` |
| Lint (ESLint) | `npm run lint` |
| Production build | `npm run build` |

## Structure

See [`../docs/architecture-foundation.md`](../docs/architecture-foundation.md) for the source layout
and conventions, and [`../docs/development-setup.md`](../docs/development-setup.md) for setup.

## Configuration

Copy `.env.example` to `.env` if you need to override defaults. Every `VITE_*` value is bundled into
the browser build and is **public** — never put secrets in it.
