#!/usr/bin/env php
<?php
/**
 * PixelHop - scripts/migrate_images_to_db.php
 *
 * Migrasi data images.json -> tabel `images` (Reliability Fase 2).
 *
 * Mode:
 *   --backfill           default: upsert seluruh data images.json ke DB
 *   --verify             bandingkan field-per-field images.json vs DB
 *   --dry-run            hitung & cetak rencana migrasi tanpa menulis
 *   --batch=N            ukuran chunk per iterasi (default 200)
 *
 * Properti:
 *   - Idempotent  : INSERT ... ON DUPLICATE KEY UPDATE (sumber kebenaran = JSON)
 *   - Resumable   : checkpoint data/migrate_images.checkpoint.json per chunk
 *   - Verifiable  : mode --verify membandingkan JSON vs DB
 *   - Aman live   : backup images.json, flock lock file, satu record gagal
 *                   tidak menghentikan batch (log + lanjut)
 *
 * Environment override (untuk pengujian/operasional):
 *   MIGRATE_IMAGES_JSON, MIGRATE_CHECKPOINT_FILE, MIGRATE_LOCK_FILE
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "migrate_images_to_db.php hanya bisa dijalankan dari CLI\n");
    exit(1);
}

require_once __DIR__ . '/../includes/JsonStore.php';
require_once __DIR__ . '/../includes/Logger.php';

// Guard class_exists memungkinkan test harness menginjeksi mock Database
// (mis. SQLite in-memory) tanpa menimpa Database produksi.
if (!class_exists('Database', false)) {
    require_once __DIR__ . '/../includes/Database.php';
}

define('MIGRATE_IMAGES_JSON', getenv('MIGRATE_IMAGES_JSON') ?: __DIR__ . '/../data/images.json');
define('MIGRATE_CHECKPOINT_FILE', getenv('MIGRATE_CHECKPOINT_FILE') ?: __DIR__ . '/../data/migrate_images.checkpoint.json');
define('MIGRATE_LOCK_FILE', getenv('MIGRATE_LOCK_FILE') ?: __DIR__ . '/../data/migrate_images.lock');

/**
 * Muat Alerter secara best-effort. Alerter bersifat opsional; ia butuh
 * vendor/autoload.php (PHPMailer) dan config/mail.php. Bila tidak tersedia
 * (mis. lingkungan uji lokal), script tetap berjalan dan hanya memakai Logger.
 */
$GLOBALS['migrate_alerter_available'] = false;
if (
    is_file(__DIR__ . '/../includes/Alerter.php')
    && is_file(__DIR__ . '/../vendor/autoload.php')
    && is_file(__DIR__ . '/../config/mail.php')
) {
    require_once __DIR__ . '/../includes/Alerter.php';
    $GLOBALS['migrate_alerter_available'] = class_exists('Alerter', false);
}

/**
 * Log error + kirim Alerter::critical bila tersedia. Tidak pernah melempar.
 */
function migrate_critical(string $message, array $context = []): void
{
    Logger::error('migrate', $message, $context);

    if (!empty($GLOBALS['migrate_alerter_available']) && class_exists('Alerter', false)) {
        try {
            Alerter::critical('migrate', $message, $context);
        } catch (Throwable $alertError) {
            Logger::error(
                'migrate',
                'Gagal mengirim Alerter::critical',
                ['error' => $alertError->getMessage()]
            );
        }
    }
}

/**
 * Parse $argv. Return ['mode'=>string,'batch'=>int].
 */
function migrate_parse_args(array $argv): array
{
    $mode = 'backfill';
    $batch = 200;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--verify') {
            $mode = 'verify';
        } elseif ($arg === '--dry-run') {
            $mode = 'dry-run';
        } elseif ($arg === '--backfill') {
            $mode = 'backfill';
        } elseif (preg_match('/^--batch=(\d+)$/', $arg, $m) === 1) {
            $batch = max(1, (int) $m[1]);
        }
    }

    return ['mode' => $mode, 'batch' => $batch];
}

