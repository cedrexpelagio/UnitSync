<?php
// Platoon Leader: Take Attendance (Stage PL-5)
//
// File layout:
//   1. Bootstrap
//   2. Constants and data/helper functions
//   3. Request flow (load data -> handle POST -> build view data)
//   4. View (HTML only; styles in assets/css/attendance.css,
//      behaviour in assets/js/attendance.js)

// ---------------------------------------------------------------
// 1. Bootstrap
// ---------------------------------------------------------------
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('platoon_leader');

// ---------------------------------------------------------------
// 2. Constants and functions
// ---------------------------------------------------------------
const ATT_TIMEZONE         = 'Asia/Manila';
const ATT_MAX_LATE_MINUTES = 480;
const ATT_DONUT_RADIUS     = 42;
const ATT_STATUSES         = [
    'P' => 'Present',
    'A' => 'Absent',
    'L' => 'Late',
    'E' => 'Excused',
];

/** Today's date (Y-m-d) in the unit's timezone. */
function att_today(): string {
    return (new DateTime('now', new DateTimeZone(ATT_TIMEZONE)))->format('Y-m-d');
}

function att_fmt_date(string $date, string $format = 'M d, Y'): string {
    return date($format, strtotime($date));
}

function att_redirect(?int $session_id = null): void {
    redirect('leader/attendance.php' . ($session_id ? '?session=' . $session_id : ''));
}

/** "Last, First M." */
function att_full_name(array $cadet): string {
    $mi = '';
    if (!empty($cadet['middle_name'])) {
        $mi = ' ' . mb_strtoupper(mb_substr(trim($cadet['middle_name']), 0, 1)) . '.';
    }
    return $cadet['last_name'] . ', ' . $cadet['first_name'] . $mi;
}

