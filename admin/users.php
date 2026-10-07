<?php
// Admin User Management (Stage 2 - Step A)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$admin = current_user();
$search = trim($_GET['search'] ?? '');

// Handle POST actions: Deactivate, Reactivate, Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
    }

    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    // Fetch user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        set_flash('error', 'User not found.');
        redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
    }

    // Protection: Admin cannot deactivate or alter their own account
    if ($targetUser['id'] === $admin['id'] && ($action === 'deactivate')) {
        set_flash('error', 'Action prohibited: You cannot deactivate your own administrator account.');
        redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
    }

    if ($action === 'deactivate') {
        $reason = trim($_POST['deactivation_reason'] ?? '');
        if ($reason === '') {
            set_flash('error', 'A reason is required to deactivate an account.');
            redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'deactivated', deactivation_reason = :reason 
                WHERE id = :id
            ");
            $stmt->execute(['reason' => $reason, 'id' => $user_id]);

            // Audit log
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (:actor_id, 'deactivate_user', 'user', :entity_id, :details, NOW())
            ");
            $stmt->execute([
                'actor_id' => $admin['id'],
                'entity_id' => $user_id,
                'details' => "Deactivated user {$targetUser['username']}. Reason: {$reason}"
            ]);

            $pdo->commit();
            set_flash('warning', "User {$targetUser['username']} has been deactivated.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Error deactivating user: ' . $e->getMessage());
        }

        redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
    } elseif ($action === 'reactivate') {
        // Enforce S1 account limits upon reactivating
        if ($targetUser['role'] === 'battalion_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'battalion_s1' AND status = 'approved'");
            if ((int)$stmt->fetchColumn() >= 2) {
                set_flash('error', 'Reactivation failed: Maximum of 2 active Battalion S1 accounts reached.');
                redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
            }
        } elseif ($targetUser['role'] === 'brigade_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'brigade_s1' AND status = 'approved'");
            if ((int)$stmt->fetchColumn() >= 1) {
                set_flash('error', 'Reactivation failed: Maximum of 1 active Brigade S1 account reached.');
                redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'approved', deactivation_reason = NULL 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $user_id]);

            // Audit log
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (:actor_id, 'reactivate_user', 'user', :entity_id, :details, NOW())
            ");
            $stmt->execute([
                'actor_id' => $admin['id'],
                'entity_id' => $user_id,
                'details' => "Reactivated user {$targetUser['username']}"
            ]);

            $pdo->commit();
            set_flash('success', "User {$targetUser['username']} has been reactivated.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Error reactivating user: ' . $e->getMessage());
        }

        redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
    } elseif ($action === 'reset_password') {
        // Generate secure temporary password
        $tempPassword = 'Reset' . bin2hex(random_bytes(3)) . '!9';
        $newHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :hash, must_change_password = 1 
                WHERE id = :id
            ");
            $stmt->execute(['hash' => $newHash, 'id' => $user_id]);

            // Audit log
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (:actor_id, 'reset_password', 'user', :entity_id, :details, NOW())
            ");
            $stmt->execute([
                'actor_id' => $admin['id'],
                'entity_id' => $user_id,
                'details' => "Admin reset password for {$targetUser['username']}"
            ]);

            $pdo->commit();

            // Email simulation log
            $subject = "UnitSync Password Reset";
            $body = "Hello {$targetUser['first_name']},\n\nYour UnitSync password was reset by an Administrator.\nTemporary password: {$tempPassword}\n\nYou will be required to change this password immediately upon logging in.";
            log_mail_sim($targetUser['email'], $subject, $body);

            set_flash('success', "Password reset for {$targetUser['username']}! Temporary Password: <strong>{$tempPassword}</strong> (Shown once. The user must change it at next login).");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Error resetting password: ' . $e->getMessage());
        }

        redirect('admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : ''));
    }
}

// Search & list users
$sql = "
    SELECT u.*, p.code AS prog_code, c.name AS company_name, plt.name AS platoon_name 
    FROM users u 
    LEFT JOIN user_assignments ua ON u.id = ua.user_id 
    LEFT JOIN programs p ON ua.program_id = p.id 
    LEFT JOIN companies c ON ua.company_id = c.id 
    LEFT JOIN platoons plt ON ua.platoon_id = plt.id 
";
$params = [];

if ($search !== '') {
    $sql .= " WHERE (u.username LIKE :s OR u.first_name LIKE :s OR u.last_name LIKE :s OR u.student_number LIKE :s OR u.email LIKE :s OR u.role LIKE :s)";
    $params['s'] = '%' . $search . '%';
}

