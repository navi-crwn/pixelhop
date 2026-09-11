#!/usr/bin/env php
<?php
/**
 * PixelHop - cron/daily_summary.php
 *
 * Observability O4: kirim email ringkasan harian ke admin setiap malam
 * (crontab 23:55). Email berisi metrik operasional sehari terakhir:
 *
 *   - uploads_today     : jumlah & total bytes gambar baru hari ini
 *                         (tabel `images`, created_at >= epoch tengah malam)
 *   - storage_used      : SUM(size) seluruh `images` + SUM(total_bytes)
 *                         `storage_stats` (bila ada) + SUM(users.storage_used)
 *   - images_total      : COUNT(*) images + guest_count / member_count
 *   - ai_usage_today    : GROUP BY tool_name dari `usage_logs`
 *                         WHERE DATE(created_at) = CURDATE()
 *   - errors_today      : parse data/logs/app-YYYY-MM-DD.jsonl hari ini,
 *                         dihitung per level (error/warning) dan per channel
 *   - alerts_today      : dari file log yang sama, baris channel
 *                         alert/alerter level error (kegagalan jalur alert).
 *                         Sumber: Logger::error('alert', ...) di Alerter.
 *   - pending_operations: baris `pending_operations` state != 'completed'
 *                         (termasuk failed; masalah potensial)
 *   - orphan_storage / orphan_metadata:
 *                         dari data/storage_reconcile_report.json terbaru
 *                         (bila belum ada laporan -> "belum ada laporan")
 *
 * Idempotent: script TIDAK menyimpan state apa pun; aman dijalankan
 * berulang-ulang pada hari yang sama.
 *
 * Kegagalan SMTP tidak menggagalkan cron: error dicatat via Logger dan
 * exit code tetap 0. Kegagalan agregasi data (DB/log) dianggap fatal
 * (exit 1) karena email ringkasan tidak mungkin dibangun.
 *
 * Run:
 *   55 23 * * * php /path/to/pixelhop/cron/daily_summary.php \
 *     >> /var/log/pixelhop/daily_summary.log 2>&1
 *
 * Environment override (opsional, untuk pengujian/operasional):
 *   DAILY_SUMMARY_DATE          "Y-m-d" tanggal laporan (default: hari ini)
 *   DAILY_SUMMARY_LOG_DIR       direktori log jsonl (default: <root>/data/logs)
 *   STORAGE_RECONCILE_REPORT    path report reconcile (default: <root>/data/storage_reconcile_report.json)
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "daily_summary.php hanya bisa dijalankan dari CLI\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

// Guard class_exists memudahkan test harness menginjeksi mock
// (mis. SQLite in-memory, mock Logger, mock Mailer) tanpa menimpa produksi.
if (!class_exists('Logger', false)) {
    require_once ROOT_PATH . '/includes/Logger.php';
}

if (!class_exists('Database', false)) {
    require_once ROOT_PATH . '/includes/Database.php';
}

if (!class_exists('JsonStore', false)) {
    require_once ROOT_PATH . '/includes/JsonStore.php';
}

/**
 * Mailer dimuat best-effort. Bila vendor/autoload.php (PHPMailer) atau
 * config/mail.php tidak tersedia (mis. lingkungan uji lokal), script tetap
 * berjalan: metrik tetap dihitung dan dicatat, hanya langkah kirim email
 * yang dilewati dengan log error (exit 0, tidak menggagalkan cron).
 */
if (!class_exists('Mailer', false)) {
    if (
        is_file(ROOT_PATH . '/includes/Mailer.php')
        && is_file(ROOT_PATH . '/vendor/autoload.php')
        && is_file(ROOT_PATH . '/config/mail.php')
    ) {
        require_once ROOT_PATH . '/includes/Mailer.php';
    }
}