/** Company/platoon the signed-in leader is assigned to. */
function att_fetch_assignment(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("
        SELECT ua.company_id, ua.platoon_id, c.name AS company_name, p.name AS platoon_name
        FROM user_assignments ua
        LEFT JOIN companies c ON ua.company_id = c.id
        LEFT JOIN platoons p ON ua.platoon_id = p.id
        WHERE ua.user_id = ?
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: [];
}

function att_fetch_active_term(PDO $pdo): ?array {
    $row = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1")->fetch();
    return $row ?: null;
}

/** All sessions of the term, each with this platoon's submission state. */
function att_fetch_sessions(PDO $pdo, int $term_id, int $platoon_id): array {
    $stmt = $pdo->prepare("
        SELECT ts.*,
               sub.id AS submission_id,
               sub.state AS submission_state,
               sub.remarks AS submission_remarks,
               sub.submitted_at,
               sub.battalion_approved_at,
               sub.brigade_approved_at
        FROM training_sessions ts
        LEFT JOIN attendance_submissions sub
               ON sub.session_id = ts.id AND sub.platoon_id = ?
        WHERE ts.term_id = ?
        ORDER BY ts.session_date ASC, ts.id ASC
    ");
    $stmt->execute([$platoon_id, $term_id]);
    return $stmt->fetchAll();
}

/**
 * Requested session if valid; otherwise the latest non-cancelled session on
 * or before today; otherwise the first session.
 */
function att_pick_session(array $sessions, int $requested_id, string $today): ?array {
    if (!$sessions) return null;

    if ($requested_id > 0) {
        foreach ($sessions as $s) {
            if ((int)$s['id'] === $requested_id) return $s;
        }
    }
    for ($i = count($sessions) - 1; $i >= 0; $i--) {
        if ($sessions[$i]['session_date'] <= $today && $sessions[$i]['status'] !== 'cancelled') {
            return $sessions[$i];
        }
    }
    return $sessions[0];
}

/** Booleans that decide whether the sheet can be edited. */
function att_session_flags(?array $session, string $today): array {
    $flags = ['cancelled' => false, 'future' => false, 'approved' => false, 'editable' => false];
    if (!$session) return $flags;

    $flags['cancelled'] = ($session['status'] === 'cancelled');
    $flags['future']    = ($session['session_date'] > $today);
    $flags['approved']  = (($session['submission_state'] ?? null) === 'approved');
    $flags['editable']  = !$flags['cancelled'] && !$flags['future'] && !$flags['approved'];
    return $flags;
}

/** [state key, badge css class, badge label] for a session. */
function att_session_badge(array $s): array {
    if ($s['status'] === 'cancelled') return ['cancelled', 'badge-cancelled', 'Cancelled'];

    switch ($s['submission_state'] ?? null) {
        case 'draft':     return ['draft',     'badge-draft',     'Draft'];
        case 'submitted': return ['submitted', 'badge-submitted', 'Submitted'];
        case 'returned':  return ['returned',  'badge-returned',  'Returned'];
        case 'approved':  return ['approved',  'badge-approved',  'Approved'];
    }
    return ['none', 'badge-deactivated', 'Not started'];
}

/** Active cadets enrolled in the platoon for the term. */
function att_fetch_cadets(PDO $pdo, int $term_id, int $platoon_id): array {
    $stmt = $pdo->prepare("
        SELECT c.id, c.cadet_code, c.last_name, c.first_name, c.middle_name,
               c.gender, pr.code AS program_code
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
        LEFT JOIN programs pr ON c.program_id = pr.id
        WHERE e.platoon_id = ? AND c.status = 'active'
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
    ");
    $stmt->execute([$term_id, $platoon_id]);
    return $stmt->fetchAll();
}

/** Saved attendance rows for a session, keyed by cadet id. */
function att_load_records(PDO $pdo, int $session_id): array {
    $stmt = $pdo->prepare("
        SELECT cadet_id, status, minutes_late, excuse_reason
        FROM attendance_records
        WHERE session_id = ?
    ");
    $stmt->execute([$session_id]);

    $by_cadet = [];
    foreach ($stmt->fetchAll() as $r) {
        $by_cadet[(int)$r['cadet_id']] = $r;
    }
    return $by_cadet;
}

/**
 * Validate the posted sheet. Only cadets of this platoon are read, so
 * injected cadet ids are ignored.
 * @return array{0: array, 1: array} [records by cadet id, errors by cadet id]
 */
function att_parse_posted(array $cadets, array $raw_records, string $action): array {
    $records = [];
    $errors  = [];

    foreach ($cadets as $cadet) {
        $cid   = (int)$cadet['id'];
        $input = $raw_records[$cid] ?? [];

        $status = trim($input['status'] ?? '');
        if ($action === 'mark_all_present' && $status === '') {
            $status = 'P';
        }
        if (!isset(ATT_STATUSES[$status])) {
            $status = '';
        }

        $minutes_late  = null;
        $excuse_reason = null;

        if ($status === 'L') {
            $raw_min = trim((string)($input['minutes_late'] ?? ''));
            if ($raw_min !== '') {
                if (!ctype_digit($raw_min)) {
                    $errors[$cid] = 'Minutes late must be a non-negative whole number.';
                } elseif ((int)$raw_min > ATT_MAX_LATE_MINUTES) {
                    $errors[$cid] = 'Minutes late cannot be more than ' . ATT_MAX_LATE_MINUTES . '.';
                } else {
                    $minutes_late = (int)$raw_min;
                }
            }
        } elseif ($status === 'E') {
            $excuse_reason = trim($input['excuse_reason'] ?? '');
            if ($excuse_reason === '') {
                $errors[$cid] = 'Excuse reason is required when marking Excused.';
            }
        }

        $records[$cid] = [
            'status'        => $status,
            'minutes_late'  => $minutes_late,
            'excuse_reason' => $excuse_reason,
        ];
    }

    return [$records, $errors];
}

/** Upsert/delete the records and make sure a draft submission row exists. */
function att_save_records(PDO $pdo, array $records, int $session_id, int $platoon_id, int $user_id): void {
    $pdo->beginTransaction();

    try {
        $upsert = $pdo->prepare("
            INSERT INTO attendance_records (
                cadet_id, session_id, status, minutes_late, excuse_reason, marked_by, updated_at
            ) VALUES (
                :cadet_id, :session_id, :status, :minutes_late, :excuse_reason, :marked_by, NOW()
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                minutes_late = VALUES(minutes_late),
                excuse_reason = VALUES(excuse_reason),
                marked_by = VALUES(marked_by),
                updated_at = NOW()
        ");
        $delete = $pdo->prepare("
            DELETE FROM attendance_records
            WHERE cadet_id = :cadet_id AND session_id = :session_id
        ");

        foreach ($records as $cid => $rec) {
            if ($rec['status'] !== '') {
                $upsert->execute([
                    'cadet_id'      => $cid,
                    'session_id'    => $session_id,
                    'status'        => $rec['status'],
                    'minutes_late'  => $rec['minutes_late'],
                    'excuse_reason' => $rec['excuse_reason'],
                    'marked_by'     => $user_id,
                ]);
            } else {
                $delete->execute(['cadet_id' => $cid, 'session_id' => $session_id]);
            }
        }

        $find = $pdo->prepare("SELECT id FROM attendance_submissions WHERE platoon_id = ? AND session_id = ?");
        $find->execute([$platoon_id, $session_id]);
        $existing = $find->fetch();

        if (!$existing) {
            $pdo->prepare("
                INSERT INTO attendance_submissions (platoon_id, session_id, state, created_at, updated_at)
                VALUES (:platoon_id, :session_id, 'draft', NOW(), NOW())
            ")->execute(['platoon_id' => $platoon_id, 'session_id' => $session_id]);
        } else {
            $pdo->prepare("UPDATE attendance_submissions SET updated_at = NOW() WHERE id = ?")
                ->execute([(int)$existing['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Totals for the counter and the donut. */
function att_counts(array $cadets, array $records): array {
    $counts = ['total' => count($cadets), 'marked' => 0, 'unmarked' => 0, 'P' => 0, 'A' => 0, 'L' => 0, 'E' => 0];

    foreach ($cadets as $cadet) {
        $st = $records[(int)$cadet['id']]['status'] ?? '';
        if (isset(ATT_STATUSES[$st])) {
            $counts['marked']++;
            $counts[$st]++;
        } else {
            $counts['unmarked']++;
        }
    }
    $counts['percent'] = $counts['total'] ? (int)round($counts['marked'] / $counts['total'] * 100) : 0;
    return $counts;
}

/** stroke-dasharray / offset for each donut segment (JS keeps them live). */
function att_donut_segments(array $counts): array {
    $circ = 2 * M_PI * ATT_DONUT_RADIUS;
    $acc  = 0.0;
    $out  = [];

    foreach (array_keys(ATT_STATUSES) as $code) {
        $len = $counts['total'] ? $counts[$code] / $counts['total'] * $circ : 0;
        $out[$code] = [
            'dasharray'  => round($len, 2) . ' ' . round($circ - $len, 2),
            'dashoffset' => round(-$acc, 2),
        ];
        $acc += $len;
    }
    return $out;
}

// ---------------------------------------------------------------
// 3. Request flow
// ---------------------------------------------------------------
$user  = current_user();
$today = att_today();

// 3a. Load context
$assignment   = att_fetch_assignment($pdo, (int)$user['id']);
$company_id   = $assignment['company_id'] ?? null;
$platoon_id   = $assignment['platoon_id'] ?? null;
$company_name = $assignment['company_name'] ?? 'Unassigned';
$platoon_name = $assignment['platoon_name'] ?? 'Unassigned';

$active_term = att_fetch_active_term($pdo);
$can_load    = ($active_term && $company_id && $platoon_id);

$sessions = $can_load ? att_fetch_sessions($pdo, (int)$active_term['id'], (int)$platoon_id) : [];
$cadets   = $can_load ? att_fetch_cadets($pdo, (int)$active_term['id'], (int)$platoon_id) : [];

$selected_session = att_pick_session($sessions, (int)($_GET['session'] ?? 0), $today);
$flags            = att_session_flags($selected_session, $today);
$selected_id      = $selected_session ? (int)$selected_session['id'] : null;

// Which screen to show
if (!$active_term)                    $view_mode = 'no_term';
elseif (!$platoon_id || !$company_id) $view_mode = 'unassigned';
elseif (!$sessions)                   $view_mode = 'no_sessions';
else                                  $view_mode = 'sheet';

// 3b. Handle POST (manual Save Draft / Mark All Present)
$form_errors    = [];
$posted_records = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        att_redirect($selected_id);
    }
    if (!$selected_session) {
        set_flash('error', 'No session selected.');
        att_redirect();
    }
    if ((int)($_POST['session_id'] ?? 0) !== $selected_id) {
        set_flash('error', 'Session mismatch.');
        att_redirect();
    }
    if ($flags['cancelled']) {
        set_flash('error', 'Cannot record attendance for a cancelled session.');
        att_redirect($selected_id);
    }
    if ($flags['future']) {
        set_flash('error', 'Cannot record attendance for a future session.');
        att_redirect($selected_id);
    }
    if ($flags['approved']) {
        set_flash('error', 'This session has already been approved and is locked.');
        att_redirect($selected_id);
    }

    $action = $_POST['form_action'] ?? 'save_draft';
    [$posted_records, $form_errors] = att_parse_posted($cadets, $_POST['records'] ?? [], $action);

    if (!$form_errors) {
        try {
            att_save_records($pdo, $posted_records, $selected_id, (int)$platoon_id, (int)$user['id']);
            set_flash('success', $action === 'mark_all_present'
                ? 'Unmarked cadets were marked Present and draft saved.'
                : 'Attendance draft saved successfully.');
            att_redirect($selected_id);
        } catch (Throwable $e) {
            error_log('attendance save failed: ' . $e->getMessage());
            set_flash('error', 'Failed to save attendance. Please try again.');
        }
    } else {
        set_flash('error', 'Please fix the errors in the attendance sheet.');
    }
}

// 3c. Build the view data
$attendance_by_cadet = $selected_session ? att_load_records($pdo, $selected_id) : [];

// If validation failed, show what the user typed instead of what is saved
foreach ($posted_records as $cid => $rec) {
    $attendance_by_cadet[$cid] = $rec;
}

$counts   = att_counts($cadets, $attendance_by_cadet);
$donut    = att_donut_segments($counts);
$editable = $flags['editable'];

$page_title = 'Take Attendance';
require_once __DIR__ . '/../includes/header.php';

// ---------------------------------------------------------------
// 4. View
// ---------------------------------------------------------------
$icons = [
    'alert'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'lock'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'x'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    'cal'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'users'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
    'flag'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
    'chart'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>',
    'caret'  => '<svg class="att-day-caret" viewBox="0 0 12 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 1l5 5 5-5"/></svg>',
];

/** Print a notice box. $body is trusted HTML (callers escape their data). */
function att_notice(string $variant, string $icon_svg, string $title, string $body): void { ?>
    <div class="att-notice att-notice--<?= e($variant) ?>" role="status">
        <?= $icon_svg ?>
        <div>
            <strong class="att-notice-title"><?= e($title) ?></strong>
            <p><?= $body ?></p>
        </div>
    </div>
<?php }
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/attendance.css">

<div class="att-page">

    <!-- Page header -->
    <div class="att-head">
        <div>
            <h1>Take Attendance</h1>
            <div class="att-meta">
                <span class="att-chip"><?= $icons['flag'] ?> Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?></span>
                <?php if ($active_term): ?>
                    <span class="att-chip"><?= $icons['cal'] ?> Term: <?= e($active_term['name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm">View Platoon Cadets</a>
    </div>

<?php if ($view_mode === 'no_term'): ?>

    <?php att_notice('warning', $icons['alert'], 'No Active Academic Term',
        'There is currently no active academic term configured in the system. Attendance sessions cannot be loaded.'); ?>

<?php elseif ($view_mode === 'unassigned'): ?>

    <?php att_notice('warning', $icons['alert'], 'Unassigned Officer',
        'Your account is not assigned to a company and platoon. Please contact your administrator.'); ?>

<?php elseif ($view_mode === 'no_sessions'): ?>

    <div class="att-sheet">
        <div class="att-empty">
            <?= $icons['cal'] ?>
            <h3>No Training Sessions Found</h3>
            <p>There are no training sessions configured for <?= e($active_term['name']) ?> yet. Please contact your administrator.</p>
        </div>
    </div>

<?php elseif ($selected_session): ?>

    <?php [$sel_state, $sel_badge_class, $sel_badge_label] = att_session_badge($selected_session); ?>

    <!-- Session state notice -->
    <?php if ($flags['cancelled']): ?>
        <?php att_notice('danger', $icons['x'], 'Session Cancelled',
            'This training session was marked as cancelled. Attendance marking is disabled.'); ?>
    <?php elseif ($flags['future']): ?>
        <?php att_notice('info', $icons['lock'], 'Future Training Session (Locked)',
            'This session is scheduled for <strong>' . e(att_fmt_date($selected_session['session_date'], 'F j, Y')) . '</strong>. Attendance can only be taken on or after the scheduled date.'); ?>
    <?php elseif ($flags['approved']): ?>
        <?php att_notice('success', $icons['lock'], 'Attendance Approved &amp; Locked',
            'This session\'s attendance sheet has received final approval from S1 leadership and is locked in read-only mode.'); ?>
    <?php elseif (($selected_session['submission_state'] ?? null) === 'returned'): ?>
        <?php
            $remarks_html = '';
            if (!empty($selected_session['submission_remarks'])) {
                $remarks_html = '<span class="att-remarks"><strong>S1 Remarks:</strong> '
                              . nl2br(e($selected_session['submission_remarks'])) . '</span>';
            }
            att_notice('returned', $icons['alert'], 'Attendance Returned by S1',
                'This attendance submission was returned for correction. Please review the remarks, update the sheet, and resubmit.' . $remarks_html);
        ?>
    <?php endif; ?>

    <section class="att-sheet" id="att-sheet"
             data-session-id="<?= $selected_id ?>"
             data-save-url="<?= e(BASE_URL . '/api/attendance_save.php') ?>"
             data-editable="<?= $editable ? '1' : '0' ?>"
             data-total="<?= (int)$counts['total'] ?>">

        <!-- Row 1: training-day button (left) + counter / summary / save status (right) -->
        <div class="att-sheet-head">

            <div class="att-daypicker">
                <button type="button" class="att-day-btn" id="att-day-btn"
                        aria-haspopup="true" aria-expanded="false" aria-controls="att-day-menu">
                    <span class="att-day-icon"><?= $icons['cal'] ?></span>
                    <span class="att-day-text">
                        <span class="att-day-kicker">Training day</span>
                        <span class="att-day-value"><?= e($selected_session['label']) ?> &middot; <?= e(att_fmt_date($selected_session['session_date'])) ?></span>
                    </span>
                    <span class="badge <?= $sel_badge_class ?>"><?= e($sel_badge_label) ?></span>
                    <?= $icons['caret'] ?>
                </button>

                <ul class="att-day-menu" id="att-day-menu" hidden aria-label="Choose a training day">
                    <?php foreach ($sessions as $s): ?>
                        <?php
                            [$state, $badge_class, $badge_label] = att_session_badge($s);
                            $is_active = ((int)$s['id'] === $selected_id);
                            $is_future = ($s['session_date'] > $today);
                            $opt_class = 'att-day-opt' . ($is_active ? ' is-active' : '') . ($state === 'cancelled' ? ' is-disabled' : '');
                        ?>
                        <li>
                            <a class="<?= $opt_class ?>"
                               href="<?= BASE_URL ?>/leader/attendance.php?session=<?= (int)$s['id'] ?>"
                               <?= $is_active ? 'aria-current="page"' : '' ?>
                               <?= $state === 'cancelled' ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                                <span class="att-day-opt-main">
                                    <span class="att-day-opt-label"><?= e($s['label']) ?></span>
                                    <span class="att-day-opt-date"><?= e(att_fmt_date($s['session_date'], 'D, M d, Y')) ?><?= $is_future ? ' (upcoming)' : '' ?></span>
                                </span>
                                <span class="badge <?= $badge_class ?>"><?= e($badge_label) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($cadets): ?>
                <div class="att-head-right">
                    <div class="att-count <?= ($counts['total'] > 0 && $counts['marked'] === $counts['total']) ? 'is-complete' : '' ?>" id="att-count">
                        <span class="att-count-text"><strong id="cnt-marked"><?= (int)$counts['marked'] ?></strong>/<?= (int)$counts['total'] ?> marked</span>
                        <span class="att-count-bar"><span id="att-count-bar" style="width: <?= (int)$counts['percent'] ?>%;"></span></span>
                    </div>

                    <button type="button" class="att-summary-toggle" id="att-summary-toggle" aria-expanded="false" aria-controls="att-summary">
                        <?= $icons['chart'] ?> <span class="att-summary-label">Show summary</span>
                    </button>

                    <?php if ($editable): ?>
                        <div class="save-indicator" id="save-indicator" aria-live="polite">
                            <span class="save-indicator-dot"></span>
                            <span id="save-status-text">Draft ready</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$cadets): ?>

            <div class="att-empty">
                <?= $icons['users'] ?>
                <h3>No Active Cadets in Platoon</h3>
                <p>There are no active cadets enrolled in your platoon for this active term.</p>
                <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary btn-sm">+ Enroll Cadets</a>
            </div>

        <?php else: ?>

            <!-- Donut summary (hidden until "Show summary" is pressed) -->
            <div class="att-summary" id="att-summary" hidden>
                <div class="att-donut" role="img" aria-label="Attendance status breakdown">
                    <svg viewBox="0 0 100 100">
                        <circle class="seg-track" cx="50" cy="50" r="<?= ATT_DONUT_RADIUS ?>"/>
                        <?php foreach ($donut as $code => $seg): ?>
                            <circle class="seg-<?= strtolower($code) ?>" cx="50" cy="50" r="<?= ATT_DONUT_RADIUS ?>"
                                    stroke-dasharray="<?= $seg['dasharray'] ?>" stroke-dashoffset="<?= $seg['dashoffset'] ?>"/>
                        <?php endforeach; ?>
                    </svg>
                    <div class="att-donut-center">
                        <strong id="donut-pct"><?= (int)$counts['percent'] ?>%</strong>
                        <span>marked</span>
                    </div>
                </div>
                <ul class="att-legend-list">
                    <li><i class="lg-p"></i> Present   <b id="cnt-p"><?= (int)$counts['P'] ?></b></li>
                    <li><i class="lg-a"></i> Absent    <b id="cnt-a"><?= (int)$counts['A'] ?></b></li>
                    <li><i class="lg-l"></i> Late      <b id="cnt-l"><?= (int)$counts['L'] ?></b></li>
                    <li><i class="lg-e"></i> Excused   <b id="cnt-e"><?= (int)$counts['E'] ?></b></li>
                    <li><i class="lg-u"></i> Unmarked  <b id="cnt-unmarked"><?= (int)$counts['unmarked'] ?></b></li>
                </ul>
            </div>

            <!-- Row 2: search / filter / mark all -->
            <div class="att-toolbar">
                <div class="att-search">
                    <?= $icons['search'] ?>
                    <input type="search" id="att-search" class="form-control" placeholder="Search cadet" autocomplete="off" aria-label="Search cadets">
                </div>
                <button type="button" class="att-toggle" id="att-filter-unmarked" aria-pressed="false">Unmarked only</button>
                <?php if ($editable): ?>
                    <button type="button" class="btn btn-secondary btn-sm att-markall" id="btn-mark-all-present">Mark all present</button>
                <?php endif; ?>
            </div>

            <form id="attendance-sheet-form" method="POST" action="<?= BASE_URL ?>/leader/attendance.php?session=<?= $selected_id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="session_id" value="<?= $selected_id ?>">

                <div class="att-table-wrap">
                    <table class="att-table" id="attendance-table">
                        <thead>
                            <tr>
                                <th class="sticky-cadet-col" style="min-width: 220px;">Cadet</th>
                                <th style="min-width: 380px;">Status<?php if ($editable): ?><small>click a button to mark</small><?php endif; ?></th>
                                <th style="min-width: 240px;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cadets as $cadet): ?>
                                <?php
                                    $cid        = (int)$cadet['id'];
                                    $rec        = $attendance_by_cadet[$cid] ?? [];
                                    $status     = $rec['status'] ?? '';
                                    $late_value = $rec['minutes_late'] ?? '';
                                    $excuse     = $rec['excuse_reason'] ?? '';
                                    $full_name  = att_full_name($cadet);
                                    $row_error  = $form_errors[$cid] ?? '';
                                    $disabled   = $editable ? '' : 'disabled';
                                    $program    = $cadet['program_code'] ?? '';
                                ?>
                                <tr class="att-row <?= $row_error ? 'has-row-error' : '' ?>"
                                    data-cadet-id="<?= $cid ?>"
                                    data-status="<?= e($status) ?>"
                                    data-name="<?= e(mb_strtolower($full_name . ' ' . $cadet['cadet_code'])) ?>">

                                    <td class="sticky-cadet-col att-cell-name">
                                        <div class="att-name"><?= e($full_name) ?></div>
                                        <div class="cadet-details-subtext">
                                            <span class="att-code"><?= e($cadet['cadet_code']) ?></span>
                                            &bull; <?= e($cadet['gender']) ?>
                                            <?php if ($program !== ''): ?>&bull; <?= e($program) ?><?php endif; ?>
                                        </div>
                                        <div class="att-row-error" role="alert" <?= $row_error ? '' : 'hidden' ?>><?= e($row_error) ?></div>
                                    </td>

                                    <td>
                                        <div class="att-radio-group" role="radiogroup" aria-label="Attendance for <?= e($full_name) ?>">
                                            <?php foreach (ATT_STATUSES as $code => $label): ?>
                                                <label class="att-radio-label opt-<?= strtolower($code) ?>">
                                                    <input type="radio"
                                                           name="records[<?= $cid ?>][status]"
                                                           value="<?= $code ?>"
                                                           <?= $status === $code ? 'checked' : '' ?>
                                                           <?= $disabled ?>>
                                                    <span class="att-btn"><?= e($label) ?></span>
                                                </label>
                                            <?php endforeach; ?>

                                            <?php if ($editable): ?>
                                                <button type="button" class="att-clear-btn btn-unmark" aria-label="Clear selection for <?= e($full_name) ?>">Clear</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td class="att-cell-details">
                                        <div class="att-details">
                                            <!-- Shown only when status = Late -->
                                            <div class="att-late">
                                                <label class="att-mini-label" for="late-<?= $cid ?>">Minutes late</label>
                                                <div class="att-late-row">
                                                    <input type="number" id="late-<?= $cid ?>"
                                                           name="records[<?= $cid ?>][minutes_late]"
                                                           class="form-control input-late"
                                                           min="0" max="<?= ATT_MAX_LATE_MINUTES ?>" inputmode="numeric" placeholder="0"
                                                           value="<?= e((string)$late_value) ?>" <?= $disabled ?>>
                                                    <?php if ($editable): ?>
                                                        <div class="att-quick" aria-label="Quick minutes">
                                                            <?php foreach ([5, 10, 15, 30] as $m): ?>
                                                                <button type="button" data-minutes="<?= $m ?>"><?= $m ?></button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Shown only when status = Excused -->
                                            <div class="att-excuse">
                                                <label class="att-mini-label" for="excuse-<?= $cid ?>">Excuse reason (required)</label>
                                                <input type="text" id="excuse-<?= $cid ?>"
                                                       name="records[<?= $cid ?>][excuse_reason]"
                                                       class="form-control input-excuse <?= ($row_error && $status === 'E') ? 'is-invalid' : '' ?>"
                                                       placeholder="e.g. Medical appointment"
                                                       value="<?= e($excuse) ?>" <?= $disabled ?>>
                                            </div>

                                            <span class="att-dash att-muted">&mdash;</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr id="att-no-results" hidden>
                                <td colspan="3" class="att-empty-search">No cadets match your search.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php if ($editable): ?>
                    <div class="att-sheet-foot">
                        <span>Changes save automatically.</span>
                        <button type="submit" name="form_action" value="save_draft" class="btn btn-primary">Save draft</button>
                    </div>
                <?php endif; ?>
            </form>

        <?php endif; ?>
    </section>

<?php endif; ?>

</div>

<script src="<?= BASE_URL ?>/assets/js/attendance.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>