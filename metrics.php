<?php
/**
 * PixelHop - Metrics Endpoint
 *
 * Endpoint teks bergaya Prometheus (text exposition format 0.0.4) yang
 * mengekspos counter kunci sistem dari sumber nyata (DB + file log/report).
 * Dirancang ringan seperti health.php: tanpa session/auth layer, tanpa HTML,
 * dan tanpa pernah mencetak path/credential/versi.
 *
 * Keamanan (TIDAK publik):
 *   - Bila env METRICS_TOKEN diset  -> wajib ?token=<METRICS_TOKEN> dan
 *     dibandingkan dengan hash_equals() (tahan timing attack).
 *   - Bila METRICS_TOKEN tidak diset -> hanya IP loopback (127.0.0.1/::1)
 *     atau IP yang terdaftar di env METRICS_ALLOW_IPS (daftar dipisah koma).
 *   - Selain itu -> HTTP 403.
 *
 * Output : Content-Type: text/plain; version=0.0.4, Cache-Control: no-store.
 * Down   : kegagalan DB -> "pixelhop_up 0" + HTTP 503.
 *
 * Env opsional (khusus operasional/testing):
 *   METRICS_LOG_DIR          Direktori log jsonl (default data/logs).
 *   METRICS_RECONCILE_REPORT Path report reconcile (default
 *                            data/storage_reconcile_report.json).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

/**
 * Resolve IP klien. Memakai ClientIp (sadar trusted-proxy) bila tersedia,
 * dengan fallback ke REMOTE_ADDR.
 */
function metrics_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    $clientIpFile = __DIR__ . '/includes/ClientIp.php';
    if (is_file($clientIpFile)) {
        try {
            require_once $clientIpFile;
            if (class_exists('ClientIp', false)) {
                $ip = ClientIp::get();
                if (is_string($ip) && $ip !== '') {
                    return $ip;
                }
            }
        } catch (Throwable $e) {
            // Jatuh ke REMOTE_ADDR di bawah.
        }
    }

    return is_string($remote) ? $remote : '';
}

/**
 * True hanya untuk alamat loopback yang diizinkan tanpa token.
 */
function metrics_is_loopback(string $ip): bool
{
    return $ip === '127.0.0.1' || $ip === '::1';
}

/**
 * Apakah request ini boleh mengakses endpoint metrik.
 *
 * Token (bila dikonfigurasi) selalu menang: request tanpa token valid ditolak
 * walaupun berasal dari loopback. Tanpa token, akses hanya via loopback atau
 * allowlist IP eksplisit.
 */
