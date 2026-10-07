<?php
// Log In (Stage 1 - Step A: Plain HTML and PHP)
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
        $errors['username'] = 'Username is required.';
    }
    if ($password === '') {
        $errors['password'] = 'Password is required.';
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
                $errors['general'] = 'Invalid username or password.';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - UnitSync</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-brand">
        <div class="logo-title">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--green-700);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>UnitSync</span>
        </div>
    </div>

    <div class="auth-card">
        <header>
            <h2>Sign In</h2>
            <p>Enter your ROTC credentials to access your dashboard</p>
        </header>

        <?php show_flash(); ?>

        <?php if ($status_banner): ?>
            <div class="banner banner-<?= e($status_banner['type']) ?>" role="alert">
                <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <?php if ($status_banner['type'] === 'error'): ?>
                        <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                    <?php elseif ($status_banner['type'] === 'warning'): ?>
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>
                    <?php else: ?>
                        <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>
                    <?php endif; ?>
                </svg>
                <div><?= e($status_banner['message']) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="banner banner-error" role="alert">
                <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div><?= e($errors['general']) ?></div>
            </div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/auth/login.php" method="POST">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control <?= !empty($errors['username']) ? 'is-invalid' : '' ?>" value="<?= e($username) ?>" required autofocus>
                <?php if (!empty($errors['username'])): ?>
                    <span class="field-error"><?= e($errors['username']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control <?= !empty($errors['password']) ? 'is-invalid' : '' ?>" required>
                <?php if (!empty($errors['password'])): ?>
                    <span class="field-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>

            <div style="margin-top: 24px; margin-bottom: 20px;">
                <button type="submit" class="btn btn-primary btn-block">Log In</button>
            </div>
        </form>

        <div style="text-align: center; font-size: 13px;">
            <p>Don't have an account? <a href="<?= BASE_URL ?>/auth/register.php"><strong>Register</strong></a></p>
        </div>
    </div>
</body>
</html>
