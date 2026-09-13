<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Color Palette -->
                <div class="tool-card<?= !$toolStatus['palette'] ? ' disabled' : '' ?>" <?= $toolStatus['palette'] ? 'onclick="openModal(\'palette\')"' : '' ?>>
                    <?php if (!$toolStatus['palette']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon" style="background: linear-gradient(135deg, #f97316, #ef4444);">
                        <i data-lucide="palette" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        Color Palette
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">Instant</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Extract dominant colors from any image. Get hex codes instantly.
                    </p>
                </div>

<?php else: ?>
    <!-- Color Palette Modal -->
    <div id="modal-palette" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('palette')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('palette')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="palette" class="w-6 h-6 inline-block mr-2 text-orange-400"></i>
                Color Palette
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">Instant</span>
            </h2>

            <form id="palette-form" onsubmit="handlePalette(event)">
                <div class="drop-zone-mini" id="palette-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">JPG, PNG, WebP, GIF, BMP</p>
                    <input type="file" id="palette-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="palette-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="palette-filename"></p>
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Number of colors: <span id="palette-count-value">6</span></label>
                    <input type="range" min="4" max="10" value="6" class="w-full" id="palette-count"
                           oninput="document.getElementById('palette-count-value').textContent = this.value">
                </div>

                <div class="processing" id="palette-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Extracting colors...</span>
                </div>

                <div class="result-area" id="palette-result">
                    <p class="text-sm mb-4" style="color: var(--color-text-tertiary);">Click any color to copy its hex code.</p>
                    <div id="palette-swatches" class="palette-swatches"></div>
                    <p class="text-center mt-4 text-xs" id="palette-copied" style="color: var(--color-accent); display: none;">Copied to clipboard!</p>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="palette-submit">
                    <i data-lucide="palette" class="w-4 h-4"></i>
                    Extract Palette
                </button>
            </form>
        </div>
    </div>

    <style>
        .palette-swatches {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 10px;
        }
        .palette-swatch {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .palette-swatch:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }
        .palette-swatch-color {
            height: 64px;
        }
        .palette-swatch-meta {
            padding: 8px;
            text-align: center;
            background: var(--glass-bg);
        }
        .palette-swatch-hex {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text-primary);
            font-family: monospace;
        }
        .palette-swatch-percent {
            font-size: 11px;
            color: var(--color-text-muted);
        }
    </style>

    <script>
        function notify(msg, type) {
            type = type || 'error';
            if (typeof window.showToast === 'function') {
                window.showToast(msg, type);
            } else if (typeof showToast === 'function') {
                showToast(msg, type);
            } else {
                console.error(msg);
            }
        }

        async function handlePalette(e) {
            e.preventDefault();
            if (!fileData.palette) {
                notify('Please select an image first', 'error');
                return;
            }

            const processing = document.getElementById('palette-processing');
            const result = document.getElementById('palette-result');
            const submit = document.getElementById('palette-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.palette);
            formData.append('count', document.getElementById('palette-count').value);

            try {
                const response = await fetch('/api/palette.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                const swatches = document.getElementById('palette-swatches');
                swatches.innerHTML = '';

                (data.colors || []).forEach(color => {
                    const swatch = document.createElement('div');
                    swatch.className = 'palette-swatch';
                    swatch.title = 'Copy ' + color.hex;

                    const colorBlock = document.createElement('div');
                    colorBlock.className = 'palette-swatch-color';
                    colorBlock.style.backgroundColor = color.hex;

                    const meta = document.createElement('div');
                    meta.className = 'palette-swatch-meta';

                    const hex = document.createElement('div');
                    hex.className = 'palette-swatch-hex';
                    hex.textContent = color.hex;

                    const percent = document.createElement('div');
                    percent.className = 'palette-swatch-percent';
                    percent.textContent = (color.percent || 0) + '%';

                    meta.appendChild(hex);
                    meta.appendChild(percent);
                    swatch.appendChild(colorBlock);
                    swatch.appendChild(meta);

                    swatch.addEventListener('click', () => {
                        navigator.clipboard.writeText(color.hex).then(() => {
                            const copied = document.getElementById('palette-copied');
                            copied.textContent = 'Copied ' + color.hex + ' to clipboard!';
                            copied.style.display = 'block';
                            clearTimeout(swatch._copiedTimeout);
                            swatch._copiedTimeout = setTimeout(() => {
                                copied.style.display = 'none';
                            }, 2000);
                        }).catch(() => {
                            notify('Could not copy. Hex: ' + color.hex, 'error');
                        });
                    });

                    swatches.appendChild(swatch);
                });

                result.classList.add('show');
            } catch (err) {
                notify('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }
    </script>

<?php endif; ?>
