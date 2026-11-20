<?php
/**
 * DocumentCleanupService.php
 * -------------------------------------------------------
 * Maintenance operations for temporary capture files and the
 * Customer Digital File. Safe to run from cron/CLI; never
 * deletes documents that belong to saved customers.
 * -------------------------------------------------------
 */

class DocumentCleanupService
{
    private PDO $db;
    private CameraSession $sessions;

    public function __construct()
    {
        $this->db       = Database::getConnection();
        $this->sessions = new CameraSession();
    }

    /**
     * Expire stale sessions then remove old cancelled/expired/completed
     * sessions and their temporary files.
     *
     * @return array{expired:int,removed:int,dry_run:bool}
     */
    public function cleanupCameraSessions(int $olderThanMinutes = 120, bool $dryRun = false): array
    {
        $expired = $this->sessions->expireStale();

        if ($dryRun) {
            $cutoff = date('Y-m-d H:i:s', time() - max(15, $olderThanMinutes) * 60);
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM camera_sessions
                  WHERE status IN ('cancelled','expired','completed')
                    AND COALESCE(completed_at, cancelled_at, expires_at) < :cutoff"
            );
            $stmt->execute(['cutoff' => $cutoff]);

            return ['expired' => $expired, 'removed' => (int) $stmt->fetchColumn(), 'dry_run' => true];
        }

