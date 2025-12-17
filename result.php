<?php
/**
 * PixelHop - Batch Upload Result Page
 * One-time use page for displaying batch upload results
 */

// Get result ID from URL
$resultId = $_GET['id'] ?? '';
$errorCount = intval($_GET['errors'] ?? 0);

// If no IDs and no errors, redirect home
if (empty($resultId) && $errorCount === 0) {
    header('Location: /');
    exit;
}

// Load config
$config = require __DIR__ . '/config/s3.php';

// Load images data
$dataFile = __DIR__ . '/data/images.json';
$images = [];

if (!empty($resultId) && file_exists($dataFile)) {
    $allImages = json_decode(file_get_contents($dataFile), true) ?: [];


    $imageIds = explode(',', $resultId);

    foreach ($imageIds as $id) {
        $id = trim($id);
        if (isset($allImages[$id])) {
            $images[$id] = $allImages[$id];
        }
    }
}

// If no images found AND no errors to show, redirect
if (empty($images) && $errorCount === 0) {
    header('Location: /?error=no_results');
    exit;
}

// Calculate totals
$totalSize = 0;
$totalImages = count($images);
$earliestExpiry = null;

foreach ($images as $img) {
    $totalSize += $img['size'] ?? 0;
    if (isset($img['delete_at']) && $img['delete_at']) {
        if ($earliestExpiry === null || $img['delete_at'] < $earliestExpiry) {
            $earliestExpiry = $img['delete_at'];
        }
    }
}

// Format file size
function resultFormatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// Format date
function resultFormatDate($timestamp) {
    if (!$timestamp) return 'Never';
    return date('M d, Y H:i', $timestamp);
}

