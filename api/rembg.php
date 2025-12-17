<?php
/**
 * PixelHop - Background Remover API (rembg)
 * Removes background from images using AI
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - model: u2net|u2netp|u2net_human_seg|silueta (default: u2net)
 * - return: download|json (default: download)
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
if (!$gatekeeper->getSetting('tool_rembg_enabled', 1)) {
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
    jsonError('Please login to use AI-powered background removal. It\'s free!', 401);
}

// Quota enforcement
$currentUser = getCurrentUser();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';
$isUserAdmin = ($currentUser['role'] ?? '') === 'admin';

if (!$isUserAdmin) {
    require_once __DIR__ . '/../includes/Database.php';
    $db = Database::getInstance();


    $rembgLimit = (int)$gatekeeper->getSetting($isPremium ? 'daily_removebg_limit_premium' : 'daily_removebg_limit_free', $isPremium ? 30 : 3);


    $today = date('Y-m-d');
    $usageStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND tool_name = 'rembg' AND DATE(created_at) = ?");
    $usageStmt->execute([getCurrentUserId(), $today]);
    $usedToday = (int)$usageStmt->fetchColumn();

    if ($usedToday >= $rembgLimit) {
        jsonError('Daily quota exceeded. You have used ' . $usedToday . '/' . $rembgLimit . ' Remove BG operations today. ' . ($isPremium ? '' : 'Upgrade to Premium for 30 uses/day!'), 429);
    }
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce(getCurrentUserId());
$rateLimiter->addHeaders(getCurrentUserId());

try {
    $handler = new ImageHandler();
    $aiService = new AiService(60);


    $imageData = getImageInput($handler);


    $model = $_POST['model'] ?? 'u2net';
    $returnType = $_POST['return'] ?? 'download';


    $validModels = array_keys(AiService::getRembgModels());
    if (!in_array($model, $validModels)) {
        $model = 'u2net';
    }


    $outputPath = $handler->generateTempPath('png');


    $result = $aiService->removeBackground($imageData['path'], $outputPath, $model);


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


    if (!file_exists($result['output_path'])) {
        jsonError('Output file not generated', 500);
    }

    $outputData = file_get_contents($result['output_path']);
    $outputSize = strlen($outputData);


    $originalName = $imageData['original_name'] ?? pathinfo($imageData['filename'], PATHINFO_FILENAME);
    $downloadName = $originalName . '_nobg.png';


    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, 'image/png', getCurrentUserId(), 'rembg');
        if ($tempResult) {
            $viewUrl = $tempResult['view_url'];
        }
    }


    $processingTimeMs = $result['duration_ms'] ?? 0;
    $gatekeeper->recordToolUsage('rembg', getCurrentUserId() ?? 0, $imageData['size'], $processingTimeMs, 'success');

    if ($returnType === 'json') {
        echo json_encode([
            'success' => true,
            'original_size' => $result['input_size'],
            'new_size' => $outputSize,
            'width' => $result['width'],
            'height' => $result['height'],
            'model' => $model,
            'duration_ms' => $result['duration_ms'],
            'data' => 'data:image/png;base64,' . base64_encode($outputData),
            'view_url' => $viewUrl,
            'filename' => $downloadName,
        ]);
    } else {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Model: ' . $model);
        header('X-Duration-Ms: ' . $result['duration_ms']);
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    error_log('Rembg error: ' . $e->getMessage());
    jsonError('Background removal failed: ' . $e->getMessage(), 500);
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