/**
 * Baca images.json + deteksi korup. Data tetap dibaca lewat JsonStore->read();
 * pemeriksaan raw di sini hanya untuk mengubah JSON korup menjadi kegagalan
 * kritis (JsonStore::read() mengembalikan [] untuk file korup).
 *
 * @return array<int|string, array<string, mixed>>
 */
function migrate_read_images(JsonStore $jsonStore, string $jsonPath): array
{
    $raw = @file_get_contents($jsonPath);

    if ($raw === false) {
        if (is_file($jsonPath)) {
            throw new RuntimeException('images.json tidak bisa dibaca: ' . $jsonPath);
        }
        return [];
    }

    if (trim($raw) !== '') {
        $test = json_decode($raw, true);
        if (!is_array($test)) {
            throw new RuntimeException(
                'images.json korup: ' . json_last_error_msg()
            );
        }
    }

    return $jsonStore->read();
}

/**
 * Normalisasi kolom JSON (array -> string JSON, null tetap null, string lolos).
 */
function migrate_json_column(mixed $value, bool $notNull = false): ?string
{
    if (is_array($value)) {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Gagal encode JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    if ($value === null) {
        return $notNull ? '[]' : null;
    }

    if (is_string($value)) {
        return $value;
    }

    if (is_scalar($value)) {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? ($notNull ? '[]' : null) : $json;
    }

    return $notNull ? '[]' : null;
}

/**
 * Konversi nilai epoch ke int|null (nilai kosong dianggap null).
 */
function migrate_int_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return (int) $value;
}

/**
 * Petakan record JSON ke parameter kolom tabel `images`.
 *
 * @return array<string, mixed>
 */
function migrate_map_record(string $id, array $record): array
{
    $userId = $record['user_id'] ?? null;
    if ($userId === null || $userId === '') {
        $userId = null;
    } else {
        $userId = (int) $userId;
    }

    return [
        'id'                  => $id,
        'user_id'             => $userId,
        'ip'                  => (string) ($record['ip'] ?? ''),
        'filename'            => (string) ($record['filename'] ?? ''),
        'mime_type'           => (string) ($record['mime_type'] ?? ''),
        'extension'           => (string) ($record['extension'] ?? ''),
        'size'                => (int) ($record['size'] ?? 0),
        'width'               => (int) ($record['width'] ?? 0),
        'height'              => (int) ($record['height'] ?? 0),
        'hash'                => (string) ($record['hash'] ?? ''),
        'urls'                => migrate_json_column($record['urls'] ?? null, false),
        's3_keys'             => migrate_json_column($record['s3_keys'] ?? [], true),
        'storage_providers'   => migrate_json_column($record['storage_providers'] ?? null, false),
        'view_count'          => (int) ($record['view_count'] ?? 0),
        'last_viewed_at'      => migrate_int_or_null($record['last_viewed_at'] ?? null),
        'delete_at'           => migrate_int_or_null($record['delete_at'] ?? null),
        'marked_for_deletion' => migrate_int_or_null($record['marked_for_deletion'] ?? null),
        'deleting_at'         => migrate_int_or_null($record['deleting_at'] ?? null),
        'last_delete_error'   => migrate_json_column($record['last_delete_error'] ?? null, false),
        'created_at'          => (int) ($record['created_at'] ?? 0),
    ];
}

/**
 * Bangun SQL upsert sesuai driver PDO aktif.
 * MySQL/MariaDB: ON DUPLICATE KEY UPDATE (VALUES()).
 * SQLite (uji lokal): ON CONFLICT(id) DO UPDATE (excluded.*).
 */
