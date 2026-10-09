<?php
// UnitSync: S1 Export Attendance as Excel (.xls) or PDF (print page). Status cells are color only.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_queries.php';
require_once __DIR__ . '/../includes/export_helpers.php';

require_role('battalion_s1', 'brigade_s1');
$user = current_user();
$format = exp_format();

$active_term = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1")->fetch();
if (!$active_term) {
    http_response_code(400);
    die('No active academic term configured.');
}
$term_id = (int)$active_term['id'];

$company_filter = trim((string)($_GET['company_id'] ?? ''));
$platoon_filter = trim((string)($_GET['platoon_id'] ?? ''));
$program_filter = (int)($_GET['program_id'] ?? 0);
$session_filter = (int)($_GET['session_id'] ?? 0);
$status_filter  = trim((string)($_GET['status'] ?? 'all'));
$q_filter       = trim((string)($_GET['q'] ?? ''));
if (!in_array($status_filter, ['all', 'active', 'dropped', 'transferred', 'graduated'], true)) $status_filter = 'all';

$filters = [
    'company_id' => $company_filter, 'platoon_id' => $platoon_filter, 'program_id' => $program_filter,
    'session_id' => $session_filter, 'status' => $status_filter, 'q' => $q_filter,
];

$sessions = get_approved_sessions($pdo, $term_id, $company_filter, $platoon_filter, $session_filter > 0 ? $session_filter : null);
$cadets = get_filtered_cadets($pdo, $term_id, $filters);
$cadet_ids = array_column($cadets, 'id');
$session_ids = array_column($sessions, 'id');
$matrix = get_approved_attendance_matrix($pdo, $term_id, $cadet_ids, $session_ids);
$stats_all = get_cadet_term_attendance_stats($pdo, $term_id, $cadet_ids);
$threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');

log_audit($pdo, $user['id'], 'export_attendance_' . $format, 'attendance', null, json_encode([
    'term_id' => $term_id, 'filters' => $filters, 'row_count' => count($cadets),
    'session_count' => count($sessions), 'exported_at' => date('Y-m-d H:i:s'),
]));

// Human-readable description of the applied filters
$desc = [];
if ($company_filter !== '') $desc[] = $company_filter === 'unassigned' ? 'Company: Unassigned' : 'Company ID ' . $company_filter;
if ($platoon_filter !== '') $desc[] = $platoon_filter === 'unassigned' ? 'Platoon: Unassigned' : 'Platoon ID ' . $platoon_filter;
if ($program_filter > 0)    $desc[] = 'Program ID ' . $program_filter;
if ($session_filter > 0)    $desc[] = 'Single session';
if ($status_filter !== 'all') $desc[] = 'Status: ' . ucfirst($status_filter);
if ($q_filter !== '')       $desc[] = 'Search: ' . $q_filter;

$fixed = 7;                      // #, Cadet, Program, Company, Platoon, Status ... (see header below)
$cols = $fixed + count($sessions) + 5;   // + P, L, A, E, %
$colors = exp_colors();

exp_begin($format, 'Attendance Report', 'attendance');
exp_banner($pdo, $cols, 'Attendance Report', 'Term: ' . $active_term['name'] . ' (fully approved sessions only)', [
    'Filters' => $desc ? implode('; ', $desc) : 'None (all cadets)',
    'Cadets' => count($cadets) . ' | Sessions: ' . count($sessions),
    'Formula' => '(Present + Late) / (Present + Absent + Late) x 100. Excused is excluded. At risk below ' . (int)$threshold . '%.',
]);
exp_legend($cols, true, ['At risk' => '#FEE2E2']);

// Table header
echo '<thead><tr>';
foreach (['#', 'Cadet Code', 'Cadet Name', 'Program', 'Company', 'Platoon', 'Status'] as $i => $h) {
    echo '<th class="h ' . ($i === 2 ? 'h-left' : '') . '">' . e($h) . '</th>';
}
foreach ($sessions as $s) {
    echo '<th class="h">' . e(date('M d', strtotime($s['session_date']))) . '<br>' . e($s['label']) . '</th>';
}
foreach (['Present', 'Late', 'Absent', 'Excused', 'Attendance %'] as $h) echo '<th class="h">' . e($h) . '</th>';
echo '</tr></thead><tbody>';

if (!$cadets) {
    echo '<tr><td colspan="' . $cols . '" style="text-align:center;padding:14px;">No cadets match the selected filters.</td></tr>';
}

$n = 0;
foreach ($cadets as $c) {
    $n++;
    $cid = (int)$c['id'];
    $st = $stats_all[$cid] ?? ['p' => 0, 'a' => 0, 'l' => 0, 'e' => 0, 'percentage' => null];
    $mi = !empty($c['middle_name']) ? ' ' . mb_strtoupper(mb_substr(trim($c['middle_name']), 0, 1)) . '.' : '';
    $name = $c['last_name'] . ', ' . $c['first_name'] . $mi;
    $zebra = $n % 2 === 0 ? ' class="zebra"' : '';

    echo '<tr' . $zebra . '>';
    echo '<td class="num">' . $n . '</td>';
    echo '<td>' . e($c['cadet_code']) . '</td>';
    echo '<td><strong>' . e($name) . '</strong></td>';
    echo '<td class="num">' . e($c['program_code'] ?? '') . '</td>';
    echo '<td class="num">' . (!empty($c['company_name']) ? e($c['company_name']) : 'Unassigned') . '</td>';
    echo '<td class="num">' . (!empty($c['platoon_name']) ? e($c['platoon_name']) : 'Unassigned') . '</td>';
    echo '<td class="num">' . e(ucfirst($c['status'])) . '</td>';

    foreach ($sessions as $s) {
        $rec = $matrix[$cid][(int)$s['id']] ?? null;
        if ($rec === null || !isset($colors[$rec['status']])) {
            echo '<td class="sess">&nbsp;</td>';
        } else {
            $hex = $colors[$rec['status']]['solid'];
            echo '<td class="sess" bgcolor="' . $hex . '" style="background:' . $hex . ';" title="' . e($colors[$rec['status']]['label']) . '">&nbsp;</td>';
        }
    }

    echo '<td class="num">' . (int)$st['p'] . '</td><td class="num">' . (int)$st['l'] . '</td><td class="num">' . (int)$st['a'] . '</td><td class="num">' . (int)$st['e'] . '</td>';
    if ($st['percentage'] === null) {
        echo '<td class="num">&mdash;</td>';
    } elseif ($st['percentage'] < $threshold) {
        echo '<td class="risk" bgcolor="#FEE2E2">' . number_format($st['percentage'], 1) . '%</td>';
    } else {
        echo '<td class="num"><strong>' . number_format($st['percentage'], 1) . '%</strong></td>';
    }
    echo '</tr>';
}
echo '</tbody>';

exp_end($format, $cols, (trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'S1 account')) . ' (' . ($user['role'] === 'brigade_s1' ? 'Brigade S1' : 'Battalion S1') . ')');
exit;
