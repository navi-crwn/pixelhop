<?php
/**
 * PixelHop - View Temp File
 * Serves temporary processed files with expiration tracking
 */

// Get file ID from URL
$fileId = $_GET['id'] ?? '';

if (empty($fileId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $fileId)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

require_once __DIR__ . '/includes/Database.php';

$db = Database::getInstance();

// Look up file in temp_files table
$stmt = $db->prepare("SELECT * FROM temp_files WHERE file_id = ? AND expires_at > NOW()");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>File Expired - PixelHop</title>
        <style>
            body { font-family: 'Inter', sans-serif; background: #0a0a0f; color: #fff; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
            .container { text-align: center; padding: 40px; }
            h1 { font-size: 2rem; margin-bottom: 1rem; color: #ef4444; }
            p { color: rgba(255,255,255,0.6); margin-bottom: 2rem; }
            a { color: #22d3ee; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>⏱️ File Expired</h1>
            <p>This temporary file has expired and been automatically deleted.</p>
            <p>Processed files are kept for 6 hours after creation.</p>
            <a href="/">← Back to Home</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Check if physical file exists
if (!file_exists($file['file_path'])) {

    $stmt = $db->prepare("DELETE FROM temp_files WHERE id = ?");
    $stmt->execute([$file['id']]);

    http_response_code(404);
    echo 'File not found on server';
    exit;
}

// Serve the file
$mimeType = $file['mime_type'] ?? 'application/octet-stream';
$filename = $file['original_name'] ?? basename($file['file_path']);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($file['file_path']));
header('Cache-Control: public, max-age=3600');

// For images, display inline; otherwise download
if (strpos($mimeType, 'image/') === 0) {
    header('Content-Disposition: inline; filename="' . $filename . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

readfile($file['file_path']);