function migrate_build_upsert_sql(PDO $pdo, string $table = 'images'): string
{
    $columns = [
        'id', 'user_id', 'ip', 'filename', 'mime_type', 'extension',
        'size', 'width', 'height', 'hash', 'urls', 's3_keys',
        'storage_providers', 'view_count', 'last_viewed_at', 'delete_at',
        'marked_for_deletion', 'deleting_at', 'last_delete_error', 'created_at',
    ];

    $cols = implode(', ', array_map(static fn ($c) => "`$c`", $columns));
    $placeholders = implode(', ', array_map(static fn ($c) => ":$c", $columns));
    $nonPk = array_values(array_filter($columns, static fn ($c) => $c !== 'id'));

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $updates = array_map(static fn ($c) => "`$c` = excluded.`$c`", $nonPk);
        $updates[] = '`updated_at` = CURRENT_TIMESTAMP';
        return "INSERT INTO `$table` ($cols) VALUES ($placeholders) "
            . 'ON CONFLICT(`id`) DO UPDATE SET ' . implode(', ', $updates);
    }

    $updates = array_map(static fn ($c) => "`$c` = VALUES(`$c`)", $nonPk);
    $updates[] = '`updated_at` = NOW()';
    return "INSERT INTO `$table` ($cols) VALUES ($placeholders) "
        . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
}

/**
 * Mode --dry-run: cetak rencana migrasi tanpa koneksi DB & tanpa menulis.
 */
function migrate_dry_run(array $records, int $batchSize): int
{
    $ids = array_keys($records);
    sort($ids, SORT_STRING);

    $total = count($ids);
    $batches = $total > 0 ? (int) ceil($total / $batchSize) : 0;

    echo "Dry-run migrasi images.json -> images\n";
    echo "Total record : {$total}\n";
    echo "Batch size   : {$batchSize}\n";
    echo "Jumlah batch : {$batches}\n";
    echo "Rencana per record:\n";

    foreach ($ids as $i => $id) {
        $record = is_array($records[$id]) ? $records[$id] : [];
        echo sprintf(
            "  #%d %s user_id=%s file=%s size=%d delete_at=%s\n",
            $i + 1,
            $id,
            (isset($record['user_id']) && $record['user_id'] !== '' && $record['user_id'] !== null)
                ? (string) $record['user_id']
                : 'null(guest)',
            (string) ($record['filename'] ?? ''),
            (int) ($record['size'] ?? 0),
            (isset($record['delete_at']) && $record['delete_at'] !== '')
                ? (string) $record['delete_at']
                : 'null'
        );
    }

    echo "Dry-run selesai (tidak ada yang ditulis).\n";
    return 0;
}

/**
 * Mode --verify: bandingkan field kunci images.json vs baris tabel images.
 */