/**
 * Tanggal laporan. Default: hari ini (mengikuti timezone PHP, konsisten
 * dengan penamaan file log Logger). Override DAILY_SUMMARY_DATE untuk
 * pengujian/backfill manual.
 */
function daily_summary_today_date(): string
{
    $date = getenv('DAILY_SUMMARY_DATE');

    if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        return $date;
    }

    return date('Y-m-d');
}

/**
 * Epoch tengah malam (awal hari) untuk tanggal Y-m-d.
 */
function daily_summary_midnight_epoch(string $date): int
{
    $ts = strtotime($date . ' 00:00:00');
    return $ts === false ? 0 : $ts;
}

/**
 * Epoch tengah malam hari berikutnya.
 */
function daily_summary_next_midnight_epoch(string $date): int
{
    $ts = strtotime($date . ' 00:00:00 +1 day');
    return $ts === false ? 0 : $ts;
}

/**
 * Ambil satu nilai integer dari hasil query (best-effort, default 0).
 */
function daily_summary_scalar(string $sql, array $params = []): int
{
    try {
        $row = Database::fetchOne($sql, $params);
        if (!is_array($row)) {
            return 0;
        }

        $value = reset($row);
        return is_numeric($value) ? (int) $value : 0;
    } catch (Throwable $e) {
        Logger::warning('daily_summary', 'Query skalar gagal (dianggap 0)', [
            'error' => $e->getMessage(),
        ]);
        return 0;
    }
}

/**
 * Kumpulkan seluruh metrik database.
 *
 * @return array<string, mixed>
 */
function daily_summary_fetch_db_metrics(string $date): array
{
    $midnight = daily_summary_midnight_epoch($date);

    // Upload hari ini dari tabel images (created_at epoch INT).
    $uploadsToday = Database::fetchOne(
        'SELECT COUNT(*) AS cnt, COALESCE(SUM(size), 0) AS bytes
           FROM images
          WHERE created_at >= ?',
        [$midnight]
    );

    $uploadsCount = is_array($uploadsToday) ? (int) ($uploadsToday['cnt'] ?? 0) : 0;
    $uploadsBytes = is_array($uploadsToday) ? (int) ($uploadsToday['bytes'] ?? 0) : 0;

    // Total storage terpakai:
    // SUM(size) images + SUM(total_bytes) storage_stats + SUM(users.storage_used).
    $imagesBytes = daily_summary_scalar('SELECT COALESCE(SUM(size), 0) FROM images');
    $storageStatsBytes = daily_summary_scalar('SELECT COALESCE(SUM(total_bytes), 0) FROM storage_stats');
    $usersStorageBytes = daily_summary_scalar('SELECT COALESCE(SUM(storage_used), 0) FROM users');
    $storageUsed = $imagesBytes + $storageStatsBytes + $usersStorageBytes;

    // Total gambar + guest/member.
    $imagesTotal = daily_summary_scalar('SELECT COUNT(*) FROM images');

    $guestMember = Database::fetchOne(
        'SELECT
            COALESCE(SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END), 0) AS guest_count,
            COALESCE(SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS member_count
           FROM images'
    );

    $guestCount = is_array($guestMember) ? (int) ($guestMember['guest_count'] ?? 0) : 0;
    $memberCount = is_array($guestMember) ? (int) ($guestMember['member_count'] ?? 0) : 0;

    // AI usage hari ini. DATE(created_at) = CURDATE() adalah pola produksi;
    // pada SQLite uji, CURDATE() diregistrasikan oleh test harness.
    $aiUsage = [];
    try {
        $aiUsage = Database::fetchAll(
            'SELECT
                tool_name,
                COUNT(*) AS cnt,
                COALESCE(SUM(file_size), 0) AS bytes,
                COALESCE(SUM(processing_time_ms), 0) AS processing_ms,
                COALESCE(SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END), 0) AS failed
               FROM usage_logs
              WHERE DATE(created_at) = CURDATE()
              GROUP BY tool_name
              ORDER BY cnt DESC, tool_name ASC'
        );
        if (!is_array($aiUsage)) {
            $aiUsage = [];
        }
    } catch (Throwable $e) {
        Logger::warning('daily_summary', 'Gagal membaca usage_logs (dianggap kosong)', [
            'error' => $e->getMessage(),
        ]);
    }

    // Pending operations: semua yang belum completed (masalah potensial).
    $pendingOperations = daily_summary_scalar(
        "SELECT COUNT(*) FROM pending_operations WHERE state != 'completed'"
    );

    return [
        'uploads_today' => $uploadsCount,
        'uploads_bytes_today' => $uploadsBytes,
        'storage_used' => $storageUsed,
        'storage_images_bytes' => $imagesBytes,
        'storage_stats_bytes' => $storageStatsBytes,
        'storage_users_bytes' => $usersStorageBytes,
        'images_total' => $imagesTotal,
        'guest_count' => $guestCount,
        'member_count' => $memberCount,
        'ai_usage' => $aiUsage,
        'pending_operations' => $pendingOperations,
    ];
}

