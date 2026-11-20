<?php
/**
 * WorkbookImportService.php
 * -------------------------------------------------------
 * Administrator-only import of the weekly-loan workbook.
 *
 * The flow is deliberately staged so nothing reaches the live
 * loan book until a human has looked at it:
 *
 *   upload   → the original .xlsx is stored privately (and kept as
 *              a re-downloadable backup of what was imported)
 *   analyze  → every CUSTOMERS / LOANS / PAYMENTS row is copied into
 *              import_rows with a status and a list of issues
 *   review   → an administrator fixes or skips unclear rows
 *   commit   → one transaction creates customers, loans, schedules and
 *              payments, then fills the dashboard
 *
 * Repeated imports are additive: rows whose CUST-/LN-/PMT- legacy
 * reference already exists are treated as duplicates instead of being
 * created twice, so the same workbook can be re-uploaded safely.
 * -------------------------------------------------------
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class WorkbookImportService
{
    public const MAX_BYTES       = 20 * 1024 * 1024;   // 20 MB
    public const MAX_WEEKS       = 104;                // two years
    public const ALLOWED_EXT     = ['xlsx', 'xls'];

    private PDO $db;
    private int $userId;
    private string $directory;

    /** @var array<string,int> reference counters used during one commit */
    private array $referenceCache = [];

    public function __construct(?int $userId = null)
    {
        $this->db        = Database::getConnection();
        $this->userId    = $userId ?? currentUserId();
        $this->directory = APP_ROOT . '/storage/imports';
    }

    /* ============================================================
     * Storage and batch lookup
     * ============================================================ */

    public function ensureStorage(): string
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }

        $guard = $this->directory . '/index.html';
        if (!is_file($guard)) {
            @file_put_contents($guard, '');
        }

        return $this->directory;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentBatches(int $limit = 8): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, u.full_name AS user_name
               FROM import_batches b
               LEFT JOIN users u ON u.id = b.created_by
              ORDER BY b.id DESC
              LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findBatch(int $batchId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, u.full_name AS user_name
               FROM import_batches b
               LEFT JOIN users u ON u.id = b.created_by
              WHERE b.id = :id
              LIMIT 1"
        );
        $stmt->execute(['id' => $batchId]);
        $batch = $stmt->fetch();

        return $batch ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function batchRows(int $batchId, ?string $status = null): array
    {
        $sql    = "SELECT * FROM import_rows WHERE batch_id = :b";
        $params = ['b' => $batchId];

        if ($status !== null && $status !== '') {
            $sql .= " AND status = :s";
            $params['s'] = $status;
        }

        $sql .= " ORDER BY FIELD(sheet, 'CUSTOMERS', 'LOANS', 'PAYMENTS'), `row_number`, id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = $this->decodeRow($row);
        }

        return $rows;
    }

    public function counts(int $batchId): array
    {
        $stmt = $this->db->prepare(
            "SELECT status, COUNT(*) AS total
               FROM import_rows
              WHERE batch_id = :b
              GROUP BY status"
        );
        $stmt->execute(['b' => $batchId]);

        $counts = [
            'ready'     => 0,
            'unclear'   => 0,
            'skipped'   => 0,
            'duplicate' => 0,
            'imported'  => 0,
            'error'     => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /* ============================================================
     * 1. Upload
     * ============================================================ */

    public function createFromUpload(array $file): array
    {
        $name = (string) ($file['name'] ?? '');
        $tmp  = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException(t('The workbook is larger than this server accepts. Increase upload_max_filesize or split the workbook.'));
        }

        if ($error === UPLOAD_ERR_PARTIAL) {
            throw new RuntimeException(t('The upload was interrupted. Please try again.'));
        }

        if ($error !== UPLOAD_ERR_OK || $tmp === '') {
            throw new RuntimeException(t('Choose an Excel workbook (.xlsx or .xls) to upload.'));
        }

        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            throw new RuntimeException(t('The upload could not be read. Please try again.'));
        }

        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException(t('The workbook must be smaller than :mb MB.', [
                'mb' => (int) (self::MAX_BYTES / 1048576),
            ]));
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXT, true)) {
            throw new RuntimeException(t('Only .xlsx and .xls workbook files are accepted.'));
        }

        $this->ensureStorage();

        $uuid       = self::uuid();
        $storedName = $uuid . '.' . $extension;
        $target     = $this->directory . '/' . $storedName;

        if (!@move_uploaded_file($tmp, $target) && !@rename($tmp, $target)) {
            // PHP's built-in server and some CLI tests hand us a normal file.
            if (!@copy($tmp, $target)) {
                throw new RuntimeException(t('The workbook could not be stored on the server.'));
            }
            @unlink($tmp);
        }

        @chmod($target, 0640);

        $checksum = hash_file('sha256', $target) ?: null;

        $stmt = $this->db->prepare(
            "INSERT INTO import_batches
                (batch_uuid, original_filename, stored_name, file_size_bytes, file_checksum, status, created_by)
             VALUES (:uuid, :name, :stored, :size, :checksum, 'uploaded', :user)"
        );
        $stmt->execute([
            'uuid'     => $uuid,
            'name'     => mb_substr($name, 0, 255),
            'stored'   => $storedName,
            'size'     => $size,
            'checksum' => $checksum,
            'user'     => $this->userId,
        ]);

        $batchId = (int) $this->db->lastInsertId();

        AuditLog::record('import.upload', 'import_batches', $batchId, sprintf(
            'Uploaded workbook %s (%s KB)',
            $name,
            number_format($size / 1024, 1)
        ));

        return $this->findBatch($batchId) ?? [];
    }

    public function filePath(array $batch): ?string
    {
        $stored = basename((string) ($batch['stored_name'] ?? ''));

        if ($stored === '') {
            return null;
        }

        $path = $this->directory . '/' . $stored;

        return is_file($path) ? $path : null;
    }

    /* ============================================================
     * 2. Analyze
     * ============================================================ */

    public function analyze(int $batchId): array
    {
        $batch = $this->requireBatch($batchId);
        $path  = $this->filePath($batch);

        if ($path === null) {
            throw new RuntimeException(t('The uploaded workbook is no longer on the server. Please upload it again.'));
        }

        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->db->prepare(
            "UPDATE import_batches SET status = 'analyzing', error_message = NULL WHERE id = :id"
        )->execute(['id' => $batchId]);

        try {
            $sheets = $this->readWorkbook($path);

            $rows = [];
            foreach ($sheets as $sheet => $list) {
                foreach ($list as $row) {
                    $rows[] = $row + ['sheet' => $sheet];
                }
            }

            if (!$rows) {
                throw new RuntimeException(t('No customer, loan or payment rows were found. Check that the workbook uses the CUSTOMERS, LOANS and PAYMENTS sheets with data starting on row 4.'));
            }

            $rows = $this->evaluateRows($rows, []);

            $this->db->beginTransaction();
            $this->db->prepare("DELETE FROM import_rows WHERE batch_id = :b")
                     ->execute(['b' => $batchId]);

            $insert = $this->db->prepare(
                "INSERT INTO import_rows
                    (batch_id, sheet, `row_number`, legacy_reference, payload_json, issues_json, resolution_json, status)
                 VALUES (:batch, :sheet, :row, :legacy, :payload, :issues, :resolution, :status)"
            );

            foreach ($rows as $row) {
                $insert->execute([
                    'batch'      => $batchId,
                    'sheet'      => $row['sheet'],
                    'row'        => $row['row_number'],
                    'legacy'     => $row['legacy'],
                    'payload'    => json_encode($row['payload'], JSON_UNESCAPED_UNICODE),
                    'issues'     => json_encode($row['issues'], JSON_UNESCAPED_UNICODE),
                    'resolution' => json_encode($row['resolution'], JSON_UNESCAPED_UNICODE),
                    'status'     => $row['status'],
                ]);
            }

            $counts  = $this->counts($batchId);
            $summary = $this->summarize($rows);

            $this->db->prepare(
                "UPDATE import_batches
                    SET status = 'review', analyzed_at = NOW(),
                        summary_json = :summary, counts_json = :counts, error_message = NULL
                  WHERE id = :id"
            )->execute([
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                'counts'  => json_encode($counts, JSON_UNESCAPED_UNICODE),
                'id'      => $batchId,
            ]);

            $this->db->commit();

            AuditLog::record('import.analyze', 'import_batches', $batchId, sprintf(
                'Analyzed workbook: %d rows, %d unclear',
                $counts['total'],
                $counts['unclear']
            ));

            return ['counts' => $counts, 'summary' => $summary];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->db->prepare(
                "UPDATE import_batches SET status = 'failed', error_message = :msg WHERE id = :id"
            )->execute(['msg' => mb_substr($e->getMessage(), 0, 1000), 'id' => $batchId]);

            throw $e;
        }
    }

    /** @return array{CUSTOMERS:array,LOANS:array,PAYMENTS:array} */
    private function readWorkbook(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $available = array_map('strtoupper', $reader->listWorksheetNames($path));
        $wanted    = ['CUSTOMERS', 'LOANS', 'PAYMENTS'];
        $missing   = array_values(array_diff($wanted, $available));

        if ($missing) {
            throw new RuntimeException(t('The workbook is missing these sheets: :sheets.', [
                'sheets' => implode(', ', $missing),
            ]));
        }

        $reader->setLoadSheetsOnly($wanted);
        $book = $reader->load($path);

        $customersSheet = $book->getSheetByName('CUSTOMERS');
        $loansSheet     = $book->getSheetByName('LOANS');
        $paymentsSheet  = $book->getSheetByName('PAYMENTS');

        if (!$customersSheet || !$loansSheet || !$paymentsSheet) {
            throw new RuntimeException(t('The workbook could not be opened. Save it again as .xlsx and retry.'));
        }

        $customers = [];
        $loans     = [];
        $payments  = [];

        for ($row = 4; $row <= $customersSheet->getHighestDataRow(); $row++) {
            $name = self::cleanName($customersSheet->getCell('B' . $row)->getValue());

            if ($name === '') {
                continue;
            }

            $customers[] = [
                'row_number' => $row,
                'legacy'     => 'CUST-' . str_pad((string) ($row - 3), 4, '0', STR_PAD_LEFT),
                'payload'    => [
                    'name'            => $name,
                    'phone'           => self::cellText($customersSheet->getCell('C' . $row)->getValue()),
                    'emergency'       => self::cellText($customersSheet->getCell('D' . $row)->getValue()),
                    'emergency_phone' => self::cellText($customersSheet->getCell('E' . $row)->getValue()),
                    'registered_at'   => self::cellDate($customersSheet->getCell('F' . $row)->getValue()),
                    'notes'           => self::cellText($customersSheet->getCell('G' . $row)->getValue()),
                ],
                'issues'     => [],
                'resolution' => [],
                'status'     => 'ready',
            ];
        }

        for ($row = 4; $row <= $loansSheet->getHighestDataRow(); $row++) {
            $name = self::cleanName($loansSheet->getCell('C' . $row)->getValue());

            if ($name === '') {
                continue;
            }

            $loans[] = [
                'row_number' => $row,
                'legacy'     => 'LN-' . str_pad((string) ($row - 3), 4, '0', STR_PAD_LEFT),
                'payload'    => [
                    'name'      => $name,
                    'loan_date' => self::cellDate($loansSheet->getCell('D' . $row)->getValue()),
                    'principal' => self::round2($loansSheet->getCell('E' . $row)->getValue()),
                    'weekly'    => self::round2($loansSheet->getCell('F' . $row)->getValue()),
                    'weeks'     => (int) $loansSheet->getCell('G' . $row)->getValue(),
                ],
                'issues'     => [],
                'resolution' => [],
                'status'     => 'ready',
            ];
        }

        for ($row = 4; $row <= $paymentsSheet->getHighestDataRow(); $row++) {
            $loanRef = self::cellText($paymentsSheet->getCell('C' . $row)->getValue());

            if ($loanRef === '') {
                continue;
            }

            $payments[] = [
                'row_number' => $row,
                'legacy'     => 'PMT-' . str_pad((string) ($row - 3), 4, '0', STR_PAD_LEFT),
                'payload'    => [
                    'loan'    => $loanRef,
                    'paid_at' => self::cellDate($paymentsSheet->getCell('F' . $row)->getValue()),
                    'amount'  => self::round2($paymentsSheet->getCell('H' . $row)->getValue()),
                ],
                'issues'     => [],
                'resolution' => [],
                'status'     => 'ready',
            ];
        }

        return ['CUSTOMERS' => $customers, 'LOANS' => $loans, 'PAYMENTS' => $payments];
    }

    /**
     * Decide a status and issue list for every staged row.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $previous rows from the database (to keep user decisions)
     * @return array<int,array<string,mixed>>
     */
    private function evaluateRows(array $rows, array $previous): array
    {
        $existingCustomers = $this->existingCustomerRefs();
        $existingLoans     = $this->existingLoanRefs();
        $existingPayments  = $this->existingPaymentRefs();

        // Customer names available to loans. Skipped customer rows do not count.
        $knownCustomerKeys = [];
        $seenCustomerKeys  = [];

        foreach ($rows as $row) {
            if ($row['sheet'] !== 'CUSTOMERS' || ($row['status'] ?? '') === 'skipped') {
                continue;
            }

            $key = self::mergeKey((string) ($row['payload']['name'] ?? ''));
            if ($key !== '') {
                $knownCustomerKeys[$key] = true;
            }
        }

        $knownLoanRefs = array_fill_keys(array_keys($existingLoans), true);
        $seenLoanRefs  = [];

        foreach ($rows as $row) {
            if ($row['sheet'] === 'LOANS' && ($row['status'] ?? '') !== 'skipped') {
                $knownLoanRefs[(string) $row['legacy']] = true;
            }
        }

        $evaluated = [];

        foreach ($rows as $index => $row) {
            $sheet      = (string) $row['sheet'];
            $payload    = $row['payload'];
            $legacy     = (string) $row['legacy'];
            $resolution = $row['resolution'] ?? [];
            $previousStatus = (string) ($row['status'] ?? 'ready');

            $issues = [];
            $status = 'ready';

            if ($sheet === 'CUSTOMERS') {
                $name  = self::cleanName((string) ($payload['name'] ?? ''));
                $key   = self::mergeKey($name);
                $phone = self::cellText($payload['phone'] ?? '');
                $merged = $key !== '' && isset($seenCustomerKeys[$key]);

                // A repeat row only adds extra keys to an existing person; its blank
                // phone or date is not a problem once the first row has them.
                if (!$merged) {
                    if ($name === '') {
                        $issues[] = self::issue('error', 'missing_name', t('The customer name is empty.'));
                    } elseif (count(preg_split('/\s+/', $name) ?: []) < 2) {
                        $issues[] = self::issue('warning', 'single_name', t('Only one name was found. Add the full name before importing.'));
                    }

                    if ($phone === '') {
                        $issues[] = self::issue('warning', 'missing_phone', t('No phone number was found. Add one or accept the row as incomplete.'));
                    } elseif (!isValidTzPhone($phone)) {
                        $issues[] = self::issue('warning', 'invalid_phone', t('“:phone” is not a valid Tanzanian phone number.', ['phone' => $phone]));
                    }

                    if (empty($payload['registered_at'])) {
                        $issues[] = self::issue('info', 'default_date', t('No registration date was found; today will be used.'));
                    }
                }

                if (isset($existingCustomers[$legacy])) {
                    $status   = 'duplicate';
                    $issues[] = self::issue('info', 'already_imported', t('This customer already exists in the system and will be reused, not duplicated.'));
                } elseif ($merged) {
                    $issues[] = self::issue('info', 'merged', t('Same person as an earlier row; the rows will be merged into one customer.'));
                }

                if ($key !== '') {
                    $seenCustomerKeys[$key] = true;
                }
            }

            if ($sheet === 'LOANS') {
                $key = self::mergeKey((string) ($payload['name'] ?? ''));

                $principal = (float) ($payload['principal'] ?? 0);
                $weekly    = (float) ($payload['weekly'] ?? 0);
                $weeks     = (int) ($payload['weeks'] ?? 0);

                if (isset($existingLoans[$legacy])) {
                    $status   = 'duplicate';
                    $issues[] = self::issue('info', 'already_imported', t('This loan already exists in the system and will be reused, not duplicated.'));
                } else {
                    if ($key === '' || !isset($knownCustomerKeys[$key])) {
                        $issues[] = self::issue('error', 'unknown_customer', t('No customer row matches “:name”. Correct the name or skip this loan.', [
                            'name' => (string) ($payload['name'] ?? ''),
                        ]));
                    }

                    if ($principal <= 0) {
                        $issues[] = self::issue('error', 'invalid_principal', t('The principal must be greater than zero.'));
                    }

                    if ($weekly <= 0) {
                        $issues[] = self::issue('error', 'invalid_weekly', t('The weekly repayment must be greater than zero.'));
                    }

                    if ($weeks < 1) {
                        $issues[] = self::issue('error', 'invalid_weeks', t('The number of weeks must be at least 1.'));
                    } elseif ($weeks > self::MAX_WEEKS) {
                        $issues[] = self::issue('warning', 'long_loan', t('This loan runs for more than :n weeks. Check the number of weeks.', [
                            'n' => self::MAX_WEEKS,
                        ]));
                    }

                    if (empty($payload['loan_date'])) {
                        $issues[] = self::issue('warning', 'missing_date', t('No loan date was found; today will be used.'));
                    }

                    if (isset($seenLoanRefs[$legacy])) {
                        $issues[] = self::issue('error', 'duplicate_reference', t('Another loan row uses the same workbook reference.'));
                    }
                }

                $seenLoanRefs[$legacy] = true;
            }

            if ($sheet === 'PAYMENTS') {
                $loanRef = self::cellText($payload['loan'] ?? '');
                $amount  = (float) ($payload['amount'] ?? 0);

                if (isset($existingPayments[$legacy])) {
                    $status   = 'duplicate';
                    $issues[] = self::issue('info', 'already_imported', t('This payment already exists in the system and will be skipped.'));
                } elseif ($amount <= 0) {
                    $status   = 'skipped';
                    $issues[] = self::issue('info', 'empty_amount', t('Zero-amount placeholder row; nothing to import.'));
                } else {
                    if ($loanRef === '' || !isset($knownLoanRefs[$loanRef])) {
                        $issues[] = self::issue('error', 'unknown_loan', t('No loan in this workbook has the reference “:ref”. Correct it or skip this payment.', [
                            'ref' => $loanRef,
                        ]));
                    }

                    if (empty($payload['paid_at'])) {
                        $issues[] = self::issue('warning', 'missing_date', t('No payment date was found; today will be used.'));
                    }
                }
            }

            // User decisions win over automatic classification.
            if ($previousStatus === 'skipped' && empty($resolution['restored'])) {
                $status = 'skipped';
            } elseif ($status === 'unclear' || (!in_array($status, ['duplicate', 'skipped'], true) && self::hasBlockingIssue($issues))) {
                $status = 'unclear';
            }

            if (!in_array($status, ['duplicate', 'skipped'], true)) {
                $accepted = !empty($resolution['accepted']);

                if (self::hasBlockingIssue($issues) || self::hasWarning($issues)) {
                    $status = ($accepted && !self::hasError($issues)) ? 'ready' : 'unclear';
                } else {
                    $status = 'ready';
                }
            }

            // A duplicate is never "unclear" and never gets created, even if accepted.
            if (in_array($status, ['duplicate'], true)) {
                $resolution['accepted'] = true;
            }

            $evaluated[] = [
                'id'         => $row['id'] ?? null,
                'sheet'      => $sheet,
                'row_number' => (int) $row['row_number'],
                'legacy'     => $legacy,
                'payload'    => $payload,
                'issues'     => $issues,
                'resolution' => $resolution,
                'status'     => $status,
            ];
        }

        return $evaluated;
    }

    /* ============================================================
     * 3. Review actions
     * ============================================================ */

    public function decide(int $batchId, int $rowId, string $decision, array $fields = []): array
    {
        $batch = $this->requireBatch($batchId);

        if (!in_array($batch['status'], ['review'], true)) {
            throw new RuntimeException(t('This import is no longer open for review.'));
        }

        $row = $this->requireRow($batchId, $rowId);

        if ($decision !== 'skip') {
            $row['payload'] = $this->applyFields($row['sheet'], $row['payload'], $fields);
        }

        $resolution = $row['resolution'];

        if ($decision === 'skip') {
            $row['status']       = 'skipped';
            $resolution['accepted'] = false;
            $resolution['restored'] = false;
        } elseif ($decision === 'accept') {
            $resolution['accepted'] = true;
            $resolution['restored'] = false;
            $row['status'] = 'unclear'; // re-evaluated below
        } elseif ($decision === 'restore') {
            $resolution['accepted'] = false;
            $resolution['restored'] = true;
            $row['status'] = 'unclear';
        } else {
            $resolution['accepted'] = false;
            $resolution['restored'] = false;
            $row['status'] = 'unclear';
        }

        $row['resolution'] = $resolution;

        $this->db->prepare(
            "UPDATE import_rows SET payload_json = :payload, resolution_json = :resolution, status = :status WHERE id = :id"
        )->execute([
            'payload'    => json_encode($row['payload'], JSON_UNESCAPED_UNICODE),
            'resolution' => json_encode($resolution, JSON_UNESCAPED_UNICODE),
            'status'     => $row['status'],
            'id'         => $rowId,
        ]);

        $this->reevaluateBatch($batchId);

        $fresh = $this->requireRow($batchId, $rowId);

        return $fresh;
    }

    public function acceptAll(int $batchId): int
    {
        $batch = $this->requireBatch($batchId);

        if ($batch['status'] !== 'review') {
            throw new RuntimeException(t('This import is no longer open for review.'));
        }

        $rows     = $this->batchRows($batchId);
        $accepted = 0;

        foreach ($rows as $row) {
            if ($row['status'] !== 'unclear' || self::hasError($row['issues'])) {
                continue;
            }

            $resolution = $row['resolution'];
            $resolution['accepted'] = true;
            $resolution['restored'] = false;

            $this->db->prepare(
                "UPDATE import_rows SET resolution_json = :r WHERE id = :id"
            )->execute([
                'r'  => json_encode($resolution, JSON_UNESCAPED_UNICODE),
                'id' => $row['id'],
            ]);

            $accepted++;
        }

        $this->reevaluateBatch($batchId);

        return $accepted;
    }

    public function cancel(int $batchId): void
    {
        $batch = $this->requireBatch($batchId);

        if ($batch['status'] === 'committed') {
            throw new RuntimeException(t('A finished import cannot be cancelled.'));
        }

        $path = $this->filePath($batch);

        if ($path !== null) {
            @unlink($path);
        }

        $this->db->prepare(
            "UPDATE import_batches SET status = 'cancelled', stored_name = NULL WHERE id = :id"
        )->execute(['id' => $batchId]);

        AuditLog::record('import.cancel', 'import_batches', $batchId, 'Cancelled workbook import');
    }

    /* ============================================================
     * 4. Commit
     * ============================================================ */

    public function commit(int $batchId): array
    {
        $batch = $this->requireBatch($batchId);

        if ($batch['status'] !== 'review') {
            throw new RuntimeException(t('This import cannot be committed in its current state.'));
        }

        $rows = $this->batchRows($batchId);

        foreach ($rows as $row) {
            if ($row['status'] === 'unclear') {
                throw new RuntimeException(t('There are still :n row(s) to review. Fix or skip them first.', [
                    'n' => $this->counts($batchId)['unclear'],
                ]));
            }
        }

        set_time_limit(300);

        $existingCustomers = $this->existingCustomerRefs();
        $existingLoans     = $this->existingLoanRefs();

        $created = ['customers' => 0, 'loans' => 0, 'payments' => 0, 'duplicates' => 0];

        $this->referenceCache = [];

        $this->db->beginTransaction();

        try {
            // Lock the batch so a second administrator cannot commit the same
            // review twice while this transaction is running.
            $lock = $this->db->prepare(
                "SELECT status FROM import_batches WHERE id = :id FOR UPDATE"
            );
            $lock->execute(['id' => $batchId]);
            $lockedStatus = (string) $lock->fetchColumn();

            if ($lockedStatus !== 'review') {
                throw new RuntimeException(t('This import cannot be committed in its current state.'));
            }

            $this->db->prepare(
                "UPDATE import_batches SET status = 'committing', error_message = NULL WHERE id = :id"
            )->execute(['id' => $batchId]);

            /* ---------- customers (merged by name) ---------- */
            $groups = [];

            foreach ($rows as $row) {
                if ($row['sheet'] !== 'CUSTOMERS' || $row['status'] === 'skipped') {
                    continue;
                }

                $payload = $row['payload'];
                $key     = self::mergeKey((string) ($payload['name'] ?? ''));

                if ($key === '') {
                    continue;
                }

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'name'            => self::cleanName((string) ($payload['name'] ?? '')),
                        'phone'           => '',
                        'emergency'       => '',
                        'emergency_phone' => '',
                        'registered_at'   => null,
                        'notes'           => '',
                        'refs'            => [],
                    ];
                }

                $group = &$groups[$key];

                $phone = self::cellText($payload['phone'] ?? '');
                if (($group['phone'] === '' || !isValidTzPhone($group['phone'])) && $phone !== '') {
                    $group['phone'] = $phone;
                }

                if ($group['emergency_phone'] === '' && self::cellText($payload['emergency_phone'] ?? '') !== '') {
                    $group['emergency_phone'] = self::cellText($payload['emergency_phone']);
                    $group['emergency']       = self::cellText($payload['emergency'] ?? '');
                }

                $registered = (string) ($payload['registered_at'] ?? '');
                if ($registered !== '' && ($group['registered_at'] === null || $registered < $group['registered_at'])) {
                    $group['registered_at'] = $registered;
                }

                if ($group['notes'] === '' && self::cellText($payload['notes'] ?? '') !== '') {
                    $group['notes'] = self::cellText($payload['notes']);
                }

                $group['refs'][] = (string) $row['legacy'];
                unset($group);
            }

            $insertCustomer = $this->db->prepare(
                "INSERT INTO customers
                    (reference, first_name, middle_name, last_name, full_name, phone, emergency_contact,
                     emergency_phone, notes, data_status, status, registered_by, assigned_officer_id, registered_at)
                 VALUES
                    (:reference, :first_name, :middle_name, :last_name, :full_name, :phone, :emergency,
                     :emergency_phone, :notes, 'incomplete', 'active', :registered_by, :officer_id, :registered_at)"
            );

            $insertLegacy = $this->db->prepare(
                "INSERT IGNORE INTO customer_legacy_refs (customer_id, legacy_reference, legacy_name, source)
                 VALUES (:customer_id, :legacy_reference, :legacy_name, 'workbook')"
            );

            $insertAssignment = $this->db->prepare(
                "INSERT INTO customer_assignments (customer_id, officer_id, assigned_by)
                 VALUES (:c, :o, :b)"
            );

            $customerIds = [];

            foreach ($groups as $key => $group) {
                $customerId = null;

                foreach ($group['refs'] as $ref) {
                    if (isset($existingCustomers[$ref])) {
                        $customerId = (int) $existingCustomers[$ref];
                        break;
                    }
                }

                $phone   = $group['phone'] !== '' ? normalisePhone($group['phone']) : null;
                $notes   = $group['notes'] !== '' ? $group['notes'] : null;
                $regDate = $group['registered_at'] ?: date('Y-m-d');

                if ($customerId !== null) {
                    // Add missing legacy keys and fill blank fields without overwriting anything.
                    $this->db->prepare(
                        "UPDATE customers
                            SET phone = COALESCE(NULLIF(phone, ''), :phone),
                                emergency_contact = COALESCE(NULLIF(emergency_contact, ''), :emergency),
                                emergency_phone = COALESCE(NULLIF(emergency_phone, ''), :emergency_phone),
                                notes = COALESCE(NULLIF(notes, ''), :notes)
                          WHERE id = :id"
                    )->execute([
                        'phone'           => $phone,
                        'emergency'       => $group['emergency'] ?: null,
                        'emergency_phone' => $group['emergency_phone'] !== '' ? normalisePhone($group['emergency_phone']) : null,
                        'notes'           => $notes,
                        'id'              => $customerId,
                    ]);

                    $created['duplicates']++;
                } else {
                    [$first, $middle, $last] = self::splitName($group['name']);

                    $insertCustomer->execute([
                        'reference'       => $this->nextReference('customers', 'CUST'),
                        'first_name'      => $first,
                        'middle_name'     => $middle,
                        'last_name'       => $last,
                        'full_name'       => $group['name'],
                        'phone'           => $phone,
                        'emergency'       => $group['emergency'] ?: null,
                        'emergency_phone' => $group['emergency_phone'] !== '' ? normalisePhone($group['emergency_phone']) : null,
                        'notes'           => trim('Imported from workbook. ' . (string) $notes),
                        'registered_by'   => $this->userId,
                        'officer_id'      => $this->userId,
                        'registered_at'   => $regDate . ' 00:00:00',
                    ]);

                    $customerId = (int) $this->db->lastInsertId();
                    $created['customers']++;

                    $insertAssignment->execute([
                        'c' => $customerId,
                        'o' => $this->userId,
                        'b' => $this->userId,
                    ]);
                }

                foreach ($group['refs'] as $ref) {
                    $insertLegacy->execute([
                        'customer_id'      => $customerId,
                        'legacy_reference' => $ref,
                        'legacy_name'      => $group['name'],
                    ]);
                }

                $customerIds[$key] = $customerId;
            }

            /* ---------- loans ---------- */
            $insertLoan = $this->db->prepare(
                "INSERT INTO loans
                    (reference, legacy_reference, customer_id, principal, weekly_amount, number_of_weeks,
                     total_payable, loan_date, first_payment_date, final_payment_date, disbursed_at, created_by)
                 VALUES
                    (:reference, :legacy, :customer_id, :principal, :weekly, :weeks,
                     :total, :loan_date, :first_payment, :final_payment, :disbursed_at, :created_by)"
            );

            $insertDisbursement = $this->db->prepare(
                "INSERT INTO disbursements (loan_id, amount, method, disbursed_by, disbursed_at)
                 VALUES (:loan_id, :amount, 'cash', :user_id, :disbursed_at)"
            );

            $schedule = new RepaymentSchedule();
            $loanIds  = [];   // legacy ref => loan id
            $touched  = [];   // loan ids whose schedule needs rebuilding

            foreach ($rows as $row) {
                if ($row['sheet'] !== 'LOANS' || $row['status'] === 'skipped') {
                    continue;
                }

                $legacy = (string) $row['legacy'];

                if (isset($existingLoans[$legacy])) {
                    $loanIds[$legacy] = (int) $existingLoans[$legacy]['id'];
                    continue;
                }

                $payload = $row['payload'];
                $key     = self::mergeKey((string) ($payload['name'] ?? ''));
                $customerId = $customerIds[$key] ?? null;

                if ($customerId === null) {
                    throw new RuntimeException(t('Loan :ref has no matching customer after merging.', ['ref' => $legacy]));
                }

                $loanDate = (string) ($payload['loan_date'] ?? '') ?: date('Y-m-d');
                $weeks    = max(1, (int) ($payload['weeks'] ?? 1));
                $weekly   = (float) ($payload['weekly'] ?? 0);
                $principal = (float) ($payload['principal'] ?? 0);
                $first    = addDays($loanDate, 7);
                $final    = addDays($first, ($weeks - 1) * 7);

                $reference = $legacy;
                if ($this->referenceExists('loans', $reference)) {
                    $reference = $this->nextReference('loans', 'LN');
                }

                $insertLoan->execute([
                    'reference'     => $reference,
                    'legacy'        => $legacy,
                    'customer_id'   => $customerId,
                    'principal'     => $principal,
                    'weekly'        => $weekly,
                    'weeks'         => $weeks,
                    'total'         => round($weekly * $weeks, 2),
                    'loan_date'     => $loanDate,
                    'first_payment' => $first,
                    'final_payment' => $final,
                    'disbursed_at'  => $loanDate . ' 09:00:00',
                    'created_by'    => $this->userId,
                ]);

                $loanId = (int) $this->db->lastInsertId();
                $loanIds[$legacy] = $loanId;
                $created['loans']++;

                $insertDisbursement->execute([
                    'loan_id'      => $loanId,
                    'amount'       => $principal,
                    'user_id'      => $this->userId,
                    'disbursed_at' => $loanDate . ' 09:00:00',
                ]);

                $schedule->generate($loanId, $first, $weeks, $weekly);
                $touched[$loanId] = true;
            }

            /* ---------- payments ---------- */
            $insertPayment = $this->db->prepare(
                "INSERT INTO payments
                    (reference, legacy_reference, loan_id, customer_id, amount, method, status,
                     received_by, paid_at, notes)
                 VALUES
                    (:reference, :legacy, :loan_id, :customer_id, :amount, 'cash', 'confirmed',
                     :received_by, :paid_at, NULL)"
            );

            $loanOwner = $this->db->prepare("SELECT customer_id FROM loans WHERE id = :id");

            foreach ($rows as $row) {
                if ($row['sheet'] !== 'PAYMENTS' || in_array($row['status'], ['skipped', 'duplicate'], true)) {
                    continue;
                }

                $payload = $row['payload'];
                $amount  = (float) ($payload['amount'] ?? 0);

                if ($amount <= 0) {
                    continue;
                }

                $loanRef = self::cellText($payload['loan'] ?? '');

                if (!isset($loanIds[$loanRef])) {
                    throw new RuntimeException(t('Payment :ref points at an unknown loan (:loan).', [
                        'ref'  => (string) $row['legacy'],
                        'loan' => $loanRef,
                    ]));
                }

                $loanId = (int) $loanIds[$loanRef];
                $loanOwner->execute(['id' => $loanId]);
                $customerId = (int) $loanOwner->fetchColumn();

                $paidAt = (string) ($payload['paid_at'] ?? '') ?: date('Y-m-d');

                $insertPayment->execute([
                    'reference'   => $this->nextReference('payments', 'PMT'),
                    'legacy'      => (string) $row['legacy'],
                    'loan_id'     => $loanId,
                    'customer_id' => $customerId,
                    'amount'      => $amount,
                    'received_by' => $this->userId,
                    'paid_at'     => $paidAt . ' 10:00:00',
                ]);

                $touched[$loanId] = true;
                $created['payments']++;
            }

            foreach (array_keys($touched) as $loanId) {
                $schedule->rebuild((int) $loanId);
            }

            $this->db->prepare(
                "UPDATE import_rows SET status = 'imported' WHERE batch_id = :b AND status = 'ready'"
            )->execute(['b' => $batchId]);

            $counts = $this->counts($batchId);
            $result = $created + ['rows' => $counts];

            $this->db->prepare(
                "UPDATE import_batches
                    SET status = 'committed', committed_at = NOW(),
                        summary_json = :summary, counts_json = :counts, error_message = NULL
                  WHERE id = :id"
            )->execute([
                'summary' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'counts'  => json_encode($counts, JSON_UNESCAPED_UNICODE),
                'id'      => $batchId,
            ]);

            AuditLog::record('import.commit', 'import_batches', $batchId, sprintf(
                'Imported workbook: %d customers, %d loans, %d payments (%d existing rows reused)',
                $created['customers'],
                $created['loans'],
                $created['payments'],
                $created['duplicates']
            ));

            $this->db->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->db->prepare(
                "UPDATE import_batches SET status = 'review', error_message = :msg WHERE id = :id"
            )->execute([
                'msg' => mb_substr($e->getMessage(), 0, 1000),
                'id'  => $batchId,
            ]);

            throw $e;
        }
    }

    /* ============================================================
     * 5. Housekeeping
     * ============================================================ */

    /**
     * Remove old cancelled/failed imports and their private files.
     * Committed batches are kept as a re-downloadable backup unless
     * $includeCommitted is true.
     */
    public function cleanup(int $days = 7, bool $includeCommitted = false): int
    {
        $statuses = $includeCommitted
            ? ['cancelled', 'failed', 'committed']
            : ['cancelled', 'failed'];

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $cutoff       = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));

        $stmt = $this->db->prepare(
            "SELECT id, stored_name FROM import_batches
              WHERE status IN ({$placeholders}) AND created_at < ?"
        );
        $stmt->execute(array_merge($statuses, [$cutoff]));

        $removed = 0;

        foreach ($stmt->fetchAll() as $batch) {
            if (!empty($batch['stored_name'])) {
                @unlink($this->directory . '/' . basename((string) $batch['stored_name']));
            }

            $this->db->prepare("DELETE FROM import_batches WHERE id = :id")
                     ->execute(['id' => (int) $batch['id']]);
            $removed++;
        }

        return $removed;
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function requireBatch(int $batchId): array
    {
        $batch = $this->findBatch($batchId);

        if (!$batch) {
            throw new RuntimeException(t('Import batch not found.'));
        }

        return $batch;
    }

    private function requireRow(int $batchId, int $rowId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM import_rows WHERE id = :id AND batch_id = :b LIMIT 1"
        );
        $stmt->execute(['id' => $rowId, 'b' => $batchId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException(t('Import row not found.'));
        }

        return $this->decodeRow($row);
    }

    private function decodeRow(array $row): array
    {
        $row['payload']    = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
        $row['issues']     = json_decode((string) ($row['issues_json'] ?? '[]'), true) ?: [];
        $row['resolution'] = json_decode((string) ($row['resolution_json'] ?? '{}'), true) ?: [];
        $row['id']         = (int) $row['id'];
        $row['legacy']     = (string) ($row['legacy_reference'] ?? '');

        return $row;
    }

    private function applyFields(string $sheet, array $payload, array $fields): array
    {
        if ($sheet === 'CUSTOMERS') {
            if (array_key_exists('name', $fields)) {
                $payload['name'] = self::cleanName((string) $fields['name']);
            }
            foreach (['phone' => 'phone', 'emergency' => 'emergency', 'emergency_phone' => 'emergency_phone', 'notes' => 'notes'] as $key => $field) {
                if (array_key_exists($field, $fields)) {
                    $payload[$key] = self::cellText($fields[$field]);
                }
            }
            if (array_key_exists('registered_at', $fields)) {
                $payload['registered_at'] = self::dateInput((string) $fields['registered_at']);
            }
        }

        if ($sheet === 'LOANS') {
            if (array_key_exists('name', $fields)) {
                $payload['name'] = self::cleanName((string) $fields['name']);
            }
            if (array_key_exists('loan_date', $fields)) {
                $payload['loan_date'] = self::dateInput((string) $fields['loan_date']);
            }
            if (array_key_exists('principal', $fields)) {
                $payload['principal'] = self::round2($fields['principal']);
            }
            if (array_key_exists('weekly', $fields)) {
                $payload['weekly'] = self::round2($fields['weekly']);
            }
            if (array_key_exists('weeks', $fields)) {
                $payload['weeks'] = (int) $fields['weeks'];
            }
        }

        if ($sheet === 'PAYMENTS') {
            if (array_key_exists('loan', $fields)) {
                $payload['loan'] = self::cellText($fields['loan']);
            }
            if (array_key_exists('paid_at', $fields)) {
                $payload['paid_at'] = self::dateInput((string) $fields['paid_at']);
            }
            if (array_key_exists('amount', $fields)) {
                $payload['amount'] = self::round2($fields['amount']);
            }
        }

        return $payload;
    }

    /** Re-run classification for every row and persist only what changed. */
    private function reevaluateBatch(int $batchId): void
    {
        $rows = $this->batchRows($batchId);

        // Preserve a manual skip before evaluating.
        foreach ($rows as &$row) {
            if ($row['status'] === 'skipped') {
                $row['resolution']['restored'] = false;
            }
            if (($row['resolution']['restored'] ?? false) === true) {
                $row['status'] = 'ready';
                $row['resolution']['restored'] = false;
            }
        }
        unset($row);

        $evaluated = $this->evaluateRows($rows, $rows);

        $update = $this->db->prepare(
            "UPDATE import_rows
                SET payload_json = :payload, issues_json = :issues,
                    resolution_json = :resolution, status = :status
              WHERE id = :id"
        );

        foreach ($evaluated as $row) {
            if (empty($row['id'])) {
                continue;
            }

            $update->execute([
                'payload'    => json_encode($row['payload'], JSON_UNESCAPED_UNICODE),
                'issues'     => json_encode($row['issues'], JSON_UNESCAPED_UNICODE),
                'resolution' => json_encode($row['resolution'], JSON_UNESCAPED_UNICODE),
                'status'     => $row['status'],
                'id'         => (int) $row['id'],
            ]);
        }

        $counts = $this->counts($batchId);

        $this->db->prepare(
            "UPDATE import_batches SET counts_json = :counts WHERE id = :id"
        )->execute([
            'counts' => json_encode($counts, JSON_UNESCAPED_UNICODE),
            'id'     => $batchId,
        ]);
    }

    private function summarize(array $rows): array
    {
        $summary = ['CUSTOMERS' => 0, 'LOANS' => 0, 'PAYMENTS' => 0, 'groups' => 0];
        $keys    = [];

        foreach ($rows as $row) {
            $summary[$row['sheet']]++;

            if ($row['sheet'] === 'CUSTOMERS') {
                $key = self::mergeKey((string) ($row['payload']['name'] ?? ''));
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        $summary['groups'] = count($keys);

        return $summary;
    }

    /** @return array<string,int> legacy ref => customer id */
    private function existingCustomerRefs(): array
    {
        $rows = $this->db->query(
            "SELECT legacy_reference, customer_id FROM customer_legacy_refs"
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['legacy_reference']] = (int) $row['customer_id'];
        }

        return $map;
    }

    /** @return array<string,array{id:int,customer_id:int}> */
    private function existingLoanRefs(): array
    {
        $rows = $this->db->query(
            "SELECT id, legacy_reference, customer_id FROM loans
              WHERE legacy_reference IS NOT NULL AND legacy_reference <> ''"
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['legacy_reference']] = [
                'id'          => (int) $row['id'],
                'customer_id' => (int) $row['customer_id'],
            ];
        }

        return $map;
    }

    /** @return array<string,int> */
    private function existingPaymentRefs(): array
    {
        $rows = $this->db->query(
            "SELECT legacy_reference FROM payments
              WHERE legacy_reference IS NOT NULL AND legacy_reference <> ''"
        )->fetchAll(PDO::FETCH_COLUMN);

        $map = [];
        foreach ($rows as $ref) {
            $map[(string) $ref] = 1;
        }

        return $map;
    }

    private function referenceExists(string $table, string $reference): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM `{$table}` WHERE reference = :r LIMIT 1");
        $stmt->execute(['r' => $reference]);

        return (bool) $stmt->fetchColumn();
    }

    private function nextReference(string $table, string $prefix): string
    {
        if (!isset($this->referenceCache[$table])) {
            $stmt = $this->db->prepare("SELECT reference FROM `{$table}` WHERE reference LIKE :p");
            $stmt->execute(['p' => $prefix . '-%']);

            $max = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $reference) {
                $number = (int) substr((string) $reference, strlen($prefix) + 1);
                $max    = max($max, $number);
            }

            $this->referenceCache[$table] = $max;
        }

        do {
            $this->referenceCache[$table]++;
            $reference = $prefix . '-' . str_pad((string) $this->referenceCache[$table], 4, '0', STR_PAD_LEFT);
        } while ($this->referenceExists($table, $reference));

        return $reference;
    }

    private static function issue(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }

    private static function hasError(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['level'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    private static function hasWarning(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['level'] ?? '') === 'warning') {
                return true;
            }
        }

        return false;
    }

    private static function hasBlockingIssue(array $issues): bool
    {
        return self::hasError($issues) || self::hasWarning($issues);
    }

    public static function mergeKey(string $name): string
    {
        $key = strtoupper(self::cleanName($name));
        $key = preg_replace('/\s+2[AB]?$/', '', $key) ?? $key;

        $key = str_replace('ROSE ALODI MAHINYA', 'ROSE ANOLDI MAHINYA', $key);
        $key = str_replace('ROSE ANOLDI MAHINYILA', 'ROSE ANOLDI MAHINYA', $key);

        return trim($key);
    }

    private static function cleanName($value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private static function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', self::cleanName($full)) ?: [];

        if (count($parts) === 0) {
            return ['', '', ''];
        }

        if (count($parts) === 1) {
            return [$parts[0], '', ''];
        }

        if (count($parts) === 2) {
            return [$parts[0], '', $parts[1]];
        }

        $first  = array_shift($parts);
        $last   = array_pop($parts);
        $middle = implode(' ', $parts);

        return [$first, $middle, $last];
    }

    private static function cellText($value): string
    {
        if (is_float($value) && floor($value) === $value) {
            return number_format($value, 0, '', '');
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private static function cellDate($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value) && (float) $value > 1000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable $e) {
                return null;
            }
        }

        if (is_string($value) && trim($value) !== '') {
            $text = trim($value);

            // The office writes DD/MM/YYYY everywhere, so parse that before
            // letting strtotime guess (which would read 06/01 as June 1st).
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m)
                && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }

            $ts = strtotime($text);

            return $ts ? date('Y-m-d', $ts) : null;
        }

        return null;
    }

    private static function dateInput(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Browsers send Y-m-d; the team also types DD/MM/YYYY.
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value)) {
            return $value;
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        $ts = strtotime($value);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function round2($value): float
    {
        return round((float) $value, 2);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
