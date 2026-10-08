<?php
// UnitSync: S1 View Attendance (Stage 2)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_queries.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

const ATTENDANCE_PAGE_SIZE = 25;

// 1. Fetch active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

// 2. Load filter options
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$platoons  = $pdo->query("SELECT id, company_id, name FROM platoons ORDER BY company_id ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$programs  = $pdo->query("SELECT id, code, name FROM programs ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

// 3. Read and validate GET filters
$company_filter = trim((string)($_GET['company_id'] ?? ''));
$platoon_filter = trim((string)($_GET['platoon_id'] ?? ''));
$program_filter = (int)($_GET['program_id'] ?? 0);
$session_filter = (int)($_GET['session_id'] ?? 0);
$status_filter  = trim((string)($_GET['status'] ?? 'all'));
$q_filter       = trim((string)($_GET['q'] ?? ''));
$page           = max(1, (int)($_GET['page'] ?? 1));

// Validate status
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

// 4. Fetch settings threshold
$at_risk_threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');

// 5. Data containers
$approved_sessions = [];
$all_term_sessions = [];
$cadets            = [];
$total_cadets      = 0;
$pages             = 1;
$attendance_matrix = [];
$cadet_stats       = [];
$session_totals    = [];
$from              = 0;
$to                = 0;

if ($active_term) {
    $term_id = (int)$active_term['id'];

    // All approved sessions for the dropdown
    $all_term_sessions = get_approved_sessions($pdo, $term_id);

    // Filtered approved sessions for table columns
    $approved_sessions = get_approved_sessions(
        $pdo,
        $term_id,
        $company_filter,
        $platoon_filter,
        $session_filter > 0 ? $session_filter : null
    );

    // Count and paginate matching cadets
    $total_cadets = count_filtered_cadets($pdo, $term_id, $filters);
    $pages = max(1, (int)ceil($total_cadets / ATTENDANCE_PAGE_SIZE));
    $page = min($page, $pages);
    $offset = ($page - 1) * ATTENDANCE_PAGE_SIZE;

    $cadets = get_filtered_cadets($pdo, $term_id, $filters, ATTENDANCE_PAGE_SIZE, $offset);

    $from = $total_cadets === 0 ? 0 : $offset + 1;
    $to = min($offset + ATTENDANCE_PAGE_SIZE, $total_cadets);

    if (!empty($cadets)) {
        $cadet_ids = array_column($cadets, 'id');
        $session_ids = array_column($approved_sessions, 'id');

        // Matrix of [cadet_id][session_id] -> status
        $attendance_matrix = get_approved_attendance_matrix($pdo, $term_id, $cadet_ids, $session_ids);

        // Stats per cadet (all approved sessions in the term, not filtered by session)
        $cadet_stats = get_cadet_term_attendance_stats($pdo, $term_id, $cadet_ids);
    }

    // Totals per session for ALL cadets matching the filter criteria
    if (!empty($approved_sessions)) {
        $session_ids = array_column($approved_sessions, 'id');
        $session_totals = get_session_totals_for_filtered_cadets($pdo, $term_id, $filters, $session_ids);
    }
}

// Helper to preserve filters in links
function filter_query(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return $qs !== '' ? '?' . $qs : '';
}

$page_title = 'View Attendance - S1';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>View Attendance</h1>
        <p style="margin: 0; color: var(--gray-700);">
            <strong>Battalion &amp; Brigade S1 Comprehensive Attendance Matrix</strong>
            <?php if ($active_term): ?>
                &nbsp;|&nbsp; Term: <span style="color: var(--green-700); font-weight: 600;"><?= e($active_term['name']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <?php if ($active_term && !empty($approved_sessions)): ?>
            <a href="<?= BASE_URL ?>/s1/export_attendance_csv.php<?= filter_query(['page' => null]) ?>" 
               class="btn btn-secondary btn-sm" 
               id="export-csv-btn" 
               style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Export CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$active_term): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">No Active Academic Term</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            There is currently no active academic term configured. Attendance data requires an active term.
        </p>
    </div>
<?php else: ?>

    <!-- Filter Card -->
    <div class="card" style="margin-bottom: 20px; padding: 18px 20px;">
        <form method="GET" action="<?= BASE_URL ?>/s1/view_attendance.php" id="attendance-filter-form">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; align-items: flex-end;">
                
                <!-- Company Filter -->
                <div class="form-group" style="margin: 0;">
                    <label for="company_id" style="font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Company</label>
                    <select name="company_id" id="company_id" class="form-control" style="font-size: 13px; padding: 8px 10px;">
                        <option value="">All Companies</option>
                        <option value="unassigned" <?= $company_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned Only</option>
                        <?php foreach ($companies as $co): ?>
                            <option value="<?= (int)$co['id'] ?>" <?= $company_filter === (string)$co['id'] ? 'selected' : '' ?>>
                                Company <?= e($co['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Platoon Filter -->
                <div class="form-group" style="margin: 0;">
                    <label for="platoon_id" style="font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Platoon</label>
                    <select name="platoon_id" id="platoon_id" class="form-control" style="font-size: 13px; padding: 8px 10px;">
                        <option value="">All Platoons</option>
                        <option value="unassigned" <?= $platoon_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned Only</option>
                        <?php foreach ($platoons as $pl): ?>
                            <option value="<?= (int)$pl['id'] ?>" 
                                    data-company="<?= (int)$pl['company_id'] ?>" 
                                    <?= $platoon_filter === (string)$pl['id'] ? 'selected' : '' ?>>
                                <?= e($pl['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Program Filter -->
                <div class="form-group" style="margin: 0;">
                    <label for="program_id" style="font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Program</label>
                    <select name="program_id" id="program_id" class="form-control" style="font-size: 13px; padding: 8px 10px;">
                        <option value="0">All Programs</option>
                        <?php foreach ($programs as $pr): ?>
                            <option value="<?= (int)$pr['id'] ?>" <?= $program_filter === (int)$pr['id'] ? 'selected' : '' ?>>
                                <?= e($pr['code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Session Filter -->
                <div class="form-group" style="margin: 0;">
                    <label for="session_id" style="font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Session</label>
                    <select name="session_id" id="session_id" class="form-control" style="font-size: 13px; padding: 8px 10px;">
                        <option value="0">All Approved Sessions</option>
                        <?php foreach ($all_term_sessions as $ts): ?>
                            <option value="<?= (int)$ts['id'] ?>" <?= $session_filter === (int)$ts['id'] ? 'selected' : '' ?>>
                                <?= e($ts['session_date']) ?>: <?= e($ts['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="form-group" style="margin: 0;">
                    <label for="status" style="font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Cadet Status</label>
                    <select name="status" id="status" class="form-control" style="font-size: 13px; padding: 8px 10px;">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="dropped" <?= $status_filter === 'dropped' ? 'selected' : '' ?>>Dropped</option>
                        <option value="transferred" <?= $status_filter === 'transferred' ? 'selected' : '' ?>>Transferred</option>
                        <option value="graduated" <?= $status_filter === 'graduated' ? 'selected' : '' ?>>Graduated</option>
                    </select>
                </div>

                <!-- Search Input -->
                <div class="form-group" style="margin: 0;">
                    <label for="q" style="font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Search</label>
                    <input type="text" name="q" id="q" class="form-control" style="font-size: 13px; padding: 8px 10px;" value="<?= e($q_filter) ?>" placeholder="Name, code, SN...">
                </div>

            </div>

            <div style="display: flex; gap: 8px; margin-top: 14px; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary btn-sm" style="padding: 7px 16px;">Apply Filters</button>
                <a href="<?= BASE_URL ?>/s1/view_attendance.php" class="btn btn-secondary btn-sm" style="padding: 7px 16px;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Overview -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; color: var(--gray-700); flex-wrap: wrap; gap: 8px;">
        <div>
            Showing <strong><?= $from ?>&ndash;<?= $to ?></strong> of <strong><?= $total_cadets ?></strong> cadet(s)
            &bull; <strong><?= count($approved_sessions) ?></strong> approved session column(s)
        </div>
        <div style="font-size: 12px;">
            <span style="display: inline-flex; align-items: center; gap: 4px; margin-right: 10px;"><span class="badge badge-approved" style="padding: 2px 6px;">P</span> Present</span>
            <span style="display: inline-flex; align-items: center; gap: 4px; margin-right: 10px;"><span class="badge badge-transferred" style="padding: 2px 6px;">L</span> Late</span>
            <span style="display: inline-flex; align-items: center; gap: 4px; margin-right: 10px;"><span class="badge badge-rejected" style="padding: 2px 6px;">A</span> Absent</span>
            <span style="display: inline-flex; align-items: center; gap: 4px;"><span class="badge badge-scheduled" style="padding: 2px 6px;">E</span> Excused</span>
        </div>
    </div>

    <?php if (empty($approved_sessions)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Approved Attendance Sessions Found</h3>
            <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
                There are no training sessions with fully approved attendance records for the selected company/platoon filter.
            </p>
            <a href="<?= BASE_URL ?>/s1/view_attendance.php" class="btn btn-secondary btn-sm">Clear Filters</a>
        </div>
    <?php elseif (empty($cadets)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Matching Cadets Found</h3>
            <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
                No cadets match your selected filter criteria.
            </p>
            <a href="<?= BASE_URL ?>/s1/view_attendance.php" class="btn btn-secondary btn-sm">Reset Filters</a>
        </div>
    <?php else: ?>

        <!-- Attendance Matrix Table -->
        <div class="table-responsive" style="margin-bottom: 20px;">
            <table class="data-table" id="attendance-matrix-table">
                <thead>
                    <tr>
                        <th class="sticky-col" style="min-width: 180px; position: sticky; left: 0; z-index: 3; background-color: var(--gray-50);">
                            Cadet Name
                        </th>
                        <th style="min-width: 80px;">Program</th>
                        <th style="min-width: 90px;">Company</th>
                        <th style="min-width: 100px;">Platoon</th>
                        <th style="min-width: 80px;">Status</th>
                        <?php foreach ($approved_sessions as $sess): ?>
                            <th style="text-align: center; min-width: 85px; font-size: 12px; padding: 8px 6px;">
                                <div style="font-weight: 700; color: var(--green-900);"><?= date('M d', strtotime($sess['session_date'])) ?></div>
                                <div style="font-size: 11px; font-weight: 400; color: var(--gray-700);"><?= e($sess['label']) ?></div>
                            </th>
                        <?php endforeach; ?>
                        <th style="text-align: right; min-width: 110px;">
                            Attendance %
                            <span class="formula-info-btn" id="matrix-formula-info" title="Formula: (Present + Late) &divide; (Present + Absent + Late) &times; 100. Excused sessions are excluded from denominator.">?</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cadets as $c): ?>
                        <?php
                            $cid = (int)$c['id'];
                            $mi = !empty($c['middle_name']) ? ' ' . mb_strtoupper(mb_substr(trim($c['middle_name']), 0, 1)) . '.' : '';
                            $full_name = $c['last_name'] . ', ' . $c['first_name'] . $mi;

                            $stats = $cadet_stats[$cid] ?? ['percentage' => null];
                            $pct = $stats['percentage'];
                            $is_at_risk = ($pct !== null && $pct < $at_risk_threshold);

                            $status_class = 'badge-active';
                            if ($c['status'] === 'dropped') $status_class = 'badge-dropped';
                            elseif ($c['status'] === 'transferred') $status_class = 'badge-transferred';
                            elseif ($c['status'] === 'graduated') $status_class = 'badge-graduated';
                        ?>
                        <tr>
                            <!-- Sticky Cadet Name Column -->
                            <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background-color: var(--white); box-shadow: 2px 0 4px -2px rgba(0,0,0,0.1);">
                                <strong><?= e($full_name) ?></strong>
                                <div style="font-size: 11px; font-family: monospace; color: var(--gray-700);">
                                    <?= e($c['cadet_code']) ?>
                                </div>
                            </td>
                            <td><?= e($c['program_code'] ?? '&mdash;') ?></td>
                            <td><?= !empty($c['company_name']) ? 'Co. ' . e($c['company_name']) : '<em style="color: var(--gray-500);">Unassigned</em>' ?></td>
                            <td><?= !empty($c['platoon_name']) ? e($c['platoon_name']) : '<em style="color: var(--gray-500);">Unassigned</em>' ?></td>
                            <td>
                                <span class="badge <?= $status_class ?>" style="font-size: 11px;">
                                    <?= ucfirst($c['status']) ?>
                                </span>
                            </td>

                            <!-- Approved Session Marks -->
                            <?php foreach ($approved_sessions as $sess): ?>
                                <?php
                                    $sid = (int)$sess['id'];
                                    $rec = $attendance_matrix[$cid][$sid] ?? null;
                                ?>
                                <td style="text-align: center; padding: 6px;">
                                    <?php if ($rec === null): ?>
                                        <span class="cell-blank" style="color: var(--gray-300);">&mdash;</span>
                                    <?php else: ?>
                                        <?php
                                            $st = $rec['status'];
                                            $badge = 'badge-approved';
                                            $title = 'Present';

                                            if ($st === 'L') {
                                                $badge = 'badge-transferred';
                                                $mins = (int)($rec['minutes_late'] ?? 0);
                                                $title = 'Late' . ($mins > 0 ? " ({$mins} mins)" : '');
                                            } elseif ($st === 'A') {
                                                $badge = 'badge-rejected';
                                                $title = 'Absent';
                                            } elseif ($st === 'E') {
                                                $badge = 'badge-scheduled';
                                                $reason = $rec['excuse_reason'] ?? '';
                                                $title = 'Excused' . ($reason !== '' ? ": {$reason}" : '');
                                            }
                                        ?>
                                        <span class="badge <?= $badge ?>" title="<?= e($title) ?>" style="font-size: 12px; font-weight: 700; padding: 3px 7px;">
                                            <?= e($st) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <!-- Percentage Column -->
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($pct === null): ?>
                                    <span style="color: var(--gray-500); font-weight: 500;">&mdash;</span>
                                <?php else: ?>
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <strong style="color: <?= $is_at_risk ? 'var(--error)' : 'var(--green-900)' ?>; font-size: 13px;">
                                            <?= number_format($pct, 1) ?>%
                                        </strong>
                                        <?php if ($is_at_risk): ?>
                                            <span class="badge badge-at-risk" title="At Risk: Attendance is <?= number_format($pct, 1) ?>%, below <?= (int)$at_risk_threshold ?>%">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                At Risk
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                <!-- Totals Row (Matching All Cadets in Filter) -->
                <tfoot>
                    <tr style="background-color: var(--gray-50); font-weight: 600; border-top: 2px solid var(--gray-300);">
                        <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background-color: var(--gray-50); box-shadow: 2px 0 4px -2px rgba(0,0,0,0.1);">
                            <strong>Totals (All Matching)</strong>
                        </td>
                        <td colspan="4" style="font-size: 12px; color: var(--gray-700);">
                            Per-session counts:
                        </td>
                        <?php foreach ($approved_sessions as $sess): ?>
                            <?php
                                $sid = (int)$sess['id'];
                                $tot = $session_totals[$sid] ?? ['p' => 0, 'a' => 0, 'l' => 0, 'e' => 0];
                            ?>
                            <td style="text-align: center; font-size: 11px; padding: 6px 4px; line-height: 1.3;">
                                <div style="color: var(--success);" title="Present"><strong>P:</strong> <?= $tot['p'] ?></div>
                                <div style="color: var(--warning);" title="Late"><strong>L:</strong> <?= $tot['l'] ?></div>
                                <div style="color: var(--error);" title="Absent"><strong>A:</strong> <?= $tot['a'] ?></div>
                                <div style="color: var(--info);" title="Excused"><strong>E:</strong> <?= $tot['e'] ?></div>
                            </td>
                        <?php endforeach; ?>
                        <td style="text-align: right; color: var(--gray-700); font-size: 12px;">
                            &mdash;
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Formula and Threshold Explanation Banner -->
        <div class="formula-banner" style="margin-bottom: 24px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <div>
                <strong>Attendance Formula:</strong> <code>(Present + Late) &divide; (Present + Absent + Late) &times; 100</code>.
                Only fully approved sessions count. Excused sessions are excluded from the denominator. Cadets below <strong><?= (int)$at_risk_threshold ?>%</strong> are highlighted as <strong>At Risk</strong>.
            </div>
        </div>

        <!-- Pagination Bar -->
        <?php if ($pages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div style="font-size: 13px; color: var(--gray-700);">
                    Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong>
                </div>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <a href="<?= BASE_URL ?>/s1/view_attendance.php<?= filter_query(['page' => 1]) ?>" class="btn btn-secondary btn-sm">&laquo; First</a>
                        <a href="<?= BASE_URL ?>/s1/view_attendance.php<?= filter_query(['page' => $page - 1]) ?>" class="btn btn-secondary btn-sm">&lsaquo; Prev</a>
                    <?php endif; ?>

                    <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($pages, $page + 2);
                        for ($p = $start_page; $p <= $end_page; $p++):
                    ?>
                        <a href="<?= BASE_URL ?>/s1/view_attendance.php<?= filter_query(['page' => $p]) ?>" 
                           class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $pages): ?>
                        <a href="<?= BASE_URL ?>/s1/view_attendance.php<?= filter_query(['page' => $page + 1]) ?>" class="btn btn-secondary btn-sm">Next &rsaquo;</a>
                        <a href="<?= BASE_URL ?>/s1/view_attendance.php<?= filter_query(['page' => $pages]) ?>" class="btn btn-secondary btn-sm">Last &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/view_attendance.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
