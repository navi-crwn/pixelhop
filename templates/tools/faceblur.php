<?php if (($phToolSection ?? 'card') === 'card'): ?>
                <!-- Face Blur (NEW) -->
                <div class="tool-card<?= empty($toolStatus['faceblur']) ? ' disabled' : '' ?>" <?= !empty($toolStatus['faceblur']) ? ($isLoggedIn ? 'onclick="openModal(\'faceblur\')"' : 'onclick="requireLogin(\'Face Blur\')"') : '' ?>>
                    <?php if (empty($toolStatus['faceblur'])): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && !empty($toolStatus['faceblur'])): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon" style="background: linear-gradient(135deg, #f59e0b, #ef4444);">
                        <i data-lucide="scan-face" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        Face Blur
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-amber-400 to-rose-500 text-white rounded-full ml-2">PRIVACY</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Blur or pixelate faces in photos instantly. Protect privacy in seconds.
                    </p>
                </div>

<?php else: ?>
    <!-- Face Blur Modal -->
    <div id="modal-faceblur" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('faceblur')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('faceblur')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="scan-face" class="w-6 h-6 inline-block mr-2 text-amber-400"></i>
                Face Blur
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-amber-400 to-rose-500 text-white rounded-full ml-2">PRIVACY</span>
            </h2>

            <form id="faceblur-form">
                <div class="drop-zone-mini" id="faceblur-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">Best for: group photos, portraits, public shots</p>
                    <input type="file" id="faceblur-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="faceblur-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="faceblur-filename"></p>
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Method</label>
                    <select class="form-select" id="faceblur-method">
                        <option value="blur">Blur (soft)</option>
                        <option value="pixelate">Pixelate (mosaic)</option>
                    </select>
                </div>

                <div class="form-group mt-4">
                    <label class="form-label">Strength: <span id="faceblur-strength-value">5</span></label>
                    <input type="range" class="form-range" id="faceblur-strength" min="1" max="10" value="5" oninput="document.getElementById('faceblur-strength-value').textContent = this.value">
                </div>

                <div class="processing" id="faceblur-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Detecting &amp; blurring faces...</span>
                </div>

                <div class="result-area" id="faceblur-result">
                    <div class="text-center mb-4">
                        <img src="" alt="Result" id="faceblur-result-image" class="preview-image" style="max-height: 250px; border-radius: 8px;">
                    </div>
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="faceblur-faces">-</div>
                            <div class="stat-label">Faces Blurred</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="faceblur-new-size">-</div>
                            <div class="stat-label">Result Size</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full mt-4" id="faceblur-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download Result
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="faceblur-submit">
                    <i data-lucide="scan-face" class="w-4 h-4"></i>
                    Blur Faces
                </button>
            </form>
        </div>
    </div>

    <script>
    (function () {
        if (window.__faceblurBound) return;
        window.__faceblurBound = true;

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

        function handleFaceblur(e) {
            e.preventDefault();

            if (typeof window.faceblurFile === 'undefined') {
                notify('Please select an image first', 'error');
                return;
            }

            var file = window.faceblurFile;
            var processing = document.getElementById('faceblur-processing');
            var result = document.getElementById('faceblur-result');
            var submit = document.getElementById('faceblur-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            var formData = new FormData();
            formData.append('image', file);
            formData.append('method', document.getElementById('faceblur-method').value);
            formData.append('strength', document.getElementById('faceblur-strength').value);
            formData.append('include_data', '1');

            fetch('/api/faceblur.php', {
                method: 'POST',
                body: formData
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) throw new Error(data.error);

                var facesEl = document.getElementById('faceblur-faces');
                var sizeEl = document.getElementById('faceblur-new-size');
                var img = document.getElementById('faceblur-result-image');

                if (facesEl) facesEl.textContent = data.faces;
                if (sizeEl) sizeEl.textContent = (typeof formatSize === 'function')
                    ? formatSize(data.new_size)
                    : (data.new_size + ' B');

                var resultUrl = data.data || data.view_url;
                if (resultUrl && img) {
                    img.src = resultUrl;
                }

                document.getElementById('faceblur-download').onclick = function () {
                    if (data.data) {
                        if (typeof downloadDataUrl === 'function') {
                            downloadDataUrl(data.data, data.filename);
                        } else {
                            var a = document.createElement('a');
                            a.href = data.data;
                            a.download = data.filename || 'faceblur.jpg';
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                        }
                    } else if (data.view_url) {
                        var a = document.createElement('a');
                        a.href = data.view_url;
                        a.download = data.filename || 'faceblur.jpg';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }
                };

                result.classList.add('show');
            })
            .catch(function (err) {
                notify('Error: ' + err.message, 'error');
            })
            .finally(function () {
                processing.classList.remove('show');
                submit.disabled = false;
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('faceblur-form');
            if (form) form.addEventListener('submit', handleFaceblur);

            var drop = document.getElementById('faceblur-drop');
            var input = document.getElementById('faceblur-file');

            if (drop && input) {
                drop.addEventListener('click', function () { input.click(); });

                drop.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    drop.classList.add('dragover');
                });

                drop.addEventListener('dragleave', function () {
                    drop.classList.remove('dragover');
                });

                drop.addEventListener('drop', function (e) {
                    e.preventDefault();
                    drop.classList.remove('dragover');
                    if (e.dataTransfer.files.length) {
                        setFaceblurFile(e.dataTransfer.files[0]);
                    }
                });

                input.addEventListener('change', function () {
                    if (input.files.length) setFaceblurFile(input.files[0]);
                });
            }
        });

        function setFaceblurFile(file) {
            if (!file.type || file.type.indexOf('image/') !== 0) {
                notify('Please select an image file', 'error');
                return;
            }

            window.faceblurFile = file;

            var preview = document.getElementById('faceblur-preview');
            var img = preview ? preview.querySelector('img') : null;
            var nameEl = document.getElementById('faceblur-filename');

            var reader = new FileReader();
            reader.onload = function (e) {
                if (img) {
                    img.src = e.target.result;
                    if (preview) preview.classList.add('has-image');
                }
                if (nameEl) {
                    nameEl.textContent = file.name + ' (' + file.size + ' bytes)';
                }
            };
            reader.readAsDataURL(file);

            var result = document.getElementById('faceblur-result');
            if (result) result.classList.remove('show');
        }
    })();
    </script>

<?php endif; ?>
