<?php
// Class President Dashboard (Stage 4)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('class_president');

$user = current_user();

// Fetch program assignment
$stmt = $pdo->prepare("
    SELECT p.code AS prog_code, p.name AS prog_name 
    FROM user_assignments ua 
    LEFT JOIN programs p ON ua.program_id = p.id 
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();
$prog_code = $assignment['prog_code'] ?? 'Unassigned';
$prog_name = $assignment['prog_name'] ?? 'Not Assigned';

$page_title = 'Class President Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Class President Dashboard</h1>
    <p>Read-only monitoring of section attendance</p>
</div>

<div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 16px;">Representative Profile</h3>
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
            <td style="padding: 8px 0; color: var(--gray-700);"><strong>Assigned Section:</strong></td>
            <td style="padding: 8px 0; color: var(--green-700); font-weight: 600;">
                <?= e($prog_code) ?> &mdash; <?= e($prog_name) ?>
            </td>
        </tr>
    </table>
</div>

<div style="background-color: var(--gray-50); border: 1px dashed var(--gray-300); border-radius: var(--radius-default); padding: 32px; max-width: 650px; text-align: center;">
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gold-500); margin-bottom: 8px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
    <p><strong>Your section's attendance will appear here.</strong></p>
    <p style="font-size: 13px; color: var(--gray-700);">Attendance monitoring and per-cadet percentages will be displayed once training sessions are active.</p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
