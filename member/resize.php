<?php
/**
 * PixelHop - Resize Tool (Member)
 * Batch image resizing with URL support
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

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resize Images - PixelHop</title>
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

        .title-section { display: flex; align-items: center; gap: 14px; }
        .title-icon {
            width: 48px; height: 48px; border-radius: 14px;
            background: linear-gradient(135deg, #a855f7, #7c3aed);
            display: flex; align-items: center; justify-content: center;
        }

        .nav-links { display: flex; gap: 8px; }
        .nav-link {
            padding: 10px 18px; border-radius: 10px;
            color: rgba(255, 255, 255, 0.6); text-decoration: none;
            font-size: 14px; font-weight: 500; transition: all 0.2s;
            display: flex; align-items: center; gap: 8px;
        }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .upload-box {
            border: 2px dashed rgba(255, 255, 255, 0.15);
            border-radius: 16px; padding: 50px 20px;
            text-align: center; cursor: pointer; transition: all 0.3s;
            background: rgba(255, 255, 255, 0.02); margin-bottom: 24px;
        }
        .upload-box:hover { border-color: #a855f7; background: rgba(168, 85, 247, 0.05); }

        .options-panel {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px; padding: 20px; margin-bottom: 24px;
        }

        .option-row {
            display: flex; align-items: center; gap: 16px;
            margin-bottom: 16px; flex-wrap: wrap;
        }
        .option-row:last-child { margin-bottom: 0; }

        .option-label { font-size: 14px; color: rgba(255, 255, 255, 0.8); min-width: 80px; }

        .size-input {
            width: 100px; padding: 10px 14px; border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05); color: #fff;
            font-size: 14px; text-align: center;
        }
        .size-input:focus { border-color: #a855f7; outline: none; }

        .preset-btn {
            padding: 8px 16px; border-radius: 8px;
            background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .preset-btn:hover { background: rgba(168, 85, 247, 0.15); color: #a855f7; border-color: rgba(168, 85, 247, 0.3); }
        .preset-btn.active { background: rgba(168, 85, 247, 0.2); color: #a855f7; border-color: #a855f7; }

        .checkbox-label {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: rgba(255, 255, 255, 0.7); cursor: pointer;
        }
        .checkbox-label input { width: 16px; height: 16px; accent-color: #a855f7; }

        .file-list { margin-bottom: 24px; }
        .file-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px; margin-bottom: 8px;
        }
        .file-thumb { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; }
        .file-info { flex: 1; }
        .file-name { font-size: 13px; color: #fff; }
        .file-size { font-size: 11px; color: rgba(255, 255, 255, 0.4); }
        .file-remove {
            width: 28px; height: 28px; border-radius: 50%;
            background: rgba(239, 68, 68, 0.15); color: #ef4444;
            border: none; cursor: pointer;
        }

        .action-bar { display: flex; gap: 12px; justify-content: flex-end; }
        .action-btn {
            padding: 14px 28px; border-radius: 12px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: all 0.2s; display: flex; align-items: center; gap: 8px; border: none;
        }
        .btn-primary { background: linear-gradient(135deg, #a855f7, #7c3aed); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(168, 85, 247, 0.3); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-secondary { background: rgba(255, 255, 255, 0.08); color: rgba(255, 255, 255, 0.8); }

        .empty-state { text-align: center; padding: 40px; color: rgba(255, 255, 255, 0.4); }

        @media (max-width: 600px) { .nav-links { display: none; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title-section">
                <div class="title-icon"><i data-lucide="maximize-2" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">Resize Images</h1>
                    <p class="text-xs text-white/50">Change dimensions • Batch processing</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/member/tools.php" class="nav-link"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
                <a href="/dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
            </div>
        </div>

        <div class="upload-box" id="dropzone">
            <i data-lucide="upload-cloud" class="w-10 h-10 mx-auto mb-3 opacity-50"></i>
            <div class="text-sm text-white/70 mb-1">Drop files or click to browse</div>
            <div class="text-xs text-white/40">Also supports URL upload</div>
            <input type="file" id="fileInput" accept="image/*" multiple hidden>
        </div>

        <div class="options-panel">
            <div class="option-row">
                <span class="option-label">Size:</span>
                <input type="number" class="size-input" id="widthInput" placeholder="Width" value="800">
                <span class="text-white/50">×</span>
                <input type="number" class="size-input" id="heightInput" placeholder="Height" value="600">
                <label class="checkbox-label">
                    <input type="checkbox" id="keepRatio" checked>
                    Keep aspect ratio
                </label>
            </div>
            <div class="option-row">
                <span class="option-label">Presets:</span>
                <button class="preset-btn" data-w="1920" data-h="1080">1920×1080</button>
                <button class="preset-btn" data-w="1280" data-h="720">1280×720</button>
                <button class="preset-btn" data-w="800" data-h="600">800×600</button>
                <button class="preset-btn" data-w="500" data-h="500">500×500</button>
                <button class="preset-btn" data-w="150" data-h="150">150×150</button>
            </div>
        </div>

        <div class="file-list" id="fileList">
            <div class="empty-state"><i data-lucide="image" class="w-12 h-12 mx-auto mb-3 opacity-40"></i><p>No files added</p></div>
        </div>

        <div class="action-bar">
            <button class="action-btn btn-secondary" id="clearBtn" style="display: none;"><i data-lucide="trash-2" class="w-4 h-4"></i> Clear</button>
            <button class="action-btn btn-primary" id="processBtn" disabled><i data-lucide="maximize-2" class="w-4 h-4"></i> Resize All</button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const processBtn = document.getElementById('processBtn');
        const clearBtn = document.getElementById('clearBtn');
        const widthInput = document.getElementById('widthInput');
        const heightInput = document.getElementById('heightInput');

        let files = [];


        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                widthInput.value = btn.dataset.w;
                heightInput.value = btn.dataset.h;
            });
        });

        dropzone.addEventListener('click', () => fileInput.click());
        dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.style.borderColor = '#a855f7'; });
        dropzone.addEventListener('dragleave', () => { dropzone.style.borderColor = ''; });
        dropzone.addEventListener('drop', e => { e.preventDefault(); dropzone.style.borderColor = ''; handleFiles(e.dataTransfer.files); });
        fileInput.addEventListener('change', e => handleFiles(e.target.files));

        function handleFiles(inputFiles) {
            for (const file of inputFiles) {
                if (!file.type.startsWith('image/')) continue;
                const reader = new FileReader();
                reader.onload = e => {
                    files.push({ type: 'file', data: file, thumb: e.target.result, name: file.name, size: file.size, status: 'pending' });
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
                    <div class="file-item"><img src="${f.thumb}" class="file-thumb"><div class="file-info"><div class="file-name">${f.name}</div><div class="file-size">${(f.size/1024).toFixed(1)} KB</div></div><button class="file-remove" onclick="removeFile(${i})">×</button></div>
                `).join('');
                processBtn.disabled = false; clearBtn.style.display = 'flex';
            }
            lucide.createIcons();
        }

        window.removeFile = i => { files.splice(i, 1); renderList(); };
        clearBtn.addEventListener('click', () => { files = []; renderList(); });

        processBtn.addEventListener('click', async () => {
            processBtn.disabled = true;
            processBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Processing...';

            const results = [];
            for (const f of files) {
                const formData = new FormData();
                formData.append('image', f.data);
                formData.append('width', widthInput.value);
                formData.append('height', heightInput.value);
                formData.append('maintain_ratio', document.getElementById('keepRatio').checked ? '1' : '0');
                formData.append('return', 'json');

                try {
                    const res = await fetch('/api/resize.php', { method: 'POST', body: formData });
                    const result = await res.json();
                    if (result.success) {
                        results.push({
                            success: true,
                            filename: result.filename || f.name.replace(/\.[^.]+$/, '_resized.jpg'),
                            data: result.data,
                            view_url: result.view_url,
                            size: result.new_size,
                            width: result.new_width,
                            height: result.new_height
                        });
                    } else {
                        results.push({ success: false, error: result.error || 'Failed' });
                    }
                } catch (e) {
                    results.push({ success: false, error: e.message });
                }
            }

            sessionStorage.setItem('toolResults', JSON.stringify(results));
            window.location.href = '/member/result.php?type=tool&tool=resize&count=' + results.filter(r => r.success).length;
        });

        document.addEventListener('paste', e => {
            const items = e.clipboardData.items;
            for (const item of items) {
                if (item.type.startsWith('image/')) handleFiles([item.getAsFile()]);
            }
        });
    </script>
</body>
</html>
