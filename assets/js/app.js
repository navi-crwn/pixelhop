/**
 * PixelHop - Main Application JavaScript
 * iOS 26 Liquid Glass Image Hosting Platform
 * Phase 3: Batch Upload & Enhanced UX
 */

// Upload queue management
const uploadQueue = {
    files: [],
    current: 0,
    results: [],
    isUploading: false
};

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Lucide Icons
    lucide.createIcons();
    
    // Initialize all components
    initThemeToggle();
    initAnimatedBackground();
    initMobileMenu();
    initUploadZone();
    initBentoCards();
    initHeaderScroll();
    initParallaxEffects();
    initKeyboardShortcuts();
    initLightbox();
    initClipboardPaste();
    initCustomModal();
    initGlassDropdown();
});

/**
 * Custom Modal System - Replaces browser alerts
 */
function initCustomModal() {
    // Create modal container if not exists
    if (!document.getElementById('custom-modal')) {
        const modalHTML = `
            <div id="custom-modal" class="custom-modal-overlay">
                <div class="custom-modal-container">
                    <div class="custom-modal-icon" id="modal-icon"></div>
                    <h3 class="custom-modal-title" id="modal-title"></h3>
                    <p class="custom-modal-message" id="modal-message"></p>
                    <div class="custom-modal-actions" id="modal-actions"></div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Close on overlay click
        document.getElementById('custom-modal').addEventListener('click', (e) => {
            if (e.target.id === 'custom-modal') {
                closeCustomModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeCustomModal();
            }
        });
    }
}

function showCustomModal(options = {}) {
    const modal = document.getElementById('custom-modal');
    const iconEl = document.getElementById('modal-icon');
    const titleEl = document.getElementById('modal-title');
    const messageEl = document.getElementById('modal-message');
    const actionsEl = document.getElementById('modal-actions');
    
    const {
        type = 'info',
        title = 'Notice',
        message = '',
        confirmText = 'OK',
        cancelText = null,
        onConfirm = null,
        onCancel = null
    } = options;
    
    // Set icon based on type
    const icons = {
        info: '<i data-lucide="info" class="w-8 h-8 text-neon-cyan"></i>',
        success: '<i data-lucide="check-circle" class="w-8 h-8 text-green-400"></i>',
        warning: '<i data-lucide="alert-triangle" class="w-8 h-8 text-yellow-400"></i>',
        error: '<i data-lucide="x-circle" class="w-8 h-8 text-red-400"></i>',
        coming: '<i data-lucide="clock" class="w-8 h-8 text-neon-purple"></i>'
    };
    
    iconEl.innerHTML = icons[type] || icons.info;
    titleEl.textContent = title;
    messageEl.textContent = message;
    
    // Build actions
    let actionsHTML = '';
    if (cancelText) {
        actionsHTML += `<button class="modal-btn modal-btn-secondary" onclick="closeCustomModal(${onCancel ? 'true' : 'false'})">${cancelText}</button>`;
    }
    actionsHTML += `<button class="modal-btn modal-btn-primary" onclick="closeCustomModal(false, true)">${confirmText}</button>`;
    actionsEl.innerHTML = actionsHTML;
    
    // Store callbacks
    modal._onConfirm = onConfirm;
    modal._onCancel = onCancel;
    
    // Show modal
    modal.classList.add('active');
    lucide.createIcons();
    
    // Animate in
    gsap.fromTo(modal.querySelector('.custom-modal-container'),
        { scale: 0.9, opacity: 0 },
        { scale: 1, opacity: 1, duration: 0.3, ease: 'back.out(1.7)' }
    );
}

function closeCustomModal(cancelled = false, confirmed = false) {
    const modal = document.getElementById('custom-modal');
    if (!modal) return;
    
    gsap.to(modal.querySelector('.custom-modal-container'), {
        scale: 0.9,
        opacity: 0,
        duration: 0.2,
        onComplete: () => {
            modal.classList.remove('active');
            
            if (confirmed && modal._onConfirm) {
                modal._onConfirm();
            } else if (cancelled && modal._onCancel) {
                modal._onCancel();
            }
        }
    });
}

// Helper function for "Coming Soon" modal
function showComingSoon(feature = 'This feature') {
    showCustomModal({
        type: 'coming',
        title: 'Coming Soon!',
        message: `${feature} is currently under development. Stay tuned for updates!`,
        confirmText: 'Got it'
    });
}

/**
 * Glass Dropdown Component
 */
function initGlassDropdown() {
    const dropdowns = document.querySelectorAll('.glass-dropdown');
    
    dropdowns.forEach(dropdown => {
        const trigger = dropdown.querySelector('.glass-dropdown-trigger');
        const menu = dropdown.querySelector('.glass-dropdown-menu');
        const options = dropdown.querySelectorAll('.glass-dropdown-option');
        const textEl = dropdown.querySelector('[id$="-dropdown-text"]');
        const hiddenInput = dropdown.querySelector('input[type="hidden"]');
        
        if (!trigger || !menu) return;
        
        // Prevent menu clicks from propagating
        menu.addEventListener('click', (e) => {
            e.stopPropagation();
        });
        
        // Toggle dropdown
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            // Close other dropdowns
            document.querySelectorAll('.glass-dropdown.open').forEach(d => {
                if (d !== dropdown) d.classList.remove('open');
            });
            
            dropdown.classList.toggle('open');
        });
        
        // Handle option selection
        options.forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const value = option.dataset.value;
                const text = option.querySelector('span')?.textContent || value;
                
                // Update selected state
                options.forEach(o => o.classList.remove('selected'));
                option.classList.add('selected');
                
                // Update trigger text
                if (textEl) textEl.textContent = text;
                
                // Update hidden input
                if (hiddenInput) hiddenInput.value = value;
                
                // Close dropdown with animation
                dropdown.classList.remove('open');
                
                // Re-initialize icons
                lucide.createIcons();
            });
        });
    });
    
    // Close on outside click
    document.addEventListener('click', (e) => {
        // Don't close if clicking inside a dropdown
        if (e.target.closest('.glass-dropdown')) return;
        
        document.querySelectorAll('.glass-dropdown.open').forEach(d => {
            d.classList.remove('open');
        });
    });
    
    // Close on escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.glass-dropdown.open').forEach(d => {
                d.classList.remove('open');
            });
        }
    });
}

/**
 * Theme Toggle (Dark/Light Mode)
 */
function initThemeToggle() {
    const toggleBtn = document.getElementById('theme-toggle');
    if (!toggleBtn) return;
    
    toggleBtn.addEventListener('click', () => {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        // Update theme
        html.setAttribute('data-theme', newTheme);
        
        // Save preference
        localStorage.setItem('pixelhop-theme', newTheme);
        
        // Update theme-color meta tag
        const themeColorMeta = document.getElementById('theme-color-meta');
        if (themeColorMeta) {
            themeColorMeta.setAttribute('content', newTheme === 'dark' ? '#0a0f1c' : '#f0f4f8');
        }
        
        // Animate the toggle
        gsap.fromTo(toggleBtn, 
            { scale: 0.8, rotation: -180 },
            { scale: 1, rotation: 0, duration: 0.4, ease: 'back.out(1.7)' }
        );
        
        // Re-init icons
        lucide.createIcons();
    });
}

/**
 * Animated Background Blobs
 * Uses GSAP for smooth, organic blob movement
 */
function initAnimatedBackground() {
    const blobs = document.querySelectorAll('.blob');
    
    blobs.forEach((blob, index) => {
        // Random starting position offset
        const startX = Math.random() * 100 - 50;
        const startY = Math.random() * 100 - 50;
        
        // Create infinite floating animation
        gsap.to(blob, {
            x: `+=${startX}`,
            y: `+=${startY}`,
            duration: 0,
        });
        
        // Continuous floating movement
        createBlobAnimation(blob, index);
    });
}

function createBlobAnimation(blob, index) {
    const duration = 15 + (index * 3);
    const xRange = 150 + (index * 30);
    const yRange = 100 + (index * 20);
    
    // Create a timeline for organic movement
    const tl = gsap.timeline({ repeat: -1, yoyo: true });
    
    tl.to(blob, {
        x: `+=${xRange}`,
        y: `+=${yRange}`,
        rotation: 10,
        scale: 1.1,
        duration: duration,
        ease: 'sine.inOut',
    })
    .to(blob, {
        x: `-=${xRange * 0.5}`,
        y: `-=${yRange * 1.5}`,
        rotation: -5,
        scale: 0.95,
        duration: duration * 0.8,
        ease: 'sine.inOut',
    })
    .to(blob, {
        x: `-=${xRange * 0.3}`,
        y: `+=${yRange * 0.8}`,
        rotation: 8,
        scale: 1.05,
        duration: duration * 0.6,
        ease: 'sine.inOut',
    });
    
    // Add subtle pulsing opacity
    gsap.to(blob, {
        opacity: blob.classList.contains('blob-cyan-sm') || blob.classList.contains('blob-purple-sm') ? 0.4 : 0.6,
        duration: 4 + index,
        repeat: -1,
        yoyo: true,
        ease: 'sine.inOut',
    });
}

/**
 * Mobile Menu Toggle
 */
function initMobileMenu() {
    const menuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    
    if (!menuBtn || !mobileMenu) return;
    
    menuBtn.addEventListener('click', () => {
        const isOpen = !mobileMenu.classList.contains('hidden');
        
        if (isOpen) {
            // Close menu
            gsap.to(mobileMenu, {
                opacity: 0,
                y: -10,
                duration: 0.2,
                onComplete: () => {
                    mobileMenu.classList.add('hidden');
                }
            });
        } else {
            // Open menu
            mobileMenu.classList.remove('hidden');
            gsap.fromTo(mobileMenu, 
                { opacity: 0, y: -10 },
                { opacity: 1, y: 0, duration: 0.3, ease: 'back.out(1.7)' }
            );
        }
        
        // Toggle icon
        const icon = menuBtn.querySelector('i');
        icon.setAttribute('data-lucide', isOpen ? 'menu' : 'x');
        lucide.createIcons();
    });
    
    // Close menu on link click
    mobileMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            gsap.to(mobileMenu, {
                opacity: 0,
                y: -10,
                duration: 0.2,
                onComplete: () => {
                    mobileMenu.classList.add('hidden');
                    const icon = menuBtn.querySelector('i');
                    icon.setAttribute('data-lucide', 'menu');
                    lucide.createIcons();
                }
            });
        });
    });
}

/**
 * Upload Zone - Drag & Drop + Click
 */
function initUploadZone() {
    const uploadZone = document.getElementById('upload-zone');
    const fileInput = document.getElementById('file-input');
    const dragOverlay = document.getElementById('drag-overlay');
    
    if (!uploadZone || !fileInput) return;
    
    // Simple click to upload - dropdown is now OUTSIDE upload zone
    uploadZone.addEventListener('click', (e) => {
        // Ignore clicks on file input itself
        if (e.target === fileInput) return;
        fileInput.click();
    });
    
    // File input change
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            handleFiles(e.target.files);
        }
    });
    
    // Drag & Drop events
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    // Drag enter/over - show overlay
    ['dragenter', 'dragover'].forEach(eventName => {
        uploadZone.addEventListener(eventName, () => {
            dragOverlay.classList.add('active');
            uploadZone.classList.add('drag-active');
        });
    });
    
    // Drag leave - hide overlay
    ['dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, () => {
            dragOverlay.classList.remove('active');
            uploadZone.classList.remove('drag-active');
        });
    });
    
    // Drop - handle files
    uploadZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFiles(files);
        }
    });
}

/**
 * Handle file uploads - BATCH UPLOAD SUPPORT
 */
function handleFiles(files) {
    const uploadZone = document.getElementById('upload-zone');
    const progressSection = document.getElementById('upload-progress');
    const resultSection = document.getElementById('upload-result');
    
    // Filter for valid image files
    const validFiles = Array.from(files).filter(file => {
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return validTypes.includes(file.type) && file.size <= 15 * 1024 * 1024;
    });
    
    if (validFiles.length === 0) {
        showNotification('Please select valid image files (JPG, PNG, GIF, WebP, max 15MB)', 'error');
        return;
    }
    
    // Initialize upload queue
    uploadQueue.files = validFiles;
    uploadQueue.current = 0;
    uploadQueue.results = [];
    uploadQueue.isUploading = true;
    
    // Show progress section with queue info
    progressSection.classList.remove('hidden');
    resultSection.classList.add('hidden');
    
    // Build queue UI
    progressSection.innerHTML = buildQueueUI(validFiles);
    
    // Animate appearance
    gsap.fromTo(progressSection, 
        { opacity: 0, y: 10 },
        { opacity: 1, y: 0, duration: 0.3 }
    );
    
    lucide.createIcons();
    
    // Start uploading
    uploadNextFile();
}

/**
 * Build upload queue UI
 */
function buildQueueUI(files) {
    const isSingle = files.length === 1;
    
    let html = `
        <div class="upload-queue">
            <div class="upload-queue-header">
                <div class="flex items-center gap-3">
                    <div class="upload-queue-icon">
                        <i data-lucide="layers" class="w-5 h-5 text-neon-cyan"></i>
                    </div>
                    <div>
                        <h4 class="text-white font-medium">Uploading ${files.length} ${isSingle ? 'file' : 'files'}</h4>
                        <p class="text-white/50 text-sm" id="queue-status">Starting...</p>
                    </div>
                </div>
                <button onclick="cancelAllUploads()" class="text-white/40 hover:text-red-400 transition-colors" title="Cancel all">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            
            <div class="upload-queue-list" id="queue-list">
    `;
    
    files.forEach((file, index) => {
        html += `
            <div class="upload-queue-item" id="queue-item-${index}">
                <div class="upload-queue-item-info">
                    <div class="upload-queue-thumb" id="queue-thumb-${index}">
                        <i data-lucide="image" class="w-4 h-4 text-white/40"></i>
                    </div>
                    <div class="upload-queue-details">
                        <span class="upload-queue-name">${truncateFilename(file.name, 25)}</span>
                        <span class="upload-queue-size">${formatFileSize(file.size)}</span>
                    </div>
                </div>
                <div class="upload-queue-item-status" id="queue-status-${index}">
                    <div class="upload-queue-waiting">
                        <i data-lucide="clock" class="w-4 h-4 text-white/30"></i>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
            </div>
            
            <div class="upload-queue-progress">
                <div class="upload-progress-bar">
                    <div id="total-progress-fill" class="upload-progress-fill" style="width: 0%"></div>
                </div>
                <div class="flex items-center justify-between mt-2">
                    <span id="total-progress-text" class="text-xs text-white/50">0 of ${files.length} complete</span>
                    <span id="total-progress-percent" class="text-xs font-medium text-neon-cyan">0%</span>
                </div>
            </div>
        </div>
    `;
    
    return html;
}

/**
 * Upload next file in queue
 */
function uploadNextFile() {
    if (uploadQueue.current >= uploadQueue.files.length) {
        // All done
        uploadQueue.isUploading = false;
        showBatchResults();
        return;
    }
    
    const file = uploadQueue.files[uploadQueue.current];
    const index = uploadQueue.current;
    
    // Update queue status
    document.getElementById('queue-status').textContent = `Uploading ${index + 1} of ${uploadQueue.files.length}...`;
    
    // Update item status to uploading
    const statusEl = document.getElementById(`queue-status-${index}`);
    statusEl.innerHTML = `
        <div class="upload-queue-uploading">
            <div class="mini-spinner"></div>
            <span id="item-progress-${index}" class="text-xs text-neon-cyan">0%</span>
        </div>
    `;
    
    // Load thumbnail preview and store data URL
    loadThumbnailPreview(file, index);
    
    // Create FormData
    const formData = new FormData();
    formData.append('image', file);
    
    // Add delete schedule if selected
    const deleteSchedule = document.getElementById('delete-schedule')?.value || 'never';
    formData.append('delete_after', deleteSchedule);
    
    // Upload with progress
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const percent = Math.round((e.loaded / e.total) * 100);
            const itemProgress = document.getElementById(`item-progress-${index}`);
            if (itemProgress) {
                itemProgress.textContent = percent === 100 ? 'Processing...' : `${percent}%`;
            }
            
            // Update total progress
            updateTotalProgress(index, percent);
        }
    });
    
    xhr.addEventListener('load', () => {
        // Get thumbnail data URL from preview
        const thumbEl = document.getElementById(`queue-thumb-${index}`);
        const thumbImg = thumbEl?.querySelector('img');
        const thumbDataUrl = thumbImg?.src || '';
        
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    markItemSuccess(index, response.data);
                    uploadQueue.results.push({ success: true, data: response.data, file: file.name, thumb: thumbDataUrl });
                } else {
                    markItemError(index, response.error || 'Upload failed');
                    uploadQueue.results.push({ success: false, error: response.error || 'Upload failed', file: file.name, thumb: thumbDataUrl });
                }
            } catch (e) {
                markItemError(index, 'Invalid response');
                uploadQueue.results.push({ success: false, error: 'Invalid response', file: file.name, thumb: thumbDataUrl });
            }
        } else {
            markItemError(index, `Server error (HTTP ${xhr.status})`);
            uploadQueue.results.push({ success: false, error: `Server error (HTTP ${xhr.status})`, file: file.name, thumb: thumbDataUrl });
        }
        
        // Continue to next
        uploadQueue.current++;
        uploadNextFile();
    });
    
    xhr.addEventListener('error', () => {
        markItemError(index, 'Network error - please try again');
        uploadQueue.results.push({ success: false, error: 'Network error', file: file.name });
        uploadQueue.current++;
        uploadNextFile();
    });
    
    xhr.addEventListener('timeout', () => {
        markItemError(index, 'Upload timed out - please try again');
        uploadQueue.results.push({ success: false, error: 'Timeout', file: file.name });
        uploadQueue.current++;
        uploadNextFile();
    });
    
    xhr.open('POST', '/api/upload.php');
    xhr.timeout = 120000; // 2 minute timeout
    xhr.send(formData);
}

/**
 * Load thumbnail preview
 */
function loadThumbnailPreview(file, index) {
    const reader = new FileReader();
    reader.onload = (e) => {
        const thumbEl = document.getElementById(`queue-thumb-${index}`);
        if (thumbEl) {
            thumbEl.innerHTML = `<img src="${e.target.result}" alt="" class="w-full h-full object-cover rounded">`;
        }
    };
    reader.readAsDataURL(file);
}

/**
 * Update total progress bar
 */
function updateTotalProgress(currentIndex, itemPercent) {
    const totalFiles = uploadQueue.files.length;
    const completedFiles = currentIndex;
    const totalPercent = Math.round(((completedFiles * 100) + itemPercent) / totalFiles);
    
    const fill = document.getElementById('total-progress-fill');
    const text = document.getElementById('total-progress-text');
    const percent = document.getElementById('total-progress-percent');
    
    if (fill) fill.style.width = `${totalPercent}%`;
    if (text) text.textContent = `${completedFiles} of ${totalFiles} complete`;
    if (percent) percent.textContent = `${totalPercent}%`;
}

/**
 * Mark item as success
 */
function markItemSuccess(index, data) {
    const statusEl = document.getElementById(`queue-status-${index}`);
    const itemEl = document.getElementById(`queue-item-${index}`);
    
    if (statusEl) {
        statusEl.innerHTML = `
            <a href="${data.view_url}" target="_blank" class="upload-queue-success" title="View image">
                <i data-lucide="check-circle" class="w-4 h-4 text-green-400"></i>
            </a>
        `;
        lucide.createIcons();
    }
    
    if (itemEl) {
        itemEl.classList.add('upload-complete');
    }
}

/**
 * Mark item as error
 */
function markItemError(index, error) {
    const statusEl = document.getElementById(`queue-status-${index}`);
    const itemEl = document.getElementById(`queue-item-${index}`);
    
    if (statusEl) {
        statusEl.innerHTML = `
            <div class="upload-queue-error" title="${error}">
                <i data-lucide="alert-circle" class="w-4 h-4 text-red-400"></i>
            </div>
        `;
        lucide.createIcons();
    }
    
    if (itemEl) {
        itemEl.classList.add('upload-error');
    }
}

/**
 * Cancel all uploads
 */
function cancelAllUploads() {
    uploadQueue.isUploading = false;
    uploadQueue.files = [];
    uploadQueue.current = 0;
    uploadQueue.results = [];
    
    const progressSection = document.getElementById('upload-progress');
    gsap.to(progressSection, {
        opacity: 0,
        y: -10,
        duration: 0.2,
        onComplete: () => {
            progressSection.classList.add('hidden');
            progressSection.innerHTML = '';
        }
    });
    
    showNotification('Uploads cancelled', 'info');
}

/**
 * Show batch upload results
 */
function showBatchResults() {
    const progressSection = document.getElementById('upload-progress');
    const resultSection = document.getElementById('upload-result');
    
    const successCount = uploadQueue.results.filter(r => r.success).length;
    const errorCount = uploadQueue.results.filter(r => !r.success).length;
    
    // Update final queue status
    document.getElementById('queue-status').textContent = 
        `Complete! ${successCount} uploaded${errorCount > 0 ? `, ${errorCount} failed` : ''}`;
    document.getElementById('total-progress-text').textContent = 
        `${successCount} of ${uploadQueue.results.length} complete`;
    document.getElementById('total-progress-percent').textContent = '100%';
    document.getElementById('total-progress-fill').style.width = '100%';
    
    // If single file success, show detailed result
    if (uploadQueue.results.length === 1 && successCount === 1) {
        setTimeout(() => {
            progressSection.classList.add('hidden');
            showUploadResult(uploadQueue.results[0].data);
        }, 500);
        return;
    }
    
    // For batch uploads (2+ images), redirect to result page
    if (uploadQueue.results.length > 1) {
        const successResults = uploadQueue.results.filter(r => r.success);
        const errorResults = uploadQueue.results.filter(r => !r.success);
        
        // Build query params
        const imageIds = successResults.map(r => r.data.id).join(',');
        
        // Store error data in sessionStorage for result page
        if (errorResults.length > 0) {
            sessionStorage.setItem('uploadErrors', JSON.stringify(errorResults.map(r => ({
                file: r.file,
                error: r.error,
                thumb: r.thumb || ''
            }))));
        }
        
        console.log('Redirecting to result page. Success:', successCount, 'Errors:', errorCount);
        
        setTimeout(() => {
            const url = successCount > 0 
                ? '/result?id=' + imageIds + (errorCount > 0 ? '&errors=' + errorCount : '')
                : '/result?errors=' + errorCount;
            window.location.href = url;
        }, 800);
        return;
    }
    
    // Single file failed - show error inline
    if (uploadQueue.results.length === 1 && errorCount === 1) {
        setTimeout(() => {
            showBatchSummary(successCount, errorCount);
        }, 500);
    }
}

/**
 * Show batch summary (only for error cases)
 */
function showBatchSummary(successCount, errorCount) {
    const resultSection = document.getElementById('upload-result');
    const progressSection = document.getElementById('upload-progress');
    
    progressSection.classList.add('hidden');
    
    const errorResults = uploadQueue.results.filter(r => !r.success);
    
    let html = `
        <div class="upload-result-card batch-result">
            <div class="upload-result-header">
                <div class="upload-result-icon">
                    <i data-lucide="alert-circle" class="w-6 h-6 text-red-400"></i>
                </div>
                <div class="upload-result-title">
                    <h4 class="text-white font-semibold">Upload Failed</h4>
                    <p class="text-white/50 text-sm">${errorCount} file${errorCount > 1 ? 's' : ''} failed to upload</p>
                </div>
            </div>
            
            <div class="error-list" style="margin: 1rem 0; max-height: 200px; overflow-y: auto;">
    `;
    
    errorResults.forEach(result => {
        html += `
            <div class="error-item" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: rgba(239, 68, 68, 0.1); border-radius: 8px; margin-bottom: 0.5rem;">
                <i data-lucide="x-circle" class="w-4 h-4 text-red-400 flex-shrink-0"></i>
                <div style="flex: 1; min-width: 0;">
                    <div style="color: var(--color-text-primary); font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${result.file}</div>
                    <div style="color: var(--color-text-tertiary); font-size: 0.75rem;">${result.error}</div>
                </div>
            </div>
        `;
    });
    
    html += `
            </div>
            
            <div class="upload-result-actions">
                <button onclick="resetUpload()" class="btn-primary">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                    Try Again
                </button>
            </div>
        </div>
    `;
    
    resultSection.innerHTML = html;
    resultSection.classList.remove('hidden');
    
    gsap.fromTo(resultSection, 
        { opacity: 0, y: 20 },
        { opacity: 1, y: 0, duration: 0.4, ease: 'back.out(1.7)' }
    );
    
    lucide.createIcons();
}

/**
 * Utility: Truncate filename
 */
function truncateFilename(name, maxLength) {
    if (name.length <= maxLength) return name;
    const ext = name.split('.').pop();
    const base = name.substring(0, name.length - ext.length - 1);
    const truncatedBase = base.substring(0, maxLength - ext.length - 4) + '...';
    return truncatedBase + '.' + ext;
}

/**
 * Utility: Format file size
 */
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

/**
 * Show upload result
 */
function showUploadResult(data) {
    const progressSection = document.getElementById('upload-progress');
    const resultSection = document.getElementById('upload-result');
    
    // Hide progress
    progressSection.classList.add('hidden');
    
    // Build result HTML
    resultSection.innerHTML = `
        <div class="upload-result-card">
            <div class="upload-result-header">
                <div class="upload-result-icon">
                    <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i>
                </div>
                <div class="upload-result-title">
                    <h4 class="text-white font-semibold">Upload Successful!</h4>
                    <p class="text-white/50 text-sm">Your image is ready to share</p>
                </div>
            </div>
            
            <div class="upload-result-preview">
                <img src="${data.urls.thumb}" alt="Uploaded image" class="upload-result-thumb">
            </div>
            
            <div class="upload-result-links">
                <div class="upload-link-group">
                    <label class="upload-link-label">Direct Link</label>
                    <div class="upload-link-input">
                        <input type="text" value="${data.urls.original}" readonly id="link-direct">
                        <button onclick="copyToClipboard('link-direct')" class="upload-link-copy" title="Copy">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                
                <div class="upload-link-group">
                    <label class="upload-link-label">Share Link</label>
                    <div class="upload-link-input">
                        <input type="text" value="${data.view_url}" readonly id="link-share">
                        <button onclick="copyToClipboard('link-share')" class="upload-link-copy" title="Copy">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                
                <div class="upload-link-group">
                    <label class="upload-link-label">HTML Embed</label>
                    <div class="upload-link-input">
                        <input type="text" value='<img src="${data.urls.original}" alt="Image">' readonly id="link-html">
                        <button onclick="copyToClipboard('link-html')" class="upload-link-copy" title="Copy">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                
                <div class="upload-link-group">
                    <label class="upload-link-label">Markdown</label>
                    <div class="upload-link-input">
                        <input type="text" value="![Image](${data.urls.original})" readonly id="link-markdown">
                        <button onclick="copyToClipboard('link-markdown')" class="upload-link-copy" title="Copy">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="upload-result-actions">
                <a href="${data.view_url}" target="_blank" class="btn-primary">
                    <i data-lucide="external-link" class="w-4 h-4"></i>
                    View Image
                </a>
                <button onclick="resetUpload()" class="btn-secondary">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Upload Another
                </button>
            </div>
        </div>
    `;
    
    // Show result with animation
    resultSection.classList.remove('hidden');
    gsap.fromTo(resultSection, 
        { opacity: 0, y: 20 },
        { opacity: 1, y: 0, duration: 0.4, ease: 'back.out(1.7)' }
    );
    
    // Re-initialize Lucide icons
    lucide.createIcons();
}

/**
 * Copy to clipboard
 */
function copyToClipboard(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    
    input.select();
    input.setSelectionRange(0, 99999);
    
    navigator.clipboard.writeText(input.value).then(() => {
        showNotification('Copied to clipboard!', 'success');
    }).catch(() => {
        // Fallback
        document.execCommand('copy');
        showNotification('Copied to clipboard!', 'success');
    });
}

/**
 * Reset upload zone
 */
function resetUpload() {
    const resultSection = document.getElementById('upload-result');
    const fileInput = document.getElementById('file-input');
    
    gsap.to(resultSection, {
        opacity: 0,
        y: -10,
        duration: 0.2,
        onComplete: () => {
            resultSection.classList.add('hidden');
            resultSection.innerHTML = '';
            fileInput.value = '';
        }
    });
}

/**
 * Show notification toast
 */
function showNotification(message, type = 'info') {
    // Remove existing notification
    const existing = document.querySelector('.notification-toast');
    if (existing) existing.remove();
    
    // Create notification
    const notification = document.createElement('div');
    notification.className = `notification-toast notification-${type}`;
    
    const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'alert-circle' : 'info';
    
    notification.innerHTML = `
        <i data-lucide="${icon}" class="w-5 h-5"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    lucide.createIcons();
    
    // Animate in
    gsap.fromTo(notification,
        { opacity: 0, y: 50, x: '-50%' },
        { opacity: 1, y: 0, duration: 0.4, ease: 'back.out(1.7)' }
    );
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        gsap.to(notification, {
            opacity: 0,
            y: 50,
            duration: 0.3,
            onComplete: () => notification.remove()
        });
    }, 3000);
}

