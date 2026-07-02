<?php
/**
 * PixelHop - Temp Result Viewer
 * Serves processed tool results registered in temp_files (see Gatekeeper::saveTempResult)
 * GET /view-temp.php?id=<file_id>
 */

require_once __DIR__ . '/includes/Database.php';

$fileId = $_GET['id'] ?? '';

// file_id is generated with bin2hex(random_bytes(16)) → 32 hex chars
if (!preg_match('/^[a-f0-9]{32}$/', $fileId)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

try {
    $file = Database::fetchOne(
        'SELECT file_path, file_name, mime_type, original_name, file_size
         FROM temp_files
         WHERE file_id = ? AND expires_at > NOW()
         LIMIT 1',
        [$fileId]
    );
} catch (Exception $e) {
    error_log('view-temp: lookup failed - ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Server error');
}

if (!$file) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('File not found or expired');
}

// Only serve files that still live inside the temp directory
$tempRoot = realpath(__DIR__ . '/temp');
$realPath = realpath($file['file_path']);

if (!$tempRoot || !$realPath || strpos($realPath, $tempRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('File not found or expired');
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$mime = in_array($file['mime_type'], $allowedMimes, true) ? $file['mime_type'] : 'application/octet-stream';

$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name'] ?: $file['file_name']);
$disposition = $mime === 'application/octet-stream' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
