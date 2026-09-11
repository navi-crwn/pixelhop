#!/usr/bin/env php
<?php
/**
 * PixelHop - cron/reconcile_pending.php
 *
 * Crash recovery untuk operasi upload multi-langkah yang mati di tengah.
 *
 * Mengambil baris `pending_operations` ber-state non-terminal
 * ('started' / 's3_uploaded' / 'metadata_saved') yang sudah lebih tua dari
 * batas stale (default 900 detik), lalu untuk tiap operasi memutuskan:
 *
 *   - metadata gambar SUDAH ada di tabel `images`
 *       => upload sebenarnya sukses, hanya jurnal yang tidak sempat
 *          complete. Tandai completed.
 *   - metadata gambar BELUM ada
 *       => proses mati sebelum metadata tersimpan. Objek S3 yang tercatat
 *          di payload dianggap orphan dan dihapus via
 *          R2StorageManager::deleteImage(), lalu jurnal ditandai failed.
 *
 * Opsi:
 *   --dry-run   Cetak rencana aksi tanpa menulis jurnal / menghapus objek.
 *
 * Log:
 *   Logger::info/error('reconcile', ...) per operasi + ringkasan.
 *   Alerter::critical bila ada operasi yang gagal di-reconcile
 *   (mis. objek S3 tidak bisa dihapus) dan barisnya dibiarkan pending
 *   agar run berikutnya mencoba ulang.
 *
 * Run:
 *   php cron/reconcile_pending.php
 *   php cron/reconcile_pending.php --dry-run
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "reconcile_pending.php hanya bisa dijalankan dari CLI\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

// Bila test harness sudah mendefinisikan class (mis. Database SQLite
// in-memory atau mock R2StorageManager), jangan menimpa dengan file produksi.
if (!class_exists('Logger', false)) {
    require_once ROOT_PATH . '/includes/Logger.php';
}

if (!class_exists('UploadJournal', false)) {
    require_once ROOT_PATH . '/includes/UploadJournal.php';
}

if (!class_exists('Database', false)) {
    require_once ROOT_PATH . '/includes/Database.php';
}

/**
 * Muat Alerter secara best-effort. Alerter butuh vendor/autoload.php
 * (PHPMailer) dan config/mail.php. Bila tidak tersedia, script tetap
 * berjalan dan hanya memakai Logger.
 */
if (!class_exists('Alerter', false)) {
    if (
        is_file(ROOT_PATH . '/includes/Alerter.php')
        && is_file(ROOT_PATH . '/vendor/autoload.php')
        && is_file(ROOT_PATH . '/config/mail.php')
    ) {
        require_once ROOT_PATH . '/includes/Alerter.php';
    }
}

/**
 * Siapkan R2StorageManager. Bila test harness sudah menyediakan mock
 * (class_exists tanpa file produksi + instance di global), pakai instance
 * tersebut; bila tidak, baca config/s3.php dan buat instance produksi.
 */
if (!class_exists('R2StorageManager', false)) {
    require_once ROOT_PATH . '/includes/R2StorageManager.php';
}

$GLOBALS['reconcile_storage_manager'] = $GLOBALS['reconcile_storage_manager'] ?? null;

if (!$GLOBALS['reconcile_storage_manager'] instanceof R2StorageManager) {
    $configFile = ROOT_PATH . '/config/s3.php';
    if (!is_file($configFile)) {
        fwrite(STDERR, "config/s3.php tidak ditemukan; jalankan dari environment produksi atau injeksi mock\n");
        exit(1);
    }

    try {
        $config = require $configFile;
        $GLOBALS['reconcile_storage_manager'] = new R2StorageManager($config);
    } catch (Throwable $e) {
        reconcile_critical('Gagal inisialisasi R2StorageManager', ['error' => $e->getMessage()]);
        echo 'CRITICAL: ' . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Log error + Alerter::critical bila class tersedia. Tidak pernah melempar.
 *
 * @param array<string, mixed> $context
 */
function reconcile_critical(string $message, array $context = []): void
{
    Logger::error('reconcile', $message, $context);

    if (class_exists('Alerter', false)) {
        try {
            Alerter::critical('reconcile', $message, $context);
        } catch (Throwable $alertError) {
            Logger::error(
                'reconcile',
                'Gagal mengirim Alerter::critical',
                ['error' => $alertError->getMessage()]
            );
        }
    }
}

/**
 * Parse $argv. Return ['dry_run' => bool].
 *
 * @param array<int, string> $argv
 * @return array{dry_run: bool}
 */
function reconcile_parse_args(array $argv): array
{
    $dryRun = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        }
    }

    return ['dry_run' => $dryRun];
}

/**
 * Decode payload JSON dari baris pending_operations.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function reconcile_payload(array $row): array
{
    $raw = $row['payload'] ?? null;

    if (is_array($raw)) {
        return $raw;
    }

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/**
 * Cek metadata gambar di tabel `images` berdasarkan id.
 */
function reconcile_metadata_exists(string $id): bool
{
    $row = Database::fetchOne('SELECT id FROM images WHERE id = ? LIMIT 1', [$id]);

    return is_array($row) && array_key_exists('id', $row);
}

/**
 * Entry point.
 *
 * @param array<int, string> $argv
 */
