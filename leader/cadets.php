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

<div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Platoon Cadets</h1>
        <p style="margin: 0; color: var(--gray-700);">
            <strong>Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?></strong>
            <?php if ($active_term): ?>
                &nbsp;|&nbsp; Term: <span style="color: var(--green-700); font-weight: 600;"><?= e($active_term['name']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary">
            + Add Cadet
        </a>
    </div>
</div>

<?php if (!$active_term): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">No Active Term</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            There is currently no active academic term set up in the system. Cadet rosters and enrollments require an active term.
        </p>
    </div>
<?php elseif (!$platoon_id || !$company_id): ?>
    <div style="background-color: var(--gold-100); border: 1px solid #F6E05E; border-radius: var(--radius-default); padding: 20px; margin-bottom: 24px;">
        <h4 style="color: var(--warning); margin-bottom: 8px;">Unassigned Officer</h4>
        <p style="font-size: 14px; color: var(--gray-700); margin: 0;">
            Your account is not assigned to a company and platoon. Please contact your system administrator.
        </p>
    </div>
<?php else: ?>

    <!-- Search & Filter Bar -->
    <div class="card" style="margin-bottom: 20px; padding: 16px 20px;">
        <form method="GET" action="<?= BASE_URL ?>/leader/cadets.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
            <div class="form-group" style="margin: 0; flex: 1; min-width: 240px;">
                <label for="q" style="font-size: 13px; font-weight: 600; color: var(--gray-700); margin-bottom: 4px; display: block;">Search Cadets</label>
                <input type="text" id="q" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Search by name, student number, or cadet code...">
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary btn-sm" style="padding: 9px 16px;">Search</button>
                <?php if ($q !== ''): ?>
                    <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm" style="padding: 9px 16px;">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 14px; color: var(--gray-700);">
        <div>
            <?php if ($total > 0): ?>
                Showing <strong><?= $from ?>&ndash;<?= $to ?></strong> of <strong><?= $total ?></strong> cadet(s)
            <?php else: ?>
                Showing <strong>0</strong> cadets
            <?php endif; ?>
            <?php if ($q !== ''): ?>
                for query <em style="color: var(--green-900);">"<?= e($q) ?>"</em>
            <?php endif; ?>
        </div>
    </div>

    <!-- Table of Cadets -->
    <?php if (empty($cadets)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray-500); margin-bottom: 12px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <?php if ($q !== ''): ?>
                <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Matching Cadets Found</h3>
                <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">No cadets in your platoon matched your search keyword.</p>
                <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm">Clear Search Filter</a>
            <?php else: ?>
                <h3 style="font-size: 16px; color: var(--green-900); margin-bottom: 6px;">No Cadets Enrolled Yet</h3>
                <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">There are no cadets enrolled in your platoon for this active term.</p>
                <a href="<?= BASE_URL ?>/leader/add_cadet.php" class="btn btn-primary btn-sm">+ Enroll First Cadet</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Cadet Code</th>
                        <th>Full Name</th>
                        <th>Program</th>
                        <th>Gender</th>
                        <th>Student Number</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cadets as $c): ?>
                        <?php
                            // Full name format: Last, First, M.I.
                            $mi = '';
                            if (!empty($c['middle_name'])) {
                                $mi = ' ' . mb_strtoupper(mb_substr(trim($c['middle_name']), 0, 1)) . '.';
                            }
                            $full_name = $c['last_name'] . ', ' . $c['first_name'] . $mi;

                            // Badge class for cadet status
                            $badge_class = 'badge-active';
                            if ($c['status'] === 'dropped') {
                                $badge_class = 'badge-dropped';
                            } elseif ($c['status'] === 'transferred') {
                                $badge_class = 'badge-transferred';
                            } elseif ($c['status'] === 'graduated') {
                                $badge_class = 'badge-graduated';
                            }
                        ?>
                        <tr>
                            <td>
                                <strong style="font-family: monospace; font-size: 13px; color: var(--green-900);">
                                    <?= e($c['cadet_code']) ?>
                                </strong>
                            </td>
                            <td>
                                <strong><?= e($full_name) ?></strong>
                            </td>
                            <td>
                                <?= e($c['program_code'] ?? '&mdash;') ?>
                            </td>
                            <td>
                                <?= e($c['gender']) ?>
                            </td>
                            <td>
                                <?= e($c['student_number']) ?>
                            </td>
                            <td>
                                <span class="badge <?= $badge_class ?>">
                                    <?= e($status_labels[$c['status']] ?? ucfirst($c['status'])) ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div class="table-actions" style="justify-content: flex-end;">
                                    <a href="<?= BASE_URL ?>/leader/cadet_edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary">
                                        Edit
                                    </a>
                                    <form method="POST" action="<?= BASE_URL ?>/leader/cadets.php" class="remove-cadet-form" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_cadet">
                                        <input type="hidden" name="cadet_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="return_page" value="<?= (int)$page ?>">
                                        <button type="button" 
                                                class="btn btn-sm btn-danger btn-remove-cadet"
                                                data-name="<?= e($full_name) ?>"
                                                data-code="<?= e($c['cadet_code']) ?>">
                                            Remove
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 16px;">
                <div style="font-size: 14px; color: var(--gray-700);">
                    Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong>
                </div>
                <div style="display: flex; gap: 8px;">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(cadets_page_url($q, $page - 1)) ?>" class="btn btn-sm btn-secondary">
                            &laquo; Previous
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e(cadets_page_url($q, $page + 1)) ?>" class="btn btn-sm btn-secondary">
                            Next &raquo;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

<script>
// Step C: Confirmation Modal for Cadet Removal
document.addEventListener('DOMContentLoaded', function () {
    const removeButtons = document.querySelectorAll('.btn-remove-cadet');
    removeButtons.forEach(function (button) {
        button.addEventListener('click', async function (e) {
            e.preventDefault();
            const form = button.closest('form');
            const cadetName = button.getAttribute('data-name') || 'this cadet';
            const cadetCode = button.getAttribute('data-code') || '';

            const confirmed = await showConfirm({
                title: 'Remove Cadet from Platoon',
                message: `<p style="margin-bottom: 12px;">Are you sure you want to remove <strong>${cadetName}</strong> (${cadetCode}) from your platoon?</p>
                          <div style="background-color: #FFF5F5; border: 1px solid #FEB2B2; border-radius: 6px; padding: 10px 12px; color: #C53030; font-size: 13px;">
                              <strong>Important consequence:</strong> The cadet becomes <strong>Unassigned</strong>. Only S1 can reassign this cadet.
                          </div>`,
                confirmText: 'Remove Cadet',
                cancelText: 'Cancel',
                isDanger: true
            });

            if (confirmed && form) {
                form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