/**
 * Parse file log jsonl hari ini.
 *
 * Sumber errors/alerts: data/logs/app-YYYY-MM-DD.jsonl (satu JSON per baris).
 * File tidak ada dianggap nol (bukan error).
 *
 * @return array{errors_today:int,warnings_today:int,channels:array<string,array{error:int,warning:int}>,alerts_today:int}
 */
function daily_summary_parse_logs(string $date): array
{
    $logDir = getenv('DAILY_SUMMARY_LOG_DIR');
    if (!is_string($logDir) || $logDir === '') {
        $logDir = ROOT_PATH . '/data/logs';
    }

    $logFile = rtrim($logDir, '/') . '/app-' . $date . '.jsonl';

    $errors = 0;
    $warnings = 0;
    $channels = [];
    $alerts = 0;

    // Sumber alerts_today: baris log level error pada channel sistem alert
    // (Logger::error('alert', ...) / channel 'alerter'). Ini menangkap
    // kegagalan jalur alert (mis. Mailer gagal kirim alert), bukan event
    // kritis asli yang sudah masuk hitungan errors_today per channel-nya.
    $alertChannels = ['alert', 'alerter'];

    if (!is_file($logFile)) {
        return [
            'errors_today' => 0,
            'warnings_today' => 0,
            'channels' => [],
            'alerts_today' => 0,
        ];
    }

    $fp = @fopen($logFile, 'rb');
    if ($fp === false) {
        Logger::warning('daily_summary', 'File log tidak bisa dibuka', ['file' => $logFile]);
        return [
            'errors_today' => 0,
            'warnings_today' => 0,
            'channels' => [],
            'alerts_today' => 0,
        ];
    }

    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            continue;
        }

        $level = (string) ($entry['level'] ?? '');
        $channel = (string) ($entry['channel'] ?? 'unknown');

        if ($level !== 'error' && $level !== 'warning') {
            continue;
        }

        if (!isset($channels[$channel])) {
            $channels[$channel] = ['error' => 0, 'warning' => 0];
        }

        if ($level === 'error') {
            $errors++;
            $channels[$channel]['error']++;

            if (in_array(strtolower($channel), $alertChannels, true)) {
                $alerts++;
            }
        } else {
            $warnings++;
            $channels[$channel]['warning']++;
        }
    }

    fclose($fp);

    ksort($channels);

    return [
        'errors_today' => $errors,
        'warnings_today' => $warnings,
        'channels' => $channels,
        'alerts_today' => $alerts,
    ];
}

/**
 * Baca laporan storage reconcile terbaru (bila ada).
 *
 * @return array{available:bool,generated_at:?string,orphan_storage:?int,orphan_metadata:?int}
 */
