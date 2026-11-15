# Brevo email verification

New student registrations, organization account creation, and forgotten-password resets require at least 12 characters with an ASCII uppercase letter, an ASCII lowercase letter, a digit and a symbol, up to 72 UTF-8 bytes. Spaces are permitted but do not count as symbols. The forms show these requirements and the API enforces them before consuming an OTP. Existing password hashes and login behavior are unchanged; this policy does not force existing users to reset their passwords. Run `php console/test_email_otp.php` to check OTP security, password rules and UTF-8 boundaries.

Set these variables in the PHP API environment (local `.env` or Render Environment):

```dotenv
BREVO_API_KEY=your-brevo-api-key
BREVO_SENDER_EMAIL=minsufinesmonitoring@gmail.com
BREVO_SENDER_NAME=Fines Monitoring System
```

Use a Brevo **API key**, and configure a verified sender in Brevo. No secret belongs in the React environment. PHP cURL and outbound HTTPS to `api.brevo.com:443` are required. The implementation uses [Brevo's transactional email API](https://developers.brevo.com/reference/send-transac-email).

After configuring delivery, run from the API project:

```powershell
php console/migrate.php --run --only=email_otp
```

Deploy the API and frontend together. This migration adds `users.email_verified_at` and `email_otps`. Existing accounts start unverified and must confirm their email on their next login; existing sessions will require login again. Configure working email delivery **before** activating this migration and code. No existing account is automatically trusted.

Student activation verifies the entered email before creating the user. Admin-created organization accounts remain unverified until their first sign-in, when a code is sent to their assigned email. Account creation does not grant an authenticated session. Provisioned admin accounts use the same first-sign-in verification. Email changes require the current password and a code delivered to the new email. Admin organization editing cannot bypass this: owners change email in Account Settings. Password changes require the current password and a code to the current email. Forgot password is available on the login page for every active role and signs out existing sessions after success.

Codes contain six digits (including possible leading zeros), expire after ten minutes, allow five attempts and can be resent after sixty seconds. Resending invalidates the previous code. Only hashes are stored. Codes are scoped to their purpose, email and user, and consumed once. IP rate limits apply to OTP endpoints; password recovery also limits requests per email. Delivery errors do not expose credentials or email contents in logs. A code consumed by an account operation that subsequently fails validation must be requested again.

Public JSON POST endpoints:

- `/api/auth/otp/request`: `purpose` (`signup`, `email_change`, `password_change`, `password_reset`) and `email`. Signup also requires the student's `qr_token` and `activation_code`. Account changes require a bearer token and `current_password`.
- `/api/auth/otp/verify`: `user_id`, `challenge_id`, `otp` from a login response with `verification_required`.
- `/api/auth/password/reset`: `email`, `challenge_id`, `otp`, `password`, `password_confirmation`.
- Existing activation and account update/password endpoints additionally accept `challenge_id` and `otp`.

Run `php console/test_email_otp.php` for isolated SQLite security tests. No real emails are sent by these tests. For a delivery smoke test, activate a test student or sign in with an unverified test account, verify that the email arrives, and submit its code. Live delivery requires your configured Brevo account and has not been tested by the offline suite.

Expired challenges can be removed periodically with `DELETE FROM email_otps WHERE expires_at < UNIX_TIMESTAMP() - 86400`.
