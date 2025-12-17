<?php
/**
 * PixelHop - Image Convert API (Imagick)
 * Convert images between formats with animated GIF/WebP support
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - format: jpeg|jpg|png|webp|gif|bmp|tiff (required)
 * - quality: 10-100 (default: 90)
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
if (!$gatekeeper->getSetting('tool_convert_enabled', 1)) {
    jsonError('This tool is currently disabled for maintenance.', 503);
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce(getCurrentUserId());
$rateLimiter->addHeaders(getCurrentUserId());

try {
    $handler = new ImageHandler();


    $imageData = getImageInput($handler);


    $targetFormat = strtolower($_POST['format'] ?? 'webp');
    $quality = max(10, min(100, (int) ($_POST['quality'] ?? 90)));
    $preserveMetadata = (bool) ($_POST['preserve_metadata'] ?? false);
    $returnType = $_POST['return'] ?? 'download';


    if ($targetFormat === 'jpg') $targetFormat = 'jpeg';


    $validFormats = ['jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff'];
    if (!in_array($targetFormat, $validFormats)) {
        jsonError('Invalid format. Allowed: ' . implode(', ', $validFormats), 400);
    }


    $imagick = $handler->createImagick($imageData['path']);
    $isAnimated = $handler->isAnimated($imagick);
    $sourceFormat = strtolower($imagick->getImageFormat());


    $width = $imagick->getImageWidth();
    $height = $imagick->getImageHeight();
    $originalSize = $imageData['size'];


    $handler->autoOrient($imagick);


    if ($isAnimated) {
        $imagick = $imagick->coalesceImages();


        if (!in_array($targetFormat, ['gif', 'webp'])) {
            $imagick = $imagick->getImage();
            $isAnimated = false;
        } else {
            foreach ($imagick as $frame) {
                $frame->setImageFormat($targetFormat);
                setQuality($frame, $targetFormat, $quality);
            }
            $imagick = $imagick->deconstructImages();
        }
    }


    if (in_array($targetFormat, ['jpeg', 'bmp'])) {
        $imagick->setImageBackgroundColor('white');
        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    }


    $imagick->setImageFormat($targetFormat);
    setQuality($imagick, $targetFormat, $quality);


    if (!$preserveMetadata) {
        $handler->stripMetadata($imagick);
    }


    if ($isAnimated) {
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
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
    ];
    $outputMime = $mimeMap[$targetFormat] ?? 'image/jpeg';
    $extension = $targetFormat === 'jpeg' ? 'jpg' : $targetFormat;


    $originalName = $imageData['original_name'] ?? pathinfo($imageData['filename'], PATHINFO_FILENAME);
    $downloadName = $originalName . '.' . $extension;


    $imagick->clear();
    $imagick->destroy();


    $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);
    $gatekeeper->recordToolUsage('convert', getCurrentUserId() ?? 0, $originalSize, $processingTimeMs, 'success');


    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, $outputMime, getCurrentUserId(), 'convert');
        if ($tempResult) {
            $viewUrl = $tempResult['view_url'];
        }
    }

    if ($returnType === 'json') {
        echo json_encode([
            'success' => true,
            'source_format' => $sourceFormat === 'jpeg' ? 'jpg' : $sourceFormat,
            'target_format' => $extension,
            'original_size' => $originalSize,
            'new_size' => $outputSize,
            'width' => $width,
            'height' => $height,
            'animated' => $isAnimated,
            'size_change' => $outputSize - $originalSize,
            'size_change_percent' => round((($outputSize - $originalSize) / $originalSize) * 100, 1),
            'data' => 'data:' . $outputMime . ';base64,' . base64_encode($outputData),
            'view_url' => $viewUrl,
            'filename' => $downloadName
        ]);
    } else {
        header('Content-Type: ' . $outputMime);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Source-Format: ' . $sourceFormat);
        header('X-Original-Size: ' . $originalSize);
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    error_log('Convert error: ' . $e->getMessage());
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
 * Set quality based on format
 */
function setQuality(Imagick $imagick, string $format, int $quality): void
{
    if (in_array($format, ['jpeg', 'webp'])) {
        $imagick->setImageCompressionQuality($quality);
    } elseif ($format === 'png') {
        $pngLevel = (int) round((100 - $quality) / 10);
        $imagick->setImageCompressionQuality($pngLevel * 10);
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
