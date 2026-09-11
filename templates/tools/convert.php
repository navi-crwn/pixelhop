<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Convert -->
                <div class="tool-card<?= !$toolStatus['convert'] ? ' disabled' : '' ?>" <?= $toolStatus['convert'] ? 'onclick="openModal(\'convert\')"' : '' ?>>
                    <?php if (!$toolStatus['convert']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-convert">
                        <i data-lucide="repeat" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Convert</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Convert between formats: JPEG, PNG, WebP, GIF, BMP.
                    </p>
                </div>

<?php else: ?>
    <!-- Convert Modal -->
    <div id="modal-convert" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('convert')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('convert')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="repeat" class="w-6 h-6 inline-block mr-2 text-orange-400"></i>
                Convert Format
            </h2>

            <form id="convert-form" onsubmit="handleConvert(event)">
                <div class="drop-zone-mini" id="convert-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="convert-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="convert-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="convert-source-format"></p>
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Convert to</label>
                    <select class="form-select" id="convert-format">
                        <option value="webp">WebP (Modern, small size)</option>
                        <option value="jpg">JPEG (Universal)</option>
                        <option value="png">PNG (Lossless, transparency)</option>
                        <option value="gif">GIF (Animation support)</option>
                        <option value="bmp">BMP (Uncompressed)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Quality: <span id="convert-quality-value">90</span>%</label>
                    <input type="range" min="10" max="100" value="90" class="w-full" id="convert-quality"
                           oninput="document.getElementById('convert-quality-value').textContent = this.value">
                </div>

                <div class="processing" id="convert-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Converting...</span>
                </div>

                <div class="result-area" id="convert-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="convert-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="convert-new-size">-</div>
                            <div class="stat-label">Converted</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full" id="convert-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="convert-submit">
                    <i data-lucide="repeat" class="w-4 h-4"></i>
                    Convert Image
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>
