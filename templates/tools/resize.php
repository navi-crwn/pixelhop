<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Resize -->
                <div class="tool-card<?= !$toolStatus['resize'] ? ' disabled' : '' ?>" <?= $toolStatus['resize'] ? 'onclick="openModal(\'resize\')"' : '' ?>>
                    <?php if (!$toolStatus['resize']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-resize">
                        <i data-lucide="scaling" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Resize</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Change dimensions while maintaining aspect ratio or exact sizes.
                    </p>
                </div>

<?php else: ?>
    <!-- Resize Modal -->
    <div id="modal-resize" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('resize')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('resize')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="scaling" class="w-6 h-6 inline-block mr-2 text-purple-400"></i>
                Resize Image
            </h2>

            <form id="resize-form" onsubmit="handleResize(event)">
                <div class="drop-zone-mini" id="resize-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="resize-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="resize-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="resize-dimensions"></p>
                </div>

                <div class="form-row mt-6">
                    <div class="form-group">
                        <label class="form-label">Width (px)</label>
                        <div class="number-input-inline">
                            <button type="button" onclick="adjustNumber('resize-width', -10)">−</button>
                            <input type="number" class="form-input" id="resize-width" placeholder="Auto" min="1">
                            <button type="button" onclick="adjustNumber('resize-width', 10)">+</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Height (px)</label>
                        <div class="number-input-inline">
                            <button type="button" onclick="adjustNumber('resize-height', -10)">−</button>
                            <input type="number" class="form-input" id="resize-height" placeholder="Auto" min="1">
                            <button type="button" onclick="adjustNumber('resize-height', 10)">+</button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mode</label>
                    <select class="form-select" id="resize-mode">
                        <option value="fit">Fit (maintain aspect ratio)</option>
                        <option value="fill">Fill (crop to fit)</option>
                        <option value="exact">Exact (may distort)</option>
                    </select>
                </div>

                <div class="processing" id="resize-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Resizing...</span>
                </div>

                <div class="result-area" id="resize-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="resize-original-dims">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="resize-new-dims">-</div>
                            <div class="stat-label">New Size</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full" id="resize-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="resize-submit">
                    <i data-lucide="scaling" class="w-4 h-4"></i>
                    Resize Image
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
