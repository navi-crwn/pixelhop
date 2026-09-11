#!/usr/bin/env php
<?php
/**
 * PixelHop - cron/images_drift_check.php
 *
 * Drift checker untuk mode dual_write (dan mode transisi lainnya):
 * membandingkan metadata foto antara data/images.json (JSON) dengan
 * tabel `images` (DB) per-id.
 *
 * Field kunci yang dibandingkan:
 *   user_id, size, hash, created_at, delete_at, s3_keys (setelah decode),
 *   view_count, marked_for_deletion
 *
 * Perilaku:
 *   - Tanpa drift -> Logger::info('drift', 'Sinkron', ...) + exit 0.
 *   - Ada drift (mismatch/json_only/db_only) -> Logger::error + Alerter::critical
 *     (bila class tersedia) + exit 1.
 *   - Kegagalan DB/JSON dianggap drift (alert + exit 1), TIDAK pernah lolos diam.
 *
 * Opsi:
 *   --limit=N   sampling N id acak dari JSON (default: semua).
 *
 * Environment override (untuk pengujian/operasional):
 *   DRIFT_IMAGES_JSON   path alternatif data/images.json
 *                       (default: <root>/data/images.json)
 *
 * Run:
 *   php cron/images_drift_check.php
 *   php cron/images_drift_check.php --limit=100
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "images_drift_check.php hanya bisa dijalankan dari CLI\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/includes/JsonStore.php';
require_once ROOT_PATH . '/includes/Logger.php';

// Guard class_exists memungkinkan test harness menginjeksi mock Database
// (mis. SQLite in-memory) tanpa menimpa Database produksi.
if (!class_exists('Database', false)) {
    require_once ROOT_PATH . '/includes/Database.php';
}

/**
 * Muat Alerter secara best-effort. Alerter bersifat opsional; ia butuh
 * vendor/autoload.php (PHPMailer) dan config/mail.php. Bila tidak tersedia
 * (mis. lingkungan uji lokal), script tetap berjalan dan hanya memakai Logger.
 */
$GLOBALS['drift_alerter_available'] = false;
if (
    is_file(ROOT_PATH . '/includes/Alerter.php')
    && is_file(ROOT_PATH . '/vendor/autoload.php')
    && is_file(ROOT_PATH . '/config/mail.php')
) {
    require_once ROOT_PATH . '/includes/Alerter.php';
}

// Bila Alerter sudah tersedia (di-require produksi, atau didefinisikan oleh
// test harness), pakai untuk alert. Alerter tidak pernah menjadi syarat
// keberhasilan drift check.
$GLOBALS['drift_alerter_available'] = class_exists('Alerter', false);

define('DRIFT_IMAGES_JSON', getenv('DRIFT_IMAGES_JSON') ?: ROOT_PATH . '/data/images.json');

/**
 * Log error + kirim Alerter::critical bila class tersedia. Tidak pernah melempar.
 *
 * @param array<int|string, mixed> $context
 */
function drift_critical(string $message, array $context = []): void
{
    Logger::error('drift', $message, $context);

    if (!empty($GLOBALS['drift_alerter_available']) && class_exists('Alerter', false)) {
        try {
            Alerter::critical('drift', $message, $context);
        } catch (Throwable $alertError) {
            Logger::error(
                'drift',
                'Gagal mengirim Alerter::critical',
                ['error' => $alertError->getMessage()]
            );
        }
    }
}

/**
 * Parse $argv. Return ['limit' => int]; 0 berarti semua record.
 *
 * @param array<int, string> $argv
 * @return array{limit: int}
 */
function drift_parse_args(array $argv): array
{
    $limit = 0;

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
            $limit = max(1, (int) $m[1]);
        }
    }

    return ['limit' => $limit];
}

/**
 * Baca seluruh images.json + deteksi korup.
 *
 * Data tetap dibaca lewat JsonStore->read(); pemeriksaan raw di sini hanya
 * untuk mengubah JSON korup / tidak terbaca menjadi kegagalan kritis
 * (JsonStore::read() mengembalikan [] untuk file korup, yang bisa membuat
 * drift checker lolos secara keliru bila DB juga kosong).
 *
 * @return array<int|string, mixed>
 */
