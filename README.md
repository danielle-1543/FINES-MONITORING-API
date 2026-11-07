# Fines Monitoring API

LavaLust API for the React fines monitoring system. Local development uses WAMP,
PHP 8+ and the existing MySQL database configured in `.env`.

## Application code

- `app/controllers`: HTTP endpoints and validation.
- `app/models`: database queries and application records.
- `app/libraries/Api.php`: JSON responses, JWT authentication, token issuing,
  rate limiting and activity logging used by the controllers.
- `app/config/migration.php`: migration settings only.
- `app/libraries/FinesMigrationRunner.php`: migration execution and completion tracking.
- `app/migrations`: numbered migration classes with `up()` and `down()` methods.
  Files `003`–`013` contain the application's 11 custom database changes.
- `console/migrate.php`: migration commands.
- `console/cli.php`: LavaLust's CLI, used by the root `lava` command.
- `console/finalize_attendance.php`: attendance absence worker.

Application queries use LavaLust Query Builder for ordinary reads, joins,
inserts, updates, deletes, filtering and pagination. Parameterized SQL remains
for operations the installed builder cannot express, including row locks,
atomic `INSERT IGNORE ... SELECT`, unions and derived financial reports.
Schema migrations use PDO because they create or alter database structures.

## Database updates

Start MySQL through WAMP, then run commands from `C:\wamp64\www\FINES_API`:

```powershell
php console/migrate.php --list
php console/migrate.php --status
php console/migrate.php --run --only=program_catalog
```

Use `--run` without `--only` to run all pending custom migrations. These steps
extend the existing application schema; they are not a blank-database installer.
Loading configuration does not run migrations. The custom CLI preserves the
existing `fines_schema_migrations` history and migration keys, so previously
completed steps remain completed. It discovers files `003`–`013` automatically.
The files also expose LavaLust's `up()`/`down()` class interface; automatic
rollback of these data-preserving changes is intentionally unsupported.
The original `000`–`002` files are framework examples, not a complete installer
for the application's existing base tables. The custom CLI skips those examples.

Academic programs and default college memberships are read from database tables
through `ProgramCatalogModel`. Organization-specific program selections remain
in `organization_programs`. No organization config file is used.

## Attendance worker

```powershell
php console/finalize_attendance.php --watch
```

Keep this running when ended attendance windows should be finalized without
waiting for an API request. It checks every 15 seconds.

## React development server

```powershell
cd C:\wamp64\www\FINES_REACT\fines_monitoring_react
npm run dev
```

The framework's license and attribution remain in `LICENSE` and its source files.
