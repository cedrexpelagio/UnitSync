<?php
// UnitSync: i-Cadet Floating Widget
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../csrf.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/knowledge.php';

if (!is_logged_in()) {
    return;
}
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/db.php';
}
if (!ai_is_enabled($pdo)) {
    return;
}

$user = current_user();
$role = $user['role'] ?? '';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
$csrfToken = generate_csrf_token();
$suggestions = ai_get_page_suggestions($role, $currentPage);
$initials = mb_strtoupper(mb_substr($user['first_name'] ?? 'U', 0, 1));
?>

<!-- i-Cadet Floating Trigger Button -->
<button type="button" id="ai-chat-toggle" class="ai-chat-toggle" aria-label="Open i-Cadet Assistant" title="Need help? Ask i-Cadet!">
    <span class="ai-toggle-avatar">
        <!-- Friendly Cadet Icon (Cap / Badge) -->
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
            <path d="M2 17l10 5 10-5"></path>
            <path d="M2 12l10 5 10-5"></path>
        </svg>
    </span>
    <span class="ai-toggle-pulse" aria-hidden="true"></span>
    <span class="ai-toggle-label">i-Cadet</span>
</button>

<!-- i-Cadet Chat Drawer Panel -->
<div id="ai-chat-panel" class="ai-chat-panel" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ai-chat-title"
     data-base-url="<?= BASE_URL ?>"
     data-csrf="<?= e($csrfToken) ?>" 
     data-current-page="<?= e($currentPage) ?>" 
     data-role="<?= e($role) ?>"
     data-first-name="<?= e($user['first_name']) ?>">

    <!-- Chat Header -->
    <div class="ai-chat-header">
        <div class="ai-header-info">
            <div class="ai-avatar-badge">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                    <path d="M2 17l10 5 10-5"></path>
                    <path d="M2 12l10 5 10-5"></path>
                </svg>
                <span class="ai-online-dot"></span>
            </div>
            <div>
                <h3 id="ai-chat-title" class="ai-header-title">i-Cadet</h3>
                <span class="ai-header-sub">Friendly AI Guide Cadet</span>
            </div>
        </div>
        <div class="ai-header-actions">
            <button type="button" id="ai-chat-clear" class="ai-header-btn" title="Clear chat history" aria-label="Clear chat history">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
            </button>
            <button type="button" id="ai-chat-close" class="ai-header-btn" title="Close i-Cadet" aria-label="Close assistant">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
    </div>

    <!-- Messages List -->
    <div id="ai-chat-messages" class="ai-chat-messages" role="log" aria-live="polite">
        <!-- Default Welcome Greeting -->
        <div class="ai-msg ai-msg-bot">
            <div class="ai-msg-bubble">
                <p><strong>Snappy salute, <?= e($user['first_name']) ?>!</strong> 🫡</p>
                <p>I'm <strong>i-Cadet</strong>, your friendly AI cadet guide. I can answer questions about attendance rules, system workflows, and help you navigate UnitSync.</p>
                <p>How can I assist you today, Sir/Ma'am?</p>
            </div>
            <span class="ai-msg-time">Just now</span>
        </div>

        <!-- Dynamic suggestions container -->
        <div id="ai-quick-suggestions" class="ai-quick-suggestions">
            <div class="ai-suggestions-title">Suggested questions:</div>
            <div class="ai-suggestions-chips">
                <?php foreach ($suggestions as $q): ?>
                    <button type="button" class="ai-chip-btn" data-query="<?= e($q) ?>"><?= e($q) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Typing Indicator (Hidden by default) -->
    <div id="ai-typing-indicator" class="ai-typing" style="display:none;" aria-hidden="true">
        <span class="ai-dot"></span>
        <span class="ai-dot"></span>
        <span class="ai-dot"></span>
        <span class="ai-typing-text">i-Cadet is thinking...</span>
    </div>

    <!-- Chat Form / Input -->
    <form id="ai-chat-form" class="ai-chat-form" autocomplete="off">
        <div class="ai-input-wrap">
            <textarea id="ai-chat-input" class="ai-chat-input" rows="1" placeholder="Ask i-Cadet a question..." maxlength="1000" aria-label="Ask i-Cadet"></textarea>
            <button type="submit" id="ai-chat-send" class="ai-chat-send" aria-label="Send question" title="Send (Enter)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>
        <div class="ai-chat-footer-note">
            <span>Official UnitSync guide • Read-only</span>
        </div>
    </form>
</div>
