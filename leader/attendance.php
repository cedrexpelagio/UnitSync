<?php
// Platoon Leader: Take Attendance (Stage PL-5)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
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

// Current date in Asia/Manila (+08:00)
$today_manila = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

// 3. Fetch all training sessions for the active term
$sessions = [];
if ($active_term && $platoon_id) {
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
    $stmt->execute([(int)$platoon_id, (int)$active_term['id']]);
    $sessions = $stmt->fetchAll();
}

// 4. Determine selected session
$requested_session_id = isset($_GET['session']) ? (int)$_GET['session'] : 0;
$selected_session = null;

if ($requested_session_id > 0) {
    foreach ($sessions as $s) {
        if ((int)$s['id'] === $requested_session_id) {
            $selected_session = $s;
            break;
        }
    }
}

// If no session was selected or requested session was invalid, pick the latest available current/past session
if (!$selected_session && !empty($sessions)) {
    // Pick the most recent non-cancelled session that is on or before today
    for ($i = count($sessions) - 1; $i >= 0; $i--) {
        if ($sessions[$i]['session_date'] <= $today_manila && $sessions[$i]['status'] !== 'cancelled') {
            $selected_session = $sessions[$i];
            break;
        }
    }
    // If all are future or cancelled, fallback to the first one
    if (!$selected_session) {
        $selected_session = $sessions[0];
    }
}

// Session state variables
$is_cancelled = false;
$is_future    = false;
$is_approved  = false;
$is_editable  = false;

if ($selected_session) {
    $is_cancelled = ($selected_session['status'] === 'cancelled');
    $is_future    = ($selected_session['session_date'] > $today_manila);
    $is_approved  = ($selected_session['submission_state'] === 'approved');
    $is_editable  = (!$is_cancelled && !$is_future && !$is_approved);
}

// 5. Fetch active cadets enrolled in this leader's platoon
$cadets = [];
$active_cadet_ids = [];
if ($active_term && $platoon_id) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.cadet_code, c.last_name, c.first_name, c.middle_name,
               c.gender, pr.code AS program_code
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
        LEFT JOIN programs pr ON c.program_id = pr.id
        WHERE e.platoon_id = ? AND c.status = 'active'
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
    ");
    $stmt->execute([(int)$active_term['id'], (int)$platoon_id]);
    $cadets = $stmt->fetchAll();

    foreach ($cadets as $c) {
        $active_cadet_ids[(int)$c['id']] = true;
    }
}

