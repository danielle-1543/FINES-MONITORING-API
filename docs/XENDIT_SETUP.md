# Xendit test integration

This integration supports **test payments only**. `XENDIT_MODE=live` and production secret keys are rejected. A successful simulation confirms a claim in this application's ledger and changes its balances. Use demo student/fine records or a separate demo database. Every Xendit claim uses `XENDIT_TEST`, and receipts use `TEST-FMS-...` with a no-real-funds note. Existing Cash/GCash payments remain manually reviewed.

## Architecture and files

The implementation follows this project's LavaLust MVC structure and its installed Query Builder/API library. Controllers handle HTTP/authentication, models handle ledger transactions, and the gateway library handles Xendit's HTTPS API. Models extend `Model`; controllers extend `Controller`; dependencies load through `$this->call->model()` and `$this->call->library()`. Query Builder handles ordinary reads/writes. Parameterized raw SQL is restricted to existing claim insertion behavior and row locks. PDO is used for schema migrations, consistent with the project's migration runner.

| File | Functions and responsibility |
| --- | --- |
| `app/config/routes.php` | Registers checkout, owned status, and provider webhook routes. |
| `app/controllers/PaymentController.php` | `xendit_checkout()` authenticates a student and rate-limits creation; `xendit_status()` checks a student's own payment. Both reuse the existing student-session checks. |
| `app/controllers/XenditWebhookController.php` | `receive()` checks the callback token, limits/decodes the payload, calls the model, and returns JSON. It uses provider authentication instead of a student JWT. |
| `app/libraries/XenditGateway.php` | `assert_configured()` rejects missing/test-incompatible settings. `create_session()` calls `POST /sessions`; `get_session()` retrieves authoritative status. `verify_webhook_token()` uses `hash_equals()`. `checkout_url()` restricts redirects to approved HTTPS checkout hosts. The secret is used only in server-side Basic authentication. Requests have timeouts and no automatic retries. |
| `app/models/PaymentModel.php` | `submit()` can internally reserve a Xendit test claim, computes its amount from current payable balances, saves fine allocations and its Xendit reference atomically, and returns the claim ID. Public manual submissions cannot choose the internal Xendit path. `cancel()`/`review()` block manual modifications to provider-managed claims. |
| `app/models/XenditPaymentModel.php` | `checkout()` creates or resumes a reservation; `status()` checks ownership and refreshes provider status; `webhook()` handles completed/expired session events. `validate_session()` checks reference, session, amount, currency, country, PAY type, and automatic capture. `apply_session()` commits confirmation/expiry under locks, preserving confirmed payments on duplicate/out-of-order events. `validate_allocations()` rechecks the ledger. `fail_creation()` releases a reservation only on a definitive provider rejection. |
| `app/migrations/020_xendit_payments.php` | Adds `XENDIT_TEST` to the method enum and creates `xendit_payments`, with unique reference/session/payment IDs. Registered under migration key `xendit_payments`. |
| `Dockerfile` | Installs PHP cURL and CA certificates for the HTTPS API client. |
| `.env.example` | Documents the server-only Xendit settings and optional frontend return URL. Contains no credentials. |
| `console/test_xendit.php` | In-memory database tests for reservations, confirmation, duplicate events, expiry, authorization, failures, and unsafe links. Does not call Xendit or access application data. |

Frontend changes are in the React repository: `Payments.jsx` adds test checkout/status/resume actions and explicit TEST receipts; `StudentAccount.jsx` recognizes `#payments` on return; `utils/xenditCheckout.js` validates hosted redirect URLs; `tests/xenditCheckout.test.mjs` checks unsafe links.

## Deploy and configure, in order

1. Confirm **Xendit Test Mode** is selected in your dashboard. Use the test secret key with payment creation/read permissions.
2. In the **Render PHP API service**, set the real values:

   ```dotenv
   XENDIT_MODE=test
   XENDIT_SECRET_API_KEY=<your xnd_development_ secret>
   XENDIT_WEBHOOK_TOKEN=<verification token from Xendit webhook settings>
   FRONTEND_URL=https://fines-monitoring-react-1.onrender.com
   ```

   `FRONTEND_URL` is optional for this deployment because the code defaults to that exact website. Neither secret belongs in `VITE_` settings or React files. Keep the test key and token on the PHP server.

