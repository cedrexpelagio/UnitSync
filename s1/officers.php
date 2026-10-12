<?php
// S1 Officer Roster: view, search, filter, paginate (Battalion S1 and Brigade S1 only)
// Every platoon always has a row: a platoon without an active Platoon Leader shows as "Vacant" with an Assign button.
// Also shows the generated Platoon Leader login credentials once and can generate missing logins.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/officer_accounts.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

// 6 companies x (1 commander + 4 platoons) = 30 positions, so one page holds the whole roster
const OFFICER_PAGE_SIZE = 50;

$role_labels = [
    'company_commander' => 'Company Commander',
    'platoon_leader'    => 'Platoon Leader',
];
$status_labels = [
    'active'   => 'Active',
    'vacant'   => 'Vacant',
    'inactive' => 'Inactive',
];

function off_int_param($v): int {
    return (is_string($v) && ctype_digit($v)) ? (int)$v : 0;
}

$roster_back = 's1/officers.php' . (!empty($_SESSION['officer_roster_qs']) ? '?' . $_SESSION['officer_roster_qs'] : '');

// ---------- POST actions: dismiss the credentials card, generate missing logins ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect($roster_back);
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'dismiss_credentials') {
        unset($_SESSION['officer_credentials']);
        set_flash('info', 'Temporary passwords hidden. Use Reset password in Admin > Users if one is lost.');
        redirect($roster_back);
    }

    if ($action === 'add_user_leader') {
        $uid = off_int_param($_POST['user_id'] ?? '');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT u.id, u.first_name, u.middle_name, u.last_name, p.id AS platoon_id, p.company_id
                FROM users u
                JOIN user_assignments ua ON ua.user_id = u.id
                JOIN platoons p ON p.id = ua.platoon_id
                WHERE u.id = ? AND u.role = 'platoon_leader' AND u.status = 'approved'
                  AND NOT EXISTS (SELECT 1 FROM officers o WHERE o.user_id = u.id)
                FOR UPDATE
            ");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            if (!$u) throw new DomainException('That Platoon Leader account was not found or is already in the roster.');

            $stmt = $pdo->prepare("SELECT last_name, first_name FROM officers WHERE role = 'platoon_leader' AND platoon_id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([(int)$u['platoon_id']]);
            $held = $stmt->fetch();
            if ($held) throw new DomainException('That platoon already has an active officer (' . $held['last_name'] . ', ' . $held['first_name'] . '). Deactivate or move that officer first.');

            $pdo->prepare("
                INSERT INTO officers (last_name, first_name, middle_name, role, company_id, platoon_id, user_id, status, created_by)
                VALUES (?, ?, ?, 'platoon_leader', ?, ?, ?, 'active', ?)
            ")->execute([
                $u['last_name'], $u['first_name'], ($u['middle_name'] ?? '') !== '' ? $u['middle_name'] : null,
                (int)$u['company_id'], (int)$u['platoon_id'], (int)$u['id'], (int)$user['id'],
            ]);
            $new_id = (int)$pdo->lastInsertId();
            log_audit($pdo, (int)$user['id'], 'add_officer_from_account', 'officers', $new_id,
                'Added registered Platoon Leader ' . officer_display_name($u) . ' (user #' . (int)$u['id'] . ') to the officer roster');
            $pdo->commit();
            set_flash('success', officer_display_name($u) . ' was added to the officer roster.');
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', $ex->getMessage());
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Could not add the officer, nothing was saved. ' . $ex->getMessage());
        }
        redirect($roster_back);
    }

    if ($action === 'generate_missing') {
        try {
            $pdo->beginTransaction();
            $todo = $pdo->query("
                SELECT id, last_name, first_name, middle_name, company_id, platoon_id
                FROM officers
                WHERE role = 'platoon_leader' AND status = 'active' AND user_id IS NULL
                ORDER BY last_name, first_name
            ")->fetchAll();

            $made = [];
            $notes = [];
            foreach ($todo as $o) {
                $res = officer_create_pl_account($pdo, $o, (int)$user['id']);
                if ($res['ok']) $made[] = $res['credential'];
                else $notes[] = $res['note'];
            }
            $pdo->commit();

            foreach ($made as $c) officer_credentials_stash($c);
            $generated = count(array_filter($made, fn($c) => $c['password'] !== null));
            $linked = count($made) - $generated;
            if ($generated) set_flash('success', "{$generated} login account(s) generated: copy the temporary passwords below, they are shown only here.");
            if ($linked) set_flash('info', "{$linked} officer(s) linked to a login account that already existed.");
            if (!$made && !$notes) set_flash('info', 'Every active Platoon Leader already has a login account.');
            if ($notes) set_flash('warning', 'No login account was generated for: ' . implode('; ', $notes) . '.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Could not generate logins, nothing was saved. ' . $ex->getMessage());
        }
        redirect($roster_back);
    }

    redirect($roster_back);
}

