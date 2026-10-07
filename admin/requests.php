<?php
// Admin Registration Requests (Stage 2 - Step A)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$admin = current_user();
$tab = $_GET['tab'] ?? 'pending';

// Handle POST actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('admin/requests.php?tab=' . urlencode($tab));
    }

    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    // Fetch user details
    $stmt = $pdo->prepare("
        SELECT u.*, ua.program_id, ua.company_id, ua.platoon_id 
        FROM users u 
        LEFT JOIN user_assignments ua ON u.id = ua.user_id 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        set_flash('error', 'User not found.');
        redirect('admin/requests.php?tab=' . urlencode($tab));
    }

    if ($action === 'approve') {
        if ($targetUser['status'] !== 'pending') {
            set_flash('error', 'Only pending requests can be approved.');
            redirect('admin/requests.php?tab=' . urlencode($tab));
        }

        // Limit rule: Maximum 2 active Battalion S1 and 1 active Brigade S1
        if ($targetUser['role'] === 'battalion_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'battalion_s1' AND status = 'approved'");
            $activeBn = (int)$stmt->fetchColumn();
            if ($activeBn >= 2) {
                set_flash('error', 'Approval failed: The maximum limit of 2 active Battalion S1 accounts has been reached. Deactivate an existing account first.');
                redirect('admin/requests.php?tab=' . urlencode($tab));
            }
        } elseif ($targetUser['role'] === 'brigade_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'brigade_s1' AND status = 'approved'");
            $activeBr = (int)$stmt->fetchColumn();
            if ($activeBr >= 1) {
                set_flash('error', 'Approval failed: The maximum limit of 1 active Brigade S1 account has been reached. Deactivate the existing account first.');
                redirect('admin/requests.php?tab=' . urlencode($tab));
            }
        }

        try {
            $pdo->beginTransaction();

            // Update user status
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'approved', reviewed_by = :admin_id, reviewed_at = NOW(), rejection_reason = NULL 
                WHERE id = :id
            ");
            $stmt->execute([
                'admin_id' => $admin['id'],
                'id' => $user_id
            ]);

            // Insert audit log
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (:actor_id, 'approve_registration', 'user', :entity_id, :details, NOW())
            ");
            $stmt->execute([
                'actor_id' => $admin['id'],
                'entity_id' => $user_id,
                'details' => "Approved registration for {$targetUser['username']} ({$targetUser['role']})"
            ]);

            $pdo->commit();

            // Send local simulated email
            $subject = "UnitSync Account Approved";
            $body = "Hello {$targetUser['first_name']},\n\nYour UnitSync registration has been APPROVED by the administrator.\nYour official username is: {$targetUser['username']}\n\nYou can now log in at: " . BASE_URL . "/auth/login.php";
            log_mail_sim($targetUser['email'], $subject, $body);

            set_flash('success', "Account approved! Generated Username: {$targetUser['username']}. Approval email logged.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Error approving account: ' . $e->getMessage());
        }

        redirect('admin/requests.php?tab=pending');
    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            set_flash('error', 'A rejection reason is required.');
            redirect('admin/requests.php?tab=' . urlencode($tab));
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'rejected', rejection_reason = :reason, reviewed_by = :admin_id, reviewed_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([
                'reason' => $reason,
                'admin_id' => $admin['id'],
                'id' => $user_id
            ]);

            // Audit log
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (:actor_id, 'reject_registration', 'user', :entity_id, :details, NOW())
            ");
            $stmt->execute([
                'actor_id' => $admin['id'],
                'entity_id' => $user_id,
                'details' => "Rejected registration for {$targetUser['username']}. Reason: {$reason}"
            ]);

            $pdo->commit();

            // Log email notification
            $subject = "UnitSync Account Registration Update";
            $body = "Hello {$targetUser['first_name']},\n\nYour registration request was not approved for the following reason:\n{$reason}\n\nYou can review and resubmit your registration.";
            log_mail_sim($targetUser['email'], $subject, $body);

            set_flash('info', "Registration for {$targetUser['username']} has been rejected.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Error rejecting registration: ' . $e->getMessage());
        }

        redirect('admin/requests.php?tab=pending');
    }
}

