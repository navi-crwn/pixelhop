<?php
/**
 * PixelHop - Face Blur API (OpenCV Haar cascade)
 * Blurs or pixelates detected faces for privacy.
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - method: blur|pixelate (default: blur)
 * - strength: 1-10 (default: 5)
 * - include_data: 1 to inline the output as a data URL (<= 3MB)
 *
 * Always returns JSON.
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

// Must match AiService::PYTHON_BIN so production keeps one venv path.
// AiService has no public faceblur wrapper yet, so this endpoint uses the
// same exec/timeout pattern directly without modifying AiService.
define('FACE_BLUR_PYTHON_BIN', '/var/www/pichost/python/venv/bin/python3');

// Check Gatekeeper: Maintenance Mode and Kill Switch
$gatekeeper = new Gatekeeper();

// Check if tool is disabled
if (!$gatekeeper->getSetting('tool_faceblur_enabled', 1)) {
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
    jsonError('Please login to use Face Blur. It\'s free!', 401);
}

// Quota enforcement (atomic claim)
$currentUser = getCurrentUser();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';
$isUserAdmin = ($currentUser['role'] ?? '') === 'admin';
$userId = getCurrentUserId();

$usageClaimId = null;

if (!$isUserAdmin) {
    $db = Database::getInstance();
    $faceblurLimit = (int)$gatekeeper->getSetting($isPremium ? 'faceblur_limit_premium' : 'faceblur_limit_free', $isPremium ? 100 : 10);

    // Single atomic statement. INSERT succeeds only when the user is still
    // under their daily limit, so concurrent requests cannot all pass the
    // old SELECT COUNT(*) check before any of them records usage.
    // usage_logs.status starts as 'failed' and is flipped to 'success' on
    // completion or DELETEd as a refund.
    $claimStmt = $db->prepare("
        INSERT INTO usage_logs (user_id, tool_name, status, ip_address, created_at)
        SELECT ?, 'faceblur', 'failed', ?, NOW()
        FROM DUAL
        WHERE (SELECT COUNT(*) FROM usage_logs
               WHERE user_id = ? AND tool_name = 'faceblur' AND DATE(created_at) = CURDATE()) < ?
    ");
    $claimStmt->execute([$userId, ClientIp::get(), $userId, $faceblurLimit]);

    if ($claimStmt->rowCount() === 0) {
        jsonError('Daily quota exceeded. You have reached your ' . $faceblurLimit . ' Face Blur operations limit for today. ' . ($isPremium ? '' : 'Upgrade to Premium for 100 uses/day!'), 429);
    }

    $usageClaimId = (int)$db->lastInsertId();
}

// Heavy-tool gate: CPU load + concurrency protection before launching Python
$heavy = $gatekeeper->canRunHeavyTool('faceblur', $userId ?: null);
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
    $aiService = new AiService(30);

    $imageData = getImageInput($handler);

    $method = $_POST['method'] ?? 'blur';
    if (!in_array($method, ['blur', 'pixelate'], true)) {
        $method = 'blur';
    }

    $strength = (int)($_POST['strength'] ?? 5);
    $strength = max(1, min(10, $strength));

    $outputPath = $handler->generateTempPath('jpg');

    $result = runFaceBlurEngine($imageData['path'], $outputPath, $method, $strength);

    if (!$result['success']) {
        // Refund the atomic claim so a failed attempt does not consume quota.
        if ($usageClaimId !== null) {
            $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
            $refundStmt->execute([$usageClaimId]);
        }

        // Business rule: no faces is a normal, explainable outcome.
        if (($result['error'] ?? '') === 'no_face') {
            jsonError($result['message'] ?? 'Tidak ada wajah terdeteksi pada gambar ini.', 422);
        }

        $code = $result['code'] ?? 500;
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => 'Face blur processing failed. Please try again.',
            'load_info' => $aiService->getLoadInfo(),
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
    $downloadName = $originalName . '_faceblur.jpg';

    $viewUrl = null;
    $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, 'image/jpeg', getCurrentUserId(), 'faceblur');
    if ($tempResult) {
        $viewUrl = $tempResult['view_url'];
    }

    $processingTimeMs = $result['duration_ms'] ?? 0;

    if ($usageClaimId !== null) {
        // Mark the claim as a completed, successful audit record.
        $finalizeStmt = $db->prepare("UPDATE usage_logs SET status = 'success', file_size = ?, processing_time_ms = ? WHERE id = ?");
        $finalizeStmt->execute([$imageData['size'], $processingTimeMs, $usageClaimId]);
    }

    // Display counter only — the atomic claim row IS the audit record.
    $gatekeeper->incrementToolDisplayCounter('faceblur', getCurrentUserId());

    $payload = [
        'success' => true,
        'faces' => (int)($result['faces'] ?? 0),
        'method' => $method,
        'strength' => $strength,
        'original_size' => $imageData['size'],
        'new_size' => $outputSize,
        'width' => $imageData['width'],
        'height' => $imageData['height'],
        'duration_ms' => $result['duration_ms'],
        'view_url' => $viewUrl,
        'filename' => $downloadName,
    ];

    // Keep memory bounded: only inline data when explicitly requested AND
    // the output is 3MB or smaller. For larger outputs the processing
    // already succeeded, so we must NOT fail with a 413 — instead
    // flag data_omitted and let the client fall back to view_url.
    if (($_POST['include_data'] ?? '') === '1') {
        if ($outputSize > 3 * 1024 * 1024) {
            $payload['data_omitted'] = true;
        } else {
            $payload['data'] = 'data:image/jpeg;base64,' . base64_encode($outputData);
        }
    }

    echo json_encode($payload);

} catch (InvalidArgumentException $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('Faceblur error (InvalidArgumentException): ' . $e->getMessage());
    jsonError('Invalid input.', 400);
} catch (Exception $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('Faceblur error: ' . $e->getMessage());
    jsonError('Face blur processing failed. Please try again later.', 500);
}

/**
 * Run the Python face blur engine and parse its JSON stdout.
 *
 * Uses the same exec/timeout pattern as AiService::executeCommand without
 * modifying AiService itself. Fail-closed: empty stdout, invalid JSON, or a
 * GNU timeout exit (124) all produce a non-success result.
 */
