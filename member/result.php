<?php
/**
 * PixelHop - Result Page
 * Shows results for uploads and tool processing (batch support)
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$isAdmin = isAdmin();

$type = $_GET['type'] ?? 'upload';
$count = (int)($_GET['count'] ?? 0);
$tool = $_GET['tool'] ?? '';

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 1000px;
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .title-section {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .title-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #22c55e, #10b981);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btns {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
            border: none;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        .result-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
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
            color: #fff;
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .result-meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
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
        }

        .result-btn-view {
            background: rgba(34, 211, 238, 0.15);
            color: #22d3ee;
            border: none;
        }

        .result-btn-download {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
            border: none;
        }

        .result-btn-copy {
            background: rgba(168, 85, 247, 0.15);
            color: #a855f7;
            border: none;
        }

        .result-btn:hover {
            transform: translateY(-1px);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.4);
        }

        .stats-summary {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .stat-box {
            padding: 16px 24px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
        }

        .text-green { color: #22c55e; }
        .text-cyan { color: #22d3ee; }

        @media (max-width: 600px) {
            .action-btns { display: none; }
            .stats-summary { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="title-section">
                <div class="title-icon">
                    <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">
                        <?php if ($type === 'upload'): ?>
                            Upload Complete
                        <?php else: ?>
                            <?= ucfirst($tool) ?> Results
                        <?php endif; ?>
                    </h1>
                    <p class="text-xs text-white/50">
                        <span id="resultCount"><?= $count ?></span> <?= $count === 1 ? 'image' : 'images' ?> processed
                    </p>
                </div>
            </div>
            <div class="action-btns">
                <a href="/" class="action-btn btn-secondary">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Home
                </a>
                <a href="/member/tools.php" class="action-btn btn-secondary">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    Tools
                </a>
                <button class="action-btn btn-secondary" id="downloadAllBtn">
                    <i data-lucide="download" class="w-4 h-4"></i>
                    Download All (ZIP)
                </button>
                <a href="/member/upload.php" class="action-btn btn-primary">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Upload More
                </a>
            </div>
        </div>

        <!-- Stats Summary -->
        <div class="stats-summary" id="statsSummary">
            <div class="stat-box">
                <div class="stat-value text-green" id="totalFiles">0</div>
                <div class="stat-label">Files Processed</div>
            </div>
            <div class="stat-box">
                <div class="stat-value text-cyan" id="totalSize">0 KB</div>
                <div class="stat-label">Total Size</div>
            </div>
        </div>

        <!-- Results Grid -->
        <div class="results-grid" id="resultsGrid">
            <div class="empty-state" id="emptyState">
                <i data-lucide="loader" class="w-12 h-12 mx-auto mb-4 opacity-50 animate-spin"></i>
                <p>Loading results...</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const resultsGrid = document.getElementById('resultsGrid');
        const emptyState = document.getElementById('emptyState');
        const downloadAllBtn = document.getElementById('downloadAllBtn');

        let results = [];


        const storedResults = sessionStorage.getItem('uploadResults') || sessionStorage.getItem('toolResults');

        if (storedResults) {
            try {
                results = JSON.parse(storedResults);
                displayResults(results);
            } catch (e) {
                showEmpty('Failed to load results');
            }
        } else {
            showEmpty('No results found');
        }

        function displayResults(items) {
            if (!items || items.length === 0) {
                showEmpty('No results found');
                return;
            }

            resultsGrid.innerHTML = '';
            document.getElementById('totalFiles').textContent = items.length;
            document.getElementById('resultCount').textContent = items.length;

            let totalSize = 0;

            items.forEach((item, index) => {
                if (!item.success) return;

                totalSize += item.size || 0;

                const card = document.createElement('div');
                card.className = 'result-card';


                const isToolResult = !!item.data && item.data.startsWith('data:');
                const thumbUrl = item.urls?.thumb || item.urls?.medium || item.data || '';
                const downloadUrl = item.urls?.original || item.data || '';


                const viewUrl = item.view_url || (item.id ? '/' + item.id : (isToolResult ? item.data : downloadUrl));

                card.innerHTML = `
                    <div class="result-image">
                        <img src="${thumbUrl}" alt="">
                        <span class="result-badge">Success</span>
                    </div>
                    <div class="result-info">
                        <div class="result-filename">${item.filename || item.id || 'image_' + (index + 1)}</div>
                        <div class="result-meta">
                            <span>${item.width || '?'}×${item.height || '?'}</span>
                            <span>${formatSize(item.size || 0)}</span>
                            ${item.savings_percent ? `<span class="text-green">-${item.savings_percent}%</span>` : ''}
                        </div>
                        <div class="result-actions">
                            <a href="${viewUrl}" class="result-btn result-btn-view" target="_blank">
                                <i data-lucide="eye" class="w-3 h-3"></i>
                                View
                            </a>
                            <a href="${downloadUrl}" class="result-btn result-btn-download" download="${item.filename || 'image_' + (index + 1)}">
                                <i data-lucide="download" class="w-3 h-3"></i>
                                Download
                            </a>
                        </div>
                    </div>
                `;

                resultsGrid.appendChild(card);
            });

            document.getElementById('totalSize').textContent = formatSize(totalSize);
            lucide.createIcons();
        }

        function showEmpty(message) {
            resultsGrid.innerHTML = `
                <div class="empty-state" style="grid-column: 1/-1;">
                    <i data-lucide="image-off" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                    <p>${message}</p>
                    <a href="/member/upload.php" class="action-btn btn-primary" style="display: inline-flex; margin-top: 16px;">
                        <i data-lucide="upload" class="w-4 h-4"></i>
                        Upload Images
                    </a>
                </div>
            `;
            lucide.createIcons();
        }

        function formatSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return bytes + ' B';
        }

        function copyLink(url) {
            const fullUrl = url.startsWith('http') ? url : window.location.origin + url;
            navigator.clipboard.writeText(fullUrl).then(() => {

                alert('Link copied!');
            });
        }


        downloadAllBtn.addEventListener('click', async () => {
            if (results.length === 0) return;

            downloadAllBtn.disabled = true;
            downloadAllBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Creating ZIP...';

            try {
                const zip = new JSZip();

                for (const item of results) {
                    if (!item.success) continue;

                    const url = item.urls?.original || item.url || item.data;
                    if (!url) continue;

                    try {
                        if (url.startsWith('data:')) {

                            const base64 = url.split(',')[1];
                            zip.file(item.filename || item.id + '.png', base64, { base64: true });
                        } else {

                            const response = await fetch(url);
                            const blob = await response.blob();
                            zip.file(item.filename || item.id + '.' + (item.extension || 'jpg'), blob);
                        }
                    } catch (e) {
                        console.error('Failed to add file:', e);
                    }
                }

                const content = await zip.generateAsync({ type: 'blob' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(content);
                a.download = 'pixelhop_' + Date.now() + '.zip';
                a.click();

            } catch (e) {
                alert('Failed to create ZIP: ' + e.message);
            }

            downloadAllBtn.disabled = false;
            downloadAllBtn.innerHTML = '<i data-lucide="download" class="w-4 h-4"></i> Download All (ZIP)';
            lucide.createIcons();
        });
    </script>
</body>
</html>
