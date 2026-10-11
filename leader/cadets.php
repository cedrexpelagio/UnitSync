<?php
// Platoon Leader: View Cadets Roster (Stage PL-4)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('platoon_leader');

$user = current_user();

const CADETS_PAGE_SIZE = 25;

// Status labels mapping
$status_labels = [
    'active'      => 'Active',
    'dropped'     => 'Dropped',
    'transferred' => 'Transferred',
    'graduated'   => 'Graduated',
];

// 1. Fetch assignment details for this Platoon Leader
$stmt = $pdo->prepare("
    SELECT ua.company_id, ua.platoon_id, c.name AS company_name, p.name AS platoon_name 
    FROM user_assignments ua 
    LEFT JOIN companies c ON ua.company_id = c.id 
    LEFT JOIN platoons p ON ua.platoon_id = p.id 
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();

$company_id   = $assignment['company_id'] ?? null;
$platoon_id   = $assignment['platoon_id'] ?? null;
$company_name = $assignment['company_name'] ?? 'Unassigned';
$platoon_name = $assignment['platoon_name'] ?? 'Unassigned';

// 2. Fetch current active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

// 3. Handle Remove from Platoon (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_cadet') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('leader/cadets.php');
    }

    if (!$active_term) {
        set_flash('error', 'Cannot remove cadet: No active academic term.');
        redirect('leader/cadets.php');
    }

    if (!$platoon_id) {
        set_flash('error', 'Cannot remove cadet: You are not assigned to a platoon.');
        redirect('leader/cadets.php');
    }

    $cadet_id = (int)($_POST['cadet_id'] ?? 0);
    $return_q = trim($_POST['return_q'] ?? '');
    $return_page = max(1, (int)($_POST['return_page'] ?? 1));

    // Verify cadet belongs to leader's platoon for the active term
    $stmt = $pdo->prepare("
        SELECT c.id, c.cadet_code, c.last_name, c.first_name, e.id AS enrollment_id
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
        WHERE c.id = ? AND e.platoon_id = ?
    ");
    $stmt->execute([(int)$active_term['id'], $cadet_id, (int)$platoon_id]);
    $cadet_to_remove = $stmt->fetch();

    if (!$cadet_to_remove) {
        set_flash('error', 'Cadet not found in your platoon or already unassigned.');
        redirect('leader/cadets.php');
    }

    try {
        $pdo->beginTransaction();

        // Unassign from platoon (sets company_id and platoon_id to NULL)
        $stmt = $pdo->prepare("
            UPDATE enrollments 
            SET company_id = NULL, platoon_id = NULL, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([(int)$cadet_to_remove['enrollment_id']]);

        // Write audit log
        log_audit(
            $pdo,
            $user['id'],
            'remove_from_platoon',
            'cadet',
            (int)$cadet_to_remove['id'],
            json_encode([
                'cadet_code'          => $cadet_to_remove['cadet_code'],
                'name'                => "{$cadet_to_remove['last_name']}, {$cadet_to_remove['first_name']}",
                'term_id'             => (int)$active_term['id'],
                'previous_company_id' => $company_id,
                'previous_platoon_id' => $platoon_id,
                'status'              => 'unassigned',
                'action_taken'        => 'Removed from platoon by Platoon Leader'
            ])
        );

        $pdo->commit();

        set_flash(
            'success',
            'Cadet ' . e($cadet_to_remove['first_name'] . ' ' . $cadet_to_remove['last_name']) .
            ' (' . e($cadet_to_remove['cadet_code']) . ') was removed from your platoon and is now Unassigned.'
        );

        $params = [];
        if ($return_q !== '') $params['q'] = $return_q;
        if ($return_page > 1) $params['page'] = $return_page;
        $qs = http_build_query($params);

        redirect('leader/cadets.php' . ($qs !== '' ? '?' . $qs : ''));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Failed to remove cadet: ' . $e->getMessage());
        redirect('leader/cadets.php');
    }
}

// 4. Search and pagination filters
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));

$cadets = [];
$total = 0;
$pages = 1;
$from = 0;
$to = 0;

