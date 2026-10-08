<?php
// UnitSync: S1 Export Attendance CSV (Stage 3)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_queries.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

// 1. Fetch active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

if (!$active_term) {
    http_response_code(400);
    die("No active academic term configured.");
}

$term_id = (int)$active_term['id'];

// 2. Read GET filters
$company_filter = trim((string)($_GET['company_id'] ?? ''));
$platoon_filter = trim((string)($_GET['platoon_id'] ?? ''));
$program_filter = (int)($_GET['program_id'] ?? 0);
$session_filter = (int)($_GET['session_id'] ?? 0);
$status_filter  = trim((string)($_GET['status'] ?? 'all'));
$q_filter       = trim((string)($_GET['q'] ?? ''));

$valid_statuses = ['all', 'active', 'dropped', 'transferred', 'graduated'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'all';
}

$filters = [
    'company_id' => $company_filter,
    'platoon_id' => $platoon_filter,
    'program_id' => $program_filter,
    'session_id' => $session_filter,
    'status'     => $status_filter,
    'q'          => $q_filter,
];

// 3. Fetch approved sessions and all matching cadets (no pagination)
$approved_sessions = get_approved_sessions(
    $pdo,
    $term_id,
    $company_filter,
    $platoon_filter,
    $session_filter > 0 ? $session_filter : null
);

$cadets = get_filtered_cadets($pdo, $term_id, $filters);

$cadet_ids = array_column($cadets, 'id');
$session_ids = array_column($approved_sessions, 'id');

$attendance_matrix = get_approved_attendance_matrix($pdo, $term_id, $cadet_ids, $session_ids);
$cadet_stats = get_cadet_term_attendance_stats($pdo, $term_id, $cadet_ids);
$at_risk_threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');

// 4. Helper to sanitize Excel formula injection
function sanitize_csv_cell(?string $val): string {
    $str = $val ?? '';
    if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
        return "'" . $str;
    }
    return $str;
}

// 5. Audit Log Entry
log_audit(
    $pdo,
    $user['id'],
    'export_attendance_csv',
    'attendance',
    null,
    json_encode([
        'term_id'    => $term_id,
        'filters'    => $filters,
        'row_count'  => count($cadets),
        'session_count' => count($approved_sessions),
        'exported_at' => date('Y-m-d H:i:s'),
    ])
);

// 6. Send CSV Headers
$filename = 'attendance_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Output UTF-8 BOM so Excel opens with proper character encoding
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
$headers = [
    'Cadet Code',
    'Last Name',
    'First Name',
    'Middle Name',
    'Program',
    'Company',
    'Platoon',
    'Status',
];

foreach ($approved_sessions as $sess) {
    $headers[] = $sess['session_date'] . ' - ' . $sess['label'];
}

$headers[] = 'Present';
$headers[] = 'Late';
$headers[] = 'Absent';
$headers[] = 'Excused';
$headers[] = 'Attendance %';
$headers[] = 'At Risk';

fputcsv($out, array_map('sanitize_csv_cell', $headers));

// Data rows
foreach ($cadets as $c) {
    $cid = (int)$c['id'];
    $stats = $cadet_stats[$cid] ?? ['p' => 0, 'a' => 0, 'l' => 0, 'e' => 0, 'percentage' => null];

    $pct_str = $stats['percentage'] !== null ? number_format($stats['percentage'], 1) . '%' : '—';
    $at_risk = ($stats['percentage'] !== null && $stats['percentage'] < $at_risk_threshold) ? 'Yes' : 'No';
    if ($stats['percentage'] === null) {
        $at_risk = '—';
    }

    $row = [
        sanitize_csv_cell($c['cadet_code']),
        sanitize_csv_cell($c['last_name']),
        sanitize_csv_cell($c['first_name']),
        sanitize_csv_cell($c['middle_name'] ?? ''),
        sanitize_csv_cell($c['program_code'] ?? ''),
        sanitize_csv_cell(!empty($c['company_name']) ? 'Company ' . $c['company_name'] : 'Unassigned'),
        sanitize_csv_cell(!empty($c['platoon_name']) ? $c['platoon_name'] : 'Unassigned'),
        sanitize_csv_cell(ucfirst($c['status'])),
    ];

    foreach ($approved_sessions as $sess) {
        $sid = (int)$sess['id'];
        $rec = $attendance_matrix[$cid][$sid] ?? null;
        if ($rec === null) {
            $row[] = '';
        } else {
            $mark = $rec['status'];
            if ($mark === 'L' && !empty($rec['minutes_late'])) {
                $mark .= ' (' . $rec['minutes_late'] . 'm)';
            } elseif ($mark === 'E' && !empty($rec['excuse_reason'])) {
                $mark .= ' (' . $rec['excuse_reason'] . ')';
            }
            $row[] = sanitize_csv_cell($mark);
        }
    }

    $row[] = (string)$stats['p'];
    $row[] = (string)$stats['l'];
    $row[] = (string)$stats['a'];
    $row[] = (string)$stats['e'];
    $row[] = $pct_str;
    $row[] = $at_risk;

    fputcsv($out, $row);
}

fclose($out);
exit;
