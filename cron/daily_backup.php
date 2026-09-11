<?php
/**
 * PixelHop - Daily Backup Cron (Reliability Fase 1)
 *
 * Backup harian untuk server produksi: database, konfigurasi, data JSON,
 * dan (opsional) kode. Menyimpan ke direktori bertanggal, lalu memangkas
 * backup lama (>14 hari).
 *
 * Cara pakai (crontab, harian 03:00):
 *   0 3 * * * php /var/www/pichost/cron/daily_backup.php >> /var/log/pichost/backup.log 2>&1
 *
 * Struktur hasil:
 *   /var/backups/pichost/daily/<YYYY-MM-DD_HHMMSS>/
 *       db.sql.gz        dump database (mysqldump --single-transaction | gzip)
 *       config.tar.gz    isi config/ (chmod 0600, berisi secrets)
 *       data_json/       salinan data/images.json, data/abuse_reports.json, data/contacts.json
 *       code.tar.gz      kode tanpa python/venv, temp/, data/ratelimit/ (opsional)
 *
 * Keamanan kredensial:
 *   - Kredensial DB HANYA ditulis ke /tmp/.my.cnf sementara (chmod 0600),
 *     dipakai via `mysqldump --defaults-file=...`, lalu DIHAPUS di blok
 *     finally (tidak pernah tertinggal).
 *   - Kredensial TIDAK pernah di-echo, TIDAK pernah dikirim sebagai argumen
 *     baris-perintah (agar tak terlihat di `ps`), dan TIDAK pernah di-log.
 *
 * Robustness:
 *   - Setiap langkah dibungkus try/catch sendiri; satu langkah gagal tidak
 *     membatalkan langkah lain.
 *   - Exit code 0 bila langkah kritis (db.sql.gz) sukses/di-skip karena
 *     konfigurasi tidak ada; 1 bila langkah kritis benar-benar gagal.
 *
 * Override direktori (opsional, untuk staging/uji lokal):
 *   PICHOST_BACKUP_DIR=/path/lain php cron/daily_backup.php
 */

// CLI only — script cron, dijalankan lewat crontab.
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

define('ROOT_PATH', dirname(__DIR__));
const BACKUP_RETENTION_DAYS = 14;

require_once ROOT_PATH . '/includes/Logger.php';

// Direktori dasar backup. Default produksi; bisa dioverride lewat env.
$backupBase = getenv('PICHOST_BACKUP_DIR');
if (!is_string($backupBase) || $backupBase === '') {
    $backupBase = '/var/backups/pichost/daily';
}
$backupBase = rtrim($backupBase, '/');

$criticalFailure = false; // true hanya bila langkah kritis (db) gagal.

/**
 * Apakah sebuah executable tersedia di PATH.
 */
function commandExists(string $bin): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $rc = 1;
    $out = [];
    // `command -v` tidak mencetak apa pun ke stdout selain path.
    @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $rc);
    return $rc === 0 && !empty($out);
}

/**
 * Jalankan perintah shell, kembalikan exit code (atau null bila exec mati).
 *
 * @param string $cmd
 * @param array  $output Baris stdout perintah (by reference).
 * @return int|null
 */
function runCommand(string $cmd, array &$output = []): ?int
{
    if (!function_exists('exec')) {
        return null;
    }
    $output = [];
    $rc = 1;
    @exec($cmd, $output, $rc);
    return $rc;
}

/**
 * Hapus direktori beserta seluruh isinya secara rekursif.
 */
