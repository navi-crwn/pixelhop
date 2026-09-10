<?php
/**
 * PixelHop - Bootstrap sesi & header keamanan
 *
 * Satu titik bootstrap untuk SEMUA entrypoint. File ini:
 *   - memulai sesi dengan cookie parameter yang aman (HTTPS-aware);
 *   - meregenerasi ID sesi secara berkala;
 *   - menyediakan helper keamanan: sendSecurityHeaders(), e(), clientIsHttps().
 *
 * Aman di-require berulang (idempotent): sesi hanya dimulai bila belum aktif
 * dan semua helper didefinisikan hanya bila belum ada.
 *
 * Catatan: file ini sengaja TIDAK me-require file lain agar bebas dari
 * dependensi siklik. Logika trusted-proxy untuk deteksi HTTPS di belakang
 * Cloudflare diduplikasi ringkas dari includes/ClientIp.php.
 */

// ---------------------------------------------------------------------------
// Helper trusted-proxy (didefinisikan lebih dulu, tanpa dependensi eksternal)
// ---------------------------------------------------------------------------

if (!function_exists('_pixelhop_isLoopback')) {
    /**
     * True untuk alamat loopback lokal.
     */
    function _pixelhop_isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost';
    }
}

if (!function_exists('_pixelhop_ipInCidr')) {
    /**
     * Pencocokan CIDR sederhana, mendukung IPv4 dan IPv6.
     */
    function _pixelhop_ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Keluarga alamat harus sama (IPv4 vs IPv6)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder > 0) {
            $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('_pixelhop_isTrustedProxy')) {
    /**
     * True bila peer langsung (REMOTE_ADDR) adalah proxy tepercaya.
     *
     * Duplikasi ringkas dari daftar CIDR Cloudflare pada includes/ClientIp.php.
     * Sama seperti ClientIp, header forwarding tidak dipercaya dari peer lain.
     */
    function _pixelhop_isTrustedProxy(string $ip): bool
    {
        static $ranges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];

        foreach ($ranges as $range) {
            if (_pixelhop_ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}

// ---------------------------------------------------------------------------
// Helper keamanan umum
// ---------------------------------------------------------------------------

if (!function_exists('clientIsHttps')) {
    /**
     * Deteksi apakah request berjalan di atas HTTPS.
     *
     * Prioritas keamanan: default true (situs publik sudah HTTPS penuh).
     * Hanya false untuk localhost/127.0.0.1/::1 TANPA HTTPS. Header forwarding
     * hanya dipercaya bila peer langsung adalah trusted proxy.
     */
    function clientIsHttps(): bool
    {
        // HTTPS langsung (mis. TLS di origin)
        $https = $_SERVER['HTTPS'] ?? '';
        if (!empty($https) && $https !== 'off') {
            return true;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $cfVisitor = $_SERVER['HTTP_CF_VISITOR'] ?? '';

        // Forwarding header yang menandakan HTTPS
        $forwardedHttps = ($forwardedProto === 'https')
            || (strpos($cfVisitor, '"https"') !== false);

        // Hanya percaya forwarding header bila peer-nya trusted proxy
        if ($forwardedHttps && _pixelhop_isTrustedProxy($remoteAddr)) {
            return true;
        }

        // Fallback aman: bila ragu tetap anggap secure=true,
        // kecuali loopback lokal yang jelas-jelas tidak HTTPS.
        if (_pixelhop_isLoopback($remoteAddr)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('e')) {
    /**
     * Alias htmlspecialchars() untuk escaping output HTML.
     */
    function e($s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sendSecurityHeaders')) {
    /**
     * Kirim header keamanan untuk respons HTML.
     *
     * CSP sengaja opsional (tidak dikirim default) agar tidak merusak halaman
     * yang belum disesuaikan. Panggil hanya untuk respons HTML.
     */
    function sendSecurityHeaders(?string $csp = null): void
    {
        static $sent = false;

        if ($sent || headers_sent()) {
            return;
        }
        $sent = true;

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // CSP opsional — kirim hanya bila eksplisit diminta.
        if ($csp !== null && $csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }
    }
}

// ---------------------------------------------------------------------------
// Bootstrap sesi
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'domain' => '',
        'secure' => clientIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Regenerasi ID sesi berkala (tiap 30 menit) untuk cegah fiksasi sesi.
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}
