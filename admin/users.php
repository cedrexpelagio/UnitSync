<?php
// Admin User Management (card view)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_ui.php';

require_role('admin');

$admin = current_user();
$search = trim($_GET['search'] ?? '');
$self_url = 'admin/users.php' . ($search !== '' ? '?search=' . urlencode($search) : '');

// Handle POST actions: Deactivate, Reactivate, Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect($self_url);
    }

    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    // Fetch user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        set_flash('error', 'User not found.');
        redirect($self_url);
    }

    // Protection: Admin cannot deactivate or alter their own account
    if ((int)$targetUser['id'] === (int)$admin['id'] && ($action === 'deactivate')) {
        set_flash('error', 'Action prohibited: You cannot deactivate your own administrator account.');
        redirect($self_url);
    }

    if ($action === 'deactivate') {
        $reason = trim($_POST['deactivation_reason'] ?? '');
        if ($reason === '') {
            set_flash('error', 'A reason is required to deactivate an account.');
            redirect($self_url);
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

        redirect($self_url);
    } elseif ($action === 'reactivate') {
        // Enforce S1 account limits upon reactivating
        if ($targetUser['role'] === 'battalion_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'battalion_s1' AND status = 'approved'");
            if ((int)$stmt->fetchColumn() >= 2) {
                set_flash('error', 'Reactivation failed: Maximum of 2 active Battalion S1 accounts reached.');
                redirect($self_url);
            }
        } elseif ($targetUser['role'] === 'brigade_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'brigade_s1' AND status = 'approved'");
            if ((int)$stmt->fetchColumn() >= 1) {
                set_flash('error', 'Reactivation failed: Maximum of 1 active Brigade S1 account reached.');
                redirect($self_url);
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

        redirect($self_url);
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

        redirect($self_url);
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

// Counts per status for the filter chips
$by_status = ['approved' => 0, 'pending' => 0, 'deactivated' => 0, 'rejected' => 0];
foreach ($usersList as $u) {
    if (isset($by_status[$u['status']])) $by_status[$u['status']]++;
}
$status_text = ['approved' => 'Active', 'pending' => 'Pending', 'deactivated' => 'Deactivated', 'rejected' => 'Rejected'];

$page_title = 'User Management';
require_once __DIR__ . '/../includes/header.php';
ad_css();
?>
<div class="ad">
    <?php
    ad_head('Users', 'Everyone with an account, in one place. Each card has what you need to manage that person.', '', 'users');
    ad_guide('users', 'Managing accounts', [
        ['Find someone fast', 'Type in the search box to narrow the cards instantly. Press / to jump to it from anywhere on the page.'],
        ['Use the filters', 'Switch between active, pending and deactivated accounts with the chips above the cards.'],
        ['Act from the card', 'Reset a password if someone is locked out. Deactivate when an officer graduates or is replaced; you will be asked for a reason.'],
    ]);
    ?>

    <div data-filter>
        <form class="ad-toolbar ad-rise" style="--i:2" action="<?= BASE_URL ?>/admin/users.php" method="GET" role="search">
            <div class="ad-search">
                <?= ad_icon('search', 16) ?>
                <input type="search" name="search" class="ad-input" value="<?= e($search) ?>" data-live-filter data-focus-key
                       placeholder="Search name, username, student no., email" aria-label="Search users">
                <kbd>/</kbd>
            </div>
            <noscript><button type="submit" class="btn btn-primary btn-sm">Search</button></noscript>
            <?php if ($search !== ''): ?>
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm"><?= ad_icon('x', 14) ?> Clear search "<?= e($search) ?>"</a>
            <?php endif; ?>
            <span class="ad-count"><span data-filter-count><?= count($usersList) ?></span> of <?= count($usersList) ?> shown</span>
        </form>

        <?php if ($usersList): ?>
            <div class="ad-chipbar ad-rise" style="--i:3; margin-bottom: 18px;" role="group" aria-label="Filter by status">
                <button type="button" class="ad-chip" data-chip="all" aria-pressed="true">All <b><?= count($usersList) ?></b></button>
                <?php foreach ($status_text as $key => $text): if ($by_status[$key] === 0) continue; ?>
                    <button type="button" class="ad-chip" data-chip="<?= e($key) ?>" aria-pressed="false"><?= e($text) ?> <b><?= $by_status[$key] ?></b></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($usersList)): ?>
            <div class="ad-empty ad-rise" style="--i:3">
                <?= ad_icon('users', 36) ?>
                <strong>No accounts match "<?= e($search) ?>"</strong>
                <p>Try a shorter search, or part of the username or student number.</p>
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">Show everyone</a>
            </div>
        <?php else: ?>
            <div class="ad-cards">
                <?php foreach ($usersList as $i => $u):
                    $is_self = (int)$u['id'] === (int)$admin['id'];
                    $status = $u['status'];
                    $assignment = ad_assignment($u);
                    $name = trim($u['first_name'] . ' ' . $u['last_name']);
                    $avatar = $status === 'approved' ? '' : ($status === 'pending' ? 'is-warn' : ($status === 'rejected' ? 'is-bad' : 'is-off'));
                ?>
                    <article class="ad-card ad-person <?= $status === 'deactivated' ? 'is-off' : '' ?> ad-rise" style="--i:<?= min($i, 8) + 3 ?>"
                             data-item data-tags="<?= e($status) ?>"
                             data-search="<?= e(strtolower($name . ' ' . $u['username'] . ' ' . $u['student_number'] . ' ' . $u['email'] . ' ' . format_role_name($u['role']))) ?>">
                        <div class="ad-person-top">
                            <span class="ad-avatar <?= $avatar ?>"><?= e(ad_initials($u['first_name'], $u['last_name'])) ?></span>
                            <div class="ad-person-id">
                                <div class="ad-person-name"><?= e($u['last_name'] . ', ' . $u['first_name']) ?></div>
                                <div class="ad-person-user"><?= e($u['username']) ?></div>
                            </div>
                            <span class="badge badge-<?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
                        </div>

                        <div class="ad-person-tags">
                            <span class="ad-tag"><?= e(format_role_name($u['role'])) ?></span>
                            <?php if ($is_self): ?><span class="ad-tag is-you">You</span><?php endif; ?>
                            <?php if (!empty($u['must_change_password'])): ?><span class="ad-tag is-warn" data-tip="This person must set a new password the next time they sign in."><?= ad_icon('lock', 12) ?> Password change pending</span><?php endif; ?>
                        </div>

                        <dl class="ad-kv">
                            <div><dt>Assignment</dt><dd><?= e($assignment) ?></dd></div>
                            <div><dt>Student no.</dt><dd><?= e($u['student_number'] ?: '—') ?></dd></div>
                            <div class="is-wide"><dt>Email</dt><dd><?php if ($u['email']): ?><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a><?php else: ?>—<?php endif; ?></dd></div>
                            <?php if (!empty($u['created_at'])): ?>
                                <div class="is-wide"><dt>Registered</dt><dd><?= e(date('M j, Y', strtotime($u['created_at']))) ?></dd></div>
                            <?php endif; ?>
                        </dl>

                        <?php if ($status === 'deactivated' && !empty($u['deactivation_reason'])): ?>
                            <div class="ad-note ad-note-bad"><?= ad_icon('info', 16) ?><div><strong>Deactivated:</strong> <?= e($u['deactivation_reason']) ?></div></div>
                        <?php elseif ($status === 'rejected' && !empty($u['rejection_reason'])): ?>
                            <div class="ad-note ad-note-bad"><?= ad_icon('info', 16) ?><div><strong>Rejected:</strong> <?= e($u['rejection_reason']) ?></div></div>
                        <?php endif; ?>

                        <div class="ad-person-foot">
                            <?php if ($is_self): ?>
                                <span class="ad-muted" style="font-size:12.5px;">This is your account. Use the account menu to change your own password.</span>
                            <?php else: ?>
                                <?php if ($status === 'pending'): ?>
                                    <a href="<?= BASE_URL ?>/admin/requests.php" class="btn btn-primary btn-sm"><?= ad_icon('inbox', 14) ?> Review request</a>
                                <?php endif; ?>

                                <form action="<?= BASE_URL ?>/admin/users.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" data-ad-confirm data-title="Reset password"
                                            data-message="<?= e('Reset the password for <strong>' . e($u['username']) . '</strong>? A temporary password is created and shown to you once. They must change it at next sign-in.') ?>"
                                            data-confirm-text="Reset password"><?= ad_icon('key', 14) ?> Reset password</button>
                                </form>

                                <?php if ($status === 'approved'): ?>
                                    <form class="ad-spacer" action="<?= BASE_URL ?>/admin/users.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <input type="hidden" name="deactivation_reason" data-reason value="">
                                        <button type="button" class="btn btn-outline-danger btn-sm ad-spacer"
                                                data-ad-prompt data-target="[data-reason]" data-danger="1" data-title="Deactivate account"
                                                data-message="<?= e('Why is <strong>' . e($u['username']) . '</strong> being deactivated? They will see this reason if they try to sign in.') ?>"
                                                data-placeholder="e.g. Graduated / Replaced as platoon leader"
                                                data-confirm-text="Deactivate account"><?= ad_icon('ban', 14) ?> Deactivate</button>
                                    </form>
                                <?php elseif ($status === 'deactivated'): ?>
                                    <form action="<?= BASE_URL ?>/admin/users.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm ad-spacer" data-ad-confirm data-title="Reactivate account"
                                                data-message="<?= e('Give <strong>' . e($u['username']) . '</strong> access to UnitSync again?') ?>"
                                                data-confirm-text="Reactivate account"><?= ad_icon('rotate', 14) ?> Reactivate</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="ad-empty" data-filter-empty hidden>
                <?= ad_icon('search', 36) ?>
                <strong>No cards match</strong>
                <p>Clear the search box or pick "All" to see everyone again.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php ad_js(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>