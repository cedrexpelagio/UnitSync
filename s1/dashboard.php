<?php
// S1 Officer Dashboard (Stage 5: Shared for Battalion S1 & Brigade S1)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

// Active cadet count (safe if the cadets table has not been created yet)
$total_cadets = 0;
try {
    $total_cadets = (int)$pdo->query("SELECT COUNT(*) FROM cadets WHERE status = 'active'")->fetchColumn();
} catch (Throwable $ex) {
    $total_cadets = 0;
}

$page_title = format_role_name($user['role']) . ' Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><?= e(format_role_name($user['role'])) ?> Dashboard</h1>
    <p>Cadet roster oversight and two-level attendance review</p>
</div>

<div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 16px;">S1 Officer Profile</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr style="border-bottom: 1px solid var(--gray-300);">
            <td style="padding: 8px 0; color: var(--gray-700); width: 140px;"><strong>Full Name:</strong></td>
            <td style="padding: 8px 0;"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></td>
        </tr>
        <tr style="border-bottom: 1px solid var(--gray-300);">
            <td style="padding: 8px 0; color: var(--gray-700);"><strong>Username:</strong></td>
            <td style="padding: 8px 0;"><strong><?= e($user['username']) ?></strong></td>
        </tr>
        <tr style="border-bottom: 1px solid var(--gray-300);">
            <td style="padding: 8px 0; color: var(--gray-700);"><strong>Role:</strong></td>
            <td style="padding: 8px 0;"><span class="badge badge-approved"><?= e(format_role_name($user['role'])) ?></span></td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: var(--gray-700);"><strong>Data Scope:</strong></td>
            <td style="padding: 8px 0; color: var(--green-700); font-weight: 600;">All Cadets & Units (Unit-wide)</td>
        </tr>
    </table>
</div>

<div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 8px;">Cadet Roster</h3>
    <p style="font-size: 14px; margin-bottom: 4px;">Active cadets in roster: <strong><?= $total_cadets ?></strong></p>
    <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
        Upload a CSV file (such as Google Form responses) to add cadets in bulk. You can preview and fix errors before anything is saved.
    </p>
    <a href="<?= BASE_URL ?>/s1/import_cadets.php" class="btn btn-primary">Import Cadets (CSV)</a>
    <a href="<?= BASE_URL ?>/s1/import_cadets.php?template=1" class="btn btn-secondary">Download Template</a>
</div>

<div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; max-width: 650px;">
    <h4 style="color: var(--warning); margin-bottom: 8px;">MVP Status: Dashboard Active</h4>
    <p style="font-size: 13px; color: var(--gray-700);">
        Unit-wide cadet roster review and attendance approval queue features will be unlocked in Phase 2.
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>