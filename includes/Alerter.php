<?php
/**
 * PixelHop - Alerter
 *
 * Kanal alert email ke admin untuk kegagalan kritis (upload S3 gagal,
 * DB error, delete gagal, dsb). Kelas ini adalah jaring pengaman untuk
 * migrasi data: alert sampingan yang TIDAK BOLEH menggagalkan request
 * utama, sehingga semua kegagalan pengiriman ditelan dan dicatat ke log.
 *
 * Operator dapat mengarahkan alert ke alamat khusus melalui
 * config/mail.php key 'admin_alerts_to', atau environment variable
 * ADMIN_ALERT_EMAIL. Bila keduanya tidak ada, fallback ke alamat email
 * admin/from yang sudah ada di config/mail.php.
 *
 * Throttle: maksimal 1 email per channel per 15 menit. State throttle
 * disimpan di data/alerts_throttle.json melalui JsonStore (map channel
 * -> last_sent_ts) di dalam lock agar aman dari race antar request.
 *
 * Kelas ini sengaja diletakkan di global namespace agar bisa di-require
 * manual tanpa autoloader, konsisten dengan struktur repo PixelHop.
 * Tidak ada output saat file di-require (idempotent, tanpa side-effect).
 */

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/JsonStore.php';

if (!class_exists('Alerter', false)) {

    final class Alerter
    {
        /** Interval throttle: satu email per channel per 15 menit (detik). */
        private const THROTTLE_SECONDS = 15 * 60;

        /** Path file state throttle (relatif terhadap root repo). */
        private const THROTTLE_FILE = __DIR__ . '/../data/alerts_throttle.json';

        /**
         * Kirim alert level CRITICAL ke admin.
         *
         * @param string $channel Kanal/sumber alert (mis. 'upload-s3', 'db').
         * @param string $message Ringkasan/deskripsi kegagalan kritis.
         * @param array  $context Konteks terstruktur (akan disanitasi).
         */
        public static function critical(
            string $channel,
            string $message,
            array $context = []
        ): void {
            self::send('CRITICAL', $channel, $message, $context);
        }

        /**
         * Kirim alert level WARNING ke admin.
         *
         * @param string $channel Kanal/sumber alert.
         * @param string $message Ringkasan/deskripsi peringatan.
         * @param array  $context Konteks terstruktur (akan disanitasi).
         */
        public static function warning(
            string $channel,
            string $message,
            array $context = []
        ): void {
            self::send('WARNING', $channel, $message, $context);
        }

        /**
         * Jalur pengiriman alert gabungan untuk critical & warning.
         *
         * Alur:
         *   1. Cek throttle di dalam lock JsonStore (bila < 15 menit, skip).
         *   2. Bangun subject + body HTML (context disanitasi).
         *   3. Kirim via Mailer::sendAdminAlert().
         *   4. Semua Throwable ditangkap & dicatat via Logger::error('alert', ...).
         *
         * Method ini tidak pernah melempar exception ke pemanggil.
         */
        private static function send(
            string $level,
            string $channel,
            string $message,
            array $context
        ): void {
            try {
                if (!self::throttleAllows($channel)) {
                    Logger::warning(
                        'alert-throttled',
                        'Alert ditahan throttle (maks 1 email per channel per 15 menit)',
                        ['channel' => $channel]
                    );
                    return;
                }

                $subject = '[PixelHop ALERT] ' . $channel . ': ' . self::summarize($message);

                $mailer = new Mailer();
                $sent = $mailer->sendAdminAlert(
                    $subject,
                    self::buildHtmlBody($level, $channel, $message, $context)
                );

                if (!$sent) {
                    Logger::error(
                        'alert',
                        'Mailer gagal mengirim alert email ke admin',
                        ['level' => $level, 'channel' => $channel]
                    );
                    return;
                }

                Logger::info(
                    'alert',
                    'Alert email terkirim ke admin',
                    ['level' => $level, 'channel' => $channel]
                );
            } catch (Throwable $e) {
                Logger::error(
                    'alert',
                    'Gagal mengirim alert email: ' . $e->getMessage(),
                    ['level' => $level, 'channel' => $channel]
                );
            }
        }

        /**
         * Cek throttle di dalam lock JsonStore.
         *
         * Mengembalikan true bila pengiriman diizinkan. Mengembalikan false
         * bila channel pernah dikirim dalam THROTTLE_SECONDS terakhir.
         *
         * Catatan: bila pengiriman email gagal setelah throttle di-update,
         * channel tetap dianggap "sudah dikirim" untuk menahan ledakan alert;
         * ini trade-off yang disengaja agar jalur sampingan tidak spam SMTP.
         *
         * @return bool true bila boleh kirim.
         */
        private static function throttleAllows(string $channel): bool
        {
            try {
                $store = new JsonStore(self::THROTTLE_FILE);

                $allowed = false;

                $store->mutate(function (array $data) use ($channel, &$allowed): array {
                    $now = time();
                    $lastSent = $data[$channel] ?? null;

                    if (
                        is_numeric($lastSent)
                        && ($now - (int) $lastSent) < self::THROTTLE_SECONDS
                    ) {
                        $allowed = false;
                        return $data;
                    }

                    $data[$channel] = $now;
                    $allowed = true;
                    return $data;
                });

                return $allowed;
            } catch (Throwable $e) {
                // Gagal baca/tulis state throttle: tetap izinkan kirim,
                // jangan sampai alert bungkam karena file state bermasalah.
                Logger::warning(
                    'alert-throttle-state',
                    'Tidak bisa membaca/menulis state throttle; kirim tetap diizinkan',
                    ['channel' => $channel, 'error' => $e->getMessage()]
                );
                return true;
            }
        }

        /**
         * Bangun body HTML sederhana: message + context terformat.
         *
         * Context disanitasi sebelum dirender untuk mencegah kebocoran
         * secret/credential. Sanitasi mengikuti pola yang sama seperti
         * Logger (hanya mereplikasi perilakunya di sini; Logger tidak
         * mengekspos sanitizer-nya sebagai API publik).
         */
        private static function buildHtmlBody(
            string $level,
            string $channel,
            string $message,
            array $context
        ): string {
            $safeMessage = self::sanitizeString($message);
            $safeContext = self::sanitizeValue($context);

            $rows = '';
            if ($safeContext !== []) {
                foreach ($safeContext as $key => $value) {
                    $keyHtml = self::escapeHtml((string) $key);
                    $valueHtml = self::escapeHtml(self::stringifyValue($value));
                    $rows .= '<tr>'
                        . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#888;white-space:nowrap;vertical-align:top;">'
                        . $keyHtml
                        . '</td>'
                        . '<td style="padding:8px 12px;border:1px solid rgba(255,255,255,0.08);color:#ddd;word-break:break-all;vertical-align:top;">'
                        . $valueHtml
                        . '</td>'
                        . '</tr>';
                }
            }

            $contextBlock = $rows !== ''
                ? '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
                    . '<tr><th colspan="2" style="text-align:left;padding:10px 12px;border:1px solid rgba(255,255,255,0.08);color:#aaa;background:rgba(255,255,255,0.04);">Context</th></tr>'
                    . $rows
                    . '</table>'
                : '<p style="color:#666;font-size:13px;margin:0 0 24px 0;">(tanpa context tambahan)</p>';

            $levelColor = $level === 'CRITICAL' ? '#f87171' : '#fbbf24';

            return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#0a0a0f;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:40px 20px;">
        <div style="text-align:center;margin-bottom:32px;">
            <h1 style="color:#22d3ee;font-size:24px;margin:0;">PixelHop Alert</h1>
            <p style="color:#888;font-size:13px;margin-top:8px;">Observability &amp; migrasi data</p>
        </div>

        <div style="background:linear-gradient(135deg,rgba(20,20,35,0.95),rgba(30,30,50,0.95));border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:32px;">
            <div style="margin-bottom:16px;">
                <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:{$levelColor};color:#0a0a0f;font-weight:700;font-size:11px;letter-spacing:0.08em;">{$level}</span>
                <span style="display:inline-block;margin-left:8px;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,0.08);color:#ccc;font-weight:600;font-size:11px;">{$channel}</span>
            </div>

            <h2 style="color:#fff;font-size:20px;margin:0 0 16px 0;line-height:1.4;">{$safeMessage}</h2>

            {$contextBlock}

            <p style="color:#555;font-size:12px;margin:24px 0 0 0;">
                Pesan ini dibuat otomatis oleh PixelHop Alerter. Throttle: maks 1 email per channel per 15 menit.
            </p>
        </div>

        <div style="text-align:center;margin-top:32px;">
            <p style="color:#555;font-size:12px;margin:0;">&copy; 2025 PixelHop</p>
        </div>
    </div>
</body>
</html>
HTML;
        }

        /**
         * Ringkas message untuk subject (potong pada batas wajar).
         */
        private static function summarize(string $message): string
        {
            $subject = trim((string) preg_replace('/\s+/u', ' ', $message));
            $max = 80;

            if (function_exists('mb_substr')) {
                if (mb_strlen($subject) > $max) {
                    return mb_substr($subject, 0, $max) . '…';
                }
                return $subject;
            }

            return strlen($subject) > $max
                ? substr($subject, 0, $max) . '…'
                : $subject;
        }

        /**
         * Sanitasi nilai konteks secara rekursif (mirip Logger):
         * mask IP, redact key sensitif, batasi kedalaman, objek diwakili
         * nama kelasnya, dan string IP di-mask.
         *
         * @param mixed $value
         * @param int   $depth
         * @return mixed
         */
        private static function sanitizeValue(mixed $value, int $depth = 0): mixed
        {
            if ($depth > 8) {
                return '[max-depth]';
            }

            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    if (is_string($key) && self::isSensitiveKey($key)) {
                        $clean[$key] = '[redacted]';
                        continue;
                    }

                    if (is_string($key) && self::isIpKey($key) && is_string($item)) {
                        $clean[$key] = self::maskIp($item);
                        continue;
                    }

                    $clean[$key] = self::sanitizeValue($item, $depth + 1);
                }
                return $clean;
            }

            if (is_object($value)) {
                return '[object ' . get_class($value) . ']';
            }

            if (is_string($value)) {
                return self::sanitizeString($value);
            }

            if (is_scalar($value) || $value === null) {
                return $value;
            }

            return '[' . gettype($value) . ']';
        }

        /**
         * Sanitasi string: mask IPv4 yang menyelip di dalam teks.
         */
        private static function sanitizeString(string $value): string
        {
            $masked = preg_replace_callback(
                '/(?<![\w.])((?:\d{1,3}\.){3}\d{1,3})(?![\w.])/',
                static function (array $m): string {
                    return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                        ? self::maskIp($m[1])
                        : $m[1];
                },
                $value
            );

            return is_string($masked) ? $masked : $value;
        }

        /**
         * Mask satu alamat IP: IPv4 -> 1.2.3.x, IPv6 -> hextet terakhir 'x'.
         */
        private static function maskIp(string $ip): string
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

        private static function isIpKey(string $key): bool
        {
            $ipKeys = [
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

            return in_array(strtolower($key), $ipKeys, true);
        }

        private static function isSensitiveKey(string $key): bool
        {
            $needles = [
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

            $lower = strtolower($key);
            foreach ($needles as $needle) {
                if (strpos($lower, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Ubah nilai tersanitasi menjadi string untuk dirender di HTML.
         */
        private static function stringifyValue(mixed $value): string
        {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if ($value === null) {
                return 'null';
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '[unencodable]';
        }

        private static function escapeHtml(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}