function runFaceBlurEngine(string $inputPath, string $outputPath, string $method, int $strength): array
{
    $scriptPath = __DIR__ . '/../python/faceblur_engine.py';

    if (!file_exists($scriptPath)) {
        return [
            'success' => false,
            'error' => 'Face blur engine not available',
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

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $command = sprintf(
        'timeout %ds %s %s %s %s %s %s 2>/dev/null',
        30,
        escapeshellarg(FACE_BLUR_PYTHON_BIN),
        escapeshellarg($scriptPath),
        escapeshellarg($inputPath),
        escapeshellarg($outputPath),
        escapeshellarg($method),
        escapeshellarg((string)$strength)
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
            'error' => 'Face blur timed out after 30 seconds',
            'code' => 504,
            'duration_ms' => $duration,
        ];
    }

    // The Python engine prints a JSON payload even on failure (then exits 1),
    // so only bail out when there is nothing to parse.
    if (trim($output) === '') {
        return [
            'success' => false,
            'error' => 'Face blur failed to execute',
            'code' => 500,
            'duration_ms' => $duration,
        ];
    }

    $result = json_decode(trim($output), true);

    if ($result === null) {
        error_log('Face blur: Invalid JSON output from engine: ' . substr($output, 0, 500));
        return [
            'success' => false,
            'error' => 'Face blur returned invalid response',
            'code' => 500,
            'duration_ms' => $duration,
            'raw_output' => substr($output, 0, 200),
        ];
    }

    $result['duration_ms'] = $duration;
    $result['output_path'] = $outputPath;

    return $result;
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
 * JSON error response
 */
function jsonError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $message, 'success' => false]);
    exit;
}
