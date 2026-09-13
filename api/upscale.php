<?php
/**
 * PixelHop - AI HD Upscale API (Real-ESRGAN via ONNX Runtime)
 * Upscales images 2x/4x without losing quality.
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - scale: 2|4 (default: 2)
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
require_once __DIR__ . '/../includes/UpscaleRunner.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/../includes/ClientIp.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

// Check Gatekeeper: Maintenance Mode and Kill Switch
$gatekeeper = new Gatekeeper();

// Check if tool is disabled
if (!$gatekeeper->getSetting('tool_upscale_enabled', 1)) {
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
    jsonError('Please login to use AI-powered HD upscaling. It\'s free!', 401);
}

// Quota enforcement (atomic claim)
$currentUser = getCurrentUser();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';
$isUserAdmin = ($currentUser['role'] ?? '') === 'admin';
$userId = getCurrentUserId();

$usageClaimId = null;

if (!$isUserAdmin) {
    $db = Database::getInstance();
    $upscaleLimit = (int)$gatekeeper->getSetting($isPremium ? 'upscale_limit_premium' : 'upscale_limit_free', $isPremium ? 30 : 3);

    // Single atomic statement: INSERT succeeds only when the user is still
    // under their daily limit, so concurrent requests cannot all pass a
    // SELECT COUNT(*) check before any of them records usage. The claim
    // starts as 'failed' and is flipped to 'success' on completion or
    // DELETEd as a refund.
    $claimStmt = $db->prepare("
        INSERT INTO usage_logs (user_id, tool_name, status, ip_address, created_at)
        SELECT ?, 'upscale', 'failed', ?, NOW()
        FROM DUAL
        WHERE (SELECT COUNT(*) FROM usage_logs
               WHERE user_id = ? AND tool_name = 'upscale' AND DATE(created_at) = CURDATE()) < ?
    ");
    $claimStmt->execute([$userId, ClientIp::get(), $userId, $upscaleLimit]);

    if ($claimStmt->rowCount() === 0) {
        jsonError('Daily quota exceeded. You have reached your ' . $upscaleLimit . ' AI HD Upscale operations limit for today. ' . ($isPremium ? '' : 'Upgrade to Premium for 30 uses/day!'), 429);
    }

    $usageClaimId = (int)$db->lastInsertId();
}

// Heavy-tool gate: CPU load + concurrency protection before launching Python
$heavy = $gatekeeper->canRunHeavyTool('upscale', $userId ?: null);
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
    $upscaleRunner = new UpscaleRunner();

    $imageData = getImageInput($handler);

    $scale = (int)($_POST['scale'] ?? 2);
    if ($scale !== 2 && $scale !== 4) {
        $scale = 2;
    }

    $returnType = $_POST['return'] ?? 'json';

    $outputPath = $handler->generateTempPath('png');

    $result = $upscaleRunner->run($imageData['path'], $outputPath, $scale, 120);

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
            'error' => 'AI upscaling failed. Please try again.',
            'load_info' => [
                'duration_ms' => $result['duration_ms'] ?? 0,
                'code' => $code,
            ],
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
    $downloadName = $originalName . '_upscaled_' . $scale . 'x.png';

    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, 'image/png', getCurrentUserId(), 'upscale');
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
    $gatekeeper->incrementToolDisplayCounter('upscale', getCurrentUserId());

    if ($returnType === 'json') {
        $payload = [
            'success' => true,
            'original_size' => $result['input_size'] ?? $imageData['size'],
            'new_size' => $outputSize,
            'original_width' => $result['width'] ?? $imageData['width'],
            'original_height' => $result['height'] ?? $imageData['height'],
            'new_width' => $result['output_width'] ?? 0,
            'new_height' => $result['output_height'] ?? 0,
            'scale' => $scale,
            'duration_ms' => $result['duration_ms'],
            'view_url' => $viewUrl,
            'filename' => $downloadName,
        ];

        // Inline base64 data only when explicitly requested AND the
        // output is 3 MB or smaller.  For larger outputs the processing
        // already succeeded, so we must NOT fail with a 413 — instead
        // flag data_omitted and let the client fall back to view_url.
        if (($_POST['include_data'] ?? '') === '1') {
            if ($outputSize <= 3 * 1024 * 1024) {
                $payload['data'] = 'data:image/png;base64,' . base64_encode($outputData);
            } else {
                $payload['data_omitted'] = true;
            }
        }

        echo json_encode($payload);
    } else {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Scale: ' . $scale);
        header('X-Duration-Ms: ' . $result['duration_ms']);
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('Upscale error (InvalidArgumentException): ' . $e->getMessage());
    jsonError('Invalid input.', 400);
} catch (Exception $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('Upscale error: ' . $e->getMessage());
    jsonError('AI upscaling failed. Please try again later.', 500);
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