function metrics_authorized(): bool
{
    $token = getenv('METRICS_TOKEN');
    if (is_string($token) && $token !== '') {
        $provided = $_GET['token'] ?? '';
        return is_string($provided)
            && $provided !== ''
            && hash_equals($token, $provided);
    }

    $ip = metrics_client_ip();

    if (metrics_is_loopback($ip)) {
        return true;
    }

    $allow = getenv('METRICS_ALLOW_IPS');
    if (is_string($allow) && trim($allow) !== '') {
        foreach (explode(',', $allow) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && $entry === $ip) {
                return true;
            }
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// Response + formatting helpers
// ---------------------------------------------------------------------------

/**
 * Kirim respons akhir dan berhenti.
 */
function metrics_respond(string $body, int $httpCode): never
{
    http_response_code($httpCode);
    header('Content-Type: text/plain; version=0.0.4');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

/**
 * Escape nilai label sesuai aturan text exposition Prometheus.
 */
function metrics_escape_label(string $value): string
{
    return str_replace(
        ['\\', "\n", '"'],
        ['\\\\', '\\n', '\\"'],
        $value
    );
}

/**
 * Format satu baris sample (metric + optional label set + nilai).
 *
 * @param array<string,string> $labels
 */
function metrics_sample(string $name, int|float|string $value, array $labels = []): string
{
    if ($labels === []) {
        return $name . ' ' . $value . "\n";
    }

    $pairs = [];
    foreach ($labels as $key => $val) {
        $pairs[] = $key . '="' . metrics_escape_label((string) $val) . '"';
    }

    return $name . '{' . implode(',', $pairs) . '} ' . $value . "\n";
}

// ---------------------------------------------------------------------------
// Collectors
// ---------------------------------------------------------------------------

/**
 * Metrik yang berasal dari DB. Melempar Throwable bila DB bermasalah.
 *
 * Semua query memakai prepared statement (via Database) dan portable antara
 * MySQL (produksi) dan SQLite (mock test).
 */
function metrics_collect_db(): string
{
    // Redam warning engine dari helper internal (mis. config/database.php
    // tidak ada di checkout lokal) agar TIDAK ada path/DSN yang bocor ke
    // respons. PDO exception tetap dilempar dan ditangani pemanggil.
    set_error_handler(static function (): bool {
        return true;
    });

    try {
        require_once __DIR__ . '/includes/Database.php';

        return metrics_collect_db_queries();
    } finally {
        restore_error_handler();
    }
}

/**
 * Jalankan seluruh query DB metrik. Dipisah agar error handler dapat
 * dipasang/dilepas dengan rapi di metrics_collect_db().
 */
function metrics_collect_db_queries(): string
{
    $out = [];

    // -- images per tipe akun (guest = user_id NULL, member = sebaliknya) ----
    $byType = ['guest' => 0, 'member' => 0];
    $rows = Database::fetchAll(
        "SELECT CASE WHEN user_id IS NULL THEN 'guest' ELSE 'member' END AS account_type,
                COUNT(*) AS total
           FROM images
          GROUP BY account_type"
    );
    foreach ($rows as $row) {
        $type = (string) ($row['account_type'] ?? '');
        if (array_key_exists($type, $byType)) {
            $byType[$type] = (int) ($row['total'] ?? 0);
        }
    }

    $out[] = "# HELP pixelhop_images_total Jumlah image tersimpan per tipe akun.\n";
    $out[] = "# TYPE pixelhop_images_total gauge\n";
    $out[] = metrics_sample('pixelhop_images_total', $byType['guest'], ['account_type' => 'guest']);
    $out[] = metrics_sample('pixelhop_images_total', $byType['member'], ['account_type' => 'member']);

    // -- total byte storage ------------------------------------------------
    $row = Database::fetchOne('SELECT COALESCE(SUM(size), 0) AS total FROM images');
    $out[] = "# HELP pixelhop_storage_bytes_total Total byte seluruh image.\n";
    $out[] = "# TYPE pixelhop_storage_bytes_total gauge\n";
    $out[] = metrics_sample('pixelhop_storage_bytes_total', (int) ($row['total'] ?? 0));

    // -- upload hari ini (images.created_at = epoch INT) -------------------
    $todayEpoch = (int) strtotime('today');
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS total FROM images WHERE created_at >= ?',
        [$todayEpoch]
    );
    $out[] = "# HELP pixelhop_uploads_today_total Jumlah image yang di-upload hari ini.\n";
    $out[] = "# TYPE pixelhop_uploads_today_total gauge\n";
    $out[] = metrics_sample('pixelhop_uploads_today_total', (int) ($row['total'] ?? 0));

    // -- total user --------------------------------------------------------
    $row = Database::fetchOne('SELECT COUNT(*) AS total FROM users');
    $out[] = "# HELP pixelhop_users_total Jumlah user terdaftar.\n";
    $out[] = "# TYPE pixelhop_users_total gauge\n";
    $out[] = metrics_sample('pixelhop_users_total', (int) ($row['total'] ?? 0));

    // -- pending operations per state --------------------------------------
    $states = [];
    $rows = Database::fetchAll(
        'SELECT state, COUNT(*) AS total FROM pending_operations GROUP BY state'
    );
    foreach ($rows as $row) {
        $state = (string) ($row['state'] ?? '');
        if ($state !== '') {
            $states[$state] = (int) ($row['total'] ?? 0);
        }
    }

    $out[] = "# HELP pixelhop_pending_operations_total Operasi pending per state.\n";
    $out[] = "# TYPE pixelhop_pending_operations_total gauge\n";
    foreach ($states as $state => $total) {
        $out[] = metrics_sample('pixelhop_pending_operations_total', $total, ['state' => $state]);
    }

    // -- AI usage hari ini (usage_logs.created_at = DATETIME) --------------
    $todayDatetime = date('Y-m-d 00:00:00');
    $tools = [];
    $rows = Database::fetchAll(
        'SELECT tool_name, COUNT(*) AS total
           FROM usage_logs
          WHERE created_at >= ?
          GROUP BY tool_name',
        [$todayDatetime]
    );
    foreach ($rows as $row) {
        $tool = (string) ($row['tool_name'] ?? '');
        if ($tool !== '') {
            $tools[$tool] = (int) ($row['total'] ?? 0);
        }
    }

    $out[] = "# HELP pixelhop_ai_usage_today_total Pemakaian tool AI hari ini.\n";
    $out[] = "# TYPE pixelhop_ai_usage_today_total gauge\n";
    foreach ($tools as $tool => $total) {
        $out[] = metrics_sample('pixelhop_ai_usage_today_total', $total, ['tool' => $tool]);
    }

    return implode('', $out);
}

/**
 * Direktori log jsonl yang dipakai (env override untuk testing).
 */
function metrics_log_dir(): string
{
    $dir = getenv('METRICS_LOG_DIR');
    if (is_string($dir) && $dir !== '') {
        return rtrim($dir, '/');
    }

    return __DIR__ . '/data/logs';
}

/**
 * Hitung entri log hari ini per level 'error'/'warning'.
 *
 * Membaca app-YYYY-MM-DD.jsonl termasuk file rotasi app-YYYY-MM-DD.N.jsonl.
 * Baris yang bukan JSON valid atau level tak dikenal diabaikan.
 *
 * @return array{error:int,warning:int}
 */
function metrics_count_log_levels(string $logDir): array
{
    $counts = ['error' => 0, 'warning' => 0];

    $files = glob($logDir . '/app-' . date('Y-m-d') . '*.jsonl');
    if (!is_array($files)) {
        return $counts;
    }

    foreach ($files as $file) {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            continue;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }

            $level = $entry['level'] ?? null;
            if (is_string($level) && array_key_exists($level, $counts)) {
                $counts[$level]++;
            }
        }

        fclose($handle);
    }

    return $counts;
}

