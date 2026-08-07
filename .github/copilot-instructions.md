# EinvaBill repository instructions for coding agents

## Project overview
- This repository is a PHP billing and management web application for RT/RW Net and ISP operators.
- The main entry point is index.php, which routes requests to the appropriate view based on role and page.
- Core bootstrap and database initialization live in app/init.php and app/database_setup.php.
- The application uses SQLite, session-based authentication, role-based access control, and license gating.

## Important constraints
- Preserve existing behavior for login, session handling, permission checks, and tenant logic.
- Avoid breaking the multi-tenant and role-based access control flow.
- When changing schemas, keep migrations safe and update versioning logic where appropriate.
- Prefer small, targeted changes over broad refactors.
- Do not introduce new dependencies unless they are clearly justified.

## Working style
- Inspect the relevant route, view, and helper files before editing.
- Reuse existing helpers in app/helpers.php where suitable.
- Validate PHP syntax after changes with `php -l <file>`.
- If you make database-related changes, confirm they remain compatible with the existing SQLite setup and migration system.
