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

// Everything below runs inside a closure so its variables ($label, $icon, $nav, ...)
// can never overwrite variables used by the page that includes this sidebar.
(function () use ($user, $role, $current_script) {

// Inline icon paths (24x24, stroke style)
$icons = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
    'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'layers'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'check'     => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    'award'     => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    'clipboard' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><polyline points="9 14 11 16 15 12"/>',
    'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
];

// Each item: [label, path, scripts that mark it active, icon key]
$nav = [];
if ($role === 'admin') {
    $nav = [
        ['Dashboard',            '/admin/dashboard.php',  ['dashboard.php'],  'dashboard'],
        ['Registration Requests','/admin/requests.php',   ['requests.php'],   'user-plus'],
        ['Users',                '/admin/users.php',      ['users.php'],      'users'],
        ['Training Sessions',    '/admin/sessions.php',   ['sessions.php'],   'calendar'],
        ['Terms & Semesters',    '/admin/terms.php',      ['terms.php'],      'layers'],
        ['Audit Log',            '/admin/audit_log.php',  ['audit_log.php'],  'file-text'],
    ];
} elseif ($role === 'platoon_leader') {
    $nav = [
        ['Dashboard',      '/leader/dashboard.php', ['dashboard.php'],                  'dashboard'],
        ['Add Cadet',      '/leader/add_cadet.php', ['add_cadet.php'],                  'user-plus'],
        ['View Cadets',    '/leader/cadets.php',    ['cadets.php', 'cadet_edit.php'],   'users'],
        ['Take Attendance','/leader/attendance.php',['attendance.php'],                 'check'],
    ];
} elseif ($role === 'class_president') {
    $nav = [
        ['Dashboard',       '/president/dashboard.php',       ['dashboard.php'],       'dashboard'],
        ['View Attendance', '/president/view_attendance.php', ['view_attendance.php'], 'eye'],
    ];
} elseif ($role === 'battalion_s1' || $role === 'brigade_s1') {
    $nav = [
        ['Dashboard',        '/s1/dashboard.php',         ['dashboard.php'],                                 'dashboard'],
        ['Import Cadets',    '/s1/import_cadets.php',     ['import_cadets.php'],                             'upload'],
        ['Import Officers',  '/s1/import_officers.php',   ['import_officers.php'],                           'upload'],
        ['Cadet Roster',     '/s1/roster.php',            ['roster.php', 'edit_cadet.php'],                  'users'],
        ['Officer Roster',   '/s1/officers.php',          ['officers.php', 'edit_officer.php', 'reassign_officer.php'],              'award'],
        ['Review Attendance','/s1/review_attendance.php', ['review_attendance.php', 'review_session.php'],   'clipboard'],
        ['View Attendance',  '/s1/view_attendance.php',   ['view_attendance.php'],                           'eye'],
    ];
}

$sb_name = trim($user['first_name'] . ' ' . $user['last_name']);
$sb_initials = mb_strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1));
$on_profile = $current_script === 'profile.php';
?>
<aside class="app-sidebar" id="app-sidebar">
    <nav class="sidebar-nav" aria-label="Main navigation">
        <ul>
            <?php foreach ($nav as $i => $item): ?>
                <?php [$label, $href, $match, $icon] = $item; $is_active = in_array($current_script, $match, true); ?>
                <li class="<?= $is_active ? 'active' : '' ?>" style="--i: <?= (int) $i ?>">
                    <a href="<?= BASE_URL . $href ?>"<?= $is_active ? ' aria-current="page"' : '' ?>>
                        <i class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$icon] ?></svg></i>
                        <?= e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a class="sidebar-profile<?= $on_profile ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/profile.php"<?= $on_profile ? ' aria-current="page"' : '' ?>>
            <span class="sp-avatar" aria-hidden="true"><?= e($sb_initials !== '' ? $sb_initials : '?') ?></span>
            <span class="sp-text">
                <span class="sp-name"><?= e($sb_name) ?></span>
                <span class="sp-sub">My Profile</span>
            </span>
            <svg class="sp-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </a>
    </div>
</aside>
<?php })(); ?>