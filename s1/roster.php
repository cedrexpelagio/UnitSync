<?php
// S1 Cadet Roster: view, search, filter, paginate (Battalion S1 and Brigade S1 only)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/export_helpers.php';

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
// Filters without the status choice, used for the status tab counts
$where_base = $where;
$params_base = $params;
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

// Count per status under the other filters (drives the status tabs)
$stmt = $pdo->prepare("
    SELECT CASE WHEN c.status = 'active' AND e.platoon_id IS NULL THEN 'unassigned' ELSE c.status END AS st, COUNT(*) AS n
    $from_sql
    " . ($where_base ? 'WHERE ' . implode(' AND ', $where_base) : '') . "
    GROUP BY st
");
$stmt->execute($params_base);
$status_counts = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
$status_counts_all = array_sum($status_counts);

$pages = max(1, (int)ceil($total / ROSTER_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * ROSTER_PAGE_SIZE;
$limit = (int)ROSTER_PAGE_SIZE;

$stmt = $pdo->prepare("
    SELECT c.id, c.cadet_code, c.last_name, c.first_name, CONCAT(c.last_name, ', ', c.first_name, IF(c.middle_name IS NULL OR c.middle_name = '', '', CONCAT(' ', c.middle_name))) AS full_name, c.gender, c.designation, pr.code AS program,
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
    SELECT p.id, p.name, p.company_id, co.name AS company_name
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

// URL of the roster with ONE filter removed (page resets to 1)
function roster_without(array $filters, string $key): string {
    unset($filters[$key]);
    $qs = http_build_query($filters);
    return BASE_URL . '/s1/roster.php' . ($qs !== '' ? '?' . $qs : '');
}

// ---------- Active filter chips ----------
$company_names = array_column($companies, 'name', 'id');
$platoon_names = [];
foreach ($platoons as $pl) {
    $platoon_names[$pl['id']] = $pl['company_name'] . ' - ' . $pl['name'];
}

$chips = [];
if ($q !== '')       $chips['q']       = 'Name: ' . $q;
if ($gender !== '')  $chips['gender']  = 'Gender: ' . $gender;
if ($program !== '') $chips['program'] = 'Program: ' . $program;
if ($company > 0)    $chips['company'] = 'Company: ' . ($company_names[$company] ?? $company);
if ($platoon > 0)    $chips['platoon'] = 'Platoon: ' . ($platoon_names[$platoon] ?? $platoon);
if ($status !== '')  $chips['status']  = 'Status: ' . $status_labels[$status];

// The Filters button counts everything except the search box (that is always visible)
$filter_count = count($chips) - (isset($chips['q']) ? 1 : 0);

$from = $total === 0 ? 0 : $offset + 1;
$to = min($offset + ROSTER_PAGE_SIZE, $total);

$page_title = 'Cadet Roster';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/roster.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">
<script>document.documentElement.classList.add('no-js');</script>

<div class="content-header">
    <h1>Cadet Roster</h1>
    <p>All cadets in the database. Edit details and assign cadets to a company and platoon.</p>
</div>

<?php if (!$active_term_id): ?>
    <div class="alert alert-warning" style="margin-bottom: 16px;">No active term is set, so every cadet shows as Unassigned. An Administrator must activate a term.</div>
<?php endif; ?>

<form method="GET" action="<?= BASE_URL ?>/s1/roster.php" id="rosterFilters" role="search" aria-label="Filter cadets">

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

            <div class="rf-panel" id="rfPanel" role="dialog" aria-label="Filter cadets">
                <div class="rf-panel-head">
                    <span class="rf-panel-title">Filters</span>
                    <button type="button" class="rf-panel-close" id="rfClose" aria-label="Close filters">&times;</button>
                </div>

                <div class="rf-panel-body">
                    <div class="rf-grid">
                        <div class="rf-field">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-control <?= $gender !== '' ? 'is-set' : '' ?>">
                                <option value="">All genders</option>
                                <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>

                        <div class="rf-field">
                            <label for="program">Program</label>
                            <select id="program" name="program" class="form-control <?= $program !== '' ? 'is-set' : '' ?>">
                                <option value="">All programs</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?= e($p['code']) ?>" <?= $program === $p['code'] ? 'selected' : '' ?>><?= e($p['code']) ?></option>
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
                <a class="rf-chip-x" href="<?= e(roster_without($filters, $key)) ?>"
                   aria-label="Remove filter <?= e($text) ?>" title="Remove">&times;</a>
            </span>
        <?php endforeach; ?>
        <a class="rf-clear-all" href="<?= BASE_URL ?>/s1/roster.php">Clear all</a>
    </div>
<?php endif; ?>

<div class="rt-bar">
    <span>Showing <strong><?= $from ?>-<?= $to ?></strong> of <strong><?= $total ?></strong> cadet(s)</span>
    <span class="rt-bar-links" style="display:inline-flex;align-items:center;gap:14px;">
        <a href="<?= BASE_URL ?>/s1/import_cadets.php">Import cadets (CSV)</a>
        <?= exp_menu([
            'Excel (.xls)' => ['export_roster.php', ['format' => 'xls'], 'Branded roster with status colors'],
            'PDF'          => ['export_roster.php', ['format' => 'pdf'], 'Print-ready, opens in a new tab'],
        ]) ?>
    </span>
</div>

<nav class="s1-tabs" aria-label="Filter by status">
    <a class="s1-tab <?= $status === '' ? 'active' : '' ?>" href="<?= e(roster_without($filters, 'status')) ?>">All <b><?= (int)$status_counts_all ?></b></a>
    <?php foreach ($status_labels as $key => $label): ?>
        <a class="s1-tab <?= $status === $key ? 'active' : '' ?> <?= $key === 'unassigned' && ($status_counts[$key] ?? 0) > 0 ? 's1-tab-warn' : '' ?>"
           href="<?= e(roster_url(['status' => $key] + $filters, 1)) ?>"><?= e($label) ?> <b><?= (int)($status_counts[$key] ?? 0) ?></b></a>
    <?php endforeach; ?>
</nav>

<div class="table-responsive rt-wrap s1-rt">
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
                <tr>
                    <td colspan="8" class="rt-empty">
                        <strong>No cadets found</strong>
                        Try removing a filter or <a href="<?= BASE_URL ?>/s1/roster.php">reset all filters</a>.
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($cadets as $c): ?>
                <?php
                    $initials = mb_strtoupper(mb_substr((string)$c['first_name'], 0, 1) . mb_substr((string)$c['last_name'], 0, 1));
                    $needs_assign = ($c['status'] === 'unassigned');
                ?>
                <tr class="<?= $needs_assign ? 's1-row-attn' : '' ?>">
                    <td class="rt-name">
                        <div class="s1-person">
                            <span class="s1-avatar" aria-hidden="true"><?= e($initials) ?></span>
                            <div>
                                <div class="s1-person-name"><?= e($c['full_name']) ?></div>
                                <div class="s1-person-sub"><?= e((string)$c['cadet_code']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= e($c['gender']) ?></td>
                    <td><?= e($c['designation']) ?></td>
                    <td><span class="s1-prog"><?= e($c['program']) ?></span></td>
                    <td><?= $c['company_name'] !== null ? e($c['company_name']) : '<span class="rt-muted">&mdash;</span>' ?></td>
                    <td><?= $c['platoon_name'] !== null ? e($c['platoon_name']) : '<span class="rt-muted">&mdash;</span>' ?></td>
                    <td><span class="s1-status s1-status-<?= e($c['status']) ?>"><i></i><?= e($status_labels[$c['status']] ?? $c['status']) ?></span></td>
                    <td><a href="<?= BASE_URL ?>/s1/edit_cadet.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm <?= $needs_assign ? 'btn-primary' : 'btn-secondary' ?>"><?= $needs_assign ? 'Assign' : 'Edit' ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
    <div class="rt-pager">
        <?php if ($page > 1): ?>
            <a href="<?= e(roster_url($filters, $page - 1)) ?>" class="btn btn-sm btn-secondary">&laquo; Previous</a>
        <?php else: ?>
            <span class="btn btn-sm btn-secondary" aria-disabled="true">&laquo; Previous</span>
        <?php endif; ?>

        <span>Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong></span>

        <?php if ($page < $pages): ?>
            <a href="<?= e(roster_url($filters, $page + 1)) ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
        <?php else: ?>
            <span class="btn btn-sm btn-secondary" aria-disabled="true">Next &raquo;</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/roster.js" defer></script>
<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>