/**
 * Bento Cards Interactivity
 */
function initBentoCards() {
    const cards = document.querySelectorAll('.bento-card');
    
    cards.forEach(card => {
        // Mouse move parallax effect
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            
            const rotateX = (y - centerY) / 20;
            const rotateY = (centerX - x) / 20;
            
            gsap.to(card, {
                rotateX: rotateX,
                rotateY: rotateY,
                transformPerspective: 1000,
                duration: 0.3,
                ease: 'power2.out'
            });
            
            // Move glow to mouse position
            const glow = card.querySelector('.bento-card-glow');
            if (glow) {
                gsap.to(glow, {
                    x: x - 75,
                    y: y - 75,
                    duration: 0.3
                });
            }
        });
        
        // Reset on mouse leave
        card.addEventListener('mouseleave', () => {
            gsap.to(card, {
                rotateX: 0,
                rotateY: 0,
                duration: 0.5,
                ease: 'elastic.out(1, 0.5)'
            });
        });
        
        // Click handler for tools
        card.addEventListener('click', () => {
            const tool = card.dataset.tool;
            if (tool) {
                openToolModal(tool);
            }
        });
    });
}

/**
 * Open tool modal - redirects to tools page with modal open
 */
function openToolModal(tool) {
    // Redirect to tools page with the specific tool modal open
    window.location.href = '/tools?open=' + tool;
}

