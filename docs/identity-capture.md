# Identity Capture & Customer Digital File

This module adds device‑aware photo/document capture, QR phone pairing, private
storage, document versioning, passport MRZ reading and an on‑demand customer
dossier to the weekly loan system.

Everything is native PHP 8 + MySQL. No framework is required.

---

## 1. Where files live

```
storage/private/
├── customers/<REFERENCE>/portrait/…        portraits added from the file tab
├── customers/<REFERENCE>/identity/…        passport, NIDA, other IDs
├── customers/<REFERENCE>/loan_documents/…  applications, guarantors, agreements
├── customers/<REFERENCE>/supporting_documents/…
├── customers/<YYYY>/<MM>/…                 portraits saved by the registration form
├── temporary/camera_sessions/<UUID>/…      phone uploads (short lived)
├── thumbnails/…                            generated previews
└── thumbnails/photos/…                     cached list/menu avatars
```

* Files are **never** inside the public web root.
* Every image is decoded with GD and re‑encoded as a fresh JPEG, which removes
  EXIF (including GPS), embedded scripts and the original filename.
* Storage names are random (`bin2hex(random_bytes(16))`), only the metadata row
  knows what a file belongs to.
* Files are served by `media.php` after authentication (or a signed, expiring
  URL for phone uploads).

Nothing in a view or API response exposes a filesystem path.

---

## 2. Permissions

```
storage/                         0755  (web user)
storage/private/                 0755  (web user, not readable by others)
```

Do **not** add `storage/` to any public alias. On Apache, the bundled `.htaccess`
rules are not required because the directory sits outside `htdocs`; keep it that
way if the project moves.

---

## 3. HTTPS (required for phone cameras)

Browsers only expose `getUserMedia()` on secure contexts:
`https://…` or `http://localhost`.

The capture page and QR link work over plain HTTP, but the phone camera will be
blocked. For office use with a LAN IP:

1. Put the app behind HTTPS (reverse proxy or a certificate on Apache).
2. Forward `X-Forwarded-Proto: https` so `CameraSession::captureUrl()` emits an
   `https://` QR link.
3. Keep `Permissions-Policy: camera=(self)` (already sent by `Security`).

The upload fallback and the desktop QR flow still work without a local camera.

---

## 4. Configuration

| Setting | Where | Default |
|---|---|---|
| `DB_SOCKET` / `DB_HOST` … | environment | XAMPP socket |
| `SESSION_FINGERPRINT_SECRET` | `config/config.php` | **change in production** |
| `TESSERACT_BIN` | environment | `tesseract` (PATH) |
| Session TTL | `CameraSession::TTL_SECONDS` | 900 s |
| Signed asset TTL | `CameraSession::ASSET_TTL` | 600 s |
| Upload ceiling | `DocumentStorage::MAX_BYTES` | 10 MB |
| Portrait edge / quality | `CustomerDocument::store()` | 1200 px / 88 |
| Document edge / quality | `CustomerDocument::store()` | 2200 px / 86 |
| Thumbnail edge | `DocumentStorage::THUMB_EDGE` | 360 px |

The secret protects signed phone‑asset URLs. Rotating it invalidates any URL
already handed to a phone; sessions themselves keep working.

---

## 5. Passport OCR (optional)

OCR is **not** required. Without Tesseract the MRZ page shows *OCR unavailable*
and the officer can paste the two MRZ lines — the parser validates ICAO 9303
check digits locally.

To enable automatic reading:

```bash
sudo apt install tesseract-ocr
# optional: tesseract-ocr-eng is installed with the base package
/opt/lampp/bin/php -r 'echo (new PassportOcrService())->status()["version"], PHP_EOL;'
```

MRZ results are advisory. A valid check digit proves only that the characters
were read consistently — never that the document is genuine. The UI requires
officer review and never overwrites typed customer details.

---

## 6. Maintenance (cron)

Run at least hourly during office hours:

```cron
*/30 * * * * /opt/lampp/bin/php /opt/lampp/htdocs/loan/tools/cleanup_camera_sessions.php --minutes=60 >> /opt/lampp/htdocs/loan/logs/cleanup.log 2>&1
```

