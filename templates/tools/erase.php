<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Magic Eraser (NEW) -->
                <div class="tool-card<?= !$toolStatus['erase'] ? ' disabled' : '' ?>" <?= $toolStatus['erase'] ? ($isLoggedIn ? 'onclick="openModal(\'erase\')"' : 'onclick="requireLogin(\'Magic Eraser\')"') : '' ?>>
                    <?php if (!$toolStatus['erase']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && $toolStatus['erase']): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon" style="background: linear-gradient(135deg, #f97316, #ec4899);">
                        <i data-lucide="wand-2" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        Magic Eraser
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Remove objects, people, or watermarks by painting over them.
                    </p>
                </div>

<?php else: ?>
    <!-- Magic Eraser Modal -->
    <div id="modal-erase" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('erase')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('erase')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="wand-2" class="w-6 h-6 inline-block mr-2 text-orange-400"></i>
                Magic Eraser
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
            </h2>

            <form id="erase-form" onsubmit="handleErase(event)">
                <div class="drop-zone-mini" id="erase-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">Best for: objects, people, watermarks</p>
                    <input type="file" id="erase-file" accept="image/*" class="hidden">
                </div>

                <div id="erase-editor" style="display:none; margin-top: 20px;">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label" for="erase-brush-size">Brush Size: <span id="erase-brush-label">40</span>px</label>
                        <input type="range" id="erase-brush-size" min="8" max="120" value="40" class="erase-brush-range" style="width: 100%;">
                    </div>
                    <div style="position: relative; display: inline-block; max-width: 100%; line-height: 0;">
                        <canvas id="erase-canvas" class="erase-canvas" style="max-width: 100%; height: auto; border-radius: 8px; cursor: crosshair; touch-action: none; border: 1px solid rgba(255,255,255,0.15);"></canvas>
                    </div>
                    <div class="flex items-center justify-between mt-3">
                        <p class="text-xs" style="color: var(--color-text-muted);" id="erase-hint">Paint over the object or watermark you want to remove.</p>
                        <button type="button" id="erase-clear" style="padding: 6px 14px; font-size: 12px; background: rgba(255,255,255,0.08); color: var(--color-text-secondary); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; cursor: pointer;">Clear</button>
                    </div>
                </div>

                <div class="preview-container" id="erase-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="erase-filename"></p>
                </div>

                <div class="processing" id="erase-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Erasing marked area... This may take a moment.</span>
                </div>

                <div class="result-area" id="erase-result">
                    <div class="text-center mb-4">
                        <img src="" alt="Result" id="erase-result-image" class="preview-image" style="max-height: 250px; border-radius: 8px;">
                    </div>
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="erase-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="erase-new-size">-</div>
                            <div class="stat-label">Result (PNG)</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full mt-4" id="erase-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download PNG
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="erase-submit">
                    <i data-lucide="wand-2" class="w-4 h-4"></i>
                    Erase Area
                </button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var erase = window.eraseState = {
                canvas: document.getElementById('erase-canvas'),
                ctx: null,
                maskCanvas: null,
                maskCtx: null,
                image: null,
                drawing: false,
                lastX: 0,
                lastY: 0,
                scale: 1,
                painted: false
            };

            function setupEraseCanvas() {
                if (!erase.canvas) {
                    erase.canvas = document.getElementById('erase-canvas');
                }
                if (!erase.canvas || erase.initializedEvents) return;
                erase.initializedEvents = true;
                erase.ctx = erase.canvas.getContext('2d');

                var brushSlider = document.getElementById('erase-brush-size');
                var brushLabel = document.getElementById('erase-brush-label');

                brushSlider.addEventListener('input', function () {
                    brushLabel.textContent = brushSlider.value;
                });

                erase.canvas.addEventListener('pointerdown', function (e) {
                    erase.drawing = true;
                    erase.painted = true;
                    var pos = getErasePos(e);
                    erase.lastX = pos.x;
                    erase.lastY = pos.y;
                    drawEraseDot(pos.x, pos.y);
                    e.preventDefault();
                });

                erase.canvas.addEventListener('pointermove', function (e) {
                    if (!erase.drawing) return;
                    var pos = getErasePos(e);
                    drawEraseLine(erase.lastX, erase.lastY, pos.x, pos.y);
                    erase.lastX = pos.x;
                    erase.lastY = pos.y;
                    e.preventDefault();
                });

                window.addEventListener('pointerup', function () {
                    erase.drawing = false;
                });

                erase.canvas.addEventListener('pointerleave', function () {
                    erase.drawing = false;
                });

                document.getElementById('erase-clear').addEventListener('click', function () {
                    if (erase.ctx && erase.image) {
                        erase.ctx.clearRect(0, 0, erase.canvas.width, erase.canvas.height);
                        erase.ctx.drawImage(erase.image, 0, 0, erase.canvas.width, erase.canvas.height);
                    }
                    if (erase.maskCtx && erase.maskCanvas) {
                        erase.maskCtx.clearRect(0, 0, erase.maskCanvas.width, erase.maskCanvas.height);
                    }
                    erase.painted = false;
                });
            }

            function getErasePos(e) {
                var rect = erase.canvas.getBoundingClientRect();
                return {
                    x: (e.clientX - rect.left) * (erase.canvas.width / rect.width),
                    y: (e.clientY - rect.top) * (erase.canvas.height / rect.height)
                };
            }

            function drawEraseDot(x, y) {
                var size = parseInt(document.getElementById('erase-brush-size').value, 10) || 40;

                if (erase.ctx) {
                    erase.ctx.fillStyle = 'rgba(255, 0, 0, 0.55)';
                    erase.ctx.beginPath();
                    erase.ctx.arc(x, y, size / 2, 0, Math.PI * 2);
                    erase.ctx.fill();
                }

                if (erase.maskCtx) {
                    erase.maskCtx.fillStyle = '#ffffff';
                    erase.maskCtx.beginPath();
                    erase.maskCtx.arc(x, y, size / 2, 0, Math.PI * 2);
                    erase.maskCtx.fill();
                }
            }

            function drawEraseLine(x1, y1, x2, y2) {
                var size = parseInt(document.getElementById('erase-brush-size').value, 10) || 40;

                if (erase.ctx) {
                    erase.ctx.strokeStyle = 'rgba(255, 0, 0, 0.55)';
                    erase.ctx.lineCap = 'round';
                    erase.ctx.lineJoin = 'round';
                    erase.ctx.lineWidth = size;
                    erase.ctx.beginPath();
                    erase.ctx.moveTo(x1, y1);
                    erase.ctx.lineTo(x2, y2);
                    erase.ctx.stroke();
                }

                if (erase.maskCtx) {
                    erase.maskCtx.strokeStyle = '#ffffff';
                    erase.maskCtx.lineCap = 'round';
                    erase.maskCtx.lineJoin = 'round';
                    erase.maskCtx.lineWidth = size;
                    erase.maskCtx.beginPath();
                    erase.maskCtx.moveTo(x1, y1);
                    erase.maskCtx.lineTo(x2, y2);
                    erase.maskCtx.stroke();
                }
            }

            function renderEraseMask() {
                var width = (erase.canvas && erase.canvas.width) || 1;
                var height = (erase.canvas && erase.canvas.height) || 1;
                var outputCanvas = document.createElement('canvas');
                outputCanvas.width = width;
                outputCanvas.height = height;
                var octx = outputCanvas.getContext('2d');

                // Black background = keep area
                octx.fillStyle = '#000000';
                octx.fillRect(0, 0, width, height);

                // Draw white strokes from maskCanvas = erase area
                if (erase.maskCanvas) {
                    octx.drawImage(erase.maskCanvas, 0, 0);
                }

                return outputCanvas.toDataURL('image/png');
            }

            window.setupEraseEditor = function (imageSrc) {
                var img = new Image();
                img.onload = function () {
                    erase.image = img;
                    var maxDim = 1024;
                    var scale = 1;
                    if (img.naturalWidth > maxDim || img.naturalHeight > maxDim) {
                        scale = maxDim / Math.max(img.naturalWidth, img.naturalHeight);
                    }
                    erase.scale = scale;
                    var w = Math.max(1, Math.round(img.naturalWidth * scale));
                    var h = Math.max(1, Math.round(img.naturalHeight * scale));

                    erase.canvas.width = w;
                    erase.canvas.height = h;
                    erase.ctx = erase.canvas.getContext('2d');
                    erase.ctx.clearRect(0, 0, w, h);
                    erase.ctx.drawImage(img, 0, 0, w, h);

                    if (!erase.maskCanvas) {
                        erase.maskCanvas = document.createElement('canvas');
                    }
                    erase.maskCanvas.width = w;
                    erase.maskCanvas.height = h;
                    erase.maskCtx = erase.maskCanvas.getContext('2d');
                    erase.maskCtx.clearRect(0, 0, w, h);

                    erase.painted = false;
                    document.getElementById('erase-editor').style.display = 'block';
                    document.getElementById('erase-preview').classList.remove('has-image');
                    setupEraseCanvas();
                };
                img.src = imageSrc;
            };

            window.hasEraseMask = function () {
                return erase.painted;
            };

            window.renderEraseMask = renderEraseMask;
        })();
    </script>

<?php endif; ?>
