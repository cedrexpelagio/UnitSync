<?php
// Admin Dashboard (Stage 2 - Step A)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';

// Fetch summary metrics
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$pending_count = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved'");
$approved_count = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$total_count = (int)$stmt->fetchColumn();
?>

<div class="content-header">
    <h1>Admin Dashboard</h1>
    <p>System overview and quick access to administrative tasks</p>
</div>

<div class="summary-cards">
    <div class="summary-card">
        <h3>Pending Requests</h3>
        <div class="metric-number" style="color: var(--warning);"><?= $pending_count ?></div>
        <p><a href="<?= BASE_URL ?>/admin/requests.php">Review Requests &rarr;</a></p>
    </div>
    <div class="summary-card">
        <h3>Approved Users</h3>
        <div class="metric-number" style="color: var(--success);"><?= $approved_count ?></div>
        <p><a href="<?= BASE_URL ?>/admin/users.php">Manage Users &rarr;</a></p>
    </div>
    <div class="summary-card">
        <h3>Total Accounts</h3>
        <div class="metric-number" style="color: var(--green-900);"><?= $total_count ?></div>
        <p style="color: var(--gray-700); font-size: 13px;">Registered in system</p>
    </div>
</div>

<div style="background-color: var(--white); border: 1px solid var(--gray-300); border-radius: var(--radius-default); padding: 24px; box-shadow: var(--card-shadow);">
    <h2 style="font-size: 18px; color: var(--green-900); margin-bottom: 12px;">Administrative Actions</h2>
    <div style="display: flex; gap: 16px; flex-wrap: wrap;">
        <a href="<?= BASE_URL ?>/admin/requests.php" class="btn btn-primary btn-sm">Review Registration Requests</a>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">Manage Active Users</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
