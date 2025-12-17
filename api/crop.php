<?php
/**
 * PixelHop - Image Crop API (Imagick)
 * Crop images with position, dimensions, or aspect ratio
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - x: Crop start X position (default: 0)
 * - y: Crop start Y position (default: 0)
 * - width: Crop width (optional)
 * - height: Crop height (optional)
 * - aspect: Aspect ratio preset (1:1, 4:3, 16:9, etc.)
 * - quality: 10-100 (default: 90)
 * - format: original|webp|png|jpg (default: original)
 * - preserve_metadata: 0|1 (default: 0)
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
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

// Check if tool is disabled
$gatekeeper = new Gatekeeper();
if (!$gatekeeper->getSetting('tool_crop_enabled', 1)) {
    jsonError('This tool is currently disabled for maintenance.', 503);
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce(getCurrentUserId());
$rateLimiter->addHeaders(getCurrentUserId());

// Aspect ratio presets
$aspectRatios = [
    '1:1' => 1,
    '4:3' => 4/3,
    '3:4' => 3/4,
    '16:9' => 16/9,
    '9:16' => 9/16,
    '3:2' => 3/2,
    '2:3' => 2/3,
    '21:9' => 21/9,
    '5:4' => 5/4,
    '4:5' => 4/5,
];

try {
    $handler = new ImageHandler();


    $imageData = getImageInput($handler);


    $x = isset($_POST['x']) ? (int) $_POST['x'] : 0;
    $y = isset($_POST['y']) ? (int) $_POST['y'] : 0;
    $cropWidth = isset($_POST['width']) ? (int) $_POST['width'] : null;
    $cropHeight = isset($_POST['height']) ? (int) $_POST['height'] : null;
    $aspectRatio = $_POST['aspect'] ?? null;
    $quality = max(10, min(100, (int) ($_POST['quality'] ?? 90)));
    $outputFormat = $_POST['format'] ?? 'original';
    $preserveMetadata = (bool) ($_POST['preserve_metadata'] ?? false);
    $returnType = $_POST['return'] ?? 'download';


    $imagick = $handler->createImagick($imageData['path']);
    $isAnimated = $handler->isAnimated($imagick);


    $origWidth = $imagick->getImageWidth();
    $origHeight = $imagick->getImageHeight();
    $originalSize = $imageData['size'];


    if ($aspectRatio && isset($aspectRatios[$aspectRatio])) {
        $ratio = $aspectRatios[$aspectRatio];

        if ($origWidth / $origHeight > $ratio) {

            $cropHeight = $origHeight;
            $cropWidth = (int) round($cropHeight * $ratio);
        } else {

            $cropWidth = $origWidth;
            $cropHeight = (int) round($cropWidth / $ratio);
        }


        if (!isset($_POST['x'])) {
            $x = (int) round(($origWidth - $cropWidth) / 2);
        }
        if (!isset($_POST['y'])) {
            $y = (int) round(($origHeight - $cropHeight) / 2);
        }
    } else {

        $cropWidth = $cropWidth ?: ($origWidth - $x);
        $cropHeight = $cropHeight ?: ($origHeight - $y);
    }


    $x = max(0, min($x, $origWidth - 1));
    $y = max(0, min($y, $origHeight - 1));
    $cropWidth = min($cropWidth, $origWidth - $x);
    $cropHeight = min($cropHeight, $origHeight - $y);

    if ($cropWidth <= 0 || $cropHeight <= 0) {
        jsonError('Invalid crop dimensions', 400);
    }


    if ($isAnimated) {
        $imagick = $imagick->coalesceImages();

        foreach ($imagick as $frame) {
            $frame->cropImage($cropWidth, $cropHeight, $x, $y);
            $frame->setImagePage($cropWidth, $cropHeight, 0, 0);
        }

        $imagick = $imagick->deconstructImages();
    } else {
        $imagick->cropImage($cropWidth, $cropHeight, $x, $y);
        $imagick->setImagePage($cropWidth, $cropHeight, 0, 0);
    }


    if (!$preserveMetadata) {
        $handler->stripMetadata($imagick);
    }


    $currentFormat = strtolower($imagick->getImageFormat());
    if ($outputFormat === 'original') {
        $targetFormat = $currentFormat;
    } else {
        $targetFormat = strtolower($outputFormat);
        if ($targetFormat === 'jpg') $targetFormat = 'jpeg';
    }


    $imagick->setImageFormat($targetFormat);

    if (in_array($targetFormat, ['jpeg', 'webp'])) {
        $imagick->setImageCompressionQuality($quality);
    } elseif ($targetFormat === 'png') {
        $pngLevel = (int) round((100 - $quality) / 10);
        $imagick->setImageCompressionQuality($pngLevel * 10);
    }


    if ($isAnimated && in_array($targetFormat, ['gif', 'webp'])) {
        $outputData = $imagick->getImagesBlob();
    } else {
        $outputData = $imagick->getImageBlob();
    }

    $outputSize = strlen($outputData);


    $mimeMap = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    $outputMime = $mimeMap[$targetFormat] ?? 'image/jpeg';
    $extension = $targetFormat === 'jpeg' ? 'jpg' : $targetFormat;


    $originalName = $imageData['original_name'] ?? pathinfo($imageData['filename'], PATHINFO_FILENAME);
    $downloadName = $originalName . '_cropped.' . $extension;


    $imagick->clear();
    $imagick->destroy();


    $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);
    $gatekeeper->recordToolUsage('crop', getCurrentUserId() ?? 0, $originalSize, $processingTimeMs, 'success');


    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, $outputMime, getCurrentUserId(), 'crop');
        if ($tempResult) {
            $viewUrl = $tempResult['view_url'];
        }
    }

    if ($returnType === 'json') {
        echo json_encode([
            'success' => true,
            'original_width' => $origWidth,
            'original_height' => $origHeight,
            'original_size' => $originalSize,
            'crop_x' => $x,
            'crop_y' => $y,
            'crop_width' => $cropWidth,
            'crop_height' => $cropHeight,
            'new_size' => $outputSize,
            'format' => $extension,
            'animated' => $isAnimated,
            'aspect' => $aspectRatio,
            'data' => 'data:' . $outputMime . ';base64,' . base64_encode($outputData),
            'view_url' => $viewUrl,
            'filename' => $downloadName
        ]);
    } else {
        header('Content-Type: ' . $outputMime);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Crop-Region: ' . $x . ',' . $y . ',' . $cropWidth . ',' . $cropHeight);
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    error_log('Crop error: ' . $e->getMessage());
    jsonError('Processing failed: ' . $e->getMessage(), 500);
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