/**
 * Path report reconcile (env override untuk testing).
 */
function metrics_reconcile_report_path(): string
{
    $path = getenv('METRICS_RECONCILE_REPORT');
    if (is_string($path) && $path !== '') {
        return $path;
    }

    return __DIR__ . '/data/storage_reconcile_report.json';
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

if (!metrics_authorized()) {
    metrics_respond("forbidden\n", 403);
}

$body = '';

try {
    $body = metrics_collect_db();
} catch (Throwable $e) {
    // Jangan pernah bocorkan detail DB ke klien.
    error_log('metrics.php: database metrics failed');
    metrics_respond(
        "# HELP pixelhop_up 1 bila endpoint sehat, 0 bila tidak.\n"
        . "# TYPE pixelhop_up gauge\n"
        . "pixelhop_up 0\n",
        503
    );
}

// -- error/warning hari ini dari log jsonl (best-effort) --------------------
$logCounts = metrics_count_log_levels(metrics_log_dir());
$body .= "# HELP pixelhop_errors_today_total Jumlah entri log error/warning hari ini.\n";
$body .= "# TYPE pixelhop_errors_today_total gauge\n";
$body .= metrics_sample('pixelhop_errors_today_total', $logCounts['error'], ['level' => 'error']);
$body .= metrics_sample('pixelhop_errors_today_total', $logCounts['warning'], ['level' => 'warning']);

// -- orphan storage/metadata dari report reconcile terbaru (bila ada) -------
$reportPath = metrics_reconcile_report_path();
if (is_file($reportPath)) {
    try {
        require_once __DIR__ . '/includes/JsonStore.php';
        $report = (new JsonStore($reportPath))->read();
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        $body .= "# HELP pixelhop_orphan_storage_total Objek storage tanpa metadata (report reconcile terbaru).\n";
        $body .= "# TYPE pixelhop_orphan_storage_total gauge\n";
        $body .= metrics_sample('pixelhop_orphan_storage_total', (int) ($summary['orphan_storage'] ?? 0));

        $body .= "# HELP pixelhop_orphan_metadata_total Metadata tanpa objek storage (report reconcile terbaru).\n";
        $body .= "# TYPE pixelhop_orphan_metadata_total gauge\n";
        $body .= metrics_sample('pixelhop_orphan_metadata_total', (int) ($summary['orphan_metadata'] ?? 0));
    } catch (Throwable $e) {
        error_log('metrics.php: reconcile report read failed');
    }
}

// -- health check konstanta -------------------------------------------------
$body .= "# HELP pixelhop_up 1 bila endpoint sehat, 0 bila tidak.\n";
$body .= "# TYPE pixelhop_up gauge\n";
$body .= "pixelhop_up 1\n";

metrics_respond($body, 200);
