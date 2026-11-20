# Weekly Loan Management System

A PHP and MySQL application for managing weekly loans, customer records,
repayments, arrears, user access, and operational reports.

## Features

- Customer records, documents, and officer assignments
- Loan products, applications, approvals, schedules, and disbursements
- Payment collection, allocation, reversals, and loan balance tracking
- Overdue-loan follow-up and daily reconciliation
- User roles (administrator overview, officer workspace), audit history, settings, and notifications
- Reports, customer statements, and printable records
- Administrator workbook import with drag-and-drop upload, readable progress, row-by-row review and duplicate-safe re-imports
- Session protection, CSRF checks, login throttling, and hashed passwords

## Roles and navigation

* **Administrators** sign in to the dashboard (portfolio, arrears, collections).
* **Loan officers** sign in directly on **Register customer**, so recording a
  new customer is the first action on screen. The dashboard is administrator-only.
* The sidebar keeps the working order: *Register customer → View customers →
  New loan → View loans*, followed by payments, overdue and reports.
* Language (English / Kiswahili) is switched from **My profile**, which also
  shows the administrator's last login.

## Import the company workbook

Once the schema and first administrator exist, sign in as an administrator and
open **Administration → Import workbook** (`import.php`). The upload is
deliberately staged:

1. **Upload** — drag the `.xlsx`/`.xls` file onto the page. A progress bar runs
   from 1% to 100%, then the server starts reading.
2. **Analyze** — the `CUSTOMERS`, `LOANS` and `PAYMENTS` sheets (data from row 4)
   are copied into `import_rows`. Repeat borrowers are merged by name and every
   workbook `CUST-/LN-/PMT-` reference is kept.
3. **Review** — unclear rows (missing phone, unknown customer or loan, missing
   dates, bad amounts) are listed with editable fields. Fix them, accept warnings
   or skip the row. Nothing is written to the live loan book yet.
4. **Import** — one transaction creates customers, loans, weekly schedules,
   disbursements and payments, rebuilds allocations and fills the dashboard.

The original workbook stays under `storage/imports/` (never web-accessible) and
can be downloaded again from the import history. Re-uploading the same workbook
is safe: rows whose legacy reference already exists are reused instead of
duplicated. The importer is administrator-only.

The CLI equivalent (`tools/import_legacy.php`) still exists for server
maintenance, but it wipes and re-imports; prefer the web importer.

## Requirements

- PHP 8.0+ with PDO MySQL and mbstring
- MySQL or MariaDB
- Composer dependencies from `composer.json`

## Setup

1. Copy the project into the web server document root.
2. Create the `loan_ims` database in MySQL, or set `DB_NAME` to the database
   you want to use.
3. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and optionally
   `DB_SOCKET` in the environment. The local XAMPP defaults are configured in
   `config/Database.php`.
4. Install dependencies with `composer install`.
5. Apply the schema with `/opt/lampp/bin/php tools/install.php`. The canonical
   schema is `database/schema.sql`; it creates missing tables and refreshes the
   reporting views without dropping existing tables or records.
6. Create the first administrator in the `users` table using a
   `password_hash()` value. Do not store plaintext passwords in project files.
7. Open the application's login page.

For detailed XAMPP instructions and the first-admin SQL template, see
[INSTALLATION.md](./INSTALLATION.md).

## Database source

`database/schema.sql` is the canonical loan-system schema. The root
`database.sql` file is kept as a matching import copy for phpMyAdmin workflows.
Both target the database selected by the connection configuration; neither
should be used to replace a populated database.

Running `tools/install.php` on an older database adds any missing
identity-capture columns and indexes in place, so existing customer, loan and
payment records are preserved.

## Identity capture and documents

Customer portraits and identity documents are captured with the device camera,
a paired smartphone (QR), or a normal file upload. Images, thumbnails and
replaced versions live under `storage/private/` and are only served through
`media.php` to signed-in officers.

* Design, endpoints and data model: [docs/identity-capture.md](./docs/identity-capture.md)
* Roles, hardening and remaining recommendations: [docs/security.md](./docs/security.md)
* On-demand PDF dossier: **Customer → Documents → Dossier**
* Maintenance: `tools/cleanup_camera_sessions.php` (`--stats`, `--integrity`,
  `--thumbnails`, `--recover`, `--orphans`, `--imports`, `--dry-run`)
* Tests: `tools/tests/run.php` (see the doc for the exact command)
