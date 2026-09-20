# Deferred framework migrations

These three files are the unmodified Laravel skeleton migrations (users / password reset / sessions,
cache, jobs). They are kept here — byte-for-byte as shipped — but **deliberately outside the
directory Laravel scans** (`database/migrations`; Laravel does not recurse into sub-directories),
so `php artisan migrate` does not execute them.

Reason (S02 decision): `0001_01_01_000000_create_users_table.php` creates authentication-related
structures (`users`, `password_reset_tokens`, `sessions`). Authentication/RBAC belongs to a later
stage; S02 must not claim ownership of that schema. Cache and queue run on Redis (approved
architecture), so the database-backed `cache`/`jobs` tables are not needed either.

They are not deleted and not redesigned. The stage that owns identity/authentication decides
whether to adopt, adapt or replace them and moves or supersedes them under its own authorization.
