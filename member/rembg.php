<?php
/**
 * PixelHop - Remove Background Tool (Member)
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

// Get quota from settings
$db = Database::getInstance();
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';

// Read limits from database settings
$rembgLimit = $isAdmin ? PHP_INT_MAX : (int)$gatekeeper->getSetting($isPremium ? 'daily_removebg_limit_premium' : 'daily_removebg_limit_free', $isPremium ? 30 : 3);

$today = date('Y-m-d');
$userId = $currentUser['id'];
$rembgUsageStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND tool_name = 'rembg' AND DATE(created_at) = ?");
$rembgUsageStmt->execute([$userId, $today]);
$rembgUsed = (int)$rembgUsageStmt->fetchColumn();
$rembgRemaining = $isAdmin ? 'Unlimited' : max(0, $rembgLimit - $rembgUsed);

// Quota enforcement - redirect if no uses remaining
if (!$isAdmin && $rembgRemaining <= 0) {
    header('Location: /member/tools.php?error=quota_exceeded&tool=rembg');
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remove Background - PixelHop</title>
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
        .title-icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #ec4899, #be185d); display: flex; align-items: center; justify-content: center; }
        .nav-links { display: flex; gap: 8px; }
        .nav-link { padding: 10px 18px; border-radius: 10px; color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .quota-badge { padding: 6px 14px; background: rgba(236, 72, 153, 0.15); border-radius: 8px; font-size: 12px; color: #ec4899; }

        .upload-section { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        @media (max-width: 700px) { .upload-section { grid-template-columns: 1fr; } .nav-links { display: none; } }

        .upload-box { border: 2px dashed rgba(255, 255, 255, 0.15); border-radius: 16px; padding: 40px 20px; text-align: center; cursor: pointer; }
        .upload-box:hover { border-color: #ec4899; background: rgba(236, 72, 153, 0.05); }

        .url-panel { display: flex; flex-direction: column; gap: 12px; }
        .url-input { padding: 14px 16px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(255, 255, 255, 0.05); color: #fff; font-size: 13px; }
        .url-input:focus { border-color: #ec4899; outline: none; }
        .add-url-btn { padding: 12px; border-radius: 10px; background: rgba(236, 72, 153, 0.15); color: #ec4899; border: 1px solid rgba(236, 72, 153, 0.2); cursor: pointer; }

        .comparison { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        @media (max-width: 700px) { .comparison { grid-template-columns: 1fr; } }

        .compare-box { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 16px; min-height: 250px; display: flex; align-items: center; justify-content: center; position: relative; }
        .compare-label { position: absolute; top: 12px; left: 12px; font-size: 11px; color: rgba(255, 255, 255, 0.4); background: rgba(0,0,0,0.4); padding: 4px 10px; border-radius: 6px; }
        .compare-img { max-width: 100%; max-height: 280px; border-radius: 10px; }
        .result-transparent { background: repeating-conic-gradient(#333 0% 25%, #222 0% 50%) 50% / 16px 16px; }
        .empty-state { color: rgba(255, 255, 255, 0.3); text-align: center; }

        .action-bar { display: flex; gap: 12px; justify-content: flex-end; }
        .action-btn { padding: 14px 28px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: linear-gradient(135deg, #ec4899, #be185d); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.15); }

        .download-bar { display: none; gap: 12px; margin-top: 16px; justify-content: center; }
        .download-bar.visible { display: flex; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title-section">
                <div class="title-icon"><i data-lucide="eraser" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">Remove Background</h1>
                    <p class="text-xs text-white/50">AI-powered background removal</p>
                </div>
            </div>
            <div class="quota-badge">
                <i data-lucide="sparkles" class="w-3 h-3 inline"></i>
                <?= $rembgRemaining ?> <?= is_numeric($rembgRemaining) ? 'uses left today' : '' ?>
            </div>
            <div class="nav-links">
                <a href="/member/tools.php" class="nav-link"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
            </div>
        </div>

        <div class="upload-section" id="uploadSection">
            <div class="upload-box" id="dropzone">
                <i data-lucide="upload-cloud" class="w-10 h-10 mx-auto mb-3 opacity-50"></i>
                <div class="text-sm text-white/70">Drop image or click</div>
                <input type="file" id="fileInput" accept="image/*" hidden>
            </div>
            <div class="upload-box url-panel">
                <input type="text" class="url-input" id="urlInput" placeholder="Paste image URL...">
                <button class="add-url-btn" id="urlBtn">Fetch from URL</button>
            </div>
        </div>

        <div class="comparison">
            <div class="compare-box">
                <span class="compare-label">Original</span>
                <div class="empty-state" id="origEmpty"><i data-lucide="image" class="w-10 h-10 mx-auto opacity-30"></i><br>No image</div>
                <img class="compare-img" id="origImg" src="" style="display: none;">
            </div>
            <div class="compare-box result-transparent">
                <span class="compare-label">Result</span>
                <div class="empty-state" id="resultEmpty"><i data-lucide="image-off" class="w-10 h-10 mx-auto opacity-30"></i><br>Result</div>
                <img class="compare-img" id="resultImg" src="" style="display: none;">
            </div>
        </div>

        <div class="action-bar">
            <button class="action-btn btn-primary" id="processBtn" disabled>
                <i data-lucide="eraser" class="w-4 h-4"></i> Remove Background
            </button>
        </div>

        <div class="download-bar" id="downloadBar">
            <button class="action-btn btn-secondary" id="downloadPng"><i data-lucide="download" class="w-4 h-4"></i> Download PNG</button>
            <button class="action-btn btn-secondary" id="newBtn"><i data-lucide="plus" class="w-4 h-4"></i> Process Another</button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const urlInput = document.getElementById('urlInput');
        const urlBtn = document.getElementById('urlBtn');
        const origEmpty = document.getElementById('origEmpty');
        const origImg = document.getElementById('origImg');
        const resultEmpty = document.getElementById('resultEmpty');
        const resultImg = document.getElementById('resultImg');
        const processBtn = document.getElementById('processBtn');
        const downloadBar = document.getElementById('downloadBar');
        const downloadPng = document.getElementById('downloadPng');
        const newBtn = document.getElementById('newBtn');

        let currentFile = null;
        let currentUrl = null;
        let resultBlob = null;

        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', e => {
            if (e.target.files[0]) {
                currentFile = e.target.files[0];
                currentUrl = null;
                const reader = new FileReader();
                reader.onload = e => {
                    origImg.src = e.target.result;
                    origImg.style.display = 'block';
                    origEmpty.style.display = 'none';
                    processBtn.disabled = false;
                };
                reader.readAsDataURL(currentFile);
            }
        });

        urlBtn.addEventListener('click', () => {
            const url = urlInput.value.trim();
            if (url) {
                currentUrl = url;
                currentFile = null;
                origImg.src = url;
                origImg.style.display = 'block';
                origEmpty.style.display = 'none';
                processBtn.disabled = false;
            }
        });

        processBtn.addEventListener('click', async () => {
            processBtn.disabled = true;
            processBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Processing...';
            resultImg.style.display = 'none';
            resultEmpty.innerHTML = '<i data-lucide="loader" class="w-10 h-10 mx-auto animate-spin"></i><br>Removing...';

            const formData = new FormData();
            if (currentFile) {
                formData.append('image', currentFile);
            } else if (currentUrl) {
                formData.append('url', currentUrl);
            }
            formData.append('return', 'json');

            try {
                const res = await fetch('/api/rembg.php', { method: 'POST', body: formData });
                const data = await res.json();


                if (data.success && (data.data || data.view_url)) {

                    const imageUrl = data.view_url || data.data;
                    resultImg.src = imageUrl;
                    resultImg.style.display = 'block';
                    resultEmpty.style.display = 'none';
                    downloadBar.classList.add('visible');


                    if (data.view_url) {
                        const blobRes = await fetch(data.view_url);
                        resultBlob = await blobRes.blob();
                    } else if (data.data) {

                        const byteString = atob(data.data.split(',')[1]);
                        const mimeType = data.data.split(',')[0].split(':')[1].split(';')[0];
                        const ab = new ArrayBuffer(byteString.length);
                        const ia = new Uint8Array(ab);
                        for (let i = 0; i < byteString.length; i++) {
                            ia[i] = byteString.charCodeAt(i);
                        }
                        resultBlob = new Blob([ab], { type: mimeType });
                    }
                } else {
                    alert('RemBG failed: ' + (data.error || 'Unknown error'));
                    resultEmpty.innerHTML = '<i data-lucide="alert-circle" class="w-10 h-10 mx-auto text-red-400"></i><br>Failed';
                }
            } catch (e) {
                alert('Error: ' + e.message);
                resultEmpty.innerHTML = '<i data-lucide="alert-circle" class="w-10 h-10 mx-auto text-red-400"></i><br>Error';
            }

            processBtn.disabled = false;
            processBtn.innerHTML = '<i data-lucide="eraser" class="w-4 h-4"></i> Remove Background';
            lucide.createIcons();
        });

        downloadPng.addEventListener('click', () => {
            if (resultBlob) {
                const a = document.createElement('a');
                a.href = URL.createObjectURL(resultBlob);
                a.download = 'rembg_' + Date.now() + '.png';
                a.click();
            }
        });

        newBtn.addEventListener('click', () => {
            currentFile = null;
            currentUrl = null;
            resultBlob = null;
            origImg.style.display = 'none';
            origEmpty.style.display = 'block';
            resultImg.style.display = 'none';
            resultEmpty.style.display = 'block';
            resultEmpty.innerHTML = '<i data-lucide="image-off" class="w-10 h-10 mx-auto opacity-30"></i><br>Result';
            downloadBar.classList.remove('visible');
            processBtn.disabled = true;
            urlInput.value = '';
            fileInput.value = '';
            lucide.createIcons();
        });
    </script>
</body>
</html>
