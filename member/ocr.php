<?php
/**
 * PixelHop - OCR Tool (Member)
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
$ocrLimit = $isAdmin ? PHP_INT_MAX : (int)$gatekeeper->getSetting($isPremium ? 'daily_ocr_limit_premium' : 'daily_ocr_limit_free', $isPremium ? 50 : 5);

$today = date('Y-m-d');
$userId = $currentUser['id'];
$ocrUsageStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND tool_name = 'ocr' AND DATE(created_at) = ?");
$ocrUsageStmt->execute([$userId, $today]);
$ocrUsed = (int)$ocrUsageStmt->fetchColumn();
$ocrRemaining = $isAdmin ? 'Unlimited' : max(0, $ocrLimit - $ocrUsed);

// Quota enforcement - redirect if no uses remaining
if (!$isAdmin && $ocrRemaining <= 0) {
    header('Location: /member/tools.php?error=quota_exceeded&tool=ocr');
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR - Extract Text - PixelHop</title>
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
        .title-icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #f59e0b, #d97706); display: flex; align-items: center; justify-content: center; }
        .nav-links { display: flex; gap: 8px; }
        .nav-link { padding: 10px 18px; border-radius: 10px; color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .quota-badge { padding: 6px 14px; background: rgba(245, 158, 11, 0.15); border-radius: 8px; font-size: 12px; color: #f59e0b; }

        .upload-section { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        @media (max-width: 700px) { .upload-section { grid-template-columns: 1fr; } .nav-links { display: none; } }

        .upload-box { border: 2px dashed rgba(255, 255, 255, 0.15); border-radius: 16px; padding: 40px 20px; text-align: center; cursor: pointer; }
        .upload-box:hover { border-color: #f59e0b; background: rgba(245, 158, 11, 0.05); }

        .url-panel { display: flex; flex-direction: column; gap: 12px; }
        .url-input { padding: 14px 16px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(255, 255, 255, 0.05); color: #fff; font-size: 13px; }
        .url-input:focus { border-color: #f59e0b; outline: none; }
        .add-url-btn { padding: 12px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); cursor: pointer; }

        .options-panel { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 20px; margin-bottom: 24px; }
        .lang-select { padding: 10px 16px; border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: #fff; border: 1px solid rgba(255, 255, 255, 0.1); }

        .preview-area { margin-bottom: 24px; display: none; }
        .preview-img { max-width: 100%; max-height: 300px; border-radius: 12px; }

        .result-area { margin-bottom: 24px; display: none; }
        .result-box { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 20px; }
        .result-text { color: #fff; font-size: 14px; line-height: 1.8; white-space: pre-wrap; }
        .copy-btn { margin-top: 12px; padding: 10px 20px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: none; border-radius: 8px; cursor: pointer; }

        .action-bar { display: flex; gap: 12px; justify-content: flex-end; }
        .action-btn { padding: 14px 28px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title-section">
                <div class="title-icon"><i data-lucide="scan-text" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">OCR - Text Extraction</h1>
                    <p class="text-xs text-white/50">Extract text from images</p>
                </div>
            </div>
            <div class="quota-badge">
                <i data-lucide="zap" class="w-3 h-3 inline"></i>
                <?= $ocrRemaining ?> <?= is_numeric($ocrRemaining) ? 'uses left today' : '' ?>
            </div>
            <div class="nav-links">
                <a href="/member/tools.php" class="nav-link"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
            </div>
        </div>

        <div class="upload-section">
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

        <div class="options-panel">
            <label class="text-sm text-white/70">Language: </label>
            <select class="lang-select" id="langSelect">
                <option value="en">English</option>
                <option value="ch">Chinese</option>
                <option value="id">Indonesian</option>
                <option value="japan">Japanese</option>
                <option value="korean">Korean</option>
            </select>
        </div>

        <div class="preview-area" id="previewArea">
            <img class="preview-img" id="previewImg" src="">
        </div>

        <div class="result-area" id="resultArea">
            <div class="result-box">
                <div class="result-text" id="resultText"></div>
                <button class="copy-btn" id="copyBtn"><i data-lucide="copy" class="w-4 h-4 inline"></i> Copy Text</button>
            </div>
        </div>

        <div class="action-bar">
            <button class="action-btn btn-primary" id="processBtn" disabled>
                <i data-lucide="scan-text" class="w-4 h-4"></i> Extract Text
            </button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const urlInput = document.getElementById('urlInput');
        const urlBtn = document.getElementById('urlBtn');
        const previewArea = document.getElementById('previewArea');
        const previewImg = document.getElementById('previewImg');
        const resultArea = document.getElementById('resultArea');
        const resultText = document.getElementById('resultText');
        const processBtn = document.getElementById('processBtn');
        const copyBtn = document.getElementById('copyBtn');
        const langSelect = document.getElementById('langSelect');

        let currentFile = null;
        let currentUrl = null;

        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', e => {
            if (e.target.files[0]) {
                currentFile = e.target.files[0];
                currentUrl = null;
                const reader = new FileReader();
                reader.onload = e => {
                    previewImg.src = e.target.result;
                    previewArea.style.display = 'block';
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
                previewImg.src = url;
                previewArea.style.display = 'block';
                processBtn.disabled = false;
            }
        });

        processBtn.addEventListener('click', async () => {
            processBtn.disabled = true;
            processBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Processing...';
            resultArea.style.display = 'none';

            const formData = new FormData();
            if (currentFile) {
                formData.append('image', currentFile);
            } else if (currentUrl) {
                formData.append('url', currentUrl);
            }
            formData.append('language', langSelect.value);

            try {
                const res = await fetch('/api/ocr.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    resultText.textContent = data.text || 'No text detected';
                    resultArea.style.display = 'block';
                } else {
                    alert('OCR failed: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }

            processBtn.disabled = false;
            processBtn.innerHTML = '<i data-lucide="scan-text" class="w-4 h-4"></i> Extract Text';
            lucide.createIcons();
        });

        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(resultText.textContent);
            copyBtn.innerHTML = '<i data-lucide="check" class="w-4 h-4 inline"></i> Copied!';
            setTimeout(() => {
                copyBtn.innerHTML = '<i data-lucide="copy" class="w-4 h-4 inline"></i> Copy Text';
                lucide.createIcons();
            }, 2000);
            lucide.createIcons();
        });
    </script>
</body>
</html>