if ($active_term && $platoon_id) {
    $where = ['e.term_id = :term_id', 'e.platoon_id = :platoon_id'];
    $params = [
        'term_id'    => (int)$active_term['id'],
        'platoon_id' => (int)$platoon_id
    ];

    if ($q !== '') {
        $where[] = '(c.last_name LIKE :q OR c.first_name LIKE :q OR c.middle_name LIKE :q OR c.student_number LIKE :q OR c.cadet_code LIKE :q)';
        $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    // Count total matching cadets
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cadets c 
        JOIN enrollments e ON e.cadet_id = c.id 
        $where_sql
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / CADETS_PAGE_SIZE));
    $page = min($page, $pages);
    $offset = ($page - 1) * CADETS_PAGE_SIZE;
    $limit = (int)CADETS_PAGE_SIZE;

    // Fetch cadets page
    $stmt = $pdo->prepare("
        SELECT c.id, c.cadet_code, c.last_name, c.first_name, c.middle_name,
               c.gender, c.student_number, c.email, c.contact_number, c.status,
               p.code AS program_code, p.name AS program_name
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id
        LEFT JOIN programs p ON c.program_id = p.id
        $where_sql
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $cadets = $stmt->fetchAll();

    $from = $total === 0 ? 0 : $offset + 1;
    $to = min($offset + CADETS_PAGE_SIZE, $total);

    // Fetch at-risk threshold and calculate attendance percentage for page cadets
    $at_risk_threshold = (float)get_setting($pdo, 'attendance_at_risk_threshold', '80');
    $page_cadet_ids = array_map(fn($item) => (int)$item['id'], $cadets);
    $attendance_summaries = [];
    if (!empty($page_cadet_ids)) {
        $attendance_summaries = get_platoon_cadets_attendance_summary(
            $pdo,
            (int)$platoon_id,
            (int)$active_term['id'],
            $page_cadet_ids
        );
    }
}

// Helper to build pagination URL
function cadets_page_url(string $q, int $target_page): string {
    $params = [];
    if ($q !== '') $params['q'] = $q;
    if ($target_page > 1) $params['page'] = $target_page;
    $qs = http_build_query($params);
    return BASE_URL . '/leader/cadets.php' . ($qs !== '' ? '?' . $qs : '');
}

$page_title = 'Platoon Cadets';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leader.css">

<?php
$svg = function (string $inner): string {
    return '<svg class="pl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$ico = [
    'users'  => $svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
    'alert'  => $svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'),
    'plus'   => $svg('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>'),
    'risk'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
];
?>

<div class="pl">

    <!-- Hero -->
    <div class="pl-hero">
        <div>
            <div class="pl-eyebrow">Platoon leader</div>
            <h1>Platoon cadets</h1>
            <p class="pl-sub">
                <strong>Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?></strong>
                <?php if ($active_term): ?>
                    &nbsp;|&nbsp; Term: <span class="pl-term"><?= e($active_term['name']) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="pl-hero-actions">
            <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary"><?= $ico['plus'] ?> Add cadet</a>
        </div>
    </div>

<?php if (!$active_term): ?>

    <div class="pl-notice pl-notice--warn" role="alert">
        <?= $ico['alert'] ?>
        <div class="pl-notice-body">
            <span class="pl-notice-title">No active academic term</span>
            <p>Rosters and enrollments need an active term. Ask your administrator to activate one.</p>
        </div>
    </div>

<?php elseif (!$platoon_id || !$company_id): ?>

    <div class="pl-notice pl-notice--warn" role="alert">
        <?= $ico['alert'] ?>
        <div class="pl-notice-body">
            <span class="pl-notice-title">Your account has no platoon yet</span>
            <p>You are not assigned to a company and platoon. Please contact your system administrator.</p>
        </div>
    </div>

