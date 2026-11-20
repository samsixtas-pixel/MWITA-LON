<?php
/**
 * tools/cleanup_camera_sessions.php
 * -------------------------------------------------------
 * CLI/cron maintenance for capture sessions and private files.
 *
 * Examples:
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php --minutes=60 --dry-run
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php --stats
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php --integrity
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/autoload.php';

$options = getopt('', ['minutes::', 'days::', 'dry-run', 'stats', 'integrity', 'thumbnails', 'recover', 'orphans', 'imports', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/cleanup_camera_sessions.php [--minutes=120] [--dry-run]\n";
    echo "       php tools/cleanup_camera_sessions.php --stats\n";
    echo "       php tools/cleanup_camera_sessions.php --integrity\n";
    echo "       php tools/cleanup_camera_sessions.php --thumbnails [--dry-run]\n";
    echo "       php tools/cleanup_camera_sessions.php --recover [--dry-run]\n";
    echo "       php tools/cleanup_camera_sessions.php --orphans [--minutes=1440] [--dry-run]\n";
    echo "       php tools/cleanup_camera_sessions.php --imports [--days=7]\n";
    echo "\n";
    echo "  --orphans only removes files under private/customers and their\n";
    echo "  thumbnails that no document, version or customer row references.\n";
    echo "  Run it with --dry-run first on production data.\n";
    exit(0);
}

$minutes = isset($options['minutes']) ? max(15, (int) $options['minutes']) : 120;
$dryRun  = array_key_exists('dry-run', $options);
$service = new DocumentCleanupService();

try {
    if (array_key_exists('stats', $options)) {
        $report = $service->storageReport();
        echo "Private storage\n";
        echo '  files:  ' . number_format($report['private_files']) . "\n";
        echo '  size:   ' . formatBytes($report['private_bytes']) . "\n";
        echo '  active documents: ' . number_format($report['documents'])
           . ' (' . formatBytes($report['document_bytes']) . ")\n";
        echo "Capture sessions\n";
        foreach ($report['sessions'] as $row) {
            echo '  ' . str_pad((string) $row['status'], 12) . $row['n'] . "\n";
        }
        exit(0);
    }

    if (array_key_exists('thumbnails', $options)) {
        $result = $service->regenerateThumbnails($dryRun);
        echo ($dryRun ? 'DRY RUN - nothing will be written' : 'Thumbnail check') . "\n";
        echo 'Documents checked: ' . $result['checked'] . "\n";
        echo 'Thumbnails rebuilt: ' . $result['rebuilt'] . "\n";
        echo 'Could not rebuild:  ' . $result['failed'] . "\n";
        exit($result['failed'] > 0 ? 1 : 0);
    }

    if (array_key_exists('recover', $options)) {
        $result = $service->recoverFailedProcessing($minutes, $dryRun);
        echo ($dryRun ? 'DRY RUN - nothing will be changed' : 'Recovery') . "\n";
        echo 'Documents reactivated: ' . $result['documents_fixed'] . "\n";
        echo 'Documents rejected:    ' . $result['documents_rejected'] . "\n";
        echo 'Temp assets discarded: ' . $result['assets_discarded'] . "\n";
        exit(0);
    }

    if (array_key_exists('orphans', $options)) {
        // Default retention is one day; --minutes can lower it to 60 at most.
        $retention = isset($options['minutes']) ? max(60, (int) $options['minutes']) : 1440;
        $result = $service->cleanupOrphanDocumentFiles($retention, $dryRun);

        echo ($dryRun ? 'DRY RUN - nothing will be deleted' : 'Orphan scan') . "\n";
        echo 'Files scanned: ' . $result['scanned'] . "\n";
        echo 'Orphans found: ' . $result['removed'] . ' (' . formatBytes($result['bytes']) . ")\n";

        exit(0);
    }

    if (array_key_exists('imports', $options)) {
        $days    = isset($options['days']) ? max(1, (int) $options['days']) : 7;
        $imports = new WorkbookImportService();
        $removed = $imports->cleanup($days, false);
        echo 'Cancelled/failed imports removed: ' . $removed . "\n";
        exit(0);
    }

    if (array_key_exists('integrity', $options)) {
        $integrity = $service->checkDocumentIntegrity();
        echo 'Checked:     ' . $integrity['checked'] . "\n";
        echo 'Missing:     ' . $integrity['missing'] . "\n";
        echo 'Mismatched:  ' . $integrity['mismatched'] . "\n";

        foreach ($integrity['rows'] as $row) {
            echo '  document #' . $row['id'] . ' — ' . $row['issue'] . "\n";
        }

        exit($integrity['missing'] || $integrity['mismatched'] ? 1 : 0);
    }

    echo $dryRun ? "DRY RUN — nothing will be deleted\n" : "Cleaning up\n";

    $sessions = $service->cleanupCameraSessions($minutes, $dryRun);
    echo 'Expired sessions: ' . $sessions['expired'] . "\n";
    echo 'Sessions removed: ' . $sessions['removed'] . "\n";

    $orphans = $service->cleanupOrphanTemporaryFiles($minutes * 2, $dryRun);
    echo 'Temporary files scanned: ' . $orphans['scanned'] . "\n";
    echo 'Orphan files removed:    ' . $orphans['removed']
       . ' (' . formatBytes($orphans['bytes']) . ")\n";

    $report = $service->storageReport();
    echo 'Private storage now: ' . formatBytes($report['private_bytes']) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}
