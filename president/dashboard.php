<?php
// Class President Dashboard (Stage 4)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_queries.php';
require_once __DIR__ . '/../includes/s1_ui.php';

require_role('class_president');

$user = current_user();

// Fetch program assignment
$stmt = $pdo->prepare("
    SELECT ua.program_id, p.code AS prog_code, p.name AS prog_name 
    FROM user_assignments ua 
    LEFT JOIN programs p ON ua.program_id = p.id 
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();
$program_id = (int)($assignment['program_id'] ?? 0);
$prog_code = $assignment['prog_code'] ?? 'Unassigned';
$prog_name = $assignment['prog_name'] ?? 'Not Assigned';

// Section snapshot (fully approved attendance only). Skipped when no program is assigned.
$at_risk_threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');
$active_term = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1")->fetch();
$snap = null;

if ($active_term && $program_id > 0) {
    $term_id = (int)$active_term['id'];
    $filters = [
        'company_id' => '',
        'platoon_id' => '',
        'program_id' => $program_id,
        'session_id' => 0,
        'status'     => 'active',
        'q'          => '',
    ];

    $sessions = get_approved_sessions($pdo, $term_id);
    $all_cadets = get_filtered_cadets($pdo, $term_id, $filters);
    $stats = $all_cadets ? get_cadet_term_attendance_stats($pdo, $term_id, array_column($all_cadets, 'id')) : [];

    $rated = 0;
    $sum = 0.0;
    $risk = 0;
    foreach ($all_cadets as $c) {
        $pct = $stats[(int)$c['id']]['percentage'] ?? null;
        if ($pct === null) continue;
        $rated++;
        $sum += $pct;
        if ($pct < $at_risk_threshold) $risk++;
    }

    $snap = [
        'cadets'   => count($all_cadets),
        'sessions' => count($sessions),
        'avg'      => $rated > 0 ? $sum / $rated : null,
        'risk'     => $risk,
        'rated'    => $rated,
    ];
}

$page_title = 'Class President Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

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

<?php if ($snap && $snap['sessions'] > 0): ?>
    <?php $avg_tone = s1_tone($snap['avg'], $at_risk_threshold); ?>
    <section class="s1-summary" style="max-width: 900px;" aria-labelledby="presSnapTitle">
        <div class="s1-summary-head">
            <h2 id="presSnapTitle">Section snapshot</h2>
            <span><?= e($prog_code) ?> &middot; <?= e($active_term['name']) ?> &middot; fully approved sessions only</span>
        </div>

        <div class="s1-kpis">
            <div class="s1-kpi <?= $avg_tone === 'bad' ? 'is-bad' : '' ?>">
                <span class="s1-kpi-label">Average attendance</span>
                <strong class="s1-kpi-val s1-tone-<?= $avg_tone ?>"><?= $snap['avg'] === null ? '&mdash;' : number_format($snap['avg'], 1) . '%' ?></strong>
                <small>Threshold at <?= (int)$at_risk_threshold ?>%</small>
            </div>
            <div class="s1-kpi <?= $snap['risk'] > 0 ? 'is-bad' : '' ?>">
                <span class="s1-kpi-label">Cadets at risk</span>
                <strong class="s1-kpi-val <?= $snap['risk'] > 0 ? 's1-tone-bad' : 's1-tone-good' ?>"><?= (int)$snap['risk'] ?></strong>
                <small>of <?= (int)$snap['rated'] ?> rated cadets</small>
            </div>
            <div class="s1-kpi is-gold">
                <span class="s1-kpi-label">Approved sessions</span>
                <strong class="s1-kpi-val"><?= (int)$snap['sessions'] ?></strong>
                <small>This term</small>
            </div>
            <div class="s1-kpi is-gold">
                <span class="s1-kpi-label">Cadets in section</span>
                <strong class="s1-kpi-val"><?= (int)$snap['cadets'] ?></strong>
                <small>Active cadets</small>
            </div>
        </div>

        <p style="margin-top: 16px;">
            <a href="<?= BASE_URL ?>/president/view_attendance.php" class="btn btn-primary">Open full attendance matrix</a>
        </p>
    </section>
<?php else: ?>
    <div style="background-color: var(--gray-50); border: 1px dashed var(--gray-300); border-radius: var(--radius-default); padding: 32px; max-width: 650px; text-align: center;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gold-500); margin-bottom: 8px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <?php if ($program_id === 0): ?>
            <p><strong>No section assigned yet.</strong></p>
            <p style="font-size: 13px; color: var(--gray-700);">Please contact the Admin to assign your program.</p>
        <?php else: ?>
            <p><strong>Your section's attendance will appear here.</strong></p>
            <p style="font-size: 13px; color: var(--gray-700);">Attendance monitoring and per-cadet percentages will be displayed once a training session is fully approved.</p>
            <a href="<?= BASE_URL ?>/president/view_attendance.php" class="btn btn-secondary btn-sm">Open View Attendance</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
