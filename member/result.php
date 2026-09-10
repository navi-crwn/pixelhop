<?php
/**
 * PixelHop - Member Result Page
 * Shows results for uploads and tool processing
 * Upload results: accordion style like landing page
 * Tool results: grid style with Download All ZIP
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$type = $_GET['type'] ?? 'upload';
$count = (int)($_GET['count'] ?? 0);

// Whitelist the requested tool. $tool is reflected into the <title>, the page
// heading and the JS download filename, so anything outside the known tool set
// is discarded instead of being echoed back (reflected XSS).
$tool = $_GET['tool'] ?? '';
$allowedTools = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];
if ($tool !== '' && !in_array($tool, $allowedTools, true)) {
    $tool = '';
}

$isToolResult = ($type === 'tool' || !empty($tool));

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $isToolResult ? ucfirst($tool) . ' Results' : 'Upload Complete' ?> - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            if (savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <?php if ($isToolResult): ?>
    <script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
    <?php endif; ?>
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
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: none;
        }
        .action-btn-primary {
            background: linear-gradient(135deg, var(--neon-cyan), var(--neon-purple));
            color: white;
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

        /* Temporary notice styling */
        .temp-notice {
            background: rgba(251, 191, 36, 0.1);
            border: 1px solid rgba(251, 191, 36, 0.3);
        }
        .temp-notice-text {
            color: var(--color-text-secondary);
        }

        /* Upload Result - Accordion Style */
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
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* Tool Results - Grid Style */
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        .result-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            overflow: hidden;
        }
        .result-image {
            aspect-ratio: 4/3;
            background: rgba(0, 0, 0, 0.2);
            position: relative;
        }
        .result-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .result-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(34, 197, 94, 0.9);
            color: #fff;
        }
        .result-info {
            padding: 16px;
        }
        .result-filename {
            font-size: 14px;
            font-weight: 500;
            color: var(--color-text-primary);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .result-meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            color: var(--color-text-tertiary);
            margin-bottom: 12px;
        }
        .result-actions {
            display: flex;
            gap: 8px;
        }
        .result-btn {
            flex: 1;
            padding: 8px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            border: none;
        }
        .result-btn-view {
            background: rgba(34, 211, 238, 0.15);
            color: #22d3ee;
        }
        .result-btn-download {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }
        .result-btn:hover {
            transform: translateY(-1px);
        }
        .text-green { color: #22c55e; }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--color-text-tertiary);
        }
        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
            background: rgba(34, 197, 94, 0.9);
            color: #fff;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        
        /* Theme Toggle */
        .theme-toggle-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            color: var(--color-text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            z-index: 100;
        }
        .theme-toggle-btn:hover {
            background: var(--glass-bg-hover);
            color: var(--color-neon-cyan);
        }
        #theme-icon-light { display: none; }
        #theme-icon-dark { display: block; }
        [data-theme="light"] #theme-icon-light { display: block; }
        [data-theme="light"] #theme-icon-dark { display: none; }
    </style>