function reconcile_main(array $argv): int
{
    $opts = reconcile_parse_args($argv);
    $dryRun = $opts['dry_run'];

    /** @var R2StorageManager $storageManager */
    $storageManager = $GLOBALS['reconcile_storage_manager'];

    try {
        $journal = new UploadJournal(Database::getInstance());
        $pending = $journal->stalePending(900);
    } catch (Throwable $e) {
        reconcile_critical('Gagal membaca pending_operations', ['error' => $e->getMessage()]);
        echo 'CRITICAL: ' . $e->getMessage() . "\n";
        return 1;
    }

    $summary = [
        'mode' => $dryRun ? 'dry_run' : 'live',
        'pending' => count($pending),
        'completed' => 0,
        'orphans_deleted' => 0,
        'failed_reconcile' => 0,
    ];

    echo 'Reconcile pending_operations [' . $summary['mode'] . "]\n";
    echo 'Pending stale : ' . $summary['pending'] . "\n";

    foreach ($pending as $op) {
        $operationId = (string) ($op['operation_id'] ?? '');
        if ($operationId === '') {
            Logger::warning('reconcile', 'Baris pending tanpa operation_id, dilewati', ['row' => $op]);
            continue;
        }

        $payload = reconcile_payload($op);
        $state = (string) ($op['state'] ?? 'started');

        echo '  OP ' . $operationId . ' state=' . $state . ($dryRun ? ' [DRY-RUN]' : '') . "\n";

        try {
            if (reconcile_metadata_exists($operationId)) {
                echo '    -> metadata ada di tabel images; jurnal ditandai completed' . "\n";

                if (!$dryRun) {
                    try {
                        $journal->markReconciled($operationId, 'completed');
                    } catch (Throwable $e) {
                        reconcile_critical('Gagal menandai jurnal completed', [
                            'id' => $operationId,
                            'error' => $e->getMessage(),
                        ]);
                        $summary['failed_reconcile']++;
                        continue;
                    }
                }

                Logger::info('reconcile', 'Metadata sudah ada; jurnal ditandai completed', [
                    'id' => $operationId,
                    'state_sebelumnya' => $state,
                    'dry_run' => $dryRun,
                ]);
                $summary['completed']++;
                continue;
            }

            // Metadata belum ada -> objek S3 di payload adalah orphan.
            $s3Keys = $payload['s3_keys'] ?? [];
            if (!is_array($s3Keys)) {
                $s3Keys = [];
            }

            $size = (int) ($payload['size'] ?? 0);
            $sizes = $payload['sizes'] ?? null;
            if (!is_array($sizes)) {
                $sizes = null;
            }

            if ($s3Keys === []) {
                echo "    -> metadata TIDAK ada; payload tanpa s3_keys\n";
                if (!$dryRun) {
                    try {
                        $journal->markReconciled(
                            $operationId,
                            'failed',
                            'metadata tidak ada dan payload tidak memuat s3_keys'
                        );
                    } catch (Throwable $e) {
                        reconcile_critical('Gagal menandai jurnal failed', [
                            'id' => $operationId,
                            'error' => $e->getMessage(),
                        ]);
                        $summary['failed_reconcile']++;
                        continue;
                    }
                }

                Logger::info('reconcile', 'Metadata tidak ada; tanpa s3_keys, jurnal ditandai failed', [
                    'id' => $operationId,
                    'dry_run' => $dryRun,
                ]);
                $summary['orphans_deleted']++;
                continue;
            }

            echo '    -> metadata TIDAK ada; hapus ' . count($s3Keys) . " objek S3 orphan\n";

            $deleteResult = null;
            if (!$dryRun) {
                try {
                    $deleteResult = $storageManager->deleteImage($s3Keys, $size, $sizes);
                } catch (Throwable $e) {
                    reconcile_critical('Objek S3 orphan tidak bisa dihapus (exception)', [
                        'id' => $operationId,
                        'error' => $e->getMessage(),
                        's3_keys' => $s3Keys,
                    ]);
                    $summary['failed_reconcile']++;
                    continue;
                }

                $deleted = (int) ($deleteResult['deleted'] ?? 0);
                $total = (int) ($deleteResult['total'] ?? count($s3Keys));
                echo '    -> deleted ' . $deleted . '/' . $total . " objek\n";

                if ($deleted < $total) {
                    reconcile_critical('Objek S3 orphan tidak bisa dihapus', [
                        'id' => $operationId,
                        'result' => $deleteResult,
                    ]);
                    $summary['failed_reconcile']++;
                    continue;
                }

                try {
                    $journal->markReconciled($operationId, 'failed', 'orphan S3 dihapus oleh reconcile');
                } catch (Throwable $e) {
                    reconcile_critical('Gagal menandai jurnal failed', [
                        'id' => $operationId,
                        'error' => $e->getMessage(),
                    ]);
                    $summary['failed_reconcile']++;
                    continue;
                }
            } else {
                echo '    -> dry-run: ' . count($s3Keys) . " objek akan dihapus\n";
            }

            Logger::info('reconcile', 'Orphan S3 dihapus; jurnal ditandai failed', [
                'id' => $operationId,
                'state_sebelumnya' => $state,
                's3_keys' => $s3Keys,
                'dry_run' => $dryRun,
            ]);
            $summary['orphans_deleted']++;
        } catch (Throwable $e) {
            reconcile_critical('Operasi gagal di-reconcile', [
                'id' => $operationId,
                'error' => $e->getMessage(),
            ]);
            $summary['failed_reconcile']++;
        }
    }

    echo 'Summary: ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
    Logger::info('reconcile', 'Ringkasan reconcile pending_operations', $summary);

    return $summary['failed_reconcile'] > 0 ? 1 : 0;
}

exit(reconcile_main($argv));
