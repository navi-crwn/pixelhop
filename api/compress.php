<?php
/**
 * PixelHop - Image Compress API (Imagick)
 * Compress images with quality control
 *
 * POST Parameters:
 * - image: File upload OR
 * - url: URL to fetch image from
 * - quality: 10-100 (default: 80)
 * - format: original|webp|jpg (default: original, webp recommended)
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
if (!$gatekeeper->getSetting('tool_compress_enabled', 1)) {
    jsonError('This tool is currently disabled for maintenance.', 503);
}

// Rate limiting
$rateLimiter = new RateLimiter();
$rateLimiter->enforce(getCurrentUserId());
$rateLimiter->addHeaders(getCurrentUserId());

try {
    $handler = new ImageHandler();


    $imageData = getImageInput($handler);


    $quality = max(10, min(100, (int) ($_POST['quality'] ?? 80)));
    $outputFormat = $_POST['format'] ?? 'original';
    $preserveMetadata = (bool) ($_POST['preserve_metadata'] ?? false);
    $returnType = $_POST['return'] ?? 'download';


    $imagick = $handler->createImagick($imageData['path']);
    $isAnimated = $handler->isAnimated($imagick);


    $origWidth = $imagick->getImageWidth();
    $origHeight = $imagick->getImageHeight();
    $originalSize = $imageData['size'];
    $sourceFormat = strtolower($imagick->getImageFormat());


    if ($outputFormat === 'original') {
        $targetFormat = $sourceFormat;

        if ($targetFormat === 'jpeg') $targetFormat = 'jpeg';
    } else {
        $targetFormat = strtolower($outputFormat);
        if ($targetFormat === 'jpg') $targetFormat = 'jpeg';
    }


    if ($targetFormat === 'webp') {
        $supported = Imagick::queryFormats('WEBP');
        if (empty($supported)) {

            $targetFormat = 'jpeg';
        }
    }


    $supportedFormats = ['jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'];
    if (!in_array($targetFormat, $supportedFormats)) {
        $targetFormat = 'jpeg';
    }


    try {
        $testFormat = Imagick::queryFormats(strtoupper($targetFormat));
        if (empty($testFormat)) {
            $targetFormat = 'jpeg';
        }
    } catch (Exception $e) {
        $targetFormat = 'jpeg';
    }


    if ($isAnimated) {
        $imagick = $imagick->coalesceImages();

        foreach ($imagick as $frame) {
            optimizeFrame($frame, $targetFormat, $quality);
        }


        if ($targetFormat === 'gif') {
            $imagick = $imagick->optimizeImageLayers();
        } else {
            $imagick = $imagick->deconstructImages();
        }
    } else {
        optimizeFrame($imagick, $targetFormat, $quality);
    }


    if (!$preserveMetadata) {
        $handler->stripMetadata($imagick);
    }


    if ($targetFormat === 'jpeg') {
        $imagick->setImageBackgroundColor('white');
        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        if (!$isAnimated) {
            $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }
    }


    try {
        $imagick->setImageFormat($targetFormat);
    } catch (ImagickException $e) {

        $targetFormat = 'jpeg';
        $imagick->setImageFormat('jpeg');
    }


    if ($isAnimated && in_array($targetFormat, ['gif', 'webp'])) {
        $outputData = $imagick->getImagesBlob();
    } else {
        $outputData = $imagick->getImageBlob();
    }

    $outputSize = strlen($outputData);
    $savings = $originalSize - $outputSize;
    $savingsPercent = round(($savings / $originalSize) * 100, 1);


    $mimeMap = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    $outputMime = $mimeMap[$targetFormat] ?? 'image/jpeg';
    $extension = $targetFormat === 'jpeg' ? 'jpg' : $targetFormat;


    $originalName = $imageData['original_name'] ?? pathinfo($imageData['filename'], PATHINFO_FILENAME);
    $downloadName = $originalName . '_compressed.' . $extension;


    $imagick->clear();
    $imagick->destroy();


    $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);
    $gatekeeper->recordToolUsage('compress', getCurrentUserId() ?? 0, $originalSize, $processingTimeMs, 'success');


    $viewUrl = null;
    if ($returnType === 'json') {
        $tempResult = $gatekeeper->saveTempResult($outputData, $downloadName, $outputMime, getCurrentUserId(), 'compress');
        if ($tempResult) {
            $viewUrl = $tempResult['view_url'];
        }
    }

    if ($returnType === 'json') {
        echo json_encode([
            'success' => true,
            'original_size' => $originalSize,
            'new_size' => $outputSize,
            'savings' => $savings,
            'savings_percent' => $savingsPercent,
            'width' => $origWidth,
            'height' => $origHeight,
            'source_format' => $sourceFormat === 'jpeg' ? 'jpg' : $sourceFormat,
            'output_format' => $extension,
            'quality' => $quality,
            'animated' => $isAnimated,
            'data' => 'data:' . $outputMime . ';base64,' . base64_encode($outputData),
            'view_url' => $viewUrl,
            'filename' => $downloadName
        ]);
    } else {
        header('Content-Type: ' . $outputMime);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $outputSize);
        header('X-Original-Size: ' . $originalSize);
        header('X-Savings: ' . $savings);
        header('X-Savings-Percent: ' . $savingsPercent . '%');
        echo $outputData;
    }

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    error_log('Compress error: ' . $e->getMessage());
    jsonError('Processing failed: ' . $e->getMessage(), 500);
}

/**
 * Optimize a single frame
 */
function optimizeFrame(Imagick $frame, string $format, int $quality): void
{

    if (in_array($format, ['jpeg', 'webp'])) {
        $frame->setImageCompressionQuality($quality);

        if ($format === 'jpeg') {
            $frame->setImageCompression(Imagick::COMPRESSION_JPEG);
            $frame->setSamplingFactors(['2x2', '1x1', '1x1']);
            $frame->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }
    } elseif ($format === 'png') {

        $pngLevel = (int) round((100 - $quality) / 10);
        $frame->setImageCompressionQuality($pngLevel * 10);
        $frame->setInterlaceScheme(Imagick::INTERLACE_PNG);
    } elseif ($format === 'gif') {

        if ($quality < 80) {
            $colors = (int) (256 * ($quality / 100));
            $colors = max(16, $colors);
            $frame->quantizeImage($colors, Imagick::COLORSPACE_SRGB, 0, false, false);
        }
    }
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
