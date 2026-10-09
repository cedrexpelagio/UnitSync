<?php
// Log In
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect_dashboard_by_role();
}

$status_banner = null; // ['type' => 'info'|'warning'|'error', 'message' => '']
$errors = [];
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// IP detection for rate limiting
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Function to check lockout
function is_locked_out(PDO $pdo, string $user, string $ip): bool {
    // 5 failed attempts in the last 10 minutes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE (username = :username OR ip_address = :ip)
        AND attempted_at >= (NOW() - INTERVAL 10 MINUTE)
    ");
    $stmt->execute(['username' => $user, 'ip' => $ip]);
    return ((int)$stmt->fetchColumn()) >= 5;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors['csrf'] = 'Invalid session token. Please try again.';
    }

    if ($username === '') {
        $errors['username'] = 'Enter your username.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    // Rate limiting check
    if (empty($errors) && is_locked_out($pdo, $username, $ip_address)) {
        $status_banner = [
            'type' => 'error',
            'message' => 'Too many failed login attempts. Your account is temporarily locked for 10 minutes. Please try again later.'
        ];
    } elseif (empty($errors)) {
        // Query user
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, first_name, last_name, role, status,
                   rejection_reason, deactivation_reason, must_change_password
            FROM users
            WHERE username = :username
            LIMIT 1
        ");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Record failed attempt
            $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (?, ?, NOW())");
            $stmt->execute([$username, $ip_address]);

            // Check if just reached lockout
            if (is_locked_out($pdo, $username, $ip_address)) {
                $status_banner = [
                    'type' => 'error',
                    'message' => 'Too many failed login attempts. Your account is temporarily locked for 10 minutes.'
                ];
            } else {
                $errors['general'] = 'Incorrect username or password.';
            }
        } else {
            // Password verified, check account status
            if ($user['status'] === 'pending') {
                $status_banner = [
                    'type' => 'info',
                    'message' => 'Your registration is being processed. Login is blocked until Admin approval.'
                ];
            } elseif ($user['status'] === 'rejected') {
                $reason = !empty($user['rejection_reason']) ? $user['rejection_reason'] : 'No reason specified.';
                $status_banner = [
                    'type' => 'error',
                    'message' => 'Your registration was rejected. Reason: ' . $reason
                ];
            } elseif ($user['status'] === 'deactivated') {
                $reason = !empty($user['deactivation_reason']) ? $user['deactivation_reason'] : 'Account deactivated by administrator.';
                $status_banner = [
                    'type' => 'warning',
                    'message' => 'Your account has been deactivated. Reason: ' . $reason
                ];
            } elseif ($user['status'] === 'approved') {
                // Successful login! Clear old failed attempts for this user
                $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = ?");
                $stmt->execute([$username]);

                // Regenerate session ID for security
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['must_change_password'] = (int)$user['must_change_password'];
                $_SESSION['last_activity'] = time();

                // If must change password, redirect to change password
                if (!empty($user['must_change_password'])) {
                    redirect('auth/change_password.php');
                }

                // Redirect to role dashboard
                redirect_dashboard_by_role($user['role']);
            } else {
                $errors['general'] = 'Account status invalid.';
            }
        }
    }
}

// ---------- View helpers ----------
$cred_error = !empty($errors['general']);                 // wrong username/password
$user_msg   = $errors['username'] ?? '';
$pass_msg   = $errors['password'] ?? ($cred_error ? $errors['general'] : '');
$user_has_error = $user_msg !== '' || $cred_error;
$pass_has_error = $pass_msg !== '';
$focus_password = $cred_error && $username !== '';

