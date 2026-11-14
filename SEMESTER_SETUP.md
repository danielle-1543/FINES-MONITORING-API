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
6. Open **Student Management**. Register/import new students into the selected
   semester. Existing student numbers are skipped. Update existing students using
   **Edit Profile** or **Multiple edits**; choose Change academic year / semester
   or include the semester with a promotion/correction. There is no separate
   enrollment workflow, status, or confirmation step.
7. Each organization creates its payment schedule for the same academic semester,
   with a future/today opening and closing date and optional cap. New events
   also select an open academic semester, defaulting to the current one.
8. Check one registered student's attendance/violation notification, Fine Archives,
   semester totals, and a small **test** checkout before wider use. Xendit remains
   test-only. Local checks cannot verify live OneSignal delivery or provider
   credentials.

## Each new semester

Create the next academic period with Make current selected, or use Set current
on an existing period. The system previews the old/new semester and student count,
then asks for confirmation. Cancel makes no changes. A stale preview is rejected
if the current semester or student data changed before confirmation.

On confirmation, one transaction saves student before/after history and a
before-change snapshot, closes the previous current semester, switches the current
period and updates ALL students to the new semester (including pending/inactive
registrations). Year level, section, course, accounts and QR codes stay unchanged.
Use Edit Profile or Multiple edits for year-level/section changes separately.
Original events, attendance, fine amounts, caps, payment allocations and
notifications retain their original IDs and semester references. Existing unpaid
balances are preserved; switching semesters does not forgive or recreate fines.

Academic Settings → Previous semester records replaces legacy-classification
controls. Expand a saved change to see the student data from before it and counts
of preserved operational records. This history starts when the confirmed rollover
feature is first used; no earlier student snapshots are guessed retroactively.
The current period cannot be closed alone: switch to another period instead.

## Payments and reports

Student Fine Archives is a personal, current-semester page. Its API uses the
authenticated student and the open current period from Academic Settings, ignoring
client-supplied student IDs and semester overrides. It shows fines and absences
(including zero-charge absences and paid fines) in one chronological list. The
organization filter affects the list; the financial summary covers every
organization in that semester. There are no allocations, exports or semester
selectors on this student page. No open current semester produces an empty state.
The student dashboard has four current-semester statistics: violation count,
original fine assessments, verified payments allocated to that semester's fines,
and the remaining adjusted balance. No historical/all-semester totals are used.
Admin/organization archives retain their existing filters, tables and exports.

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
