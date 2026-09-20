# Cross-cutting tests

Reserved for tests that span `backend/` and `frontend/` (for example end-to-end checks). None exist
in S01.

Unit and feature tests live beside the code they cover:

- Backend: `backend/tests/` (`composer test` from `backend/`)
- Frontend: `frontend/src/**/*.test.ts(x)` (`npm test` from `frontend/`)

Test data must be synthetic — never real employee data.
