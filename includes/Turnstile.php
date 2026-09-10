<?php
/**
 * PixelHop - Cloudflare Turnstile Integration
 * Spam prevention for forms
 *
 * Setup:
 * 1. Get keys from https://dash.cloudflare.com/turnstile
 * 2. Update SITE_KEY and SECRET_KEY below
 * 3. Add widget to forms with renderTurnstile()
 */

require_once __DIR__ . '/ClientIp.php';

class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var array{site_key:string,secret_key:string}|null */
    private static ?array $config = null;

    /**
     * Load keys from config/turnstile.php, falling back to the
     * TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY environment variables.
     * Keys are never hardcoded in source.
     */
    private static function config(): array
    {
        if (self::$config === null) {
            $configFile = __DIR__ . '/../config/turnstile.php';
            $loaded = is_file($configFile) ? require $configFile : [];

            self::$config = [
                'site_key'         => $loaded['site_key']         ?? getenv('TURNSTILE_SITE_KEY')   ?: '',
                'secret_key'       => $loaded['secret_key']       ?? getenv('TURNSTILE_SECRET_KEY') ?: '',
                'allowed_hostnames' => $loaded['allowed_hostnames'] ?? [],
            ];
        }

        return self::$config;
    }

    private static function siteKey(): string
    {
        return self::config()['site_key'];
    }

    private static function secretKey(): string
    {
        return self::config()['secret_key'];
    }

    /**
     * Check if Turnstile is configured
     */
    public static function isConfigured(): bool
    {
        $c = self::config();
        return !empty($c['site_key']) && !empty($c['secret_key'])
            && $c['site_key'] !== 'YOUR_SITE_KEY_HERE'
            && $c['secret_key'] !== 'YOUR_SECRET_KEY_HERE';
    }

    /**
     * Verify Turnstile token from form submission
     */
    public static function verify(?string $token = null): array
    {

        if (!self::isConfigured()) {
            // Fail closed. Dev/test environments may explicitly opt out.
            if (getenv('TURNSTILE_ALLOW_UNCONFIGURED') === '1') {
                return [
                    'success' => true,
                    'message' => 'Turnstile bypassed (TURNSTILE_ALLOW_UNCONFIGURED=1)',
                ];
            }

            return [
                'success' => false,
                'message' => 'Captcha service is not configured',
            ];
        }


        if ($token === null) {
            $token = $_POST['cf-turnstile-response'] ?? '';
        }

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Please complete the security challenge',
            ];
        }


        $ip = self::getClientIp();


        $data = [
            'secret' => self::secretKey(),
            'response' => $token,
            'remoteip' => $ip,
        ];

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);

        if ($error) {
            error_log('Turnstile verification error: ' . $error);
            return [
                'success' => false,
                'message' => 'Security verification failed. Please try again.',
            ];
        }

        $result = json_decode($response, true);

        if (!$result || !isset($result['success'])) {
            return [
                'success' => false,
                'message' => 'Invalid security response',
            ];
        }

        if (!$result['success']) {
            $errorCodes = $result['error-codes'] ?? [];
            error_log('Turnstile failed: ' . implode(', ', $errorCodes));

            return [
                'success' => false,
                'message' => 'Security challenge failed. Please try again.',
                'error_codes' => $errorCodes,
            ];
        }

        // Verify that the challenge was solved for an allowed hostname.
        if (self::isHostnameMismatch($result['hostname'] ?? '')) {
            error_log('Turnstile hostname mismatch: ' . ($result['hostname'] ?? ''));
            return [
                'success' => false,
                'message' => 'Captcha hostname mismatch',
            ];
        }

        return [
            'success' => true,
            'message' => 'Verification successful',
            'hostname' => $result['hostname'] ?? null,
        ];
    }

    /**
     * Check the hostname returned by Cloudflare against the configured
     * allow-list. When no allow-list is configured, fall back to the current
     * request's HTTP_HOST.
     */
    private static function isHostnameMismatch(string $hostname): bool
    {
        if ($hostname === '') {
            // Cloudflare does not always return a hostname for all
            // verification modes; only enforce it when one is present.
            return false;
        }

        $allowed = self::config()['allowed_hostnames'] ?? [];
        if (!is_array($allowed)) {
            $allowed = [];
        }

        if (!empty($allowed)) {
            foreach ($allowed as $allowedHost) {
                if (hash_equals($allowedHost, $hostname)) {
                    return false;
                }
            }
            return true;
        }

        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        return $httpHost === '' ? false : !hash_equals($httpHost, $hostname);
    }

    /**
     * Get HTML for Turnstile widget
     * Theme will be set dynamically via JavaScript based on localStorage
     */
    public static function renderWidget(string $theme = 'auto'): string
    {
        if (!self::isConfigured()) {
            return '<!-- Turnstile not configured -->';
        }


        return sprintf(
            '<div id="turnstile-container" class="cf-turnstile" data-sitekey="%s" data-theme="%s"></div>
            <script>
            (function() {
                var savedTheme = localStorage.getItem("pixelhop-theme") || "dark";
                var turnstileTheme = savedTheme === "light" ? "light" : "dark";
                var container = document.getElementById("turnstile-container");
                if (container) {
                    container.setAttribute("data-theme", turnstileTheme);
                }
            })();
            </script>',
            htmlspecialchars(self::siteKey()),
            htmlspecialchars($theme)
        );
    }

    /**
     * Get Turnstile script tag
     */
    public static function getScript(): string
    {
        if (!self::isConfigured()) {
            return '';
        }

        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    /**
     * Get site key for JavaScript usage
     */
    public static function getSiteKey(): string
    {
        return self::isConfigured() ? self::siteKey() : '';
    }

    /**
     * Get client IP
     */
    private static function getClientIp(): string
    {
        return ClientIp::get();
    }
}
