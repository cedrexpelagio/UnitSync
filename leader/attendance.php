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

<div class="content-header">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1>Take Attendance</h1>
            <p style="margin: 0; color: var(--gray-700);">
                <strong>Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?></strong>
                <?php if ($active_term): ?>
                    &nbsp;|&nbsp; Term: <span style="color: var(--green-700); font-weight: 600;"><?= e($active_term['name']) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm">
                View Platoon Cadets
            </a>
        </div>
    </div>
</div>

<?php if (!$active_term): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">No Active Academic Term</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            There is currently no active academic term configured in the system. Attendance sessions cannot be loaded.
        </p>
    </div>
<?php elseif (!$platoon_id || !$company_id): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">Unassigned Officer</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            Your account is not assigned to a company and platoon. Please contact your administrator.
        </p>
    </div>
<?php elseif (empty($sessions)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Training Sessions Found</h3>
        <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 0;">
            There are no training sessions configured for <?= e($active_term['name']) ?> yet. Please contact your administrator.
        </p>
    </div>
<?php else: ?>

    <!-- Session Selector Strip -->
    <div style="margin-bottom: 8px; font-size: 13px; font-weight: 600; color: var(--gray-700);">
        Select Training Session:
    </div>
    <div class="attendance-session-strip">
        <?php foreach ($sessions as $s): ?>
            <?php
                $s_is_active   = ($selected_session && (int)$s['id'] === (int)$selected_session['id']);
                $s_cancelled   = ($s['status'] === 'cancelled');
                $s_future      = ($s['session_date'] > $today_manila);
                $s_sub_state   = $s['submission_state'] ?? null;

                // Determine badge
                $badge_class = 'badge-deactivated';
                $badge_label = 'Not started';

                if ($s_cancelled) {
                    $badge_class = 'badge-cancelled';
                    $badge_label = 'Cancelled';
                } elseif ($s_sub_state === 'draft') {
                    $badge_class = 'badge-draft';
                    $badge_label = 'Draft';
                } elseif ($s_sub_state === 'submitted') {
                    $badge_class = 'badge-submitted';
                    $badge_label = 'Submitted';
                } elseif ($s_sub_state === 'returned') {
                    $badge_class = 'badge-returned';
                    $badge_label = 'Returned';
                } elseif ($s_sub_state === 'approved') {
                    $badge_class = 'badge-approved';
                    $badge_label = 'Approved';
                }

                $classes = ['session-card-pill'];
                if ($s_is_active) $classes[] = 'active';
                if ($s_cancelled) $classes[] = 'disabled';
            ?>
            <a href="<?= BASE_URL ?>/leader/attendance.php?session=<?= (int)$s['id'] ?>" class="<?= implode(' ', $classes) ?>">
                <div class="session-date">
                    <?= date('M d, Y', strtotime($s['session_date'])) ?>
                    <?php if ($s_future): ?>
                        <span style="color: var(--gray-500); font-weight: normal;">(Future)</span>
                    <?php endif; ?>
                </div>
                <div class="session-label">
                    <?= e($s['label']) ?>
                </div>
                <div style="margin-top: auto; padding-top: 4px;">
                    <span class="badge <?= $badge_class ?>" style="font-size: 11px;">
                        <?= e($badge_label) ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($selected_session): ?>

        <!-- Notice Banners based on Session State -->
        <?php if ($is_cancelled): ?>
            <div style="background-color: #FFF5F5; border: 1px solid #FEB2B2; border-radius: var(--radius-default); padding: 16px 20px; margin-bottom: 20px;">
                <h4 style="color: var(--error); margin-bottom: 4px;">Session Cancelled</h4>
                <p style="font-size: 13px; color: var(--gray-700); margin: 0;">
                    This training session was marked as cancelled. Attendance marking is disabled.
                </p>
            </div>
        <?php elseif ($is_future): ?>
            <div style="background-color: #EFF6FF; border: 1px solid #BFDBFE; border-radius: var(--radius-default); padding: 16px 20px; margin-bottom: 20px;">
                <h4 style="color: #1D4ED8; margin-bottom: 4px;">Future Training Session (Locked)</h4>
                <p style="font-size: 13px; color: var(--gray-700); margin: 0;">
                    This session is scheduled for <strong><?= date('F j, Y', strtotime($selected_session['session_date'])) ?></strong>. Attendance can only be taken on or after the scheduled date.
                </p>
            </div>
        <?php elseif ($is_approved): ?>
            <div style="background-color: #ECFDF5; border: 1px solid #A7F3D0; border-radius: var(--radius-default); padding: 16px 20px; margin-bottom: 20px;">
                <h4 style="color: #065F46; margin-bottom: 4px;">Attendance Approved & Locked</h4>
                <p style="font-size: 13px; color: var(--gray-700); margin: 0;">
                    This session's attendance sheet has received final approval from S1 leadership and is locked in read-only mode.
                </p>
            </div>
        <?php elseif ($selected_session['submission_state'] === 'submitted'): ?>
            <div class="banner-notice banner-submitted-warning">
                <div class="banner-notice-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    Attendance Submitted (Under S1 Review)
                </div>
                <div>
                    This attendance sheet has been submitted to S1. You may still make edits if needed, but <strong>saving any changes will clear approvals already given by S1</strong> and require re-verification.
                </div>
            </div>
            <?php if ($count_unmarked > 0): ?>
                <div class="banner-notice" style="background-color: #FEF2F2; border: 1px solid #F87171; color: #991B1B;">
                    <div class="banner-notice-header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        Unmarked Cadets in Submitted Session
                    </div>
                    <div>
                        There are <strong><?= $count_unmarked ?> unmarked cadet(s)</strong> (e.g. recently enrolled into the platoon). Please mark them and resubmit the sheet so S1 can approve.
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif ($selected_session['submission_state'] === 'returned'): ?>
            <div class="banner-notice banner-returned-alert">
                <div class="banner-notice-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    Attendance Returned by S1
                </div>
                <div style="margin-bottom: 6px;">
                    This attendance submission was returned for correction. Please review the remarks, update the sheet, and resubmit.
                </div>
                <?php if (!empty($selected_session['submission_remarks'])): ?>
                    <div style="background-color: var(--white); border: 1px dashed #F59E0B; padding: 10px 14px; border-radius: 6px; font-size: 13px; color: #92400E;">
                        <strong>S1 Remarks:</strong> <?= nl2br(e($selected_session['submission_remarks'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Attendance Sheet Header & Actions Toolbar -->
        <div class="attendance-sheet-header">
            <div>
                <h2 style="font-size: 18px; color: var(--green-900); margin-bottom: 4px;">
                    <?= e($selected_session['label']) ?> &mdash; <?= date('F j, Y', strtotime($selected_session['session_date'])) ?>
                </h2>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <div class="att-counter-pill" id="counter-pill">
                        <span>Marked: <strong id="cnt-marked" style="color: var(--green-900);"><?= $count_marked ?></strong> / <?= $count_total ?></span>
                        <span style="color: var(--gray-400);">|</span>
                        <span>Unmarked: <strong id="cnt-unmarked" style="color: <?= $count_unmarked > 0 ? 'var(--warning)' : 'var(--gray-700)' ?>;"><?= $count_unmarked ?></strong></span>
                    </div>

                    <?php if ($is_editable): ?>
                        <div class="save-indicator" id="save-indicator">
                            <span class="save-indicator-dot" style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: currentColor;"></span>
                            <span id="save-status-text">Draft ready</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_editable): ?>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-mark-all-present">
                        Mark All Present (P)
                    </button>
                    <button type="submit" form="attendance-sheet-form" name="form_action" value="save_draft" class="btn btn-secondary btn-sm" id="btn-save-draft">
                        Save Draft
                    </button>
                    <button type="button" class="btn btn-sm btn-submit-attendance btn-trigger-submit" id="btn-submit-attendance">
                        Submit to S1
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attendance Form & Table -->
        <?php if (empty($cadets)): ?>
            <div class="empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Active Cadets in Platoon</h3>
                <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
                    There are no active cadets enrolled in your platoon for this active term.
                </p>
                <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary btn-sm">+ Enroll Cadets</a>
            </div>
        <?php else: ?>
            <form id="attendance-sheet-form" method="POST" action="<?= BASE_URL ?>/leader/attendance.php?session=<?= (int)$selected_session['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="session_id" value="<?= (int)$selected_session['id'] ?>">

                <div class="table-responsive">
                    <table class="data-table" id="attendance-table">
                        <thead>
                            <tr>
                                <th class="sticky-cadet-col" style="min-width: 220px;">Cadet Name</th>
                                <th style="min-width: 90px;">Program</th>
                                <th style="min-width: 260px;">Attendance Status</th>
                                <th style="min-width: 260px;">Details (Late / Excuse)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cadets as $cadet): ?>
                                <?php
                                    $cid = (int)$cadet['id'];
                                    $rec = $attendance_by_cadet[$cid] ?? null;
                                    $current_status = $rec['status'] ?? '';
                                    $minutes_late   = $rec['minutes_late'] ?? '';
                                    $excuse_reason  = $rec['excuse_reason'] ?? '';

                                    // Full name: Last, First, M.I.
                                    $mi = '';
                                    if (!empty($cadet['middle_name'])) {
                                        $mi = ' ' . mb_strtoupper(mb_substr(trim($cadet['middle_name']), 0, 1)) . '.';
                                    }
                                    $full_name = $cadet['last_name'] . ', ' . $cadet['first_name'] . $mi;

                                    $has_error = isset($form_errors[$cid]);
                                    $row_error = $form_errors[$cid] ?? '';
                                ?>
                                <tr class="cadet-row <?= $has_error ? 'has-row-error' : '' ?>" data-cadet-id="<?= $cid ?>">
                                    <!-- Sticky Name Column -->
                                    <td class="sticky-cadet-col">
                                        <div style="font-weight: 600; color: var(--green-900);">
                                            <?= e($full_name) ?>
                                        </div>
                                        <div class="cadet-details-subtext">
                                            <span style="font-family: monospace;"><?= e($cadet['cadet_code']) ?></span>
                                            &bull; <?= e($cadet['gender']) ?>
                                        </div>
                                        <?php if ($has_error): ?>
                                            <div style="color: var(--error); font-size: 11px; margin-top: 4px; font-weight: 600;">
                                                <?= e($row_error) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Program Column -->
                                    <td>
                                        <span style="font-weight: 500; font-size: 13px;">
                                            <?= !empty($cadet['program_code']) ? e($cadet['program_code']) : '&mdash;' ?>
                                        </span>
                                    </td>

                                    <!-- Status Radio Group Column -->
                                    <td>
                                        <div class="att-radio-group">
                                            <!-- Present -->
                                            <label class="att-radio-label opt-p" title="Present">
                                                <input type="radio" 
                                                       name="records[<?= $cid ?>][status]" 
                                                       value="P" 
                                                       <?= $current_status === 'P' ? 'checked' : '' ?>
                                                       <?= !$is_editable ? 'disabled' : '' ?>>
                                                <span class="att-btn">P</span>
                                            </label>

                                            <!-- Absent -->
                                            <label class="att-radio-label opt-a" title="Absent">
                                                <input type="radio" 
                                                       name="records[<?= $cid ?>][status]" 
                                                       value="A" 
                                                       <?= $current_status === 'A' ? 'checked' : '' ?>
                                                       <?= !$is_editable ? 'disabled' : '' ?>>
                                                <span class="att-btn">A</span>
                                            </label>

                                            <!-- Late -->
                                            <label class="att-radio-label opt-l" title="Late">
                                                <input type="radio" 
                                                       name="records[<?= $cid ?>][status]" 
                                                       value="L" 
                                                       <?= $current_status === 'L' ? 'checked' : '' ?>
                                                       <?= !$is_editable ? 'disabled' : '' ?>>
                                                <span class="att-btn">L</span>
                                            </label>

                                            <!-- Excused -->
                                            <label class="att-radio-label opt-e" title="Excused">
                                                <input type="radio" 
                                                       name="records[<?= $cid ?>][status]" 
                                                       value="E" 
                                                       <?= $current_status === 'E' ? 'checked' : '' ?>
                                                       <?= !$is_editable ? 'disabled' : '' ?>>
                                                <span class="att-btn">E</span>
                                            </label>

                                            <?php if ($is_editable): ?>
                                                <!-- Clear / Unmark button -->
                                                <button type="button" class="att-clear-btn btn-unmark" title="Clear selection">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Details Column (Late minutes & Excused reason) -->
                                    <td>
                                        <div class="att-extra-inputs">
                                            <!-- Minutes Late -->
                                            <div class="box-late" style="<?= $current_status === 'L' ? '' : 'display: none;' ?>">
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <label style="font-size: 11px; font-weight: 600; color: var(--gray-700); margin: 0; white-space: nowrap;">Minutes late:</label>
                                                    <input type="number" 
                                                           name="records[<?= $cid ?>][minutes_late]" 
                                                           class="form-control input-late" 
                                                           min="0" 
                                                           max="480" 
                                                           style="width: 80px;" 
                                                           placeholder="0"
                                                           value="<?= e((string)$minutes_late) ?>"
                                                           <?= !$is_editable ? 'disabled' : '' ?>>
                                                </div>
                                            </div>

                                            <!-- Excuse Reason -->
                                            <div class="box-excuse" style="<?= $current_status === 'E' ? '' : 'display: none;' ?>">
                                                <input type="text" 
                                                       name="records[<?= $cid ?>][excuse_reason]" 
                                                       class="form-control input-excuse <?= ($has_error && $current_status === 'E') ? 'is-invalid' : '' ?>" 
                                                       placeholder="Excuse reason (required)" 
                                                       value="<?= e($excuse_reason) ?>"
                                                       <?= !$is_editable ? 'disabled' : '' ?>>
                                            </div>

                                            <!-- Fallback placeholder for P and A -->
                                            <div class="box-blank" style="<?= in_array($current_status, ['P', 'A', ''], true) ? '' : 'display: none;' ?>">
                                                <span style="font-size: 12px; color: var(--gray-400);">&mdash;</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($is_editable): ?>
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px;">
                        <button type="submit" name="form_action" value="save_draft" class="btn btn-secondary">
                            Save Draft
                        </button>
                        <button type="button" class="btn btn-submit-attendance btn-trigger-submit">
                            Submit to S1
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

<?php if ($selected_session && $is_editable && !empty($cadets)): ?>
<script>
// Step C: JavaScript Auto-Save, Dynamic Toggles, and Mark All Present
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('attendance-table');
    if (!table) return;

    const sessionId = <?= (int)$selected_session['id'] ?>;
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    const indicator = document.getElementById('save-indicator');
    const statusText = document.getElementById('save-status-text');
    const cntMarked = document.getElementById('cnt-marked');
    const cntUnmarked = document.getElementById('cnt-unmarked');
    const totalCadets = <?= count($cadets) ?>;

    let autoSaveTimer = null;
    let isSaving = false;

    // Helper: update save indicator status
    function setSaveStatus(state, message) {
        if (!indicator || !statusText) return;
        indicator.className = 'save-indicator ' + state;
        statusText.textContent = message;
    }

    // Helper: calculate counts of marked and unmarked
    function updateCounters() {
        let marked = 0;
        document.querySelectorAll('.cadet-row').forEach(row => {
            const checked = row.querySelector('input[type="radio"]:checked');
            if (checked && checked.value) {
                marked++;
            }
        });
        const unmarked = totalCadets - marked;
        if (cntMarked) cntMarked.textContent = marked;
        if (cntUnmarked) {
            cntUnmarked.textContent = unmarked;
            cntUnmarked.style.color = unmarked > 0 ? 'var(--warning)' : 'var(--gray-700)';
        }
    }

    // Toggle row inputs based on chosen status
    function updateRowInputs(row, status) {
        const boxLate = row.querySelector('.box-late');
        const boxExcuse = row.querySelector('.box-excuse');
        const boxBlank = row.querySelector('.box-blank');

        if (boxLate) boxLate.style.display = (status === 'L') ? 'block' : 'none';
        if (boxExcuse) boxExcuse.style.display = (status === 'E') ? 'block' : 'none';
        if (boxBlank) boxBlank.style.display = (status === 'P' || status === 'A' || status === '') ? 'block' : 'none';
    }

    // Collect current state of the sheet
    function collectSheetData() {
        const records = [];
        document.querySelectorAll('.cadet-row').forEach(row => {
            const cid = parseInt(row.getAttribute('data-cadet-id'), 10);
            const checkedRadio = row.querySelector('input[type="radio"]:checked');
            const status = checkedRadio ? checkedRadio.value : '';

            const minInput = row.querySelector('.input-late');
            const excuseInput = row.querySelector('.input-excuse');

            records.push({
                cadet_id: cid,
                status: status,
                minutes_late: minInput ? minInput.value.trim() : null,
                excuse_reason: excuseInput ? excuseInput.value.trim() : null
            });
        });
        return records;
    }

    // Trigger auto-save to API
    async function performAutoSave() {
        if (isSaving) return;
        isSaving = true;
        setSaveStatus('saving', 'Saving changes...');

        const records = collectSheetData();

        try {
            const response = await fetch('<?= BASE_URL ?>/api/attendance_save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    csrf_token: csrfToken,
                    records: records
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setSaveStatus('saved', 'Saved at ' + (data.saved_at || 'just now'));
                // Clear any error highlights
                document.querySelectorAll('.input-excuse.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            } else {
                setSaveStatus('error', data.error || 'Save failed');
                if (data.row_errors) {
                    for (const [cid, errMsg] of Object.entries(data.row_errors)) {
                        const row = document.querySelector(`.cadet-row[data-cadet-id="${cid}"]`);
                        if (row) {
                            const excuseInput = row.querySelector('.input-excuse');
                            if (excuseInput) excuseInput.classList.add('is-invalid');
                        }
                    }
                }
            }
        } catch (err) {
            setSaveStatus('error', 'Network error while saving');
        } finally {
            isSaving = false;
        }
    }

    // Debounced trigger
    function scheduleAutoSave() {
        setSaveStatus('saving', 'Unsaved changes...');
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(performAutoSave, 1200);
    }

    // Handle radio changes
    table.addEventListener('change', function (e) {
        if (e.target.matches('input[type="radio"]')) {
            const row = e.target.closest('.cadet-row');
            if (row) {
                updateRowInputs(row, e.target.value);
                updateCounters();
                scheduleAutoSave();
            }
        } else if (e.target.matches('.input-late') || e.target.matches('.input-excuse')) {
            scheduleAutoSave();
        }
    });

    // Handle typing in text inputs with input event debounce
    table.addEventListener('input', function (e) {
        if (e.target.matches('.input-late') || e.target.matches('.input-excuse')) {
            scheduleAutoSave();
        }
    });

    // Handle Clear / Unmark button
    table.addEventListener('click', function (e) {
        const unmarkBtn = e.target.closest('.btn-unmark');
        if (unmarkBtn) {
            const row = unmarkBtn.closest('.cadet-row');
            if (row) {
                const radios = row.querySelectorAll('input[type="radio"]');
                radios.forEach(r => r.checked = false);
                updateRowInputs(row, '');
                updateCounters();
                scheduleAutoSave();
            }
        }
    });

    // Mark All Present (P) button
    const btnMarkAll = document.getElementById('btn-mark-all-present');
    if (btnMarkAll) {
        btnMarkAll.addEventListener('click', function () {
            let changed = 0;
            document.querySelectorAll('.cadet-row').forEach(row => {
                const checked = row.querySelector('input[type="radio"]:checked');
                // By default, fill unmarked cadets so existing L and E entries are kept
                if (!checked) {
                    const radioP = row.querySelector('input[type="radio"][value="P"]');
                    if (radioP) {
                        radioP.checked = true;
                        updateRowInputs(row, 'P');
                        changed++;
                    }
                }
            });

            if (changed > 0) {
                updateCounters();
                scheduleAutoSave();
                if (typeof showToast === 'function') {
                    showToast(`Marked ${changed} unmarked cadet(s) as Present.`, 'success');
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast('All cadets are already marked.', 'info');
                }
            }
        });
    }

    // Step C: Warn once if session is already submitted
    <?php if ($selected_session['submission_state'] === 'submitted'): ?>
    let hasWarnedSubmittedEdit = false;
    function checkSubmittedWarning() {
        if (!hasWarnedSubmittedEdit) {
            hasWarnedSubmittedEdit = true;
            if (typeof showToast === 'function') {
                showToast('Notice: Editing a submitted sheet will reset any S1 approvals upon saving.', 'warning', 6000);
            }
        }
    }
    table.addEventListener('change', checkSubmittedWarning);
    <?php endif; ?>

    // Step C: Submit Confirmation Modal & Unmarked Cadets Enforcement
    const submitButtons = document.querySelectorAll('.btn-trigger-submit, #btn-submit-attendance');
    submitButtons.forEach(btn => {
        btn.addEventListener('click', async function (e) {
            e.preventDefault();
            const form = document.getElementById('attendance-sheet-form');
            if (!form) return;

            // Collect unmarked cadets
            const unmarkedCadets = [];
            document.querySelectorAll('.cadet-row').forEach(row => {
                const checked = row.querySelector('input[type="radio"]:checked');
                if (!checked || !checked.value) {
                    const nameEl = row.querySelector('.sticky-cadet-col div:first-child');
                    unmarkedCadets.push(nameEl ? nameEl.textContent.trim() : 'Unnamed Cadet');
                }
            });

            if (unmarkedCadets.length > 0) {
                const preview = unmarkedCadets.slice(0, 5).join('<br>');
                const remaining = unmarkedCadets.length > 5 ? `<div style="margin-top: 4px; font-style: italic;">...and ${unmarkedCadets.length - 5} more</div>` : '';
                await showConfirm({
                    title: 'Cannot Submit Attendance',
                    message: `<p style="margin-bottom: 10px;">There are still <strong>${unmarkedCadets.length} unmarked cadet(s)</strong>:</p>
                              <div style="background-color: #FFF5F5; border: 1px solid #FEB2B2; border-radius: 6px; padding: 10px 12px; color: #C53030; font-size: 13px; max-height: 140px; overflow-y: auto;">
                                  ${preview}
                                  ${remaining}
                              </div>
                              <p style="margin-top: 10px; font-size: 13px; color: var(--gray-700);">Every cadet in the platoon must be marked (Present, Absent, Late, or Excused) before the sheet can be submitted to S1.</p>`,
                    confirmText: 'Got It',
                    cancelText: 'Close'
                });
                return;
            }

            const sessionLabel = <?= json_encode($selected_session['label']) ?>;
            const confirmed = await showConfirm({
                title: 'Submit Attendance to S1',
                message: `<p style="margin-bottom: 8px;">Are you sure you want to submit attendance for <strong>${sessionLabel}</strong>?</p>
                          <p style="font-size: 13px; color: var(--gray-700); margin: 0;">This will forward the attendance record to <strong>Battalion S1</strong> and <strong>Brigade S1</strong> for review and approval.</p>`,
                confirmText: 'Submit Attendance',
                cancelText: 'Cancel'
            });

            if (confirmed) {
                let actionInput = form.querySelector('input[name="form_action"]');
                if (!actionInput) {
                    actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'form_action';
                    form.appendChild(actionInput);
                }
                actionInput.value = 'submit';
                form.submit();
            }
        });
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>