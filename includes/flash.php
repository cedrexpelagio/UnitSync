<?php
// Flash Messages (one-time messages via session)
require_once __DIR__ . '/../config/config.php';

function set_flash(string $type, string $message): void {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type' => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message
    ];
}

function get_flashes(): array {
    if (isset($_SESSION['flash_messages'])) {
        $messages = $_SESSION['flash_messages'];
        unset($_SESSION['flash_messages']);
        return $messages;
    }
    return [];
}

function show_flash(): void {
    $flashes = get_flashes();
    if (empty($flashes)) {
        return;
    }
    echo '<div class="flash-container">';
    foreach ($flashes as $flash) {
        $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
        $msg = htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
        echo "<div class=\"flash-message flash-{$type}\" role=\"alert\">{$msg}</div>";
    }
    echo '</div>';
}

/**
 * Like show_flash() but renders a persistent banner (not converted to a toast by JS).
 * Use this when you want the message to stay visible on the page after redirect.
 */
function show_flash_banner(): void {
    $flashes = get_flashes();
    if (empty($flashes)) {
        return;
    }
    echo '<div class="flash-container">';
    foreach ($flashes as $flash) {
        $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
        $msg  = $flash['message']; // allow HTML (e.g. <strong>)
        echo "<div class=\"flash-message flash-{$type}\" data-persist=\"true\" role=\"alert\">{$msg}</div>";
    }
    echo '</div>';
}
