<?php
// Platoon Leader Dashboard (Stage PL-8)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('platoon_leader');

$user = current_user();

// 1. Fetch assignment details for this Platoon Leader
$stmt = $pdo->prepare("
    SELECT ua.company_id, ua.platoon_id, c.name AS company_name, p.name AS platoon_name 
    FROM user_assignments ua 
    LEFT JOIN companies c ON ua.company_id = c.id 
    LEFT JOIN platoons p ON ua.platoon_id = p.id 
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();

$company_id   = $assignment['company_id'] ?? null;
$platoon_id   = $assignment['platoon_id'] ?? null;
$company_name = $assignment['company_name'] ?? 'Unassigned';
$platoon_name = $assignment['platoon_name'] ?? 'Unassigned';

// 2. Fetch active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

// 3. Compute Manila current date
$today_manila = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

// 4. Fetch at-risk threshold from settings
$at_risk_threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');

// 5. Initialize summary metrics
$cadet_count    = 0;
$waiting_count  = 0;
$returned_count = 0;
$returned_sessions = [];
$waiting_sessions  = [];
$overall_attendance = [
    'overall_percentage'  => null,
    'approved_held'       => 0,
    'at_risk_count'       => 0,
    'total_active_cadets' => 0,
];

if ($active_term && $platoon_id) {
    $term_id = (int)$active_term['id'];
    $plt_id  = (int)$platoon_id;

    // Stat 1: Cadets in platoon (active status enrolled in active term)
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = :term_id
        WHERE e.platoon_id = :platoon_id AND c.status = 'active'
    ");
    $stmt->execute(['term_id' => $term_id, 'platoon_id' => $plt_id]);
    $cadet_count = (int)$stmt->fetchColumn();

    // Stat 2: Sessions waiting to be submitted (held or past or today, not cancelled, submission state NULL or 'draft')
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.session_date, ts.label, sub.state
        FROM training_sessions ts
        LEFT JOIN attendance_submissions sub ON sub.session_id = ts.id AND sub.platoon_id = :platoon_id
        WHERE ts.term_id = :term_id
          AND ts.status != 'cancelled'
          AND ts.session_date <= :today
          AND (sub.id IS NULL OR sub.state = 'draft')
        ORDER BY ts.session_date ASC
    ");
    $stmt->execute([
        'term_id'    => $term_id,
        'platoon_id' => $plt_id,
        'today'      => $today_manila
    ]);
    $waiting_sessions = $stmt->fetchAll();
    $waiting_count = count($waiting_sessions);

    // Stat 3: Sessions returned by S1 (state = 'returned')
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.session_date, ts.label, sub.remarks, sub.updated_at
        FROM training_sessions ts
        JOIN attendance_submissions sub ON sub.session_id = ts.id AND sub.platoon_id = :platoon_id
        WHERE ts.term_id = :term_id
          AND ts.status != 'cancelled'
          AND sub.state = 'returned'
        ORDER BY ts.session_date ASC
    ");
    $stmt->execute([
        'term_id'    => $term_id,
        'platoon_id' => $plt_id
    ]);
    $returned_sessions = $stmt->fetchAll();
    $returned_count = count($returned_sessions);

    // Platoon Attendance Summary
    $overall_attendance = get_platoon_overall_attendance($pdo, $plt_id, $term_id, $at_risk_threshold);
}

$page_title = 'Platoon Leader Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Platoon Leader Dashboard</h1>
        <p style="margin: 0; color: var(--gray-700);">
            <strong>Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?></strong>
            <?php if ($active_term): ?>
                &nbsp;|&nbsp; Active Term: <span style="color: var(--green-700); font-weight: 600;"><?= e($active_term['name']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="<?= BASE_URL ?>/leader/attendance.php" class="btn btn-primary btn-sm">
            Take Attendance
        </a>
        <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm">
            View Cadets
        </a>
    </div>
</div>

<?php if (!$active_term): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">No Active Academic Term</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            There is currently no active academic term configured in the system. Cadet enrollments and attendance sessions require an active term.
        </p>
    </div>
<?php elseif (!$platoon_id || !$company_id): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">Unassigned Officer</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            Your account is not assigned to a company and platoon. Please contact your system administrator.
        </p>
    </div>
<?php else: ?>

    <!-- Summary Numbers Cards Grid (Stage PL-8 Core Requirement) -->
    <div class="summary-cards" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom: 24px;">
        
        <!-- Card 1: Cadets in Platoon -->
        <div class="summary-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <h3 style="margin: 0;">Cadets in Platoon</h3>
                <span style="color: var(--green-700);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </span>
            </div>
            <div class="metric-number" style="color: var(--green-900);"><?= $cadet_count ?></div>
            <p style="margin: 0; font-size: 13px; color: var(--gray-700);">
                Active enrolled cadets
                <br>
                <a href="<?= BASE_URL ?>/leader/cadets.php" style="display: inline-block; margin-top: 6px;">Manage Roster &rarr;</a>
            </p>
        </div>

        <!-- Card 2: Waiting to be Submitted -->
        <div class="summary-card" style="<?= $waiting_count > 0 ? 'border-color: #F6E05E;' : '' ?>">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <h3 style="margin: 0;">Waiting to Submit</h3>
                <span style="color: <?= $waiting_count > 0 ? 'var(--warning)' : 'var(--gray-500)' ?>;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </span>
            </div>
            <div class="metric-number" style="color: <?= $waiting_count > 0 ? 'var(--warning)' : 'var(--green-900)' ?>;">
                <?= $waiting_count ?>
            </div>
            <p style="margin: 0; font-size: 13px; color: var(--gray-700);">
                Held sessions (draft or unstarted)
                <br>
                <a href="<?= BASE_URL ?>/leader/attendance.php" style="display: inline-block; margin-top: 6px;">Take Attendance &rarr;</a>
            </p>
        </div>

        <!-- Card 3: Returned by S1 -->
        <div class="summary-card" style="<?= $returned_count > 0 ? 'border-color: #FCA5A5; background-color: #FFFDFD;' : '' ?>">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <h3 style="margin: 0;">Returned by S1</h3>
                <span style="color: <?= $returned_count > 0 ? 'var(--error)' : 'var(--success)' ?>;">
                    <?php if ($returned_count > 0): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <?php endif; ?>
                </span>
            </div>
            <div class="metric-number" style="color: <?= $returned_count > 0 ? 'var(--error)' : 'var(--success)' ?>;">
                <?= $returned_count ?>
            </div>
            <p style="margin: 0; font-size: 13px; color: var(--gray-700);">
                <?= $returned_count > 0 ? 'Requires revision & resubmission' : 'All reviews clear' ?>
                <br>
                <a href="<?= BASE_URL ?>/leader/attendance.php" style="display: inline-block; margin-top: 6px;">View Sessions &rarr;</a>
            </p>
        </div>

        <!-- Card 4: Platoon Attendance Rate -->
        <div class="summary-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <h3 style="margin: 0;">
                    Platoon Attendance
                    <span class="formula-info-btn" title="Formula: (Present + Late) &divide; (Approved Sessions Held &minus; Excused) &times; 100">?</span>
                </h3>
                <span style="color: var(--green-700);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </span>
            </div>
            <div class="metric-number" style="color: <?= ($overall_attendance['overall_percentage'] !== null && $overall_attendance['overall_percentage'] < $at_risk_threshold) ? 'var(--error)' : 'var(--green-900)' ?>;">
                <?= $overall_attendance['overall_percentage'] !== null ? number_format($overall_attendance['overall_percentage'], 1) . '%' : '&mdash;' ?>
            </div>
            <p style="margin: 0; font-size: 13px; color: var(--gray-700);">
                <?php if ($overall_attendance['approved_held'] === 0): ?>
                    No approved sessions yet
                <?php else: ?>
                    <?= $overall_attendance['approved_held'] ?> approved session(s)
                    <?php if ($overall_attendance['at_risk_count'] > 0): ?>
                        &bull; <strong style="color: var(--error);"><?= $overall_attendance['at_risk_count'] ?> at risk (&lt;<?= (int)$at_risk_threshold ?>%)</strong>
                    <?php endif; ?>
                <?php endif; ?>
                <br>
                <a href="<?= BASE_URL ?>/leader/cadets.php" style="display: inline-block; margin-top: 6px;">Inspect Cadets &rarr;</a>
            </p>
        </div>

    </div>

    <!-- Alert: Returned Sessions (if any) -->
    <?php if (!empty($returned_sessions)): ?>
        <div style="background-color: #FEF2F2; border: 1px solid #FCA5A5; border-radius: var(--radius-default); padding: 18px 20px; margin-bottom: 24px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div style="flex: 1;">
                    <h3 style="font-size: 15px; color: #991B1B; margin-bottom: 6px;">
                        <?= count($returned_sessions) ?> Session(s) Returned by S1
                    </h3>
                    <p style="font-size: 13px; color: #7F1D1D; margin-bottom: 12px;">
                        The S1 reviewer returned the following attendance sheet(s) with revision notes. Please review the remarks, adjust attendance records, and resubmit.
                    </p>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($returned_sessions as $ret): ?>
                            <div style="background: var(--white); border: 1px solid #FCA5A5; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <strong style="color: var(--green-900);"><?= e($ret['session_date']) ?>: <?= e($ret['label']) ?></strong>
                                    <?php if (!empty($ret['remarks'])): ?>
                                        <div style="font-size: 12px; color: #991B1B; margin-top: 2px;">
                                            <strong>S1 Remarks:</strong> <?= e($ret['remarks']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <a href="<?= BASE_URL ?>/leader/attendance.php?session=<?= (int)$ret['id'] ?>" class="btn btn-sm btn-primary" style="background-color: var(--error); border-color: var(--error);">
                                    Fix &amp; Resubmit &rarr;
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Two-Column Layout: Officer Profile Card & Platoon Operations -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 24px;">
        
        <!-- Officer Profile Card (Kept as required by Stage PL-8) -->
        <div class="summary-card">
            <h3 style="font-size: 15px; color: var(--green-900); margin-bottom: 16px; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                Officer Profile
            </h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <tr style="border-bottom: 1px solid var(--gray-300);">
                    <td style="padding: 8px 0; color: var(--gray-700); width: 130px;"><strong>Full Name:</strong></td>
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

        <!-- Platoon Operations & Quick Actions -->
        <div class="summary-card">
            <h3 style="font-size: 15px; color: var(--green-900); margin-bottom: 16px; border-bottom: 1px solid var(--gray-300); padding-bottom: 8px;">
                Platoon Operations
            </h3>
            <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
                Quick shortcuts to manage your platoon roster and mark attendance for training sessions.
            </p>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <a href="<?= BASE_URL ?>/leader/attendance.php" class="btn btn-primary" style="justify-content: center;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    Take / Submit Attendance
                </a>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary" style="flex: 1; justify-content: center;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                        View Cadets (<?= $cadet_count ?>)
                    </a>
                    <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-secondary" style="flex: 1; justify-content: center;">
                        + Add Cadet
                    </a>
                </div>
            </div>
            
            <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--gray-300); font-size: 12px; color: var(--gray-700);">
                <strong>At-Risk Benchmark:</strong> Cadets below <strong><?= (int)$at_risk_threshold ?>%</strong> attendance are flagged as at-risk on the roster.
            </div>
        </div>

    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
