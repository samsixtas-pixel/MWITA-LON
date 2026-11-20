<?php
/**
 * import.php — administrator-only workbook import.
 *
 * GET  import.php                 → upload screen + import history
 * GET  import.php?batch=N         → review screen for one batch
 * GET  import.php?download=N      → re-download the original workbook (backup)
 * POST action=upload              → store the file, JSON
 * POST action=analyze             → read + stage the rows, JSON
 * POST action=decide              → save / accept / skip one row
 * POST action=accept_all          → accept every row without blocking errors
 * POST action=commit              → write the reviewed rows to the live book
 * POST action=cancel              → discard a batch and its stored file
 */

require_once __DIR__ . '/includes/autoload.php';
User::requireAdmin();

$service = new WorkbookImportService();
$action  = (string) ($_POST['action'] ?? '');
$json    = in_array($action, ['upload', 'analyze'], true);

/* ---------- private workbook download (kept as a backup) ---------- */
if (isset($_GET['download'])) {
    $batch = $service->findBatch((int) $_GET['download']);

    if (!$batch) {
        http_response_code(404);
        exit(t('Import batch not found.'));
    }

    $path = $service->filePath($batch);

    if ($path === null) {
        flash('error', t('That workbook is no longer stored on the server.'));
        redirect(BASE_URL . 'import.php');
    }

    $name = (string) ($batch['original_filename'] ?: ('workbook-' . $batch['id'] . '.xlsx'));

    AuditLog::record('import.download', 'import_batches', (int) $batch['id'], 'Downloaded imported workbook');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ---------- actions ---------- */
if (isPost()) {
    $batchId = (int) ($_POST['batch_id'] ?? 0);

    try {
        switch ($action) {
            case 'upload':
                $batch = $service->createFromUpload($_FILES['workbook'] ?? []);

                header('Content-Type: application/json');
                echo json_encode([
                    'ok'       => true,
                    'batch_id' => (int) ($batch['id'] ?? 0),
                    'message'  => t('Upload complete.'),
                ]);
                exit;

            case 'analyze':
                $result = $service->analyze($batchId);

                header('Content-Type: application/json');
                echo json_encode([
                    'ok'       => true,
                    'redirect' => BASE_URL . 'import.php?batch=' . $batchId . '&fresh=1',
                    'counts'   => $result['counts'],
                    'message'  => t('Analysis complete.'),
                ]);
                exit;

            case 'decide':
                $rowId    = (int) ($_POST['row_id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? 'save');
                $fields   = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];

                if (!in_array($decision, ['save', 'accept', 'skip'], true)) {
                    $decision = 'save';
                }

                $service->decide($batchId, $rowId, $decision, $fields);

                flash('success', match ($decision) {
                    'skip'   => t('Row skipped.'),
                    'accept' => t('Row accepted.'),
                    default  => t('Row saved and re-checked.'),
                });

                redirect(BASE_URL . 'import.php?batch=' . $batchId . '#row-' . $rowId);

            case 'accept_all':
                $accepted = $service->acceptAll($batchId);
                flash('success', t(':n row(s) accepted.', ['n' => $accepted]));
                redirect(BASE_URL . 'import.php?batch=' . $batchId);

            case 'commit':
                $result = $service->commit($batchId);

                flash('success', t('Import complete: :c customers, :l loans and :p payments added.', [
                    'c' => (int) $result['customers'],
                    'l' => (int) $result['loans'],
                    'p' => (int) $result['payments'],
                ]));

                redirect(BASE_URL . 'dashboard.php');

            case 'cancel':
                $service->cancel($batchId);
                flash('success', t('Import cancelled. The uploaded file was removed.'));
                redirect(BASE_URL . 'import.php');
        }

        throw new RuntimeException(t('Unknown import action.'));
    } catch (Throwable $e) {
        if ($json) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            exit;
        }

        flash('error', $e->getMessage());
        redirect(BASE_URL . 'import.php' . ($batchId > 0 ? '?batch=' . $batchId : ''));
    }
}

/* ---------- page data ---------- */
$batchId  = (int) ($_GET['batch'] ?? 0);
$batch    = $batchId > 0 ? $service->findBatch($batchId) : null;
$rows     = $batch ? $service->batchRows($batchId) : [];
$counts   = $batch ? $service->counts($batchId) : null;
$batches  = $service->recentBatches(10);

$summary = [];
if ($batch && !empty($batch['summary_json'])) {
    $summary = json_decode((string) $batch['summary_json'], true) ?: [];
}

$bySheet  = ['CUSTOMERS' => [], 'LOANS' => [], 'PAYMENTS' => []];
$unclear  = [];
$ready    = [];
$skipped  = [];
$duplicate = [];
$blocked  = 0;

foreach ($rows as $row) {
    $bySheet[$row['sheet']][] = $row;

    if ($row['status'] === 'unclear') {
        $unclear[] = $row;
        foreach ($row['issues'] as $issue) {
            if (($issue['level'] ?? '') === 'error') {
                $blocked++;
                break;
            }
        }
    } elseif ($row['status'] === 'ready' || $row['status'] === 'imported') {
        $ready[] = $row;
    } elseif ($row['status'] === 'skipped') {
        $skipped[] = $row;
    } elseif ($row['status'] === 'duplicate') {
        $duplicate[] = $row;
    }
}

$pageTitle = t('Import workbook');
$pageScripts = $batch ? [] : ['assets/js/import-workbook.js'];

require_once __DIR__ . '/includes/header.php';
require __DIR__ . '/views/import/index.php';
require_once __DIR__ . '/includes/footer.php';