function daily_summary_read_reconcile_report(): array
{
    $reportPath = getenv('STORAGE_RECONCILE_REPORT');
    if (!is_string($reportPath) || $reportPath === '') {
        $reportPath = ROOT_PATH . '/data/storage_reconcile_report.json';
    }

    if (!is_file($reportPath)) {
        return [
            'available' => false,
            'generated_at' => null,
            'orphan_storage' => null,
            'orphan_metadata' => null,
        ];
    }

    $report = (new JsonStore($reportPath))->read();

    if (!is_array($report)) {
        return [
            'available' => false,
            'generated_at' => null,
            'orphan_storage' => null,
            'orphan_metadata' => null,
        ];
    }

    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

    return [
        'available' => true,
        'generated_at' => isset($report['generated_at']) ? (string) $report['generated_at'] : null,
        'orphan_storage' => isset($summary['orphan_storage']) ? (int) $summary['orphan_storage'] : null,
        'orphan_metadata' => isset($summary['orphan_metadata']) ? (int) $summary['orphan_metadata'] : null,
    ];
}

/**
 * Format byte menjadi satuan yang mudah dibaca.
 */
function daily_summary_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float) $bytes;
    $unit = 'B';

    foreach ($units as $candidate) {
        $value /= 1024;
        $unit = $candidate;
        if ($value < 1024) {
            break;
        }
    }

    return number_format($value, 2, ',', '.') . ' ' . $unit;
}

/**
 * Escape string untuk HTML.
 */
function daily_summary_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render email HTML dark theme.
 *
 * @param array<string, mixed> $metrics
 */
