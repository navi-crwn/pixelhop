<?php
/**
 * PixelHop - Logger
 *
 * Logger statis ringan & terstruktur untuk seluruh endpoint.
 *
 * Menutup temuan audit D6-03 (error_log tak terstruktur) dan menopang
 * D6-02 (jangan pernah bocorkan pesan exception mentah ke klien): pesan
 * asli dari exception hanya ditulis ke log server di sini, sedangkan
 * respons ke klien memakai pesan generik.
 *
 * Karakteristik:
 *   - Satu baris JSON per log, dikirim ke error_log PHP (dual-write).
 *     {"ts":"...","level":"error","channel":"upload","msg":"...","ctx":{...}}
 *   - Baris JSON yang sama juga di-append ke log terpusat
 *     data/logs/app-YYYY-MM-DD.jsonl (satu JSON object per baris).
 *   - Rotasi ukuran: bila file hari ini > 5MB, dirotasi ke
 *     app-YYYY-MM-DD.N.jsonl (N increment).
 *   - Retensi 14 hari: file app-*.jsonl lebih tua dari 14 hari dihapus.
 *   - TIDAK mencetak apa pun saat di-require (tanpa side-effect).
 *   - Idempotent: aman di-require berulang kali.
 *   - TIDAK menulis PII: alamat IP di-mask (1.2.3.x) dan key sensitif
 *     (password/token/secret/authorization/cookie/api key) di-redact.
 *
 * Class ini sengaja diletakkan di global namespace agar bisa di-require
 * manual tanpa autoloader, konsisten dengan struktur repo PixelHop.
 */

