<?php
// S1 Officer Dashboard (Stage 5: Shared for Battalion S1 & Brigade S1)
// Markup only: styles live in assets/css/dashboard.css, behaviour in assets/js/dashboard.js
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

/* ------------------------------------------------------------------
 * Data helpers (each one fails soft so the page works before tables exist)
 * ------------------------------------------------------------------ */

function db_active_term_id(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

/** Cadet counts. "Unassigned" = active cadet with no platoon in the active term (not a stored status). */
function db_cadet_counts(PDO $pdo, int $term_id): array
{
    $counts = ['active' => 0, 'unassigned' => 0, 'dropped' => 0, 'transferred' => 0, 'graduated' => 0];
    try {
        $stmt = $pdo->prepare("
            SELECT CASE WHEN c.status = 'active' AND e.platoon_id IS NULL THEN 'unassigned' ELSE c.status END AS status,
                   COUNT(*) AS n
            FROM cadets c
            LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
            GROUP BY 1
        ");
        $stmt->execute([$term_id]);
        foreach ($stmt as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['n'];
            }
        }
    } catch (Throwable $ex) {
        // table missing: keep zeros
    }
    return $counts;
}

/** Attendance submissions waiting for THIS account's approval (active term). */
function db_pending_reviews(PDO $pdo, array $user, int $term_id): int
{
    try {
        $col = ($user['role'] === 'brigade_s1') ? 'brigade_approved_at' : 'battalion_approved_at';
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM attendance_submissions sub
            JOIN training_sessions ts ON ts.id = sub.session_id
            WHERE ts.term_id = ? AND sub.state = 'submitted' AND sub.$col IS NULL
        ");
        $stmt->execute([$term_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

/** Cumulative cadets added per day, for the line graph. */
function db_cadet_timeline(PDO $pdo): array
{
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
    return $timeline;
}

/** Small inline icon set (24x24, stroke based) so no icon font is needed. */
function db_icon(string $name): string
{
    $paths = [
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'check'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/>',
        'alert'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'archive'  => '<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
        'clipboard'=> '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="m9 14 2 2 4-4"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'badge'    => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'arrow'    => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'table'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
    ];
    return '<svg class="db-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . ($paths[$name] ?? '') . '</svg>';
}

/* ------------------------------------------------------------------
 * Gather data
 * ------------------------------------------------------------------ */
$term_id         = db_active_term_id($pdo);
$counts          = db_cadet_counts($pdo, $term_id);
$pending_reviews = db_pending_reviews($pdo, $user, $term_id);
$timeline        = db_cadet_timeline($pdo);

$total_cadets    = array_sum($counts);
$inactive_cadets = $counts['dropped'] + $counts['transferred'] + $counts['graduated'];

// Payload for dashboard.js (HEX flags make it safe inside an HTML attribute)
$chart_payload = [
    'status' => [
        ['key' => 'active',     'label' => 'Active',     'value' => $counts['active'],     'color' => '#2E7D32',
         'hint' => 'Assigned to a platoon'],
        ['key' => 'unassigned', 'label' => 'Unassigned', 'value' => $counts['unassigned'], 'color' => '#B26A00',
         'hint' => 'Needs a company and platoon'],
        ['key' => 'inactive',   'label' => 'Inactive',   'value' => $inactive_cadets,      'color' => '#6B7280',
         'hint' => 'Dropped ' . $counts['dropped'] . ' · Transferred ' . $counts['transferred'] . ' · Graduated ' . $counts['graduated']],
    ],
    'timeline' => $timeline,
    'rosterUrl' => BASE_URL . '/s1/roster.php',
];

$role_name  = format_role_name($user['role']);
$page_title = $role_name . ' Dashboard';
$todo_count = $counts['unassigned'] + $pending_reviews;

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css">

<div class="db" id="dashboard">

    <!-- ===== Page header ===== -->
    <header class="db-hero db-reveal">
        <div>
            <p class="db-eyebrow"><?= e($role_name) ?></p>
            <h1>Dashboard</h1>
            <p class="db-sub">Cadet roster oversight and two-level attendance review</p>
        </div>
        <div class="db-hero-actions">
            <a href="<?= BASE_URL ?>/s1/import_cadets.php" class="btn btn-primary db-btn-icon">
                <?= db_icon('upload') ?> Import cadets
            </a>
            <a href="<?= BASE_URL ?>/s1/roster.php" class="btn btn-secondary db-btn-icon">
                <?= db_icon('list') ?> View roster
            </a>
        </div>
    </header>

    <!-- ===== Needs attention ===== -->
    <?php if ($todo_count > 0): ?>
    <section class="db-attention db-reveal" aria-label="Items that need your attention">
        <span class="db-attention-title"><?= db_icon('alert') ?> Needs your attention</span>
        <?php if ($pending_reviews > 0): ?>
            <a class="db-chip db-chip-warn" href="<?= BASE_URL ?>/s1/review_attendance.php">
                <strong><?= $pending_reviews ?></strong> attendance <?= $pending_reviews === 1 ? 'submission' : 'submissions' ?> to review
                <?= db_icon('arrow') ?>
            </a>
        <?php endif; ?>
        <?php if ($counts['unassigned'] > 0): ?>
            <a class="db-chip db-chip-warn" href="<?= BASE_URL ?>/s1/roster.php?status=unassigned">
                <strong><?= $counts['unassigned'] ?></strong> unassigned <?= $counts['unassigned'] === 1 ? 'cadet' : 'cadets' ?>
                <?= db_icon('arrow') ?>
            </a>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===== KPI cards ===== -->
    <section class="db-kpis" aria-label="Key figures">
        <a class="db-kpi db-reveal" style="--accent: var(--green-900)" href="<?= BASE_URL ?>/s1/roster.php">
            <span class="db-kpi-icon"><?= db_icon('users') ?></span>
            <span class="db-kpi-label">Total cadets</span>
            <span class="db-kpi-number" data-count="<?= $total_cadets ?>"><?= $total_cadets ?></span>
            <span class="db-kpi-link">View roster <?= db_icon('arrow') ?></span>
        </a>
        <a class="db-kpi db-reveal" style="--accent: var(--success)" href="<?= BASE_URL ?>/s1/roster.php?status=active">
            <span class="db-kpi-icon"><?= db_icon('check') ?></span>
            <span class="db-kpi-label">Active (assigned)</span>
            <span class="db-kpi-number" data-count="<?= $counts['active'] ?>"><?= $counts['active'] ?></span>
            <span class="db-kpi-link">View active <?= db_icon('arrow') ?></span>
        </a>
        <a class="db-kpi db-reveal" style="--accent: var(--warning)" href="<?= BASE_URL ?>/s1/roster.php?status=unassigned">
            <span class="db-kpi-icon"><?= db_icon('alert') ?></span>
            <span class="db-kpi-label">Unassigned</span>
            <span class="db-kpi-number" data-count="<?= $counts['unassigned'] ?>"><?= $counts['unassigned'] ?></span>
            <span class="db-kpi-link">Assign cadets <?= db_icon('arrow') ?></span>
        </a>
        <div class="db-kpi db-kpi-static db-reveal" style="--accent: var(--gray-700)">
            <span class="db-kpi-icon"><?= db_icon('archive') ?></span>
            <span class="db-kpi-label">Inactive</span>
            <span class="db-kpi-number" data-count="<?= $inactive_cadets ?>"><?= $inactive_cadets ?></span>
            <span class="db-kpi-note">Dropped, transferred or graduated</span>
        </div>
        <a class="db-kpi db-reveal <?= $pending_reviews > 0 ? 'db-kpi-pulse' : '' ?>"
           style="--accent: <?= $pending_reviews > 0 ? 'var(--warning)' : 'var(--green-900)' ?>"
           href="<?= BASE_URL ?>/s1/review_attendance.php">
            <span class="db-kpi-icon"><?= db_icon('clipboard') ?></span>
            <span class="db-kpi-label">Awaiting my review</span>
            <span class="db-kpi-number" data-count="<?= $pending_reviews ?>"><?= $pending_reviews ?></span>
            <span class="db-kpi-link">Review attendance <?= db_icon('arrow') ?></span>
        </a>
    </section>

    <!-- ===== Cadet overview (charts) ===== -->
    <section class="db-card db-reveal" id="cadet-overview"
             data-dashboard="<?= e(json_encode($chart_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
        <div class="db-card-head">
            <div>
                <h2>Cadet overview</h2>
                <p class="db-muted" id="chart-note"></p>
            </div>
            <div class="db-controls">
                <div class="db-segmented" role="tablist" aria-label="Chart type">
                    <button type="button" role="tab" id="tab-pie" aria-controls="chart-panel" aria-selected="true" data-view="pie">Status breakdown</button>
                    <button type="button" role="tab" id="tab-line" aria-controls="chart-panel" aria-selected="false" data-view="line" tabindex="-1">Growth over time</button>
                    <span class="db-segmented-thumb" aria-hidden="true"></span>
                </div>
            </div>
        </div>

        <div class="db-range" id="range-controls" hidden>
            <span class="db-muted">Range</span>
            <div class="db-pills" role="group" aria-label="Time range">
                <button type="button" data-range="7">7 days</button>
                <button type="button" data-range="30">30 days</button>
                <button type="button" data-range="0" class="is-on">All time</button>
            </div>
        </div>

        <div id="chart-panel" role="tabpanel" aria-labelledby="tab-pie" aria-live="polite">
            <div id="chart-area" class="db-chart-area"></div>
        </div>

        <div class="db-card-foot">
            <button type="button" class="db-link-btn" id="toggle-table" aria-expanded="false" aria-controls="chart-table-wrap">
                <?= db_icon('table') ?> <span>Show data as table</span>
            </button>
            <div id="chart-table-wrap" class="db-table-wrap" hidden></div>
        </div>
    </section>

    <!-- ===== Import guide ===== -->
    <section class="db-card db-reveal" aria-labelledby="import-heading">
        <div class="db-card-head">
            <div>
                <h2 id="import-heading">Add cadets in bulk</h2>
                <p class="db-muted">Upload a CSV (for example Google Form responses). You can preview and fix errors before anything is saved.</p>
            </div>
            <a href="<?= BASE_URL ?>/s1/import_cadets.php" class="btn btn-primary db-btn-icon"><?= db_icon('upload') ?> Start import</a>
        </div>

        <ol class="db-steps">
            <li class="db-step">
                <span class="db-step-num">1</span>
                <div>
                    <strong>Get the template</strong>
                    <p>Use our columns so every row validates.</p>
                    <a href="<?= BASE_URL ?>/s1/import_cadets.php?template=1"><?= db_icon('download') ?> Download template</a>
                </div>
            </li>
            <li class="db-step">
                <span class="db-step-num">2</span>
                <div>
                    <strong>Upload your CSV</strong>
                    <p>Drop in the filled-in file.</p>
                    <a href="<?= BASE_URL ?>/s1/import_cadets.php"><?= db_icon('upload') ?> Open importer</a>
                </div>
            </li>
            <li class="db-step">
                <span class="db-step-num">3</span>
                <div>
                    <strong>Preview and fix</strong>
                    <p>Errors are flagged before saving.</p>
                </div>
            </li>
            <li class="db-step">
                <span class="db-step-num">4</span>
                <div>
                    <strong>Assign to platoons</strong>
                    <p>Imported cadets start as Unassigned.</p>
                    <a href="<?= BASE_URL ?>/s1/roster.php?status=unassigned"><?= db_icon('users') ?> Assign cadets</a>
                </div>
            </li>
        </ol>
    </section>

    <!-- ===== Officers ===== -->
    <section class="db-card db-reveal" aria-labelledby="officers-heading">
        <div class="db-card-head">
            <div>
                <h2 id="officers-heading">Officers</h2>
                <p class="db-muted">View, edit and deactivate Company Commanders and Platoon Leaders, or upload a CSV to add more. Each company gets one commander and each platoon one leader.</p>
            </div>
        </div>
        <div class="db-actions">
            <a class="db-action" href="<?= BASE_URL ?>/s1/officers.php">
                <span class="db-action-icon"><?= db_icon('badge') ?></span>
                <span><strong>Officer roster</strong><small>View and edit officers</small></span>
            </a>
            <a class="db-action" href="<?= BASE_URL ?>/s1/import_officers.php">
                <span class="db-action-icon"><?= db_icon('upload') ?></span>
                <span><strong>Import officers</strong><small>Add from a CSV file</small></span>
            </a>
            <a class="db-action" href="<?= BASE_URL ?>/s1/import_officers.php?template=1">
                <span class="db-action-icon"><?= db_icon('download') ?></span>
                <span><strong>Download template</strong><small>Officer CSV columns</small></span>
            </a>
        </div>
    </section>
</div>

<script src="<?= BASE_URL ?>/assets/js/dashboard.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>