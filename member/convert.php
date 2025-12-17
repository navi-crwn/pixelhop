<?php
/**
 * PixelHop - Convert Tool (Member)
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Convert Images - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { width: 100%; max-width: 900px; background: rgba(20, 20, 35, 0.85); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 24px; padding: 32px; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
        .title-section { display: flex; align-items: center; gap: 14px; }
        .title-icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #4ade80, #22c55e); display: flex; align-items: center; justify-content: center; }
        .nav-links { display: flex; gap: 8px; }
        .nav-link { padding: 10px 18px; border-radius: 10px; color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .upload-box { border: 2px dashed rgba(255, 255, 255, 0.15); border-radius: 16px; padding: 50px 20px; text-align: center; cursor: pointer; margin-bottom: 16px; }
        .upload-box:hover { border-color: #4ade80; background: rgba(74, 222, 128, 0.05); }
        .upload-box.url-panel { padding: 20px; cursor: default; }

        .url-input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 13px;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 12px;
        }
        .url-input:focus { border-color: #4ade80; outline: none; }
        .url-input::placeholder { color: rgba(255, 255, 255, 0.35); }
        .add-url-btn {
            padding: 12px 20px;
            border-radius: 10px;
            background: rgba(74, 222, 128, 0.15);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.3);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .add-url-btn:hover { background: rgba(74, 222, 128, 0.25); }

        .options-panel { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 20px; margin-bottom: 24px; }
        .option-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .option-label { font-size: 14px; color: rgba(255, 255, 255, 0.8); }

        .format-btn { padding: 12px 24px; border-radius: 10px; background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.7); border: 1px solid rgba(255, 255, 255, 0.08); font-size: 14px; font-weight: 500; cursor: pointer; }
        .format-btn:hover { background: rgba(74, 222, 128, 0.15); color: #4ade80; }
        .format-btn.active { background: rgba(74, 222, 128, 0.2); color: #4ade80; border-color: #4ade80; }

        .file-list { margin-bottom: 24px; }
        .file-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; margin-bottom: 8px; }
        .file-thumb { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; }
        .file-info { flex: 1; }
        .file-name { font-size: 13px; color: #fff; }
        .file-size { font-size: 11px; color: rgba(255, 255, 255, 0.4); }
        .file-remove { width: 28px; height: 28px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: #ef4444; border: none; cursor: pointer; }

        .action-bar { display: flex; gap: 12px; justify-content: flex-end; }
        .action-btn { padding: 14px 28px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: linear-gradient(135deg, #4ade80, #22c55e); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary { background: rgba(255, 255, 255, 0.08); color: rgba(255, 255, 255, 0.8); }

        .empty-state { text-align: center; padding: 40px; color: rgba(255, 255, 255, 0.4); }
        @media (max-width: 600px) { .nav-links { display: none; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title-section">
                <div class="title-icon"><i data-lucide="repeat" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">Convert Images</h1>
                    <p class="text-xs text-white/50">Change format • Batch processing</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/member/tools.php" class="nav-link"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
                <a href="/dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
            </div>
        </div>

        <div class="upload-box" id="dropzone">
            <i data-lucide="upload-cloud" class="w-10 h-10 mx-auto mb-3 opacity-50"></i>
            <div class="text-sm text-white/70">Drop files or click to browse</div>
            <input type="file" id="fileInput" accept="image/*" multiple hidden>
        </div>

        <div class="upload-box url-panel">
            <textarea class="url-input" id="urlInput" placeholder="Paste image URLs (one per line, max 10)&#10;&#10;Example:&#10;https://example.com/image1.jpg&#10;https://example.com/image2.png" rows="5"></textarea>
            <button class="add-url-btn" id="addUrlBtn">
                <i data-lucide="plus" class="w-4 h-4"></i>
                Add from URL
            </button>
        </div>

        <div class="options-panel">
            <div class="option-row">
                <span class="option-label">Convert to:</span>
                <button class="format-btn active" data-format="jpg">JPG</button>
                <button class="format-btn" data-format="png">PNG</button>
                <button class="format-btn" data-format="webp">WebP</button>
                <button class="format-btn" data-format="gif">GIF</button>
            </div>
        </div>

        <div class="file-list" id="fileList">
            <div class="empty-state"><i data-lucide="image" class="w-12 h-12 mx-auto mb-3 opacity-40"></i><p>No files added</p></div>
        </div>

        <div class="action-bar">
            <button class="action-btn btn-secondary" id="clearBtn" style="display: none;"><i data-lucide="trash-2" class="w-4 h-4"></i> Clear</button>
            <button class="action-btn btn-primary" id="processBtn" disabled><i data-lucide="repeat" class="w-4 h-4"></i> Convert All</button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const processBtn = document.getElementById('processBtn');
        const clearBtn = document.getElementById('clearBtn');
        const urlInput = document.getElementById('urlInput');
        const addUrlBtn = document.getElementById('addUrlBtn');

        let files = [];
        let targetFormat = 'jpg';

        document.querySelectorAll('.format-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.format-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                targetFormat = btn.dataset.format;
            });
        });

        dropzone.addEventListener('click', () => fileInput.click());
        dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.style.borderColor = '#4ade80'; });
        dropzone.addEventListener('dragleave', () => { dropzone.style.borderColor = ''; });
        dropzone.addEventListener('drop', e => { e.preventDefault(); dropzone.style.borderColor = ''; handleFiles(e.dataTransfer.files); });
        fileInput.addEventListener('change', e => handleFiles(e.target.files));


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
                        size: 0
                    });
                }
                renderList();
                urlInput.value = '';
            } catch (e) {
                alert('Failed to add URL: ' + e.message);
            }

            addUrlBtn.disabled = false;
            addUrlBtn.innerHTML = '<i data-lucide="plus" class="w-4 h-4"></i> Add from URL';
            lucide.createIcons();
        });

        function handleFiles(inputFiles) {
            for (const file of inputFiles) {
                if (!file.type.startsWith('image/')) continue;
                const reader = new FileReader();
                reader.onload = e => {
                    files.push({ type: 'file', data: file, thumb: e.target.result, name: file.name, size: file.size });
                    renderList();
                };
                reader.readAsDataURL(file);
            }
        }

        function renderList() {
            if (files.length === 0) {
                fileList.innerHTML = '<div class="empty-state"><i data-lucide="image" class="w-12 h-12 mx-auto mb-3 opacity-40"></i><p>No files added</p></div>';
                processBtn.disabled = true; clearBtn.style.display = 'none';
            } else {
                fileList.innerHTML = files.map((f, i) => `
                    <div class="file-item">
                        <img src="${f.thumb}" class="file-thumb" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 48 48%22><rect fill=%22%23333%22 width=%2248%22 height=%2248%22/></svg>'">
                        <div class="file-info"><div class="file-name">${f.name}</div><div class="file-size">${f.size ? (f.size/1024).toFixed(1) + ' KB' : 'URL'}</div></div>
                        <button class="file-remove" onclick="removeFile(${i})">×</button>
                    </div>
                `).join('');
                processBtn.disabled = false; clearBtn.style.display = 'flex';
            }
            lucide.createIcons();
        }

        window.removeFile = i => { files.splice(i, 1); renderList(); };
        clearBtn.addEventListener('click', () => { files = []; renderList(); });

        processBtn.addEventListener('click', async () => {
            processBtn.disabled = true;
            processBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Converting...';

            const results = [];
            for (const f of files) {
                const formData = new FormData();
                formData.append('format', targetFormat);
                formData.append('return', 'json');

                if (f.type === 'file') {
                    formData.append('image', f.data);
                } else {
                    formData.append('url', f.data);
                }

                try {
                    const res = await fetch('/api/convert.php', { method: 'POST', body: formData });
                    const result = await res.json();

                    if (result.success) {
                        results.push({
                            success: true,
                            filename: f.name.replace(/\.[^.]+$/, '.' + targetFormat),
                            data: result.data,
                            view_url: result.view_url,
                            size: result.new_size || 0,
                            width: result.width,
                            height: result.height
                        });
                    } else {
                        results.push({ success: false, error: result.error });
                    }
                } catch (e) {
                    results.push({ success: false, error: e.message });
                }
            }

            sessionStorage.setItem('toolResults', JSON.stringify(results));
            window.location.href = '/member/result.php?type=tool&tool=convert&count=' + results.filter(r => r.success).length;
        });
    </script>
</body>
</html>
