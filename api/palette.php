<?php
/**
 * PixelHop - Color Palette API
 * Extracts dominant colors from an image using Python k-means clustering.
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - count: Number of colors to extract, 4-10 (default: 6)
 *
 * Always returns JSON.
 *
 * Quota gates (B10 remediation — no double-counting):
 *
 *   Guest (unauthenticated):
 *     1. paletteGuestAllowed() — palette-specific daily per-IP cap
 *        (palette_limit_guest, default 20/day, counts usage_logs rows).
 *     2. RateLimiter::enforce() — short-window IP burst protection.
 *     ► canRunLightTool() is intentionally SKIPPED for guests so the
 *       generic hourly/daily limits (tool_guest_hourly_limit /
 *       tool_guest_daily_limit) do not conflict with the palette-
 *       specific cap.  A single usage_logs row is written via
 *       recordLightToolUsage() and counted by paletteGuestAllowed().
 *
 *   Authenticated user:
 *     1. canRunLightTool() — generic per-user daily limit
 *        (tool_user_daily_limit, default 1000/day via usage_logs).
 *     2. RateLimiter::enforce() — per-user burst protection.
 *     ► paletteGuestAllowed() is skipped (not a guest).
 *       recordLightToolUsage() writes the single usage_logs row
 *       counted by canRunLightTool().
 *
 * Both paths share: maintenance mode, kill switch, tool-enabled checks.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Session bootstrap (secure cookie params + periodic session ID regeneration)
require_once __DIR__ . '/../includes/bootstrap.php';

// Security Firewall Check
require_once __DIR__ . '/../includes/SecurityFirewall.php';
$firewall = new SecurityFirewall();
$firewallCheck = $firewall->check();
if (!$firewallCheck['allowed']) {
    jsonError($firewallCheck['reason'], $firewallCheck['code'] ?? 403);
}

require_once __DIR__ . '/../includes/ImageHandler.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/../includes/ClientIp.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Logger.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

// Check Gatekeeper: Maintenance Mode and Kill Switch
$gatekeeper = new Gatekeeper();

// Check if tool is disabled
if (!$gatekeeper->getSetting('tool_palette_enabled', 1)) {
    jsonError('This tool is currently disabled for maintenance.', 503);
}

if ($gatekeeper->getSetting('maintenance_mode', false)) {
    $currentUser = getCurrentUser();
    if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin') {
        jsonError('System is under maintenance. Please try again later.', 503);
    }
}

if ($gatekeeper->getSetting('kill_switch_active', false)) {
    jsonError('Image tools are temporarily disabled.', 503);
}

$userId = getCurrentUserId();
$clientIP = ClientIp::get();

// ── Quota gate: guest vs authenticated (B10 — no overlapping limits) ──
//
// Guest path:  palette-specific daily cap only (paletteGuestAllowed).
//              canRunLightTool() is NOT called for guests because it
//              enforces generic hourly/daily limits that would conflict
//              with the palette-specific quota and double-count the same
//              usage_logs rows.
//
// Auth path:   canRunLightTool() enforces per-user daily limit.
//              paletteGuestAllowed() is irrelevant for logged-in users.
//
// Both paths still get the short-window RateLimiter burst check below.
if ($userId === null) {
    // ─── Guest gate: palette-specific daily per-IP cap ───
    $paletteGuestLimit = (int) $gatekeeper->getSetting('palette_limit_guest', 20);
    if (!paletteGuestAllowed($clientIP, $paletteGuestLimit)) {
        jsonError('Daily palette limit reached for guests. Please register for more.', 429);
    }
} else {
    // ─── Authenticated gate: generic per-user light-tool limit ───
    $toolCheck = $gatekeeper->canRunLightTool('palette', $userId, $clientIP);
    if (!$toolCheck['allowed']) {
        jsonError($toolCheck['reason'], 429);
    }
}

// Rate limiting (per-IP for guests, per-user for logged-in users)
$rateLimiter = new RateLimiter();
$rateLimiter->enforce($userId);
$rateLimiter->addHeaders($userId);

// Track start time for usage logging
$toolStartTime = microtime(true);

try {
    $handler = new ImageHandler();

    $imageData = getImageInput($handler);

    // 4-10 colors, default 6
    $count = max(4, min(10, (int) ($_POST['count'] ?? 6)));

    $result = runPaletteEngine($imageData['path'], $count);

    if (!$result['success']) {
        Logger::error('palette', $result['error'] ?? 'Palette engine failed', [
            'code' => 500,
            'count' => $count,
        ]);
        jsonError('Color extraction failed. Please try again.', 500);
    }

    $processingTimeMs = (int) ((microtime(true) - $toolStartTime) * 1000);

    $gatekeeper->recordLightToolUsage(
        'palette',
        $userId,
        (int) $imageData['size'],
        $processingTimeMs,
        'success'
    );

    echo json_encode([
        'success' => true,
        'colors' => $result['colors'] ?? [],
        'count' => count($result['colors'] ?? []),
        'duration_ms' => $processingTimeMs,
        'image' => [
            'width' => $imageData['width'] ?? null,
            'height' => $imageData['height'] ?? null,
            'size' => $imageData['size'] ?? null,
            'filename' => $imageData['filename'] ?? 'uploaded',
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    // Detail validasi HANYA ke log server; klien menerima pesan generik.
    Logger::error('palette', $e->getMessage(), ['code' => 400, 'exception' => get_class($e)]);
    jsonError('Invalid image or parameters. Please check your input and try again.', 400);
} catch (Exception $e) {
    // Detail internal HANYA ke log server, tidak ke klien.
    Logger::error('palette', $e->getMessage(), ['code' => 500, 'exception' => get_class($e)]);
    jsonError('Color extraction failed. Please try again later.', 500);
}

/**
 * Get image from upload or URL
 */
