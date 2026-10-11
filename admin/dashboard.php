<?php
// Admin Dashboard: tells the admin what to do next
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_ui.php';

require_role('admin');
$admin = current_user();

$count = static function (string $sql) use ($pdo): int {
    return (int)$pdo->query($sql)->fetchColumn();
};

// Summary metrics
$pending_count  = $count("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$approved_count = $count("SELECT COUNT(*) FROM users WHERE status = 'approved'");
$total_count    = $count("SELECT COUNT(*) FROM users");

// Setup progress
$terms_count      = $count("SELECT COUNT(*) FROM terms");
$active_term      = $pdo->query("SELECT id, name FROM terms WHERE is_active = 1 LIMIT 1")->fetch();
$active_sessions  = 0;
if ($active_term) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM training_sessions WHERE term_id = ?");
    $stmt->execute([(int)$active_term['id']]);
    $active_sessions = (int)$stmt->fetchColumn();
}
$officers_approved = $count("SELECT COUNT(*) FROM users WHERE status = 'approved' AND role <> 'admin'");

// S1 seat usage (same limits the approval screen enforces)
$bn_active = $count("SELECT COUNT(*) FROM users WHERE role = 'battalion_s1' AND status = 'approved'");
$br_active = $count("SELECT COUNT(*) FROM users WHERE role = 'brigade_s1' AND status = 'approved'");