function daily_summary_render_html(array $metrics, string $date): string
{
    $uploadsToday = (int) ($metrics['uploads_today'] ?? 0);
    $uploadsBytes = (int) ($metrics['uploads_bytes_today'] ?? 0);
    $storageUsed = (int) ($metrics['storage_used'] ?? 0);
    $imagesTotal = (int) ($metrics['images_total'] ?? 0);
    $guestCount = (int) ($metrics['guest_count'] ?? 0);
    $memberCount = (int) ($metrics['member_count'] ?? 0);
    $pendingOperations = (int) ($metrics['pending_operations'] ?? 0);
    $errorsToday = (int) ($metrics['errors_today'] ?? 0);
    $warningsToday = (int) ($metrics['warnings_today'] ?? 0);
    $alertsToday = (int) ($metrics['alerts_today'] ?? 0);

    $aiUsage = is_array($metrics['ai_usage'] ?? null) ? $metrics['ai_usage'] : [];
    $channels = is_array($metrics['channels'] ?? null) ? $metrics['channels'] : [];

    $reconcile = $metrics['reconcile_report'] ?? [
        'available' => false,
        'generated_at' => null,
        'orphan_storage' => null,
        'orphan_metadata' => null,
    ];

    $storageParts = [];
    if ((int) ($metrics['storage_images_bytes'] ?? 0) > 0 || $storageUsed === 0) {
        $storageParts[] = 'images: ' . daily_summary_format_bytes((int) ($metrics['storage_images_bytes'] ?? 0));
    }
    if ((int) ($metrics['storage_stats_bytes'] ?? 0) > 0) {
        $storageParts[] = 'storage_stats: ' . daily_summary_format_bytes((int) ($metrics['storage_stats_bytes'] ?? 0));
    }
    if ((int) ($metrics['storage_users_bytes'] ?? 0) > 0) {
        $storageParts[] = 'users.storage_used: ' . daily_summary_format_bytes((int) ($metrics['storage_users_bytes'] ?? 0));
    }
    $storageDetail = $storageParts !== []
        ? implode(' + ', $storageParts)
        : 'tidak ada data';

    // Baris metrik utama.
    $metricRows = '';
    $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Upload hari ini</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong>' . $uploadsToday . '</strong> gambar &middot; ' . daily_summary_format_bytes($uploadsBytes) . '</td></tr>';
    $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Storage terpakai</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong>' . daily_summary_format_bytes($storageUsed) . '</strong> <span style="color:#666;font-size:12px;">(' . $storageDetail . ')</span></td></tr>';
    $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Total gambar</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong>' . $imagesTotal . '</strong> &middot; guest: ' . $guestCount . ' &middot; member: ' . $memberCount . '</td></tr>';
    $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Error hari ini</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong style="color:#f87171;">' . $errorsToday . '</strong> error &middot; ' . $warningsToday . ' warning</td></tr>';
    $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Alert kritis hari ini</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong>' . $alertsToday . '</strong> <span style="color:#666;font-size:12px;">(log channel alert/alerter level error)</span></td></tr>';
    $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Pending operations</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong>' . $pendingOperations . '</strong> <span style="color:#666;font-size:12px;">(state != completed)</span></td></tr>';

    if (!empty($reconcile['available'])) {
        $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Orphan storage / metadata</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;"><strong>' . (int) $reconcile['orphan_storage'] . '</strong> / <strong>' . (int) $reconcile['orphan_metadata'] . '</strong> <span style="color:#666;font-size:12px;">(' . daily_summary_escape((string) ($reconcile['generated_at'] ?? '-')) . ')</span></td></tr>';
    } else {
        $metricRows .= '<tr><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;">Orphan storage / metadata</td><td style="padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#999;">belum ada laporan</td></tr>';
    }

    // Tabel AI usage.
    $aiRows = '';
    if ($aiUsage !== []) {
        foreach ($aiUsage as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tool = daily_summary_escape((string) ($row['tool_name'] ?? '-'));
            $cnt = (int) ($row['cnt'] ?? 0);
            $bytes = (int) ($row['bytes'] ?? 0);
            $ms = (int) ($row['processing_ms'] ?? 0);
            $failed = (int) ($row['failed'] ?? 0);

            $aiRows .= '<tr>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;">' . $tool . '</td>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;">' . $cnt . '</td>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;">' . daily_summary_format_bytes($bytes) . '</td>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;">' . $ms . ' ms</td>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:' . ($failed > 0 ? '#f87171;' : '#ddd;') . '">' . $failed . '</td>'
                . '</tr>';
        }
    } else {
        $aiRows .= '<tr><td colspan="5" style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#666;">tidak ada pemakaian AI hari ini</td></tr>';
    }

    // Tabel per channel.
    $channelRows = '';
    if ($channels !== []) {
        foreach ($channels as $channel => $counts) {
            $channelName = daily_summary_escape((string) $channel);
            $err = (int) ($counts['error'] ?? 0);
            $warn = (int) ($counts['warning'] ?? 0);

            $channelRows .= '<tr>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;">' . $channelName . '</td>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:' . ($err > 0 ? '#f87171;' : '#666;') . '">' . $err . '</td>'
                . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:' . ($warn > 0 ? '#fbbf24;' : '#666;') . '">' . $warn . '</td>'
                . '</tr>';
        }
    } else {
        $channelRows .= '<tr><td colspan="3" style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#666;">tidak ada error/warning hari ini</td></tr>';
    }

    $dateHtml = daily_summary_escape($date);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#0a0a0f;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
    <div style="max-width:680px;margin:0 auto;padding:40px 20px;">
        <div style="text-align:center;margin-bottom:32px;">
            <h1 style="color:#22d3ee;font-size:26px;margin:0;">🐰 PixelHop</h1>
            <p style="color:#888;font-size:13px;margin-top:8px;">Ringkasan Harian &mdash; {$dateHtml}</p>
        </div>

        <div style="background:linear-gradient(135deg,rgba(20,20,35,0.95),rgba(30,30,50,0.95));border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:28px;">
            <h2 style="color:#fff;font-size:19px;margin:0 0 16px 0;">Metrik Utama</h2>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                {$metricRows}
            </table>

            <h3 style="color:#fff;font-size:15px;margin:28px 0 12px 0;">AI Usage Hari Ini</h3>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tr>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Tool</th>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Jumlah</th>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Bytes</th>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Waktu</th>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Failed</th>
                </tr>
                {$aiRows}
            </table>

            <h3 style="color:#fff;font-size:15px;margin:28px 0 12px 0;">Error/Warning per Channel</h3>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tr>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Channel</th>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Error</th>
                    <th style="text-align:left;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Warning</th>
                </tr>
                {$channelRows}
            </table>

            <p style="color:#555;font-size:12px;margin:24px 0 0 0;">
                Sumber: tabel images/usage_logs/pending_operations, data/logs/app-{$dateHtml}.jsonl,
                dan data/storage_reconcile_report.json. Pesan ini dibuat otomatis oleh PixelHop daily_summary.
            </p>
        </div>

        <div style="text-align:center;margin-top:32px;">
            <p style="color:#555;font-size:12px;margin:0;">&copy; 2025 PixelHop &middot; p.hel.ink</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Entry point.
 *
 * @param array<int, string> $argv
 */
function daily_summary_main(array $argv): int
{
    $date = daily_summary_today_date();

    try {
        $dbMetrics = daily_summary_fetch_db_metrics($date);
    } catch (Throwable $e) {
        Logger::error('daily_summary', 'Gagal mengumpulkan metrik database untuk ringkasan harian', [
            'date' => $date,
            'error' => $e->getMessage(),
        ]);
        fwrite(STDERR, 'CRITICAL: ' . $e->getMessage() . "\n");
        return 1;
    }

    $logMetrics = daily_summary_parse_logs($date);
    $reconcile = daily_summary_read_reconcile_report();

    $metrics = array_merge($dbMetrics, $logMetrics, [
        'reconcile_report' => $reconcile,
    ]);

    $subject = '[PixelHop] Ringkasan Harian — ' . $date;
    $htmlBody = daily_summary_render_html($metrics, $date);

    // Mailer opsional (vendor/autoload + config/mail.php). Bila tidak ada,
    // catat error dan keluar 0: jangan gagalkan cron hanya karena email
    // tidak bisa dikirim dari environment ini.
    if (!class_exists('Mailer', false)) {
        Logger::error('daily_summary', 'Mailer tidak tersedia; ringkasan harian tidak dikirim', [
            'subject' => $subject,
        ]);
        echo "Ringkasan harian TIDAK terkirim (Mailer tidak tersedia)\n";
        echo "Subject : {$subject}\n";
        return 0;
    }

    // Kegagalan SMTP harus tidak menggagalkan cron (exit 0).
    try {
        $mailer = new Mailer();
        $sent = $mailer->sendAdminAlert($subject, $htmlBody);

        if (!$sent) {
            Logger::error('daily_summary', 'Mailer gagal mengirim ringkasan harian (SMTP error)', [
                'date' => $date,
            ]);
            echo "Ringkasan harian TIDAK terkirim (SMTP error)\n";
            echo "Subject : {$subject}\n";
            return 0;
        }

        Logger::info('daily_summary', 'Ringkasan harian terkirim ke admin', [
            'date' => $date,
            'uploads_today' => $metrics['uploads_today'],
            'storage_used' => $metrics['storage_used'],
            'errors_today' => $metrics['errors_today'],
            'alerts_today' => $metrics['alerts_today'],
        ]);

        echo "Ringkasan harian terkirim\n";
        echo "Subject : {$subject}\n";
        return 0;
    } catch (Throwable $e) {
        Logger::error('daily_summary', 'Gagal mengirim ringkasan harian: ' . $e->getMessage(), [
            'date' => $date,
        ]);
        echo "Ringkasan harian TIDAK terkirim: " . $e->getMessage() . "\n";
        echo "Subject : {$subject}\n";
        return 0;
    }
}

exit(daily_summary_main($argv));
