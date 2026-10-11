<?php
// Admin Audit Log Viewer (read-only)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_ui.php';

require_role('admin');

const AUDIT_PER_PAGE = 25;

// ---- Read filters ----
$search = trim($_GET['search'] ?? '');
$action = trim($_GET['action'] ?? '');
$entity = trim($_GET['entity'] ?? '');
$actor  = (int)($_GET['actor'] ?? 0);
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$ad_page   = max(1, (int)($_GET['page'] ?? 1));

// Only accept real YYYY-MM-DD dates
$valid_date = static function (string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
};
if ($from !== '' && !$valid_date($from)) $from = '';
if ($to !== '' && !$valid_date($to)) $to = '';

// ---- Build WHERE ----
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(a.details LIKE :search OR a.action LIKE :search OR a.entity LIKE :search OR u.username LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($action !== '') {
    $where[] = "a.action = :action";
    $params['action'] = $action;
}
if ($entity !== '') {
    $where[] = "a.entity = :entity";
    $params['entity'] = $entity;
}
if ($actor > 0) {
    $where[] = "a.actor_id = :actor";
    $params['actor'] = $actor;
}
if ($from !== '') {
    $where[] = "a.created_at >= :from_dt";
    $params['from_dt'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = "a.created_at <= :to_dt";
    $params['to_dt'] = $to . ' 23:59:59';
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ---- Count + page ----
$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON a.actor_id = u.id" . $where_sql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / AUDIT_PER_PAGE));
if ($ad_page > $total_pages) $ad_page = $total_pages;
$offset = ($ad_page - 1) * AUDIT_PER_PAGE;

$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.first_name, u.last_name, u.role
    FROM audit_log a
    LEFT JOIN users u ON a.actor_id = u.id
    $where_sql
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT " . AUDIT_PER_PAGE . " OFFSET " . $offset
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- Filter dropdown options ----
$actions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity FROM audit_log ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);
$actors = $pdo->query("
    SELECT DISTINCT u.id, u.username, u.first_name, u.last_name
    FROM audit_log a JOIN users u ON a.actor_id = u.id
    ORDER BY u.last_name, u.first_name
")->fetchAll();

$filters = [
    'search' => $search,
    'action' => $action,
    'entity' => $entity,
    'actor'  => $actor > 0 ? (string)$actor : '',
    'from'   => $from,
    'to'     => $to,
];
$query_base = array_filter($filters, static fn($v) => $v !== '');
$filters_active = !empty($query_base);
$ad_url = static function (array $q): string {
    return BASE_URL . '/admin/audit_log.php' . ($q ? '?' . http_build_query($q) : '');
};
$ad_page_url = static fn(int $p): string => $ad_url($query_base + ['page' => $p]);

// Removable "active filter" chips
$actor_name = '';
foreach ($actors as $a) {
    if ((int)$a['id'] === $actor) { $actor_name = $a['first_name'] . ' ' . $a['last_name']; }
}
$active_chips = [];
if ($search !== '') $active_chips['search'] = 'Search: ' . $search;
if ($actor > 0)     $active_chips['actor']  = 'User: ' . ($actor_name !== '' ? $actor_name : '#' . $actor);
if ($action !== '') $active_chips['action'] = 'Action: ' . ad_label($action);
if ($entity !== '') $active_chips['entity'] = 'Entity: ' . ad_label($entity);
if ($from !== '' || $to !== '') {
    $active_chips['date'] = 'Dates: ' . ($from !== '' ? date('M j, Y', strtotime($from)) : 'start') . ' to ' . ($to !== '' ? date('M j, Y', strtotime($to)) : 'now');
}
$ad_without = static function (string $key) use ($query_base): array {
    $q = $query_base;
    if ($key === 'date') { unset($q['from'], $q['to']); } else { unset($q[$key]); }
    return $q;
};

// Quick date ranges
$today = date('Y-m-d');
$presets = [
    'Today'        => ['from' => $today, 'to' => $today],
    'Last 7 days'  => ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today],
    'Last 30 days' => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today],
];
$base_no_dates = $query_base;
unset($base_no_dates['from'], $base_no_dates['to']);

// Group the page of rows by calendar day
$by_day = [];
foreach ($rows as $r) {
    $by_day[date('Y-m-d', strtotime($r['created_at']))][] = $r;
}
$ad_day_label = static function (string $d) use ($today): string {
    if ($d === $today) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('l, M j, Y', strtotime($d));
};

$first_shown = $total ? $offset + 1 : 0;
$last_shown = $offset + count($rows);

$page_title = 'Audit Log';
require_once __DIR__ . '/../includes/header.php';
ad_css();
?>
<div class="ad">
    <?php
    ad_head('Audit log', 'A read-only record of who changed what, and when. Nothing here can be edited or deleted.', '', 'audit');
    ad_guide('audit', 'Finding what you need', [
        ['Pick a time range', 'Use the quick ranges for recent activity, or open More filters for exact dates.'],
        ['Narrow it down', 'Search for a name or username, or filter by one person, action or record type.'],
        ['Read the entry', 'Colour shows the kind of change: green for approvals and creations, red for rejections and cancellations, amber for edits.'],
    ]);
    ?>

    <form action="<?= BASE_URL ?>/admin/audit_log.php" method="GET" class="ad-card ad-filterbar ad-rise" style="--i:2" data-autosubmit role="search">
        <div class="ad-filterrow">
            <div class="ad-search" style="flex: 1 1 280px;">
                <?= ad_icon('search', 16) ?>
                <input type="search" id="search" name="search" class="ad-input" value="<?= e($search) ?>" data-focus-key placeholder="Search names, actions or details" aria-label="Search the audit log">
                <kbd>/</kbd>
            </div>
            <div class="ad-chipbar" role="group" aria-label="Quick date range">
                <?php foreach ($presets as $label => $range):
                    $on = $from === $range['from'] && $to === $range['to'];
                ?>
                    <a class="ad-chip <?= $on ? 'is-on' : '' ?>" href="<?= e($ad_url($base_no_dates + $range)) ?>" <?= $on ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
        </div>

        <details class="ad-more" <?= ($actor > 0 || $action !== '' || $entity !== '' || (($from !== '' || $to !== '') && !array_filter($presets, static fn($r) => $r['from'] === $from && $r['to'] === $to))) ? 'open' : '' ?>>
            <summary><?= ad_icon('filter', 15) ?> More filters <?= ad_icon('chevron', 15, 'ad-caret') ?></summary>
            <div class="ad-more-grid">
                <div class="ad-field">
                    <label for="actor">User</label>
                    <select id="actor" name="actor" class="ad-input">
                        <option value="">All users</option>
                        <?php foreach ($actors as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= $actor === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['last_name'] . ', ' . $a['first_name'] . ' (' . $a['username'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ad-field">
                    <label for="action">Action</label>
                    <select id="action" name="action" class="ad-input">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e(ad_label($a)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ad-field">
                    <label for="entity">Record type</label>
                    <select id="entity" name="entity" class="ad-input">
                        <option value="">All types</option>
                        <?php foreach ($entities as $en): ?>
                            <option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e(ad_label($en)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ad-field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" class="ad-input" value="<?= e($from) ?>">
                </div>
                <div class="ad-field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" class="ad-input" value="<?= e($to) ?>">
                </div>
            </div>
        </details>
    </form>

    <?php if ($filters_active): ?>
        <div class="ad-active ad-rise" style="--i:3" aria-label="Active filters">
            <span class="ad-muted" style="font-size:13px;">Filtering by</span>
            <?php foreach ($active_chips as $key => $text): ?>
                <a class="ad-chip" href="<?= e($ad_url($ad_without($key))) ?>" aria-label="Remove filter: <?= e($text) ?>"><?= e($text) ?> <?= ad_icon('x', 14, 'ad-chip-x') ?></a>
            <?php endforeach; ?>
            <a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-ghost btn-sm">Clear all</a>
        </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="ad-empty ad-rise" style="--i:4">
            <?= ad_icon('list', 36) ?>
            <strong><?= $filters_active ? 'No entries match these filters' : 'Nothing recorded yet' ?></strong>
            <p><?= $filters_active ? 'Try a wider date range or remove a filter.' : 'Approvals, resets and calendar changes will be listed here as they happen.' ?></p>
            <?php if ($filters_active): ?><a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-secondary btn-sm">Clear filters</a><?php endif; ?>
        </div>
    <?php else: ?>
        <p class="ad-muted ad-rise" style="--i:4; font-size:13px; margin-bottom:14px;">
            Showing <strong><?= number_format($first_shown) ?>&ndash;<?= number_format($last_shown) ?></strong> of <strong><?= number_format($total) ?></strong> <?= $total === 1 ? 'entry' : 'entries' ?>, newest first.
        </p>

        <?php foreach ($by_day as $day => $entries): ?>
            <section class="ad-feed-day ad-rise" style="--i:5">
                <h3><?= e($ad_day_label($day)) ?> <small><?= e(ad_plural(count($entries), 'entry', 'entries')) ?></small></h3>
                <ol class="ad-feed">
                    <?php foreach ($entries as $r):
                        $tone = ad_audit_tone($r['action']);
                        $details = ad_audit_details($r['details']);
                    ?>
                        <li class="ad-entry t-<?= e($tone) ?>">
                            <span class="ad-entry-icon"><?= ad_icon(ad_audit_icon($r['action']), 17) ?></span>
                            <div class="ad-entry-main">
                                <span class="ad-entry-action"><?= e(ad_label($r['action'])) ?></span>
                                <span class="ad-ent"><?= e(ad_label($r['entity'])) ?><?= $r['entity_id'] !== null ? ' #' . (int)$r['entity_id'] : '' ?></span>
                                <div class="ad-entry-sub">
                                    by
                                    <?php if ($r['username'] !== null): ?>
                                        <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong> (<?= e($r['username']) ?> &middot; <?= e(format_role_name($r['role'])) ?>)
                                    <?php else: ?>
                                        <em>System or removed user</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <time class="ad-entry-time" datetime="<?= e($r['created_at']) ?>" title="<?= e(date('M j, Y g:i:s A', strtotime($r['created_at']))) ?>"><?= e(date('g:i A', strtotime($r['created_at']))) ?></time>
                            <?php if ($details !== ''): ?><div class="ad-entry-detail"><?= $details ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endforeach; ?>

        <?php if ($total_pages > 1):
            $window = [];
            for ($p = 1; $p <= $total_pages; $p++) {
                if ($p === 1 || $p === $total_pages || abs($p - $ad_page) <= 2) $window[] = $p;
            }
        ?>
            <nav class="ad-pager" aria-label="Pages">
                <a href="<?= e($ad_page_url(max(1, $ad_page - 1))) ?>" class="<?= $ad_page <= 1 ? 'is-disabled' : '' ?>" aria-label="Previous page"><?= ad_icon('arrow-left', 15) ?></a>
                <?php $prev = 0; foreach ($window as $p):
                    if ($prev && $p - $prev > 1): ?><span class="ad-gap">&hellip;</span><?php endif;
                    $prev = $p;
                    if ($p === $ad_page): ?><span class="is-here" aria-current="page"><?= $p ?></span>
                    <?php else: ?><a href="<?= e($ad_page_url($p)) ?>"><?= $p ?></a><?php endif;
                endforeach; ?>
                <a href="<?= e($ad_page_url(min($total_pages, $ad_page + 1))) ?>" class="<?= $ad_page >= $total_pages ? 'is-disabled' : '' ?>" aria-label="Next page"><?= ad_icon('arrow', 15) ?></a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php ad_js(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