// Make openToolModal globally available
window.openToolModal = openToolModal;

/**
 * Header scroll effect
 */
function initHeaderScroll() {
    const header = document.getElementById('header');
    if (!header) return;
    
    let lastScroll = 0;
    
    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('header-scrolled');
        } else {
            header.classList.remove('header-scrolled');
        }
        
        // Hide/show on scroll direction
        if (currentScroll > lastScroll && currentScroll > 200) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        
        lastScroll = currentScroll;
    });
}

/**
 * Parallax effects for background
 */
function initParallaxEffects() {
    const blobs = document.querySelectorAll('.blob');
    
    window.addEventListener('mousemove', (e) => {
        const mouseX = e.clientX / window.innerWidth - 0.5;
        const mouseY = e.clientY / window.innerHeight - 0.5;
        
        blobs.forEach((blob, index) => {
            const speed = (index + 1) * 15;
            gsap.to(blob, {
                x: `+=${mouseX * speed}`,
                y: `+=${mouseY * speed}`,
                duration: 1,
                ease: 'power2.out',
                overwrite: 'auto'
            });
        });
    });
}

// Make functions globally available
window.copyToClipboard = copyToClipboard;
window.resetUpload = resetUpload;

/**
 * Keyboard Shortcuts
 * Ctrl+V: Paste image from clipboard
 * Esc: Close modals
 * Arrow keys: Navigate gallery
 */
function initKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Escape - close modals and lightbox
        if (e.key === 'Escape') {
            // Close any open modal
            const modals = document.querySelectorAll('.glass-modal[style*="flex"], .lightbox-overlay');
            modals.forEach(modal => {
                if (modal.classList.contains('lightbox-overlay')) {
                    closeLightbox();
                } else {
                    modal.style.display = 'none';
                }
            });
            
            // Close upload queue if open
            const queue = document.querySelector('.upload-queue');
            if (queue) {
                queue.remove();
            }
        }
        
        // Arrow keys for lightbox navigation
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            const lightbox = document.querySelector('.lightbox-overlay');
            if (lightbox) {
                e.preventDefault();
                navigateLightbox(e.key === 'ArrowLeft' ? -1 : 1);
            }
        }
        
        // Ctrl/Cmd + V for paste (handled separately)
    });
}

/**
 * Clipboard Paste Upload
 * Allows pasting images directly from clipboard
 */
function initClipboardPaste() {
    document.addEventListener('paste', async (e) => {
        // Don't intercept if user is typing in an input
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            return;
        }
        
        const items = e.clipboardData?.items;
        if (!items) return;
        
        const imageFiles = [];
        
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) {
                    imageFiles.push(file);
                }
            }
        }
        
        if (imageFiles.length > 0) {
            e.preventDefault();
            
            // Show paste notification
            showPasteNotification(imageFiles.length);
            
            // Trigger upload
            if (typeof handleFiles === 'function') {
                handleFiles(imageFiles);
            }
        }
    });
}