function removeDirRecursive(string $path): bool
{
    if (!is_dir($path)) {
        return true;
    }

    try {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return @rmdir($path);
}

/**
 * Quote satu nilai untuk file opsi MySQL (my.cnf).
 * Membuang CR/LF (cegah injeksi opsi) dan meng-escape backslash/quote.
 */
function quoteCnfValue(string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

$backupDir = $backupBase . '/' . date('Y-m-d_His');

// ---------------------------------------------------------------------------
// 0. Siapkan direktori backup.
// ---------------------------------------------------------------------------
try {
    if (!is_dir($backupDir)) {
        if (!@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Tidak bisa membuat direktori backup: ' . $backupDir);
        }
    }
    @chmod($backupDir, 0700);
} catch (Throwable $e) {
    // Tanpa direktori, tidak ada yang bisa dikerjakan.
    Logger::error('backup', 'daily backup gagal menyiapkan direktori', [
        'dir' => $backupDir,
        'error' => $e->getMessage(),
    ]);
    fwrite(STDERR, '[backup] fatal: ' . $e->getMessage() . "\n");
    exit(1);
}

$result = [
    'dir' => basename($backupDir),
    'db_ok' => false,
    'config_ok' => false,
    'data_ok' => false,
    'code_ok' => false,
    'db_size' => 0,
];

// ---------------------------------------------------------------------------
// 1. Database dump: db.sql.gz
// ---------------------------------------------------------------------------
try {
    $dbConfigFile = ROOT_PATH . '/config/database.php';

    if (!is_file($dbConfigFile)) {
        // Bukan kegagalan kritis: environment mungkin belum dikonfigurasi.
        Logger::info('backup', 'database config tidak ditemukan, lewati dump db', [
            'path' => 'config/database.php',
        ]);
    } elseif (!commandExists('mysqldump')) {
        $criticalFailure = true;
        Logger::error('backup', 'mysqldump tidak tersedia, dump db dilewati', [
            'dir' => $result['dir'],
        ]);
    } else {
        $dbConfig = require $dbConfigFile;

        $dbHost = (string) ($dbConfig['host'] ?? 'localhost');
        $dbPort = (string) ($dbConfig['port'] ?? '3306');
        $dbName = (string) ($dbConfig['database'] ?? '');
        $dbUser = (string) ($dbConfig['username'] ?? '');
        $dbPass = (string) ($dbConfig['password'] ?? '');

        if ($dbName === '' || $dbUser === '') {
            throw new RuntimeException('Konfigurasi database tidak lengkap (database/username kosong)');
        }

        $tmpCnf = '/tmp/.my.cnf';
        $outSqlGz = $backupDir . '/db.sql.gz';
        $errFile = $backupDir . '/.db_dump.err';

        try {
            // Tulis file kredensial sementara, izin 0600.
            $cnf = "[client]\n"
                . "host=" . quoteCnfValue($dbHost) . "\n"
                . "port=" . quoteCnfValue($dbPort) . "\n"
                . "user=" . quoteCnfValue($dbUser) . "\n"
                . "password=" . quoteCnfValue($dbPass) . "\n";

            if (@file_put_contents($tmpCnf, $cnf) === false) {
                throw new RuntimeException('Gagal menulis file kredensial sementara');
            }
            @chmod($tmpCnf, 0600);

            // mysqldump --defaults-file=<escaped> --single-transaction --quick <escaped db> | gzip > <escaped out>
            $inner = sprintf(
                'mysqldump --defaults-file=%s --single-transaction --quick %s | gzip > %s',
                escapeshellarg($tmpCnf),
                escapeshellarg($dbName),
                escapeshellarg($outSqlGz)
            );

            // pipefail agar kegagalan mysqldump (bukan hanya gzip) terdeteksi.
            $cmd = 'bash -o pipefail -c ' . escapeshellarg($inner)
                . ' 2>' . escapeshellarg($errFile);

            $rc = runCommand($cmd);

            if ($rc === null) {
                // exec() tidak tersedia (disable_functions).
                throw new RuntimeException('Fungsi exec() tidak tersedia; dump db tidak bisa dijalankan');
            }

            // Verifikasi: gzip -t bila tersedia, jika tidak cukup ukuran > 0.
            $valid = false;
            if ($rc === 0 && is_file($outSqlGz) && filesize($outSqlGz) > 0) {
                if (commandExists('gzip')) {
                    $tRc = runCommand('gzip -t ' . escapeshellarg($outSqlGz) . ' 2>/dev/null');
                    $valid = ($tRc === 0);
                } else {
                    $valid = true; // verifikasi fallback: ukuran > 0
                }
            }

            if ($valid) {
                // Defense-in-depth: dump memuat data, batasi izin baca.
                @chmod($outSqlGz, 0600);
                $result['db_ok'] = true;
                $result['db_size'] = (int) filesize($outSqlGz);
                Logger::info('backup', 'db dump selesai', [
                    'dir' => $result['dir'],
                    'size' => $result['db_size'],
                ]);
            } else {
                $criticalFailure = true;
                $errTail = is_file($errFile) ? trim((string) @file_get_contents($errFile)) : '';
                Logger::error('backup', 'db dump gagal', [
                    'dir' => $result['dir'],
                    'rc' => $rc,
                    'error' => substr($errTail, 0, 500),
                ]);
                // Buang artefak dump yang tidak valid.
                @unlink($outSqlGz);
            }

            @unlink($errFile);
        } finally {
            // WAJIB: jangan biarkan kredensial tertinggal.
            if (is_file($tmpCnf)) {
                @unlink($tmpCnf);
            }
        }
    }
} catch (Throwable $e) {
    $criticalFailure = true;
    Logger::error('backup', 'db dump error', [
        'dir' => $result['dir'],
        'error' => $e->getMessage(),
    ]);
    // Pastikan file kredensial sementara tidak tertinggal pada jalur error apa pun.
    if (is_file('/tmp/.my.cnf')) {
        @unlink('/tmp/.my.cnf');
    }
}

// ---------------------------------------------------------------------------
// 2. Konfigurasi: config.tar.gz (berisi secrets → chmod 0600)
// ---------------------------------------------------------------------------
try {
    $configDir = ROOT_PATH . '/config';
    if (is_dir($configDir) && commandExists('tar')) {
        $outConfig = $backupDir . '/config.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($outConfig)
            . ' -C ' . escapeshellarg(ROOT_PATH) . ' config 2>/dev/null';
        $rc = runCommand($cmd);

        if ($rc === 0 && is_file($outConfig) && filesize($outConfig) > 0) {
            @chmod($outConfig, 0600);
            $result['config_ok'] = true;
            Logger::info('backup', 'config diarsipkan', [
                'dir' => $result['dir'],
                'size' => (int) filesize($outConfig),
            ]);
        } else {
            Logger::error('backup', 'arsip config gagal', [
                'dir' => $result['dir'],
                'rc' => $rc,
            ]);
        }
    } else {
        Logger::info('backup', 'config/ atau tar tidak tersedia, lewati arsip config', [
            'dir' => $result['dir'],
        ]);
    }
} catch (Throwable $e) {
    Logger::error('backup', 'arsip config error', [
        'dir' => $result['dir'],
        'error' => $e->getMessage(),
    ]);
}

// ---------------------------------------------------------------------------
// 3. Data JSON: data_json/
// ---------------------------------------------------------------------------
try {
    $jsonFiles = ['images.json', 'abuse_reports.json', 'contacts.json'];
    $dataDest = $backupDir . '/data_json';
    $copied = 0;

    foreach ($jsonFiles as $file) {
        $src = ROOT_PATH . '/data/' . $file;
        if (!is_file($src)) {
            continue;
        }
        if (!is_dir($dataDest) && !@mkdir($dataDest, 0700, true) && !is_dir($dataDest)) {
            throw new RuntimeException('Gagal membuat direktori data_json');
        }
        if (@copy($src, $dataDest . '/' . $file)) {
            @chmod($dataDest . '/' . $file, 0600);
            $copied++;
        } else {
            Logger::error('backup', 'gagal menyalin data json', [
                'dir' => $result['dir'],
                'file' => $file,
            ]);
        }
    }

    $result['data_ok'] = $copied > 0;
    Logger::info('backup', 'data json disalin', [
        'dir' => $result['dir'],
        'copied' => $copied,
    ]);
} catch (Throwable $e) {
    Logger::error('backup', 'salin data json error', [
        'dir' => $result['dir'],
        'error' => $e->getMessage(),
    ]);
}

// ---------------------------------------------------------------------------
// 4. Kode: code.tar.gz (opsional, tanpa python/venv, temp/, data/ratelimit/)
// ---------------------------------------------------------------------------
try {
    if (commandExists('tar')) {
        $outCode = $backupDir . '/code.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($outCode)
            . ' -C ' . escapeshellarg(ROOT_PATH)
            . " --exclude='./python/venv'"
            . " --exclude='./temp'"
            . " --exclude='./data/ratelimit'"
            . ' . 2>/dev/null';
        $rc = runCommand($cmd);

        if ($rc === 0 && is_file($outCode) && filesize($outCode) > 0) {
            @chmod($outCode, 0600);
            $result['code_ok'] = true;
            Logger::info('backup', 'arsip kode dibuat', [
                'dir' => $result['dir'],
                'size' => (int) filesize($outCode),
            ]);
        } else {
            // Opsional: cukup dicatat, tidak fatal.
            Logger::error('backup', 'arsip kode gagal', [
                'dir' => $result['dir'],
                'rc' => $rc,
            ]);
        }
    }
} catch (Throwable $e) {
    Logger::error('backup', 'arsip kode error', [
        'dir' => $result['dir'],
        'error' => $e->getMessage(),
    ]);
}

// ---------------------------------------------------------------------------
// 5. Retensi: hapus backup lebih tua dari BACKUP_RETENTION_DAYS (14) hari.
// ---------------------------------------------------------------------------
try {
    $removed = 0;
    $cutoff = time() - (BACKUP_RETENTION_DAYS * 86400);

    if (is_dir($backupBase)) {
        $entries = @scandir($backupBase) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $backupBase . '/' . $entry;
            if (!is_dir($path) || $path === $backupDir) {
                continue;
            }

            // Parse nama direktori Y-m-d_His; fallback ke mtime bila tak cocok.
            $ts = null;
            $dt = DateTime::createFromFormat('Y-m-d_His', $entry);
            if ($dt !== false && $dt->format('Y-m-d_His') === $entry) {
                $ts = $dt->getTimestamp();
            } else {
                $mtime = @filemtime($path);
                $ts = ($mtime !== false) ? $mtime : null;
            }

            if ($ts !== null && $ts < $cutoff) {
                if (removeDirRecursive($path)) {
                    $removed++;
                } else {
                    Logger::error('backup', 'gagal menghapus backup lama', [
                        'dir' => $entry,
                    ]);
                }
            }
        }
    }

    Logger::info('backup', 'retensi selesai', [
        'dir' => $result['dir'],
        'removed' => $removed,
        'retention_days' => BACKUP_RETENTION_DAYS,
    ]);
} catch (Throwable $e) {
    Logger::error('backup', 'retensi error', [
        'dir' => $result['dir'],
        'error' => $e->getMessage(),
    ]);
}

// ---------------------------------------------------------------------------
// 6. Ringkasan akhir.
// ---------------------------------------------------------------------------
$summary = [
    'dir' => $result['dir'],
    'db_ok' => $result['db_ok'],
    'config_ok' => $result['config_ok'],
    'data_ok' => $result['data_ok'],
    'code_ok' => $result['code_ok'],
    'db_size' => $result['db_size'],
];

if ($criticalFailure) {
    Logger::error('backup', 'daily backup selesai dengan kegagalan kritis', $summary);
} else {
    Logger::info('backup', 'daily backup done', $summary);
}

exit($criticalFailure ? 1 : 0);