Useful one‑off commands:

```bash
php tools/cleanup_camera_sessions.php --dry-run            # preview session/temp cleanup
php tools/cleanup_camera_sessions.php                      # run it
php tools/cleanup_camera_sessions.php --stats              # storage usage
php tools/cleanup_camera_sessions.php --integrity          # missing/mismatched files
php tools/cleanup_camera_sessions.php --thumbnails         # rebuild missing/stale thumbnails
php tools/cleanup_camera_sessions.php --recover            # unstick 'processing' documents
php tools/cleanup_camera_sessions.php --orphans --dry-run  # find unreferenced customer files
php tools/cleanup_camera_sessions.php --orphans            # delete them (1 day retention)
```

The default cleanup only deletes **temporary** capture files and closed
sessions. Documents attached to saved customers are never removed by it.

`--thumbnails` rebuilds thumbnails that are missing or older than their source
image. `--recover` reactivates documents stuck in `processing` when their file
is readable and marks the rest `rejected`; it also discards failed temporary
assets whose session is already closed.

`--orphans` is the only command that can delete files under
`storage/private/customers/`. It keeps every file referenced by
`customer_documents`, `document_versions` or `customers.photo_path`, and any
file newer than the retention window (default 1440 minutes), so an in‑flight
upload is never removed. Always run it with `--dry-run` first on production.

Deleting a storage key also removes its mirrored thumbnail under
`private/thumbnails/…`, so temporary sessions and replaced documents do not
leave thumbnail litter behind.

Suggested cron (adjust paths):

```cron
*/30 * * * * /opt/lampp/bin/php /opt/lampp/htdocs/loan/tools/cleanup_camera_sessions.php --minutes=60 >> /opt/lampp/htdocs/loan/logs/cleanup.log 2>&1
15 2 * * *   /opt/lampp/bin/php /opt/lampp/htdocs/loan/tools/cleanup_camera_sessions.php --orphans >> /opt/lampp/htdocs/loan/logs/cleanup.log 2>&1
30 2 * * 0   /opt/lampp/bin/php /opt/lampp/htdocs/loan/tools/cleanup_camera_sessions.php --thumbnails >> /opt/lampp/htdocs/loan/logs/cleanup.log 2>&1
```

---

## 7. Endpoints

| Method | URL | Purpose |
|---|---|---|
| POST | `camera_session.php` (`action=create`) | new QR capture session |
| GET | `camera_session.php?action=status&id=` | PC polling |
| POST | `camera_session.php` (`complete`/`cancel`/`renew`) | session lifecycle |
| GET | `capture.php?t=<token>` | phone capture page |
| POST | `capture.php?t=<token>` (JSON `{image}`) | phone upload |
| POST | `document_capture.php` | save a captured document (JSON) |
| GET | `media.php?doc=&size=thumb\|full` | authorized document preview |
| GET | `media.php?doc=&size=full&download=1` | authorized download |
| GET | `media.php?version=` | document version history |
| GET | `media.php?asset=&exp=&sig=` | signed temporary phone asset |

All POSTs from the PC require `X-CSRF-Token` (or `csrf_token`). The phone page is
exempt because the token authenticates the session itself, not an officer login.

`capture.php` cannot read customers, list documents or reach any other page: the
token authorises a single upload slot.

Each capture link accepts at most **10 images** (`CameraSession::MAX_ASSETS`) so a
stray QR code cannot be used as an upload endpoint. The 11th upload returns HTTP
429 with a translated message; the officer presses *Renew* to issue a fresh link,
which resets the counter.

---

## 8. Data model

New tables (see `database/migrations/2026_11_16_identity_capture.sql`):

* `camera_sessions` — hashed tokens, officer, draft id, purpose, expiry, state.
* `camera_session_assets` — one latest image per session, processing state.
* `document_versions` — previous files after a replacement.
* `document_ocr_results` — OCR/MRZ attempts and validation output.

Extended columns: `customers.gender`, `customer_documents.storage_key`,
`thumbnail_key`, `mime_type`, `file_size_bytes`, `width`, `height`, `checksum`,
`version_number`, `is_primary`, `issue_date`, `expiry_date`, `ocr_status`,
`deleted_at` (soft delete).

