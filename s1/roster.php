<?php
// S1 Cadet Roster: view, search, filter, paginate (Battalion S1 and Brigade S1 only)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

const ROSTER_PAGE_SIZE = 25;

// Active term: enrollments (company/platoon) are stored per term
$active_term_id = (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();

$status_labels = [
    'active'      => 'Active',
    'unassigned'  => 'Unassigned',
    'dropped'     => 'Dropped',
    'transferred' => 'Transferred',
    'graduated'   => 'Graduated',
];

function int_param($v): int {
    return (is_string($v) && ctype_digit($v)) ? (int)$v : 0;
}

// ---------- Read filters from the query string ----------
$q       = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$gender  = is_string($_GET['gender'] ?? null) ? $_GET['gender'] : '';
$program = is_string($_GET['program'] ?? null) ? trim($_GET['program']) : '';
$company = int_param($_GET['company'] ?? '');
$platoon = int_param($_GET['platoon'] ?? '');
$status  = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$page    = max(1, int_param($_GET['page'] ?? '1'));

if (!in_array($gender, ['Male', 'Female'], true)) $gender = '';
if (!isset($status_labels[$status])) $status = '';

// ---------- Build WHERE ----------
$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name) LIKE :q1 OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE :q2)";
    $params['q1'] = '%' . addcslashes($q, '%_\\') . '%';
    $params['q2'] = $params['q1'];
}
if ($gender !== '')  { $where[] = 'c.gender = :gender';       $params['gender'] = $gender; }
if ($program !== '') { $where[] = 'pr.code = :program';       $params['program'] = $program; }
if ($company > 0)    { $where[] = 'e.company_id = :company';  $params['company'] = $company; }
if ($platoon > 0)    { $where[] = 'e.platoon_id = :platoon';  $params['platoon'] = $platoon; }
// "Unassigned" is not stored: it means an active cadet with no platoon in the active term
if ($status === 'unassigned')   { $where[] = "c.status = 'active' AND e.platoon_id IS NULL"; }
elseif ($status === 'active')   { $where[] = "c.status = 'active' AND e.platoon_id IS NOT NULL"; }
elseif ($status !== '')         { $where[] = 'c.status = :status'; $params['status'] = $status; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------- Count and page ----------
$from_sql = "
    FROM cadets c
    JOIN programs pr ON pr.id = c.program_id
    LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = {$active_term_id}
    LEFT JOIN companies co ON e.company_id = co.id
    LEFT JOIN platoons p ON e.platoon_id = p.id
";

$stmt = $pdo->prepare("SELECT COUNT(*) $from_sql $where_sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / ROSTER_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * ROSTER_PAGE_SIZE;
$limit = (int)ROSTER_PAGE_SIZE;

$stmt = $pdo->prepare("
    SELECT c.id, CONCAT(c.last_name, ', ', c.first_name, IF(c.middle_name IS NULL OR c.middle_name = '', '', CONCAT(' ', c.middle_name))) AS full_name, c.gender, c.designation, pr.code AS program,
           CASE WHEN c.status = 'active' AND e.platoon_id IS NULL THEN 'unassigned' ELSE c.status END AS status,
           co.name AS company_name, p.name AS platoon_name
    $from_sql
    $where_sql
    ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$cadets = $stmt->fetchAll();

// ---------- Dropdown data ----------
$programs  = $pdo->query("SELECT code, name FROM programs ORDER BY code")->fetchAll();
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$platoons  = $pdo->query("
    SELECT p.id, p.name, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();

// ---------- Remember filters so Edit can return to the same view ----------
$filters = array_filter([
    'q' => $q, 'gender' => $gender, 'program' => $program,
    'company' => $company ?: '', 'platoon' => $platoon ?: '', 'status' => $status,
], fn($v) => $v !== '');
$_SESSION['roster_qs'] = http_build_query($filters + ($page > 1 ? ['page' => $page] : []));

function roster_url(array $filters, int $page): string {
    if ($page > 1) $filters['page'] = $page;
    $qs = http_build_query($filters);
    return BASE_URL . '/s1/roster.php' . ($qs !== '' ? '?' . $qs : '');
}

$from = $total === 0 ? 0 : $offset + 1;
$to = min($offset + ROSTER_PAGE_SIZE, $total);

$page_title = 'Cadet Roster';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Cadet Roster</h1>
    <p>All cadets in the database. Edit details and assign cadets to a company and platoon.</p>
</div>

<?php if (!$active_term_id): ?>
    <div class="alert alert-warning" style="margin-bottom: 16px;">No active term is set, so every cadet shows as Unassigned. An Administrator must activate a term.</div>
<?php endif; ?>

<div class="summary-card" style="margin-bottom: 16px;">
    <form method="GET" action="<?= BASE_URL ?>/s1/roster.php">
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
            <div class="form-group" style="margin: 0; min-width: 200px;">
                <label for="q">Search name</label>
                <input type="text" id="q" name="q" class="form-control" value="<?= e($q) ?>" placeholder="e.g. Dela Cruz">
            </div>
            <div class="form-group" style="margin: 0;">
                <label for="gender">Gender</label>
                <select id="gender" name="gender" class="form-control">
                    <option value="">All</option>
                    <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label for="program">Program</label>
                <select id="program" name="program" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= e($p['code']) ?>" <?= $program === $p['code'] ? 'selected' : '' ?>><?= e($p['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label for="company">Company</label>
                <select id="company" name="company" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $company === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
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
            <div class="form-group" style="margin: 0;">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($status_labels as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="<?= BASE_URL ?>/s1/roster.php" class="btn btn-secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<p style="margin-bottom: 8px; font-size: 14px;">
    Showing <strong><?= $from ?>-<?= $to ?></strong> of <strong><?= $total ?></strong> cadet(s)
    &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/s1/import_cadets.php">Import cadets (CSV)</a>
</p>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Gender</th>
                <th>Designation</th>
                <th>Program</th>
                <th>Company</th>
                <th>Platoon</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$cadets): ?>
                <tr><td colspan="8">No cadets match your filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($cadets as $c): ?>
                <?php
                    $badge = 'badge-deactivated';
                    if ($c['status'] === 'active') $badge = 'badge-approved';
                    elseif ($c['status'] === 'unassigned') $badge = 'badge-pending';
                ?>
                <tr>
                    <td><?= e($c['full_name']) ?></td>
                    <td><?= e($c['gender']) ?></td>
                    <td><?= e($c['designation']) ?></td>
                    <td><?= e($c['program']) ?></td>
                    <td><?= $c['company_name'] !== null ? e($c['company_name']) : '&mdash;' ?></td>
                    <td><?= $c['platoon_name'] !== null ? e($c['platoon_name']) : '&mdash;' ?></td>
                    <td><span class="badge <?= $badge ?>"><?= e($status_labels[$c['status']] ?? $c['status']) ?></span></td>
                    <td><a href="<?= BASE_URL ?>/s1/edit_cadet.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
    <p style="margin-top: 16px; font-size: 14px;">
        <?php if ($page > 1): ?>
            <a href="<?= e(roster_url($filters, $page - 1)) ?>" class="btn btn-sm btn-secondary">&laquo; Previous</a>
        <?php endif; ?>
        &nbsp; Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong> &nbsp;
        <?php if ($page < $pages): ?>
            <a href="<?= e(roster_url($filters, $page + 1)) ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>