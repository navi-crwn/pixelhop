<?php
/**
 * PixelHop - Magic Eraser API (LaMa inpainting)
 * Erases objects / watermarks marked by a user-painted mask.
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - mask: base64-encoded PNG mask (white pixels = erase area)
 * - return: download|json (default: json)
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
require_once __DIR__ . '/../includes/AiService.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/../includes/ClientIp.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

// Check Gatekeeper: Maintenance Mode and Kill Switch
$gatekeeper = new Gatekeeper();

// Check if tool is disabled
if (!$gatekeeper->getSetting('tool_erase_enabled', 1)) {
    jsonError('This tool is currently disabled for maintenance.', 503);
}

if ($gatekeeper->getSetting('maintenance_mode', false)) {

    $currentUser = getCurrentUser();
    if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin') {
        jsonError('System is under maintenance. Please try again later.', 503);
    }
}

if ($gatekeeper->getSetting('kill_switch_active', false)) {
    jsonError('AI tools are temporarily disabled.', 503);
}

// AI tools require login
if (!isAuthenticated()) {
    jsonError('Please login to use Magic Eraser. It\'s free!', 401);
}

// Quota enforcement (atomic claim)
$currentUser = getCurrentUser();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';
$isUserAdmin = ($currentUser['role'] ?? '') === 'admin';
$userId = getCurrentUserId();

$usageClaimId = null;
$db = Database::getInstance();

if (!$isUserAdmin) {
    $eraseLimit = (int)$gatekeeper->getSetting($isPremium ? 'erase_limit_premium' : 'erase_limit_free', $isPremium ? 30 : 3);

    // Single atomic statement. INSERT succeeds only when the user is still
    // under their daily limit, so concurrent requests cannot all pass the
    // old SELECT COUNT(*) check before any of them records usage.
    // usage_logs.status starts as 'failed' and is flipped to 'success' on
    // completion or DELETEd as a refund.
    $claimStmt = $db->prepare("
        INSERT INTO usage_logs (user_id, tool_name, status, ip_address, created_at)
        SELECT ?, 'erase', 'failed', ?, NOW()
        FROM DUAL
        WHERE (SELECT COUNT(*) FROM usage_logs
               WHERE user_id = ? AND tool_name = 'erase' AND DATE(created_at) = CURDATE()) < ?
    ");
    $claimStmt->execute([$userId, ClientIp::get(), $userId, $eraseLimit]);

    if ($claimStmt->rowCount() === 0) {
        jsonError('Daily quota exceeded. You have reached your ' . $eraseLimit . ' Magic Eraser operations limit for today. ' . ($isPremium ? '' : 'Upgrade to Premium for 30 uses/day!'), 429);
    }

    $usageClaimId = (int)$db->lastInsertId();
}

// Heavy-tool gate: CPU load + concurrency protection before launching Python
$heavy = $gatekeeper->canRunHeavyTool('erase', $userId ?: null);
if (!($heavy['allowed'] ?? true)) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    jsonError($heavy['reason'] ?? 'Server busy, please try again later.', 503);
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce($userId);
$rateLimiter->addHeaders($userId);

try {
    $handler = new ImageHandler();

    $imageData = getImageInput($handler);

    $maskPath = saveMaskInput($handler);
    $returnType = $_POST['return'] ?? 'json';

    $outputPath = $handler->generateTempPath('png');

    $result = runEraseEngine($imageData['path'], $maskPath, $outputPath);

    if (!$result['success']) {
        // Refund the atomic claim so a failed attempt does not consume quota.
        if ($usageClaimId !== null) {
            $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
            $refundStmt->execute([$usageClaimId]);
        }
        $code = $result['code'] ?? 500;
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => 'Magic Eraser failed. Please try again.',
            'load_info' => getLoadInfo(),
        ]);
        exit;
    }

    if (!file_exists($result['output_path'])) {
        if ($usageClaimId !== null) {
            $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
            $refundStmt->execute([$usageClaimId]);
        }
        jsonError('Output file not generated', 500);
    }

    $outputData = file_get_contents($result['output_path']);
    $outputSize = strlen($outputData);

    $originalName = $imageData['original_name'] ?? pathinfo($imageData['filename'], PATHINFO_FILENAME);
    $downloadName = $originalName . '_erased.png';

    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, 'image/png', getCurrentUserId(), 'erase');
        if ($tempResult) {
            $viewUrl = $tempResult['view_url'];
        }
    }

    $processingTimeMs = $result['duration_ms'] ?? 0;

    if ($usageClaimId !== null) {
        // Mark the claim as a completed, successful audit record.
        $finalizeStmt = $db->prepare("UPDATE usage_logs SET status = 'success', file_size = ?, processing_time_ms = ? WHERE id = ?");
        $finalizeStmt->execute([$imageData['size'], $processingTimeMs, $usageClaimId]);
    }

    // Display counter only — the atomic claim row IS the audit record.
    $gatekeeper->incrementToolDisplayCounter('erase', getCurrentUserId());

    if ($returnType === 'json') {
        $payload = [
            'success' => true,
            'original_size' => $imageData['size'],
            'new_size' => $outputSize,
            'width' => $result['width'],
            'height' => $result['height'],
            'duration_ms' => $result['duration_ms'],
            'view_url' => $viewUrl,
            'filename' => $downloadName,
        ];

        // Keep memory bounded: only inline data when explicitly requested
        // AND the PNG is 3MB or smaller. Larger successful outputs stay
        // available through view_url and are marked as omitted inline data.
        if (($_POST['include_data'] ?? '') === '1') {
            if ($outputSize > 3 * 1024 * 1024) {
                $payload['data_omitted'] = true;
            } else {
                $payload['data'] = 'data:image/png;base64,' . base64_encode($outputData);
            }
        }

        echo json_encode($payload);
    } else {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Model: lama');
        header('X-Duration-Ms: ' . $result['duration_ms']);
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('Erase error (InvalidArgumentException): ' . $e->getMessage());
    jsonError('Invalid input.', 400);
} catch (Exception $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('Erase error: ' . $e->getMessage());
    jsonError('Magic Eraser failed. Please try again later.', 500);
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
 * Decode the brush mask sent by the frontend.
 *
 * Primary contract: base64-encoded PNG string in $_POST['mask']
 *   (with or without the data:image/…;base64, prefix).
 *
 * Resilient fallback: multipart file upload in $_FILES['mask']
 *   (MIME image/png or image/jpeg, max 10 MB). Cleans up PHP's tmp
 *   file on failure to prevent stale temp files.
 */