        return [
            'expired' => $expired,
            'removed' => $this->sessions->cleanup($olderThanMinutes),
            'dry_run' => false,
        ];
    }

    /**
     * Delete files under private/temporary that no camera asset row
     * references and that are older than the retention window.
     *
     * @return array{scanned:int,removed:int,bytes:int,dry_run:bool}
     */
    public function cleanupOrphanTemporaryFiles(int $olderThanMinutes = 240, bool $dryRun = false): array
    {
        $roots = array_values(array_filter([
            realpath(PRIVATE_DIR . '/temporary'),
            realpath(PRIVATE_DIR . '/thumbnails/temporary'),
        ], static fn($path): bool => $path !== false));

        if (!$roots) {
            return ['scanned' => 0, 'removed' => 0, 'bytes' => 0, 'dry_run' => $dryRun];
        }

        $referenced = [];
        $rows = $this->db->query(
            "SELECT temporary_storage_key, thumbnail_key FROM camera_session_assets"
        )->fetchAll();

        foreach ($rows as $row) {
            foreach (['temporary_storage_key', 'thumbnail_key'] as $key) {
                if (!empty($row[$key])) {
                    $absolute = DocumentStorage::absolutePath($row[$key]);
                    if ($absolute !== null) {
                        $referenced[$absolute] = true;
                    }
                }
            }
        }

        $cutoff  = time() - max(30, $olderThanMinutes) * 60;
        $scanned = 0;
        $removed = 0;
        $bytes   = 0;

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    if (!$dryRun && $item->getMTime() < $cutoff) {
                        @rmdir($item->getPathname());
                    }
                    continue;
                }

                $scanned++;
                $path = $item->getRealPath();

                if ($path === false || isset($referenced[$path]) || $item->getMTime() >= $cutoff) {
                    continue;
                }

                $bytes += (int) $item->getSize();
                $removed++;

                if (!$dryRun) {
                    @unlink($path);
                }
            }
        }

        return ['scanned' => $scanned, 'removed' => $removed, 'bytes' => $bytes, 'dry_run' => $dryRun];
    }

    /** Storage usage and document counts. */
    public function storageReport(): array
    {
        $privateBytes = 0;
        $privateFiles = 0;
        $root = realpath(PRIVATE_DIR);

        if ($root !== false) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $privateFiles++;
                    $privateBytes += (int) $item->getSize();
                }
            }
        }

        $docs = $this->db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(file_size_bytes), 0) AS bytes
               FROM customer_documents
              WHERE deleted_at IS NULL AND document_status = 'active'"
        )->fetch();

        $sessions = $this->db->query(
            "SELECT status, COUNT(*) AS n FROM camera_sessions GROUP BY status"
        )->fetchAll();

        return [
            'private_files'  => $privateFiles,
            'private_bytes'  => $privateBytes,
            'documents'      => (int) ($docs['total'] ?? 0),
            'document_bytes' => (int) ($docs['bytes'] ?? 0),
            'sessions'       => $sessions,
        ];
    }

    /**
     * Delete files under private/customers (and document thumbnails) that no
     * database row references. Files younger than the retention window are
     * kept so an in-flight upload is never removed.
     *
     * @return array{scanned:int,removed:int,bytes:int,dry_run:bool}
     */
    public function cleanupOrphanDocumentFiles(int $olderThanMinutes = 1440, bool $dryRun = true): array
    {
        $referenced = [];

        $add = static function (?string $key) use (&$referenced): void {
            if ($key === null || $key === '') {
                return;
            }

            $relative = str_starts_with($key, 'private/') ? substr($key, strlen('private/')) : $key;
            $referenced[$relative] = true;
        };

        foreach ($this->db->query(
            "SELECT storage_key, thumbnail_key FROM customer_documents"
        )->fetchAll() as $row) {
            $add($row['storage_key']);
            $add($row['thumbnail_key']);
        }

        foreach ($this->db->query(
            "SELECT storage_key, thumbnail_key FROM document_versions"
        )->fetchAll() as $row) {
            $add($row['storage_key']);
            $add($row['thumbnail_key']);
        }

        foreach ($this->db->query("SELECT photo_path FROM customers WHERE photo_path IS NOT NULL")->fetchAll() as $row) {
            $add($row['photo_path']);
        }

        $cutoff = time() - max(60, $olderThanMinutes) * 60;
        $scanned = 0;
        $removed = 0;
        $bytes   = 0;

        $roots = [
            PRIVATE_DIR . '/customers',
            PRIVATE_DIR . '/thumbnails/customers',
        ];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->getExtension() === 'html') {
                    continue;
                }

                $scanned++;
                $absolute = str_replace('\\', '/', $item->getPathname());
                $private  = str_replace('\\', '/', (string) realpath(PRIVATE_DIR));
                $relative = ltrim(substr($absolute, strlen($private)), '/');

                if (isset($referenced[$relative])) {
                    continue;
                }

                if ($item->getMTime() > $cutoff) {
                    continue;
                }

                $bytes += (int) $item->getSize();

                if (!$dryRun) {
                    @unlink($item->getPathname());
                }

                $removed++;
            }
        }

        return ['scanned' => $scanned, 'removed' => $removed, 'bytes' => $bytes, 'dry_run' => $dryRun];
    }

    /**
     * Rebuild missing or stale document thumbnails.
     *
     * @return array{checked:int,rebuilt:int,failed:int,dry_run:bool}
     */
    public function regenerateThumbnails(bool $dryRun = false): array
    {
        $stmt = $this->db->query(
            "SELECT id, storage_key, thumbnail_key
               FROM customer_documents
              WHERE deleted_at IS NULL
                AND document_status = 'active'
                AND storage_key IS NOT NULL AND storage_key <> ''
           ORDER BY id"
        );

        $checked = 0;
        $rebuilt = 0;
        $failed  = 0;

        $update = $this->db->prepare(
            "UPDATE customer_documents SET thumbnail_key = :thumb WHERE id = :id"
        );

        foreach ($stmt->fetchAll() as $row) {
            $checked++;
            $primary = DocumentStorage::absolutePath((string) $row['storage_key']);

            if ($primary === null) {
                $failed++;
                continue;
            }

            $current = $row['thumbnail_key']
                ? DocumentStorage::absolutePath((string) $row['thumbnail_key'])
                : null;
            $stale = $current === null || filemtime($current) < filemtime($primary);

            if (!$stale) {
                continue;
            }

            if ($dryRun) {
                $rebuilt++;
                continue;
            }

            $thumb = DocumentStorage::regenerateThumbnail((string) $row['storage_key']);

            if ($thumb === null) {
                $failed++;
                continue;
            }

            $update->execute(['thumb' => $thumb, 'id' => (int) $row['id']]);
            $rebuilt++;
        }

        return ['checked' => $checked, 'rebuilt' => $rebuilt, 'failed' => $failed, 'dry_run' => $dryRun];
    }

    /**
     * Recover documents stuck in `processing` and discard failed camera
     * assets from closed sessions.
     *
     * @return array{documents_fixed:int,documents_rejected:int,assets_discarded:int,dry_run:bool}
     */
    public function recoverFailedProcessing(int $olderThanMinutes = 60, bool $dryRun = false): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(15, $olderThanMinutes) * 60);

        $stmt = $this->db->prepare(
            "SELECT id, storage_key, path
               FROM customer_documents
              WHERE document_status = 'processing' AND uploaded_at < :cutoff"
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $stuck = $stmt->fetchAll();

        $fixed = 0;
        $rejected = 0;

        if ($stuck && !$dryRun) {
            $activate = $this->db->prepare(
                "UPDATE customer_documents SET document_status = 'active' WHERE id = :id"
            );
            $reject = $this->db->prepare(
                "UPDATE customer_documents SET document_status = 'rejected' WHERE id = :id"
            );
        }

        foreach ($stuck as $row) {
            $key = (string) ($row['storage_key'] ?: $row['path']);
            $readable = DocumentStorage::absolutePath($key) !== null
                || DocumentStorage::legacyAbsolutePath((string) $row['path']) !== null;

            if ($dryRun) {
                $readable ? $fixed++ : $rejected++;
                continue;
            }

            if ($readable) {
                $activate->execute(['id' => (int) $row['id']]);
                $fixed++;
            } else {
                $reject->execute(['id' => (int) $row['id']]);
                $rejected++;
            }
        }

        // Failed camera assets on closed sessions can never be accepted.
        $assets = $this->db->prepare(
            "SELECT a.id, a.temporary_storage_key
               FROM camera_session_assets a
               JOIN camera_sessions s ON s.id = a.camera_session_id
              WHERE a.processing_status IN ('failed','uploaded')
                AND s.status IN ('completed','cancelled','expired')
                AND a.created_at < :cutoff"
        );
        $assets->execute(['cutoff' => $cutoff]);
        $assetRows = $assets->fetchAll();
        $discarded = 0;

        foreach ($assetRows as $row) {
            if ($dryRun) {
                $discarded++;
                continue;
            }

            DocumentStorage::deleteKey((string) $row['temporary_storage_key']);
            $this->db->prepare(
                "UPDATE camera_session_assets
                    SET processing_status = 'discarded', discarded_at = NOW()
                  WHERE id = :id"
            )->execute(['id' => (int) $row['id']]);
            $discarded++;
        }

        return [
            'documents_fixed'    => $fixed,
            'documents_rejected' => $rejected,
            'assets_discarded'   => $discarded,
            'dry_run'            => $dryRun,
        ];
    }

    /**
     * Verify that active documents still have readable files and
     * that checksums match where recorded.
     *
     * @return array{checked:int,missing:int,mismatched:int,rows:array}
     */
    public function checkDocumentIntegrity(int $limit = 500): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, customer_id, storage_key, path, checksum, file_size_bytes
               FROM customer_documents
              WHERE deleted_at IS NULL AND document_status = 'active'
           ORDER BY id DESC LIMIT " . max(1, min(5000, $limit))
        );
        $stmt->execute();

        $checked = 0;
        $missing = 0;
        $mismatched = 0;
        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $checked++;
            $key = $row['storage_key'] ?: $row['path'];
            $absolute = DocumentStorage::absolutePath($key);

            if ($absolute === null && !empty($row['path'])) {
                // Pre-migration rows may point at the old upload folders.
                $absolute = DocumentStorage::legacyAbsolutePath($row['path']);
            }

            if ($absolute === null) {
                $missing++;
                $rows[] = ['id' => (int) $row['id'], 'issue' => 'missing'];
                continue;
            }

            if (!empty($row['checksum'])) {
                $actual = hash_file('sha256', $absolute);
                if (!hash_equals((string) $row['checksum'], (string) $actual)) {
                    $mismatched++;
                    $rows[] = ['id' => (int) $row['id'], 'issue' => 'checksum'];
                }
            }
        }

        return ['checked' => $checked, 'missing' => $missing, 'mismatched' => $mismatched, 'rows' => $rows];
    }
}
