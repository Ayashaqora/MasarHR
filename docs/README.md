# MasarHR documentation

| Document | Purpose |
| --- | --- |
| [development-setup.md](development-setup.md) | Prerequisites, environment, run/test commands, PostgreSQL and Redis |
| [architecture-foundation.md](architecture-foundation.md) | S01 structure, layering conventions, API conventions, S01 boundaries |
| [database-persistence-foundation.md](database-persistence-foundation.md) | S02 PostgreSQL extensions, schemas, UUID/time/temporal conventions, transactions, migration discipline, test-DB safety |
| [security-access-foundation.md](security-access-foundation.md) | S03 security principal/credential/RBAC model, authentication & session security, last-administrator invariant, bootstrap, API security semantics, S04/S07/S08 boundaries, frontend security foundation |
| [audit-command-infrastructure-specification.md](audit-command-infrastructure-specification.md) | S04 specification v1.0 (pre-implementation) — CommandContext, actor/correlation model, transaction boundary, audit trail model, immutability design, S03 retrofit plan |