if (!class_exists('Logger', false)) {

    final class Logger
    {
        /** Key konteks yang nilainya berupa IP dan harus di-mask. */
        private const IP_KEYS = [
            'ip',
            'ip_address',
            'ipaddress',
            'remote_addr',
            'remoteaddr',
            'client_ip',
            'clientip',
            'forwarded_for',
            'x_forwarded_for',
        ];

        /** Substring key konteks yang dianggap sensitif (nilai di-redact). */
        private const SENSITIVE_SUBSTRINGS = [
            'password',
            'passwd',
            'pass',
            'token',
            'secret',
            'authorization',
            'auth',
            'cookie',
            'api_key',
            'apikey',
            'access_key',
            'private_key',
            'session',
            'csrf',
            'nonce',
            'signature',
        ];

        /** Placeholder nilai yang di-redact. */
        private const REDACTED = '[redacted]';

        /** Direktori log terpusat (relatif terhadap root repo). */
        private const LOG_DIR = __DIR__ . '/../data/logs';

        /** Batas ukuran file log harian sebelum dirotasi (5MB). */
        private const MAX_FILE_SIZE = 5 * 1024 * 1024;

        /** Retensi file log (hari). */
        private const RETENTION_DAYS = 14;

        /** Nama file marker cek retensi terakhir (mencegah glob tiap panggil). */
        private const RETENTION_MARKER = 'app-retention.marker';

        /** Apakah direktori log sudah dipastikan ada pada request ini. */
        private static bool $logDirEnsured = false;

        /** Apakah direktori log bisa ditulis (null = belum dicek). */
        private static ?bool $logDirWritable = null;

        /**
         * Log level ERROR.
         *
         * @param string $channel Kanal/sumber log (mis. 'compress', 'upload').
         * @param string $message Pesan internal (boleh memuat detail exception).
         * @param array  $context Konteks terstruktur (akan disanitasi).
         */
        public static function error(string $channel, string $message, array $context = []): void
        {
            self::log('error', $channel, $message, $context);
        }

        /**
         * Log level WARNING.
         */
        public static function warning(string $channel, string $message, array $context = []): void
        {
            self::log('warning', $channel, $message, $context);
        }

        /**
         * Log level INFO.
         */
        public static function info(string $channel, string $message, array $context = []): void
        {
            self::log('info', $channel, $message, $context);
        }

        /**
         * Mask satu alamat IP: IPv4 -> 1.2.3.x, IPv6 -> hextet terakhir 'x'.
         *
         * Nilai yang bukan IP valid dikembalikan sebagai 'x.x.x.x' agar tidak
         * ada nilai tak dikenal yang lolos ke log.
         */
        public static function maskIp(string $ip): string
        {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);
                if (count($parts) === 4) {
                    $parts[3] = 'x';
                    return implode('.', $parts);
                }
                return 'x.x.x.x';
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $masked = preg_replace('/[^:]*$/', 'x', $ip);
                return is_string($masked) ? $masked : 'x';
            }

            return 'x.x.x.x';
        }

        /**
         * Tulis satu baris JSON ke error_log dan file log terpusat (dual-write).
         */
        private static function log(string $level, string $channel, string $message, array $context): void
        {
            $entry = [
                'ts' => date('c'),
                'level' => $level,
                'channel' => $channel,
                'msg' => self::sanitizeMessage($message),
                'ctx' => self::sanitizeContext($context),
            ];

            $json = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($json === false) {
                // Fallback darurat: jangan pernah gagal karena encoding.
                $json = '{"ts":"' . date('c') . '","level":"' . $level
                    . '","channel":"' . self::sanitizeMessage($channel)
                    . '","msg":"[unencodable log entry]","ctx":{}}';
            }

            // 1) error_log PHP (perilaku lama dipertahankan).
            error_log($json);

            // 2) Log terpusat harian (best-effort, tak boleh menggagalkan request).
            self::writeToFile($json);
        }

        /**
         * Append satu baris JSON ke data/logs/app-YYYY-MM-DD.jsonl.
         *
         * Best-effort: kegagalan I/O tidak pernah memunculkan error/exception.
         */
        private static function writeToFile(string $json): void
        {
            if (!self::ensureLogDir()) {
                return;
            }

            $file = self::currentLogFile();

            // Rotasi ukuran sebelum menulis bila file hari ini sudah melewati batas.
            if (is_file($file) && @filesize($file) > self::MAX_FILE_SIZE) {
                self::rotateLogFile($file);
            }

            @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);

            self::maybeRunRetention();
        }

        /**
         * Pastikan direktori log ada dan bisa ditulis (di-cache per request).
         */
        private static function ensureLogDir(): bool
        {
            if (self::$logDirEnsured) {
                return self::$logDirWritable === true;
            }

            self::$logDirEnsured = true;

            if (!is_dir(self::LOG_DIR)) {
                // 0775 + recursive; pakai @ agar race antar-request tidak fatal.
                @mkdir(self::LOG_DIR, 0775, true);
            }

            self::$logDirWritable = is_dir(self::LOG_DIR) && is_writable(self::LOG_DIR);

            return self::$logDirWritable;
        }

        /**
         * Path file log untuk tanggal hari ini.
         */
        private static function currentLogFile(): string
        {
            return self::LOG_DIR . '/app-' . date('Y-m-d') . '.jsonl';
        }

        /**
         * Rotasi file log yang melebihi batas ukuran ke app-YYYY-MM-DD.N.jsonl.
         *
         * N dimulai dari 1 dan naik sampai menemukan nama yang belum dipakai.
         */
        private static function rotateLogFile(string $file): void
        {
            // app-2026-09-11.jsonl -> prefix "app-2026-09-11"
            $prefix = preg_replace('/\.jsonl$/', '', $file);
            if (!is_string($prefix)) {
                $prefix = $file;
            }

            $n = 1;
            do {
                $target = $prefix . '.' . $n . '.jsonl';
                $n++;
            } while (file_exists($target) && $n <= 1000);

            // rename() memindahkan isi; kegagalan diabaikan (best-effort).
            @rename($file, $target);
        }

        /**
         * Retensi: hapus file app-*.jsonl lebih tua dari RETENTION_DAYS hari.
         *
         * Dijalankan ringan: hanya sekali per hari, dijaga oleh file marker
         * ber-timestamp sehingga tidak ada glob() di setiap panggilan log.
         */
        private static function maybeRunRetention(): void
        {
            $marker = self::LOG_DIR . '/' . self::RETENTION_MARKER;
            $today = date('Y-m-d');

            $last = @file_get_contents($marker);
            if (is_string($last) && trim($last) === $today) {
                return;
            }

            // Tulis marker lebih dulu agar request lain tidak mengulang glob.
            @file_put_contents($marker, $today, LOCK_EX);

            self::pruneOldLogs();
        }

        /**
         * Hapus file app-*.jsonl yang lebih tua dari RETENTION_DAYS hari.
         */
        private static function pruneOldLogs(): void
        {
            $cutoff = time() - (self::RETENTION_DAYS * 24 * 60 * 60);

            $files = glob(self::LOG_DIR . '/app-*.jsonl');
            if (!is_array($files)) {
                return;
            }

            foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $cutoff) {
                    @unlink($file);
                }
            }
        }

        /**
         * Sanitasi pesan: mask IP yang mungkin menyelip di dalam teks
         * (mis. pesan exception yang memuat URL berbasis IP).
         */
        private static function sanitizeMessage(string $message): string
        {
            $masked = preg_replace_callback(
                '/(?<![\w.])((?:\d{1,3}\.){3}\d{1,3})(?![\w.])/',
                static function (array $m): string {
                    return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                        ? self::maskIp($m[1])
                        : $m[1];
                },
                $message
            );

            return is_string($masked) ? $masked : $message;
        }

        /**
         * Sanitasi konteks secara rekursif: mask IP, redact key sensitif,
         * dan batasi kedalaman untuk mencegah struktur siklik/raksasa.
         *
         * @param mixed $value
         * @param int   $depth
         * @return mixed
         */
        private static function sanitizeContext(mixed $value, int $depth = 0): mixed
        {
            if ($depth > 8) {
                return '[max-depth]';
            }

            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    if (is_string($key) && self::isSensitiveKey($key)) {
                        $clean[$key] = self::REDACTED;
                        continue;
                    }

                    if (is_string($key) && self::isIpKey($key) && is_string($item)) {
                        $clean[$key] = self::maskIp($item);
                        continue;
                    }

                    $clean[$key] = self::sanitizeContext($item, $depth + 1);
                }
                return $clean;
            }

            if (is_object($value)) {
                // Jangan biarkan objek besar/siklik masuk ke log.
                return '[object ' . get_class($value) . ']';
            }

            if (is_string($value)) {
                return self::sanitizeMessage($value);
            }

            if (is_scalar($value) || $value === null) {
                return $value;
            }

            return '[' . gettype($value) . ']';
        }

        private static function isIpKey(string $key): bool
        {
            return in_array(strtolower($key), self::IP_KEYS, true);
        }

        private static function isSensitiveKey(string $key): bool
        {
            $lower = strtolower($key);
            foreach (self::SENSITIVE_SUBSTRINGS as $needle) {
                if (strpos($lower, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }
    }
}
