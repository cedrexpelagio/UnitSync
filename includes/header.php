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
</head>
<body>
    <header class="app-header">
        <div class="header-brand">
            <strong>UnitSync</strong>
        </div>
        <div class="header-user">
            <?php if ($user): ?>
                <span>Notifications: 0</span>
                <span><?= e($user['first_name'] . ' ' . $user['last_name']) ?> (<?= e(format_role_name($user['role'])) ?>)</span>
                <form action="<?= BASE_URL ?>/auth/logout.php" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit">Sign out</button>
                </form>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php">Log In</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="app-body">
        <?php if ($user): ?>
            <?php require_once __DIR__ . '/sidebar.php'; ?>
        <?php endif; ?>
        <main class="app-content">
            <?php show_flash(); ?>
