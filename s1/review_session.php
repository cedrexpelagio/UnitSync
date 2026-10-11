<?php
// S1 Review one submitted session: see the marks, then Approve or Return with remarks
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/s1_ui.php';

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
$late_no_min = 0;
$exc_no_reason = 0;
foreach ($marks as $m) {
    if ($m['status'] === null) { $count['none']++; continue; }
    $count[$m['status']]++;
    if ($m['status'] === 'L' && ($m['minutes_late'] === null || (int)$m['minutes_late'] <= 0)) $late_no_min++;
    if ($m['status'] === 'E' && trim((string)$m['excuse_reason']) === '') $exc_no_reason++;
}
$total = count($marks);
$marked = $total - $count['none'];
$rate = s1_rate($count['P'], $count['L'], $count['A']);
$details_missing = $late_no_min + $exc_no_reason;

$state = $sub['state'];
$mine_done = ($sub[$my_at] !== null);
$bn_name = person($sub['bn_first'], $sub['bn_last']);
$br_name = person($sub['br_first'], $sub['br_last']);
$sub_name = person($sub['sub_first'], $sub['sub_last']);
$other_name = ($level === 'brigade') ? 'Battalion S1' : 'Brigade S1';
$cancelled = ($sub['session_status'] === 'cancelled');

$status_text = ['P' => 'Present', 'A' => 'Absent', 'L' => 'Late', 'E' => 'Excused'];