function drift_read_json(string $jsonPath): array
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
            throw new RuntimeException('images.json korup: ' . json_last_error_msg());
        }
    }

    $store = new JsonStore($jsonPath);
    return $store->read();
}

/**
 * Ambil seluruh baris tabel `images` dari DB.
 *
 * @return array{rows: array<int, array<string, mixed>>, by_id: array<string, array<string, mixed>>}
 */
function drift_fetch_db(): array
{
    $rows = Database::fetchAll('SELECT * FROM images');

    $byId = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = $row['id'] ?? null;
        if ($id === null || $id === '') {
            continue;
        }

        $byId[(string) $id] = $row;
    }

    return ['rows' => $rows, 'by_id' => $byId];
}

/**
 * Normalisasi nilai integer nullable (epoch): null / '' -> null.
 */
function drift_int_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}

/**
 * Normalisasi integer.
 */
function drift_int(mixed $value): int
{
    return (int) $value;
}

/**
 * Normalisasi string.
 */
function drift_string(mixed $value): string
{
    return (string) $value;
}

/**
 * Normalisasi s3_keys: JSON string di-decode, array dibiarkan, null dibiarkan.
 *
 * @return mixed
 */
function drift_s3(mixed $value): mixed
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // JSON tidak valid: biarkan sebagai string mentah agar perbedaan
        // tetap terlihat (jangan dianggap sama dengan []).
        return $value;
    }

    if ($value === null) {
        return null;
    }

    return $value;
}

/**
 * Normalisasi nilai sesuai tipe field.
 *
 * @return mixed
 */
function drift_normalize(mixed $value, string $type): mixed
{
    return match ($type) {
        'nullable_int' => drift_int_or_null($value),
        'int' => drift_int($value),
        'string' => drift_string($value),
        's3' => drift_s3($value),
        default => $value,
    };
}

/**
 * Bandingkan satu record JSON dengan satu baris DB.
 *
 * @param array<string, mixed> $jsonRecord
 * @param array<string, mixed> $dbRecord
 * @return array<int, array{id: string, field: string, expected: mixed, actual: mixed}>
 */
function drift_compare_record(string $id, array $jsonRecord, array $dbRecord): array
{
    $fields = [
        'user_id' => ['type' => 'nullable_int'],
        'size' => ['type' => 'int'],
        'hash' => ['type' => 'string'],
        'created_at' => ['type' => 'int'],
        'delete_at' => ['type' => 'nullable_int'],
        's3_keys' => ['type' => 's3'],
        'view_count' => ['type' => 'int'],
        'marked_for_deletion' => ['type' => 'nullable_int'],
    ];

    $mismatches = [];

    foreach ($fields as $field => $spec) {
        $expected = drift_normalize($jsonRecord[$field] ?? null, $spec['type']);
        $actual = drift_normalize($dbRecord[$field] ?? null, $spec['type']);

        // s3_keys dibandingkan loose (==) karena array assoc; field lain strict.
        $same = $spec['type'] === 's3'
            ? ($expected == $actual)
            : ($expected === $actual);

        if (!$same) {
            $mismatches[] = [
                'id' => $id,
                'field' => $field,
                'expected' => $expected,
                'actual' => $actual,
            ];
        }
    }

    return $mismatches;
}

/**
 * Ubah nilai menjadi string untuk ditampilkan di CLI.
 *
 * @param mixed $value
 */
function drift_display_value(mixed $value): string
{
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '[unencodable array]' : $json;
    }

    return var_export($value, true);
}

/**
 * Entry point.
 *
 * @param array<int, string> $argv
 */
