<?php
// UnitSync: Attendance Auto-Save API (Stage PL-5, Step C)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Guard: Authentication & Role
if (!is_logged_in() || current_user()['role'] !== 'platoon_leader') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$user = current_user();

// 2. Read request body (JSON or POST)
$raw_body = file_get_contents('php://input');
$data = json_decode($raw_body, true);
if (!is_array($data)) {
    $data = $_POST;
}

// 3. CSRF Verification
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? null);
if (!verify_csrf_token($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token. Please refresh.']);
    exit;
}

// 4. Load leader assignment
$stmt = $pdo->prepare("SELECT company_id, platoon_id FROM user_assignments WHERE user_id = ?");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();
$company_id = $assignment['company_id'] ?? null;
$platoon_id = $assignment['platoon_id'] ?? null;

if (!$platoon_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You are not currently assigned to a platoon.']);
    exit;
}

// 5. Load active term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();
if (!$active_term) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No active academic term configured.']);
    exit;
}

// 6. Validate Session
$session_id = (int)($data['session_id'] ?? 0);
if ($session_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A valid session ID is required.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ? AND term_id = ?");
$stmt->execute([$session_id, (int)$active_term['id']]);
$session = $stmt->fetch();
if (!$session) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Training session not found in active term.']);
    exit;
}

if ($session['status'] === 'cancelled') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Attendance cannot be marked for a cancelled session.']);
    exit;
}

$today_manila = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
if ($session['session_date'] > $today_manila) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Attendance cannot be taken for future sessions.']);
    exit;
}

// 7. Check current submission state
$stmt = $pdo->prepare("SELECT * FROM attendance_submissions WHERE platoon_id = ? AND session_id = ?");
$stmt->execute([(int)$platoon_id, $session_id]);
$submission = $stmt->fetch();

if ($submission && $submission['state'] === 'approved') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'This session attendance has already been approved and is locked.']);
    exit;
}

// 8. Fetch active cadets enrolled in this leader's platoon for this term
$stmt = $pdo->prepare("
    SELECT c.id 
    FROM cadets c
    JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
    WHERE e.platoon_id = ? AND c.status = 'active'
");
$stmt->execute([(int)$active_term['id'], (int)$platoon_id]);
$valid_cadet_ids = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

// 9. Validate records
$records = $data['records'] ?? [];
if (!is_array($records)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid records format.']);
    exit;
}

$row_errors = [];
$parsed_records = [];

foreach ($records as $item) {
    $cid = (int)($item['cadet_id'] ?? 0);
    if (!isset($valid_cadet_ids[$cid])) {
        continue; // ignore any cadet not belonging to this platoon or inactive
    }

    $raw_status = trim($item['status'] ?? '');
    $status = in_array($raw_status, ['P', 'A', 'L', 'E'], true) ? $raw_status : '';
    $minutes_late = null;
    $excuse_reason = null;

    if ($status === 'L') {
        $raw_min = trim((string)($item['minutes_late'] ?? ''));
        if ($raw_min !== '') {
            if (!ctype_digit($raw_min) || (int)$raw_min < 0 || (int)$raw_min > 480) {
                $row_errors[$cid] = 'Minutes late must be a whole number between 0 and 480.';
            } else {
                $minutes_late = (int)$raw_min;
            }
        }
    } elseif ($status === 'E') {
        $excuse_reason = trim($item['excuse_reason'] ?? '');
        if ($excuse_reason === '') {
            $row_errors[$cid] = 'Excuse reason is required when marking Excused.';
        }
    }

    $parsed_records[$cid] = [
        'status'        => $status,
        'minutes_late'  => $minutes_late,
        'excuse_reason' => $excuse_reason
    ];
}

if (!empty($row_errors)) {
    http_response_code(422);
    echo json_encode([
        'success'    => false,
        'error'      => 'Please correct the validation errors in the sheet.',
        'row_errors' => $row_errors
    ]);
    exit;
}

// 10. Persist changes in a database transaction
try {
    $pdo->beginTransaction();

    // 1. Ensure submission row exists first (draft if new)
    if (!$submission) {
        $stmt_sub = $pdo->prepare("
            INSERT INTO attendance_submissions (
                platoon_id, session_id, state, created_at, updated_at
            ) VALUES (
                :platoon_id, :session_id, 'draft', NOW(), NOW()
            )
        ");
        $stmt_sub->execute([
            'platoon_id' => (int)$platoon_id,
            'session_id' => $session_id
        ]);
        $sub_id = (int)$pdo->lastInsertId();
        $submission = ['id' => $sub_id, 'state' => 'draft'];
    } else {
        $sub_id = (int)$submission['id'];
    }

    // 2. Upsert / delete records with submission_id
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

    foreach ($parsed_records as $cid => $rec) {
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
            // Unmarked cadet: remove record if one existed
            $stmt_delete->execute([
                'cadet_id'   => $cid,
                'session_id' => $session_id
            ]);
        }
    }

    $cleared_approvals = false;

        if ($submission['state'] === 'submitted') {
            // Edit after submission clears approvals
            $stmt_sub = $pdo->prepare("
                UPDATE attendance_submissions 
                SET battalion_approved_by = NULL,
                    battalion_approved_at = NULL,
                    brigade_approved_by   = NULL,
                    brigade_approved_at   = NULL,
                    updated_at            = NOW() 
                WHERE id = ?
            ");
            $stmt_sub->execute([(int)$submission['id']]);
            $cleared_approvals = true;

            log_audit(
                $pdo,
                $user['id'],
                'edit_submitted_attendance',
                'attendance_submission',
                (int)$submission['id'],
                json_encode([
                    'session_id'        => $session_id,
                    'platoon_id'        => (int)$platoon_id,
                    'cleared_approvals' => true,
                    'updated_at'        => date('Y-m-d H:i:s')
                ])
            );
        } else {
            $stmt_sub = $pdo->prepare("
                UPDATE attendance_submissions 
                SET updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt_sub->execute([(int)$submission['id']]);
        }

    $pdo->commit();

    echo json_encode([
        'success'           => true,
        'message'           => $cleared_approvals ? 'Saved. Approvals have been reset.' : 'Draft saved successfully.',
        'saved_at'          => (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('h:i:s A'),
        'cleared_approvals' => $cleared_approvals
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error: ' . $e->getMessage()
    ]);
}