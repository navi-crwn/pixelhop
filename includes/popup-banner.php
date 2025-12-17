<?php
/**
 * PixelHop - Popup Banner Component
 * Include this file on pages where you want to show the popup banner
 */

require_once __DIR__ . '/core/Gatekeeper.php';

$gatekeeper = new Gatekeeper();

$popupEnabled = $gatekeeper->getSetting('popup_enabled', 0);
$popupTitle = $gatekeeper->getSetting('popup_title', '');
$popupMessage = $gatekeeper->getSetting('popup_message', '');
$popupButtonText = $gatekeeper->getSetting('popup_button_text', 'Got it');
$popupButtonUrl = $gatekeeper->getSetting('popup_button_url', '');
$popupType = $gatekeeper->getSetting('popup_type', 'info');
$popupShowOnce = $gatekeeper->getSetting('popup_show_once', 1);
$popupDelay = $gatekeeper->getSetting('popup_delay_ms', 1000);

if (!$popupEnabled || empty($popupTitle)) {
    return;
}

$colors = [
    'info' => ['bg' => 'rgba(34, 211, 238, 0.95)', 'border' => '#22d3ee', 'text' => '#000'],
    'success' => ['bg' => 'rgba(34, 197, 94, 0.95)', 'border' => '#22c55e', 'text' => '#000'],
    'warning' => ['bg' => 'rgba(234, 179, 8, 0.95)', 'border' => '#eab308', 'text' => '#000'],
    'error' => ['bg' => 'rgba(239, 68, 68, 0.95)', 'border' => '#ef4444', 'text' => '#fff'],
];
$color = $colors[$popupType] ?? $colors['info'];
?>

<style>
.pixelhop-popup-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.pixelhop-popup-overlay.show {
    display: flex;
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.pixelhop-popup {
    background: <?= $color['bg'] ?>;
    border: 2px solid <?= $color['border'] ?>;
    border-radius: 16px;
    padding: 28px;
    max-width: 450px;
    width: 100%;
    text-align: center;
    color: <?= $color['text'] ?>;
    position: relative;
    animation: slideUp 0.3s ease;
}
@keyframes slideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.pixelhop-popup-close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.2);
    border: none;
    color: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pixelhop-popup-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 12px;
}
.pixelhop-popup-message {
    font-size: 14px;
    line-height: 1.6;
    opacity: 0.9;
    margin-bottom: 20px;
}
.pixelhop-popup-btn {
    display: inline-block;
    padding: 12px 28px;
    background: <?= $color['text'] ?>;
    color: <?= $color['bg'] ?>;
    font-weight: 600;
    border-radius: 10px;
    text-decoration: none;
    cursor: pointer;
    border: none;
    font-size: 14px;
}
</style>

<div class="pixelhop-popup-overlay" id="pixelhopPopup">
    <div class="pixelhop-popup">
        <button class="pixelhop-popup-close" onclick="closePixelHopPopup()">✕</button>
        <div class="pixelhop-popup-title"><?= htmlspecialchars($popupTitle) ?></div>
        <div class="pixelhop-popup-message"><?= nl2br(htmlspecialchars($popupMessage)) ?></div>
        <?php if ($popupButtonUrl): ?>
        <a href="<?= htmlspecialchars($popupButtonUrl) ?>" class="pixelhop-popup-btn"><?= htmlspecialchars($popupButtonText) ?></a>
        <?php else: ?>
        <button class="pixelhop-popup-btn" onclick="closePixelHopPopup()"><?= htmlspecialchars($popupButtonText) ?></button>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const showOnce = <?= $popupShowOnce ? 'true' : 'false' ?>;
    const delay = <?= (int) $popupDelay ?>;
    const storageKey = 'pixelhop_popup_seen';

    if (showOnce && sessionStorage.getItem(storageKey)) {
        return;
    }

    setTimeout(() => {
        document.getElementById('pixelhopPopup').classList.add('show');
        if (showOnce) {
            sessionStorage.setItem(storageKey, '1');
        }
    }, delay);
})();

function closePixelHopPopup() {
    document.getElementById('pixelhopPopup').classList.remove('show');
}
</script>