function getImageInput(ImageHandler $handler): array
{
    if (!empty($_POST['url'])) {
        return $handler->uploadFromUrl($_POST['url']);
    }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        jsonError('Please upload an image or provide a URL', 400);
    }

    return $handler->processUpload($_FILES['image']);
}

/**
 * Run the Python palette engine and parse JSON output.
 */
function runPaletteEngine(string $inputPath, int $count): array
{
    $pythonBin = '/var/www/pichost/python/venv/bin/python3';
    $scriptPath = __DIR__ . '/../python/palette_engine.py';

    if (!is_file($scriptPath)) {
        return [
            'success' => false,
            'error' => 'Palette engine not available',
        ];
    }

    $command = sprintf(
        'timeout 20s %s %s %s %s 2>/dev/null',
        escapeshellarg($pythonBin),
        escapeshellarg($scriptPath),
        escapeshellarg($inputPath),
        escapeshellarg((string) $count)
    );

    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);

    if (trim($output) === '') {
        return [
            'success' => false,
            'error' => 'Palette engine failed to execute',
        ];
    }

    $result = json_decode(trim($output), true);

    if (!is_array($result)) {
        Logger::error('palette', 'Invalid JSON from palette_engine', ['output' => substr($output, 0, 300)]);
        return [
            'success' => false,
            'error' => 'Palette engine returned invalid response',
        ];
    }

    return $result;
}

/**
 * Guest daily per-IP quota for the palette tool.
 *
 * The tool is intentionally guest-accessible (lightweight, instant, no AI),
 * like compress/resize/crop/convert. We enforce a dedicated daily guest cap
 * (palette_limit_guest, default 20/day/IP) by counting successful usage_logs
 * rows for this tool. Logged-in users are not limited by this function.
 */
function paletteGuestAllowed(string $ip, int $limit): bool
{
    if ($limit <= 0) {
        return false;
    }

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM usage_logs
            WHERE ip_address = ?
              AND tool_name = 'palette'
              AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00')
        ");
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() < $limit;
    } catch (Exception $e) {
        // Fail open for guests only for this light tool; the short-window
        // RateLimiter above still protects the server.
        Logger::error('palette', 'Guest quota check failed: ' . $e->getMessage(), ['code' => 0]);
        return true;
    }
}

/**
 * JSON error response
 */
function jsonError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $message, 'success' => false]);
    exit;
}