### Upgrading an existing installation

`tools/install.php` is idempotent and now upgrades an older database in place:

* adds any missing identity-capture columns and indexes,
* creates the four new tables,
* classifies legacy `customer_documents` rows (`doc_type` → `document_category`),
* never touches existing customer, loan or payment rows.

Legacy documents that only have the `path` column are still delivered by
`media.php` as long as the file exists under `storage/private/`, `storage/uploads/`
or `uploads/`. Files outside those folders are refused.

---

## 9. Dossier

`views/customers/dossier.php` builds the dossier on demand:

* choose which documents to include (all by default),
* identity summary, portrait, loans and document labels/dates,
* **Download PDF** streams a real PDF generated by the server.

The PDF is written by `classes/PdfDocument.php` (A4, Helvetica, deflated content
streams, JPEG images) and composed by `classes/CustomerDossierService.php`. No
extra PHP extension or Composer package is needed. Images are re‑encoded to JPEG
at a maximum edge of 1400 px / quality 82 so an ordinary dossier stays a few
hundred kilobytes.

Nothing is cached or merged permanently, so replacing a document can never leave
a stale compiled file behind. Page numbers, a business footer and a verification
note ("not a certificate of identity") are added automatically, and each download
is recorded as `customer_dossier.download` in the audit log.

## 10. Tests

`tools/tests/run.php` covers the unit and HTTP behaviour of this module.

```bash
# unit tests (database only)
DB_SOCKET=/opt/lampp/var/mysql/mysql.sock \
  /opt/lampp/bin/php tools/tests/run.php --unit

# full suite against a running installation
DB_SOCKET=/opt/lampp/var/mysql/mysql.sock \
  /opt/lampp/bin/php tools/tests/run.php \
    --base-url=http://localhost/loan/ \
    --user=testadmin --password='Admin@2026'
```

The HTTP tests need a real login (any administrator account). They create a
temporary customer, a passport document, two capture sessions and their files,
then delete the fixtures again. The suite exits non‑zero when a test fails, so it
can run from CI or a pre‑deploy hook.

Covered: MRZ parsing and check digits, storage path traversal, PDF output, gender
validation, Swahili dictionary completeness, document integrity, orphan scanning,
thumbnail mirroring, login/CSRF, registration with portrait, seven profile tabs,
document upload/replacement/versioning, dossier PDF, OCR review, capture session
creation, phone upload with signed preview, retake limit, invalid/expired tokens,
captured document storage and soft deletion.

---

## 11. Known limitations

* **No perspective warp.** Document capture relies on framing guides, rotation
  and cropping while preserving the document's proportions. A four-corner warp
  was deliberately skipped: it is the riskiest step for MRZ legibility and the
  guides already keep edges and text inside the frame.
* **Polling, not SSE.** The PC listens on `camera_session.php?action=status`.
  Polling stops on completion/cancel/expiry and never downloads the same asset
  twice; the status endpoint is ready to be wrapped in an event stream later.
* **OCR needs Tesseract.** Without it the MRZ page reports *OCR unavailable* and
  the officer pastes the two MRZ lines. Check digits validate consistency only.
* **No server-side registration drafts.** "Abandoned registration" cleanup
  therefore covers capture sessions and temporary assets, not a drafts table;
  unsaved form data lives only in the officer's browser.

---

## 12. Architecture notes / Laravel migration

Business rules live in services/classes, not in views:

* `CameraSession` — session lifecycle, hashing, signed URLs.
* `DocumentStorage` — validation, re‑encoding, thumbnails, paths.
* `CustomerDocument` — metadata, categories, versions, soft delete.
* `PassportOcrService` + `MrzParser` — OCR bridge and ICAO parsing.
* `DocumentCleanupService` — retention, orphans, integrity.

Controllers (`capture.php`, `camera_session.php`, `document_capture.php`,
`media.php`) only handle HTTP. Persistence uses PDO prepared statements.

To migrate to Laravel later, keep these services and replace only routing,
authentication, storage adapters and the CLI runner.
