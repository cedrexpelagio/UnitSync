<?php
// UnitSync: Class President View Attendance (read-only): same layout as the S1 page,
// but locked to the president's own program, with no sensitive data and no exports.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_queries.php';
require_once __DIR__ . '/../includes/s1_ui.php';

require_role('class_president');

$user = current_user();

const ATTENDANCE_PAGE_SIZE = 25;

// 1. Fetch active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

// 2. Resolve the president's program. This is the ONLY source of program scope:
//    it comes from the server-side assignment, never from the URL.
$stmt = $pdo->prepare("
    SELECT ua.program_id, p.code AS prog_code, p.name AS prog_name
    FROM user_assignments ua
    LEFT JOIN programs p ON ua.program_id = p.id
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment   = $stmt->fetch();
$program_id   = (int)($assignment['program_id'] ?? 0);
$prog_code    = $assignment['prog_code'] ?? 'Unassigned';
$prog_name    = $assignment['prog_name'] ?? 'Not Assigned';
$has_program  = $program_id > 0;

// 3. Load filter options (company/platoon only; program is fixed)
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$platoons  = $pdo->query("SELECT id, company_id, name FROM platoons ORDER BY company_id ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 4. Read and validate GET filters
$company_filter = trim((string)($_GET['company_id'] ?? ''));
$platoon_filter = trim((string)($_GET['platoon_id'] ?? ''));
$session_filter = (int)($_GET['session_id'] ?? 0);
$q_filter       = trim((string)($_GET['q'] ?? ''));
$page           = max(1, (int)($_GET['page'] ?? 1));

$filters = [
    'company_id' => $company_filter,
    'platoon_id' => $platoon_filter,
    'program_id' => $program_id,   // forced, ignores any ?program_id= in the URL
    'session_id' => $session_filter,
    'status'     => 'active',      // change to 'all' to also list dropped/transferred/graduated cadets
    'q'          => $q_filter,
];

// 5. Settings threshold
$at_risk_threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');

// 6. Data containers
$approved_sessions = [];
$all_term_sessions = [];
$cadets            = [];
$total_cadets      = 0;
$pages             = 1;
$attendance_matrix = [];
$cadet_stats       = [];
$session_totals    = [];
$summary           = null;
$from              = 0;
$to                = 0;

// Guard: with no program assigned, never query. A program_id of 0 could be read as "all programs".
if ($active_term && $has_program) {
    $term_id = (int)$active_term['id'];

    $all_term_sessions = get_approved_sessions($pdo, $term_id);
    $approved_sessions = get_approved_sessions(
        $pdo,
        $term_id,
        $company_filter,
        $platoon_filter,
        $session_filter > 0 ? $session_filter : null
    );

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
        $attendance_matrix = get_approved_attendance_matrix($pdo, $term_id, $cadet_ids, $session_ids);
        $cadet_stats = get_cadet_term_attendance_stats($pdo, $term_id, $cadet_ids);
    }

    if (!empty($approved_sessions)) {
        $session_ids = array_column($approved_sessions, 'id');
        $session_totals = get_session_totals_for_filtered_cadets($pdo, $term_id, $filters, $session_ids);
    }

    // Whole-filter summary (every matching cadet in the program, not just this page)
    if (!empty($cadets) && !empty($approved_sessions)) {
        $all_cadets = get_filtered_cadets($pdo, $term_id, $filters);
        $all_stats  = get_cadet_term_attendance_stats($pdo, $term_id, array_column($all_cadets, 'id'));

        $rated = 0;
        $sum_pct = 0.0;
        $risk_list = [];
        foreach ($all_cadets as $ac) {
            $st = $all_stats[(int)$ac['id']] ?? null;
            if (!$st || $st['percentage'] === null) continue;
            $rated++;
            $sum_pct += $st['percentage'];
            if ($st['percentage'] < $at_risk_threshold) {
                $ami = !empty($ac['middle_name']) ? ' ' . mb_strtoupper(mb_substr(trim($ac['middle_name']), 0, 1)) . '.' : '';
                $risk_list[] = [
                    'name'  => $ac['last_name'] . ', ' . $ac['first_name'] . $ami,
                    'where' => (!empty($ac['company_name']) ? 'Co. ' . $ac['company_name'] : 'Unassigned company')
                               . ' · ' . (!empty($ac['platoon_name']) ? $ac['platoon_name'] : 'Unassigned platoon'),
                    'pct'   => (float)$st['percentage'],
                ];
            }
        }
        usort($risk_list, fn($a, $b) => $a['pct'] <=> $b['pct']);

        $dist = ['p' => 0, 'l' => 0, 'a' => 0, 'e' => 0];
        foreach ($session_totals as $t) {
            foreach ($dist as $k => $_) $dist[$k] += (int)($t[$k] ?? 0);
        }
        $dates = array_column($approved_sessions, 'session_date');
        sort($dates);

        $summary = [
            'cadets'  => count($all_cadets),
            'rated'   => $rated,
            'avg'     => $rated > 0 ? $sum_pct / $rated : null,
            'at_risk' => $risk_list,
            'dist'    => $dist,
            'marks'   => array_sum($dist),
            'first'   => $dates ? $dates[0] : null,
            'last'    => $dates ? $dates[count($dates) - 1] : null,
        ];
    }
}

// Helper to preserve filters in links
function filter_query(array $overrides = []): string {
    $params = $_GET;
    unset($params['program_id'], $params['status']); // never carried: program is fixed, status is not a president filter
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

$base_url = BASE_URL . '/president/view_attendance.php';
$mark_names = ['P' => 'Present', 'L' => 'Late', 'A' => 'Absent', 'E' => 'Excused'];

// Active filter chips
$company_names = array_column($companies, 'name', 'id');
$platoon_names = array_column($platoons, 'name', 'id');
$session_names = [];
foreach ($all_term_sessions as $ts) {
    $session_names[(int)$ts['id']] = $ts['session_date'] . ': ' . $ts['label'];
}

$chips = [];
if ($q_filter !== '') $chips['q'] = 'Search: ' . $q_filter;
if ($company_filter !== '') {
    $chips['company_id'] = 'Company: ' . ($company_filter === 'unassigned' ? 'Unassigned' : ($company_names[(int)$company_filter] ?? $company_filter));
}
if ($platoon_filter !== '') {
    $chips['platoon_id'] = 'Platoon: ' . ($platoon_filter === 'unassigned' ? 'Unassigned' : ($platoon_names[(int)$platoon_filter] ?? $platoon_filter));
}
if ($session_filter > 0) $chips['session_id'] = 'Session: ' . ($session_names[$session_filter] ?? $session_filter);

// The Filters button counts everything except the search box (always visible)
$filter_count = count($chips) - (isset($chips['q']) ? 1 : 0);

$page_title = 'View Attendance - Class President';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

<div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>View Attendance</h1>
        <p style="margin: 0; color: var(--gray-700);">
            <strong>Read-only section attendance</strong>
            &nbsp;|&nbsp; Section: <span style="color: var(--green-700); font-weight: 600;"><?= e($prog_code) ?> &mdash; <?= e($prog_name) ?></span>
            <?php if ($active_term): ?>
                &nbsp;|&nbsp; Term: <span style="color: var(--green-700); font-weight: 600;"><?= e($active_term['name']) ?></span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!$has_program): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">No Section Assigned</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            Your account is not assigned to a program yet, so there is no attendance to show. Please contact the Admin.
        </p>
    </div>
<?php elseif (!$active_term): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">No Active Academic Term</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            There is currently no active academic term configured. Attendance data requires an active term.
        </p>
    </div>
<?php else: ?>

    <noscript><style>.s1-panel{display:block;position:static;width:auto;box-shadow:none;margin-top:8px}</style></noscript>

    <!-- Filters: same pattern as the S1 page, minus Program (fixed) and Cadet status -->
    <form method="GET" action="<?= e($base_url) ?>" id="attendance-filter-form" data-s1-filters role="search" aria-label="Filter attendance">
        <div class="s1-toolbar">
            <div class="s1-search">
                <svg class="s1-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <input type="search" id="q" name="q" class="form-control" value="<?= e($q_filter) ?>"
                       placeholder="Search cadet name (press / to focus)" autocomplete="off" aria-label="Search cadets">
                <button type="button" class="s1-search-clear" data-s1-search-clear aria-label="Clear search" <?= $q_filter === '' ? 'hidden' : '' ?>>&times;</button>
            </div>

            <div class="s1-filter-wrap">
                <button type="button" class="btn btn-secondary s1-filter-toggle <?= $filter_count > 0 ? 'has-filters' : '' ?>"
                        data-s1-toggle aria-haspopup="dialog" aria-expanded="false" aria-controls="s1FilterPanel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5z"/></svg>
                    Filters
                    <span class="s1-count" data-s1-count <?= $filter_count === 0 ? 'hidden' : '' ?>><?= (int)$filter_count ?></span>
                </button>

                <div class="s1-backdrop" data-s1-backdrop></div>

                <div class="s1-panel" id="s1FilterPanel" data-s1-panel role="dialog" aria-label="Filter attendance">
                    <div class="s1-panel-head">
                        <span class="s1-panel-title">Filters</span>
                        <button type="button" class="s1-panel-close" data-s1-close aria-label="Close filters">&times;</button>
                    </div>

                    <div class="s1-panel-body">
                        <div class="s1-grid">
                            <div class="s1-field">
                                <label for="company_id">Company</label>
                                <select name="company_id" id="company_id" class="form-control">
                                    <option value="">All companies</option>
                                    <option value="unassigned" <?= $company_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned only</option>
                                    <?php foreach ($companies as $co): ?>
                                        <option value="<?= (int)$co['id'] ?>" <?= $company_filter === (string)$co['id'] ? 'selected' : '' ?>>Company <?= e($co['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="s1-field">
                                <label for="platoon_id">Platoon</label>
                                <select name="platoon_id" id="platoon_id" class="form-control">
                                    <option value="">All platoons</option>
                                    <option value="unassigned" <?= $platoon_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned only</option>
                                    <?php foreach ($platoons as $pl): ?>
                                        <option value="<?= (int)$pl['id'] ?>" data-company="<?= (int)$pl['company_id'] ?>" <?= $platoon_filter === (string)$pl['id'] ? 'selected' : '' ?>>
                                            <?= e(($company_names[(int)$pl['company_id']] ?? '') . ' - ' . $pl['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="s1-hint" data-s1-platoon-hint hidden>Showing platoons of the selected company.</span>
                            </div>

                            <div class="s1-field s1-field-full">
                                <label for="session_id">Session</label>
                                <select name="session_id" id="session_id" class="form-control">
                                    <option value="0">All approved sessions</option>
                                    <?php foreach ($all_term_sessions as $ts): ?>
                                        <option value="<?= (int)$ts['id'] ?>" <?= $session_filter === (int)$ts['id'] ? 'selected' : '' ?>><?= e($ts['session_date']) ?>: <?= e($ts['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="s1-panel-foot">
                        <a class="s1-linkbtn" href="<?= e($base_url) ?>">Clear filters</a>
                        <button type="submit" class="btn btn-primary">Apply filters</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php if ($chips): ?>
        <div class="s1-chips" aria-label="Active filters">
            <span class="s1-chips-label">Active filters:</span>
            <?php foreach ($chips as $key => $text): ?>
                <span class="s1-chip">
                    <?= e($text) ?>
                    <a class="s1-chip-x" href="<?= e($base_url . filter_query([$key => null, 'page' => null])) ?>"
                       aria-label="Remove filter <?= e($text) ?>" title="Remove">&times;</a>
                </span>
            <?php endforeach; ?>
            <a class="s1-clear-all" href="<?= e($base_url) ?>">Clear all</a>
        </div>
    <?php endif; ?>

    <!-- Quick company switch -->
    <nav class="s1-seg" aria-label="Quick company filter">
        <span class="s1-seg-label">Company</span>
        <a class="s1-seg-item <?= $company_filter === '' ? 'active' : '' ?>" href="<?= e($base_url . filter_query(['company_id' => null, 'platoon_id' => null, 'page' => null])) ?>">All</a>
        <?php foreach ($companies as $co): ?>
            <a class="s1-seg-item <?= $company_filter === (string)$co['id'] ? 'active' : '' ?>"
               href="<?= e($base_url . filter_query(['company_id' => (int)$co['id'], 'platoon_id' => null, 'page' => null])) ?>"><?= e($co['name']) ?></a>
        <?php endforeach; ?>
        <a class="s1-seg-item <?= $company_filter === 'unassigned' ? 'active' : '' ?>" href="<?= e($base_url . filter_query(['company_id' => 'unassigned', 'platoon_id' => null, 'page' => null])) ?>">Unassigned</a>
    </nav>

    <!-- Results overview + legend -->
    <div class="s1-bar">
        <div>
            Showing <strong><?= $from ?>&ndash;<?= $to ?></strong> of <strong><?= $total_cadets ?></strong> cadet(s) in <?= e($prog_code) ?>
            &bull; <strong><?= count($approved_sessions) ?></strong> approved session column(s)
        </div>
        <div class="s1-legend" aria-label="Legend">
            <?php foreach ($mark_names as $k => $label): ?>
                <span><span class="s1-mark s1-mark-sm s1-mark-<?= $k ?>"><?= $k ?></span> <?= e($label) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($approved_sessions)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Approved Attendance Yet</h3>
            <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
                Attendance appears here once a session has been fully approved by both the Battalion S1 and Brigade S1.
            </p>
            <?php if ($filter_count > 0): ?>
                <a href="<?= e($base_url) ?>" class="btn btn-secondary btn-sm">Clear Filters</a>
            <?php endif; ?>
        </div>
    <?php elseif (empty($cadets)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Matching Cadets Found</h3>
            <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
                No cadets in <?= e($prog_code) ?> match your filters. Remove a filter chip above or reset everything.
            </p>
            <a href="<?= e($base_url) ?>" class="btn btn-secondary btn-sm">Reset Filters</a>
        </div>
    <?php else: ?>

        <?php
            $page_risk = 0;
            foreach ($cadets as $c) {
                $pp = $cadet_stats[(int)$c['id']]['percentage'] ?? null;
                if ($pp !== null && $pp < $at_risk_threshold) $page_risk++;
            }
        ?>

        <!-- Attendance matrix with navigation -->
        <section class="s1-matrix" aria-label="Attendance matrix">
            <div class="s1-matrix-nav" data-s1-nav-for="s1MatrixWrap">
                <span class="s1-matrix-tip">Drag the table sideways, use the arrows, or click a session date to focus on it.</span>
                <span class="s1-matrix-btns">
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-scroll="start" title="Jump to the first session">&laquo; First</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-scroll="prev" aria-label="Scroll left">&lsaquo; Earlier</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-scroll="next" aria-label="Scroll right">Later &rsaquo;</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-scroll="end" title="Jump to the latest session and the percentage">Latest &raquo;</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-risk-toggle aria-pressed="false" <?= $page_risk === 0 ? 'disabled' : '' ?>
                            title="Show only cadets below <?= (int)$at_risk_threshold ?>% on this page">At risk on this page (<?= $page_risk ?>)</button>
                </span>
            </div>

            <div class="s1-matrix-wrap" id="s1MatrixWrap" tabindex="0" role="region" aria-label="Attendance matrix, scrollable">
                <table class="s1-table" id="attendance-matrix-table">
                    <thead>
                        <tr>
                            <th class="s1-sticky-l">Cadet</th>
                            <th>Company</th>
                            <th>Platoon</th>
                            <?php foreach ($approved_sessions as $sess): ?>
                                <th class="s1-sess">
                                    <?php if ($session_filter === 0): ?>
                                        <a class="s1-sess-link" href="<?= e($base_url . filter_query(['session_id' => (int)$sess['id'], 'page' => null])) ?>" title="Show only <?= e($sess['label']) ?>">
                                            <b><?= e(date('M d', strtotime($sess['session_date']))) ?></b><span><?= e($sess['label']) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="s1-sess-link"><b><?= e(date('M d', strtotime($sess['session_date']))) ?></b><span><?= e($sess['label']) ?></span></span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="s1-sticky-r">
                                Attendance %
                                <span class="formula-info-btn" id="matrix-formula-info" title="Formula: (Present + Late) &divide; (Present + Absent + Late) &times; 100. Excused sessions are excluded from the denominator.">?</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cadets as $c): ?>
                            <?php
                                $cid = (int)$c['id'];
                                $mi = !empty($c['middle_name']) ? ' ' . mb_strtoupper(mb_substr(trim($c['middle_name']), 0, 1)) . '.' : '';
                                $full_name = $c['last_name'] . ', ' . $c['first_name'] . $mi;
                                $pct = $cadet_stats[$cid]['percentage'] ?? null;
                                $is_at_risk = ($pct !== null && $pct < $at_risk_threshold);
                                $tone = s1_tone($pct, $at_risk_threshold);
                            ?>
                            <tr <?= $is_at_risk ? 'data-risk="1"' : '' ?>>
                                <td class="s1-sticky-l">
                                    <div class="s1-cadet-name"><?= e($full_name) ?></div>
                                </td>
                                <td><?= !empty($c['company_name']) ? 'Co. ' . e($c['company_name']) : '<span class="s1-muted">Unassigned</span>' ?></td>
                                <td><?= !empty($c['platoon_name']) ? e($c['platoon_name']) : '<span class="s1-muted">Unassigned</span>' ?></td>

                                <?php foreach ($approved_sessions as $sess): ?>
                                    <?php
                                        $rec = $attendance_matrix[$cid][(int)$sess['id']] ?? null;
                                        $st = $rec['status'] ?? null;
                                    ?>
                                    <td class="s1-sess">
                                        <?php if ($st === null || !isset($mark_names[$st])): ?>
                                            <span class="s1-mark s1-mark-none" title="No record">&mdash;</span>
                                        <?php else: ?>
                                            <?php // Tooltip shows only the status. Excuse reasons and minutes late are not shown to presidents. ?>
                                            <span class="s1-mark s1-mark-<?= e($st) ?>" title="<?= e($sess['label'] . ' - ' . $mark_names[$st]) ?>"><?= e($st) ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>

                                <td class="s1-sticky-r">
                                    <?php if ($pct === null): ?>
                                        <span class="s1-tone-na">&mdash;</span>
                                    <?php else: ?>
                                        <span class="s1-pct s1-tone-<?= $tone ?>">
                                            <?php if ($is_at_risk): ?><span class="s1-risk-tag" title="Below <?= (int)$at_risk_threshold ?>%">At risk</span><?php endif; ?>
                                            <?= number_format($pct, 1) ?>%
                                            <span class="s1-pct-bar" aria-hidden="true"><i class="s1-fill-<?= $tone ?>" style="width: <?= max(0, min(100, round($pct))) ?>%"></i></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="s1-sticky-l">Session rate (all matching)</td>
                            <td colspan="2" class="s1-tf-rate s1-tone-na">(Present + Late) of marked</td>
                            <?php foreach ($approved_sessions as $sess): ?>
                                <?php
                                    $t = $session_totals[(int)$sess['id']] ?? ['p' => 0, 'a' => 0, 'l' => 0, 'e' => 0];
                                    $den = (int)$t['p'] + (int)$t['l'] + (int)$t['a'];
                                    $rate = $den > 0 ? round(((int)$t['p'] + (int)$t['l']) * 100 / $den, 1) : null;
                                ?>
                                <td class="s1-sess s1-tf-rate s1-tone-<?= s1_tone($rate, $at_risk_threshold) ?>"
                                    title="Present <?= (int)$t['p'] ?> · Late <?= (int)$t['l'] ?> · Absent <?= (int)$t['a'] ?> · Excused <?= (int)$t['e'] ?>">
                                    <?= $rate === null ? '&mdash;' : number_format($rate, 0) . '%' ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="s1-sticky-r s1-tone-<?= s1_tone($summary['avg'] ?? null, $at_risk_threshold) ?>">
                                <?= isset($summary['avg']) ? 'Avg ' . number_format($summary['avg'], 1) . '%' : '&mdash;' ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- Visual summary (all matching cadets in the program, not only this page) -->
        <?php if ($summary): ?>
            <?php
                $avg = $summary['avg'];
                $avg_tone = s1_tone($avg, $at_risk_threshold);
                $risk_n = count($summary['at_risk']);
                $risk_share = $summary['rated'] > 0 ? round($risk_n * 100 / $summary['rated']) : 0;
                $tot_marks = max(1, (int)$summary['marks']);
                $mix = ['p' => 'Present', 'l' => 'Late', 'a' => 'Absent', 'e' => 'Excused'];
            ?>
            <section class="s1-summary" aria-labelledby="s1SumTitle">
                <div class="s1-summary-head">
                    <h2 id="s1SumTitle">Section summary</h2>
                    <span>Covers all <?= (int)$summary['cadets'] ?> matching cadet(s) and <?= count($approved_sessions) ?> session(s), not just this page.</span>
                </div>

                <div class="s1-kpis">
                    <div class="s1-kpi <?= $avg_tone === 'bad' ? 'is-bad' : '' ?>">
                        <span class="s1-kpi-label">Average attendance</span>
                        <strong class="s1-kpi-val s1-tone-<?= $avg_tone ?>"><?= $avg === null ? '&mdash;' : number_format($avg, 1) . '%' ?></strong>
                        <div class="s1-meter" aria-hidden="true">
                            <i class="s1-fill-<?= $avg_tone === 'bad' ? 'bad' : 'good' ?>" style="width: <?= $avg === null ? 0 : max(0, min(100, round($avg, 1))) ?>%"></i>
                            <b style="left: <?= max(0, min(100, (int)$at_risk_threshold)) ?>%" title="At-risk threshold"></b>
                        </div>
                        <small>Threshold line at <?= (int)$at_risk_threshold ?>%</small>
                    </div>
                    <div class="s1-kpi <?= $risk_n > 0 ? 'is-bad' : '' ?>">
                        <span class="s1-kpi-label">Cadets at risk</span>
                        <strong class="s1-kpi-val <?= $risk_n > 0 ? 's1-tone-bad' : 's1-tone-good' ?>"><?= $risk_n ?></strong>
                        <small><?= $summary['rated'] > 0 ? $risk_share . '% of ' . (int)$summary['rated'] . ' rated cadets' : 'No rated cadets yet' ?></small>
                    </div>
                    <div class="s1-kpi is-gold">
                        <span class="s1-kpi-label">Approved sessions</span>
                        <strong class="s1-kpi-val"><?= count($approved_sessions) ?></strong>
                        <small><?= $summary['first'] ? e(date('M d', strtotime($summary['first']))) . ' &ndash; ' . e(date('M d, Y', strtotime($summary['last']))) : '&mdash;' ?></small>
                    </div>
                    <div class="s1-kpi is-gold">
                        <span class="s1-kpi-label">Marks recorded</span>
                        <strong class="s1-kpi-val"><?= number_format((int)$summary['marks']) ?></strong>
                        <small>Across <?= (int)$summary['cadets'] ?> cadet(s)</small>
                    </div>
                </div>

                <div class="s1-sum-grid">
                    <div class="s1-card">
                        <h3>Overall status mix</h3>
                        <?= s1_stack($summary['dist'], 's1-stack-lg') ?>
                        <ul class="s1-mix">
                            <?php foreach ($mix as $k => $label): ?>
                                <li>
                                    <span class="s1-mark s1-mark-sm s1-mark-<?= strtoupper($k) ?>"><?= strtoupper($k) ?></span>
                                    <?= e($label) ?>
                                    <strong><?= number_format((int)$summary['dist'][$k]) ?></strong>
                                    <em><?= round($summary['dist'][$k] * 100 / $tot_marks, 1) ?>%</em>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="s1-card">
                        <h3>Needs attention <?= $risk_n > 0 ? '(lowest attendance first)' : '' ?></h3>
                        <?php if ($risk_n === 0): ?>
                            <div class="s1-attn-ok">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                No cadet is below <?= (int)$at_risk_threshold ?>%. Nothing to follow up.
                            </div>
                        <?php else: ?>
                            <ul class="s1-attn">
                                <?php foreach (array_slice($summary['at_risk'], 0, 5) as $r): ?>
                                    <li>
                                        <span class="s1-attn-name"><?= e($r['name']) ?></span>
                                        <span class="s1-attn-pct"><?= number_format($r['pct'], 1) ?>%</span>
                                        <span class="s1-attn-sub"><?= e($r['where']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($risk_n > 5): ?>
                                <p class="s1-attn-more">+ <?= $risk_n - 5 ?> more below <?= (int)$at_risk_threshold ?>%. Scroll the table above to see everyone.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="s1-card">
                    <h3>By session <span style="font-weight: 400; color: var(--s1-muted);">(rate = Present + Late of marked, excused excluded)</span></h3>
                    <ul class="s1-sessions">
                        <?php foreach ($approved_sessions as $sess): ?>
                            <?php
                                $t = $session_totals[(int)$sess['id']] ?? ['p' => 0, 'a' => 0, 'l' => 0, 'e' => 0];
                                $den = (int)$t['p'] + (int)$t['l'] + (int)$t['a'];
                                $rate = $den > 0 ? round(((int)$t['p'] + (int)$t['l']) * 100 / $den, 1) : null;
                            ?>
                            <li class="s1-srow">
                                <div class="s1-srow-name">
                                    <?php if ($session_filter === 0): ?>
                                        <a href="<?= e($base_url . filter_query(['session_id' => (int)$sess['id'], 'page' => null])) ?>"><?= e($sess['label']) ?></a>
                                    <?php else: ?>
                                        <strong><?= e($sess['label']) ?></strong>
                                    <?php endif; ?>
                                    <small><?= e(date('M d, Y', strtotime($sess['session_date']))) ?></small>
                                </div>
                                <?= s1_stack($t) ?>
                                <div class="s1-srow-rate s1-tone-<?= s1_tone($rate, $at_risk_threshold) ?>"><?= $rate === null ? '&mdash;' : number_format($rate, 0) . '%' ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <div class="s1-note">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <div>
                <strong>Read-only view.</strong> You can only see <?= e($prog_code) ?> cadets. Only fully approved sessions are shown.
                <strong>Attendance formula:</strong> <code>(Present + Late) &divide; (Present + Absent + Late) &times; 100</code>.
                Excused sessions are excluded from the denominator. Cadets below <strong><?= (int)$at_risk_threshold ?>%</strong> are marked <strong>At risk</strong>.
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <div class="s1-pager">
                <div>Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong></div>
                <div class="s1-pager-btns">
                    <?php if ($page > 1): ?>
                        <a href="<?= e($base_url . filter_query(['page' => 1])) ?>" class="btn btn-secondary btn-sm">&laquo; First</a>
                        <a href="<?= e($base_url . filter_query(['page' => $page - 1])) ?>" class="btn btn-secondary btn-sm">&lsaquo; Prev</a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                        <a href="<?= e($base_url . filter_query(['page' => $p])) ?>" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e($base_url . filter_query(['page' => $page + 1])) ?>" class="btn btn-secondary btn-sm">Next &rsaquo;</a>
                        <a href="<?= e($base_url . filter_query(['page' => $pages])) ?>" class="btn btn-secondary btn-sm">Last &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/view_attendance.js"></script>
<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