<?php else: ?>

    <!-- Search -->
    <div class="pl-toolbar-simple">
        <form method="GET" action="<?= BASE_URL ?>/leader/cadets.php" role="search">
            <div class="pl-search">
                <?= $ico['search'] ?>
                <input type="search" id="q" name="q" class="form-control" value="<?= e($q) ?>"
                       placeholder="Search by name, student number, or cadet code"
                       aria-label="Search cadets" autocomplete="off">
                <?php if ($q !== ''): ?>
                    <a href="<?= BASE_URL ?>/leader/cadets.php" class="pl-search-clear" aria-label="Clear search">&times;</a>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>

    <!-- Results bar -->
    <div class="pl-bar">
        <div>
            <?php if ($total > 0): ?>
                Showing <strong><?= (int)$from ?>&ndash;<?= (int)$to ?></strong> of <strong><?= (int)$total ?></strong> cadet(s)
            <?php else: ?>
                Showing <strong>0</strong> cadets
            <?php endif; ?>
            <?php if ($q !== ''): ?>
                for <em style="color: var(--green-900);">&ldquo;<?= e($q) ?>&rdquo;</em>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($cadets)): ?>

        <div class="pl-empty">
            <?= $ico['users'] ?>
            <?php if ($q !== ''): ?>
                <h3>No cadets match your search</h3>
                <p>Nobody in your platoon matched &ldquo;<?= e($q) ?>&rdquo;. Check the spelling or try a cadet code.</p>
                <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm">Clear search</a>
            <?php else: ?>
                <h3>No cadets enrolled yet</h3>
                <p>Cadets you enroll will appear here for the active term.</p>
                <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary btn-sm">Enroll your first cadet</a>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <details class="pl-help">
            <summary>How attendance % is calculated</summary>
            <div>
                <code>(Present + Late) &divide; (Approved Sessions Held &minus; Excused) &times; 100</code><br>
                Only fully approved sessions count and cancelled sessions are left out.
                Cadets below <strong><?= (int)$at_risk_threshold ?>%</strong> are flagged <strong>At risk</strong>.
            </div>
        </details>

        <div class="table-responsive pl-table-wrap">
            <table class="data-table pl-table">
                <thead>
                    <tr>
                        <th>Cadet code</th>
                        <th>Full name</th>
                        <th>Program</th>
                        <th>Gender</th>
                        <th>Student number</th>
                        <th>Status</th>
                        <th>Attendance</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cadets as $c): ?>
                        <?php
                            $mi = '';
                            if (!empty($c['middle_name'])) {
                                $mi = ' ' . mb_strtoupper(mb_substr(trim($c['middle_name']), 0, 1)) . '.';
                            }
                            $full_name = $c['last_name'] . ', ' . $c['first_name'] . $mi;

                            $badge_class = 'badge-active';
                            if ($c['status'] === 'dropped')          $badge_class = 'badge-dropped';
                            elseif ($c['status'] === 'transferred')  $badge_class = 'badge-transferred';
                            elseif ($c['status'] === 'graduated')    $badge_class = 'badge-graduated';

                            $cadet_summary = $attendance_summaries[(int)$c['id']] ?? null;
                            $cadet_pct     = $cadet_summary['percentage'] ?? null;
                            $is_at_risk    = ($cadet_pct !== null && $cadet_pct < $at_risk_threshold);
                        ?>
                        <tr>
                            <td data-label="Cadet code"><span class="pl-code"><?= e($c['cadet_code']) ?></span></td>
                            <td data-label="Name"><span class="pl-name"><?= e($full_name) ?></span></td>
                            <td data-label="Program"><?= !empty($c['program_code']) ? e($c['program_code']) : '<span class="pl-dash">&mdash;</span>' ?></td>
                            <td data-label="Gender"><?= e($c['gender']) ?></td>
                            <td data-label="Student no."><?= e($c['student_number']) ?></td>
                            <td data-label="Status">
                                <span class="badge <?= $badge_class ?>"><?= e($status_labels[$c['status']] ?? ucfirst($c['status'])) ?></span>
                            </td>
                            <td data-label="Attendance">
                                <?php if ($cadet_pct === null): ?>
                                    <span class="pl-dash" title="No approved sessions held yet">&mdash;</span>
                                <?php else: ?>
                                    <div class="pl-pct <?= $is_at_risk ? 'is-risk' : '' ?>">
                                        <strong><?= number_format($cadet_pct, 1) ?>%</strong>
                                        <span class="pl-pct-bar" aria-hidden="true"><span style="--pl-pct: <?= (int)min(100, max(0, round($cadet_pct))) ?>%;"></span></span>
                                        <?php if ($is_at_risk): ?>
                                            <span class="badge badge-at-risk" title="Below the <?= (int)$at_risk_threshold ?>% threshold"><?= $ico['risk'] ?> At risk</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="pl-cell-actions" style="text-align: right;">
                                <div class="table-actions" style="justify-content: flex-end;">
                                    <a href="<?= BASE_URL ?>/leader/cadet_edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                                    <form method="POST" action="<?= BASE_URL ?>/leader/cadets.php" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_cadet">
                                        <input type="hidden" name="cadet_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="return_page" value="<?= (int)$page ?>">
                                        <button type="button" class="btn btn-sm btn-danger btn-remove-cadet"
                                                data-name="<?= e($full_name) ?>"
                                                data-code="<?= e($c['cadet_code']) ?>">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pl-pager" aria-label="Pagination">
                <div>Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$pages ?></strong></div>
                <div class="pl-pager-btns">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(cadets_page_url($q, $page - 1)) ?>" class="btn btn-sm btn-secondary">&laquo; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e(cadets_page_url($q, $page + 1)) ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

</div>

<script src="<?= BASE_URL ?>/assets/js/leader.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