// 6. Handle Form POST (Save Draft / Mark All Present)
$form_errors = [];
$posted_records = [];
$unmarked_cadets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('leader/attendance.php' . ($selected_session ? '?session=' . (int)$selected_session['id'] : ''));
    }

    if (!$selected_session) {
        set_flash('error', 'No session selected.');
        redirect('leader/attendance.php');
    }

    $session_id = (int)($_POST['session_id'] ?? 0);
    if ($session_id !== (int)$selected_session['id']) {
        set_flash('error', 'Session mismatch.');
        redirect('leader/attendance.php');
    }

    if ($is_cancelled) {
        set_flash('error', 'Cannot record attendance for a cancelled session.');
        redirect('leader/attendance.php?session=' . $session_id);
    }

    if ($is_future) {
        set_flash('error', 'Cannot record attendance for a future session.');
        redirect('leader/attendance.php?session=' . $session_id);
    }

    if ($is_approved) {
        set_flash('error', 'This session has already been approved and is locked.');
        redirect('leader/attendance.php?session=' . $session_id);
    }

    $action = $_POST['form_action'] ?? 'save_draft';
    $raw_records = $_POST['records'] ?? [];

    // Parse and validate submitted records
    foreach ($cadets as $cadet) {
        $cid = (int)$cadet['id'];
        $cadet_input = $raw_records[$cid] ?? [];

        // If action is mark_all_present, set to P if unmarked
        $status = trim($cadet_input['status'] ?? '');
        if ($action === 'mark_all_present' && $status === '') {
            $status = 'P';
        }

        if (!in_array($status, ['P', 'A', 'L', 'E'], true)) {
            $status = '';
            $mi = !empty($cadet['middle_name']) ? ' ' . mb_strtoupper(mb_substr(trim($cadet['middle_name']), 0, 1)) . '.' : '';
            $unmarked_cadets[] = $cadet['last_name'] . ', ' . $cadet['first_name'] . $mi;
        }

        $minutes_late = null;
        $excuse_reason = null;

        if ($status === 'L') {
            $raw_min = trim((string)($cadet_input['minutes_late'] ?? ''));
            if ($raw_min !== '') {
                if (!ctype_digit($raw_min) || (int)$raw_min < 0 || (int)$raw_min > 480) {
                    $form_errors[$cid] = 'Minutes late must be a whole number between 0 and 480.';
                } else {
                    $minutes_late = (int)$raw_min;
                }
            }
        } elseif ($status === 'E') {
            $excuse_reason = trim($cadet_input['excuse_reason'] ?? '');
            if ($excuse_reason === '') {
                $form_errors[$cid] = 'Excuse reason is required when marking Excused.';
            }
        }

        $posted_records[$cid] = [
            'status'        => $status,
            'minutes_late'  => $minutes_late,
            'excuse_reason' => $excuse_reason,
        ];
    }

    // Submit Validation: Every active cadet must be marked
    if ($action === 'submit' && !empty($unmarked_cadets)) {
        $count_unm = count($unmarked_cadets);
        $names_preview = implode('; ', array_slice($unmarked_cadets, 0, 4)) . ($count_unm > 4 ? '; and ' . ($count_unm - 4) . ' more' : '');
        $form_errors['general'] = "Cannot submit: {$count_unm} cadet(s) are still unmarked ({$names_preview}). Every active cadet must be marked before submitting.";
    }

    if (empty($form_errors)) {
        try {
            $pdo->beginTransaction();

            // 1. Ensure submission row exists first (draft state if new)
            $stmt_sub = $pdo->prepare("
                SELECT id, state FROM attendance_submissions 
                WHERE platoon_id = ? AND session_id = ?
            ");
            $stmt_sub->execute([(int)$platoon_id, $session_id]);
            $existing_sub = $stmt_sub->fetch();

            if (!$existing_sub) {
                $stmt_ins_sub = $pdo->prepare("
                    INSERT INTO attendance_submissions (
                        platoon_id, session_id, state, created_at, updated_at
                    ) VALUES (
                        :platoon_id, :session_id, 'draft', NOW(), NOW()
                    )
                ");
                $stmt_ins_sub->execute([
                    'platoon_id' => (int)$platoon_id,
                    'session_id' => $session_id
                ]);
                $sub_id = (int)$pdo->lastInsertId();
                $existing_sub = ['id' => $sub_id, 'state' => 'draft'];
            } else {
                $sub_id = (int)$existing_sub['id'];
            }

            // 2. Save each record linked with submission_id
            $stmt_upsert = $pdo->prepare("
                INSERT INTO attendance_records (
                    cadet_id, session_id, submission_id, status, minutes_late, excuse_reason, marked_by, updated_at
                ) VALUES (
                    :cadet_id, :session_id, :submission_id, :status, :minutes_late, :excuse_reason, :marked_by, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    submission_id = VALUES(submission_id),
                    status = VALUES(status),
                    minutes_late = VALUES(minutes_late),
                    excuse_reason = VALUES(excuse_reason),
                    marked_by = VALUES(marked_by),
                    updated_at = NOW()
            ");

            $stmt_delete = $pdo->prepare("
                DELETE FROM attendance_records 
                WHERE cadet_id = :cadet_id AND session_id = :session_id
            ");

            foreach ($posted_records as $cid => $rec) {
                if ($rec['status'] !== '') {
                    $stmt_upsert->execute([
                        'cadet_id'      => $cid,
                        'session_id'    => $session_id,
                        'submission_id' => $sub_id,
                        'status'        => $rec['status'],
                        'minutes_late'  => $rec['minutes_late'],
                        'excuse_reason' => $rec['excuse_reason'],
                        'marked_by'     => $user['id']
                    ]);
                } else {
                    $stmt_delete->execute([
                        'cadet_id'   => $cid,
                        'session_id' => $session_id
                    ]);
                }
            }

            if ($action === 'submit') {
                // Submit Attendance
                $stmt_upd_sub = $pdo->prepare("
                    UPDATE attendance_submissions 
                    SET state                 = 'submitted',
                        submitted_by          = :submitted_by,
                        submitted_at          = NOW(),
                        battalion_approved_by = NULL,
                        battalion_approved_at = NULL,
                        brigade_approved_by   = NULL,
                        brigade_approved_at   = NULL,
                        remarks               = NULL,
                        updated_at            = NOW() 
                    WHERE id = :id
                ");
                $stmt_upd_sub->execute([
                    'submitted_by' => $user['id'],
                    'id'           => $sub_id
                ]);

                // Write audit log for submission
                log_audit(
                    $pdo,
                    $user['id'],
                    'submit_attendance',
                    'attendance_submission',
                    $sub_id,
                    json_encode([
                        'session_id'    => $session_id,
                        'platoon_id'    => (int)$platoon_id,
                        'session_label' => $selected_session['label'],
                        'total_cadets'  => count($cadets),
                        'submitted_at'  => date('Y-m-d H:i:s')
                    ])
                );

                $pdo->commit();
                set_flash('success', 'Attendance for ' . $selected_session['label'] . ' was successfully submitted to Battalion S1 and Brigade S1.');
                redirect('leader/attendance.php?session=' . $session_id);
            } else {
                // Draft save or Mark all present
                $cleared_approvals = false;

                if ($existing_sub['state'] === 'submitted') {
                    // Editing a submitted session resets approvals
                    $stmt_update_sub = $pdo->prepare("
                        UPDATE attendance_submissions 
                        SET battalion_approved_by = NULL,
                            battalion_approved_at = NULL,
                            brigade_approved_by   = NULL,
                            brigade_approved_at   = NULL,
                            updated_at            = NOW() 
                        WHERE id = ?
                    ");
                    $stmt_update_sub->execute([$sub_id]);
                    $cleared_approvals = true;

                    log_audit(
                        $pdo,
                        $user['id'],
                        'edit_submitted_attendance',
                        'attendance_submission',
                        (int)$existing_sub['id'],
                        json_encode([
                            'session_id'        => $session_id,
                            'platoon_id'        => (int)$platoon_id,
                            'cleared_approvals' => true,
                            'updated_at'        => date('Y-m-d H:i:s')
                        ])
                    );
                } else {
                    $stmt_update_sub = $pdo->prepare("
                        UPDATE attendance_submissions 
                        SET updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt_update_sub->execute([(int)$existing_sub['id']]);
                }

                $pdo->commit();

                if ($action === 'mark_all_present') {
                    set_flash('success', 'Unmarked cadets were marked Present and saved.');
                } elseif ($cleared_approvals) {
                    set_flash('success', 'Attendance saved. Notice: Any S1 approvals given for this session have been reset because changes were made.');
                } else {
                    set_flash('success', 'Attendance draft saved successfully.');
                }

                redirect('leader/attendance.php?session=' . $session_id);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Failed to save attendance: ' . $e->getMessage());
        }
    } else {
        if (isset($form_errors['general'])) {
            set_flash('error', $form_errors['general']);
        } else {
            set_flash('error', 'Please fix the errors in the attendance sheet.');
        }
    }
}

// 7. Load existing attendance records for the selected session
$attendance_by_cadet = [];
if ($selected_session) {
    $stmt = $pdo->prepare("
        SELECT cadet_id, status, minutes_late, excuse_reason
        FROM attendance_records
        WHERE session_id = ?
    ");
    $stmt->execute([(int)$selected_session['id']]);
    $records_db = $stmt->fetchAll();

    foreach ($records_db as $r) {
        $attendance_by_cadet[(int)$r['cadet_id']] = $r;
    }

    // Merge any posted records if there were validation errors
    if (!empty($posted_records)) {
        foreach ($posted_records as $cid => $prec) {
            $attendance_by_cadet[$cid] = $prec;
        }
    }
}

// Calculate summary counts
$count_total    = count($cadets);
$count_marked   = 0;
$count_unmarked = 0;
$count_p = 0;
$count_a = 0;
$count_l = 0;
$count_e = 0;

foreach ($cadets as $cadet) {
    $cid = (int)$cadet['id'];
    $st = $attendance_by_cadet[$cid]['status'] ?? '';
    if ($st !== '') {
        $count_marked++;
        if ($st === 'P') $count_p++;
        elseif ($st === 'A') $count_a++;
        elseif ($st === 'L') $count_l++;
        elseif ($st === 'E') $count_e++;
    } else {
        $count_unmarked++;
    }
}

$page_title = 'Take Attendance';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leader.css">

<?php
$svg = function (string $inner, string $cls = 'pl-icon'): string {
    return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$ico = [
    'users'   => $svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
    'alert'   => $svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'),
    'warn'    => $svg('<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'),
    'lock'    => $svg('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
    'check'   => $svg('<polyline points="20 6 9 17 4 12"/>'),
    'cal'     => $svg('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'),
    'left'    => $svg('<polyline points="15 18 9 12 15 6"/>'),
    'right'   => $svg('<polyline points="9 18 15 12 9 6"/>'),
    'chevron' => $svg('<polyline points="6 9 12 15 18 9"/>', 'pl-icon pl-picker-chevron'),
    'search'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    'x'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
];

// Badge (class + label) for a session row
$session_badge = function (array $s): array {
    if ($s['status'] === 'cancelled')                    return ['badge-cancelled', 'Cancelled'];
    switch ($s['submission_state'] ?? null) {
        case 'draft':     return ['badge-draft', 'Draft'];
        case 'submitted': return ['badge-submitted', 'Submitted'];
        case 'returned':  return ['badge-returned', 'Returned'];
        case 'approved':  return ['badge-approved', 'Approved'];
    }
    return ['badge-deactivated', 'Not started'];
};

$fmt_date = fn(string $d): string => date('M j, Y', strtotime($d));

// Previous / next usable session (skips cancelled)
$prev_id = 0;
$next_id = 0;
if ($selected_session) {
    $sel_idx = 0;
    foreach ($sessions as $i => $s) {
        if ((int)$s['id'] === (int)$selected_session['id']) { $sel_idx = $i; break; }
    }
    for ($i = $sel_idx - 1; $i >= 0; $i--) {
        if ($sessions[$i]['status'] !== 'cancelled') { $prev_id = (int)$sessions[$i]['id']; break; }
    }
    for ($i = $sel_idx + 1; $i < count($sessions); $i++) {
        if ($sessions[$i]['status'] !== 'cancelled') { $next_id = (int)$sessions[$i]['id']; break; }
    }
}

$page_url      = BASE_URL . '/leader/attendance.php';
$progress_pct  = $count_total > 0 ? (int)round(($count_marked / $count_total) * 100) : 0;
$sub_state     = $selected_session['submission_state'] ?? null;
?>

<div class="pl">

    <!-- Hero -->
    <div class="pl-hero">
        <div>
            <div class="pl-eyebrow">Platoon leader</div>
            <h1>Take attendance</h1>
            <p class="pl-sub">
                <strong>Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?></strong>
                <?php if ($active_term): ?>
                    &nbsp;|&nbsp; Term: <span class="pl-term"><?= e($active_term['name']) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="pl-hero-actions">
            <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary"><?= $ico['users'] ?> Platoon cadets</a>
        </div>
    </div>

<?php if (!$active_term): ?>

    <div class="pl-notice pl-notice--warn" role="alert">
        <?= $ico['alert'] ?>
        <div class="pl-notice-body">
            <span class="pl-notice-title">No active academic term</span>
            <p>Sessions can&rsquo;t load until an administrator activates a term.</p>
        </div>
    </div>

<?php elseif (!$platoon_id || !$company_id): ?>

    <div class="pl-notice pl-notice--warn" role="alert">
        <?= $ico['alert'] ?>
        <div class="pl-notice-body">
            <span class="pl-notice-title">Your account has no platoon yet</span>
            <p>You are not assigned to a company and platoon. Please contact your administrator.</p>
        </div>
    </div>

<?php elseif (empty($sessions)): ?>

    <div class="pl-empty">
        <?= $ico['cal'] ?>
        <h3>No training sessions yet</h3>
        <p>There are no sessions set up for <?= e($active_term['name']) ?>. Please contact your administrator.</p>
    </div>

<?php else: ?>

    <!-- Session picker -->
    <div class="pl-session-bar">
        <a class="pl-nav-btn" href="<?= $prev_id ? $page_url . '?session=' . $prev_id : '#' ?>"
           aria-label="Previous session" <?= $prev_id ? '' : 'aria-disabled="true" tabindex="-1"' ?>><?= $ico['left'] ?></a>

        <details class="pl-picker">
            <summary aria-label="Choose a training session">
                <span class="pl-picker-text">
                    <span class="pl-picker-date">
                        <?= e($fmt_date($selected_session['session_date'])) ?><?= $is_future ? ' (upcoming)' : '' ?>
                    </span>
                    <span class="pl-picker-label"><?= e($selected_session['label']) ?></span>
                </span>
                <?php [$sel_badge_class, $sel_badge_label] = $session_badge($selected_session); ?>
                <span class="badge <?= $sel_badge_class ?>"><?= e($sel_badge_label) ?></span>
                <?= $ico['chevron'] ?>
            </summary>

            <ul class="pl-picker-list">
                <?php foreach ($sessions as $s): ?>
                    <?php
                        [$b_class, $b_label] = $session_badge($s);
                        $item_cls = 'pl-picker-item';
                        if ((int)$s['id'] === (int)$selected_session['id']) $item_cls .= ' is-active';
                        if ($s['status'] === 'cancelled') $item_cls .= ' is-disabled';
                    ?>
                    <li>
                        <a class="<?= $item_cls ?>" href="<?= $page_url ?>?session=<?= (int)$s['id'] ?>">
                            <span class="pl-picker-text">
                                <span class="pl-picker-date">
                                    <?= e($fmt_date($s['session_date'])) ?><?= $s['session_date'] > $today_manila ? ' (upcoming)' : '' ?>
                                </span>
                                <span class="pl-picker-label"><?= e($s['label']) ?></span>
                            </span>
                            <span class="badge <?= $b_class ?>"><?= e($b_label) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>

        <a class="pl-nav-btn" href="<?= $next_id ? $page_url . '?session=' . $next_id : '#' ?>"
           aria-label="Next session" <?= $next_id ? '' : 'aria-disabled="true" tabindex="-1"' ?>><?= $ico['right'] ?></a>
    </div>

    <!-- Session state notice -->
    <?php if ($is_cancelled): ?>
        <div class="pl-notice pl-notice--danger" role="status">
            <?= $ico['alert'] ?>
            <div class="pl-notice-body">
                <span class="pl-notice-title">Session cancelled</span>
                <p>This session was cancelled, so attendance can&rsquo;t be marked.</p>
            </div>
        </div>
    <?php elseif ($is_future): ?>
        <div class="pl-notice pl-notice--info" role="status">
            <?= $ico['lock'] ?>
            <div class="pl-notice-body">
                <span class="pl-notice-title">Opens on <?= e(date('F j, Y', strtotime($selected_session['session_date']))) ?></span>
                <p>Attendance can only be taken on or after the session date.</p>
            </div>
        </div>
    <?php elseif ($is_approved): ?>
        <div class="pl-notice pl-notice--ok" role="status">
            <?= $ico['lock'] ?>
            <div class="pl-notice-body">
                <span class="pl-notice-title">Approved and locked</span>
                <p>S1 approved this sheet. It is now read-only.</p>
            </div>
        </div>
    <?php elseif ($sub_state === 'submitted'): ?>
        <div class="pl-notice pl-notice--warn" role="status">
            <?= $ico['warn'] ?>
            <div class="pl-notice-body">
                <span class="pl-notice-title">Submitted and under S1 review</span>
                <p>You can still edit, but <strong>saving a change clears any S1 approval already given</strong> and the sheet needs review again.</p>
            </div>
        </div>
        <?php if ($count_unmarked > 0): ?>
            <div class="pl-notice pl-notice--danger" role="alert">
                <?= $ico['warn'] ?>
                <div class="pl-notice-body">
                    <span class="pl-notice-title"><?= (int)$count_unmarked ?> cadet(s) not marked</span>
                    <p>These may be recent additions to the platoon. Mark them, then submit again.</p>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($sub_state === 'returned'): ?>
        <div class="pl-notice pl-notice--warn" role="alert">
            <?= $ico['warn'] ?>
            <div class="pl-notice-body">
                <span class="pl-notice-title">Returned by S1 for correction</span>
                <p>Update the sheet, then submit it again.</p>
                <?php if (!empty($selected_session['submission_remarks'])): ?>
                    <div class="pl-remarks"><strong>S1 remarks:</strong> <?= nl2br(e($selected_session['submission_remarks'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($cadets)): ?>

        <div class="pl-empty">
            <?= $ico['users'] ?>
            <h3>No active cadets in your platoon</h3>
            <p>There is nobody to mark yet. Enroll cadets and they will show up here.</p>
            <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary btn-sm">Enroll cadets</a>
        </div>

    <?php else: ?>

        <!-- Attendance sheet -->
        <form id="attendance-sheet-form"
              class="pl-card pl-sheet-card"
              method="POST"
              action="<?= $page_url ?>?session=<?= (int)$selected_session['id'] ?>"
              data-pl-sheet
              data-editable="<?= $is_editable ? '1' : '0' ?>"
              data-submitted="<?= $sub_state === 'submitted' ? '1' : '0' ?>"
              data-session-id="<?= (int)$selected_session['id'] ?>"
              data-label="<?= e($selected_session['label']) ?>"
              data-save-url="<?= BASE_URL ?>/api/attendance_save.php">
            <?= csrf_field() ?>
            <input type="hidden" name="session_id" value="<?= (int)$selected_session['id'] ?>">

            <!-- Title + progress -->
            <div class="pl-sheet-top">
                <div class="pl-sheet-title">
                    <h2><?= e($selected_session['label']) ?> &mdash; <?= e(date('F j, Y', strtotime($selected_session['session_date']))) ?></h2>
                </div>
                <div class="pl-progress-wrap">
                    <div class="pl-progress <?= $count_unmarked === 0 ? 'is-done' : '' ?>"
                         role="progressbar" aria-label="Cadets marked"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress_pct ?>"
                         data-progress style="--pl-pct: <?= $progress_pct ?>%;"><span></span></div>
                    <span class="pl-progress-text"><span data-count="marked"><?= (int)$count_marked ?></span> of <?= (int)$count_total ?> marked</span>
                </div>
            </div>

            <!-- Filters + search -->
            <div class="pl-toolbar">
                <div class="pl-filters" role="group" aria-label="Filter cadets">
                    <button type="button" class="pl-filter" data-filter="all" aria-pressed="true">All <span class="pl-filter-count" data-count="all"><?= (int)$count_total ?></span></button>
                    <button type="button" class="pl-filter" data-filter="unmarked" aria-pressed="false">Not marked <span class="pl-filter-count" data-count="unmarked"><?= (int)$count_unmarked ?></span></button>
                    <button type="button" class="pl-filter" data-filter="P" aria-pressed="false" style="--dot: var(--pl-p);"><i></i>Present <span class="pl-filter-count" data-count="P"><?= (int)$count_p ?></span></button>
                    <button type="button" class="pl-filter" data-filter="A" aria-pressed="false" style="--dot: var(--pl-a);"><i></i>Absent <span class="pl-filter-count" data-count="A"><?= (int)$count_a ?></span></button>
                    <button type="button" class="pl-filter" data-filter="L" aria-pressed="false" style="--dot: var(--pl-l);"><i></i>Late <span class="pl-filter-count" data-count="L"><?= (int)$count_l ?></span></button>
                    <button type="button" class="pl-filter" data-filter="E" aria-pressed="false" style="--dot: var(--pl-e);"><i></i>Excused <span class="pl-filter-count" data-count="E"><?= (int)$count_e ?></span></button>
                </div>
                <div class="pl-search">
                    <?= $ico['search'] ?>
                    <input type="search" class="form-control" data-search placeholder="Find a cadet" aria-label="Find a cadet by name or code" autocomplete="off">
                </div>
            </div>

            <?php if ($is_editable): ?>
                <div class="pl-quick">
                    <button type="button" class="btn btn-secondary btn-sm" data-mark-all>Mark everyone not yet marked as Present</button>
                    <div class="pl-key" aria-hidden="true">
                        <span><b style="--c: var(--pl-p);">P</b> Present</span>
                        <span><b style="--c: var(--pl-a);">A</b> Absent</span>
                        <span><b style="--c: var(--pl-l);">L</b> Late</span>
                        <span><b style="--c: var(--pl-e);">E</b> Excused</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Roll list -->
            <ol class="pl-roll">
                <?php $n = 0; foreach ($cadets as $cadet): $n++; ?>
                    <?php
                        $cid            = (int)$cadet['id'];
                        $rec            = $attendance_by_cadet[$cid] ?? null;
                        $current_status = $rec['status'] ?? '';
                        $minutes_late   = $rec['minutes_late'] ?? '';
                        $excuse_reason  = $rec['excuse_reason'] ?? '';

                        $mi = '';
                        if (!empty($cadet['middle_name'])) {
                            $mi = ' ' . mb_strtoupper(mb_substr(trim($cadet['middle_name']), 0, 1)) . '.';
                        }
                        $full_name = $cadet['last_name'] . ', ' . $cadet['first_name'] . $mi;
                        $search_key = mb_strtolower($full_name . ' ' . $cadet['cadet_code'] . ' ' . $cadet['first_name'] . ' ' . $cadet['last_name']);

                        $has_error = isset($form_errors[$cid]);
                        $row_error = $form_errors[$cid] ?? '';
                        $dis       = $is_editable ? '' : 'disabled';
                        $opts      = ['P' => 'Present', 'A' => 'Absent', 'L' => 'Late', 'E' => 'Excused'];
                    ?>
                    <li class="pl-cadet <?= $has_error ? 'has-row-error' : '' ?>"
                        data-cadet-id="<?= $cid ?>"
                        data-status="<?= e($current_status) ?>"
                        data-search="<?= e($search_key) ?>">

                        <div class="pl-cadet-id">
                            <span class="pl-cadet-num"><?= $n ?></span>
                            <div>
                                <div class="pl-cadet-name"><?= e($full_name) ?></div>
                                <div class="pl-cadet-meta">
                                    <code><?= e($cadet['cadet_code']) ?></code>
                                    <?php if (!empty($cadet['program_code'])): ?>&bull; <?= e($cadet['program_code']) ?><?php endif; ?>
                                </div>
                                <div class="pl-cadet-err" data-err <?= $has_error ? '' : 'hidden' ?>><?= e($row_error) ?></div>
                            </div>
                        </div>

                        <div class="pl-status" role="radiogroup" aria-label="Attendance for <?= e($full_name) ?>">
                            <?php foreach ($opts as $code => $word): ?>
                                <label class="pl-opt pl-opt-<?= strtolower($code) ?>">
                                    <input type="radio"
                                           name="records[<?= $cid ?>][status]"
                                           value="<?= $code ?>"
                                           <?= $current_status === $code ? 'checked' : '' ?>
                                           <?= $dis ?>>
                                    <span class="pl-opt-face"><b><?= $code ?></b><small><?= $word ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($is_editable): ?>
                            <div class="pl-clear-slot">
                                <button type="button" class="pl-clear" title="Clear mark" aria-label="Clear mark for <?= e($full_name) ?>" <?= $current_status === '' ? 'hidden' : '' ?>><?= $ico['x'] ?></button>
                            </div>
                        <?php endif; ?>

                        <!-- Late minutes -->
                        <div class="pl-detail" data-detail="late" <?= $current_status === 'L' ? '' : 'hidden' ?>>
                            <label for="late-<?= $cid ?>">Minutes late</label>
                            <input type="number" id="late-<?= $cid ?>"
                                   name="records[<?= $cid ?>][minutes_late]"
                                   class="form-control pl-minutes"
                                   min="0" max="480" inputmode="numeric" placeholder="0"
                                   value="<?= e((string)$minutes_late) ?>" <?= $dis ?>>
                            <?php if ($is_editable): ?>
                                <span class="pl-min-chips" aria-label="Quick minutes">
                                    <?php foreach ([5, 10, 15, 30] as $m): ?>
                                        <button type="button" data-minutes="<?= $m ?>"><?= $m ?></button>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Excuse reason -->
                        <div class="pl-detail" data-detail="excuse" <?= $current_status === 'E' ? '' : 'hidden' ?>>
                            <label for="excuse-<?= $cid ?>">Reason</label>
                            <input type="text" id="excuse-<?= $cid ?>"
                                   name="records[<?= $cid ?>][excuse_reason]"
                                   class="form-control pl-excuse <?= ($has_error && $current_status === 'E') ? 'is-invalid' : '' ?>"
                                   placeholder="Why is this cadet excused? (required)"
                                   value="<?= e($excuse_reason) ?>" <?= $dis ?>>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="pl-no-match" data-nomatch hidden>No cadets match. Change the filter or search.</div>

            <?php if ($is_editable): ?>
                <!-- Sticky action bar -->
                <div class="pl-actionbar">
                    <div class="pl-actionbar-left">
                        <span class="pl-left-count <?= $count_unmarked > 0 ? 'is-warn' : '' ?>" data-left>
                            <?= $count_unmarked > 0 ? (int)$count_unmarked . ' left to mark' : 'All cadets marked' ?>
                        </span>
                        <span class="pl-save" data-save><span class="pl-save-dot"></span><span data-save-text>Changes save automatically</span></span>
                    </div>
                    <div class="pl-actionbar-right">
                        <button type="submit" name="form_action" value="save_draft" class="btn btn-secondary">Save draft</button>
                        <button type="submit" name="form_action" value="submit" class="btn btn-primary" data-submit>Submit to S1</button>
                    </div>
                </div>
            <?php endif; ?>
        </form>

    <?php endif; ?>

<?php endif; ?>

</div>

<script src="<?= BASE_URL ?>/assets/js/leader.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