</head>
<body>
    <!-- Theme Toggle Button -->
    <button class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Theme">
        <i data-lucide="sun" id="theme-icon-light" class="w-5 h-5"></i>
        <i data-lucide="moon" id="theme-icon-dark" class="w-5 h-5"></i>
    </button>

    <div class="result-container">
        <!-- Header -->
        <div class="result-header">
            <h1>
                <?php if ($isToolResult): ?>
                    <i data-lucide="check-circle" class="inline w-8 h-8 text-green-400 mr-2"></i>
                    <?= ucfirst($tool) ?> Complete
                <?php else: ?>
                    <i data-lucide="check-circle" class="inline w-8 h-8 text-green-400 mr-2"></i>
                    Upload Complete
                <?php endif; ?>
            </h1>
            <p><span id="resultCount"><?= $count ?></span> <?= $count === 1 ? 'image' : 'images' ?> processed</p>
        </div>
        
        <?php if ($isToolResult): ?>
        <!-- Temp Storage Notice -->
        <div class="mb-6 p-4 rounded-lg temp-notice">
            <div class="flex items-start gap-3">
                <i data-lucide="clock" class="w-5 h-5 mt-0.5 flex-shrink-0" style="color: #fbbf24;"></i>
                <div class="text-sm temp-notice-text">
                    <strong style="color: #fbbf24;">⏰ Expires in 6 hours:</strong> 
                    These results are temporary and will be <strong>automatically deleted</strong>. 
                    Download your images now to keep them.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="/dashboard" class="action-btn action-btn-secondary">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
            </a>
            <a href="/gallery" class="action-btn action-btn-secondary">
                <i data-lucide="images" class="w-4 h-4"></i> Gallery
            </a>
            <a href="/member/tools" class="action-btn action-btn-secondary">
                <i data-lucide="wrench" class="w-4 h-4"></i> Tools
            </a>
            <?php if ($isToolResult): ?>
            <button class="action-btn action-btn-secondary" id="downloadAllBtn">
                <i data-lucide="download" class="w-4 h-4"></i> Download All (ZIP)
            </button>
            <?php endif; ?>
            <a href="/member/upload" class="action-btn action-btn-primary">
                <i data-lucide="plus" class="w-4 h-4"></i> Upload More
            </a>
        </div>

        <!-- Results Container -->
        <div id="resultsContainer">
            <div class="empty-state" id="emptyState">
                <i data-lucide="loader" class="w-12 h-12 mx-auto mb-4 opacity-50 animate-spin"></i>
                <p>Loading results...</p>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast"></div>

    <script>
        lucide.createIcons();
        
        // Theme Toggle Function
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('pixelhop-theme', newTheme);
            lucide.createIcons();
        }

        const isToolResult = <?= $isToolResult ? 'true' : 'false' ?>;
        const container = document.getElementById('resultsContainer');
        let results = [];

        // Load results from sessionStorage
        const storedResults = sessionStorage.getItem('uploadResults') || sessionStorage.getItem('toolResults');

        if (storedResults) {
            try {
                results = JSON.parse(storedResults);
                if (isToolResult) {
                    displayToolResults(results);
                } else {
                    displayUploadResults(results);
                }
            } catch (e) {
                showEmpty('Failed to load results');
            }
        } else {
            showEmpty('No results found');
        }

        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2000);
        }

        function formatSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return bytes + ' B';
        }

        function showEmpty(message) {
            container.innerHTML = `
                <div class="empty-state">
                    <i data-lucide="image-off" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                    <p>${message}</p>
                    <a href="/member/upload" class="action-btn action-btn-primary" style="display: inline-flex; margin-top: 16px;">
                        <i data-lucide="upload" class="w-4 h-4"></i> Upload Images
                    </a>
                </div>
            `;
            lucide.createIcons();
        }

        // UPLOAD RESULTS - Accordion Style
        function displayUploadResults(items) {
            if (!items || items.length === 0) {
                showEmpty('No results found');
                return;
            }

            const successItems = items.filter(i => i.success);
            if (successItems.length === 0) {
                showEmpty('No successful uploads');
                return;
            }

            document.getElementById('resultCount').textContent = successItems.length;

            let totalSize = 0;
            successItems.forEach(item => {
                const data = item.data || item;
                totalSize += data.size || 0;
            });

            let html = '';

            // Thumbnail strip
            html += '<div class="thumbnail-strip">';
            successItems.forEach((item, index) => {
                const data = item.data || item;
                const thumbUrl = data.urls?.thumb || data.urls?.medium || data.urls?.original || '';
                html += `
                    <div class="thumbnail-item" data-index="${index}">
                        <img src="${thumbUrl}" alt="" onerror="this.src='/assets/img/placeholder.png'">
                        <span class="thumb-index">${index + 1}</span>
                    </div>
                `;
            });
            html += '</div>';

            // Copy All section
            html += `
                <div class="copy-all-section">
                    <span><i data-lucide="link" class="w-4 h-4 inline mr-1"></i> Copy all links at once</span>
                    <div class="copy-all-buttons">
                        <button class="copy-all-btn" onclick="copyAllLinks('direct')">
                            <i data-lucide="link" class="w-4 h-4"></i> Direct Links
                        </button>
                        <button class="copy-all-btn" onclick="copyAllLinks('html')">
                            <i data-lucide="code" class="w-4 h-4"></i> HTML
                        </button>
                        <button class="copy-all-btn" onclick="copyAllLinks('bbcode')">
                            <i data-lucide="hash" class="w-4 h-4"></i> BBCode
                        </button>
                        <button class="copy-all-btn" onclick="copyAllLinks('markdown')">
                            <i data-lucide="file-text" class="w-4 h-4"></i> Markdown
                        </button>
                    </div>
                </div>
            `;

            // Image details accordion
            html += '<div class="image-details-container">';
            successItems.forEach((item, index) => {
                const data = item.data || item;
                const thumbUrl = data.urls?.thumb || data.urls?.medium || '';
                const originalUrl = data.urls?.original || '';
                const viewUrl = data.view_url || '/' + data.id;
                const filename = data.filename || data.id;
                const siteUrl = window.location.origin;

                const directLink = originalUrl.startsWith('http') ? originalUrl : siteUrl + originalUrl;

                html += `
                    <div class="image-item" data-index="${index}" 
                         data-direct="${encodeURIComponent(directLink)}"
                         data-view="${encodeURIComponent(siteUrl + viewUrl)}"
                         data-filename="${encodeURIComponent(filename)}">
                        <div class="image-header" onclick="toggleExpand(this)">
                            <div class="preview">
                                <img src="${thumbUrl}" alt="" onerror="this.src='/assets/img/placeholder.png'">
                            </div>
                            <div class="info">
                                <h3>${escapeHtml(filename)}</h3>
                                <p>${data.width || '?'}×${data.height || '?'} • ${formatSize(data.size || 0)}</p>
                            </div>
                            <i data-lucide="chevron-down" class="chevron w-5 h-5"></i>
                        </div>
                        <div class="image-details">
                            <div class="link-group">
                                <label>Direct Link</label>
                                <div class="link-input-group">
                                    <input type="text" class="link-input direct-link-input" readonly>
                                    <button class="copy-btn" data-copy="direct">
                                        <i data-lucide="copy" class="w-4 h-4"></i> Copy
                                    </button>
                                </div>
                            </div>
                            <div class="link-group">
                                <label>HTML Embed</label>
                                <div class="link-input-group">
                                    <input type="text" class="link-input html-embed-input" readonly>
                                    <button class="copy-btn" data-copy="html">
                                        <i data-lucide="copy" class="w-4 h-4"></i> Copy
                                    </button>
                                </div>
                            </div>
                            <div class="link-group">
                                <label>BBCode</label>
                                <div class="link-input-group">
                                    <input type="text" class="link-input bbcode-input" readonly>
                                    <button class="copy-btn" data-copy="bbcode">
                                        <i data-lucide="copy" class="w-4 h-4"></i> Copy
                                    </button>
                                </div>
                            </div>
                            <div class="link-group">
                                <label>Markdown</label>
                                <div class="link-input-group">
                                    <input type="text" class="link-input markdown-input" readonly>
                                    <button class="copy-btn" data-copy="markdown">
                                        <i data-lucide="copy" class="w-4 h-4"></i> Copy
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            // Summary
            html += `
                <div class="summary-card">
                    <div class="summary-title">
                        <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                        Upload Summary
                    </div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="value">${successItems.length}</div>
                            <div class="label">Images</div>
                        </div>
                        <div class="summary-item">
                            <div class="value">${formatSize(totalSize)}</div>
                            <div class="label">Total Size</div>
                        </div>
                    </div>
                </div>
            `;

            container.innerHTML = html;
            lucide.createIcons();

            // Populate input values safely (after DOM is created)
            container.querySelectorAll('.image-item').forEach(item => {
                const directLink = decodeURIComponent(item.dataset.direct);
                const viewUrl = decodeURIComponent(item.dataset.view);
                const filename = decodeURIComponent(item.dataset.filename);
                
                const htmlEmbed = `<a href="${viewUrl}"><img src="${directLink}" alt="${filename}"></a>`;
                const bbcode = `[url=${viewUrl}][img]${directLink}[/img][/url]`;
                const markdown = `[![${filename}](${directLink})](${viewUrl})`;
                
                // Set values using .value (safely escapes HTML)
                item.querySelector('.direct-link-input').value = directLink;
                item.querySelector('.html-embed-input').value = htmlEmbed;
                item.querySelector('.bbcode-input').value = bbcode;
                item.querySelector('.markdown-input').value = markdown;
                
                // Add copy button handlers
                item.querySelectorAll('.copy-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const type = btn.dataset.copy;
                        let text = '';
                        switch(type) {
                            case 'direct': text = directLink; break;
                            case 'html': text = htmlEmbed; break;
                            case 'bbcode': text = bbcode; break;
                            case 'markdown': text = markdown; break;
                        }
                        copyToClipboard(text, btn);
                    });
                });
            });

            // Auto expand first item
            const firstItem = container.querySelector('.image-item');
            if (firstItem) firstItem.classList.add('expanded');

            // Thumbnail click handler
            container.querySelectorAll('.thumbnail-item').forEach(thumb => {
                thumb.addEventListener('click', () => {
                    const index = thumb.dataset.index;
                    const targetItem = container.querySelector(`.image-item[data-index="${index}"]`);
                    
                    // Collapse all
                    container.querySelectorAll('.image-item').forEach(item => item.classList.remove('expanded'));
                    container.querySelectorAll('.thumbnail-item').forEach(t => t.classList.remove('active'));
                    
                    // Expand target
                    targetItem.classList.add('expanded');
                    thumb.classList.add('active');
                    targetItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });
        }

        function toggleExpand(header) {
            const item = header.closest('.image-item');
            item.classList.toggle('expanded');
        }

        function escapeHtml(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                btn.classList.add('copied');
                btn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Copied!';
                lucide.createIcons();
                setTimeout(() => {
                    btn.classList.remove('copied');
                    btn.innerHTML = '<i data-lucide="copy" class="w-4 h-4"></i> Copy';
                    lucide.createIcons();
                }, 2000);
            });
        }

        function copyAllLinks(type) {
            const successItems = results.filter(i => i.success);
            const siteUrl = window.location.origin;
            let links = [];

            successItems.forEach(item => {
                const data = item.data || item;
                const originalUrl = data.urls?.original || '';
                const viewUrl = data.view_url || '/' + data.id;
                const filename = data.filename || data.id;
                const directLink = originalUrl.startsWith('http') ? originalUrl : siteUrl + originalUrl;

                switch (type) {
                    case 'direct':
                        links.push(directLink);
                        break;
                    case 'html':
                        links.push(`<a href="${siteUrl}${viewUrl}"><img src="${directLink}" alt="${filename}"></a>`);
                        break;
                    case 'bbcode':
                        links.push(`[url=${siteUrl}${viewUrl}][img]${directLink}[/img][/url]`);
                        break;
                    case 'markdown':
                        links.push(`[![${filename}](${directLink})](${siteUrl}${viewUrl})`);
                        break;
                }
            });

            navigator.clipboard.writeText(links.join('\n')).then(() => {
                showToast(`Copied ${links.length} ${type} links!`);
            });
        }

        // TOOL RESULTS - Grid Style with Download All
        function displayToolResults(items) {
            if (!items || items.length === 0) {
                showEmpty('No results found');
                return;
            }

            const successItems = items.filter(i => i.success);
            document.getElementById('resultCount').textContent = successItems.length;

            let totalSize = 0;
            let html = '<div class="results-grid">';

            successItems.forEach((item, index) => {
                const data = item.data || item;
                totalSize += data.size || 0;

                const isDataUrl = typeof data === 'string' && data.startsWith('data:');
                const thumbUrl = isDataUrl ? data : (data.urls?.thumb || data.urls?.medium || data.urls?.original || '');
                const downloadUrl = isDataUrl ? data : (data.urls?.original || '');
                const viewUrl = data.view_url || (data.id ? '/' + data.id : downloadUrl);
                const filename = data.filename || data.id || 'image_' + (index + 1);

                html += `
                    <div class="result-card">
                        <div class="result-image">
                            <img src="${thumbUrl}" alt="" onerror="this.src='/assets/img/placeholder.png'">
                            <span class="result-badge">Success</span>
                        </div>
                        <div class="result-info">
                            <div class="result-filename">${filename}</div>
                            <div class="result-meta">
                                <span>${data.width || '?'}×${data.height || '?'}</span>
                                <span>${formatSize(data.size || 0)}</span>
                                ${data.savings_percent ? `<span class="text-green">-${data.savings_percent}%</span>` : ''}
                            </div>
                            <div class="result-actions">
                                <a href="${viewUrl}" class="result-btn result-btn-view" target="_blank">
                                    <i data-lucide="eye" class="w-3 h-3"></i> View
                                </a>
                                <a href="${downloadUrl}" class="result-btn result-btn-download" download="${filename}">
                                    <i data-lucide="download" class="w-3 h-3"></i> Download
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            });

            html += '</div>';

            // Summary
            html += `
                <div class="summary-card">
                    <div class="summary-title">
                        <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                        Processing Summary
                    </div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="value">${successItems.length}</div>
                            <div class="label">Processed</div>
                        </div>
                        <div class="summary-item">
                            <div class="value">${formatSize(totalSize)}</div>
                            <div class="label">Total Size</div>
                        </div>
                    </div>
                </div>
            `;

            container.innerHTML = html;
            lucide.createIcons();
        }

        // Download All ZIP for Tool Results
        <?php if ($isToolResult): ?>
        document.getElementById('downloadAllBtn').addEventListener('click', async () => {
            if (results.length === 0) return;

            const btn = document.getElementById('downloadAllBtn');
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Creating ZIP...';

            try {
                const zip = new JSZip();

                for (const item of results) {
                    if (!item.success) continue;
                    const data = item.data || item;
                    const url = data.urls?.original || (typeof data === 'string' ? data : '');
                    if (!url) continue;

                    try {
                        if (url.startsWith('data:')) {
                            const base64 = url.split(',')[1];
                            zip.file(data.filename || data.id + '.png', base64, { base64: true });
                        } else {
                            const response = await fetch(url);
                            const blob = await response.blob();
                            zip.file(data.filename || data.id + '.' + (data.extension || 'jpg'), blob);
                        }
                    } catch (e) {
                        console.error('Failed to add file:', e);
                    }
                }

                const content = await zip.generateAsync({ type: 'blob' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(content);
                a.download = 'pixelhop_<?= $tool ?>_' + Date.now() + '.zip';
                a.click();
            } catch (e) {
                alert('Failed to create ZIP: ' + e.message);
            }

            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="download" class="w-4 h-4"></i> Download All (ZIP)';
            lucide.createIcons();
        });
        <?php endif; ?>
    </script>
</body>
</html>
