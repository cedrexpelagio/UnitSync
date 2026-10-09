<?php
// S1 Officer Roster: view, search, filter, paginate (Battalion S1 and Brigade S1 only)
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

const OFFICER_PAGE_SIZE = 25;

$role_labels = [
    'company_commander' => 'Company Commander',
    'platoon_leader'    => 'Platoon Leader',
];
$status_labels = [
    'active'   => 'Active',
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

// ---------- Build WHERE ----------
$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(CONCAT_WS(' ', o.last_name, o.first_name, o.middle_name) LIKE :q1 OR CONCAT_WS(' ', o.first_name, o.last_name) LIKE :q2)";
    $params['q1'] = '%' . addcslashes($q, '%_\\') . '%';
    $params['q2'] = $params['q1'];
}
if ($role !== '')   { $where[] = 'o.role = :role';          $params['role'] = $role; }
if ($company > 0)   { $where[] = 'o.company_id = :company'; $params['company'] = $company; }
if ($platoon > 0)   { $where[] = 'o.platoon_id = :platoon'; $params['platoon'] = $platoon; }
if ($status !== '') { $where[] = 'o.status = :status';      $params['status'] = $status; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------- Count and page ----------
$from_sql = "
    FROM officers o
    JOIN companies co ON co.id = o.company_id
    LEFT JOIN platoons p ON p.id = o.platoon_id
    LEFT JOIN users u ON u.id = o.user_id
";

$stmt = $pdo->prepare("SELECT COUNT(*) $from_sql $where_sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / OFFICER_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * OFFICER_PAGE_SIZE;
$limit = (int)OFFICER_PAGE_SIZE;

$stmt = $pdo->prepare("
    SELECT o.id, CONCAT(o.last_name, ', ', o.first_name, IF(o.middle_name IS NULL OR o.middle_name = '', '', CONCAT(' ', o.middle_name))) AS full_name,
           o.role, o.status, co.name AS company_name, p.name AS platoon_name,
           u.username AS login_username, u.status AS login_status
    $from_sql
    $where_sql
    ORDER BY co.name ASC, (o.role = 'platoon_leader') ASC, p.name ASC, o.last_name ASC, o.first_name ASC, o.id ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$officers = $stmt->fetchAll();

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
<script>document.documentElement.classList.add('no-js');</script>

<div class="content-header">
    <h1>Officer Roster</h1>
    <p>All Company Commanders and Platoon Leaders. Edit details, change an assignment or deactivate an officer.</p>
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
    <span>Showing <strong><?= $from ?>-<?= $to ?></strong> of <strong><?= $total ?></strong> officer(s)</span>
    <span class="rt-bar-links">
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

<div class="table-responsive rt-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Role</th>
                <th>Company</th>
                <th>Platoon</th>
                <th>Login</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$officers): ?>
                <tr>
                    <td colspan="7" class="rt-empty">
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
                <tr>
                    <td class="rt-name"><?= e($o['full_name']) ?></td>
                    <td><?= e($role_labels[$o['role']] ?? $o['role']) ?></td>
                    <td><?= e($o['company_name']) ?></td>
                    <td><?= $o['platoon_name'] !== null ? e($o['platoon_name']) : '<span class="rt-muted">&mdash;</span>' ?></td>
                    <td>
                        <?php if ($o['login_username'] !== null): ?>
                            <?= e($o['login_username']) ?><?= in_array($o['login_status'], ['deactivated', 'pending'], true) ? ' <span class="rt-muted">(' . e($o['login_status'] === 'pending' ? 'pending approval' : 'deactivated') . ')</span>' : '' ?>
                        <?php elseif ($o['role'] === 'platoon_leader'): ?>
                            <span class="rt-muted">No account</span>
                        <?php else: ?>
                            <span class="rt-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $o['status'] === 'active' ? 'badge-approved' : 'badge-deactivated' ?>"><?= e($status_labels[$o['status']] ?? $o['status']) ?></span></td>
                    <td><a href="<?= BASE_URL ?>/s1/edit_officer.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-secondary">Edit</a></td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>