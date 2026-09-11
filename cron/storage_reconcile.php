#!/usr/bin/env php
<?php
/**
 * PixelHop - cron/storage_reconcile.php
 *
 * Reliability R5: membandingkan metadata foto (tabel `images` di DB, sumber
 * kebenaran) dengan objek yang benar-benar ada di object storage
 * (R2 + Contabo S3) untuk menemukan:
 *
 *   - orphan_storage : objek di S3 yang TIDAK punya metadata (kandidat hapus)
 *   - orphan_metadata: metadata yang TIDAK punya objek di S3 (hanya dilaporkan,
 *                      TIDAK dihapus otomatis karena berisiko)
 *
 * Mode (default aman):
 *   php cron/storage_reconcile.php            # --report, hanya laporan
 *   php cron/storage_reconcile.php --report   # sama dengan di atas
 *   php cron/storage_reconcile.php --delete   # hapus orphan_storage > 7 hari
 *
 * Grace:
 *   - Objek yang key-nya terdaftar di pending_operations (upload/delete
 *     in-flight) dikecualikan dari orphan_storage agar tidak dihapus saat
 *     operasi masih berjalan.
 *   - orphan_metadata TIDAK PERNAH dihapus otomatis; hanya dilaporkan.
 *
 * Report JSON ditulis ke data/storage_reconcile_report.json via JsonStore.
 * Path bisa dioverride untuk pengujian dengan env STORAGE_RECONCILE_REPORT.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "storage_reconcile.php hanya bisa dijalankan dari CLI\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/includes/JsonStore.php';

// Guard class_exists memudahkan test harness menginjeksi mock (SQLite DB,
// mock Logger, mock R2StorageManager, mock LIST) tanpa menimpa produksi.
if (!class_exists('Logger', false)) {
    require_once ROOT_PATH . '/includes/Logger.php';
}

if (!class_exists('Database', false)) {
    require_once ROOT_PATH . '/includes/Database.php';
}

if (!class_exists('R2StorageManager', false)) {
    require_once ROOT_PATH . '/includes/R2StorageManager.php';
}

// Alerter bersifat opsional (butuh vendor/autoload + config/mail). Bila tidak
// tersedia, script tetap berjalan dan hanya memakai Logger.
$GLOBALS['storage_reconcile_alerter_available'] = false;
if (
    !class_exists('Alerter', false)
    && is_file(ROOT_PATH . '/includes/Alerter.php')
    && is_file(ROOT_PATH . '/vendor/autoload.php')
    && is_file(ROOT_PATH . '/config/mail.php')
) {
    require_once ROOT_PATH . '/includes/Alerter.php';
}
$GLOBALS['storage_reconcile_alerter_available'] = class_exists('Alerter', false);

/**
 * Ambang umur (detik) objek orphan_storage sebelum boleh dihapus mode --delete.
 */
define('STORAGE_RECONCILE_DELETE_AGE', 7 * 24 * 60 * 60);

/**
 * Muat konfigurasi S3. Prioritas:
 *   1. env STORAGE_RECONCILE_S3_CONFIG (path file PHP yang return array)
 *   2. <root>/config/s3.php bila ada
 *   3. [] (tidak ada konfigurasi -> listing dilaporkan error)
 */
