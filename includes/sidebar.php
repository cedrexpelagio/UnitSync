<?php
// Layout Sidebar for Each Role
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

$user = current_user();
if (!$user) {
    return;
}
$role = $user['role'];
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="app-sidebar" id="app-sidebar">
    <nav class="sidebar-nav">
        <ul>
            <?php if ($role === 'admin'): ?>
                <li class="<?= $current_script === 'dashboard.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a>
                </li>
                <li class="<?= $current_script === 'requests.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/admin/requests.php">Registration Requests</a>
                </li>
                <li class="<?= $current_script === 'sessions.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/admin/sessions.php">Training Sessions</a>
                </li>
                <li class="<?= $current_script === 'terms.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/admin/terms.php">Terms & Semesters</a>
                </li>
                <li class="<?= $current_script === 'audit_log.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/admin/audit_log.php">Audit Log</a>
                </li>

            <?php elseif ($role === 'platoon_leader'): ?>
                <li class="<?= $current_script === 'dashboard.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/leader/dashboard.php">Dashboard</a>
                </li>
                <li class="<?= $current_script === 'add_cadet.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/leader/add_cadet.php">Add Cadet</a>
                </li>
                <li class="<?= in_array($current_script, ['cadets.php', 'cadet_edit.php'], true) ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/leader/cadets.php">View Cadets</a>
                </li>
                <li class="<?= $current_script === 'attendance.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/leader/attendance.php">Take Attendance</a>
                </li>

            <?php elseif ($role === 'class_president'): ?>
                <li class="<?= $current_script === 'dashboard.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/president/dashboard.php">Dashboard</a>
                </li>

            <?php elseif ($role === 'battalion_s1' || $role === 'brigade_s1'): ?>
                <li class="<?= $current_script === 'dashboard.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/s1/dashboard.php">Dashboard</a>
                </li>
                <li class="<?= $current_script === 'import_cadets.php' ? 'active' : '' ?>">
                <a href="<?= BASE_URL ?>/s1/import_cadets.php">Import Cadets</a>
                </li>
                <li class="<?= in_array($current_script, ['roster.php', 'edit_cadet.php'], true) ? 'active' : '' ?>">
                <a href="<?= BASE_URL ?>/s1/roster.php">Cadet Roster</a>
                </li>
                <li class="<?= in_array($current_script, ['review_attendance.php', 'review_session.php'], true) ? 'active' : '' ?>">
                <a href="<?= BASE_URL ?>/s1/review_attendance.php">Review Attendance</a>
                </li>
                <li><span>View Attendance <span class="badge badge-coming-soon">Soon</span></span></li>
            <?php endif; ?>
        </ul>
    </nav>
</aside>