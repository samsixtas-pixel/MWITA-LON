# Roles and security notes

## Role separation

| Area | Administrator | Loan officer |
|---|---|---|
| Sign-in landing page | `dashboard.php` | `views/customers/add.php` |
| Dashboard | yes | no (redirected to registration) |
| Customers: register / view | yes | yes |
| Loans: new / view | yes | yes |
| Payments, overdue, reports, statements | yes | yes |
| Users, settings, audit log | yes | no |

`User::homeUrl()` is the single place that decides where a signed-in user
starts, and `User::requireAdmin()` sends non-admins to that same page instead of
the dashboard, so there is no redirect loop.

The sidebar keeps the working order for officers:
*Register customer → View customers → New loan → View loans*.

The company-wide workbook export (`export.php`) was removed: any signed-in
officer could download the entire loan book as Excel. Single-loan statements and
printable reports remain available.

## Hardening applied

* **CSRF** — every POST is checked centrally by `Security::boot()`, with only
  `login.php`, `logout.php` and the token-authenticated phone `capture.php`
  exempt. Admin settings forms that were missing a token (2FA, cloud sync,
  period locks, restore) now include `Security::csrfField()`.
* **Destructive GETs** — user deletion is POST-only with a CSRF token, and the
  last active administrator can never be removed (same rule as deactivation).
* **Private files** — `storage/` and `backups/` deny web access; documents and
  photos are streamed by `media.php` after login and (for temporary captures)
  an HMAC signature.
* **Web-server surface** — `logs/`, `database/`, `docs/` and `tools/` now deny
  direct web access, directory listing is off, and the developer scratch pages
  `debug_purchase.php` / `practice.php` are blocked (the diagnostic page also
  requires an administrator login).
* **Sessions** — session ID regeneration on login and password change,
  user-agent fingerprint, idle timeout, HttpOnly + SameSite cookies, and
  `Secure` cookies whenever the request is HTTPS.
* **Login throttling** — per username/IP attempts with a timed lockout, plus
  audit entries for failed logins.
* **Uploads** — content/MIME checks, random storage names, re-encoding through
  GD, size/dimension limits, retake caps and signed short-lived asset URLs.
* **Headers** — CSP, `frame-ancestors 'none'`, `X-Frame-Options: DENY`,
  `nosniff`, strict referrer policy and a camera-only permissions policy.
* **Workbook import** — `import.php` is administrator-only
  (`User::requireAdmin()`). Uploads are limited to 20 MB with an `.xlsx`/`.xls`
  extension check, stored under `storage/imports/` (never web-accessible) with a
  random UUID name, and parsed with `setReadDataOnly(true)` so formulas and
  macros are never executed. Every parsed row is staged in `import_rows` and
  rendered through `e()` during review. Decisions and the final import are
  CSRF-protected POSTs; the commit runs in one transaction with a row lock so
  the same review cannot be imported twice. Re-uploads reuse existing
  `CUST-/LN-/PMT-` legacy references instead of duplicating records.

## Remaining recommendations

1. **Serve production over HTTPS.** Phone camera capture (`getUserMedia`) and
   secure session cookies require it.
2. **Size the upload limits for your workbook.** The importer refuses files over
   20 MB and the example `.htaccess` raises PHP's `upload_max_filesize` to 20 MB.
   If the real workbook is larger, raise both limits or split the file. Uploaded
   workbooks are kept as a private backup; run
   `tools/cleanup_camera_sessions.php --imports --days=7` to remove old failed or
   cancelled batches (committed batches are retained deliberately).
3. **Legacy template modules** (`views/boxes`, `stock`, `sales`, `products`,
   `purchases`, `suppliers`, `expenses`, `debts`, `cash`, `pricing`) are not in
   the navigation and several of their forms do not carry CSRF tokens, so the
   global guard rejects their POSTs. Remove them or retrofit tokens before
   relying on them.
4. **Delete developer scratch files** (`debug_purchase.php`, `practice.php`,
   `my_shift.php`) once you no longer need them; `my_shift.php` also references a
   `sales` table that does not exist in the loan schema.
5. **Review the SQL restore page** (`views/settings/restore.php`). It is
   administrator-only and CSRF-protected, but it executes uploaded SQL — use
   only dumps you created yourself.
6. **Enable 2FA for administrators** (`views/settings/setup_2fa.php`) and keep
   encrypted off-site backups of `storage/private/` and the database.
7. **Rotate** `SESSION_FINGERPRINT_SECRET` and database credentials when staff
   leave, and disable accounts instead of sharing logins.
