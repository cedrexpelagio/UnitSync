<?php
// Admin Audit Log Viewer (read-only)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

const AUDIT_PER_PAGE = 25;

// ---- Read filters ----
$search = trim($_GET['search'] ?? '');
$action = trim($_GET['action'] ?? '');
$entity = trim($_GET['entity'] ?? '');
$actor  = (int)($_GET['actor'] ?? 0);
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));

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
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * AUDIT_PER_PAGE;

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

$label = static function (string $s): string {
    return ucwords(str_replace('_', ' ', $s));
};

$filters_active = ($search !== '' || $action !== '' || $entity !== '' || $actor > 0 || $from !== '' || $to !== '');
$query_base = array_filter([
    'search' => $search,
    'action' => $action,
    'entity' => $entity,
    'actor'  => $actor > 0 ? $actor : '',
    'from'   => $from,
    'to'     => $to,
], static fn($v) => $v !== '');
$page_url = static function (int $p) use ($query_base): string {
    return BASE_URL . '/admin/audit_log.php?' . http_build_query($query_base + ['page' => $p]);
};

$page_title = 'Audit Log';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Audit Log</h1>
    <p>A read-only record of who changed what, and when</p>
</div>

<form action="<?= BASE_URL ?>/admin/audit_log.php" method="GET" class="card" style="margin-bottom: 24px;">
    <div class="form-row">
        <div class="form-group">
            <label for="search">Search</label>
            <input type="text" id="search" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Details, action, or user...">
        </div>
        <div class="form-group">
            <label for="actor">User</label>
            <select id="actor" name="actor" class="form-control">
                <option value="">All users</option>
                <?php foreach ($actors as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $actor === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= e($a['last_name'] . ', ' . $a['first_name'] . ' (' . $a['username'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="action">Action</label>
            <select id="action" name="action" class="form-control">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($label($a)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="entity">Entity</label>
            <select id="entity" name="entity" class="form-control">
                <option value="">All entities</option>
                <?php foreach ($entities as $en): ?>
                    <option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e($label($en)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="from">From</label>
            <input type="date" id="from" name="from" class="form-control" value="<?= e($from) ?>">
        </div>
        <div class="form-group">
            <label for="to">To</label>
            <input type="date" id="to" name="to" class="form-control" value="<?= e($to) ?>">
        </div>
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
        <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
        <?php if ($filters_active): ?>
            <a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
        <span style="margin-left: auto; font-size: 13px; color: var(--gray-700);">
            <?= number_format($total) ?> <?= $total === 1 ? 'entry' : 'entries' ?>
        </span>
    </div>
</form>

<?php if (empty($rows)): ?>
    <div class="empty-state">
        <p><?= $filters_active ? 'No audit entries match your filters.' : 'No audit entries have been recorded yet.' ?></p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="white-space: nowrap;">When</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="white-space: nowrap;"><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                        <td>
                            <?php if ($r['username'] !== null): ?>
                                <strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong><br>
                                <small style="color: var(--gray-700);"><?= e($r['username']) ?> &middot; <?= e(format_role_name($r['role'])) ?></small>
                            <?php else: ?>
                                <em style="color: var(--gray-700);">System / removed user</em>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-inactive"><?= e($label($r['action'])) ?></span></td>
                        <td>
                            <?= e($label($r['entity'])) ?>
                            <?php if ($r['entity_id'] !== null): ?>
                                <small style="color: var(--gray-700);">#<?= (int)$r['entity_id'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="max-width: 420px; word-break: break-word;"><?= $r['details'] !== null && $r['details'] !== '' ? e($r['details']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <div style="display: flex; gap: 12px; align-items: center; justify-content: center; margin-top: 20px;">
            <?php if ($page > 1): ?>
                <a href="<?= e($page_url($page - 1)) ?>" class="btn btn-secondary btn-sm">&larr; Previous</a>
            <?php endif; ?>
            <span style="font-size: 13px; color: var(--gray-700);">Page <?= $page ?> of <?= $total_pages ?></span>
            <?php if ($page < $total_pages): ?>
                <a href="<?= e($page_url($page + 1)) ?>" class="btn btn-secondary btn-sm">Next &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