$svg = function (string $inner, string $class = '', int $size = 18): string {
    return '<svg class="' . $class . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$icons = [
    'error'   => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'warning' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'info'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
];
$banner = function (string $type, string $message) use ($svg, $icons): void {
    $inner = $icons[$type] ?? $icons['info'];
    echo '<div class="ua-banner ua-banner-' . e($type) . '" role="alert">' . $svg($inner, '', 20) . '<div>' . e($message) . '</div></div>';
};
$msg_block = function (string $name, string $text) use ($svg, $icons): void {
    echo '<div class="ua-msg" id="' . e($name) . '-msg" aria-live="polite"><div><p class="ua-msg-inner">'
        . $svg($icons['error'], '', 14) . '<span data-msg>' . e($text) . '</span></p></div></div>';
};
$has_alerts = $status_banner || !empty($errors['csrf']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - UnitSync</title>
    <script>
        (function () {
            var d = document.documentElement;
            d.classList.add('js');
            try {
                var e = sessionStorage.getItem('ua-enter');
                if (e) { d.setAttribute('data-enter', e); sessionStorage.removeItem('ua-enter'); }
            } catch (x) {}
        })();
    </script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css?v=1">
</head>
<body class="ua" data-page="login">
    <div class="ua-topbar" aria-hidden="true"></div>
    <p class="ua-sr" id="uaLive" role="status" aria-live="polite"></p>

    <aside class="ua-aside">
        <span class="ua-orb ua-orb-1" aria-hidden="true"></span>
        <span class="ua-orb ua-orb-2" aria-hidden="true"></span>

        <div class="ua-brand">Unit<span>Sync</span></div>

        <div class="ua-aside-copy">
            <h2>Welcome back to your unit.</h2>
            <p>Sign in to take attendance, review rosters, and keep everyone in sync.</p>
            <ul class="ua-points">
                <li>Attendance sheets in a few taps</li>
                <li>Cadet rosters for every level of command</li>
                <li>Admin-verified accounts you can trust</li>
            </ul>
        </div>

        <p class="ua-aside-foot">ROTC unit management</p>
    </aside>

    <main class="ua-main">
        <div class="ua-panel" id="uaPanel">
            <header class="ua-head ua-rise" style="--i:0">
                <h1 class="ua-title">Sign in</h1>
                <p class="ua-sub">Use the username and password for your ROTC account.</p>
            </header>

            <div class="ua-rise" style="--i:1"><?php show_flash(); ?></div>

            <?php if ($has_alerts): ?>
                <div class="ua-alerts ua-rise" style="--i:1">
                    <?php if (!empty($errors['csrf'])) $banner('error', $errors['csrf']); ?>
                    <?php if ($status_banner) $banner($status_banner['type'], $status_banner['message']); ?>
                </div>
            <?php endif; ?>

            <form action="<?= BASE_URL ?>/auth/login.php" method="POST" id="loginForm" class="ua-form" novalidate>
                <?= csrf_field() ?>

                <div class="ua-field ua-rise <?= $user_has_error ? 'has-error' : '' ?> <?= ($cred_error && $user_msg === '') ? 'is-quiet' : '' ?>" data-field="username" style="--i:2">
                    <label class="ua-label" for="username">Username</label>
                    <div class="ua-control ua-has-icon">
                        <?= $svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', 'ua-icon') ?>
                        <input type="text" id="username" name="username" class="ua-input"
                               value="<?= e($username) ?>" placeholder="e.g. PL-2026-0001"
                               autocomplete="username" autocapitalize="none" spellcheck="false"
                               aria-describedby="username-msg"
                               <?= $user_has_error ? 'aria-invalid="true"' : '' ?>
                               <?= $focus_password ? '' : 'autofocus' ?>>
                    </div>
                    <?php $msg_block('username', $user_msg); ?>
                </div>

                <div class="ua-field ua-rise <?= $pass_has_error ? 'has-error' : '' ?>" data-field="password" <?= $cred_error ? 'data-cred="1"' : '' ?> style="--i:3">
                    <label class="ua-label" for="password">Password</label>
                    <div class="ua-control ua-has-icon">
                        <?= $svg('<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 'ua-icon') ?>
                        <input type="password" id="password" name="password" class="ua-input ua-input--pw"
                               autocomplete="current-password" aria-describedby="password-msg"
                               <?= $pass_has_error ? 'aria-invalid="true"' : '' ?>
                               <?= $focus_password ? 'autofocus' : '' ?>>
                        <button type="button" class="ua-eye" data-target="password" aria-pressed="false" aria-label="Show password">
                            <?= $svg('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>', 'eye-open', 20) ?>
                            <?= $svg('<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>', 'eye-closed', 20) ?>
                        </button>
                    </div>
                    <p class="ua-caps" data-caps hidden><?= $svg($icons['warning'], '', 14) ?>Caps Lock is on</p>
                    <?php $msg_block('password', $pass_msg); ?>
                </div>

                <div class="ua-rise" style="--i:4">
                    <button type="submit" class="ua-btn ua-btn-primary" id="loginSubmit">
                        <span class="ua-btn-label">Sign in</span>
                        <span class="ua-btn-busy" aria-hidden="true"><span class="ua-spinner"></span><span data-busy-text>Signing in&hellip;</span></span>
                    </button>
                </div>
            </form>

            <div class="ua-rise" style="--i:5">
                <div class="ua-divider">New to UnitSync?</div>
                <a href="<?= BASE_URL ?>/auth/register.php" class="ua-btn ua-btn-outline" data-nav="forward">
                    <span class="ua-btn-label">Create an account <?= $svg('<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>', 'ua-arrow-r') ?></span>
                </a>
                <p class="ua-foot">Lost your username? Ask your Administrator.</p>
            </div>
        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/auth.js?v=1"></script>
</body>
</html>