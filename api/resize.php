<?php
/**
 * PixelHop - Image Resize API (Imagick)
 * High-quality image resizing with animated GIF/WebP support
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - width: Target width (optional)
 * - height: Target height (optional)
 * - mode: fit|fill|exact (default: fit)
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
if (!$gatekeeper->getSetting('tool_resize_enabled', 1)) {
    jsonError('This tool is currently disabled for maintenance.', 503);
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce(getCurrentUserId());
$rateLimiter->addHeaders(getCurrentUserId());

try {
    $handler = new ImageHandler();


    $imageData = getImageInput($handler);


    $targetWidth = isset($_POST['width']) ? (int) $_POST['width'] : null;
    $targetHeight = isset($_POST['height']) ? (int) $_POST['height'] : null;
    $mode = $_POST['mode'] ?? 'fit';
    $quality = max(10, min(100, (int) ($_POST['quality'] ?? 90)));
    $outputFormat = $_POST['format'] ?? 'original';
    $preserveMetadata = (bool) ($_POST['preserve_metadata'] ?? false);
    $returnType = $_POST['return'] ?? 'download';


    if (!$targetWidth && !$targetHeight) {
        jsonError('Please specify width and/or height', 400);
    }

    $maxDimension = 8000;
    if (($targetWidth && $targetWidth > $maxDimension) || ($targetHeight && $targetHeight > $maxDimension)) {
        jsonError("Maximum dimension is {$maxDimension}px", 400);
    }


    if (!in_array($mode, ['fit', 'fill', 'exact'])) {
        jsonError('Invalid mode. Allowed: fit, fill, exact', 400);
    }


    $imagick = $handler->createImagick($imageData['path']);
    $isAnimated = $handler->isAnimated($imagick);


    $origWidth = $imagick->getImageWidth();
    $origHeight = $imagick->getImageHeight();
    $originalSize = $imageData['size'];


    list($newWidth, $newHeight, $cropX, $cropY, $cropWidth, $cropHeight) = calculateDimensions(
        $origWidth, $origHeight, $targetWidth, $targetHeight, $mode
    );


    if ($isAnimated) {
        $imagick = $imagick->coalesceImages();

        foreach ($imagick as $frame) {
            processFrame($frame, $mode, $newWidth, $newHeight, $cropX, $cropY, $cropWidth, $cropHeight);
        }

        $imagick = $imagick->deconstructImages();
    } else {
        processFrame($imagick, $mode, $newWidth, $newHeight, $cropX, $cropY, $cropWidth, $cropHeight);
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
    $downloadName = $originalName . '_' . $newWidth . 'x' . $newHeight . '.' . $extension;


    $imagick->clear();
    $imagick->destroy();


    $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);
    $gatekeeper->recordToolUsage('resize', getCurrentUserId() ?? 0, $originalSize, $processingTimeMs, 'success');


    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, $outputMime, getCurrentUserId(), 'resize');
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
            'new_width' => $newWidth,
            'new_height' => $newHeight,
            'new_size' => $outputSize,
            'format' => $extension,
            'animated' => $isAnimated,
            'mode' => $mode,
            'data' => 'data:' . $outputMime . ';base64,' . base64_encode($outputData),
            'view_url' => $viewUrl,
            'filename' => $downloadName
        ]);
    } else {
        header('Content-Type: ' . $outputMime);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Original-Size: ' . $originalSize);
        header('X-New-Size: ' . $outputSize);
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    error_log('Resize error: ' . $e->getMessage());
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
 * Calculate dimensions based on mode
 */
function calculateDimensions(
    int $origWidth,
    int $origHeight,
    ?int $targetWidth,
    ?int $targetHeight,
    string $mode
): array {
    $cropX = 0;
    $cropY = 0;
    $cropWidth = $origWidth;
    $cropHeight = $origHeight;

    switch ($mode) {
        case 'exact':
            $newWidth = $targetWidth ?: $origWidth;
            $newHeight = $targetHeight ?: $origHeight;
            break;

        case 'fill':
            $newWidth = $targetWidth ?: $origWidth;
            $newHeight = $targetHeight ?: $origHeight;

            $ratio = max($newWidth / $origWidth, $newHeight / $origHeight);
            $cropWidth = (int) ($newWidth / $ratio);
            $cropHeight = (int) ($newHeight / $ratio);
            $cropX = (int) (($origWidth - $cropWidth) / 2);
            $cropY = (int) (($origHeight - $cropHeight) / 2);
            break;

        case 'fit':
        default:
            if ($targetWidth && $targetHeight) {
                $ratio = min($targetWidth / $origWidth, $targetHeight / $origHeight);
            } elseif ($targetWidth) {
                $ratio = $targetWidth / $origWidth;
            } else {
                $ratio = $targetHeight / $origHeight;
            }

            $newWidth = (int) round($origWidth * $ratio);
            $newHeight = (int) round($origHeight * $ratio);
            break;
    }

    return [$newWidth, $newHeight, $cropX, $cropY, $cropWidth, $cropHeight];
}

/**
 * Process single frame
 */
function processFrame(
    Imagick $frame,
    string $mode,
    int $newWidth,
    int $newHeight,
    int $cropX,
    int $cropY,
    int $cropWidth,
    int $cropHeight
): void {
    if ($mode === 'fill') {

        $frame->cropImage($cropWidth, $cropHeight, $cropX, $cropY);
        $frame->setImagePage($cropWidth, $cropHeight, 0, 0);
    }


    $frame->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
    $frame->setImagePage($newWidth, $newHeight, 0, 0);
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
