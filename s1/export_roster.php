<?php
// UnitSync: S1 Export Cadet Roster as Excel (.xls) or PDF (print page). Mirrors the filters of roster.php.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/export_helpers.php';

require_role('battalion_s1', 'brigade_s1');
$user = current_user();
$format = exp_format();

$active_term_id = (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();

$status_labels = ['active' => 'Active', 'unassigned' => 'Unassigned', 'dropped' => 'Dropped', 'transferred' => 'Transferred', 'graduated' => 'Graduated'];
$status_fill   = ['active' => '#D1FAE5', 'unassigned' => '#FEF3C7', 'dropped' => '#FEE2E2', 'transferred' => '#FEF3C7', 'graduated' => '#DBEAFE'];

function xr_int($v): int { return (is_string($v) && ctype_digit($v)) ? (int)$v : 0; }

$q       = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$gender  = is_string($_GET['gender'] ?? null) ? $_GET['gender'] : '';
$program = is_string($_GET['program'] ?? null) ? trim($_GET['program']) : '';
$company = xr_int($_GET['company'] ?? '');
$platoon = xr_int($_GET['platoon'] ?? '');
$status  = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($gender, ['Male', 'Female'], true)) $gender = '';
if (!isset($status_labels[$status])) $status = '';

$where = []; $params = [];
if ($q !== '') {
    $where[] = "(CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name) LIKE :q1 OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE :q2)";
    $params['q1'] = '%' . addcslashes($q, '%_\\') . '%';
    $params['q2'] = $params['q1'];
}
if ($gender !== '')  { $where[] = 'c.gender = :gender';      $params['gender'] = $gender; }
if ($program !== '') { $where[] = 'pr.code = :program';      $params['program'] = $program; }
if ($company > 0)    { $where[] = 'e.company_id = :company'; $params['company'] = $company; }
if ($platoon > 0)    { $where[] = 'e.platoon_id = :platoon'; $params['platoon'] = $platoon; }
if ($status === 'unassigned')   $where[] = "c.status = 'active' AND e.platoon_id IS NULL";
elseif ($status === 'active')   $where[] = "c.status = 'active' AND e.platoon_id IS NOT NULL";
elseif ($status !== '')       { $where[] = 'c.status = :status'; $params['status'] = $status; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT CONCAT(c.last_name, ', ', c.first_name, IF(c.middle_name IS NULL OR c.middle_name = '', '', CONCAT(' ', c.middle_name))) AS full_name,
           c.cadet_code, c.gender, c.designation, pr.code AS program,
           CASE WHEN c.status = 'active' AND e.platoon_id IS NULL THEN 'unassigned' ELSE c.status END AS status,
           co.name AS company_name, p.name AS platoon_name
    FROM cadets c
    JOIN programs pr ON pr.id = c.program_id
    LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = {$active_term_id}
    LEFT JOIN companies co ON e.company_id = co.id
    LEFT JOIN platoons p ON e.platoon_id = p.id
    $where_sql
    ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
");
$stmt->execute($params);
$cadets = $stmt->fetchAll();

log_audit($pdo, $user['id'], 'export_roster_' . $format, 'cadets', null, json_encode([
    'filters' => compact('q', 'gender', 'program', 'company', 'platoon', 'status'),
    'row_count' => count($cadets), 'exported_at' => date('Y-m-d H:i:s'),
]));

$desc = [];
if ($q !== '')       $desc[] = 'Name: ' . $q;
if ($gender !== '')  $desc[] = 'Gender: ' . $gender;
if ($program !== '') $desc[] = 'Program: ' . $program;
if ($company > 0)    $desc[] = 'Company ID ' . $company;
if ($platoon > 0)    $desc[] = 'Platoon ID ' . $platoon;
if ($status !== '')  $desc[] = 'Status: ' . $status_labels[$status];

$cols = 9;
exp_begin($format, 'Cadet Roster', 'cadet_roster', false);
exp_banner($pdo, $cols, 'Cadet Roster', 'Company and platoon assignments are for the active term.', [
    'Filters' => $desc ? implode('; ', $desc) : 'None (all cadets)',
    'Total cadets' => count($cadets),
]);
$legend = [];
foreach ($status_labels as $k => $label) $legend[$label] = $status_fill[$k];
exp_legend($cols, false, $legend);

echo '<thead><tr>';
foreach (['#', 'Cadet Code', 'Full Name', 'Gender', 'Designation', 'Program', 'Company', 'Platoon', 'Status'] as $i => $h) {
    echo '<th class="h ' . ($i === 2 ? 'h-left' : '') . '">' . e($h) . '</th>';
}
echo '</tr></thead><tbody>';

if (!$cadets) echo '<tr><td colspan="' . $cols . '" style="text-align:center;padding:14px;">No cadets match the selected filters.</td></tr>';

$n = 0;
foreach ($cadets as $c) {
    $n++;
    $zebra = $n % 2 === 0 ? ' class="zebra"' : '';
    $fill = $status_fill[$c['status']] ?? '#E2E8F0';
    echo '<tr' . $zebra . '>';
    echo '<td class="num">' . $n . '</td>';
    echo '<td>' . e((string)$c['cadet_code']) . '</td>';
    echo '<td><strong>' . e($c['full_name']) . '</strong></td>';
    echo '<td class="num">' . e((string)$c['gender']) . '</td>';
    echo '<td>' . e((string)$c['designation']) . '</td>';
    echo '<td class="num">' . e((string)$c['program']) . '</td>';
    echo '<td class="num">' . ($c['company_name'] !== null ? e($c['company_name']) : '&mdash;') . '</td>';
    echo '<td class="num">' . ($c['platoon_name'] !== null ? e($c['platoon_name']) : '&mdash;') . '</td>';
    echo '<td class="num" bgcolor="' . $fill . '" style="background:' . $fill . ';font-weight:bold;">' . e($status_labels[$c['status']] ?? $c['status']) . '</td>';
    echo '</tr>';
}
echo '</tbody>';

exp_end($format, $cols, (trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'S1 account')) . ' (' . ($user['role'] === 'brigade_s1' ? 'Brigade S1' : 'Battalion S1') . ')');
exit;
