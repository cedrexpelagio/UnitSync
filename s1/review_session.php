<?php
// S1 Review one submitted session: see the marks, then Approve or Return with remarks
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();
$level = ($user['role'] === 'brigade_s1') ? 'brigade' : 'battalion';
$level_name = ($level === 'brigade') ? 'Brigade S1' : 'Battalion S1';
// Fixed column names (never taken from input)
$my_by = $level . '_approved_by';
$my_at = $level . '_approved_at';

$raw_id = $_POST['id'] ?? ($_GET['id'] ?? '');
$id = (is_string($raw_id) && ctype_digit($raw_id)) ? (int)$raw_id : 0;

function load_submission(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT sub.*, ts.label, ts.session_date, ts.term_id, ts.status AS session_status,
               pl.name AS platoon_name, co.name AS company_name,
               su.first_name AS sub_first, su.last_name AS sub_last,
               bu.first_name AS bn_first, bu.last_name AS bn_last,
               gu.first_name AS br_first, gu.last_name AS br_last
        FROM attendance_submissions sub
        JOIN training_sessions ts ON ts.id = sub.session_id
        JOIN platoons pl ON pl.id = sub.platoon_id
        JOIN companies co ON co.id = pl.company_id
        LEFT JOIN users su ON su.id = sub.submitted_by
        LEFT JOIN users bu ON bu.id = sub.battalion_approved_by
        LEFT JOIN users gu ON gu.id = sub.brigade_approved_by
        WHERE sub.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Active cadets of the platoon (for the term of the session) with their marks
function load_marks(PDO $pdo, array $sub): array {
    $stmt = $pdo->prepare("
        SELECT c.id, c.cadet_code, c.last_name, c.first_name, c.middle_name,
               pr.code AS program_code,
               ar.status, ar.minutes_late, ar.excuse_reason
        FROM cadets c
        JOIN enrollments en ON en.cadet_id = c.id AND en.term_id = :term AND en.platoon_id = :platoon
        LEFT JOIN programs pr ON pr.id = c.program_id
        LEFT JOIN attendance_records ar ON ar.cadet_id = c.id AND ar.session_id = :session
        WHERE c.status = 'active'
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
    ");
    $stmt->execute(['term' => (int)$sub['term_id'], 'platoon' => (int)$sub['platoon_id'], 'session' => (int)$sub['session_id']]);
    return $stmt->fetchAll();
}

function person(?string $first, ?string $last): string {
    return trim((string)$first . ' ' . (string)$last);
}

$sub = load_submission($pdo, $id);
if (!$sub || $sub['state'] === 'draft') {
    set_flash('error', 'That submission was not found or has not been submitted yet.');
    redirect('s1/review_attendance.php');
}
$self = 's1/review_session.php?id=' . $id;

// ---------- Approve / Return ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect($self);
    }
    $action = $_POST['action'] ?? '';

    $remarks = '';
    if ($action === 'return') {
        $r = $_POST['remarks'] ?? '';
        $remarks = is_string($r) ? trim($r) : '';
        if ($remarks === '') {
            set_flash('error', 'Remarks are required when returning a submission, so the Platoon Leader knows what to fix.');
            redirect($self);
        }
        if (mb_strlen($remarks) > 1000) {
            set_flash('error', 'Remarks must not exceed 1000 characters.');
            redirect($self);
        }
    } elseif ($action !== 'approve') {
        redirect($self);
    }

    try {
        $pdo->beginTransaction();

        // Lock the row so two S1 accounts cannot act on the same state at once
        $stmt = $pdo->prepare("SELECT * FROM attendance_submissions WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $locked = $stmt->fetch();

        if (!$locked || $locked['state'] !== 'submitted') {
            $pdo->rollBack();
            set_flash('error', 'This submission is no longer waiting for review (it is ' . ($locked['state'] ?? 'missing') . ').');
            redirect($self);
        }

        if ($action === 'approve') {
            if ($locked[$my_at] !== null) {
                $pdo->rollBack();
                set_flash('info', 'The ' . $level_name . ' approval is already in.');
                redirect($self);
            }
            if ($sub['session_status'] === 'cancelled') {
                $pdo->rollBack();
                set_flash('error', 'This session was cancelled by the Admin, so it cannot be approved. Return it instead.');
                redirect($self);
            }

            // Every active cadet in the platoon must have a mark
            $missing = 0;
            foreach (load_marks($pdo, $sub) as $m) {
                if ($m['status'] === null) $missing++;
            }
            if ($missing > 0) {
                $pdo->rollBack();
                set_flash('error', "{$missing} cadet(s) in this platoon have no mark for this session. Return it so the Platoon Leader can complete it.");
                redirect($self);
            }

            $upd = $pdo->prepare("UPDATE attendance_submissions SET $my_by = :uid, $my_at = NOW() WHERE id = :id");
            $upd->execute(['uid' => $user['id'], 'id' => $id]);

            // Both approvals in? Then the session is recorded and locked.
            $stmt = $pdo->prepare("SELECT battalion_approved_at, brigade_approved_at FROM attendance_submissions WHERE id = ?");
            $stmt->execute([$id]);
            $after = $stmt->fetch();
            $final = ($after['battalion_approved_at'] !== null && $after['brigade_approved_at'] !== null);
            if ($final) {
                $pdo->prepare("UPDATE attendance_submissions SET state = 'approved' WHERE id = ?")->execute([$id]);
            }

            log_audit($pdo, $user['id'], 'approve_attendance', 'attendance_submission', $id, json_encode([
                'level'         => $level,
                'session_id'    => (int)$sub['session_id'],
                'session_label' => $sub['label'],
                'platoon_id'    => (int)$sub['platoon_id'],
                'fully_approved' => $final,
            ]));
            $pdo->commit();

            set_flash('success', $final
                ? 'Approved. Both approvals are in, so this session is now recorded and locked.'
                : 'Your ' . $level_name . ' approval was recorded. Waiting for the ' . ($level === 'brigade' ? 'Battalion S1' : 'Brigade S1') . '.');
            redirect($self);
        }

        // Return: back to the Platoon Leader, all approvals cleared
        $pdo->prepare("
            UPDATE attendance_submissions
            SET state = 'returned', remarks = :remarks,
                battalion_approved_by = NULL, battalion_approved_at = NULL,
                brigade_approved_by = NULL, brigade_approved_at = NULL
            WHERE id = :id
        ")->execute(['remarks' => $remarks, 'id' => $id]);

        log_audit($pdo, $user['id'], 'return_attendance', 'attendance_submission', $id, json_encode([
            'level'         => $level,
            'session_id'    => (int)$sub['session_id'],
            'session_label' => $sub['label'],
            'platoon_id'    => (int)$sub['platoon_id'],
            'remarks'       => $remarks,
        ]));
        $pdo->commit();

        set_flash('success', 'Returned to the Platoon Leader with your remarks. All approvals were cleared.');
        redirect($self);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', 'Could not complete the action: ' . $ex->getMessage());
        redirect($self);
    }
}

// ---------- Page data ----------
$marks = load_marks($pdo, $sub);
$count = ['P' => 0, 'A' => 0, 'L' => 0, 'E' => 0, 'none' => 0];
foreach ($marks as $m) {
    if ($m['status'] === null) $count['none']++;
    else $count[$m['status']]++;
}

$state = $sub['state'];
$mine_done = ($sub[$my_at] !== null);
$bn_name = person($sub['bn_first'], $sub['bn_last']);
$br_name = person($sub['br_first'], $sub['br_last']);

$status_text  = ['P' => 'Present', 'A' => 'Absent', 'L' => 'Late', 'E' => 'Excused'];
$status_badge = ['P' => 'badge-approved', 'A' => 'badge-rejected', 'L' => 'badge-pending', 'E' => 'badge-submitted'];

$page_title = 'Review Session';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><?= e($sub['label']) ?> &mdash; <?= e(date('F j, Y', strtotime($sub['session_date']))) ?></h1>
    <p><?= e($sub['company_name']) ?> - <?= e($sub['platoon_name']) ?>
        &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/s1/review_attendance.php">&larr; Back to queue</a></p>
</div>

<div class="summary-cards">
    <div class="summary-card">
        <h3>State</h3>
        <p><span class="badge badge-<?= e($state) ?>"><?= e(ucfirst($state)) ?></span></p>
        <p style="font-size: 13px; color: var(--gray-700);">
            Submitted <?= $sub['submitted_at'] ? e(date('M d, Y g:i A', strtotime($sub['submitted_at']))) : '' ?>
            <?php if (person($sub['sub_first'], $sub['sub_last']) !== ''): ?>by <?= e(person($sub['sub_first'], $sub['sub_last'])) ?><?php endif; ?>
        </p>
    </div>
    <div class="summary-card">
        <h3>Approvals</h3>
        <p style="font-size: 14px;">
            Battalion S1:
            <?php if ($sub['battalion_approved_at'] !== null): ?>
                <strong>Approved</strong><?= $bn_name !== '' ? ' by ' . e($bn_name) : '' ?>
            <?php else: ?>Pending<?php endif; ?>
        </p>
        <p style="font-size: 14px;">
            Brigade S1:
            <?php if ($sub['brigade_approved_at'] !== null): ?>
                <strong>Approved</strong><?= $br_name !== '' ? ' by ' . e($br_name) : '' ?>
            <?php else: ?>Pending<?php endif; ?>
        </p>
    </div>
    <div class="summary-card">
        <h3>Summary</h3>
        <p style="font-size: 14px;">
            Present <strong><?= $count['P'] ?></strong> &middot; Absent <strong><?= $count['A'] ?></strong>
            &middot; Late <strong><?= $count['L'] ?></strong> &middot; Excused <strong><?= $count['E'] ?></strong>
        </p>
        <p style="font-size: 13px; color: <?= $count['none'] > 0 ? 'var(--warning)' : 'var(--gray-700)' ?>;">
            <?= $count['none'] > 0 ? $count['none'] . ' cadet(s) not marked' : 'All ' . count($marks) . ' cadets marked' ?>
        </p>
    </div>
</div>

<?php if ($state === 'returned' && $sub['remarks']): ?>
    <div class="summary-card" style="margin-bottom: 16px; border-left: 4px solid var(--warning);">
        <p style="font-size: 13px;"><strong>Returned with remarks:</strong> <?= e($sub['remarks']) ?></p>
    </div>
<?php endif; ?>

<?php if ($sub['session_status'] === 'cancelled' && $state === 'submitted'): ?>
    <div class="summary-card" style="margin-bottom: 16px; border-left: 4px solid var(--warning);">
        <p style="font-size: 13px;">This session was <strong>cancelled</strong> by the Admin after it was submitted. It cannot be approved; return it if needed.</p>
    </div>
<?php endif; ?>

<?php if ($state === 'submitted' && $count['none'] > 0): ?>
    <div class="summary-card" style="margin-bottom: 16px; border-left: 4px solid var(--warning);">
        <p style="font-size: 13px;"><strong><?= $count['none'] ?> cadet(s)</strong> in this platoon have no mark (they may have been assigned after submission). Approving is blocked until they are marked. Return it so the Platoon Leader can complete it.</p>
    </div>
<?php endif; ?>

<?php if ($state === 'submitted'): ?>
    <div class="summary-card" style="margin-bottom: 24px;">
        <h3>Your decision</h3>

        <?php if ($mine_done): ?>
            <p style="font-size: 13px; margin-bottom: 12px;">
                <?php
                    $who = ($level === 'brigade') ? $br_name : $bn_name;
                    $by_me = ((int)$sub[$my_by] === (int)$user['id']);
                ?>
                <?php if ($by_me): ?>
                    You already approved this session as <?= e($level_name) ?>. It is waiting for the <?= $level === 'brigade' ? 'Battalion S1' : 'Brigade S1' ?>.
                <?php else: ?>
                    Already approved by <?= e($level_name) ?><?= $who !== '' ? ' (' . e($who) . ')' : '' ?>. Nothing left for you to approve.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <form action="<?= BASE_URL ?>/s1/review_session.php" method="POST" style="display: inline;"
                  data-approve-label="<?= e($sub['label'] . ' (' . $sub['company_name'] . ' - ' . $sub['platoon_name'] . ')') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-success" <?= ($count['none'] > 0 || $sub['session_status'] === 'cancelled') ? 'disabled' : '' ?>>Approve as <?= e($level_name) ?></button>
            </form>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/s1/review_session.php" method="POST" style="margin-top: 16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="return">
            <div class="form-group">
                <label for="remarks">Return to Platoon Leader (remarks required)</label>
                <textarea id="remarks" name="remarks" class="form-control" rows="3" maxlength="1000" placeholder="What needs to be corrected?" required></textarea>
                <small>Returning clears every approval already given. The Platoon Leader corrects and submits again.</small>
            </div>
            <button type="submit" class="btn btn-danger">Return with remarks</button>
        </form>
    </div>
<?php elseif ($state === 'approved'): ?>
    <div class="summary-card" style="margin-bottom: 24px; border-left: 4px solid var(--success);">
        <p style="font-size: 13px;"><strong>Approved and locked.</strong> Both approvals are in, so this session is recorded in the official attendance.</p>
    </div>
<?php endif; ?>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Program</th>
                <th>Mark</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$marks): ?>
                <tr><td colspan="5">No active cadets are enrolled in this platoon.</td></tr>
            <?php endif; ?>
            <?php foreach ($marks as $m): ?>
                <?php
                    $mid = trim((string)$m['middle_name']);
                    $name = $m['last_name'] . ', ' . $m['first_name'] . ($mid !== '' ? ' ' . mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '');
                ?>
                <tr>
                    <td><?= e($m['cadet_code']) ?></td>
                    <td><?= e($name) ?></td>
                    <td><?= e($m['program_code'] ?? '') ?></td>
                    <td>
                        <?php if ($m['status'] === null): ?>
                            <span class="badge badge-deactivated">Not marked</span>
                        <?php else: ?>
                            <span class="badge <?= $status_badge[$m['status']] ?>"><?= e($status_text[$m['status']]) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 13px;">
                        <?php if ($m['status'] === 'L' && $m['minutes_late'] !== null): ?>
                            <?= (int)$m['minutes_late'] ?> minute(s) late
                        <?php elseif ($m['status'] === 'E' && $m['excuse_reason']): ?>
                            <?= e($m['excuse_reason']) ?>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    var form = document.querySelector('form[data-approve-label]');
    if (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            showConfirm({
                title: 'Approve Attendance',
                message: 'Approve <strong>' + esc(form.getAttribute('data-approve-label')) + '</strong> as <?= e($level_name) ?>? Once both approvals are in, the session is recorded and locked.',
                confirmText: 'Approve'
            }).then(function (ok) { if (ok) form.submit(); });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
