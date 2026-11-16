# Delete organization accounts

Admin → Organizations → Delete opens a confirmation naming the organization.
Deletion removes its account from organization management and exports, sets it
inactive, increments its authentication version, and revokes refresh sessions and
outstanding email OTP challenges. Deleted accounts cannot be edited/reactivated
through the organization update endpoint. Only an active administrator may delete
an organization; administrator and student accounts cannot be targeted.
Background attendance finalization also excludes inactive/deleted organizations,
so their scheduled events cannot create new absence fines after deletion.

The historical users row, organization name, username, email, program mappings,
attendance, fines, notifications, vouchers, schedules, payment claims, allocations
and receipts are retained. Username and email stay reserved. This preserves report
joins, balances and receipts. No financial or historical rows are deleted. Pending
payment claims must be resolved before deletion is allowed. A successful deletion
is recorded in the activity log in the same transaction; logging failure rolls the
whole deletion back. Repeated deletion requests are idempotent.

## Deployment

Apply the schema **before deploying the backend**. This adds one nullable column
and does not delete any organization. Choose one method:

1. **Navicat / free Render:** open the database used by the PHP API, open a new SQL
   query, run the complete contents of
   `console/sql/organization_account_deletion.sql`. The script is safe to rerun.
   Apply it to any separate local development database as well.
2. **PHP migration runner:** with the intended API database configured, run
   `php console/migrate.php --run --only=organization_account_deletion`, then
   `php console/migrate.php --status --only=organization_account_deletion`.

The Navicat alternative only adds the column; a later migration-runner invocation
detects that it already exists and records the migration normally.

The backend also supports a deployment where this column is not present yet:
dashboard counts, organization listing/export and existing editing continue to
work, and only organization deletion is unavailable until setup is complete.
Deletion returns an actionable setup message, rather than causing the dashboard
or organization list to fail. The UI disables Delete until the backend reports
that its database schema is ready.

Deploy the PHP backend, then rebuild/deploy React. In Admin → Organizations,
select **Delete**, check the organization name, and confirm. Existing org history
remains available through its original records. There is no automatic restore or
permanent financial-record purge in this feature.

## Verification

`php console/test_organization_deletion.php` uses isolated SQLite fixtures and the
real LavaLust Query Builder. It checks admin authorization, wrong-role targets,
stale confirmations, pending-payment protection, transactional rollback, access
revocation, OTP/session removal, listing/export exclusion, history preservation,
idempotency and the additive migration. It makes no hosted database/provider calls.
