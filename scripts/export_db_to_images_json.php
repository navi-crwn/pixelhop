#!/usr/bin/env php
<?php
/**
 * PixelHop - scripts/export_db_to_images_json.php
 *
 * Rollback pasca-cutover `db_only`: ekspor SELURUH isi tabel `images` (DB)
 * kembali ke data/images.json (format JSON lama yang kompatibel dengan
 * mode `json` dan kode fallback).
 *
 * Mode argumen:
 *   --output=PATH  path file JSON tujuan (default: data/images.json)
 *   --dry-run      cetak ringkasan tanpa menulis apa pun
 *   --limit=N      ekspor maksimal N baris pertama
 *
 * Properti:
 *   - Aman           : backup file JSON lama via JsonStore->backup()
 *                      sebelum menimpa, lalu tulis via JsonStore->write()
 *                      (atomic: temp file + rename di dalam flock).
 *   - Format legacy  : key array = id; timestamp epoch tetap INT.
 *   - Kolom JSON     : urls, s3_keys, storage_providers, last_delete_error
 *                      di-decode kembali menjadi array/objek.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "export_db_to_images_json.php hanya bisa dijalankan dari CLI\n");
    exit(1);
}

require_once __DIR__ . '/../includes/JsonStore.php';
require_once __DIR__ . '/../includes/Logger.php';

// Guard class_exists memungkinkan test harness menginjeksi mock Database
// (mis. SQLite in-memory) tanpa menimpa Database produksi.
if (!class_exists('Database', false)) {
    require_once __DIR__ . '/../includes/Database.php';
}

/**
 * Parse $argv.
 *
 * @return array{output: string, dry_run: bool, limit: int}
 */
function export_images_parse_args(array $argv): array
{
    $output = __DIR__ . '/../data/images.json';
    $dryRun = false;
    $limit = 0;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        } elseif (preg_match('/^--output=(.+)$/', $arg, $m) === 1) {
            if ($m[1] !== '') {
                $output = $m[1];
            }
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
            $limit = max(1, (int) $m[1]);
        }
    }

    return ['output' => $output, 'dry_run' => $dryRun, 'limit' => $limit];
}

/**
 * Decode satu kolom JSON dari DB. Meniru ImageRepository::decodeDbRow():
 * string JSON valid -> array/objek; null -> null; selain itu dibiarkan.
 *
 * $decodedCount di-increment setiap kali sebuah string berhasil di-decode,
 * untuk ringkasan "jumlah kolom JSON yang di-decode".
 */
function export_images_decode_json_column(mixed $value, int &$decodedCount): mixed
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decodedCount++;
            return $decoded;
        }
    }

    return $value;
}

/**
 * Konversi nilai epoch ke int|null (nilai kosong dianggap null).
 */
function export_images_epoch_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}

/**
 * Petakan satu baris DB ke format record JSON lama.
 *
 * Key array = id; id TIDAK disertakan sebagai field di dalam record.
 *
 * @return array<string, mixed>
 */
function export_images_map_row(array $row, int &$decodedColumns): array
{
    $userId = $row['user_id'] ?? null;
    if ($userId === null || $userId === '') {
        $userId = null;
    } else {
        $userId = (int) $userId;
    }

    return [
        'user_id'             => $userId,
        'ip'                  => (string) ($row['ip'] ?? ''),
        'filename'            => (string) ($row['filename'] ?? ''),
        'mime_type'           => (string) ($row['mime_type'] ?? ''),
        'extension'           => (string) ($row['extension'] ?? ''),
        'size'                => (int) ($row['size'] ?? 0),
        'width'               => (int) ($row['width'] ?? 0),
        'height'              => (int) ($row['height'] ?? 0),
        'hash'                => (string) ($row['hash'] ?? ''),
        'urls'                => export_images_decode_json_column($row['urls'] ?? null, $decodedColumns),
        's3_keys'             => export_images_decode_json_column($row['s3_keys'] ?? null, $decodedColumns),
        'storage_providers'   => export_images_decode_json_column($row['storage_providers'] ?? null, $decodedColumns),
        'view_count'          => (int) ($row['view_count'] ?? 0),
        'last_viewed_at'      => export_images_epoch_or_null($row['last_viewed_at'] ?? null),
        'delete_at'           => export_images_epoch_or_null($row['delete_at'] ?? null),
        'marked_for_deletion' => export_images_epoch_or_null($row['marked_for_deletion'] ?? null),
        'deleting_at'         => export_images_epoch_or_null($row['deleting_at'] ?? null),
        'last_delete_error'   => export_images_decode_json_column($row['last_delete_error'] ?? null, $decodedColumns),
        'created_at'          => (int) ($row['created_at'] ?? 0),
    ];
}

/**
 * Cetak ringkasan ekspor.
 */
function export_images_print_summary(int $recordCount, int $decodedColumns, string $outputPath, int $fileSize): void
{
    echo "Jumlah record diekspor      : {$recordCount}\n";
    echo "Jumlah kolom JSON di-decode : {$decodedColumns}\n";
    echo "Path output                 : {$outputPath}\n";
    echo "Ukuran file                 : {$fileSize}\n";
}

/**
 * Entry point.
 */
function export_images_main(array $argv): int
{
    $opts = export_images_parse_args($argv);

    try {
        $rows = Database::fetchAll('SELECT * FROM `images`');

        if ($opts['limit'] > 0) {
            $rows = array_slice($rows, 0, $opts['limit']);
        }

        $data = [];
        $decodedColumns = 0;

        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('id', $row)) {
                continue;
            }

            $id = (string) $row['id'];
            if ($id === '') {
                continue;
            }

            $data[$id] = export_images_map_row($row, $decodedColumns);
        }

        $jsonStore = new JsonStore($opts['output']);

        if ($opts['dry_run']) {
            echo "Dry-run ekspor images (DB) -> JSON\n";
            export_images_print_summary(count($data), $decodedColumns, $opts['output'], 0);
            echo "Dry-run selesai (tidak ada yang ditulis).\n";
            return 0;
        }

        // Backup file lama dulu sebelum menimpa.
        $backupPath = $jsonStore->backup();
        Logger::info(
            'export_images',
            'Backup images.json selesai',
            ['backup' => $backupPath, 'output' => $opts['output']]
        );
        echo 'Backup file lama: ' . ($backupPath ?? '(file tidak ada, dilewati)') . "\n";

        // Tulis atomik via JsonStore->write().
        $jsonStore->write($data);

        $fileSize = @filesize($opts['output']);
        if ($fileSize === false) {
            $fileSize = 0;
        }

        Logger::info(
            'export_images',
            'Ekspor DB ke JSON selesai',
            [
                'records'         => count($data),
                'decoded_columns' => $decodedColumns,
                'output'          => $opts['output'],
                'file_size'       => $fileSize,
            ]
        );

        echo "Ekspor selesai.\n";
        export_images_print_summary(count($data), $decodedColumns, $opts['output'], $fileSize);

        return 0;
    } catch (Throwable $e) {
        Logger::error(
            'export_images',
            'Kegagalan kritis ekspor DB ke JSON',
            ['error' => $e->getMessage(), 'output' => $opts['output']]
        );
        echo 'CRITICAL: ' . $e->getMessage() . "\n";
        return 1;
    }
}

exit(export_images_main($argv));