3. Commit/push the backend changes to the repository/branch connected to Render. In this checkout, the application remote is `fines`; `origin` points to the upstream LavaLust framework. Deploy the new Docker image. Merely restarting the old deployment will not add PHP cURL.
4. Run the additive migration against the **same database used by the deployed API**, before opening checkout:

   ```sh
   php console/migrate.php --run --only=xendit_payments
   php console/migrate.php --status --only=xendit_payments
   ```

   Run in the deployed API's shell if available. Alternatively, run from a trusted local checkout configured to connect to that same hosted database. Running it against local WAMP updates only the local database. The runner includes prerequisite payment migrations automatically. Do not change database credentials simply to run tests.

5. Commit/push and deploy the React changes. No Xendit secret or public key is needed in React for this hosted-checkout flow.
6. In **Xendit Test Mode -> Settings -> Webhooks**, set both **Payment Session - Completed** and **Payment Session - Expired** to:

   ```text
   https://fines-monitoring-api.onrender.com/api/payments/xendit/webhook
   ```

7. Use **Test & Save**. An authenticated unrelated/sample event is acknowledged without creating a payment. An invalid verification token gets HTTP 401. A browser GET to this POST-only endpoint is not a valid webhook test. The webhook has no student JWT; do not put an API secret in its URL.
8. Open the frontend, sign in as a demo student, and open Payments. Have the organization create a current/future payment window covering the demo fines. The backend enforces existing caps, discounts, organization scope, and pending reservations.
9. Click **Pay ... via Xendit Test**, complete the simulated payment in Xendit, and return to Payments. The return itself never confirms payment. The verified webhook or **Check Xendit status** fetches server-to-server status and confirms the claim. Existing checkout can be resumed without creating another session.
10. Check that the correct fine balance changes and that **View Receipt** shows **TEST PAYMENT** and a `TEST-FMS-...` receipt number.
11. Replay the completed webhook in the Xendit dashboard. Confirm only one claim/receipt exists. Test expiry as well: the claim becomes CANCELLED and its reservation is released only after provider status is verified. An abandoned/cancel-return page alone does not release a still-active session.

## Failure recovery

- **401/403 from Xendit API:** verify the backend test secret key and its payment permissions. These definitive create rejections cancel the local reservation so you can retry after fixing configuration.
- **401 from your webhook:** the verification token in Render differs from Xendit's callback token. Correct the token and resend the event.
- **Unknown route/table or missing cURL:** deploy the backend Docker changes and run the migration. Adding environment variables alone is insufficient.
- **A creation timeout with no stored session ID:** the provider may have created checkout even though its response was lost. The balance stays reserved to prevent double payment. Search Xendit for the displayed `fms-test-...` reference. Replay the session completed/expired webhook; the handler can attach the missing session ID using the reference. If no session exists, investigate and reconcile the reservation before allowing another attempt. Never recreate a session blindly or mark a paid balance cancelled manually.
- **Payment allocation changed:** the provider result is preserved as pending rather than silently applying an incorrect balance; investigate the claim/reference and reconcile before retrying.
- **Delayed confirmation on a free Render service:** wake the API, inspect delivery logs, and resend the webhook, or use Check Xendit status. The webhook returns non-2xx for failed processing so Xendit can retry.

## Local checks

```sh
php console/test_xendit.php
php console/test_api_errors.php
php console/test_calendar_dates.php
```

In the React repository: `npm test`, `npm run lint`, `npm run build`.

These tests use simulated provider responses. Real hosted checkout/webhook delivery must still be tested after deployment using your Xendit test account. The implementation does not include live payments, refunds, chargebacks, or separate organization merchant settlements.

## References

- LavaLust MVC: https://lavalust.netlify.app/docs/overview/mvc
- LavaLust models: https://lavalust.netlify.app/docs/core_topics/models
- Xendit create session: https://docs.xendit.co/apidocs/create-session
- Xendit retrieve session: https://docs.xendit.co/apidocs/get-session
- Xendit webhook verification/retries: https://docs.xendit.co/docs/handling-webhooks