// Recent activity
$recent = $pdo->query("
    SELECT a.action, a.entity, a.details, a.created_at, u.first_name, u.last_name
    FROM audit_log a
    LEFT JOIN users u ON a.actor_id = u.id
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 5
")->fetchAll();

$setup = [
    [$terms_count > 0,      'Create an academic term',        'Terms group your training sessions by semester.',              '/admin/terms.php',    'Create term'],
    [(bool)$active_term,    'Make one term active',           'Attendance is taken against the active term.',                 '/admin/terms.php',    'Choose term'],
    [$active_sessions > 0,  'Generate the training calendar', 'Create the weekly sessions for the active term in one step.', '/admin/sessions.php', 'Open sessions'],
    [$officers_approved > 0, 'Approve your first officers',   'Officers cannot sign in until you approve their request.',     '/admin/requests.php', 'Review requests'],
];
$done = 0;
$next_idx = null;
foreach ($setup as $i => $s) {
    if ($s[0]) { $done++; } elseif ($next_idx === null) { $next_idx = $i; }
}
$pct = (int)round($done / count($setup) * 100);

// The single most useful thing to do right now
if ($pending_count > 0) {
    $hero = ['icon' => 'inbox', 'eyebrow' => 'Needs your attention', 'calm' => false,
        'title' => ad_plural($pending_count, 'registration') . ' waiting for review',
        'text' => 'Officers cannot sign in until you approve or reject their request.',
        'url' => '/admin/requests.php', 'cta' => 'Review requests'];
} elseif ($next_idx !== null) {
    $s = $setup[$next_idx];
    $hero = ['icon' => 'zap', 'eyebrow' => 'Next setup step', 'calm' => false,
        'title' => $s[1], 'text' => $s[2], 'url' => $s[3], 'cta' => $s[4]];
} else {
    $hero = ['icon' => 'check-circle', 'eyebrow' => 'All caught up', 'calm' => true,
        'title' => 'Nothing needs your attention',
        'text' => 'No pending requests and your training calendar is ready. Check the audit log any time to see what changed.',
        'url' => '/admin/audit_log.php', 'cta' => 'Open audit log'];
}

$tasks = [
    ['inbox',    'Registration requests', 'Approve or reject new sign-ups. Start here when someone says they cannot log in.', '/admin/requests.php', $pending_count],
    ['users',    'Users',                 'Find an officer, reset a password, or deactivate an account when someone graduates or is replaced.', '/admin/users.php', 0],
    ['layers',   'Terms',                 'Start a new semester or switch which term is active.', '/admin/terms.php', 0],
    ['calendar', 'Training sessions',     'Build the weekly calendar, then cancel a day or mark it held.', '/admin/sessions.php', 0],
    ['list',     'Audit log',             'See who changed what, and when.', '/admin/audit_log.php', 0],
];

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
ad_css();
?>
<div class="ad">
    <?php
    ad_head(
        'Welcome back' . (!empty($admin['first_name']) ? ', ' . $admin['first_name'] : ''),
        'Here is where things stand and what to do next.',
        '',
        'dashboard'
    );
    ad_guide('dashboard', 'How to use this dashboard', [
        ['Start with the banner', 'It always shows the most useful next action, such as pending requests or the next setup step.'],
        ['Follow the checklist', 'Four steps take a new system from empty to ready for officers. Finished steps are ticked off for you.'],
        ['Jump anywhere', 'The task list says when to use each page, so you never have to guess where something lives.'],
    ]);
    ?>

    <section class="ad-hero ad-rise <?= $hero['calm'] ? 'is-calm' : '' ?>" style="--i:2" aria-label="Next action">
        <span class="ad-hero-icon"><?= ad_icon($hero['icon'], 24) ?></span>
        <div class="ad-hero-copy">
            <p class="ad-hero-eyebrow"><?= e($hero['eyebrow']) ?></p>
            <h2><?= e($hero['title']) ?></h2>
            <p><?= e($hero['text']) ?></p>
        </div>
        <a href="<?= BASE_URL . e($hero['url']) ?>" class="btn"><?= e($hero['cta']) ?> <?= ad_icon('arrow', 16) ?></a>
    </section>

    <section class="ad-stats" aria-label="Summary">
        <a class="ad-card ad-stat is-hoverable ad-rise" style="--i:3" href="<?= BASE_URL ?>/admin/requests.php">
            <div class="ad-stat-label">Pending requests</div>
            <div class="ad-stat-num <?= $pending_count > 0 ? 'is-warn' : '' ?>" data-count="<?= $pending_count ?>"><?= $pending_count ?></div>
            <span class="ad-stat-link">Review requests <?= ad_icon('arrow', 14) ?></span>
        </a>
        <a class="ad-card ad-stat is-hoverable ad-rise" style="--i:4" href="<?= BASE_URL ?>/admin/users.php">
            <div class="ad-stat-label">Approved users</div>
            <div class="ad-stat-num is-ok" data-count="<?= $approved_count ?>"><?= $approved_count ?></div>
            <span class="ad-stat-link">Manage users <?= ad_icon('arrow', 14) ?></span>
        </a>
        <a class="ad-card ad-stat is-hoverable ad-rise" style="--i:5" href="<?= BASE_URL ?>/admin/users.php">
            <div class="ad-stat-label">Total accounts</div>
            <div class="ad-stat-num" data-count="<?= $total_count ?>"><?= $total_count ?></div>
            <span class="ad-stat-link">See everyone <?= ad_icon('arrow', 14) ?></span>
        </a>
    </section>

    <div class="ad-split">
        <section class="ad-panel ad-rise" style="--i:6" aria-labelledby="setup-title">
            <h2 class="ad-panel-title" id="setup-title">Setup checklist</h2>
            <div class="ad-progress-row"><span><strong><?= $done ?> of <?= count($setup) ?></strong> steps done</span><span><?= $pct ?>%</span></div>
            <div class="ad-progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="--p: <?= $pct ?>%"></span></div>

            <ol class="ad-steps">
                <?php foreach ($setup as $i => $s): ?>
                    <li class="ad-step <?= $s[0] ? 'is-done' : '' ?> <?= $i === $next_idx ? 'is-next' : '' ?>">
                        <span class="ad-step-dot"><?= $s[0] ? ad_icon('check', 13) : ($i + 1) ?></span>
                        <div class="ad-step-body">
                            <div class="ad-step-title"><?= e($s[1]) ?></div>
                            <div class="ad-step-text"><?= $s[0] && $i === 1 && $active_term ? 'Active: ' . e($active_term['name']) : e($s[2]) ?></div>
                        </div>
                        <?php if (!$s[0]): ?>
                            <a href="<?= BASE_URL . e($s[3]) ?>" class="btn btn-sm <?= $i === $next_idx ? 'btn-primary' : 'btn-secondary' ?>"><?= e($s[4]) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <div>
            <section class="ad-panel ad-rise" style="--i:7" aria-labelledby="seats-title">
                <h2 class="ad-panel-title" id="seats-title">S1 seats in use</h2>
                <p class="ad-panel-sub">The system allows 2 active Battalion S1 and 1 active Brigade S1. Deactivate one to free a seat.</p>
                <div class="ad-seats">
                    <div>
                        <div class="ad-progress-row"><span>Battalion S1</span><strong><?= $bn_active ?> / 2</strong></div>
                        <div class="ad-progress"><span style="--p: <?= min(100, $bn_active * 50) ?>%"></span></div>
                    </div>
                    <div>
                        <div class="ad-progress-row"><span>Brigade S1</span><strong><?= $br_active ?> / 1</strong></div>
                        <div class="ad-progress"><span style="--p: <?= min(100, $br_active * 100) ?>%"></span></div>
                    </div>
                </div>
            </section>

            <section class="ad-panel ad-rise" style="--i:8" aria-labelledby="recent-title">
                <h2 class="ad-panel-title" id="recent-title">Recent activity</h2>
                <?php if (!$recent): ?>
                    <p class="ad-panel-sub" style="margin-bottom:0;">Nothing has happened yet. Actions you take will show up here.</p>
                <?php else: ?>
                    <ul class="ad-recent">
                        <?php foreach ($recent as $r):
                            $who = $r['first_name'] !== null ? trim($r['first_name'] . ' ' . $r['last_name']) : 'System';
                        ?>
                            <li>
                                <div><strong><?= e($who) ?></strong> &middot; <?= e(ad_label($r['action'])) ?></div>
                                <time datetime="<?= e($r['created_at']) ?>"><?= e(ad_time_ago($r['created_at'])) ?></time>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top:10px;"><a href="<?= BASE_URL ?>/admin/audit_log.php">View full audit log &rarr;</a></p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <h2 class="ad-section-title">Where do you need to go?</h2>
    <div class="ad-tasks">
        <?php foreach ($tasks as $i => [$icon, $title, $text, $url, $badge]): ?>
            <a href="<?= BASE_URL . e($url) ?>" class="ad-card ad-task is-hoverable ad-rise" style="--i:<?= 9 + $i ?>">
                <span class="ad-task-icon"><?= ad_icon($icon, 19) ?></span>
                <span class="ad-task-body">
                    <span class="ad-task-title"><?= e($title) ?><?php if ($badge > 0): ?> <span class="ad-pill"><?= (int)$badge ?></span><?php endif; ?></span>
                    <span class="ad-task-text"><?= e($text) ?></span>
                </span>
                <?= ad_icon('arrow', 16) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php ad_js(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
