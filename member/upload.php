<?php
/**
 * PixelHop - Member Upload Page
 * Upload by file or URL with advanced options
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

// Check maintenance mode
$maintenanceMode = $gatekeeper->getSetting('maintenance_mode', false);
if ($maintenanceMode && !$isAdmin) {
    header('Location: /dashboard.php?error=maintenance');
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        })();
    </script>
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
            max-width: 600px;
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
        }

        [data-theme="light"] body {
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 50%, #f0f4f8 100%);
        }

        [data-theme="light"] .container {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(0, 0, 0, 0.1);
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
            background: linear-gradient(135deg, #22d3ee, #a855f7);
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
        .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }

        .upload-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }

        .upload-tab {
            flex: 1;
            padding: 14px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .upload-tab:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
        }

        .upload-tab.active {
            background: rgba(34, 211, 238, 0.15);
            border-color: rgba(34, 211, 238, 0.3);
            color: #22d3ee;
        }

        .upload-panel {
            display: none;
        }

        .upload-panel.active {
            display: block;
        }

        .dropzone {
            border: 2px dashed rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 60px 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: rgba(255, 255, 255, 0.02);
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: #22d3ee;
            background: rgba(34, 211, 238, 0.05);
        }

        .dropzone-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            opacity: 0.6;
        }

        .dropzone-text {
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 8px;
        }

        .dropzone-hint {
            color: rgba(255, 255, 255, 0.4);
            font-size: 13px;
        }

        .url-input-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .url-textarea {
            width: 100%;
            min-height: 120px;
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
            resize: vertical;
            font-family: inherit;
            line-height: 1.6;
        }

        .url-textarea:focus {
            border-color: #22d3ee;
            background: rgba(34, 211, 238, 0.05);
        }

        .url-textarea::placeholder {
            color: rgba(255, 255, 255, 0.3);
        }

        .url-hint {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            margin-top: -4px;
        }

        [data-theme="light"] .url-textarea {
            background: rgba(0, 0, 0, 0.03);
            border-color: rgba(0, 0, 0, 0.1);
            color: #1a202c;
        }

        [data-theme="light"] .url-textarea::placeholder {
            color: rgba(0, 0, 0, 0.4);
        }

        .upload-btn {
            padding: 16px 32px;
            border-radius: 12px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3);
        }

        .upload-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 24px;
        }

        .preview-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 12px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.05);
        }

        .preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .preview-remove {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.9);
            color: #fff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .progress-bar {
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 16px;
            display: none;
        }

        .progress-bar.active {
            display: block;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #22d3ee, #a855f7);
            border-radius: 2px;
            width: 0%;
            transition: width 0.3s;
        }

        .status-message {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            display: none;
        }

        .status-message.success {
            display: block;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #22c55e;
        }

        .status-message.error {
            display: block;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        @media (max-width: 600px) {
            .nav-links { display: none; }
            .url-input-group { flex-direction: column; }
        }

        /* Light theme styles */
        [data-theme="light"] .container {
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.1);
        }
        [data-theme="light"] .header {
            border-color: rgba(0, 0, 0, 0.08);
        }
        [data-theme="light"] .text-white, [data-theme="light"] h1, 
        [data-theme="light"] .dropzone-text { 
            color: #1a202c !important; 
        }
        [data-theme="light"] .text-white\/50, [data-theme="light"] .dropzone-hint,
        [data-theme="light"] .url-hint { 
            color: rgba(0, 0, 0, 0.5) !important; 
        }
        [data-theme="light"] .nav-link { color: rgba(0, 0, 0, 0.6); }
        [data-theme="light"] .nav-link:hover { background: rgba(0, 0, 0, 0.05); color: #1a202c; }
        [data-theme="light"] .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #0891b2; }
        [data-theme="light"] .upload-tab {
            background: rgba(0, 0, 0, 0.03);
            border-color: rgba(0, 0, 0, 0.08);
            color: rgba(0, 0, 0, 0.6);
        }
        [data-theme="light"] .upload-tab:hover {
            background: rgba(0, 0, 0, 0.06);
            color: #1a202c;
        }
        [data-theme="light"] .upload-tab.active {
            background: rgba(34, 211, 238, 0.15);
            border-color: rgba(34, 211, 238, 0.3);
            color: #0891b2;
        }
        [data-theme="light"] .dropzone {
            border-color: rgba(0, 0, 0, 0.15);
            background: rgba(0, 0, 0, 0.02);
        }
        [data-theme="light"] .dropzone:hover, [data-theme="light"] .dropzone.dragover {
            border-color: #0891b2;
            background: rgba(8, 145, 178, 0.05);
        }
        [data-theme="light"] .preview-item {
            background: rgba(0, 0, 0, 0.05);
        }
        [data-theme="light"] .progress-bar {
            background: rgba(0, 0, 0, 0.1);
        }
        
        /* Theme Toggle */
        .theme-toggle-btn { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: rgba(255, 255, 255, 0.6); cursor: pointer; transition: all 0.2s; }
        .theme-toggle-btn:hover { background: rgba(255, 255, 255, 0.1); color: #22d3ee; }
        [data-theme="light"] .theme-toggle-btn { background: rgba(0, 0, 0, 0.05); border-color: rgba(0, 0, 0, 0.1); color: rgba(0, 0, 0, 0.6); }
        [data-theme="light"] .theme-toggle-btn:hover { background: rgba(0, 0, 0, 0.1); color: #0891b2; }
        #theme-icon-light { display: none; }
        #theme-icon-dark { display: block; }
        [data-theme="light"] #theme-icon-light { display: block; }
        [data-theme="light"] #theme-icon-dark { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="title-section">
                <div class="title-icon">
                    <i data-lucide="upload" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Upload Image</h1>
                    <p class="text-xs text-white/50">Upload files or paste URLs</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/dashboard" class="nav-link">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="/gallery" class="nav-link">
                    <i data-lucide="images" class="w-4 h-4"></i>
                    Gallery
                </a>
                <a href="/member/tools" class="nav-link">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    Tools
                </a>
                <button class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Theme">
                    <i data-lucide="sun" id="theme-icon-light" class="w-4 h-4"></i>
                    <i data-lucide="moon" id="theme-icon-dark" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        <!-- Upload Tabs -->
        <div class="upload-tabs">
            <div class="upload-tab active" data-tab="file">
                <i data-lucide="file-image" class="w-4 h-4"></i>
                File Upload
            </div>
            <div class="upload-tab" data-tab="url">
                <i data-lucide="link" class="w-4 h-4"></i>
                From URL
            </div>
        </div>

        <!-- File Upload Panel -->
        <div class="upload-panel active" id="panel-file">
            <div class="dropzone" id="dropzone">
                <i data-lucide="cloud-upload" class="dropzone-icon"></i>
                <div class="dropzone-text">Drop images here or click to browse</div>
                <div class="dropzone-hint">Supports: JPG, PNG, GIF, WebP • Max 15MB per file</div>
            </div>
            <input type="file" id="fileInput" accept="image/*" multiple hidden>
            <div class="preview-grid" id="previewGrid"></div>
            <div class="progress-bar" id="progressBar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="status-message" id="statusMessage"></div>
            <button class="upload-btn" id="uploadBtn" style="margin-top: 20px; display: none;">
                <i data-lucide="upload" class="w-4 h-4"></i>
                Upload Files
            </button>
        </div>

        <!-- URL Upload Panel -->
        <div class="upload-panel" id="panel-url">
            <div class="url-input-group">
                <textarea class="url-textarea" id="urlInput" placeholder="Paste image URLs here...
One URL per line, e.g.:
https://example.com/image1.jpg
https://example.com/image2.png"></textarea>
                <p class="url-hint">Enter one URL per line for batch upload</p>
                <button class="upload-btn" id="urlUploadBtn" style="align-self: flex-end;">
                    <i data-lucide="download" class="w-4 h-4"></i>
                    Fetch All
                </button>
            </div>
            <div class="progress-bar" id="urlProgressBar">
                <div class="progress-fill" id="urlProgressFill"></div>
            </div>
            <div class="status-message" id="urlStatusMessage"></div>
            <div class="preview-grid" id="urlPreviewGrid"></div>
        </div>
    </div>

    <script>
        lucide.createIcons();


        document.querySelectorAll('.upload-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.upload-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.upload-panel').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('panel-' + tab.dataset.tab).classList.add('active');
            });
        });


        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const previewGrid = document.getElementById('previewGrid');
        const uploadBtn = document.getElementById('uploadBtn');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        const statusMessage = document.getElementById('statusMessage');

        let selectedFiles = [];

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

        function handleFiles(files) {
            for (const file of files) {
                if (!file.type.startsWith('image/')) continue;
                if (file.size > 15 * 1024 * 1024) {
                    showStatus('error', `${file.name} is too large (max 15MB)`);
                    continue;
                }
                selectedFiles.push(file);
                addPreview(file);
            }
            uploadBtn.style.display = selectedFiles.length > 0 ? 'flex' : 'none';
        }

        function addPreview(file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const div = document.createElement('div');
                div.className = 'preview-item';
                div.innerHTML = `
                    <img src="${e.target.result}" alt="">
                    <button class="preview-remove" onclick="removeFile(${selectedFiles.length - 1})">×</button>
                `;
                previewGrid.appendChild(div);
            };
            reader.readAsDataURL(file);
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            previewGrid.innerHTML = '';
            selectedFiles.forEach((f, i) => addPreview(f));
            uploadBtn.style.display = selectedFiles.length > 0 ? 'flex' : 'none';
        }

        uploadBtn.addEventListener('click', async () => {
            if (selectedFiles.length === 0) return;

            uploadBtn.disabled = true;
            progressBar.classList.add('active');

            let uploaded = 0;
            const results = [];

            for (const file of selectedFiles) {
                const formData = new FormData();
                formData.append('image', file);
                formData.append('csrf_token', '<?= $csrfToken ?>');

                try {
                    const response = await fetch('/api/upload.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    results.push(result);
                } catch (err) {
                    results.push({ success: false, error: err.message });
                }

                uploaded++;
                progressFill.style.width = (uploaded / selectedFiles.length * 100) + '%';
            }

            const successCount = results.filter(r => r.success).length;

            if (successCount === selectedFiles.length) {
                showStatus('success', `All ${successCount} images uploaded successfully!`);

                if (results.length === 1) {
                    window.location.href = '/' + results[0].data.id;
                } else {

                    sessionStorage.setItem('uploadResults', JSON.stringify(results));
                    window.location.href = '/member/result.php?type=upload&count=' + successCount;
                }
            } else {
                showStatus('error', `${successCount}/${selectedFiles.length} images uploaded`);
            }

            uploadBtn.disabled = false;
        });

        function showStatus(type, message) {
            statusMessage.className = 'status-message ' + type;
            statusMessage.textContent = message;
        }


        const urlInput = document.getElementById('urlInput');
        const urlUploadBtn = document.getElementById('urlUploadBtn');
        const urlProgressBar = document.getElementById('urlProgressBar');
        const urlProgressFill = document.getElementById('urlProgressFill');
        const urlStatusMessage = document.getElementById('urlStatusMessage');
        const urlPreviewGrid = document.getElementById('urlPreviewGrid');

        urlUploadBtn.addEventListener('click', async () => {
            const text = urlInput.value.trim();
            if (!text) return;

            // Parse multiple URLs (one per line)
            const urls = text.split('\n')
                .map(u => u.trim())
                .filter(u => u && (u.startsWith('http://') || u.startsWith('https://')));

            if (urls.length === 0) {
                urlStatusMessage.className = 'status-message error';
                urlStatusMessage.textContent = 'Please enter valid URLs (starting with http:// or https://)';
                return;
            }

            urlUploadBtn.disabled = true;
            urlProgressBar.classList.add('active');
            urlPreviewGrid.innerHTML = '';
            
            const results = [];
            let processed = 0;

            for (const url of urls) {
                const formData = new FormData();
                formData.append('url', url);
                formData.append('csrf_token', '<?= $csrfToken ?>');

                try {
                    const response = await fetch('/api/upload.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    results.push({ ...result, url });
                } catch (err) {
                    results.push({ success: false, error: err.message, url });
                }

                processed++;
                urlProgressFill.style.width = (processed / urls.length * 100) + '%';
            }

            const successCount = results.filter(r => r.success).length;

            if (successCount > 0) {
                urlStatusMessage.className = 'status-message success';
                urlStatusMessage.textContent = `${successCount}/${urls.length} images uploaded successfully!`;
                
                if (successCount === 1 && urls.length === 1) {
                    setTimeout(() => {
                        window.location.href = '/' + results[0].data.id;
                    }, 500);
                } else if (successCount > 0) {
                    sessionStorage.setItem('uploadResults', JSON.stringify(results));
                    setTimeout(() => {
                        window.location.href = '/member/result.php?type=upload&count=' + successCount;
                    }, 1000);
                }
            } else {
                urlStatusMessage.className = 'status-message error';
                urlStatusMessage.textContent = 'All uploads failed. Check the URLs and try again.';
            }

            urlUploadBtn.disabled = false;
        });


        document.addEventListener('paste', (e) => {
            const items = e.clipboardData.items;
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    const file = item.getAsFile();
                    handleFiles([file]);
                }
            }
        });
        
        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('pixelhop-theme', next);
            lucide.createIcons();
        }
    </script>
</body>
</html>
