<?php
// S1 Officer Dashboard (Stage 5: Shared for Battalion S1 & Brigade S1)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

// Cadet counts by status (safe if the cadets table has not been created yet)
$counts = ['active' => 0, 'unassigned' => 0, 'dropped' => 0, 'transferred' => 0, 'graduated' => 0];
try {
    // "Unassigned" = active cadet with no platoon in the active term (not a stored status)
    $term_id = (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();
    foreach ($pdo->query("
        SELECT CASE WHEN c.status = 'active' AND e.platoon_id IS NULL THEN 'unassigned' ELSE c.status END AS status,
               COUNT(*) AS n
        FROM cadets c
        LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = {$term_id}
        GROUP BY 1
    ") as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int)$row['n'];
        }
    }
} catch (Throwable $ex) {
    // table missing: keep zeros
}
$total_cadets = array_sum($counts);

// Attendance submissions waiting for THIS account's approval (active term)
$pending_reviews = 0;
try {
    $my_col = ($user['role'] === 'brigade_s1') ? 'brigade_approved_at' : 'battalion_approved_at';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM attendance_submissions sub
        JOIN training_sessions ts ON ts.id = sub.session_id
        WHERE ts.term_id = ? AND sub.state = 'submitted' AND sub.$my_col IS NULL
    ");
    $stmt->execute([(int)($term_id ?? 0)]);
    $pending_reviews = (int)$stmt->fetchColumn();
} catch (Throwable $ex) {
    // tables missing: keep zero
}

// Cadets added over time (cumulative), for the line graph
$timeline = [];
try {
    $running = 0;
    foreach ($pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM cadets GROUP BY DATE(created_at) ORDER BY d") as $row) {
        $running += (int)$row['n'];
        $timeline[] = ['date' => $row['d'], 'added' => (int)$row['n'], 'total' => $running];
    }
} catch (Throwable $ex) {
    // table missing: keep empty
}
$inactive_cadets = $counts['dropped'] + $counts['transferred'] + $counts['graduated'];

$page_title = format_role_name($user['role']) . ' Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><?= e(format_role_name($user['role'])) ?> Dashboard</h1>
    <p>Cadet roster oversight and two-level attendance review</p>
</div>

<div class="summary-card" style="margin-bottom: 24px;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px;">
        <h3 style="font-size: 16px; color: var(--green-900); margin: 0;">Cadet Overview</h3>
        <div>
            <label for="chart-type" style="font-size: 13px; margin-right: 8px;">View as</label>
            <select id="chart-type" class="form-control" style="width: auto; display: inline-block;">
                <option value="pie">Pie chart: status breakdown</option>
                <option value="line">Line graph: cadets added over time</option>
            </select>
        </div>
    </div>
    <div id="chart-area"></div>
    <p id="chart-note" style="font-size: 12px; color: var(--gray-700); margin-top: 12px;"></p>
</div>

<script>
(function () {
    var statusData = <?= json_encode([
        ['label' => 'Active',     'value' => $counts['active'],     'color' => '#2E7D32'],
        ['label' => 'Unassigned', 'value' => $counts['unassigned'], 'color' => '#B26A00'],
        ['label' => 'Inactive',   'value' => $inactive_cadets,      'color' => '#4A4F47'],
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var timeData = <?= json_encode($timeline, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var area = document.getElementById('chart-area');
    var note = document.getElementById('chart-note');
    var select = document.getElementById('chart-type');

    function shortDate(d) {
        var parts = String(d).split('-');
        return months[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10);
    }

    function renderPie() {
        var total = statusData.reduce(function (s, d) { return s + d.value; }, 0);
        note.textContent = 'Inactive = dropped, transferred or graduated. Active cadets are assigned to a platoon.';
        if (total === 0) {
            area.innerHTML = '<p style="color: var(--gray-700);">No cadets yet. Import a CSV to see the breakdown.</p>';
            return;
        }
        var cx = 110, cy = 110, r = 100, start = -Math.PI / 2, shapes = '';
        statusData.forEach(function (d) {
            if (d.value === 0) return;
            if (d.value === total) {
                shapes += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + d.color + '"><title>' + d.label + ': ' + d.value + '</title></circle>';
                return;
            }
            var angle = d.value / total * 2 * Math.PI, end = start + angle;
            var x1 = cx + r * Math.cos(start), y1 = cy + r * Math.sin(start);
            var x2 = cx + r * Math.cos(end), y2 = cy + r * Math.sin(end);
            shapes += '<path d="M' + cx + ' ' + cy + ' L' + x1.toFixed(2) + ' ' + y1.toFixed(2) +
                ' A' + r + ' ' + r + ' 0 ' + (angle > Math.PI ? 1 : 0) + ' 1 ' + x2.toFixed(2) + ' ' + y2.toFixed(2) +
                ' Z" fill="' + d.color + '" stroke="#FFFFFF" stroke-width="2"><title>' + d.label + ': ' + d.value + '</title></path>';
            start = end;
        });
        var legend = '<div style="font-size: 14px; font-weight: 700; color: var(--green-900); margin-bottom: 10px;">Total cadets: ' + total + '</div>';
        statusData.forEach(function (d) {
            var pct = (d.value / total * 100).toFixed(1);
            legend += '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 14px;">' +
                '<span style="display: inline-block; width: 14px; height: 14px; border-radius: 3px; background: ' + d.color + ';"></span>' +
                '<span>' + d.label + ': <strong>' + d.value + '</strong> (' + pct + '%)</span></div>';
        });
        area.innerHTML = '<div style="display: flex; flex-wrap: wrap; gap: 32px; align-items: center;">' +
            '<svg viewBox="0 0 220 220" width="220" height="220" role="img" aria-label="Pie chart of cadets by status">' + shapes + '</svg>' +
            '<div>' + legend + '</div></div>';
    }

    function renderLine() {
        note.textContent = 'Shows the running total of cadets by the date they were added to the system.';
        if (!timeData.length) {
            area.innerHTML = '<p style="color: var(--gray-700);">No cadets yet. Import a CSV to see the graph.</p>';
            return;
        }
        var W = 620, H = 280, pl = 44, pr = 16, pt = 16, pb = 48;
        var maxY = Math.max.apply(null, timeData.map(function (p) { return p.total; }));
        var step = Math.max(1, Math.ceil(maxY / 4)), top = step * 4;
        var n = timeData.length;
        function xAt(i) { return n === 1 ? pl + (W - pl - pr) / 2 : pl + i * (W - pl - pr) / (n - 1); }
        function yAt(v) { return H - pb - (v / top) * (H - pt - pb); }

        var grid = '';
        for (var t = 0; t <= 4; t++) {
            var v = t * step, y = yAt(v);
            grid += '<line x1="' + pl + '" y1="' + y + '" x2="' + (W - pr) + '" y2="' + y + '" stroke="#D5D9D2" stroke-width="1"/>' +
                '<text x="' + (pl - 8) + '" y="' + (y + 4) + '" text-anchor="end" font-size="11" fill="#4A4F47">' + v + '</text>';
        }
        var points = timeData.map(function (p, i) { return xAt(i).toFixed(1) + ',' + yAt(p.total).toFixed(1); }).join(' ');
        var dots = '', labels = '', every = Math.ceil(n / 8);
        timeData.forEach(function (p, i) {
            dots += '<circle cx="' + xAt(i).toFixed(1) + '" cy="' + yAt(p.total).toFixed(1) + '" r="4" fill="#2E7D32"><title>' +
                shortDate(p.date) + ': ' + p.total + ' total (+' + p.added + ' added)</title></circle>';
            if (i % every === 0 || i === n - 1) {
                labels += '<text x="' + xAt(i).toFixed(1) + '" y="' + (H - pb + 20) + '" text-anchor="middle" font-size="11" fill="#4A4F47">' + shortDate(p.date) + '</text>';
            }
        });
        area.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width: 100%; max-width: ' + W + 'px;" role="img" aria-label="Line graph of total cadets over time">' +
            grid + (n > 1 ? '<polyline points="' + points + '" fill="none" stroke="#2E7D32" stroke-width="2.5"/>' : '') +
            dots + labels + '</svg>';
    }

    function render() { if (select.value === 'line') renderLine(); else renderPie(); }
    select.addEventListener('change', render);
    render();
})();
</script>

<div class="summary-cards">
    <div class="summary-card">
        <h3>Total Cadets</h3>
        <div class="metric-number" style="color: var(--green-900);"><?= $total_cadets ?></div>
        <p><a href="<?= BASE_URL ?>/s1/roster.php">View Roster &rarr;</a></p>
    </div>
    <div class="summary-card">
        <h3>Active (Assigned)</h3>
        <div class="metric-number" style="color: var(--success);"><?= $counts['active'] ?></div>
        <p><a href="<?= BASE_URL ?>/s1/roster.php?status=active">View active &rarr;</a></p>
    </div>
    <div class="summary-card">
        <h3>Unassigned</h3>
        <div class="metric-number" style="color: var(--warning);"><?= $counts['unassigned'] ?></div>
        <p><a href="<?= BASE_URL ?>/s1/roster.php?status=unassigned">Assign cadets &rarr;</a></p>
    </div>
    <div class="summary-card">
        <h3>Inactive</h3>
        <div class="metric-number" style="color: var(--gray-700);"><?= $inactive_cadets ?></div>
        <p style="color: var(--gray-700); font-size: 13px;">Dropped, transferred or graduated</p>
    </div>
    <div class="summary-card">
        <h3>Awaiting My Review</h3>
        <div class="metric-number" style="color: <?= $pending_reviews > 0 ? 'var(--warning)' : 'var(--green-900)' ?>;"><?= $pending_reviews ?></div>
        <p><a href="<?= BASE_URL ?>/s1/review_attendance.php">Review attendance &rarr;</a></p>
    </div>
</div>

<div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 8px;">Cadet Roster</h3>
    <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
        Imported cadets start as Unassigned. Assign each one to a company and platoon to make them Active.
        Upload a CSV file (such as Google Form responses) to add cadets in bulk. You can preview and fix errors before anything is saved.
    </p>
    <a href="<?= BASE_URL ?>/s1/import_cadets.php" class="btn btn-primary">Import Cadets (CSV)</a>
    <a href="<?= BASE_URL ?>/s1/roster.php" class="btn btn-secondary">View Roster</a>
    <a href="<?= BASE_URL ?>/s1/import_cadets.php?template=1" class="btn btn-secondary">Download Template</a>
</div>

<div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 8px;">Officers</h3>
    <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">
        Upload a CSV of Company Commanders and Platoon Leaders. Each company gets one commander and each platoon one leader. You can preview and fix errors before anything is saved.
    </p>
    <a href="<?= BASE_URL ?>/s1/import_officers.php" class="btn btn-primary">Import Officers (CSV)</a>
    <a href="<?= BASE_URL ?>/s1/import_officers.php?template=1" class="btn btn-secondary">Download Template</a>
</div>

<div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; max-width: 650px;">
    <h4 style="color: var(--warning); margin-bottom: 8px;">MVP Status: Dashboard Active</h4>
    <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 8px;">
        Built so far for this role: cadet roster (search, filter, edit, assign to company and platoon), CSV import, and attendance review (approve or return submitted sessions).
    </p>
    <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 4px;"><strong>Still missing:</strong></p>
    <ul style="font-size: 13px; color: var(--gray-700); padding-left: 20px;">
        <li>View attendance: roster table with a column per approved session, totals, attendance % and export</li>
        <li>Bulk assign cadets to a company and platoon from the roster</li>
    </ul>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>