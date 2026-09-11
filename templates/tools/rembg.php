<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Remove Background (NEW) -->
                <div class="tool-card<?= !$toolStatus['rembg'] ? ' disabled' : '' ?>" <?= $toolStatus['rembg'] ? ($isLoggedIn ? 'onclick="openModal(\'rembg\')"' : 'onclick="requireLogin(\'Remove Background\')"') : '' ?>>
                    <?php if (!$toolStatus['rembg']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && $toolStatus['rembg']): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon" style="background: linear-gradient(135deg, #8b5cf6, #ec4899);">
                        <i data-lucide="eraser" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        Remove Background
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        AI-powered background removal in seconds. Perfect for product photos.
                    </p>
                </div>

<?php else: ?>
    <!-- Remove Background Modal -->
    <div id="modal-rembg" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('rembg')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('rembg')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="eraser" class="w-6 h-6 inline-block mr-2 text-purple-400"></i>
                Remove Background
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
            </h2>

            <form id="rembg-form" onsubmit="handleRembg(event)">
                <div class="drop-zone-mini" id="rembg-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">Best for: portraits, products, objects</p>
                    <input type="file" id="rembg-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="rembg-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="rembg-filename"></p>
                </div>

                <div class="processing" id="rembg-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Removing background... This may take a moment.</span>
                </div>

                <div class="result-area" id="rembg-result">
                    <div class="text-center mb-4">
                        <img src="" alt="Result" id="rembg-result-image" class="preview-image" style="max-height: 250px; background: repeating-conic-gradient(#808080 0% 25%, transparent 0% 50%) 50% / 16px 16px; border-radius: 8px;">
                    </div>
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="rembg-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="rembg-new-size">-</div>
                            <div class="stat-label">Result (PNG)</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full mt-4" id="rembg-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download PNG
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="rembg-submit">
                    <i data-lucide="eraser" class="w-4 h-4"></i>
                    Remove Background
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