// Format relative time
function resultFormatRelative($timestamp) {
    if (!$timestamp) return 'Never';
    $diff = $timestamp - time();
    if ($diff < 0) return 'Expired';
    if ($diff < 3600) return round($diff / 60) . ' minutes';
    if ($diff < 86400) return round($diff / 3600) . ' hours';
    if ($diff < 604800) return round($diff / 86400) . ' days';
    return round($diff / 604800) . ' weeks';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Upload Complete - PixelHop</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Theme Detection -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            const theme = savedTheme || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'neon-cyan': '#22d3ee',
                        'neon-purple': '#a855f7',
                        'neon-pink': '#ec4899',
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: var(--color-bg-primary);
            min-height: 100vh;
        }

        .result-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .result-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .result-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-text-primary);
            margin-bottom: 0.5rem;
        }

        .result-header p {
            color: var(--color-text-secondary);
        }

        .thumbnail-strip {
            display: flex;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            backdrop-filter: blur(24px);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .thumbnail-item {
            flex-shrink: 0;
            width: 80px;
            height: 80px;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            position: relative;
        }

        .thumbnail-item:hover {
            border-color: var(--neon-cyan);
            transform: scale(1.05);
        }

        .thumbnail-item.active {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 20px rgba(34, 211, 238, 0.3);
        }

        .thumbnail-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .thumbnail-item .thumb-index {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(0,0,0,0.7);
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .image-details-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            backdrop-filter: blur(24px);
            overflow: hidden;
        }

        .image-item {
            border-bottom: 1px solid var(--glass-border);
        }

        .image-item:last-child {
            border-bottom: none;
        }

        .image-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .image-header:hover {
            background: var(--glass-bg-hover);
        }

        .image-header .preview {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .image-header .preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-header .info {
            flex: 1;
            min-width: 0;
        }

        .image-header .info h3 {
            font-weight: 600;
            color: var(--color-text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .image-header .info p {
            font-size: 0.875rem;
            color: var(--color-text-tertiary);
        }

        .image-header .chevron {
            color: var(--color-text-tertiary);
            transition: transform 0.3s ease;
        }

        .image-item.expanded .chevron {
            transform: rotate(180deg);
        }

        .image-details {
            display: none;
            padding: 0 1rem 1rem 1rem;
            animation: slideDown 0.3s ease;
        }

        .image-item.expanded .image-details {
            display: block;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .link-group {
            background: var(--color-bg-tertiary);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
        }

        .link-group:last-child {
            margin-bottom: 0;
        }

        .link-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--color-text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .link-input-group {
            display: flex;
            gap: 0.5rem;
        }

        .link-input {
            flex: 1;
            background: var(--color-bg-primary);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            color: var(--color-text-primary);
            font-size: 0.875rem;
            font-family: 'Monaco', 'Consolas', monospace;
        }

        .copy-btn {
            padding: 0.625rem 1rem;
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-purple));
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .copy-btn:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 15px rgba(34, 211, 238, 0.3);
        }

        .copy-btn.copied {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .summary-card {
            margin-top: 1.5rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            backdrop-filter: blur(24px);
            padding: 1.5rem;
        }

        .summary-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .summary-item {
            text-align: center;
            padding: 1rem;
            background: var(--color-bg-tertiary);
            border-radius: 12px;
        }

        .summary-item .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--neon-cyan);
            margin-bottom: 0.25rem;
        }

        .summary-item .label {
            font-size: 0.75rem;
            color: var(--color-text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-purple));
            color: white;
            border: none;
        }

        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(34, 211, 238, 0.3);
        }

        .action-btn-secondary {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            color: var(--color-text-primary);
        }

        .action-btn-secondary:hover {
            background: var(--glass-bg-hover);
            border-color: var(--neon-cyan);
        }

        .expire-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(239, 68, 68, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .expire-warning i {
            color: #f59e0b;
        }

        .expire-warning span {
            color: var(--color-text-secondary);
            font-size: 0.875rem;
        }

        /* Copy All Section */
        .copy-all-section {
            background: linear-gradient(135deg, rgba(34, 211, 238, 0.1), rgba(168, 85, 247, 0.1));
            border: 1px solid rgba(34, 211, 238, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .copy-all-section span {
            color: var(--color-text-secondary);
            font-size: 0.875rem;
        }

        .copy-all-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .copy-all-btn {
            padding: 0.5rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            color: var(--color-text-primary);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .copy-all-btn:hover {
            border-color: var(--neon-cyan);
            background: var(--glass-bg-hover);
        }

        /* Error Section */
        .error-section {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(185, 28, 28, 0.1));
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .error-section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .error-section-header i {
            color: #ef4444;
        }

        .error-section-header h3 {
            color: var(--color-text-primary);
            font-weight: 600;
            margin: 0;
        }

        .error-section-header span {
            color: var(--color-text-tertiary);
            font-size: 0.875rem;
        }

        .error-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .error-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
        }

        .error-item-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
            background: var(--color-bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-item-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .error-item-thumb i {
            color: var(--color-text-tertiary);
        }

        .error-item-info {
            flex: 1;
            min-width: 0;
        }

        .error-item-info .filename {
            color: var(--color-text-primary);
            font-size: 0.875rem;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .error-item-info .reason {
            color: #f87171;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <nav class="max-w-7xl mx-auto">
            <div class="glass-card flex items-center justify-between px-6 py-3">
                <a href="/" class="flex items-center gap-3 group">
                    <div class="w-10 h-10">
                        <img src="/assets/img/logo.svg" alt="PixelHop" class="w-full h-full">
                    </div>
                    <span class="text-xl font-bold" style="color: var(--color-text-primary);">PixelHop</span>
                </a>
                <a href="/" class="action-btn action-btn-secondary text-sm">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Upload More
                </a>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="pt-24 pb-12">
        <div class="result-container">

            <!-- Header -->
            <div class="result-header">
                <div class="flex items-center justify-center gap-3 mb-4">
                    <?php if ($totalImages > 0): ?>
                    <div class="w-16 h-16 rounded-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center">
                        <i data-lucide="check" class="w-8 h-8 text-white"></i>
                    </div>
                    <?php else: ?>
                    <div class="w-16 h-16 rounded-full bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center">
                        <i data-lucide="x" class="w-8 h-8 text-white"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($totalImages > 0): ?>
                <h1>Upload Complete!</h1>
                <p><?php echo $totalImages; ?> image<?php echo $totalImages > 1 ? 's' : ''; ?> uploaded successfully<?php echo $errorCount > 0 ? ', ' . $errorCount . ' failed' : ''; ?></p>
                <?php else: ?>
                <h1>Upload Failed</h1>
                <p><?php echo $errorCount; ?> file<?php echo $errorCount > 1 ? 's' : ''; ?> failed to upload</p>
                <?php endif; ?>
            </div>

            <?php if ($totalImages > 0): ?>
            <!-- Thumbnail Strip -->
            <div class="thumbnail-strip" id="thumbnail-strip">
                <?php $i = 0; foreach ($images as $id => $img): $i++; ?>
                <div class="thumbnail-item <?php echo $i === 1 ? 'active' : ''; ?>"
                     data-id="<?php echo $id; ?>"
                     onclick="scrollToImage('<?php echo $id; ?>')">
                    <img src="<?php echo htmlspecialchars($img['urls']['thumb']); ?>" alt="">
                    <span class="thumb-index"><?php echo $i; ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Copy All Section -->
            <div class="copy-all-section">
                <span><i data-lucide="copy" class="w-4 h-4 inline mr-1"></i> Quick copy all links:</span>
                <div class="copy-all-buttons">
                    <button class="copy-all-btn" onclick="copyAllLinks('direct')">
                        <i data-lucide="link" class="w-3 h-3"></i>
                        Direct Links
                    </button>
                    <button class="copy-all-btn" onclick="copyAllLinks('view')">
                        <i data-lucide="eye" class="w-3 h-3"></i>
                        View Links
                    </button>
                    <button class="copy-all-btn" onclick="copyAllLinks('bbcode')">
                        <i data-lucide="code" class="w-3 h-3"></i>
                        BBCode
                    </button>
                    <button class="copy-all-btn" onclick="copyAllLinks('markdown')">
                        <i data-lucide="hash" class="w-3 h-3"></i>
                        Markdown
                    </button>
                </div>
            </div>

            <!-- Image Details (Collapsible) -->
            <div class="image-details-container">
                <?php foreach ($images as $id => $img):
                    $viewUrl = $config['site']['url'] . '/' . $id;
                    $directUrl = $img['urls']['original'];
                    $thumbUrl = $img['urls']['thumb'];
                    $mediumUrl = $img['urls']['medium'];
                ?>
                <div class="image-item" id="image-<?php echo $id; ?>" data-id="<?php echo $id; ?>">
                    <div class="image-header" onclick="toggleDetails('<?php echo $id; ?>')">
                        <div class="preview">
                            <img src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="">
                        </div>
                        <div class="info">
                            <h3><?php echo htmlspecialchars($img['filename'] ?? $id); ?></h3>
                            <p><?php echo resultFormatSize($img['size'] ?? 0); ?> • <?php echo ($img['width'] ?? 0); ?>×<?php echo ($img['height'] ?? 0); ?></p>
                        </div>
                        <i data-lucide="chevron-down" class="w-5 h-5 chevron"></i>
                    </div>
                    <div class="image-details">
                        <!-- View Link -->
                        <div class="link-group">
                            <label>View Page</label>
                            <div class="link-input-group">
                                <input type="text" class="link-input" value="<?php echo htmlspecialchars($viewUrl); ?>" readonly>
                                <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($viewUrl); ?>', this)">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                    Copy
                                </button>
                            </div>
                        </div>

                        <!-- Direct Link -->
                        <div class="link-group">
                            <label>Direct Link (Original)</label>
                            <div class="link-input-group">
                                <input type="text" class="link-input" value="<?php echo htmlspecialchars($directUrl); ?>" readonly>
                                <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($directUrl); ?>', this)">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                    Copy
                                </button>
                            </div>
                        </div>

                        <!-- Thumbnail Link -->
                        <div class="link-group">
                            <label>Thumbnail Link</label>
                            <div class="link-input-group">
                                <input type="text" class="link-input" value="<?php echo htmlspecialchars($thumbUrl); ?>" readonly>
                                <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($thumbUrl); ?>', this)">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                    Copy
                                </button>
                            </div>
                        </div>

                        <!-- BBCode -->
                        <div class="link-group">
                            <label>BBCode</label>
                            <div class="link-input-group">
                                <input type="text" class="link-input" value="[img]<?php echo htmlspecialchars($directUrl); ?>[/img]" readonly>
                                <button class="copy-btn" onclick="copyToClipboard('[img]<?php echo htmlspecialchars($directUrl); ?>[/img]', this)">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                    Copy
                                </button>
                            </div>
                        </div>

                        <!-- Markdown -->
                        <div class="link-group">
                            <label>Markdown</label>
                            <div class="link-input-group">
                                <input type="text" class="link-input" value="![image](<?php echo htmlspecialchars($directUrl); ?>)" readonly>
                                <button class="copy-btn" onclick="copyToClipboard('![image](<?php echo htmlspecialchars($directUrl); ?>)', this)">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                    Copy
                                </button>
                            </div>
                        </div>

                        <!-- HTML -->
                        <div class="link-group">
                            <label>HTML</label>
                            <div class="link-input-group">
                                <input type="text" class="link-input" value='<img src="<?php echo htmlspecialchars($directUrl); ?>" alt="">' readonly>
                                <button class="copy-btn" onclick="copyToClipboard('<img src=&quot;<?php echo htmlspecialchars($directUrl); ?>&quot; alt=&quot;&quot;>', this)">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                    Copy
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Failed Uploads Section (populated by JS from sessionStorage) -->
            <div id="error-section" class="error-section" style="display: none;">
                <div class="error-section-header">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <h3>Failed Uploads</h3>
                    <span id="error-count"></span>
                </div>
                <div id="error-list" class="error-list"></div>
            </div>

            <?php if ($totalImages > 0): ?>
            <!-- Summary Card -->
            <div class="summary-card">
                <h3 class="summary-title">
                    <i data-lucide="bar-chart-2" class="w-5 h-5 text-neon-cyan"></i>
                    Upload Summary
                </h3>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="value"><?php echo $totalImages; ?></div>
                        <div class="label">Images</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo resultFormatSize($totalSize); ?></div>
                        <div class="label">Total Size</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo $earliestExpiry ? resultFormatRelative($earliestExpiry) : 'Never'; ?></div>
                        <div class="label">Expires In</div>
                    </div>
                    <div class="summary-item">
                        <div class="value"><?php echo date('H:i'); ?></div>
                        <div class="label">Uploaded At</div>
                    </div>
                </div>

                <?php if ($earliestExpiry): ?>
                <div class="expire-warning">
                    <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                    <span>These images will be automatically deleted on <strong><?php echo resultFormatDate($earliestExpiry); ?></strong>. Save the links now!</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="/" class="action-btn action-btn-primary">
                    <i data-lucide="upload" class="w-5 h-5"></i>
                    <?php echo $totalImages > 0 ? 'Upload More' : 'Try Again'; ?>
                </a>
                <?php if ($totalImages > 0): ?>
                <button class="action-btn action-btn-secondary" onclick="copyAllLinks('view')">
                    <i data-lucide="copy" class="w-5 h-5"></i>
                    Copy All Links
                </button>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- Custom Toast Notification -->
    <div id="toast" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 px-6 py-3 rounded-full opacity-0 pointer-events-none transition-all duration-300 z-50" style="background: linear-gradient(135deg, var(--neon-cyan), var(--neon-purple));">
        <span class="text-white font-medium flex items-center gap-2">
            <i data-lucide="check" class="w-4 h-4"></i>
            <span id="toast-message">Copied!</span>
        </span>
    </div>

    <script>

        lucide.createIcons();


        const imageData = <?php echo json_encode(array_map(function($id, $img) use ($config) {
            return [
                'id' => $id,
                'view' => $config['site']['url'] . '/' . $id,
                'direct' => $img['urls']['original'],
                'thumb' => $img['urls']['thumb']
            ];
        }, array_keys($images), array_values($images))); ?>;


        function toggleDetails(id) {
            const item = document.getElementById('image-' + id);
            item.classList.toggle('expanded');
            lucide.createIcons();
        }


        function scrollToImage(id) {

            document.querySelectorAll('.thumbnail-item').forEach(el => el.classList.remove('active'));
            document.querySelector(`.thumbnail-item[data-id="${id}"]`)?.classList.add('active');


            const item = document.getElementById('image-' + id);
            if (!item.classList.contains('expanded')) {
                item.classList.add('expanded');
            }
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            lucide.createIcons();
        }


        function copyToClipboard(text, button) {
            navigator.clipboard.writeText(text).then(() => {

                const originalHTML = button.innerHTML;
                button.classList.add('copied');
                button.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Copied';
                lucide.createIcons();

                showToast('Copied to clipboard!');

                setTimeout(() => {
                    button.classList.remove('copied');
                    button.innerHTML = originalHTML;
                    lucide.createIcons();
                }, 2000);
            });
        }


        function copyAllLinks(type) {
            let links = [];

            imageData.forEach((img, index) => {
                switch(type) {
                    case 'direct':
                        links.push(img.direct);
                        break;
                    case 'view':
                        links.push(img.view);
                        break;
                    case 'bbcode':
                        links.push('[img]' + img.direct + '[/img]');
                        break;
                    case 'markdown':
                        links.push('![image](' + img.direct + ')');
                        break;
                }
            });

            navigator.clipboard.writeText(links.join('\n')).then(() => {
                showToast(`${links.length} ${type} links copied!`);
            });
        }


        function showToast(message) {
            const toast = document.getElementById('toast');
            document.getElementById('toast-message').textContent = message;
            toast.classList.remove('opacity-0', 'pointer-events-none');
            toast.classList.add('opacity-100');

            setTimeout(() => {
                toast.classList.add('opacity-0', 'pointer-events-none');
                toast.classList.remove('opacity-100');
            }, 2500);
        }


        document.addEventListener('DOMContentLoaded', () => {



            loadUploadErrors();
        });


        function loadUploadErrors() {
            const errorData = sessionStorage.getItem('uploadErrors');
            if (!errorData) return;

            try {
                const errors = JSON.parse(errorData);
                if (!errors || errors.length === 0) return;

                const errorSection = document.getElementById('error-section');
                const errorList = document.getElementById('error-list');
                const errorCount = document.getElementById('error-count');

                errorCount.textContent = `(${errors.length} file${errors.length > 1 ? 's' : ''})`;

                let html = '';
                errors.forEach(err => {
                    html += `
                        <div class="error-item">
                            <div class="error-item-thumb">
                                ${err.thumb
                                    ? `<img src="${err.thumb}" alt="">`
                                    : '<i data-lucide="image-off" class="w-5 h-5"></i>'}
                            </div>
                            <div class="error-item-info">
                                <div class="filename">${escapeHtml(err.file)}</div>
                                <div class="reason">${escapeHtml(err.error)}</div>
                            </div>
                        </div>
                    `;
                });

                errorList.innerHTML = html;
                errorSection.style.display = 'block';
                lucide.createIcons();


                sessionStorage.removeItem('uploadErrors');

            } catch (e) {
                console.error('Error loading upload errors:', e);
            }
        }


        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
