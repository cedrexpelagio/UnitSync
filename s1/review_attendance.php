<?php
// S1 Review Attendance: queue of submitted sessions (Battalion S1 and Brigade S1)
// Reads the same tables the Platoon Leader writes to; nothing is copied or moved.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();
$level = ($user['role'] === 'brigade_s1') ? 'brigade' : 'battalion';   // which approval this account gives
$my_col = $level . '_approved_at';                                      // fixed column name, never from input

$active_term = $pdo->query("SELECT id, name FROM terms WHERE is_active = 1 LIMIT 1")->fetch() ?: null;
$term_id = $active_term ? (int)$active_term['id'] : 0;

$views = [
    'needs'     => 'Needs my review',
    'submitted' => 'All submitted',
    'approved'  => 'Approved',
    'returned'  => 'Returned',
    'all'       => 'Everything',
];

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'needs';
if (!isset($views[$view])) $view = 'needs';
$platoon = (is_string($_GET['platoon'] ?? null) && ctype_digit($_GET['platoon'])) ? (int)$_GET['platoon'] : 0;

// ---------- Tab counts ----------
$tab = ['needs' => 0, 'submitted' => 0, 'approved' => 0, 'returned' => 0];
if ($term_id) {
    $stmt = $pdo->prepare("
        SELECT SUM(sub.state = 'submitted' AND sub.$my_col IS NULL) AS needs,
               SUM(sub.state = 'submitted') AS submitted,
               SUM(sub.state = 'approved')  AS approved,
               SUM(sub.state = 'returned')  AS returned
        FROM attendance_submissions sub
        JOIN training_sessions ts ON ts.id = sub.session_id
        WHERE ts.term_id = ?
    ");
    $stmt->execute([$term_id]);
    $row = $stmt->fetch();
    foreach ($tab as $k => $_) $tab[$k] = (int)($row[$k] ?? 0);
}
$tab['all'] = $tab['submitted'] + $tab['approved'] + $tab['returned'];

// ---------- Queue ----------
$rows = [];
if ($term_id) {
    $where = ["ts.term_id = :term", "sub.state <> 'draft'"];
    $params = ['term' => $term_id];
    if ($view === 'needs')          { $where[] = "sub.state = 'submitted' AND sub.$my_col IS NULL"; }
    elseif ($view === 'submitted')  { $where[] = "sub.state = 'submitted'"; }
    elseif ($view === 'approved')   { $where[] = "sub.state = 'approved'"; }
    elseif ($view === 'returned')   { $where[] = "sub.state = 'returned'"; }
    if ($platoon > 0) { $where[] = 'sub.platoon_id = :platoon'; $params['platoon'] = $platoon; }

    $order = in_array($view, ['needs', 'submitted'], true)
        ? 'sub.submitted_at ASC, sub.id ASC'      // oldest first
        : 'sub.updated_at DESC, sub.id DESC';

    $stmt = $pdo->prepare("
        SELECT sub.id, sub.state, sub.submitted_at, sub.remarks,
               sub.battalion_approved_at, sub.brigade_approved_at,
               ts.label, ts.session_date,
               pl.name AS platoon_name, co.name AS company_name,
               su.first_name AS sub_first, su.last_name AS sub_last,
               bu.first_name AS bn_first, bu.last_name AS bn_last,
               gu.first_name AS br_first, gu.last_name AS br_last,
               COALESCE(cnt.cp, 0) AS cp, COALESCE(cnt.ca, 0) AS ca,
               COALESCE(cnt.cl, 0) AS cl, COALESCE(cnt.ce, 0) AS ce,
               COALESCE(tot.n, 0) AS total_cadets
        FROM attendance_submissions sub
        JOIN training_sessions ts ON ts.id = sub.session_id
        JOIN platoons pl ON pl.id = sub.platoon_id
        JOIN companies co ON co.id = pl.company_id
        LEFT JOIN users su ON su.id = sub.submitted_by
        LEFT JOIN users bu ON bu.id = sub.battalion_approved_by
        LEFT JOIN users gu ON gu.id = sub.brigade_approved_by
        LEFT JOIN (
            SELECT ar.session_id, en.platoon_id,
                   SUM(ar.status = 'P') AS cp, SUM(ar.status = 'A') AS ca,
                   SUM(ar.status = 'L') AS cl, SUM(ar.status = 'E') AS ce
            FROM attendance_records ar
            JOIN cadets c ON c.id = ar.cadet_id AND c.status = 'active'
            JOIN training_sessions ts2 ON ts2.id = ar.session_id
            JOIN enrollments en ON en.cadet_id = ar.cadet_id AND en.term_id = ts2.term_id
            GROUP BY ar.session_id, en.platoon_id
        ) cnt ON cnt.session_id = sub.session_id AND cnt.platoon_id = sub.platoon_id
        LEFT JOIN (
            SELECT en.platoon_id, en.term_id, COUNT(*) AS n
            FROM enrollments en
            JOIN cadets c ON c.id = en.cadet_id AND c.status = 'active'
            WHERE en.platoon_id IS NOT NULL
            GROUP BY en.platoon_id, en.term_id
        ) tot ON tot.platoon_id = sub.platoon_id AND tot.term_id = ts.term_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order
        LIMIT 200
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$platoons = $pdo->query("
    SELECT p.id, p.name, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();

function person(?string $first, ?string $last): string {
    return trim((string)$first . ' ' . (string)$last);
}

$page_title = 'Review Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Review Attendance</h1>
    <p>Sessions submitted by Platoon Leaders. A session is recorded once <strong>one Battalion S1</strong> and the <strong>Brigade S1</strong> have both approved it, in either order.
        <?php if ($active_term): ?>Active term: <strong><?= e($active_term['name']) ?></strong>.<?php endif; ?></p>
</div>

<?php if (!$active_term): ?>
    <div class="summary-card" style="max-width: 650px;">
        <h3 style="color: var(--warning);">No active term</h3>
        <p style="font-size: 13px; color: var(--gray-700);">Ask the Admin to activate a term. Submissions are reviewed per term.</p>
    </div>
<?php else: ?>

    <div class="summary-card" style="margin-bottom: 16px;">
        <form method="GET" action="<?= BASE_URL ?>/s1/review_attendance.php">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <div class="form-group" style="margin: 0;">
                    <label for="view">Show</label>
                    <select id="view" name="view" class="form-control">
                        <?php foreach ($views as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $view === $key ? 'selected' : '' ?>><?= e($label) ?> (<?= (int)$tab[$key] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="platoon">Platoon</label>
                    <select id="platoon" name="platoon" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($platoons as $pl): ?>
                            <option value="<?= (int)$pl['id'] ?>" <?= $platoon === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['company_name'] . ' - ' . $pl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?= BASE_URL ?>/s1/review_attendance.php" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Company / Platoon</th>
                    <th>Marked</th>
                    <th>Submitted</th>
                    <th>Approvals</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7">
                        <?= $view === 'needs' ? 'Nothing is waiting for your review.' : 'No submissions match this view.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $mine_done = ($r[$my_col] !== null);
                        $needs_me = ($r['state'] === 'submitted' && !$mine_done);
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($r['label']) ?></strong><br>
                            <span style="font-size: 12px; color: var(--gray-700);"><?= e(date('M d, Y', strtotime($r['session_date']))) ?></span>
                        </td>
                        <td><?= e($r['company_name']) ?> - <?= e($r['platoon_name']) ?></td>
                        <td style="font-size: 13px;">
                            P <?= (int)$r['cp'] ?> &middot; A <?= (int)$r['ca'] ?> &middot; L <?= (int)$r['cl'] ?> &middot; E <?= (int)$r['ce'] ?>
                            <br><span style="color: var(--gray-700);">of <?= (int)$r['total_cadets'] ?> cadets</span>
                        </td>
                        <td style="font-size: 13px;">
                            <?= $r['submitted_at'] ? e(date('M d, Y g:i A', strtotime($r['submitted_at']))) : '&mdash;' ?>
                            <?php if (person($r['sub_first'], $r['sub_last']) !== ''): ?>
                                <br><span style="color: var(--gray-700);">by <?= e(person($r['sub_first'], $r['sub_last'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 13px;">
                            Battalion:
                            <?php if ($r['battalion_approved_at'] !== null): ?>
                                <strong>Approved</strong><?= person($r['bn_first'], $r['bn_last']) !== '' ? ' (' . e(person($r['bn_first'], $r['bn_last'])) . ')' : '' ?>
                            <?php else: ?>Pending<?php endif; ?>
                            <br>
                            Brigade:
                            <?php if ($r['brigade_approved_at'] !== null): ?>
                                <strong>Approved</strong><?= person($r['br_first'], $r['br_last']) !== '' ? ' (' . e(person($r['br_first'], $r['br_last'])) . ')' : '' ?>
                            <?php else: ?>Pending<?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= e($r['state']) ?>"><?= e(ucfirst($r['state'])) ?></span></td>
                        <td>
                            <a href="<?= BASE_URL ?>/s1/review_session.php?id=<?= (int)$r['id'] ?>"
                               class="btn btn-sm <?= $needs_me ? 'btn-primary' : 'btn-secondary' ?>"><?= $needs_me ? 'Review' : 'View' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (count($rows) === 200): ?>
        <p style="margin-top: 12px; font-size: 12px; color: var(--gray-700);">Showing the first 200. Use the filters to narrow the list.</p>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