function showPasteNotification(count) {
    const notification = document.createElement('div');
    notification.className = 'paste-notification';
    notification.innerHTML = `
        <i data-lucide="clipboard-check" class="w-5 h-5"></i>
        <span>Pasted ${count} image${count > 1 ? 's' : ''} from clipboard</span>
    `;
    notification.style.cssText = `
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        padding: 12px 20px;
        border-radius: 9999px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--color-text-primary);
        font-size: 14px;
        z-index: 9999;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    `;
    
    document.body.appendChild(notification);
    lucide.createIcons({ icons: { 'clipboard-check': notification.querySelector('[data-lucide="clipboard-check"]') } });
    
    // Animate in
    gsap.fromTo(notification, 
        { opacity: 0, y: 20 },
        { opacity: 1, y: 0, duration: 0.3, ease: 'back.out(1.7)' }
    );
    
    // Remove after 3 seconds
    setTimeout(() => {
        gsap.to(notification, {
            opacity: 0,
            y: 20,
            duration: 0.3,
            onComplete: () => notification.remove()
        });
    }, 3000);
}

/**
 * Image Lightbox
 * Full-screen image viewer with zoom and navigation
 */
let lightboxImages = [];
let currentLightboxIndex = 0;

function initLightbox() {
    // Find all gallery images and make them clickable
    document.addEventListener('click', (e) => {
        const galleryImage = e.target.closest('.gallery-image, [data-lightbox]');
        if (galleryImage) {
            e.preventDefault();
            
            const src = galleryImage.dataset.fullSrc || galleryImage.src || galleryImage.querySelector('img')?.src;
            if (!src) return;
            
            // Collect all gallery images
            const allImages = document.querySelectorAll('.gallery-image, [data-lightbox]');
            lightboxImages = Array.from(allImages).map(img => ({
                src: img.dataset.fullSrc || img.src || img.querySelector('img')?.src,
                alt: img.alt || img.querySelector('img')?.alt || ''
            })).filter(img => img.src);
            
            // Find current index
            currentLightboxIndex = lightboxImages.findIndex(img => img.src === src) || 0;
            
            openLightbox(lightboxImages[currentLightboxIndex]);
        }
    });
}

