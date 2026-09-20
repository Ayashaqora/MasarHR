# Infrastructure

Minimal local-development infrastructure for S01. There is **no production deployment
infrastructure** in this stage.

- `docker/compose.dev.yml` — optional Redis 7 for local development. Docker is not required for S01.
  Not verified in S01 because the Docker daemon was unavailable (only `docker compose config`
  validation was possible).

PostgreSQL runs natively on the developer machine; see `docs/development-setup.md`.