// Fetch requests depending on active tab
if ($tab === 'rejected') {
    $stmt = $pdo->prepare("
        SELECT u.*, ua.program_id, ua.company_id, ua.platoon_id,
               p.code AS prog_code, c.name AS company_name, plt.name AS platoon_name,
               reviewer.username AS reviewer_username
        FROM users u
        LEFT JOIN user_assignments ua ON u.id = ua.user_id
        LEFT JOIN programs p ON ua.program_id = p.id
        LEFT JOIN companies c ON ua.company_id = c.id
        LEFT JOIN platoons plt ON ua.platoon_id = plt.id
        LEFT JOIN users reviewer ON u.reviewed_by = reviewer.id
        WHERE u.status = 'rejected'
        ORDER BY u.reviewed_at DESC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT u.*, ua.program_id, ua.company_id, ua.platoon_id,
               p.code AS prog_code, c.name AS company_name, plt.name AS platoon_name
        FROM users u
        LEFT JOIN user_assignments ua ON u.id = ua.user_id
        LEFT JOIN programs p ON ua.program_id = p.id
        LEFT JOIN companies c ON ua.company_id = c.id
        LEFT JOIN platoons plt ON ua.platoon_id = plt.id
        WHERE u.status = 'pending'
        ORDER BY u.created_at ASC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll();
}

// Function to check potential conflicts
function get_conflict_warning(PDO $pdo, array $req): ?string {
    if ($req['role'] === 'class_president' && !empty($req['program_id'])) {
        $stmt = $pdo->prepare("
            SELECT u.username, u.first_name, u.last_name 
            FROM user_assignments ua 
            JOIN users u ON ua.user_id = u.id 
            WHERE ua.program_id = ? AND u.role = 'class_president' AND u.status = 'approved' AND u.id != ?
            LIMIT 1
        ");
        $stmt->execute([$req['program_id'], $req['id']]);
        $existing = $stmt->fetch();
        if ($existing) {
            return "Warning: Program {$req['prog_code']} already has an active Class President ({$existing['username']} - {$existing['first_name']} {$existing['last_name']}).";
        }
    } elseif ($req['role'] === 'platoon_leader' && !empty($req['platoon_id'])) {
        $stmt = $pdo->prepare("
            SELECT u.username, u.first_name, u.last_name 
            FROM user_assignments ua 
            JOIN users u ON ua.user_id = u.id 
            WHERE ua.platoon_id = ? AND u.role = 'platoon_leader' AND u.status = 'approved' AND u.id != ?
            LIMIT 1
        ");
        $stmt->execute([$req['platoon_id'], $req['id']]);
        $existing = $stmt->fetch();
        if ($existing) {
            return "Warning: Platoon already has an active Leader ({$existing['username']} - {$existing['first_name']} {$existing['last_name']}).";
        }
    }
    return null;
}

$page_title = 'Registration Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Registration Requests</h1>
    <p>Review and verify officer registrations before permitting access to the system</p>
</div>

<div class="tabs-nav">
    <a href="<?= BASE_URL ?>/admin/requests.php?tab=pending" class="tab-link <?= $tab === 'pending' ? 'active' : '' ?>">Pending Requests</a>
    <a href="<?= BASE_URL ?>/admin/requests.php?tab=rejected" class="tab-link <?= $tab === 'rejected' ? 'active' : '' ?>">Rejected History</a>
</div>

<?php if ($tab === 'rejected'): ?>
    <?php if (empty($requests)): ?>
        <p style="padding: 24px; color: var(--gray-700);">No rejected registrations found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Student No.</th>
                        <th>Role</th>
                        <th>Rejection Reason</th>
                        <th>Reviewed By</th>
                        <th>Date Rejected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                        <tr>
                            <td><strong><?= e($req['username']) ?></strong></td>
                            <td><?= e($req['last_name'] . ', ' . $req['first_name'] . ' ' . $req['middle_name']) ?></td>
                            <td><?= e($req['student_number']) ?></td>
                            <td><span class="badge badge-rejected"><?= e(format_role_name($req['role'])) ?></span></td>
                            <td style="color: var(--error); font-weight: 500;"><?= e($req['rejection_reason']) ?></td>
                            <td><?= e($req['reviewer_username'] ?? 'Admin') ?></td>
                            <td><?= e($req['reviewed_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <?php if (empty($requests)): ?>
        <div style="background-color: var(--gray-50); border: 1px dashed var(--gray-300); border-radius: var(--radius-default); padding: 40px; text-align: center; color: var(--gray-700);">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gold-500); margin-bottom: 8px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <p><strong>No pending registration requests</strong></p>
            <p style="font-size: 13px;">New user registrations will appear here for verification.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Student No.</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Assignment</th>
                        <th>Submitted At</th>
                        <th style="min-width: 170px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): 
                        $assignment = '—';
                        if ($req['role'] === 'class_president') {
                            $assignment = $req['prog_code'] ? 'Program: ' . $req['prog_code'] : 'Unassigned';
                        } elseif ($req['role'] === 'platoon_leader') {
                            $assignment = ($req['company_name'] ? $req['company_name'] : '') . ' - ' . ($req['platoon_name'] ? $req['platoon_name'] : '');
                        }
                        $conflict = get_conflict_warning($pdo, $req);
                    ?>
                        <tr>
                            <td><strong><?= e($req['username']) ?></strong></td>
                            <td><?= e($req['last_name'] . ', ' . $req['first_name'] . ' ' . $req['middle_name']) ?></td>
                            <td><?= e($req['student_number']) ?></td>
                            <td><?= e($req['email']) ?></td>
                            <td><span class="badge badge-pending"><?= e(format_role_name($req['role'])) ?></span></td>
                            <td>
                                <?= e($assignment) ?>
                                <?php if ($conflict): ?>
                                    <br><small style="color: var(--warning); display: block; margin-top: 2px;">⚠️ <?= e($conflict) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= e(date('M d, Y h:i A', strtotime($req['created_at']))) ?></td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <!-- Approve Form -->
                                    <form class="approve-form" action="<?= BASE_URL ?>/admin/requests.php" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="user_id" value="<?= (int)$req['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm approve-btn" data-username="<?= e($req['username']) ?>">Approve</button>
                                    </form>

                                    <!-- Reject Form -->
                                    <form class="reject-form" action="<?= BASE_URL ?>/admin/requests.php" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="user_id" value="<?= (int)$req['id'] ?>">
                                        <input type="hidden" name="rejection_reason" class="reject-reason-input" value="">
                                        <button type="button" class="btn btn-danger btn-sm reject-btn" data-username="<?= e($req['username']) ?>">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Approve confirmation modal
    document.querySelectorAll('.approve-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const form = btn.closest('form');
            const username = btn.dataset.username;
            const ok = await showConfirm({
                title: 'Approve Registration',
                message: `Are you sure you want to approve registration for officer <strong>${username}</strong>?`,
                confirmText: 'Approve Account',
                cancelText: 'Cancel',
                isDanger: false
            });
            if (ok) form.submit();
        });
    });

    // Reject reason prompt modal
    document.querySelectorAll('.reject-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const form = btn.closest('form');
            const reasonInput = form.querySelector('.reject-reason-input');
            const username = btn.dataset.username;

            const reason = await showPromptModal({
                title: 'Reject Registration',
                message: `Please provide a rejection reason for <strong>${username}</strong>. This explanation will be shown to the user upon attempting login:`,
                placeholder: 'e.g. Identity not found on official unit roster / incorrect student ID',
                confirmText: 'Confirm Rejection',
                isDanger: true
            });

            if (reason) {
                reasonInput.value = reason;
                form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
