<?php
/**
 * PixelHop - Trusted client IP resolver
 *
 * Prevents X-Forwarded-For / CF-Connecting-IP spoofing: proxy headers are
 * only honored when the direct peer (REMOTE_ADDR) is itself a trusted proxy.
 * Otherwise the untrusted peer address is used, so an attacker cannot forge
 * an arbitrary IP to bypass per-IP rate limits or blocks.
 *
 * The trusted-proxy set defaults to Cloudflare's published ranges (this app
 * fronts everything through Cloudflare). Override via config/security.php:
 *
 *   return [
 *       'trust_proxy_headers' => true,          // set false to always use REMOTE_ADDR
 *       'trusted_proxies'     => ['10.0.0.0/8'] // CIDRs replacing the CF defaults
 *   ];
 */

class ClientIp
{
    /** Cloudflare published IP ranges (IPv4 + IPv6) */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private static ?array $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            $configFile = __DIR__ . '/../config/security.php';
            $loaded = is_file($configFile) ? require $configFile : [];

            self::$config = [
                'trust_proxy_headers' => $loaded['trust_proxy_headers'] ?? true,
                'trusted_proxies'     => $loaded['trusted_proxies'] ?? self::CLOUDFLARE_RANGES,
            ];
        }

        return self::$config;
    }

    /**
     * Resolve the real client IP.
     */
    public static function get(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $config = self::config();

        // If we don't trust proxy headers, or the direct peer is not a known
        // proxy, the only trustworthy value is REMOTE_ADDR itself.
        if (!$config['trust_proxy_headers'] || !self::isTrustedProxy($remoteAddr, $config['trusted_proxies'])) {
            return $remoteAddr;
        }

        // Peer is a trusted proxy → honor its forwarding headers.
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])
            && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Left-most entry is the original client
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])
            && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $remoteAddr;
    }

    /**
     * Is the given IP within any of the trusted-proxy CIDR ranges?
     */
    private static function isTrustedProxy(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::ipInCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * CIDR match supporting both IPv4 and IPv6.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
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

        // Address families must match (both v4 or both v6)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        // Compare full bytes
        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        // Compare remaining bits
        if ($remainder > 0) {
            $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