// Oldest other submission still waiting for this account
$stmt = $pdo->prepare("
    SELECT sub.id FROM attendance_submissions sub
    JOIN training_sessions ts ON ts.id = sub.session_id
    WHERE ts.term_id = ? AND sub.state = 'submitted' AND sub.$my_at IS NULL AND sub.id <> ?
    ORDER BY sub.submitted_at ASC, sub.id ASC LIMIT 1
");
$stmt->execute([(int)$sub['term_id'], $id]);
$next_id = (int)$stmt->fetchColumn();

// Progress steps
$steps = [
    ['Submitted', $sub['submitted_at'] ? date('M d, g:i A', strtotime($sub['submitted_at'])) : '', true],
    ['Battalion S1', $sub['battalion_approved_at'] !== null ? ($bn_name !== '' ? $bn_name : 'Approved') : 'Pending', $sub['battalion_approved_at'] !== null],
    ['Brigade S1', $sub['brigade_approved_at'] !== null ? ($br_name !== '' ? $br_name : 'Approved') : 'Pending', $sub['brigade_approved_at'] !== null],
    ['Recorded and locked', $state === 'approved' ? 'In official attendance' : 'After both approvals', $state === 'approved'],
];
if ($state === 'returned') {
    $steps = [['Returned to Platoon Leader', 'Waiting for corrections', true]];
}
$current_set = false;

$blockers = [];
if ($count['none'] > 0) $blockers[] = $count['none'] . ' cadet(s) have no mark. Approving is blocked until they are marked.';
if ($cancelled) $blockers[] = 'The Admin cancelled this session after it was submitted. It cannot be approved.';

$ico_ok  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
$ico_bad = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
$ico_inf = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>';

$page_title = 'Review Session';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

<div class="s1-rv-head">
    <div>
        <h1><?= e($sub['label']) ?> &mdash; <?= e(date('F j, Y', strtotime($sub['session_date']))) ?></h1>
        <p><?= e($sub['company_name']) ?> - <?= e($sub['platoon_name']) ?></p>
    </div>
    <div class="s1-rv-nav">
        <a href="<?= BASE_URL ?>/s1/review_attendance.php" class="btn btn-secondary btn-sm">&larr; Back to queue</a>
        <?php if ($next_id): ?>
            <a href="<?= BASE_URL ?>/s1/review_session.php?id=<?= $next_id ?>" class="btn btn-secondary btn-sm">Next waiting session &rarr;</a>
        <?php endif; ?>
    </div>
</div>

<ol class="s1-stepper" aria-label="Approval progress">
    <?php foreach ($steps as $i => [$name, $note, $done]): ?>
        <?php
            $cls = $done ? 'is-done' : '';
            if (!$done && !$current_set && $state === 'submitted') { $cls = 'is-current'; $current_set = true; }
        ?>
        <li class="s1-step <?= $cls ?>">
            <span class="s1-step-dot"><?= $done ? '&#10003;' : $i + 1 ?></span>
            <div><b><?= e($name) ?></b><small><?= e($note) ?></small></div>
        </li>
    <?php endforeach; ?>
</ol>

<?php if ($state === 'returned' && $sub['remarks']): ?>
    <div class="s1-alert s1-alert-warn" style="margin-bottom: 16px;"><div class="s1-alert-body"><strong>Returned with remarks:</strong> <?= e($sub['remarks']) ?></div></div>
<?php endif; ?>
<?php if ($cancelled && $state === 'submitted'): ?>
    <div class="s1-alert s1-alert-bad" style="margin-bottom: 16px;"><div class="s1-alert-body"><strong>This session was cancelled</strong> by the Admin after it was submitted. It cannot be approved; return it if needed.</div></div>
<?php endif; ?>

<div class="s1-rv-layout">
    <section data-s1-review aria-label="Marks">
        <div class="s1-rv-summary">
            <?= s1_stack(['p' => $count['P'], 'l' => $count['L'], 'a' => $count['A'], 'e' => $count['E']]) ?>
            <div class="s1-rv-rate"><strong><?= $rate === null ? '&mdash;' : number_format($rate, 1) . '%' ?></strong> attendance (Present + Late of marked, excused excluded)</div>
        </div>

        <div class="s1-tiles">
            <button type="button" class="s1-tile s1-tile-all" data-s1-filter-mark="all" aria-pressed="true"><span>All</span><strong><?= $total ?></strong></button>
            <?php foreach (['P', 'L', 'A', 'E'] as $k): ?>
                <button type="button" class="s1-tile s1-tile-<?= $k ?>" data-s1-filter-mark="<?= $k ?>" aria-pressed="false"><span><?= e($status_text[$k]) ?></span><strong><?= $count[$k] ?></strong></button>
            <?php endforeach; ?>
            <button type="button" class="s1-tile s1-tile-none" data-s1-filter-mark="none" aria-pressed="false"><span>Not marked</span><strong><?= $count['none'] ?></strong></button>
        </div>

        <div class="s1-rv-tools">
            <div class="s1-search">
                <svg class="s1-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <input type="search" class="form-control" placeholder="Find a cadet" data-s1-marksearch aria-label="Find a cadet in this list" autocomplete="off">
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-s1-filter-mark="exceptions" aria-pressed="false" title="Everything except Present">Exceptions only</button>
            <span class="s1-rv-count">Showing <strong data-s1-markshown><?= $total ?></strong> of <?= $total ?></span>
        </div>

        <div class="s1-rv-wrap">
            <table class="s1-table">
                <thead>
                    <tr>
                        <th style="width: 44px;">#</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Mark</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$marks): ?>
                        <tr><td colspan="6" class="s1-rv-empty">No active cadets are enrolled in this platoon.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($marks as $i => $m): ?>
                        <?php
                            $mid = trim((string)$m['middle_name']);
                            $name = $m['last_name'] . ', ' . $m['first_name'] . ($mid !== '' ? ' ' . mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '');
                            $mark = $m['status'] ?? 'none';
                        ?>
                        <tr data-mark="<?= e($mark) ?>" data-name="<?= e(mb_strtolower($name . ' ' . $m['cadet_code'])) ?>" class="<?= $mark === 'none' ? 's1-rv-row-none' : '' ?>">
                            <td class="s1-cadet-code"><?= $i + 1 ?></td>
                            <td class="s1-cadet-code"><?= e($m['cadet_code']) ?></td>
                            <td class="s1-cadet-name"><?= e($name) ?></td>
                            <td><?= e($m['program_code'] ?? '') ?></td>
                            <td>
                                <?php if ($m['status'] === null): ?>
                                    <span class="s1-mark-word"><span class="s1-mark s1-mark-none">&mdash;</span> Not marked</span>
                                <?php else: ?>
                                    <span class="s1-mark-word"><span class="s1-mark s1-mark-<?= e($m['status']) ?>"><?= e($m['status']) ?></span> <?= e($status_text[$m['status']]) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 13px;">
                                <?php if ($m['status'] === 'L'): ?>
                                    <?= (int)$m['minutes_late'] > 0 ? (int)$m['minutes_late'] . ' minute(s) late' : '<span class="s1-queue-warn">Minutes not recorded</span>' ?>
                                <?php elseif ($m['status'] === 'E'): ?>
                                    <?= trim((string)$m['excuse_reason']) !== '' ? e($m['excuse_reason']) : '<span class="s1-queue-warn">No reason given</span>' ?>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="s1-rv-empty" data-s1-markempty hidden>No cadet matches this view. Clear the search or pick another filter.</div>
        </div>
    </section>

    <aside class="s1-rv-side" aria-label="Your decision">
        <div class="s1-card s1-decide">
            <?php if ($state === 'submitted'): ?>
                <h3>Your decision</h3>
                <ul class="s1-checks">
                    <?php if ($count['none'] === 0): ?>
                        <li class="ok"><?= $ico_ok ?><span>All <?= $total ?> cadets are marked</span></li>
                    <?php else: ?>
                        <li class="bad is-bad"><?= $ico_bad ?><span><?= (int)$count['none'] ?> cadet(s) not marked</span></li>
                    <?php endif; ?>
                    <?php if ($details_missing === 0): ?>
                        <li class="ok"><?= $ico_ok ?><span>Late minutes and excuse reasons are complete</span></li>
                    <?php else: ?>
                        <li class="info"><?= $ico_inf ?><span><?= (int)$details_missing ?> mark(s) are missing details (see the table)</span></li>
                    <?php endif; ?>
                    <?php if ($cancelled): ?>
                        <li class="bad is-bad"><?= $ico_bad ?><span>Session was cancelled by the Admin</span></li>
                    <?php endif; ?>
                </ul>

                <?php if ($mine_done): ?>
                    <?php $by_me = ((int)$sub[$my_by] === (int)$user['id']); $who = ($level === 'brigade') ? $br_name : $bn_name; ?>
                    <p class="help">
                        <?php if ($by_me): ?>
                            You already approved this as <?= e($level_name) ?>. It is waiting for the <?= e($other_name) ?>.
                        <?php else: ?>
                            Already approved by <?= e($level_name) ?><?= $who !== '' ? ' (' . e($who) . ')' : '' ?>. Nothing left for you to approve.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <form action="<?= BASE_URL ?>/s1/review_session.php" method="POST"
                          data-approve-label="<?= e($sub['label'] . ' (' . $sub['company_name'] . ' - ' . $sub['platoon_name'] . ')') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success btn-block" <?= $blockers ? 'disabled' : '' ?>>Approve as <?= e($level_name) ?></button>
                    </form>
                    <?php foreach ($blockers as $b): ?><p class="help" style="color: var(--error); font-weight: 600;"><?= e($b) ?></p><?php endforeach; ?>
                <?php endif; ?>

                <hr>

                <form action="<?= BASE_URL ?>/s1/review_session.php" method="POST" data-return-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="return">
                    <h4>Or return it to the Platoon Leader</h4>
                    <div class="s1-tpls" aria-label="Quick reasons">
                        <?php if ($count['none'] > 0): ?><button type="button" class="s1-tpl" data-target="#remarks" data-s1-template="Some cadets have no mark. Please complete the attendance and submit again.">Unmarked cadets</button><?php endif; ?>
                        <?php if ($late_no_min > 0): ?><button type="button" class="s1-tpl" data-target="#remarks" data-s1-template="Late marks are missing the minutes late. Please add them.">Late minutes</button><?php endif; ?>
                        <?php if ($exc_no_reason > 0): ?><button type="button" class="s1-tpl" data-target="#remarks" data-s1-template="Excused marks are missing a reason. Please add them.">Excuse reasons</button><?php endif; ?>
                        <button type="button" class="s1-tpl" data-target="#remarks" data-s1-template="Some marks look incorrect. Please re-check against the sign-in sheet.">Re-check marks</button>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="remarks">Remarks (required)</label>
                        <textarea id="remarks" name="remarks" class="form-control" rows="4" maxlength="1000" placeholder="What needs to be corrected?" required></textarea>
                        <div class="s1-counter" data-s1-counter-for="#remarks"></div>
                        <p class="help">Returning clears every approval already given. The Platoon Leader corrects and submits again.</p>
                    </div>
                    <button type="submit" class="btn btn-danger btn-block">Return with remarks</button>
                </form>
            <?php elseif ($state === 'approved'): ?>
                <h3>Recorded</h3>
                <ul class="s1-checks"><li class="ok"><?= $ico_ok ?><span><strong>Approved and locked.</strong> Both approvals are in, so this session is part of the official attendance.</span></li></ul>
            <?php else: ?>
                <h3>Returned</h3>
                <ul class="s1-checks"><li class="info"><?= $ico_inf ?><span>This submission is with the Platoon Leader. It returns to the queue once it is corrected and submitted again.</span></li></ul>
            <?php endif; ?>
        </div>

        <div class="s1-card">
            <h3>Submission</h3>
            <dl class="s1-kv">
                <div><dt>Submitted by</dt><dd><?= $sub_name !== '' ? e($sub_name) : '&mdash;' ?></dd></div>
                <div><dt>Submitted</dt><dd><?= $sub['submitted_at'] ? e(date('M d, g:i A', strtotime($sub['submitted_at']))) : '&mdash;' ?></dd></div>
                <div><dt>Marked</dt><dd><?= $marked ?> of <?= $total ?></dd></div>
                <div><dt>Battalion S1</dt><dd><?= $sub['battalion_approved_at'] !== null ? 'Approved' . ($bn_name !== '' ? ' (' . e($bn_name) . ')' : '') : 'Pending' ?></dd></div>
                <div><dt>Brigade S1</dt><dd><?= $sub['brigade_approved_at'] !== null ? 'Approved' . ($br_name !== '' ? ' (' . e($br_name) . ')' : '') : 'Pending' ?></dd></div>
            </dl>
        </div>
    </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    var form = document.querySelector('form[data-approve-label]');
    if (form) {
        form.addEventListener('submit', function (ev) {
            if (typeof showConfirm !== 'function') return;
            ev.preventDefault();
            showConfirm({
                title: 'Approve Attendance',
                message: 'Approve <strong>' + esc(form.getAttribute('data-approve-label')) + '</strong> as <?= e($level_name) ?>? Once both approvals are in, the session is recorded and locked.',
                confirmText: 'Approve'
            }).then(function (ok) { if (ok) form.submit(); });
        });
    }
    var ret = document.querySelector('form[data-return-form]');
    if (ret) {
        ret.addEventListener('submit', function (ev) {
            if (typeof showConfirm !== 'function' || ret.getAttribute('data-confirmed')) return;
            ev.preventDefault();
            showConfirm({
                title: 'Return to Platoon Leader',
                message: 'Return this session with your remarks? Every approval already given will be cleared.',
                confirmText: 'Return'
            }).then(function (ok) { if (ok) { ret.setAttribute('data-confirmed', '1'); ret.submit(); } });
        });
    }
});
</script>
<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
