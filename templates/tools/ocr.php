<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- OCR -->
                <div class="tool-card<?= !$toolStatus['ocr'] ? ' disabled' : '' ?>" <?= $toolStatus['ocr'] ? ($isLoggedIn ? 'onclick="openModal(\'ocr\')"' : 'onclick="requireLogin(\'OCR\')"') : '' ?>>
                    <?php if (!$toolStatus['ocr']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && $toolStatus['ocr']): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon tool-icon-ocr">
                        <i data-lucide="scan-text" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">OCR</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Extract text from images. Supports multiple languages.
                    </p>
                </div>

<?php else: ?>
    <!-- OCR Modal -->
    <div id="modal-ocr" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('ocr')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('ocr')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="scan-text" class="w-6 h-6 inline-block mr-2 text-emerald-400"></i>
                Extract Text (OCR)
            </h2>

            <form id="ocr-form" onsubmit="handleOCR(event)">
                <div class="drop-zone-mini" id="ocr-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="ocr-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="ocr-preview">
                    <img src="" alt="Preview" class="preview-image">
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Language</label>
                    <select class="form-select" id="ocr-language">
                        <option value="eng">English</option>
                        <option value="ind">Indonesian</option>
                        <option value="jpn">Japanese</option>
                        <option value="chi_sim">Chinese (Simplified)</option>
                        <option value="chi_tra">Chinese (Traditional)</option>
                        <option value="kor">Korean</option>
                        <option value="eng+ind">English + Indonesian</option>
                        <option value="eng+jpn">English + Japanese</option>
                    </select>
                </div>

                <div class="processing" id="ocr-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Extracting text...</span>
                </div>

                <div class="result-area" id="ocr-result">
                    <div class="result-stats mb-4">
                        <div class="stat-item">
                            <div class="stat-value" id="ocr-word-count">-</div>
                            <div class="stat-label">Words</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="ocr-line-count">-</div>
                            <div class="stat-label">Lines</div>
                        </div>
                    </div>
                    <div class="ocr-result" id="ocr-text"></div>
                    <button type="button" class="btn-primary w-full mt-4" id="ocr-copy">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                        Copy Text
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="ocr-submit">
                    <i data-lucide="scan-text" class="w-4 h-4"></i>
                    Extract Text
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
