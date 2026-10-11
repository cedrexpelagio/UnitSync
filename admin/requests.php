<?php
// Admin Registration Requests
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_ui.php';

require_role('admin');

$admin = current_user();
$ad_tab = (($_GET['tab'] ?? 'pending') === 'rejected') ? 'rejected' : 'pending';

// Handle POST actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('admin/requests.php?tab=' . urlencode($ad_tab));
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
        redirect('admin/requests.php?tab=' . urlencode($ad_tab));
    }

    if ($action === 'approve') {
        if ($targetUser['status'] !== 'pending') {
            set_flash('error', 'Only pending requests can be approved.');
            redirect('admin/requests.php?tab=' . urlencode($ad_tab));
        }

        // Limit rule: Maximum 2 active Battalion S1 and 1 active Brigade S1
        if ($targetUser['role'] === 'battalion_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'battalion_s1' AND status = 'approved'");
            $activeBn = (int)$stmt->fetchColumn();
            if ($activeBn >= 2) {
                set_flash('error', 'Approval failed: The maximum limit of 2 active Battalion S1 accounts has been reached. Deactivate an existing account first.');
                redirect('admin/requests.php?tab=' . urlencode($ad_tab));
            }
        } elseif ($targetUser['role'] === 'brigade_s1') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'brigade_s1' AND status = 'approved'");
            $activeBr = (int)$stmt->fetchColumn();
            if ($activeBr >= 1) {
                set_flash('error', 'Approval failed: The maximum limit of 1 active Brigade S1 account has been reached. Deactivate the existing account first.');
                redirect('admin/requests.php?tab=' . urlencode($ad_tab));
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
            redirect('admin/requests.php?tab=' . urlencode($ad_tab));
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
if ($ad_tab === 'rejected') {
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

// Counts for the tabs and S1 seat usage
$pending_total  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$rejected_total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'rejected'")->fetchColumn();
$bn_active = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'battalion_s1' AND status = 'approved'")->fetchColumn();
$br_active = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'brigade_s1' AND status = 'approved'")->fetchColumn();

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
            return "Program {$req['prog_code']} already has an active Class President ({$existing['username']} - {$existing['first_name']} {$existing['last_name']}).";
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
            return "This platoon already has an active Leader ({$existing['username']} - {$existing['first_name']} {$existing['last_name']}).";
        }
    }
    return null;
}

$ad_name = static function (array $r): string {
    return trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'] ?? '', $r['last_name']])));
};