function migrate_verify(PDO $pdo, array $records): int
{
    $rows = $pdo->query('SELECT * FROM `images`')->fetchAll(PDO::FETCH_ASSOC);

    $dbById = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) {
            $dbById[(string) $row['id']] = $row;
        }
    }

    $ids = array_keys($records);
    sort($ids, SORT_STRING);

    $totalJson = count($ids);
    $totalDb = count($rows);
    $matched = 0;
    $mismatched = 0;

    foreach ($ids as $id) {
        $record = is_array($records[$id]) ? $records[$id] : [];

        if (!isset($dbById[$id])) {
            $mismatched++;
            echo "MISMATCH id={$id} field=row expected=present actual=missing\n";
            continue;
        }

        $row = $dbById[$id];
        $recordMismatches = [];

        // user_id (null = guest)
        $expectedUserId = ($record['user_id'] ?? null);
        $expectedUserId = ($expectedUserId === null || $expectedUserId === '')
            ? null
            : (int) $expectedUserId;
        $actualUserId = ($row['user_id'] ?? null);
        $actualUserId = ($actualUserId === null || $actualUserId === '')
            ? null
            : (int) $actualUserId;
        if ($expectedUserId !== $actualUserId) {
            $recordMismatches[] = 'user_id expected=' . var_export($expectedUserId, true)
                . ' actual=' . var_export($actualUserId, true);
        }

        // size
        $expectedSize = (int) ($record['size'] ?? 0);
        $actualSize = (int) ($row['size'] ?? 0);
        if ($expectedSize !== $actualSize) {
            $recordMismatches[] = "size expected={$expectedSize} actual={$actualSize}";
        }

        // hash
        $expectedHash = (string) ($record['hash'] ?? '');
        $actualHash = (string) ($row['hash'] ?? '');
        if ($expectedHash !== $actualHash) {
            $recordMismatches[] = 'hash expected=' . var_export($expectedHash, true)
                . ' actual=' . var_export($actualHash, true);
        }

        // created_at
        $expectedCreatedAt = (int) ($record['created_at'] ?? 0);
        $actualCreatedAt = (int) ($row['created_at'] ?? 0);
        if ($expectedCreatedAt !== $actualCreatedAt) {
            $recordMismatches[] = "created_at expected={$expectedCreatedAt} actual={$actualCreatedAt}";
        }

        // delete_at
        $expectedDeleteAt = migrate_int_or_null($record['delete_at'] ?? null);
        $actualDeleteAt = migrate_int_or_null($row['delete_at'] ?? null);
        if ($expectedDeleteAt !== $actualDeleteAt) {
            $recordMismatches[] = 'delete_at expected=' . var_export($expectedDeleteAt, true)
                . ' actual=' . var_export($actualDeleteAt, true);
        }

        // s3_keys setelah decode
        $expectedS3 = $record['s3_keys'] ?? [];
        $actualS3Raw = $row['s3_keys'] ?? '[]';
        $actualS3 = json_decode((string) $actualS3Raw, true);
        if (!is_array($actualS3)) {
            $actualS3 = is_array($expectedS3) ? null : $actualS3Raw;
        }
        if ($expectedS3 != $actualS3) {
            $recordMismatches[] = 's3_keys expected=' . json_encode($expectedS3)
                . ' actual=' . (is_scalar($actualS3) ? (string) $actualS3 : json_encode($actualS3));
        }

        if (count($recordMismatches) > 0) {
            $mismatched++;
            foreach ($recordMismatches as $mismatch) {
                echo "MISMATCH id={$id} field={$mismatch}\n";
            }
        } else {
            $matched++;
        }
    }

    // Baris DB ekstra (tidak ada di JSON) tidak dianggap mismatch, tapi dilaporkan.
    $extraIds = array_values(array_diff(array_keys($dbById), $ids));
    if (count($extraIds) > 0) {
        echo 'INFO: ' . count($extraIds) . " baris DB ekstra (tidak ada di JSON): "
            . implode(', ', array_slice($extraIds, 0, 20))
            . (count($extraIds) > 20 ? ' ...' : '') . "\n";
    }

    echo "Verify summary: total_json={$totalJson} total_db={$totalDb} matched={$matched} mismatched={$mismatched}\n";

    return ($mismatched === 0 && $totalDb >= $totalJson) ? 0 : 1;
}

/**
 * Mode --backfill: migrasi idempotent + resumable + lock + checkpoint.
 */
