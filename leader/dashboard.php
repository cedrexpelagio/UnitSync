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
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leader.css">

<?php
// Small inline icon set (stroke icons, same family as the s1 pages)
$svg = function (string $inner): string {
    return '<svg class="pl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$ico = [
    'arrow'    => $svg('<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>'),
    'users'    => $svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
    'clock'    => $svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
    'alert'    => $svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'),
    'check'    => $svg('<polyline points="20 6 9 17 4 12"/>'),
    'target'   => $svg('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'),
    'clipboard'=> $svg('<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><polyline points="9 14 11 16 15 12"/>'),
    'plus'     => $svg('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>'),
];

$fmt_date = fn(string $d): string => date('M j, Y', strtotime($d));

$first_waiting_id  = !empty($waiting_sessions)  ? (int)$waiting_sessions[0]['id']  : 0;
$first_returned_id = !empty($returned_sessions) ? (int)$returned_sessions[0]['id'] : 0;
$attendance_url    = BASE_URL . '/leader/attendance.php';
$pct               = $overall_attendance['overall_percentage'];
$pct_is_low        = ($pct !== null && $pct < $at_risk_threshold);
$waiting_preview   = array_slice($waiting_sessions, 0, 6);
?>

<div class="pl">

    <!-- Hero -->
    <div class="pl-hero">
        <div>
            <div class="pl-eyebrow">Platoon leader dashboard</div>
            <h1><?= $platoon_id ? e($platoon_name) : 'Platoon Leader Dashboard' ?></h1>
            <p class="pl-sub">
                <?php if ($company_id): ?>
                    <strong>Company <?= e($company_name) ?></strong>
                <?php endif; ?>
                <?php if ($active_term): ?>
                    <?= $company_id ? '&nbsp;|&nbsp;' : '' ?>Term: <span class="pl-term"><?= e($active_term['name']) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($active_term && $platoon_id && $company_id): ?>
            <div class="pl-hero-actions">
                <a href="<?= $attendance_url ?>" class="btn btn-primary"><?= $ico['clipboard'] ?> Take attendance</a>
                <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary"><?= $ico['users'] ?> Cadets</a>
                <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-secondary"><?= $ico['plus'] ?> Add cadet</a>
            </div>
        <?php endif; ?>
    </div>

<?php if (!$active_term): ?>

    <div class="pl-notice pl-notice--warn" role="alert">
        <?= $ico['alert'] ?>
        <div class="pl-notice-body">
            <span class="pl-notice-title">No active academic term</span>
            <p>Cadet enrollment and attendance sessions need an active term. Ask your administrator to activate one.</p>
        </div>
    </div>

<?php elseif (!$platoon_id || !$company_id): ?>

    <div class="pl-notice pl-notice--warn" role="alert">
        <?= $ico['alert'] ?>
        <div class="pl-notice-body">
            <span class="pl-notice-title">Your account has no platoon yet</span>
            <p>You are not assigned to a company and platoon. Please contact your system administrator.</p>
        </div>
    </div>