$page_title = 'Registration Requests';
require_once __DIR__ . '/../includes/header.php';
ad_css();
?>
<div class="ad">
    <?php
    ad_head('Registration requests', 'Check each officer against the roster, then approve or reject. Nobody can sign in until you do.', '', 'requests');
    ad_guide('requests', 'How to review a request', [
        ['Verify the identity', 'Compare the name and student number with your official unit roster.'],
        ['Read the warnings', 'A yellow note means the role or seat is already taken. Resolve that before approving.'],
        ['Approve or reject', 'Approving emails the officer their username. Rejecting needs a reason, which the officer sees when they try to sign in.'],
    ]);
    ?>

    <nav class="ad-linktabs ad-rise" style="--i:2" aria-label="Request lists">
        <a href="<?= BASE_URL ?>/admin/requests.php?tab=pending" class="<?= $ad_tab === 'pending' ? 'is-on' : '' ?>" <?= $ad_tab === 'pending' ? 'aria-current="page"' : '' ?>>
            Pending <b class="<?= $pending_total > 0 ? 'is-alert' : '' ?>"><?= $pending_total ?></b>
        </a>
        <a href="<?= BASE_URL ?>/admin/requests.php?tab=rejected" class="<?= $ad_tab === 'rejected' ? 'is-on' : '' ?>" <?= $ad_tab === 'rejected' ? 'aria-current="page"' : '' ?>>
            Rejected history <b><?= $rejected_total ?></b>
        </a>
    </nav>

    <?php if ($ad_tab === 'rejected'): ?>

        <?php if (empty($requests)): ?>
            <div class="ad-empty ad-rise" style="--i:3">
                <?= ad_icon('user-x', 36) ?>
                <strong>No rejected registrations</strong>
                <p>Requests you reject will be kept here so you can see the reason and who made the call.</p>
            </div>
        <?php else: ?>
            <div class="ad-cards" data-filter>
                <?php if (count($requests) > 4): ?>
                    <div class="ad-toolbar" style="grid-column: 1 / -1; margin-bottom: 0;">
                        <div class="ad-search">
                            <?= ad_icon('search', 16) ?>
                            <input type="search" class="ad-input" data-live-filter data-focus-key placeholder="Filter by name, username or reason" aria-label="Filter rejected requests">
                            <kbd>/</kbd>
                        </div>
                    </div>
                <?php endif; ?>
                <?php foreach ($requests as $i => $req): $name = $ad_name($req); ?>
                    <article class="ad-card ad-person is-off ad-rise" style="--i:<?= min($i, 8) + 3 ?>" data-item data-search="<?= e(strtolower($name . ' ' . $req['username'] . ' ' . $req['student_number'] . ' ' . $req['rejection_reason'])) ?>">
                        <div class="ad-person-top">
                            <span class="ad-avatar is-bad"><?= e(ad_initials($req['first_name'], $req['last_name'])) ?></span>
                            <div class="ad-person-id">
                                <div class="ad-person-name"><?= e($name) ?></div>
                                <div class="ad-person-user"><?= e($req['username']) ?></div>
                            </div>
                            <span class="badge badge-rejected">Rejected</span>
                        </div>
                        <div class="ad-note ad-note-bad"><?= ad_icon('info', 16) ?><div><strong>Reason:</strong> <?= e($req['rejection_reason'] ?: 'No reason recorded.') ?></div></div>
                        <dl class="ad-kv">
                            <div><dt>Role</dt><dd><?= e(format_role_name($req['role'])) ?></dd></div>
                            <div><dt>Student no.</dt><dd><?= e($req['student_number']) ?></dd></div>
                            <div><dt>Reviewed by</dt><dd><?= e($req['reviewer_username'] ?? 'Admin') ?></dd></div>
                            <div><dt>Rejected</dt><dd><?= $req['reviewed_at'] ? e(date('M j, Y g:i A', strtotime($req['reviewed_at']))) : '—' ?></dd></div>
                        </dl>
                    </article>
                <?php endforeach; ?>
                <p class="ad-muted" data-filter-empty hidden style="grid-column: 1 / -1;">No rejected requests match your filter.</p>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <?php if (empty($requests)): ?>
            <div class="ad-empty ad-rise" style="--i:3">
                <?= ad_icon('check-circle', 36) ?>
                <strong>You are all caught up</strong>
                <p>New registrations will show up here for verification. You can also check who is already in the system.</p>
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">Go to users <?= ad_icon('arrow', 14) ?></a>
            </div>
        <?php else: ?>
            <div class="ad-cards" data-filter>
                <?php if (count($requests) > 4): ?>
                    <div class="ad-toolbar" style="grid-column: 1 / -1; margin-bottom: 0;">
                        <div class="ad-search">
                            <?= ad_icon('search', 16) ?>
                            <input type="search" class="ad-input" data-live-filter data-focus-key placeholder="Filter by name, username or student no." aria-label="Filter pending requests">
                            <kbd>/</kbd>
                        </div>
                        <span class="ad-count"><span data-filter-count><?= count($requests) ?></span> of <?= count($requests) ?> shown</span>
                    </div>
                <?php endif; ?>

                <?php foreach ($requests as $i => $req):
                    $name = $ad_name($req);
                    $assignment = ad_assignment($req);
                    $conflict = get_conflict_warning($pdo, $req);

                    // Seat check: approving would be refused when the S1 limit is full
                    $seat = null;
                    if ($req['role'] === 'battalion_s1') $seat = ['Battalion S1', $bn_active, 2];
                    if ($req['role'] === 'brigade_s1')   $seat = ['Brigade S1', $br_active, 1];
                    $seat_full = $seat && $seat[1] >= $seat[2];
                ?>
                    <article class="ad-card ad-person ad-rise" style="--i:<?= min($i, 8) + 3 ?>" data-item data-search="<?= e(strtolower($name . ' ' . $req['username'] . ' ' . $req['student_number'] . ' ' . $req['email'])) ?>">
                        <div class="ad-person-top">
                            <span class="ad-avatar is-warn"><?= e(ad_initials($req['first_name'], $req['last_name'])) ?></span>
                            <div class="ad-person-id">
                                <div class="ad-person-name"><?= e($name) ?></div>
                                <div class="ad-person-user"><?= e($req['username']) ?></div>
                            </div>
                            <span class="badge badge-pending">Pending</span>
                        </div>

                        <dl class="ad-kv">
                            <div><dt>Role</dt><dd><?= e(format_role_name($req['role'])) ?></dd></div>
                            <div><dt>Assignment</dt><dd><?= e($assignment) ?></dd></div>
                            <div><dt>Student no.</dt><dd><?= e($req['student_number']) ?></dd></div>
                            <div><dt>Submitted</dt><dd title="<?= e(date('M j, Y g:i A', strtotime($req['created_at']))) ?>"><?= e(ad_time_ago($req['created_at'])) ?></dd></div>
                            <div class="is-wide"><dt>Email</dt><dd><a href="mailto:<?= e($req['email']) ?>"><?= e($req['email']) ?></a></dd></div>
                        </dl>

                        <?php if ($conflict): ?>
                            <div class="ad-note ad-note-warn"><?= ad_icon('alert', 16) ?><div><?= e($conflict) ?> Approving will give this unit two leaders; deactivate the current one first if that is not intended.</div></div>
                        <?php endif; ?>
                        <?php if ($seat_full): ?>
                            <div class="ad-note ad-note-bad"><?= ad_icon('ban', 16) ?><div><?= e($seat[0]) ?> seats are full (<?= (int)$seat[1] ?> of <?= (int)$seat[2] ?>). <a href="<?= BASE_URL ?>/admin/users.php?search=<?= urlencode($req['role']) ?>">Deactivate an account</a> to free a seat, then approve.</div></div>
                        <?php elseif ($seat): ?>
                            <div class="ad-note ad-note-info"><?= ad_icon('info', 16) ?><div><?= e($seat[0]) ?> seats in use: <?= (int)$seat[1] ?> of <?= (int)$seat[2] ?>.</div></div>
                        <?php endif; ?>

                        <div class="ad-person-foot">
                            <form action="<?= BASE_URL ?>/admin/requests.php" method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= (int)$req['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm" <?= $seat_full ? 'disabled title="No seat free"' : '' ?>
                                        data-ad-confirm data-title="Approve registration"
                                        data-message="<?= e('Approve <strong>' . e($name) . '</strong> (' . e($req['username']) . ') as ' . e(format_role_name($req['role'])) . '? They will be emailed their username and can sign in right away.') ?>"
                                        data-confirm-text="Approve account">
                                    <?= ad_icon('check', 14) ?> Approve
                                </button>
                            </form>
                            <form action="<?= BASE_URL ?>/admin/requests.php" method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="user_id" value="<?= (int)$req['id'] ?>">
                                <input type="hidden" name="rejection_reason" data-reason value="">
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        data-ad-prompt data-target="[data-reason]" data-danger="1" data-title="Reject registration"
                                        data-message="<?= e('Why is <strong>' . e($name) . '</strong> being rejected? The officer will see this reason when they try to sign in.') ?>"
                                        data-placeholder="e.g. Student number not found on the unit roster"
                                        data-confirm-text="Reject request">
                                    <?= ad_icon('x', 14) ?> Reject
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
                <p class="ad-muted" data-filter-empty hidden style="grid-column: 1 / -1;">No pending requests match your filter.</p>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
<?php ad_js(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