function drift_main(array $argv): int
{
    $opts = drift_parse_args($argv);
    $limit = $opts['limit'];

    try {
        $json = drift_read_json(DRIFT_IMAGES_JSON);
        $jsonIds = array_keys($json);

        $db = drift_fetch_db();
        $dbById = $db['by_id'];
        $dbIds = array_keys($dbById);

        $totalJson = count($jsonIds);
        $totalDb = count($db['rows']);

        // json_only / db_only dihitung dari SET PENUH agar selalu terdeteksi,
        // bahkan saat perbandingan field memakai --limit.
        $jsonOnly = array_values(array_diff($jsonIds, $dbIds));
        $dbOnly = array_values(array_diff($dbIds, $jsonIds));

        // Sampling N id acak dari JSON (default: semua).
        if ($limit > 0 && $limit < $totalJson) {
            $sampleIds = $jsonIds;
            shuffle($sampleIds);
            $sampleIds = array_slice($sampleIds, 0, $limit);
            $sampled = true;
        } else {
            $sampleIds = $jsonIds;
            $sampled = false;
        }

        $matched = 0;
        $mismatched = 0;
        $mismatchDetails = [];

        foreach ($sampleIds as $id) {
            $id = (string) $id;

            // Record JSON yang hilang di DB sudah dihitung sebagai json_only.
            if (!array_key_exists($id, $dbById)) {
                continue;
            }

            $jsonRecord = is_array($json[$id]) ? $json[$id] : [];
            $recordMismatches = drift_compare_record($id, $jsonRecord, $dbById[$id]);

            if ($recordMismatches !== []) {
                $mismatched++;
                foreach ($recordMismatches as $mismatch) {
                    $mismatchDetails[] = $mismatch;
                }
            } else {
                $matched++;
            }
        }

        $summary = [
            'total_json' => $totalJson,
            'total_db' => $totalDb,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'json_only' => count($jsonOnly),
            'db_only' => count($dbOnly),
        ];

        echo "Drift check images.json vs images\n";
        echo 'JSON path : ' . DRIFT_IMAGES_JSON . "\n";
        echo 'Mode      : ' . ($sampled ? "sampling limit={$limit}" : 'full') . "\n";

        foreach ($mismatchDetails as $mismatch) {
            echo 'MISMATCH id=' . $mismatch['id']
                . ' field=' . $mismatch['field']
                . ' expected=' . drift_display_value($mismatch['expected'])
                . ' actual=' . drift_display_value($mismatch['actual']) . "\n";
        }

        foreach ($jsonOnly as $id) {
            echo 'JSON_ONLY id=' . $id . "\n";
        }

        foreach ($dbOnly as $id) {
            echo 'DB_ONLY id=' . $id . "\n";
        }

        $detail = [
            'summary' => $summary,
            'mismatches' => $mismatchDetails,
            'json_only' => $jsonOnly,
            'db_only' => $dbOnly,
        ];

        $driftCount = $summary['mismatched'] + $summary['json_only'] + $summary['db_only'];

        echo 'Summary: ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";

        if ($driftCount > 0) {
            Logger::error('drift', 'Drift terdeteksi', $detail);

            if (!empty($GLOBALS['drift_alerter_available']) && class_exists('Alerter', false)) {
                try {
                    Alerter::critical('drift', 'Drift terdeteksi', $detail);
                } catch (Throwable $alertError) {
                    Logger::error(
                        'drift',
                        'Gagal mengirim Alerter::critical',
                        ['error' => $alertError->getMessage()]
                    );
                }
            }

            echo "Drift terdeteksi\n";
            return 1;
        }

        Logger::info('drift', 'Sinkron', ['total' => $summary]);
        echo "Sinkron\n";
        return 0;
    } catch (Throwable $e) {
        // Kegagalan DB (koneksi/query), JSON korup, dll. dianggap drift:
        // alert + exit 1, JANGAN pernah lolos diam-diam.
        drift_critical(
            'Kegagalan drift check images.json vs images',
            ['error' => $e->getMessage()]
        );
        echo 'CRITICAL: ' . $e->getMessage() . "\n";
        return 1;
    }
}

exit(drift_main($argv));
