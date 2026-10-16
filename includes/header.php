<?php
// Layout Header (Plain HTML and PHP for Step A)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/csrf.php';

$user = current_user();
$page_title = $page_title ?? 'UnitSync';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/theme_head.php'; ?>
    <title><?= e($page_title) ?> - UnitSync</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app-shell.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/chat.css">
    <script src="<?= BASE_URL ?>/assets/js/app-shell.js" defer></script>
    <script src="<?= BASE_URL ?>/assets/js/theme.js" defer></script>
</head>
<body class="app-shell">
    <header class="app-header">
        <div class="header-brand">
            <?php if ($user): ?>
                <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle" aria-label="Toggle navigation menu">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
            <?php endif; ?>
            <span class="brand-name">Unit<span>Sync</span></span>
        </div>
        <div class="header-user">
            <?php require __DIR__ . '/theme_toggle.php'; ?>
            <?php if ($user): ?>
                <?php
                    $full_name = trim($user['first_name'] . ' ' . $user['last_name']);
                    $initials = mb_strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1));
                ?>
                <details class="user-menu" id="user-menu">
                    <summary>
                        <span class="user-avatar"><?= e($initials !== '' ? $initials : '?') ?></span>
                        <span class="user-menu-name"><?= e($full_name) ?></span>
                        <svg class="user-menu-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </summary>
                    <div class="user-menu-panel">
                        <div class="user-menu-info">
                            <div class="user-menu-fullname"><?= e($full_name) ?></div>
                            <div class="user-menu-username">@<?= e($user['username']) ?></div>
                            <div class="user-menu-role"><?= e(format_role_name($user['role'])) ?></div>
                        </div>
                        <a class="user-menu-link" href="<?= BASE_URL ?>/profile.php">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            My Profile
                        </a>
                        <form id="signout-form" action="<?= BASE_URL ?>/auth/logout.php" method="POST">
                            <?= csrf_field() ?>
                            <button type="button" id="signout-btn" class="user-menu-signout">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                Sign out
                            </button>
                        </form>
                    </div>
                </details>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-sm">Log In</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="app-body">
        <?php if ($user): ?>
            <?php require_once __DIR__ . '/sidebar.php'; ?>
        <?php endif; ?>
        <main class="app-content">
            <?php show_flash(); ?>
