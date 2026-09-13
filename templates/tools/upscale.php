<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- AI HD Upscale (NEW) -->
                <div class="tool-card<?= !$toolStatus['upscale'] ? ' disabled' : '' ?>" <?= $toolStatus['upscale'] ? ($isLoggedIn ? 'onclick="openModal(\'upscale\')"' : 'onclick="requireLogin(\'AI HD Upscale\')"') : '' ?>>
                    <?php if (!$toolStatus['upscale']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && $toolStatus['upscale']): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon" style="background: linear-gradient(135deg, #22d3ee, #a855f7);">
                        <i data-lucide="sparkles" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        AI HD Upscale
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Enlarge images 2x or 4x without losing quality using AI.
                    </p>
                </div>

<?php else: ?>
    <!-- AI HD Upscale Modal -->
    <div id="modal-upscale" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('upscale')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('upscale')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="sparkles" class="w-6 h-6 inline-block mr-2 text-cyan-400"></i>
                AI HD Upscale
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
            </h2>

            <form id="upscale-form" onsubmit="handleUpscale(event)">
                <div class="drop-zone-mini" id="upscale-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">Best for: photos, artwork, logos</p>
                    <input type="file" id="upscale-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="upscale-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="upscale-filename"></p>
                </div>

                <div class="form-group mt-4">
                    <label class="form-label" for="upscale-scale">Scale Factor</label>
                    <select id="upscale-scale" class="form-select">
                        <option value="2" selected>2x (Recommended)</option>
                        <option value="4">4x</option>
                    </select>
                </div>

                <div class="processing" id="upscale-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Analyzing... Enhancing... This may take a moment.</span>
                </div>

                <div class="result-area" id="upscale-result">
                    <div class="text-center mb-4">
                        <img src="" alt="Result" id="upscale-result-image" class="preview-image" style="max-height: 250px; background: repeating-conic-gradient(#808080 0% 25%, transparent 0% 50%) 50% / 16px 16px; border-radius: 8px;">
                    </div>
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="upscale-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="upscale-new-size">-</div>
                            <div class="stat-label">Result (PNG)</div>
                        </div>
                    </div>
                    <p class="text-sm text-center mt-2" style="color: var(--color-text-muted);" id="upscale-dimensions"></p>
                    <button type="button" class="btn-primary w-full mt-4" id="upscale-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download PNG
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="upscale-submit">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                    Upscale Image
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
