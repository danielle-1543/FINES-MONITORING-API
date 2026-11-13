# Semester management: setup and deployment

Academic Settings uses an academic year and First/Second/Summer term. Dates are
not required. Event scanning times and payment opening/closing times retain the
existing Philippine-time no-past-date policy.

## Deploy in this order

1. Back up the hosted database using your database provider's backup/export tool.
2. In a PowerShell terminal on your laptop (Render shell is not required):

   ```powershell
   cd C:\wamp64\www\FINES_API
   php console/migrate.php --run --only=academic_periods
   php console/migrate.php --status --only=academic_periods
   ```

   The local backend `.env` must point to the hosted database, as in your earlier
   migration setup. Never put database credentials in the React environment or
   commit `.env`. If `php` is not recognized, use the full path to WAMP's PHP
   executable. Wait for the migration to finish successfully before deploying.
   The runner applies missing dependencies first. The new migration adds tables
   and nullable references; it does not guess semesters or alter old fine amounts.
3. Commit/push the backend changes to its application remote and let the API
   service deploy successfully.
4. Commit/push the frontend changes and deploy the React static site. Refresh
   after the deployment. Existing API URL, OneSignal and Xendit environment
   values remain in use. No new environment variables are required.
5. Sign in as Admin and open **Academic Settings**. Enter the academic year start
   (e.g. 2026 means 2026–2027), choose a semester, and make it current. There are
   no semester date fields to fill in.
6. Open **Student Management**. Register/import students into the selected open
   semester. For existing students, reuse their exact student number and name;
   an import adds enrollment to that account instead of generating another QR.
   Already-enrolled rows are skipped; use their profile to correct them.
7. Each organization creates its payment schedule for the same academic semester,
   with a future/today opening and closing date and optional cap. New events
   also select an open academic semester, defaulting to the current one.
8. Check one enrolled student's attendance/violation notification, Fine Archives,
   semester totals, and a small **test** checkout before wider use. Xendit remains
   test-only. Local checks cannot verify live OneSignal delivery or provider
   credentials.

## Each new semester

Create the next academic period and make it current. Import the next enrollment
list (or use Student Profile → Semester enrollment). Update year, section and
program there, retaining the existing student/account/QR. Previous enrollments,
fines, claims and receipts remain in their original periods. Setting a global
current period does not automatically enroll everyone or promote students.

Use **Correct** for a mistake in an existing semester. Editing an older semester
does not change the current profile unless explicitly selected. Closing a period
prevents new enrollment/recording in it; scheduled payment collection is governed
by its organization's payment window. Reopen the academic period to resume
recording. Select a semester in **Fine Archives** or student history to view old
records without entering past dates.

## Existing records

Old records initially appear under **Legacy / Unassigned**. In Academic Settings,
load legacy records, select an ended event or standalone violation and its correct
semester, preview, then confirm classification. Review this against your actual
school records; no dates or semesters are inferred automatically. Classification
is audited and retains source IDs, original amounts, payment allocations, existing
cap rules and notification/push idempotency keys. Legacy cap rules stay separate
from newly created academic-period schedules. Existing legacy payment windows can
be edited to today/future collection times without changing their cap coverage.

## Payments and reports

Students choose a semester, then an eligible organization and an open payment
schedule, then **Pay fines**. Cash/GCash or Xendit test checkout appear at that
point. A positive amount up to the available balance may be submitted; partial
payments allocate oldest eligible fines first within that semester/organization.
Pending claims reserve the amount; only confirmed payments reduce the balance.
After full settlement the remaining balance is zero. One pending claim per
organization prevents duplicate collection across semesters.

Archives summarize original amounts, amounts after cap/discount, paid, pending and
remaining amounts per semester and organization, with paginated fine/payment
allocation tables and exports. Paid records remain available. Historical ownership
uses the original organization, while payment choices retain the existing current
program eligibility rule. Existing analytics charts retain their original filters;
semester financial summaries are separate panels. No PostHog or OneSignal SDK/
worker configuration is changed by this feature.

## LavaLust structure and checks

Controllers authorize and dispatch to models. Ordinary reads/writes use LavaLust
Query Builder (`table`, `select`, `where`, `join`, `insert`, `update`). Bound SQL is
limited to UNION/derived financial reports, atomic INSERT SELECT/IGNORE and row
locks not represented by the installed builder. Related writes use LavaLust
transactions. Migration DDL follows the existing migration runner conventions.

Run local isolated checks (no .env, hosted DB, or provider calls):

```powershell
php console/test_academic_periods.php
php console/test_calendar_dates.php
php console/test_api_errors.php
```

The academic suite includes the Xendit suite and uses the actual LavaLust Query
Builder against SQLite fixtures. Also run `npm run lint`, `npm test`, and
`npm run build` from the React project.