$sql .= " ORDER BY u.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usersList = $stmt->fetchAll();

$page_title = 'User Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>User Management</h1>
    <p>Manage system users, assignments, account status, and credentials</p>
</div>

<div style="margin-bottom: 24px;">
    <form action="<?= BASE_URL ?>/admin/users.php" method="GET" style="display: flex; gap: 8px; max-width: 450px;">
        <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Search name, username, student #, email...">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($usersList)): ?>
    <div style="background-color: var(--gray-50); border: 1px dashed var(--gray-300); border-radius: var(--radius-default); padding: 32px; text-align: center; color: var(--gray-700);">
        <p>No user accounts found matching your query.</p>
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
                    <th>Status</th>
                    <th style="min-width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usersList as $u): 
                    $assignment = '—';
                    if ($u['role'] === 'class_president') {
                        $assignment = $u['prog_code'] ? 'Program: ' . $u['prog_code'] : 'Unassigned';
                    } elseif ($u['role'] === 'platoon_leader') {
                        $assignment = ($u['company_name'] ? $u['company_name'] : '') . ' - ' . ($u['platoon_name'] ? $u['platoon_name'] : '');
                    }
                    $badgeClass = 'badge-' . $u['status'];
                ?>
                    <tr>
                        <td><strong><?= e($u['username']) ?></strong></td>
                        <td><?= e($u['last_name'] . ', ' . $u['first_name']) ?></td>
                        <td><?= e($u['student_number']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e(format_role_name($u['role'])) ?></td>
                        <td><?= e($assignment) ?></td>
                        <td>
                            <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($u['status'])) ?></span>
                            <?php if ($u['status'] === 'deactivated' && !empty($u['deactivation_reason'])): ?>
                                <br><small style="color: var(--error); display: block; margin-top: 2px;"><?= e($u['deactivation_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['id'] !== $admin['id']): ?>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <!-- Password Reset Form -->
                                    <form class="reset-pwd-form" action="<?= BASE_URL ?>/admin/users.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm reset-pwd-btn" data-username="<?= e($u['username']) ?>">Reset Pwd</button>
                                    </form>

                                    <?php if ($u['status'] === 'approved'): ?>
                                        <!-- Deactivate Form -->
                                        <form class="deactivate-form" action="<?= BASE_URL ?>/admin/users.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="deactivation_reason" class="deactivate-reason-input" value="">
                                            <button type="button" class="btn btn-warning btn-sm deactivate-btn" data-username="<?= e($u['username']) ?>">Deactivate</button>
                                        </form>
                                    <?php elseif ($u['status'] === 'deactivated'): ?>
                                        <!-- Reactivate Form -->
                                        <form class="reactivate-form" action="<?= BASE_URL ?>/admin/users.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reactivate">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm reactivate-btn" data-username="<?= e($u['username']) ?>">Reactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <em style="font-size: 12px; color: var(--gray-700);">(Current Admin)</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Reset password confirmation
    document.querySelectorAll('.reset-pwd-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const form = btn.closest('form');
            const username = btn.dataset.username;
            const ok = await showConfirm({
                title: 'Reset Password',
                message: `Are you sure you want to reset password for <strong>${username}</strong>? A temporary password will be generated and required to change on next login.`,
                confirmText: 'Reset Password',
                cancelText: 'Cancel',
                isDanger: false
            });
            if (ok) form.submit();
        });
    });

    // Deactivate prompt modal with reason
    document.querySelectorAll('.deactivate-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const form = btn.closest('form');
            const reasonInput = form.querySelector('.deactivate-reason-input');
            const username = btn.dataset.username;

            const reason = await showPromptModal({
                title: 'Deactivate Account',
                message: `Provide a deactivation reason for <strong>${username}</strong> (e.g. Graduated, replaced, or removed):`,
                placeholder: 'e.g. Graduated / Replaced as platoon leader',
                confirmText: 'Deactivate Account',
                isDanger: true
            });

            if (reason) {
                reasonInput.value = reason;
                form.submit();
            }
        });
    });

    // Reactivate confirmation
    document.querySelectorAll('.reactivate-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const form = btn.closest('form');
            const username = btn.dataset.username;
            const ok = await showConfirm({
                title: 'Reactivate Account',
                message: `Are you sure you want to reactivate access for <strong>${username}</strong>?`,
                confirmText: 'Reactivate Account',
                cancelText: 'Cancel',
                isDanger: false
            });
            if (ok) form.submit();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
