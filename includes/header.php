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
    <title><?= e($page_title) ?> - UnitSync</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
    <header class="app-header">
        <div class="header-brand">
            <?php if ($user): ?>
                <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle" aria-label="Toggle navigation menu">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
            <?php endif; ?>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gold-500);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>UnitSync</span>
        </div>
        <div class="header-user">
            <?php if ($user): ?>
                <span style="display: inline-flex; align-items: center; gap: 4px;" title="Notifications">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    0
                </span>
                <span><?= e($user['first_name'] . ' ' . $user['last_name']) ?> (<strong><?= e(format_role_name($user['role'])) ?></strong>)</span>
                <form id="signout-form" action="<?= BASE_URL ?>/auth/logout.php" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="button" id="signout-btn" class="btn btn-secondary btn-sm" style="color: #fff; border-color: rgba(255,255,255,0.3);">Sign out</button>
                </form>
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