function storage_reconcile_load_config(): array
{
    $path = getenv('STORAGE_RECONCILE_S3_CONFIG');

    if (!is_string($path) || $path === '') {
        $path = ROOT_PATH . '/config/s3.php';
    }

    if (is_file($path)) {
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    return [];
}

/**
 * Decode kolom JSON dari DB (bisa sudah array karena PDO/SQLite, atau string).
 */
function storage_reconcile_decode_json(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/**
 * Provider untuk satu varian, mengikuti routing R2StorageManager::determineStorage:
 * original/large -> contabo; thumb/medium -> r2 (bila R2 aktif), fallback contabo.
 * storage_providers (metadata aktual) dimenangkan bila tersedia.
 */
function storage_reconcile_route_provider(string $variant, array $storageProviders, bool $r2Enabled): string
{
    if (isset($storageProviders[$variant])) {
        $provider = strtolower(trim((string) $storageProviders[$variant]));
        if ($provider === 'r2' || $provider === 'contabo') {
            return $provider;
        }
    }

    if ($variant === 'original' || $variant === 'large') {
        return 'contabo';
    }

    if ($variant === 'thumb' || $variant === 'medium') {
        return $r2Enabled ? 'r2' : 'contabo';
    }

    return 'contabo';
}

/**
 * Parse timestamp LastModified S3 (ISO 8601). Return epoch atau null bila
 * kosong/tidak valid. Null artinya umur tidak diketahui -> TIDAK deletable.
 */
function storage_reconcile_parse_modified(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);
    return $ts === false ? null : $ts;
}

/**
 * Provider siap di-listing bila endpoint/bucket/access_key/secret_key lengkap.
 */
function storage_reconcile_provider_ready(array $config, string $provider): bool
{
    // Contabo disimpan di key config 's3' (sesuai config/s3.php), sedangkan
    // R2 di key 'r2'. Normalisasi di sini agar pemanggil bisa memakai nama
    // provider yang konsisten.
    if ($provider === 'contabo') {
        $provider = 's3';
    }

    $c = $config[$provider] ?? [];

    return !empty($c['endpoint'])
        && !empty($c['bucket'])
        && !empty($c['access_key'])
        && !empty($c['secret_key']);
}

/**
 * Ambil semua s3_keys dari tabel `images` (DB) dan kelompokkan per provider.
 *
 * @return array{by_provider: array<string, array<string, true>>, details: array<string, array<string, mixed>>}
 */
function storage_reconcile_fetch_metadata(array $config): array
{
    $rows = Database::fetchAll('SELECT id, s3_keys, storage_providers FROM images');

    $r2Enabled = !empty($config['r2']['enabled'])
        && !empty($config['r2']['access_key'])
        && !empty($config['r2']['bucket']);

    $byProvider = ['r2' => [], 'contabo' => []];
    $details = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $s3Keys = storage_reconcile_decode_json($row['s3_keys'] ?? null);
        $storageProviders = storage_reconcile_decode_json($row['storage_providers'] ?? null);
        $imageId = (string) ($row['id'] ?? '');

        foreach ($s3Keys as $variant => $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $variant = (string) $variant;
            $provider = storage_reconcile_route_provider($variant, $storageProviders, $r2Enabled);

            $byProvider[$provider][$key] = true;
            $details[$key] = [
                'key' => $key,
                'provider' => $provider,
                'variant' => $variant,
                'image_id' => $imageId,
            ];
        }
    }

    return ['by_provider' => $byProvider, 'details' => $details];
}

/**
 * Ambil key-key yang sedang dalam pengerjaan (upload/delete in-flight) dari
 * pending_operations. Key ini dikecualikan dari orphan_storage agar objek
 * yang baru diupload (metadata belum tersimpan) tidak ikut dihapus.
 *
 * @return array<int, string>
 */
