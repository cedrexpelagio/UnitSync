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
                <li class="<?= $current_script === 'users.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/admin/users.php">Users</a>
                </li>
                <li><span>Training Sessions <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>Structure Setup <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>Attendance <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>Audit Log <span class="badge badge-coming-soon">Soon</span></span></li>

            <?php elseif ($role === 'platoon_leader'): ?>
                <li class="<?= $current_script === 'dashboard.php' ? 'active' : '' ?>">
                    <a href="<?= BASE_URL ?>/leader/dashboard.php">Dashboard</a>
                </li>
                <li><span>Add Cadet <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>View Cadets <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>Take Attendance <span class="badge badge-coming-soon">Soon</span></span></li>

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
                <li><span>Cadet Roster <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>Review Attendance <span class="badge badge-coming-soon">Soon</span></span></li>
                <li><span>View Attendance <span class="badge badge-coming-soon">Soon</span></span></li>
            <?php endif; ?>
        </ul>
    </nav>
</aside>
