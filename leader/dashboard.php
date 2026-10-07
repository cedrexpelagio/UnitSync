<?php
// Platoon Leader Dashboard (Stage 3)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('platoon_leader');

$user = current_user();

// Fetch assignment details for this Platoon Leader
$stmt = $pdo->prepare("
    SELECT c.name AS company_name, p.name AS platoon_name 
    FROM user_assignments ua 
    LEFT JOIN companies c ON ua.company_id = c.id 
    LEFT JOIN platoons p ON ua.platoon_id = p.id 
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();
$company_name = $assignment['company_name'] ?? 'Unassigned';
$platoon_name = $assignment['platoon_name'] ?? 'Unassigned';

$page_title = 'Platoon Leader Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Platoon Leader Dashboard</h1>
    <p>Welcome to UnitSync ROTC attendance system</p>
</div>

<div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 16px;">Officer Profile</h3>
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
            <td style="padding: 8px 0; color: var(--gray-700);"><strong>Assigned Unit:</strong></td>
            <td style="padding: 8px 0; color: var(--green-700); font-weight: 600;">
                Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?>
            </td>
        </tr>
    </table>
</div>

<div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; max-width: 650px;">
    <h4 style="color: var(--warning); margin-bottom: 8px;">MVP Status: Dashboard Active</h4>
    <p style="font-size: 13px; color: var(--gray-700);">
        Cadet encoding, roster management, and attendance submission features will be unlocked in Phase 2.
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
