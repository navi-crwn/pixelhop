<?php
/**
 * PixelHop - Compress Tool (Member)
 * Batch image compression with URL support
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$isAdmin = isAdmin();
$gatekeeper = new Gatekeeper();

// Check if tool is enabled
$toolEnabled = $gatekeeper->getSetting('tool_compress_enabled', true);
if (!$toolEnabled && !$isAdmin) {
    header('Location: /member/tools.php?error=tool_disabled');
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compress Images - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
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
            max-width: 900px;
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
            background: linear-gradient(135deg, #22d3ee, #06b6d4);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-links { display: flex; gap: 8px; }
        .nav-link {
            padding: 10px 18px;
            border-radius: 10px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .upload-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 700px) {
            .upload-section { grid-template-columns: 1fr; }
            .nav-links { display: none; }
        }

        .upload-box {
            border: 2px dashed rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: rgba(255, 255, 255, 0.02);
        }

        .upload-box:hover, .upload-box.dragover {
            border-color: #22d3ee;
            background: rgba(34, 211, 238, 0.05);
        }

        .upload-box-icon {
            width: 40px;
            height: 40px;
            margin: 0 auto 12px;
            opacity: 0.6;
        }

        .upload-box-title {
            font-size: 14px;
            font-weight: 500;
            color: #fff;
            margin-bottom: 6px;
        }

        .upload-box-hint {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
        }

        .url-panel {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .url-input {
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 13px;
            outline: none;
            resize: vertical;
            min-height: 100px;
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
        }

        .url-input:focus {
            border-color: #22d3ee;
        }

        .url-input::placeholder {
            color: rgba(255, 255, 255, 0.3);
        }

        .add-url-btn {
            padding: 12px;
            border-radius: 10px;
            background: rgba(34, 211, 238, 0.15);
            color: #22d3ee;
            border: 1px solid rgba(34, 211, 238, 0.2);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .add-url-btn:hover {
            background: rgba(34, 211, 238, 0.25);
        }

        .options-panel {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .option-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .option-row:last-child {
            margin-bottom: 0;
        }

        .option-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
        }

        .option-hint {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .quality-slider {
            width: 200px;
            -webkit-appearance: none;
            height: 6px;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.1);
            outline: none;
        }

        .quality-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #22d3ee;
            cursor: pointer;
        }

        .quality-value {
            min-width: 50px;
            text-align: right;
            font-size: 14px;
            color: #22d3ee;
            font-weight: 600;
        }

        .file-list {
            margin-bottom: 24px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            margin-bottom: 8px;
        }

        .file-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            background: rgba(255, 255, 255, 0.05);
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-size: 13px;
            color: #fff;
            margin-bottom: 4px;
        }

        .file-size {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .file-remove {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .file-status {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .status-pending {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
        }

        .status-processing {
            background: rgba(34, 211, 238, 0.15);
            color: #22d3ee;
        }

        .status-done {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .status-error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .action-bar {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .action-btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.8);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: rgba(255, 255, 255, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="title-section">
                <div class="title-icon">
                    <i data-lucide="file-archive" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Compress Images</h1>
                    <p class="text-xs text-white/50">Reduce file size • Batch processing</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/member/tools.php" class="nav-link">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    Back to Tools
                </a>
                <a href="/dashboard.php" class="nav-link">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
            </div>
        </div>

        <!-- Upload Section -->
        <div class="upload-section">
            <div class="upload-box" id="dropzone">
                <i data-lucide="upload-cloud" class="upload-box-icon"></i>
                <div class="upload-box-title">Drop files or click to browse</div>
                <div class="upload-box-hint">JPG, PNG, WebP • Max 15MB each</div>
                <input type="file" id="fileInput" accept="image/*" multiple hidden>
            </div>

            <div class="upload-box url-panel">
                <textarea class="url-input" id="urlInput" placeholder="Paste image URLs (one per line, max 10)&#10;&#10;Example:&#10;https://example.com/image1.jpg&#10;https://example.com/image2.png" rows="5"></textarea>
                <button class="add-url-btn" id="addUrlBtn">
                    <i data-lucide="plus" class="w-4 h-4 inline"></i>
                    Add from URL
                </button>
            </div>
        </div>

        <!-- Options -->
        <div class="options-panel">
            <div class="option-row">
                <div>
                    <div class="option-label">Quality Level</div>
                    <div class="option-hint">Higher = better quality, larger file</div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <input type="range" class="quality-slider" id="qualitySlider" min="10" max="100" value="80">
                    <div class="quality-value" id="qualityValue">80%</div>
                </div>
            </div>
        </div>

        <!-- File List -->
        <div class="file-list" id="fileList">
            <div class="empty-state" id="emptyState">
                <i data-lucide="image" class="w-12 h-12 mx-auto mb-3 opacity-40"></i>
                <p>No files added yet</p>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <button class="action-btn btn-secondary" id="clearBtn" style="display: none;">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
                Clear All
            </button>
            <button class="action-btn btn-primary" id="compressBtn" disabled>
                <i data-lucide="zap" class="w-4 h-4"></i>
                Compress All
            </button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const urlInput = document.getElementById('urlInput');
        const addUrlBtn = document.getElementById('addUrlBtn');
        const fileList = document.getElementById('fileList');
        const emptyState = document.getElementById('emptyState');
        const compressBtn = document.getElementById('compressBtn');
        const clearBtn = document.getElementById('clearBtn');
        const qualitySlider = document.getElementById('qualitySlider');
        const qualityValue = document.getElementById('qualityValue');

        let files = [];


        qualitySlider.addEventListener('input', () => {
            qualityValue.textContent = qualitySlider.value + '%';
        });


        dropzone.addEventListener('click', () => fileInput.click());

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files);
        });

        function handleFiles(fileInputs) {
            for (const file of fileInputs) {
                if (!file.type.startsWith('image/')) continue;
                if (file.size > 15 * 1024 * 1024) continue;

                const reader = new FileReader();
                reader.onload = (e) => {
                    files.push({
                        type: 'file',
                        data: file,
                        thumb: e.target.result,
                        name: file.name,
                        size: file.size,
                        status: 'pending'
                    });
                    renderFileList();
                };
                reader.readAsDataURL(file);
            }
        }


        addUrlBtn.addEventListener('click', async () => {
            const text = urlInput.value.trim();
            if (!text) return;

            addUrlBtn.disabled = true;
            addUrlBtn.textContent = 'Loading...';

            try {

                const urls = text.split(/[\n,;\s]+/)
                    .map(u => u.trim())
                    .filter(u => u.length > 0 && u.startsWith('http'))
                    .slice(0, 10);

                if (urls.length === 0) {
                    alert('No valid URLs found. Make sure URLs start with http:// or https://');
                    return;
                }


                for (const url of urls) {
                    files.push({
                        type: 'url',
                        data: url,
                        thumb: url,
                        name: url.split('/').pop() || 'image',
                        size: 0,
                        status: 'pending'
                    });
                }
                renderFileList();
                urlInput.value = '';
            } catch (e) {
                alert('Failed to add URL: ' + e.message);
            }

            addUrlBtn.disabled = false;
            addUrlBtn.innerHTML = '<i data-lucide="plus" class="w-4 h-4 inline"></i> Add from URL';
            lucide.createIcons();
        });

        function renderFileList() {
            if (files.length === 0) {
                fileList.innerHTML = `
                    <div class="empty-state" id="emptyState">
                        <i data-lucide="image" class="w-12 h-12 mx-auto mb-3 opacity-40"></i>
                        <p>No files added yet</p>
                    </div>
                `;
                compressBtn.disabled = true;
                clearBtn.style.display = 'none';
            } else {
                fileList.innerHTML = files.map((f, i) => `
                    <div class="file-item" data-index="${i}">
                        <img src="${f.thumb}" class="file-thumb" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 48 48%22><rect fill=%22%23333%22 width=%2248%22 height=%2248%22/></svg>'">
                        <div class="file-info">
                            <div class="file-name">${escapeHtml(f.name)}</div>
                            <div class="file-size">${f.size ? formatSize(f.size) : 'URL'}</div>
                        </div>
                        <span class="file-status status-${f.status}">${f.status}</span>
                        <button class="file-remove" onclick="removeFile(${i})">×</button>
                    </div>
                `).join('');
                compressBtn.disabled = false;
                clearBtn.style.display = 'flex';
            }
            lucide.createIcons();
        }

        function removeFile(index) {
            files.splice(index, 1);
            renderFileList();
        }

        clearBtn.addEventListener('click', () => {
            files = [];
            renderFileList();
        });


        compressBtn.addEventListener('click', async () => {
            if (files.length === 0) return;

            compressBtn.disabled = true;
            compressBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Processing...';

            const results = [];
            const quality = parseInt(qualitySlider.value);

            for (let i = 0; i < files.length; i++) {
                files[i].status = 'processing';
                renderFileList();

                const formData = new FormData();
                formData.append('quality', quality);
                formData.append('return', 'json');
                formData.append('csrf_token', '<?= $csrfToken ?>');

                if (files[i].type === 'file') {
                    formData.append('image', files[i].data);
                } else {
                    formData.append('url', files[i].data);
                }

                try {
                    const response = await fetch('/api/compress.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        results.push({
                            success: true,
                            filename: result.filename || files[i].name.replace(/\.[^.]+$/, '_compressed.jpg'),
                            data: result.data,
                            view_url: result.view_url,
                            size: result.new_size,
                            width: result.width,
                            height: result.height,
                            original_size: result.original_size || files[i].size,
                            savings_percent: result.savings_percent
                        });
                        files[i].status = 'done';
                    } else {
                        results.push({ success: false, error: result.error });
                        files[i].status = 'error';
                    }
                } catch (e) {
                    results.push({ success: false, error: e.message });
                    files[i].status = 'error';
                }

                renderFileList();
            }


            sessionStorage.setItem('toolResults', JSON.stringify(results));
            window.location.href = '/member/result.php?type=tool&tool=compress&count=' + results.filter(r => r.success).length;
        });

        function formatSize(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return bytes + ' B';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }


        document.addEventListener('paste', (e) => {
            const items = e.clipboardData.items;
            const filesList = [];
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    filesList.push(item.getAsFile());
                }
            }
            if (filesList.length > 0) {
                handleFiles(filesList);
            }
        });
    </script>
</body>
</html>
