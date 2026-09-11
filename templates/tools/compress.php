<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Compress -->
                <div class="tool-card<?= !$toolStatus['compress'] ? ' disabled' : '' ?>" <?= $toolStatus['compress'] ? 'onclick="openModal(\'compress\')"' : '' ?>>
                    <?php if (!$toolStatus['compress']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-compress">
                        <i data-lucide="file-minus" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Compress</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Reduce file size without losing quality. Supports JPEG, PNG, WebP.
                    </p>
                </div>

<?php else: ?>
    <!-- Compress Modal -->
    <div id="modal-compress" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('compress')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('compress')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="file-minus" class="w-6 h-6 inline-block mr-2 text-cyan-400"></i>
                Compress Image
            </h2>

            <form id="compress-form" onsubmit="handleCompress(event)">
                <div class="drop-zone-mini" id="compress-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="compress-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="compress-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="compress-filename"></p>
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Quality: <span id="compress-quality-value">80</span>%</label>
                    <input type="range" min="10" max="100" value="80" class="w-full" id="compress-quality"
                           oninput="document.getElementById('compress-quality-value').textContent = this.value">
                </div>

                <div class="form-group">
                    <label class="form-label">Output Format</label>
                    <select class="form-select" id="compress-format">
                        <option value="auto">Auto (WebP for best compression)</option>
                        <option value="original">Keep Original Format</option>
                    </select>
                </div>

                <div class="processing" id="compress-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Compressing...</span>
                </div>

                <div class="result-area" id="compress-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="compress-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="compress-new-size">-</div>
                            <div class="stat-label">Compressed</div>
                        </div>
                    </div>
                    <p class="text-center mb-4" style="color: var(--color-accent);" id="compress-savings"></p>
                    <button type="button" class="btn-primary w-full" id="compress-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="compress-submit">
                    <i data-lucide="zap" class="w-4 h-4"></i>
                    Compress Image
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
