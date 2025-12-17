<?php
/**
 * PixelHop - Crop Tool (Member)
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
    <title>Crop Images - PixelHop</title>
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
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .container {
            width: 100%; max-width: 900px;
            background: rgba(20, 20, 35, 0.85); backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 24px;
            padding: 32px; box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
        }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
        .title-section { display: flex; align-items: center; gap: 14px; }
        .title-icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #f472b6, #ec4899); display: flex; align-items: center; justify-content: center; }
        .nav-links { display: flex; gap: 8px; }
        .nav-link { padding: 10px 18px; border-radius: 10px; color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .upload-box { border: 2px dashed rgba(255, 255, 255, 0.15); border-radius: 16px; padding: 50px 20px; text-align: center; cursor: pointer; transition: all 0.3s; background: rgba(255, 255, 255, 0.02); margin-bottom: 24px; }
        .upload-box:hover { border-color: #f472b6; background: rgba(244, 114, 182, 0.05); }

        .crop-area { position: relative; max-width: 100%; margin-bottom: 24px; display: none; }
        .crop-area img { max-width: 100%; border-radius: 12px; }

        .options-panel { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 20px; margin-bottom: 24px; }
        .option-row { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
        .option-label { font-size: 14px; color: rgba(255, 255, 255, 0.8); min-width: 80px; }
        .size-input { width: 80px; padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(255, 255, 255, 0.05); color: #fff; font-size: 14px; text-align: center; }
        .size-input:focus { border-color: #f472b6; outline: none; }

        .action-bar { display: flex; gap: 12px; justify-content: flex-end; }
        .action-btn { padding: 14px 28px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: linear-gradient(135deg, #f472b6, #ec4899); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(244, 114, 182, 0.3); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        @media (max-width: 600px) { .nav-links { display: none; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title-section">
                <div class="title-icon"><i data-lucide="crop" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">Crop Images</h1>
                    <p class="text-xs text-white/50">Cut and trim images</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/member/tools.php" class="nav-link"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
                <a href="/dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
            </div>
        </div>

        <div class="upload-box" id="dropzone">
            <i data-lucide="upload-cloud" class="w-10 h-10 mx-auto mb-3 opacity-50"></i>
            <div class="text-sm text-white/70 mb-1">Drop image or click to browse</div>
            <input type="file" id="fileInput" accept="image/*" hidden>
        </div>

        <div class="crop-area" id="cropArea">
            <img id="cropImage" src="">
        </div>

        <div class="options-panel">
            <div class="option-row">
                <span class="option-label">Crop:</span>
                <span class="text-white/50">X:</span>
                <input type="number" class="size-input" id="cropX" value="0">
                <span class="text-white/50">Y:</span>
                <input type="number" class="size-input" id="cropY" value="0">
                <span class="text-white/50">W:</span>
                <input type="number" class="size-input" id="cropW" value="500">
                <span class="text-white/50">H:</span>
                <input type="number" class="size-input" id="cropH" value="500">
            </div>
        </div>

        <div class="action-bar">
            <button class="action-btn btn-primary" id="processBtn" disabled>
                <i data-lucide="crop" class="w-4 h-4"></i> Crop Image
            </button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const cropArea = document.getElementById('cropArea');
        const cropImage = document.getElementById('cropImage');
        const processBtn = document.getElementById('processBtn');

        let currentFile = null;

        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', e => {
            if (e.target.files[0]) {
                currentFile = e.target.files[0];
                const reader = new FileReader();
                reader.onload = e => {
                    cropImage.src = e.target.result;
                    cropArea.style.display = 'block';
                    dropzone.style.display = 'none';
                    processBtn.disabled = false;
                };
                reader.readAsDataURL(currentFile);
            }
        });

        processBtn.addEventListener('click', async () => {
            if (!currentFile) return;

            processBtn.disabled = true;
            processBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Processing...';

            const formData = new FormData();
            formData.append('image', currentFile);
            formData.append('x', document.getElementById('cropX').value);
            formData.append('y', document.getElementById('cropY').value);
            formData.append('width', document.getElementById('cropW').value);
            formData.append('height', document.getElementById('cropH').value);
            formData.append('return', 'json');

            try {
                const res = await fetch('/api/crop.php', { method: 'POST', body: formData });
                const result = await res.json();
                if (result.success) {
                    const results = [{
                        success: true,
                        filename: result.filename || 'cropped.jpg',
                        data: result.data,
                        view_url: result.view_url,
                        size: result.new_size,
                        width: result.crop_width,
                        height: result.crop_height
                    }];
                    sessionStorage.setItem('toolResults', JSON.stringify(results));
                    window.location.href = '/member/result.php?type=tool&tool=crop&count=1';
                } else {
                    alert('Failed: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Failed: ' + e.message);
            }

            processBtn.disabled = false;
        });
    </script>
</body>
</html>