// ---------- CSV of the credentials currently shown ----------
if (isset($_GET['credentials'])) {
    $creds = $_SESSION['officer_credentials'] ?? [];
    if (!$creds) {
        set_flash('info', 'There are no temporary passwords to download.');
        redirect($roster_back);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="officer_logins_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Officer', 'Platoon', 'Username', 'Temporary Password']);
    foreach ($creds as $c) {
        fputcsv($out, [$c['officer'], $c['platoon'], $c['username'], $c['password'] ?? 'Existing account (' . ($c['status'] ?? 'approved') . ')']);
    }
    fclose($out);
    exit;
}

// ---------- Read filters from the query string ----------
$q       = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$role    = is_string($_GET['role'] ?? null) ? $_GET['role'] : '';
$company = off_int_param($_GET['company'] ?? '');
$platoon = off_int_param($_GET['platoon'] ?? '');
$status  = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$page    = max(1, off_int_param($_GET['page'] ?? '1'));

if (!isset($role_labels[$role])) $role = '';
if (!isset($status_labels[$status])) $status = '';

// ---------- One list of positions: officers + vacant Platoon Leader slots ----------
// A platoon with no ACTIVE Platoon Leader is added as a "vacant" row, so every platoon is always visible.
$positions_sql = "
    SELECT o.id AS officer_id, o.last_name, o.first_name, o.middle_name,
           CONCAT(o.last_name, ', ', o.first_name, IF(o.middle_name IS NULL OR o.middle_name = '', '', CONCAT(' ', o.middle_name))) AS full_name,
           CONCAT_WS(' ', o.first_name, o.last_name) AS alt_name,
           CAST(o.role AS CHAR) AS role, o.company_id AS company_id, co.name AS company_name,
           o.platoon_id AS platoon_id, p.name AS platoon_name,
           CAST(o.status AS CHAR) AS status_key, u.username AS login_username, u.status AS login_status, o.user_id AS user_id
    FROM officers o
    JOIN companies co ON co.id = o.company_id
    LEFT JOIN platoons p ON p.id = o.platoon_id
    LEFT JOIN users u ON u.id = o.user_id
    UNION ALL
    SELECT NULL, u.last_name, u.first_name, u.middle_name,
           CONCAT(u.last_name, ', ', u.first_name, IF(u.middle_name IS NULL OR u.middle_name = '', '', CONCAT(' ', u.middle_name))),
           CONCAT_WS(' ', u.first_name, u.last_name),
           'platoon_leader', p.company_id, co.name, p.id, p.name, 'active', u.username, u.status, u.id
    FROM users u
    JOIN user_assignments ua ON ua.user_id = u.id
    JOIN platoons p ON p.id = ua.platoon_id
    JOIN companies co ON co.id = p.company_id
    WHERE u.role = 'platoon_leader' AND u.status = 'approved'
      AND NOT EXISTS (SELECT 1 FROM officers x WHERE x.user_id = u.id)
    UNION ALL
    SELECT NULL, '', '', NULL, '', '',
           'platoon_leader', p.company_id, co.name, p.id, p.name, 'vacant', NULL, NULL, NULL
    FROM platoons p
    JOIN companies co ON co.id = p.company_id
    WHERE NOT EXISTS (
        SELECT 1 FROM officers v
        WHERE v.role = 'platoon_leader' AND v.platoon_id = p.id AND v.status = 'active'
    )
    AND NOT EXISTS (
        SELECT 1 FROM users vu
        JOIN user_assignments vua ON vua.user_id = vu.id
        WHERE vu.role = 'platoon_leader' AND vu.status = 'approved' AND vua.platoon_id = p.id
    )
";

// ---------- Build WHERE ----------
$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(x.full_name LIKE :q1 OR x.alt_name LIKE :q2)";
    $params['q1'] = '%' . addcslashes($q, '%_\\') . '%';
    $params['q2'] = $params['q1'];
}
if ($role !== '')   { $where[] = 'x.role = :role';          $params['role'] = $role; }
if ($company > 0)   { $where[] = 'x.company_id = :company'; $params['company'] = $company; }
if ($platoon > 0)   { $where[] = 'x.platoon_id = :platoon'; $params['platoon'] = $platoon; }
// Filters without the status choice, used for the status tab counts
$where_base = $where;
$params_base = $params;
if ($status !== '') { $where[] = 'x.status_key = :status';  $params['status'] = $status; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------- Count and page ----------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ($positions_sql) x $where_sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Count per status under the other filters (drives the status tabs)
$stmt = $pdo->prepare("
    SELECT x.status_key AS st, COUNT(*) AS n
    FROM ($positions_sql) x
    " . ($where_base ? 'WHERE ' . implode(' AND ', $where_base) : '') . "
    GROUP BY x.status_key
");
$stmt->execute($params_base);
$status_counts = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
$status_counts_all = array_sum($status_counts);

$pages = max(1, (int)ceil($total / OFFICER_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * OFFICER_PAGE_SIZE;
$limit = (int)OFFICER_PAGE_SIZE;

$stmt = $pdo->prepare("
    SELECT x.*
    FROM ($positions_sql) x
    $where_sql
    ORDER BY x.company_name ASC, (x.role = 'platoon_leader') ASC, x.platoon_name ASC,
             (x.status_key = 'inactive') ASC, x.last_name ASC, x.first_name ASC, x.officer_id ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$officers = $stmt->fetchAll();

// Platoon coverage for the summary line
$pl_total  = (int)$pdo->query("SELECT COUNT(*) FROM platoons")->fetchColumn();
$pl_filled = (int)$pdo->query("SELECT COUNT(DISTINCT x.platoon_id) FROM ($positions_sql) x WHERE x.role = 'platoon_leader' AND x.status_key = 'active'")->fetchColumn();

// ---------- Dropdown data ----------
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$platoons  = $pdo->query("
    SELECT p.id, p.name, p.company_id, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();

// ---------- Remember filters so Edit can return to the same view ----------
$filters = array_filter([
    'q' => $q, 'role' => $role, 'company' => $company ?: '', 'platoon' => $platoon ?: '', 'status' => $status,
], fn($v) => $v !== '');
$_SESSION['officer_roster_qs'] = http_build_query($filters + ($page > 1 ? ['page' => $page] : []));

function officer_roster_url(array $filters, int $page): string {
    if ($page > 1) $filters['page'] = $page;
    $qs = http_build_query($filters);
    return BASE_URL . '/s1/officers.php' . ($qs !== '' ? '?' . $qs : '');
}

// URL of the roster with ONE filter removed (page resets to 1)
function officer_roster_without(array $filters, string $key): string {
    unset($filters[$key]);
    $qs = http_build_query($filters);
    return BASE_URL . '/s1/officers.php' . ($qs !== '' ? '?' . $qs : '');
}

// ---------- Active filter chips ----------
$company_names = array_column($companies, 'name', 'id');
$platoon_names = [];
foreach ($platoons as $pl) {
    $platoon_names[$pl['id']] = $pl['company_name'] . ' - ' . $pl['name'];
}

$chips = [];
if ($q !== '')       $chips['q']       = 'Name: ' . $q;
if ($role !== '')    $chips['role']    = 'Role: ' . $role_labels[$role];
if ($company > 0)    $chips['company'] = 'Company: ' . ($company_names[$company] ?? $company);
if ($platoon > 0)    $chips['platoon'] = 'Platoon: ' . ($platoon_names[$platoon] ?? $platoon);
if ($status !== '')  $chips['status']  = 'Status: ' . $status_labels[$status];

// The Filters button counts everything except the search box (that is always visible)
$filter_count = count($chips) - (isset($chips['q']) ? 1 : 0);

$missing_logins = (int)$pdo->query("SELECT COUNT(*) FROM officers WHERE role = 'platoon_leader' AND status = 'active' AND user_id IS NULL")->fetchColumn();
$credentials = $_SESSION['officer_credentials'] ?? [];

$from = $total === 0 ? 0 : $offset + 1;
$to = min($offset + OFFICER_PAGE_SIZE, $total);

$page_title = 'Officer Roster';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/roster.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">
<script>document.documentElement.classList.add('no-js');</script>

<div class="content-header">
    <h1>Officer Roster</h1>
    <p>All Company Commanders and Platoon Leaders, including platoons that have no leader yet. Edit details, reassign a Platoon Leader or deactivate an officer.</p>
</div>

<?php if ($credentials): ?>
    <div class="summary-card" style="margin-bottom: 16px;">
        <h3>New login accounts</h3>
        <p>Give each Platoon Leader their username and temporary password. These passwords are shown only here and cannot be looked up later. Each officer must choose a new password at first login. Officers linked to an account that already existed keep their own password.</p>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Officer</th>
                        <th>Platoon</th>
                        <th>Username</th>
                        <th>Temporary Password</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($credentials as $c): ?>
                        <tr>
                            <td><?= e($c['officer']) ?></td>
                            <td><?= e($c['platoon']) ?></td>
                            <td><strong><?= e($c['username']) ?></strong></td>
                            <td>
                                <?php if ($c['password'] !== null): ?>
                                    <code><?= e($c['password']) ?></code>
                                <?php else: ?>
                                    <span class="rt-muted">Existing account (<?= e($c['status'] ?? 'approved') ?>): the officer already has a password</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top: 12px;">
            <a href="<?= BASE_URL ?>/s1/officers.php?credentials=1" class="btn btn-secondary">Download as CSV</a>
            <form action="<?= BASE_URL ?>/s1/officers.php" method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dismiss_credentials">
                <button type="submit" class="btn btn-primary">I have saved them, hide</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<form method="GET" action="<?= BASE_URL ?>/s1/officers.php" id="rosterFilters" role="search" aria-label="Filter officers">

    <div class="rf-toolbar">
        <!-- Search (always visible; press Enter to search) -->
        <div class="rf-search">
            <svg class="rf-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" id="q" name="q" class="form-control" value="<?= e($q) ?>"
                   placeholder="Search name, e.g. Dela Cruz" autocomplete="off" aria-label="Search name">
            <button type="button" class="rf-search-clear" id="qClear" aria-label="Clear search">&times;</button>
        </div>

        <!-- The single Filters button + its panel -->
        <div class="rf-wrap">
            <button type="button" id="rfToggle" class="btn btn-secondary rf-toggle <?= $filter_count > 0 ? 'has-filters' : '' ?>"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="rfPanel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5z"/></svg>
                Filters
                <span class="rf-count" id="rfCount" <?= $filter_count === 0 ? 'hidden' : '' ?>><?= (int)$filter_count ?></span>
            </button>

            <div class="rf-backdrop" id="rfBackdrop"></div>

            <div class="rf-panel" id="rfPanel" role="dialog" aria-label="Filter officers">
                <div class="rf-panel-head">
                    <span class="rf-panel-title">Filters</span>
                    <button type="button" class="rf-panel-close" id="rfClose" aria-label="Close filters">&times;</button>
                </div>

                <div class="rf-panel-body">
                    <div class="rf-grid">
                        <div class="rf-field">
                            <label for="role">Role</label>
                            <select id="role" name="role" class="form-control <?= $role !== '' ? 'is-set' : '' ?>">
                                <option value="">All roles</option>
                                <?php foreach ($role_labels as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $role === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rf-field">
                            <label for="company">Company</label>
                            <select id="company" name="company" class="form-control <?= $company > 0 ? 'is-set' : '' ?>">
                                <option value="">All companies</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= $company === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rf-field">
                            <label for="platoon">Platoon</label>
                            <select id="platoon" name="platoon" class="form-control <?= $platoon > 0 ? 'is-set' : '' ?>">
                                <option value="">All platoons</option>
                                <?php foreach ($platoons as $pl): ?>
                                    <option value="<?= (int)$pl['id'] ?>" data-company="<?= (int)$pl['company_id'] ?>" <?= $platoon === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['company_name'] . ' - ' . $pl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="rf-hint" id="platoonHint" hidden>Showing platoons of the selected company.</span>
                        </div>

                        <div class="rf-field rf-field-full" role="radiogroup" aria-label="Status">
                            <span class="rf-group-label">Status</span>
                            <div class="rf-pills">
                                <label class="rf-pill">
                                    <input type="radio" name="status" value="" <?= $status === '' ? 'checked' : '' ?>>
                                    <span>All</span>
                                </label>
                                <?php foreach ($status_labels as $key => $label): ?>
                                    <label class="rf-pill">
                                        <input type="radio" name="status" value="<?= e($key) ?>" <?= $status === $key ? 'checked' : '' ?>>
                                        <span><?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rf-panel-foot">
                    <button type="button" class="rf-linkbtn" id="rfClear">Clear filters</button>
                    <div class="rf-foot-right">
                        <button type="submit" class="btn btn-primary">Apply filters</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($chips): ?>
    <div class="rf-chips" aria-label="Active filters">
        <span class="rf-chips-label">Active filters:</span>
        <?php foreach ($chips as $key => $text): ?>
            <span class="rf-chip">
                <?= e($text) ?>
                <a class="rf-chip-x" href="<?= e(officer_roster_without($filters, $key)) ?>"
                   aria-label="Remove filter <?= e($text) ?>" title="Remove">&times;</a>
            </span>
        <?php endforeach; ?>
        <a class="rf-clear-all" href="<?= BASE_URL ?>/s1/officers.php">Clear all</a>
    </div>
<?php endif; ?>

<div class="rt-bar">
    <span>Showing <strong><?= $from ?>-<?= $to ?></strong> of <strong><?= $total ?></strong> position(s)
        &middot; <strong><?= $pl_filled ?></strong> of <strong><?= $pl_total ?></strong> platoons have a Platoon Leader</span>
    <span class="rt-bar-links" style="display:inline-flex;align-items:center;gap:14px;">
        <?php if ($missing_logins > 0): ?>
            <form action="<?= BASE_URL ?>/s1/officers.php" method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_missing">
                <button type="submit" class="btn btn-sm btn-secondary">Generate missing logins (<?= $missing_logins ?>)</button>
            </form>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/s1/import_officers.php">Import officers (CSV)</a>
    </span>
</div>

<nav class="s1-tabs" aria-label="Filter by status">
    <a class="s1-tab <?= $status === '' ? 'active' : '' ?>" href="<?= e(officer_roster_without($filters, 'status')) ?>">All <b><?= (int)$status_counts_all ?></b></a>
    <?php foreach ($status_labels as $key => $label): ?>
        <a class="s1-tab <?= $status === $key ? 'active' : '' ?> <?= $key === 'vacant' && ($status_counts[$key] ?? 0) > 0 ? 's1-tab-warn' : '' ?>"
           href="<?= e(officer_roster_url(['status' => $key] + $filters, 1)) ?>"><?= e($label) ?> <b><?= (int)($status_counts[$key] ?? 0) ?></b></a>
    <?php endforeach; ?>
</nav>

<div class="table-responsive rt-wrap s1-rt">
    <table class="data-table">
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Role</th>
                <th>Company</th>
                <th>Platoon</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$officers): ?>
                <tr>
                    <td colspan="6" class="rt-empty">
                        <strong>No officers found</strong>
                        <?php if ($chips): ?>
                            Try removing a filter or <a href="<?= BASE_URL ?>/s1/officers.php">reset all filters</a>.
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>/s1/import_officers.php">Import officers from a CSV file</a> to get started.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($officers as $o): ?>
                <?php
                    $is_vacant = ($o['status_key'] === 'vacant');
                    $is_pl     = ($o['role'] === 'platoon_leader');
                    $account_only = (!$is_vacant && $o['officer_id'] === null); // registered account with no officer record yet
                    $initials  = $is_vacant ? '?' : mb_strtoupper(mb_substr((string)$o['first_name'], 0, 1) . mb_substr((string)$o['last_name'], 0, 1));
                ?>
                <tr class="<?= $is_vacant ? 's1-row-attn' : '' ?>">
                    <td class="rt-name">
                        <div class="s1-person">
                            <span class="s1-avatar <?= $is_vacant ? 's1-avatar-vacant' : '' ?>" aria-hidden="true"><?= e($initials) ?></span>
                            <div>
                                <?php if ($is_vacant): ?>
                                    <div class="s1-person-name rt-muted">Vacant</div>
                                    <div class="s1-person-sub">No active Platoon Leader</div>
                                <?php else: ?>
                                    <div class="s1-person-name"><?= e($o['full_name']) ?></div>
                                    <div class="s1-person-sub">
                                        <?php if ($account_only): ?>
                                            <?= e($o['login_username']) ?> (registered account)
                                        <?php elseif ($o['login_username'] !== null): ?>
                                            <?= e($o['login_username']) ?><?= in_array($o['login_status'], ['deactivated', 'pending'], true) ? ' (' . e($o['login_status'] === 'pending' ? 'pending approval' : 'deactivated') . ')' : '' ?>
                                        <?php elseif ($is_pl): ?>
                                            No login account
                                        <?php else: ?>
                                            No login needed
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><span class="s1-prog"><?= e($role_labels[$o['role']] ?? $o['role']) ?></span></td>
                    <td><?= e($o['company_name']) ?></td>
                    <td><?= $o['platoon_name'] !== null ? e($o['platoon_name']) : '<span class="rt-muted">&mdash;</span>' ?></td>
                    <td><span class="s1-status s1-status-<?= e($o['status_key']) ?>"><i></i><?= e($status_labels[$o['status_key']] ?? $o['status_key']) ?></span></td>
                    <td>
                        <div class="s1-actions">
                            <?php if ($is_vacant): ?>
                                <a href="<?= BASE_URL ?>/s1/reassign_officer.php?platoon=<?= (int)$o['platoon_id'] ?>" class="btn btn-sm btn-primary">Assign</a>
                            <?php elseif ($account_only): ?>
                                <form action="<?= BASE_URL ?>/s1/officers.php" method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_user_leader">
                                    <input type="hidden" name="user_id" value="<?= (int)$o['user_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Add to roster</button>
                                </form>
                            <?php else: ?>
                                <a href="<?= BASE_URL ?>/s1/edit_officer.php?id=<?= (int)$o['officer_id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                                <?php if ($is_pl): ?>
                                    <a href="<?= BASE_URL ?>/s1/reassign_officer.php?id=<?= (int)$o['officer_id'] ?>" class="btn btn-sm btn-secondary">Reassign</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
    <div class="rt-pager">
        <?php if ($page > 1): ?>
            <a href="<?= e(officer_roster_url($filters, $page - 1)) ?>" class="btn btn-sm btn-secondary">&laquo; Previous</a>
        <?php else: ?>
            <span class="btn btn-sm btn-secondary" aria-disabled="true">&laquo; Previous</span>
        <?php endif; ?>

        <span>Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong></span>

        <?php if ($page < $pages): ?>
            <a href="<?= e(officer_roster_url($filters, $page + 1)) ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
        <?php else: ?>
            <span class="btn btn-sm btn-secondary" aria-disabled="true">Next &raquo;</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/roster.js" defer></script>
<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>