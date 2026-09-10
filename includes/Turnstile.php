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
                'site_key'   => $loaded['site_key']   ?? getenv('TURNSTILE_SITE_KEY')   ?: '',
                'secret_key' => $loaded['secret_key'] ?? getenv('TURNSTILE_SECRET_KEY') ?: '',
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
            return [
                'success' => true,
                'message' => 'Turnstile not configured (development mode)',
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

        return [
            'success' => true,
            'message' => 'Verification successful',
            'hostname' => $result['hostname'] ?? null,
        ];
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
