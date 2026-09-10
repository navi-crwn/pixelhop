<?php
/**
 * PixelHop - OCR API (PaddleOCR)
 * Extracts text from images using PaddleOCR via Python
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - language: Language code (default: en)
 * - return: json (default) - always returns JSON
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
if (!$gatekeeper->getSetting('tool_ocr_enabled', 1)) {
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
    jsonError('Please login to use OCR text extraction. It\'s free!', 401);
}

// Quota enforcement (atomic claim)
$currentUser = getCurrentUser();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';
$isUserAdmin = ($currentUser['role'] ?? '') === 'admin';
$userId = getCurrentUserId();

$usageClaimId = null;

if (!$isUserAdmin) {
    $db = Database::getInstance();
    $ocrLimit = (int)$gatekeeper->getSetting($isPremium ? 'daily_ocr_limit_premium' : 'daily_ocr_limit_free', $isPremium ? 50 : 5);

    // D5-14: single atomic statement. INSERT succeeds only when the user is
    // still under their daily limit, so concurrent requests cannot all pass
    // the old SELECT COUNT(*) check before any of them records usage.
    // usage_logs.status enum has no 'processing'; claim starts as 'failed'
    // and is flipped to 'success' on completion or DELETEd as a refund.
    $claimStmt = $db->prepare("
        INSERT INTO usage_logs (user_id, tool_name, status, ip_address, created_at)
        SELECT ?, 'ocr', 'failed', ?, NOW()
        FROM DUAL
        WHERE (SELECT COUNT(*) FROM usage_logs
               WHERE user_id = ? AND tool_name = 'ocr' AND DATE(created_at) = CURDATE()) < ?
    ");
    $claimStmt->execute([$userId, ClientIp::get(), $userId, $ocrLimit]);

    if ($claimStmt->rowCount() === 0) {
        jsonError('Daily quota exceeded. You have reached your ' . $ocrLimit . ' OCR operations limit for today. ' . ($isPremium ? '' : 'Upgrade to Premium for 50 uses/day!'), 429);
    }

    $usageClaimId = (int)$db->lastInsertId();
}

// Heavy-tool gate (D5-16): CPU load + concurrency protection before launching Python
$heavy = $gatekeeper->canRunHeavyTool('ocr', $userId ?: null);
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


    $language = $_POST['language'] ?? 'en';


    $validLanguages = AiService::getOcrLanguages();


    $langMap = [
        'eng' => 'en',
        'chi_sim' => 'ch',
        'chi_tra' => 'chinese_cht',
        'jpn' => 'japan',
        'kor' => 'korean',
        'fra' => 'fr',
        'deu' => 'german',
        'spa' => 'es',
        'por' => 'pt',
        'ita' => 'it',
        'rus' => 'ru',
        'ara' => 'ar',
        'tha' => 'th',
        'vie' => 'vi',
        'ind' => 'id',
    ];


    if (isset($langMap[$language])) {
        $language = $langMap[$language];
    }

    // Reject anything not in the supported language list
    if (!isset($validLanguages[$language])) {
        $language = 'en';
    }


    $result = $aiService->performOcr($imageData['path'], $language);


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
            'error' => 'OCR processing failed. Please try again.',
            'load_info' => $aiService->getLoadInfo(),
        ]);
        exit;
    }


    $processingTimeMs = $result['duration_ms'] ?? 0;

    if ($usageClaimId !== null) {
        // Mark the claim as a completed, successful audit record.
        $finalizeStmt = $db->prepare("UPDATE usage_logs SET status = 'success', file_size = ?, processing_time_ms = ? WHERE id = ?");
        $finalizeStmt->execute([$imageData['size'], $processingTimeMs, $usageClaimId]);
    }

    $gatekeeper->recordToolUsage('ocr', getCurrentUserId() ?? 0, $imageData['size'], $processingTimeMs, 'success');


    echo json_encode([
        'success' => true,
        'text' => $result['text'],
        'blocks' => $result['blocks'],
        'block_count' => $result['block_count'],
        'language' => $result['language'],
        'average_confidence' => $result['average_confidence'],
        'duration_ms' => $result['duration_ms'],
        'image' => [
            'width' => $imageData['width'],
            'height' => $imageData['height'],
            'size' => $imageData['size'],
            'filename' => $imageData['filename'] ?? 'uploaded',
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('OCR error (InvalidArgumentException): ' . $e->getMessage());
    jsonError('Invalid input.', 400);
} catch (Exception $e) {
    if ($usageClaimId !== null) {
        $refundStmt = $db->prepare("DELETE FROM usage_logs WHERE id = ?");
        $refundStmt->execute([$usageClaimId]);
    }
    error_log('OCR error: ' . $e->getMessage());
    jsonError('OCR processing failed. Please try again later.', 500);
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
