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

require_once __DIR__ . '/../includes/ImageHandler.php';
require_once __DIR__ . '/../includes/AiService.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
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

// Quota enforcement
$currentUser = getCurrentUser();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';
$isUserAdmin = ($currentUser['role'] ?? '') === 'admin';

if (!$isUserAdmin) {
    require_once __DIR__ . '/../includes/Database.php';
    $db = Database::getInstance();


    $ocrLimit = (int)$gatekeeper->getSetting($isPremium ? 'daily_ocr_limit_premium' : 'daily_ocr_limit_free', $isPremium ? 50 : 5);


    $today = date('Y-m-d');
    $usageStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND tool_name = 'ocr' AND DATE(created_at) = ?");
    $usageStmt->execute([getCurrentUserId(), $today]);
    $usedToday = (int)$usageStmt->fetchColumn();

    if ($usedToday >= $ocrLimit) {
        jsonError('Daily quota exceeded. You have used ' . $usedToday . '/' . $ocrLimit . ' OCR operations today. ' . ($isPremium ? '' : 'Upgrade to Premium for 50 uses/day!'), 429);
    }
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce(getCurrentUserId());
$rateLimiter->addHeaders(getCurrentUserId());

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


    $result = $aiService->performOcr($imageData['path'], $language);


    if (!$result['success']) {
        $code = $result['code'] ?? 500;
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $result['error'],
            'load_info' => $aiService->getLoadInfo(),
        ]);
        exit;
    }


    $processingTimeMs = $result['duration_ms'] ?? 0;
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
    jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    error_log('OCR error: ' . $e->getMessage());
    jsonError('OCR processing failed: ' . $e->getMessage(), 500);
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
