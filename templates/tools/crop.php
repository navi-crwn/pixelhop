<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Crop -->
                <div class="tool-card<?= !$toolStatus['crop'] ? ' disabled' : '' ?>" <?= $toolStatus['crop'] ? 'onclick="openModal(\'crop\')"' : '' ?>>
                    <?php if (!$toolStatus['crop']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-crop">
                        <i data-lucide="crop" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Crop</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Crop to standard ratios: 1:1, 4:3, 16:9, or custom dimensions.
                    </p>
                </div>

<?php else: ?>
    <!-- Crop Modal -->
    <div id="modal-crop" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('crop')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('crop')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="crop" class="w-6 h-6 inline-block mr-2 text-pink-400"></i>
                Crop Image
            </h2>

            <form id="crop-form" onsubmit="handleCrop(event)">
                <div class="drop-zone-mini" id="crop-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="crop-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="crop-preview">
                    <img src="" alt="Preview" class="preview-image">
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Aspect Ratio</label>
                    <select class="form-select" id="crop-aspect">
                        <option value="">Free (Custom)</option>
                        <option value="1:1">1:1 (Square)</option>
                        <option value="4:3">4:3 (Standard)</option>
                        <option value="3:4">3:4 (Portrait)</option>
                        <option value="16:9">16:9 (Widescreen)</option>
                        <option value="9:16">9:16 (Stories)</option>
                        <option value="3:2">3:2 (Photo)</option>
                        <option value="2:3">2:3 (Portrait Photo)</option>
                    </select>
                </div>

                <div class="processing" id="crop-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Cropping...</span>
                </div>

                <div class="result-area" id="crop-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="crop-original-dims">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="crop-new-dims">-</div>
                            <div class="stat-label">Cropped</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full" id="crop-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="crop-submit">
                    <i data-lucide="crop" class="w-4 h-4"></i>
                    Crop Image
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
