<?php
// S1 Review Attendance: queue of submitted sessions (Battalion S1 and Brigade S1)
// Reads the same tables the Platoon Leader writes to; nothing is copied or moved.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/s1_ui.php';

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

// Oldest submission still waiting for THIS account (drives the "Review oldest first" button)
$next_id = 0;
if ($term_id && $tab['needs'] > 0) {
    $stmt = $pdo->prepare("
        SELECT sub.id FROM attendance_submissions sub
        JOIN training_sessions ts ON ts.id = sub.session_id
        WHERE ts.term_id = ? AND sub.state = 'submitted' AND sub.$my_col IS NULL
        ORDER BY sub.submitted_at ASC, sub.id ASC LIMIT 1
    ");
    $stmt->execute([$term_id]);
    $next_id = (int)$stmt->fetchColumn();
}

$self_url = BASE_URL . '/s1/review_attendance.php';
function ra_url(string $base, string $view, int $platoon): string {
    $qs = array_filter(['view' => $view === 'needs' ? '' : $view, 'platoon' => $platoon ?: ''], fn($v) => $v !== '');
    return $base . ($qs ? '?' . http_build_query($qs) : '');
}

$page_title = 'Review Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

<div class="content-header">
    <h1>Review Attendance</h1>
    <p>Sessions submitted by Platoon Leaders. A session is recorded once <strong>one Battalion S1</strong> and the <strong>Brigade S1</strong> have both approved it, in either order.
        <?php if ($active_term): ?>Active term: <strong><?= e($active_term['name']) ?></strong>.<?php endif; ?></p>
</div>

<?php if (!$active_term): ?>
    <div class="s1-alert s1-alert-warn">
        <div class="s1-alert-body"><strong>No active term.</strong> Ask the Admin to activate a term. Submissions are reviewed per term.</div>
    </div>
<?php else: ?>

    <?php if ($next_id): ?>
        <div class="s1-alert s1-alert-warn" style="margin-bottom: 16px;">
            <div class="s1-alert-body">
                <strong><?= (int)$tab['needs'] ?> session<?= $tab['needs'] === 1 ? '' : 's' ?> waiting for your review.</strong>
                Start with the oldest so Platoon Leaders are not kept waiting.
            </div>
            <a href="<?= BASE_URL ?>/s1/review_session.php?id=<?= $next_id ?>" class="btn btn-primary">Review oldest first &rarr;</a>
        </div>
    <?php elseif ($view === 'needs'): ?>
        <div class="s1-alert s1-alert-ok" style="margin-bottom: 16px;">
            <div class="s1-alert-body"><strong>You are all caught up.</strong> Nothing is waiting for your approval right now.</div>
        </div>
    <?php endif; ?>

    <nav class="s1-tabs" aria-label="Submission views">
        <?php foreach ($views as $key => $label): ?>
            <a class="s1-tab <?= $view === $key ? 'active' : '' ?> <?= $key === 'needs' && $tab['needs'] > 0 ? 's1-tab-warn' : '' ?>"
               href="<?= e(ra_url($self_url, $key, $platoon)) ?>"><?= e($label) ?> <b><?= (int)$tab[$key] ?></b></a>
        <?php endforeach; ?>
    </nav>

    <div class="s1-queue-bar">
        <form method="GET" action="<?= e($self_url) ?>">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <label for="platoon">Platoon</label>
            <select id="platoon" name="platoon" class="form-control <?= $platoon > 0 ? 'is-set' : '' ?>" data-s1-autosubmit style="width: auto; display: inline-block;">
                <option value="">All platoons</option>
                <?php foreach ($platoons as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>" <?= $platoon === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['company_name'] . ' - ' . $pl['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-secondary btn-sm">Filter</button></noscript>
            <?php if ($platoon > 0): ?><a class="s1-clear-all" href="<?= e(ra_url($self_url, $view, 0)) ?>">Clear</a><?php endif; ?>
        </form>
        <span class="s1-rv-count"><?= count($rows) ?> submission(s) shown</span>
    </div>

    <div class="s1-rv-wrap">
        <table class="s1-table s1-queue">
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Company / Platoon</th>
                    <th>Marks</th>
                    <th>Submitted</th>
                    <th>Approvals</th>
                    <th>State</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="s1-empty-ok">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        <strong><?= $view === 'needs' ? 'Nothing is waiting for your review.' : 'No submissions match this view.' ?></strong>
                        <?php if ($platoon > 0): ?><a href="<?= e(ra_url($self_url, $view, 0)) ?>">Show all platoons</a><?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $mine_done = ($r[$my_col] !== null);
                        $needs_me = ($r['state'] === 'submitted' && !$mine_done);
                        $cp = (int)$r['cp']; $ca = (int)$r['ca']; $cl = (int)$r['cl']; $ce = (int)$r['ce'];
                        $marked = $cp + $ca + $cl + $ce;
                        $total = (int)$r['total_cadets'];
                        $unmarked = max(0, $total - $marked);
                        $rate = s1_rate($cp, $cl, $ca);
                        $days = s1_age_days($r['submitted_at']);
                        $stale = ($r['state'] === 'submitted' && $days !== null && $days >= 3);
                    ?>
                    <tr class="<?= $needs_me ? 's1-row-needs' : '' ?>">
                        <td>
                            <div class="s1-cadet-name"><?= e($r['label']) ?></div>
                            <div class="s1-cadet-code"><?= e(date('M d, Y', strtotime($r['session_date']))) ?></div>
                        </td>
                        <td><?= e($r['company_name']) ?> &middot; <?= e($r['platoon_name']) ?></td>
                        <td>
                            <?= s1_stack(['p' => $cp, 'l' => $cl, 'a' => $ca, 'e' => $ce]) ?>
                            <div class="s1-cnts">
                                <span><span class="s1-mark s1-mark-sm s1-mark-P">P</span> <?= $cp ?></span>
                                <span><span class="s1-mark s1-mark-sm s1-mark-L">L</span> <?= $cl ?></span>
                                <span><span class="s1-mark s1-mark-sm s1-mark-A">A</span> <?= $ca ?></span>
                                <span><span class="s1-mark s1-mark-sm s1-mark-E">E</span> <?= $ce ?></span>
                                <?php if ($rate !== null): ?><span><?= number_format($rate, 0) ?>%</span><?php endif; ?>
                            </div>
                            <?php if ($unmarked > 0 && $r['state'] !== 'approved'): ?>
                                <div class="s1-queue-warn"><?= $unmarked ?> of <?= $total ?> not marked</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['submitted_at']): ?>
                                <span class="s1-wait <?= $stale ? 'is-old' : '' ?>"><?= e(s1_ago($r['submitted_at'])) ?></span>
                                <div class="s1-cadet-code"><?= e(date('M d, g:i A', strtotime($r['submitted_at']))) ?><?= person($r['sub_first'], $r['sub_last']) !== '' ? ' &middot; ' . e(person($r['sub_first'], $r['sub_last'])) : '' ?></div>
                            <?php else: ?>&mdash;<?php endif; ?>
                        </td>
                        <td>
                            <div class="s1-appr-wrap">
                                <?php foreach ([['Battalion', 'battalion', $r['battalion_approved_at'], person($r['bn_first'], $r['bn_last'])],
                                                ['Brigade', 'brigade', $r['brigade_approved_at'], person($r['br_first'], $r['br_last'])]] as [$lbl, $lv, $at, $who]): ?>
                                    <span class="s1-appr <?= $at !== null ? 'done' : '' ?>" title="<?= $at !== null ? e($who !== '' ? 'Approved by ' . $who : 'Approved') : 'Pending' ?>">
                                        <?= $lbl ?><?= $lv === $level ? ' (you)' : '' ?><?= $at !== null ? ': approved' : ': pending' ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><span class="badge badge-<?= e($r['state']) ?>"><?= e(ucfirst($r['state'])) ?></span></td>
                        <td style="text-align: right;">
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

<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