function openLightbox(image) {
    // Create lightbox overlay
    const overlay = document.createElement('div');
    overlay.className = 'lightbox-overlay';
    overlay.innerHTML = `
        <div class="lightbox-backdrop"></div>
        <div class="lightbox-content">
            <button class="lightbox-close" onclick="closeLightbox()">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
            <button class="lightbox-nav lightbox-prev" onclick="navigateLightbox(-1)">
                <i data-lucide="chevron-left" class="w-8 h-8"></i>
            </button>
            <div class="lightbox-image-container">
                <img src="${image.src}" alt="${image.alt}" class="lightbox-image">
            </div>
            <button class="lightbox-nav lightbox-next" onclick="navigateLightbox(1)">
                <i data-lucide="chevron-right" class="w-8 h-8"></i>
            </button>
            <div class="lightbox-counter">${currentLightboxIndex + 1} / ${lightboxImages.length}</div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    lucide.createIcons();
    
    // Animate in
    gsap.fromTo(overlay.querySelector('.lightbox-backdrop'),
        { opacity: 0 },
        { opacity: 1, duration: 0.3 }
    );
    gsap.fromTo(overlay.querySelector('.lightbox-image'),
        { opacity: 0, scale: 0.9 },
        { opacity: 1, scale: 1, duration: 0.3, ease: 'back.out(1.4)' }
    );
    
    // Close on backdrop click
    overlay.querySelector('.lightbox-backdrop').addEventListener('click', closeLightbox);
    
    // Zoom on double click
    overlay.querySelector('.lightbox-image').addEventListener('dblclick', (e) => {
        const img = e.target;
        const isZoomed = img.classList.contains('zoomed');
        
        if (isZoomed) {
            gsap.to(img, { scale: 1, duration: 0.3 });
            img.classList.remove('zoomed');
        } else {
            gsap.to(img, { scale: 2, duration: 0.3 });
            img.classList.add('zoomed');
        }
    });
}

function closeLightbox() {
    const overlay = document.querySelector('.lightbox-overlay');
    if (!overlay) return;
    
    gsap.to(overlay, {
        opacity: 0,
        duration: 0.2,
        onComplete: () => {
            overlay.remove();
            document.body.style.overflow = '';
        }
    });
}

function navigateLightbox(direction) {
    if (lightboxImages.length <= 1) return;
    
    currentLightboxIndex += direction;
    
    // Wrap around
    if (currentLightboxIndex < 0) currentLightboxIndex = lightboxImages.length - 1;
    if (currentLightboxIndex >= lightboxImages.length) currentLightboxIndex = 0;
    
    const image = lightboxImages[currentLightboxIndex];
    const container = document.querySelector('.lightbox-image-container');
    const counter = document.querySelector('.lightbox-counter');
    
    if (container && image) {
        // Animate out current
        gsap.to(container.querySelector('img'), {
            opacity: 0,
            x: direction * -50,
            duration: 0.15,
            onComplete: () => {
                // Update image
                const newImg = document.createElement('img');
                newImg.src = image.src;
                newImg.alt = image.alt;
                newImg.className = 'lightbox-image';
                container.innerHTML = '';
                container.appendChild(newImg);
                
                // Animate in new
                gsap.fromTo(newImg,
                    { opacity: 0, x: direction * 50 },
                    { opacity: 1, x: 0, duration: 0.15 }
                );
                
                // Double click zoom for new image
                newImg.addEventListener('dblclick', (e) => {
                    const isZoomed = e.target.classList.contains('zoomed');
                    if (isZoomed) {
                        gsap.to(e.target, { scale: 1, duration: 0.3 });
                        e.target.classList.remove('zoomed');
                    } else {
                        gsap.to(e.target, { scale: 2, duration: 0.3 });
                        e.target.classList.add('zoomed');
                    }
                });
            }
        });
        
        // Update counter
        if (counter) {
            counter.textContent = `${currentLightboxIndex + 1} / ${lightboxImages.length}`;
        }
    }
}

// Export lightbox functions
window.openLightbox = openLightbox;
window.closeLightbox = closeLightbox;
window.navigateLightbox = navigateLightbox;