<?php else: ?>

    <!-- Next action -->
    <?php if ($waiting_count > 0 || $returned_count > 0): ?>
        <div class="pl-attention" data-pl-reveal>
            <span class="pl-attention-title"><?= $ico['alert'] ?> Needs your attention</span>
            <?php if ($returned_count > 0): ?>
                <a class="pl-chip-link is-danger" href="<?= $attendance_url ?>?session=<?= $first_returned_id ?>">
                    <strong><?= $returned_count ?></strong> returned by S1 <?= $ico['arrow'] ?>
                </a>
            <?php endif; ?>
            <?php if ($waiting_count > 0): ?>
                <a class="pl-chip-link" href="<?= $attendance_url ?>?session=<?= $first_waiting_id ?>">
                    <strong><?= $waiting_count ?></strong> waiting to submit <?= $ico['arrow'] ?>
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="pl-attention is-clear" data-pl-reveal>
            <span class="pl-attention-title"><?= $ico['check'] ?> You are all caught up. No sessions are waiting or returned.</span>
        </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="pl-kpis">

        <a class="pl-kpi" href="<?= BASE_URL ?>/leader/cadets.php" style="--accent: var(--green-700);" data-pl-reveal>
            <span class="pl-kpi-icon"><?= $ico['users'] ?></span>
            <span class="pl-kpi-label">Cadets in platoon</span>
            <span class="pl-kpi-number"><?= (int)$cadet_count ?></span>
            <span class="pl-kpi-note">Active and enrolled this term</span>
            <span class="pl-kpi-link">Manage roster <?= $ico['arrow'] ?></span>
        </a>

        <a class="pl-kpi" href="<?= $attendance_url ?><?= $first_waiting_id ? '?session=' . $first_waiting_id : '' ?>"
           style="--accent: <?= $waiting_count > 0 ? 'var(--warning)' : 'var(--green-700)' ?>;" data-pl-reveal>
            <span class="pl-kpi-icon"><?= $ico['clock'] ?></span>
            <span class="pl-kpi-label">Waiting to submit</span>
            <span class="pl-kpi-number"><?= (int)$waiting_count ?></span>
            <span class="pl-kpi-note">Held sessions that are draft or not started</span>
            <span class="pl-kpi-link">Take attendance <?= $ico['arrow'] ?></span>
        </a>

        <a class="pl-kpi" href="<?= $attendance_url ?><?= $first_returned_id ? '?session=' . $first_returned_id : '' ?>"
           style="--accent: <?= $returned_count > 0 ? 'var(--error)' : 'var(--success)' ?>;" data-pl-reveal>
            <span class="pl-kpi-icon"><?= $returned_count > 0 ? $ico['alert'] : $ico['check'] ?></span>
            <span class="pl-kpi-label">Returned by S1</span>
            <span class="pl-kpi-number"><?= (int)$returned_count ?></span>
            <span class="pl-kpi-note"><?= $returned_count > 0 ? 'Fix the sheet and resubmit' : 'Nothing to fix' ?></span>
            <span class="pl-kpi-link">View sessions <?= $ico['arrow'] ?></span>
        </a>

        <a class="pl-kpi" href="<?= BASE_URL ?>/leader/cadets.php"
           style="--accent: <?= $pct_is_low ? 'var(--error)' : 'var(--green-700)' ?>;" data-pl-reveal>
            <span class="pl-kpi-icon"><?= $ico['target'] ?></span>
            <span class="pl-kpi-label">
                Platoon attendance
                <span class="pl-info-dot" title="Formula: (Present + Late) &divide; (Approved Sessions Held &minus; Excused) &times; 100">?</span>
            </span>
            <span class="pl-kpi-number"><?= $pct !== null ? number_format($pct, 1) . '%' : '&mdash;' ?></span>
            <span class="pl-kpi-note">
                <?php if ($overall_attendance['approved_held'] === 0): ?>
                    No approved sessions yet
                <?php else: ?>
                    <?= (int)$overall_attendance['approved_held'] ?> approved session(s)
                    <?php if ($overall_attendance['at_risk_count'] > 0): ?>
                        &bull; <strong style="color: var(--error);"><?= (int)$overall_attendance['at_risk_count'] ?> at risk</strong>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
            <span class="pl-kpi-link">Inspect cadets <?= $ico['arrow'] ?></span>
        </a>
    </div>

    <!-- Returned by S1 -->
    <?php if (!empty($returned_sessions)): ?>
        <section class="pl-card" data-pl-reveal aria-labelledby="pl-returned-title">
            <div class="pl-card-head">
                <div>
                    <h2 id="pl-returned-title">Returned by S1 (<?= (int)$returned_count ?>)</h2>
                    <p class="pl-muted">Read the remarks, correct the sheet, then submit it again.</p>
                </div>
            </div>
            <ul class="pl-list">
                <?php foreach ($returned_sessions as $ret): ?>
                    <li class="pl-row pl-row--danger">
                        <div class="pl-row-main">
                            <div class="pl-row-title"><?= e($ret['label']) ?></div>
                            <div class="pl-row-sub">
                                <?= e($fmt_date($ret['session_date'])) ?>
                                <?php if (!empty($ret['remarks'])): ?>
                                    &nbsp;&bull;&nbsp;<strong>S1 remarks:</strong> <?= e($ret['remarks']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="<?= $attendance_url ?>?session=<?= (int)$ret['id'] ?>" class="btn btn-sm btn-danger-solid">Fix and resubmit</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <!-- Waiting to submit -->
    <section class="pl-card" data-pl-reveal aria-labelledby="pl-waiting-title">
        <div class="pl-card-head">
            <div>
                <h2 id="pl-waiting-title">Sessions to take</h2>
                <p class="pl-muted">Held sessions that still need an attendance sheet from your platoon.</p>
            </div>
            <a href="<?= $attendance_url ?>" class="btn btn-secondary btn-sm">All sessions</a>
        </div>

        <?php if (empty($waiting_sessions)): ?>
            <div class="pl-empty">
                <?= $ico['check'] ?>
                <h3>Nothing waiting</h3>
                <p>Every held session has been submitted. New sessions will appear here once their date arrives.</p>
            </div>
        <?php else: ?>
            <ul class="pl-list">
                <?php foreach ($waiting_preview as $w): ?>
                    <li class="pl-row">
                        <div class="pl-row-main">
                            <div class="pl-row-title"><?= e($w['label']) ?></div>
                            <div class="pl-row-sub"><?= e($fmt_date($w['session_date'])) ?></div>
                        </div>
                        <div class="pl-row-side">
                            <?php if (($w['state'] ?? null) === 'draft'): ?>
                                <span class="badge badge-draft">Draft</span>
                            <?php else: ?>
                                <span class="badge badge-deactivated">Not started</span>
                            <?php endif; ?>
                            <a href="<?= $attendance_url ?>?session=<?= (int)$w['id'] ?>" class="btn btn-primary btn-sm">
                                <?= ($w['state'] ?? null) === 'draft' ? 'Continue' : 'Take attendance' ?>
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($waiting_count > count($waiting_preview)): ?>
                <p class="pl-foot-note" style="border-top: none; padding-top: 0;">
                    Showing <?= count($waiting_preview) ?> of <?= (int)$waiting_count ?>.
                    <a href="<?= $attendance_url ?>">Open attendance</a> to see the rest.
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <div class="pl-foot-note">
            <strong>At-risk benchmark:</strong> cadets below <strong><?= (int)$at_risk_threshold ?>%</strong> attendance are flagged on the roster.
        </div>
    </section>

<?php endif; ?>

</div>

<script src="<?= BASE_URL ?>/assets/js/leader.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