function saveMaskInput(ImageHandler $handler): string
{
    $maskPost = $_POST['mask'] ?? '';

    // ── Primary path: base64 string in POST body ──────────────────────
    if ($maskPost !== '') {
        // Accept both raw base64 and data:image/png;base64,...
        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $maskPost)) {
            $maskPost = substr($maskPost, strpos($maskPost, ',') + 1);
        }

        $maskData = base64_decode($maskPost, true);
        if ($maskData === false || $maskData === '') {
            jsonError('Invalid mask data.', 400);
        }

        if (strlen($maskData) > 10 * 1024 * 1024) {
            jsonError('Mask image is too large.', 413);
        }

        $maskPath = $handler->generateTempPath('png');

        if (file_put_contents($maskPath, $maskData) === false) {
            jsonError('Failed to save mask image.', 500);
        }

        $mime = $handler->detectMimeType($maskPath);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            @unlink($maskPath);
            jsonError('Mask must be a PNG image.', 400);
        }

        return $maskPath;
    }

    // ── Fallback: multipart file upload ($_FILES['mask']) ─────────────
    if (isset($_FILES['mask']) && $_FILES['mask']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['mask']['error'] !== UPLOAD_ERR_OK) {
            jsonError('Mask upload failed (code ' . $_FILES['mask']['error'] . ').', 400);
        }

        $tmpPath = $_FILES['mask']['tmp_name'];

        // Size gate before any further processing
        $fileSize = filesize($tmpPath);
        if ($fileSize === false || $fileSize > 10 * 1024 * 1024) {
            @unlink($tmpPath);
            jsonError('Mask image is too large.', 413);
        }

        // MIME validation on actual file content (not the client-provided type)
        $mime = $handler->detectMimeType($tmpPath);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            @unlink($tmpPath);
            jsonError('Mask must be a PNG or JPEG image.', 400);
        }

        $maskPath = $handler->generateTempPath('png');
        if (!move_uploaded_file($tmpPath, $maskPath)) {
            @unlink($tmpPath);
            jsonError('Failed to save mask image.', 500);
        }

        return $maskPath;
    }

    jsonError('Please paint over the area you want to erase first.', 400);
}

/**
 * Run python/erase_engine.py using the same exec/timeout + JSON contract as
 * UpscaleRunner, without modifying AiService (whose PYTHON_BIN stays fixed).
 */
function runEraseEngine(string $inputPath, string $maskPath, string $outputPath): array
{
    $pythonBin = '/var/www/pichost/python/venv/bin/python3';
    $scriptPath = __DIR__ . '/../python/erase_engine.py';

    if (!file_exists($scriptPath)) {
        return [
            'success' => false,
            'error' => 'Erase engine not available',
            'code' => 500,
        ];
    }

    if (!file_exists($inputPath)) {
        return [
            'success' => false,
            'error' => 'Image file not found',
            'code' => 400,
        ];
    }

    if (!file_exists($maskPath)) {
        return [
            'success' => false,
            'error' => 'Mask file not found',
            'code' => 400,
        ];
    }

    $timeout = 120;
    $command = sprintf(
        'timeout %ds %s %s %s %s %s 2>/dev/null',
        $timeout,
        escapeshellarg($pythonBin),
        escapeshellarg($scriptPath),
        escapeshellarg($inputPath),
        escapeshellarg($maskPath),
        escapeshellarg($outputPath)
    );

    $startTime = microtime(true);
    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);

    $duration = round((microtime(true) - $startTime) * 1000);

    // GNU timeout exits with 124 when the command timed out.
    if ($exitCode === 124) {
        return [
            'success' => false,
            'error' => 'Magic Eraser timed out after ' . $timeout . ' seconds',
            'code' => 504,
            'duration_ms' => $duration,
        ];
    }

    // The Python engine prints JSON even on failure (then exits 1).
    if (trim($output) === '') {
        return [
            'success' => false,
            'error' => 'Magic Eraser failed to execute',
            'code' => 500,
            'duration_ms' => $duration,
        ];
    }

    $result = json_decode(trim($output), true);

    if ($result === null) {
        error_log('Erase engine: Invalid JSON output: ' . substr($output, 0, 500));
        return [
            'success' => false,
            'error' => 'Magic Eraser returned invalid response',
            'code' => 500,
            'duration_ms' => $duration,
            'raw_output' => substr($output, 0, 200),
        ];
    }

    $result['duration_ms'] = $duration;

    return $result;
}

/**
 * System load info for AI error payloads.
 */
function getLoadInfo(): array
{
    $load = sys_getloadavg();

    return [
        'load_1min' => $load[0] ?? 0,
        'load_5min' => $load[1] ?? 0,
        'load_15min' => $load[2] ?? 0,
        'threshold' => 3.0,
        'available' => ($load[0] ?? 0) < 3.0,
    ];
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