function storage_reconcile_fetch_pending(): array
{
    try {
        $rows = Database::fetchAll(
            "SELECT payload FROM pending_operations WHERE state NOT IN ('completed','failed')"
        );
    } catch (Throwable $e) {
        Logger::warning('storage_reconcile', 'Gagal membaca pending_operations; grace nonaktif', [
            'error' => $e->getMessage(),
        ]);
        return [];
    }

    $keys = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $payload = storage_reconcile_decode_json($row['payload'] ?? null);

        if (isset($payload['s3_keys']) && is_array($payload['s3_keys'])) {
            foreach ($payload['s3_keys'] as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        } elseif (isset($payload['key'])) {
            $key = trim((string) $payload['key']);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * LIST objek dari satu bucket via SigV4 ListObjectsV2.
 *
 * Method publik R2StorageManager untuk LIST belum tersedia di subtask ini,
 * jadi logika SigV4 minimal diduplikasi dari admin/cleanup-orphans.php
 * (dengan tambahan pagination continuation-token).
 *
 * Return array{ok: bool, objects: array<int, array{key:string,size:int,last_modified:string}>, error: ?string}
 */
if (!function_exists('storage_reconcile_list_s3')) {
    function storage_reconcile_list_s3(array $providerConfig, string $provider): array
    {
        $endpoint = rtrim((string) ($providerConfig['endpoint'] ?? ''), '/');
        $bucket = (string) ($providerConfig['bucket'] ?? '');
        $accessKey = (string) ($providerConfig['access_key'] ?? '');
        $secretKey = (string) ($providerConfig['secret_key'] ?? '');
        $region = (string) ($providerConfig['region'] ?? ($provider === 'r2' ? 'auto' : 'default'));

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            return [
                'ok' => false,
                'objects' => [],
                'error' => "Missing S3 config for provider: {$provider}",
            ];
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return [
                'ok' => false,
                'objects' => [],
                'error' => "Invalid S3 endpoint for provider: {$provider}",
            ];
        }

        $objects = [];
        $continuationToken = null;
        $pages = 0;

        do {
            $pages++;

            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($continuationToken !== null && $continuationToken !== '') {
                $query['continuation-token'] = (string) $continuationToken;
            }

            ksort($query);
            $queryParts = [];
            foreach ($query as $name => $value) {
                $queryParts[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
            $canonicalQueryString = implode('&', $queryParts);

            $url = "{$endpoint}/{$bucket}?{$canonicalQueryString}";
            $canonicalUri = '/' . $bucket;

            $now = new DateTime('UTC');
            $longDate = $now->format('Ymd\THis\Z');
            $shortDate = $now->format('Ymd');

            $payloadHash = hash('sha256', '');

            $headers = [
                'host' => $host,
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $longDate,
            ];
            ksort($headers);

            $canonicalHeaders = '';
            $signedHeaders = [];
            foreach ($headers as $k => $v) {
                $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
                $signedHeaders[] = strtolower($k);
            }
            $signedHeadersStr = implode(';', $signedHeaders);

            $canonicalRequest = "GET\n"
                . $canonicalUri . "\n"
                . $canonicalQueryString . "\n"
                . $canonicalHeaders . "\n"
                . $signedHeadersStr . "\n"
                . $payloadHash;

            $algorithm = 'AWS4-HMAC-SHA256';
            $credentialScope = "{$shortDate}/{$region}/s3/aws4_request";
            $stringToSign = "{$algorithm}\n{$longDate}\n{$credentialScope}\n"
                . hash('sha256', $canonicalRequest);

            $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
            $kRegion = hash_hmac('sha256', $region, $kDate, true);
            $kService = hash_hmac('sha256', 's3', $kRegion, true);
            $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
            $signature = hash_hmac('sha256', $stringToSign, $kSigning);

            $authorization = "{$algorithm} Credential={$accessKey}/{$credentialScope}, "
                . "SignedHeaders={$signedHeadersStr}, Signature={$signature}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: {$authorization}",
                    "Host: {$host}",
                    "x-amz-content-sha256: {$payloadHash}",
                    "x-amz-date: {$longDate}",
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return [
                    'ok' => false,
                    'objects' => [],
                    'error' => "CURL error listing {$provider}: {$error}",
                ];
            }

            if ($httpCode !== 200) {
                return [
                    'ok' => false,
                    'objects' => [],
                    'error' => "HTTP {$httpCode} listing {$provider}: " . substr((string) $response, 0, 500),
                ];
            }

            $xml = function_exists('simplexml_load_string')
                ? @simplexml_load_string((string) $response)
                : false;

            if ($xml === false) {
                return [
                    'ok' => false,
                    'objects' => [],
                    'error' => "Gagal parse XML list {$provider}",
                ];
            }

            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $obj) {
                    $objects[] = [
                        'key' => (string) $obj->Key,
                        'size' => (int) $obj->Size,
                        'last_modified' => (string) $obj->LastModified,
                    ];
                }
            }

            $isTruncated = !empty($xml->IsTruncated) && (string) $xml->IsTruncated === 'true';
            $continuationToken = $isTruncated
                ? (string) ($xml->NextContinuationToken ?? '')
                : null;

            if ($pages >= 1000) {
                break;
            }
        } while ($continuationToken !== null && $continuationToken !== '');

        return ['ok' => true, 'objects' => $objects, 'error' => null];
    }
}

/**
 * Entry point.
 *
 * @param array<int, string> $argv
 */
function storage_reconcile_main(array $argv): int
{
    $deleteMode = in_array('--delete', $argv, true);
    $reportMode = in_array('--report', $argv, true);
    $mode = $deleteMode ? 'delete' : 'report';

    try {
        $config = storage_reconcile_load_config();

        // 1. Kumpulkan keys metadata (sumber kebenaran: tabel images DB).
        $metadata = storage_reconcile_fetch_metadata($config);
        $metadataByProvider = $metadata['by_provider'];
        $metadataDetails = $metadata['details'];
        $metadataKeyCount = count($metadataDetails);

        // 2. Grace: key di pending_operations (upload/delete in-flight).
        $pendingKeys = storage_reconcile_fetch_pending();
        $pendingSet = array_fill_keys($pendingKeys, true);

        // 3. LIST objek dari kedua bucket (R2 + Contabo).
        $listResults = [];
        $listErrors = [];
        $storageObjects = ['r2' => [], 'contabo' => []];

        if (!empty($config['r2']['enabled']) && storage_reconcile_provider_ready($config, 'r2')) {
            $listResults['r2'] = storage_reconcile_list_s3($config['r2'], 'r2');
        } elseif (empty($config['r2']['enabled'])) {
            // R2 memang nonaktif; bukan error, tetapi juga jangan dibandingkan
            // agar metadata r2 tidak dilaporkan sebagai orphan_metadata palsu.
            $listResults['r2'] = ['ok' => true, 'objects' => [], 'error' => null, 'skipped' => true];
        } else {
            $listResults['r2'] = [
                'ok' => false,
                'objects' => [],
                'error' => 'R2 enabled tetapi konfigurasi bucket belum lengkap',
            ];
        }

        if (storage_reconcile_provider_ready($config, 'contabo')) {
            $listResults['contabo'] = storage_reconcile_list_s3($config['s3'], 'contabo');
        } else {
            $listResults['contabo'] = [
                'ok' => false,
                'objects' => [],
                'error' => 'Konfigurasi Contabo S3 belum lengkap',
            ];
        }

        $providersCompared = [];
        foreach ($listResults as $provider => $result) {
            if (empty($result['ok'])) {
                $listErrors[] = [
                    'provider' => $provider,
                    'error' => $result['error'] ?? 'Listing gagal',
                ];
                continue;
            }

            if (!empty($result['skipped'])) {
                continue;
            }

            $providersCompared[$provider] = true;
            $storageObjects[$provider] = $result['objects'] ?? [];
        }

        // 4. Bandingkan per provider.
        $orphanStorage = [];
        $orphanMetadata = [];

        foreach (['r2', 'contabo'] as $provider) {
            // Provider yang listing-nya gagal/dilewati TIDAK dibandingkan:
            // jangan melaporkan metadata valid sebagai orphan hanya karena
            // LIST gagal atau R2 memang nonaktif.
            if (!isset($providersCompared[$provider])) {
                continue;
            }

            $metadataKeys = $metadataByProvider[$provider] ?? [];
            $objects = $storageObjects[$provider];
            $storageKeySet = [];

            foreach ($objects as $obj) {
                if (!is_array($obj)) {
                    continue;
                }

                $key = trim((string) ($obj['key'] ?? ''));
                if ($key === '') {
                    continue;
                }

                $storageKeySet[$key] = true;

                // Orphan storage = objek S3 tanpa metadata dan tidak sedang
                // dalam pengerjaan (grace pending_operations).
                if (!isset($metadataKeys[$key]) && !isset($pendingSet[$key])) {
                    $modified = (string) ($obj['last_modified'] ?? '');
                    $ts = storage_reconcile_parse_modified($modified);
                    $ageDays = $ts !== null
                        ? round((time() - $ts) / 86400, 3)
                        : null;
                    $deletable = $ts !== null && (time() - $ts) > STORAGE_RECONCILE_DELETE_AGE;

                    $orphanStorage[] = [
                        'key' => $key,
                        'provider' => $provider,
                        'size' => (int) ($obj['size'] ?? 0),
                        'last_modified' => $modified,
                        'age_days' => $ageDays,
                        'deletable' => $deletable,
                    ];
                }
            }

            foreach ($metadataKeys as $key => $unused) {
                if (!isset($storageKeySet[$key])) {
                    $orphanMetadata[] = $metadataDetails[$key] ?? [
                        'key' => $key,
                        'provider' => $provider,
                        'variant' => '',
                        'image_id' => '',
                    ];
                }
            }
        }

        $orphanStorageCount = count($orphanStorage);
        $orphanMetadataCount = count($orphanMetadata);

        // 5. Mode --delete: hanya orphan_storage berumur > 7 hari. Satu
        // try/catch per key; orphan_metadata TIDAK PERNAH dihapus.
        $deletedKeys = [];
        $deleteResults = [];
        $deletedCount = 0;

        if ($deleteMode && $orphanStorageCount > 0) {
            try {
                $storage = new R2StorageManager($config);

                foreach ($orphanStorage as $obj) {
                    if (empty($obj['deletable'])) {
                        continue;
                    }

                    $key = (string) $obj['key'];

                    try {
                        $result = $storage->deleteImage(['orphan' => $key]);
                        $success = is_array($result)
                            && (!empty($result['success']) || (int) ($result['deleted'] ?? 0) > 0);

                        $deleteResults[$key] = ['success' => $success, 'result' => $result];

                        if ($success) {
                            $deletedCount++;
                            $deletedKeys[] = $key;
                        }
                    } catch (Throwable $e) {
                        $deleteResults[$key] = ['success' => false, 'error' => $e->getMessage()];
                    }
                }
            } catch (Throwable $e) {
                $deleteResults['__manager_error__'] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $summary = [
            'metadata_keys' => $metadataKeyCount,
            'r2_keys' => count($storageObjects['r2']),
            'contabo_keys' => count($storageObjects['contabo']),
            'orphan_storage' => $orphanStorageCount,
            'orphan_metadata' => $orphanMetadataCount,
            'deleted' => $deletedCount,
            'pending_grace' => count($pendingKeys),
        ];

        $reportPath = getenv('STORAGE_RECONCILE_REPORT');
        if (!is_string($reportPath) || $reportPath === '') {
            $reportPath = ROOT_PATH . '/data/storage_reconcile_report.json';
        }

        $report = [
            'generated_at' => date('c'),
            'mode' => $mode,
            'summary' => $summary,
            'orphan_storage' => $orphanStorage,
            'orphan_metadata' => $orphanMetadata,
            'deleted' => $deletedKeys,
            'delete_results' => $deleteResults,
            'pending_grace_keys' => $pendingKeys,
            'list_errors' => $listErrors,
        ];

        (new JsonStore($reportPath))->write($report);

        // 6. Ringkasan console + Logger.
        echo "=== PixelHop Storage Reconcile ===\n";
        echo 'Mode: ' . strtoupper($mode) . "\n";
        echo 'metadata_keys : ' . $summary['metadata_keys'] . "\n";
        echo 'r2_keys       : ' . $summary['r2_keys'] . "\n";
        echo 'contabo_keys  : ' . $summary['contabo_keys'] . "\n";
        echo 'orphan_storage: ' . $summary['orphan_storage'] . "\n";
        echo 'orphan_metadata: ' . $summary['orphan_metadata'] . "\n";
        echo 'pending_grace : ' . $summary['pending_grace'] . "\n";
        echo 'deleted       : ' . $summary['deleted'] . "\n";

        if ($orphanStorageCount > 0) {
            echo "Orphan storage:\n";
            foreach ($orphanStorage as $obj) {
                echo '  - ' . $obj['key']
                    . ' [' . $obj['provider'] . ']'
                    . ' age=' . ($obj['age_days'] === null ? 'unknown' : $obj['age_days'] . 'd')
                    . ($obj['deletable'] ? ' (deletable)' : '') . "\n";
            }
        }

        if ($orphanMetadataCount > 0) {
            echo "Orphan metadata (TIDAK dihapus otomatis):\n";
            foreach ($orphanMetadata as $obj) {
                echo '  - ' . $obj['key']
                    . ' [' . $obj['provider'] . ']'
                    . ' image_id=' . $obj['image_id'] . "\n";
            }
        }

        echo 'Report: ' . $reportPath . "\n";

        Logger::info(
            'storage_reconcile',
            $deleteMode ? 'Storage reconcile delete selesai' : 'Storage reconcile report selesai',
            $summary
        );

        if ($listErrors !== []) {
            Logger::error('storage_reconcile', 'Beberapa bucket gagal di-list', [
                'errors' => $listErrors,
            ]);
        }

        // 7. Alert bila orphan_storage melebihi ambang (kemungkinan kebocoran).
        $threshold = (int) (getenv('STORAGE_RECONCILE_ORPHAN_ALERT_THRESHOLD') ?: 20);
        if ($orphanStorageCount > $threshold) {
            if (!empty($GLOBALS['storage_reconcile_alerter_available']) && class_exists('Alerter', false)) {
                try {
                    Alerter::critical(
                        'storage_reconcile',
                        'Orphan storage melebihi ambang, kemungkinan kebocoran object storage',
                        $summary
                    );
                } catch (Throwable $alertError) {
                    Logger::error('storage_reconcile', 'Gagal mengirim Alerter::critical', [
                        'error' => $alertError->getMessage(),
                    ]);
                }
            } else {
                Logger::error('storage_reconcile', 'Orphan storage melebihi ambang, Alerter tidak tersedia', $summary);
            }
        }

        return $listErrors === [] ? 0 : 1;
    } catch (Throwable $e) {
        Logger::error('storage_reconcile', 'Kegagalan storage reconcile: ' . $e->getMessage());
        echo 'CRITICAL: ' . $e->getMessage() . "\n";
        return 1;
    }
}

exit(storage_reconcile_main($argv));
