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
$current_uri = $_SERVER['REQUEST_URI'] ?? '';
?>
<aside class="app-sidebar">
    <nav class="sidebar-nav">
        <ul>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a></li>
                <li><a href="<?= BASE_URL ?>/admin/requests.php">Registration Requests</a></li>
                <li><a href="<?= BASE_URL ?>/admin/users.php">Users</a></li>
                <li><span>Training Sessions (Coming soon)</span></li>
                <li><span>Structure Setup (Coming soon)</span></li>
                <li><span>Attendance (Coming soon)</span></li>
                <li><span>Audit Log (Coming soon)</span></li>

            <?php elseif ($role === 'platoon_leader'): ?>
                <li><a href="<?= BASE_URL ?>/leader/dashboard.php">Dashboard</a></li>
                <li><span>Add Cadet (Coming soon)</span></li>
                <li><span>View Cadets (Coming soon)</span></li>
                <li><span>Take Attendance (Coming soon)</span></li>

            <?php elseif ($role === 'class_president'): ?>
                <li><a href="<?= BASE_URL ?>/president/dashboard.php">Dashboard</a></li>

            <?php elseif ($role === 'battalion_s1' || $role === 'brigade_s1'): ?>
                <li><a href="<?= BASE_URL ?>/s1/dashboard.php">Dashboard</a></li>
                <li><span>Cadet Roster (Coming soon)</span></li>
                <li><span>Review Attendance (Coming soon)</span></li>
                <li><span>View Attendance (Coming soon)</span></li>
            <?php endif; ?>
        </ul>
    </nav>
</aside>