function migrate_backfill(JsonStore $jsonStore, array $records, int $batchSize): int
{
    $checkpointStore = new JsonStore(MIGRATE_CHECKPOINT_FILE);

    // Lock agar tidak ada 2 migrasi berjalan bersamaan.
    $lockFp = @fopen(MIGRATE_LOCK_FILE, 'c');
    if ($lockFp === false) {
        migrate_critical('migrate', 'Tidak bisa membuka lock file ' . MIGRATE_LOCK_FILE);
        return 1;
    }

    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        migrate_critical('migrate', 'Migrasi lain sedang berjalan (lock ' . MIGRATE_LOCK_FILE . ' terkunci)');
        return 1;
    }

    try {
        // Backup dulu sebelum menyentuh DB.
        $backupPath = $jsonStore->backup();
        Logger::info(
            'migrate',
            'Backup images.json selesai',
            ['backup' => $backupPath]
        );
        echo 'Backup images.json: ' . ($backupPath ?? '(file tidak ada, dilewati)') . "\n";

        $ids = array_keys($records);
        sort($ids, SORT_STRING);
        $total = count($ids);

        // Resumable: baca checkpoint, lanjut dari last_id.
        $checkpoint = $checkpointStore->read();
        $checkpointLastId = $checkpoint['last_id'] ?? null;
        $initialDone = (int) ($checkpoint['done'] ?? 0);

        $skip = true;
        if ($checkpointLastId === null) {
            $skip = false;
        }

        $pdo = Database::getInstance();
        $sql = migrate_build_upsert_sql($pdo);
        $stmt = $pdo->prepare($sql);

        $done = $initialDone;
        $failed = 0;
        $processed = 0;
        $checkpointFrozen = false;

        echo "Backfill dimulai: total={$total} batch={$batchSize}"
            . ($checkpointLastId !== null ? " resume_dari={$checkpointLastId}" : '') . "\n";

        $chunks = array_chunk($ids, $batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkLastId = null;
            $chunkOk = true;

            foreach ($chunk as $id) {
                // Lanjut dari last_id (urut by key).
                if ($skip) {
                    if ((string) $id === (string) $checkpointLastId) {
                        $skip = false;
                    }
                    continue;
                }

                $chunkLastId = $id;

                try {
                    $record = is_array($records[$id]) ? $records[$id] : [];
                    $params = migrate_map_record((string) $id, $record);
                    $stmt->execute($params);
                    $done++;
                    $processed++;
                } catch (Throwable $e) {
                    $failed++;
                    $chunkOk = false;
                    $checkpointFrozen = true;
                    Logger::error(
                        'migrate',
                        'Gagal memigrasikan record; batch tetap dilanjutkan',
                        ['id' => $id, 'error' => $e->getMessage()]
                    );
                    echo "ERROR record id={$id}: {$e->getMessage()}\n";
                }
            }

            // Simpan checkpoint per chunk (bila tidak ada kegagalan di run ini;
            // checkpoint beku membuat re-run mengulang dari chunk baik terakhir).
            // Checkpoint ditulis SEBELUM log/echo agar bila proses dihentikan
            // tepat setelah baris progres, status terakhir sudah tersimpan.
            if ($chunkLastId !== null && $chunkOk && !$checkpointFrozen) {
                $checkpointStore->write([
                    'last_id' => $chunkLastId,
                    'done'    => $done,
                    'total'   => $total,
                ]);
            }

            if ($chunkLastId !== null) {
                Logger::info(
                    'migrate',
                    'Chunk selesai',
                    [
                        'chunk'     => $chunkIndex + 1,
                        'last_id'   => $chunkLastId,
                        'done'      => $done,
                        'total'     => $total,
                        'failed'    => $failed,
                    ]
                );
                echo "Chunk " . ($chunkIndex + 1) . "/" . count($chunks)
                    . " selesai: done={$done}/{$total} failed={$failed}\n";
            }
        }

        if ($failed === 0) {
            @unlink(MIGRATE_CHECKPOINT_FILE);
            Logger::info(
                'migrate',
                'Backfill selesai',
                ['done' => $done, 'total' => $total, 'failed' => $failed]
            );
            echo "Backfill selesai: done={$done}/{$total} failed={$failed}\n";
            return 0;
        }

        Logger::error(
            'migrate',
            'Backfill selesai dengan kegagalan sebagian',
            ['done' => $done, 'total' => $total, 'failed' => $failed]
        );
        echo "Backfill selesai dengan kegagalan: done={$done}/{$total} failed={$failed} "
            . "(checkpoint dipertahankan untuk re-run)\n";
        return 1;
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/**
 * Entry point.
 */
function migrate_main(array $argv): int
{
    $opts = migrate_parse_args($argv);
    $batchSize = $opts['batch'];

    try {
        $jsonStore = new JsonStore(MIGRATE_IMAGES_JSON);
        $records = migrate_read_images($jsonStore, MIGRATE_IMAGES_JSON);

        if ($opts['mode'] === 'dry-run') {
            return migrate_dry_run($records, $batchSize);
        }

        if ($opts['mode'] === 'verify') {
            $pdo = Database::getInstance();
            return migrate_verify($pdo, $records);
        }

        // Default/backfill: preflight koneksi DB agar DB down terdeteksi
        // sebagai kegagalan kritis sebelum loop per-record.
        $pdo = Database::getInstance();
        $pdo->query('SELECT 1');

        return migrate_backfill($jsonStore, $records, $batchSize);
    } catch (Throwable $e) {
        migrate_critical(
            'migrate',
            'Kegagalan kritis migrasi images.json -> images',
            ['error' => $e->getMessage(), 'mode' => $opts['mode']]
        );
        echo 'CRITICAL: ' . $e->getMessage() . "\n";
        return 1;
    }
}

exit(migrate_main($argv));
