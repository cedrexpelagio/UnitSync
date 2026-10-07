<?php
// Forced / User Change Password (Stage 1 - Step A: Plain HTML and PHP)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

// Must be logged in to change password
if (!is_logged_in()) {
    redirect('auth/login.php');
}

$user = current_user();
$errors = [];

$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors['csrf'] = 'Invalid session token. Please try again.';
    }

    if ($current_password === '') {
        $errors['current_password'] = 'Current password is required.';
    }

    if ($new_password === '') {
        $errors['new_password'] = 'New password is required.';
    } elseif (strlen($new_password) < 10) {
        $errors['new_password'] = 'Password must be at least 10 characters long.';
    } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $errors['new_password'] = 'Password must include uppercase, lowercase, and numeric characters.';
    }

    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Please confirm your new password.';
    } elseif ($new_password !== $confirm_password) {
        $errors['confirm_password'] = 'New passwords do not match.';
    }

    if (empty($errors)) {
        // Fetch current password hash from database
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $dbHash = $stmt->fetchColumn();

        if (!$dbHash || !password_verify($current_password, $dbHash)) {
            $errors['current_password'] = 'Current password is incorrect.';
        } else {
            // Update password in DB and clear must_change_password
            $newHash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :hash, must_change_password = 0 
                WHERE id = :id
            ");
            $stmt->execute([
                'hash' => $newHash,
                'id' => $user['id']
            ]);

            // Update session flag
            $_SESSION['must_change_password'] = 0;
            set_flash('success', 'Password updated successfully!');
            redirect_dashboard_by_role($user['role']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - UnitSync</title>
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
            <h2>Change Password</h2>
            <?php if (!empty($_SESSION['must_change_password'])): ?>
                <p>You must change your temporary password before accessing the system.</p>
            <?php else: ?>
                <p>Update your account password</p>
            <?php endif; ?>
        </header>

        <?php show_flash(); ?>

        <?php if (!empty($errors['csrf'])): ?>
            <div class="banner banner-error" role="alert">
                <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div><?= e($errors['csrf']) ?></div>
            </div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/auth/change_password.php" method="POST">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="current_password">Current Password *</label>
                <div class="password-wrapper">
                    <input type="password" id="current_password" name="current_password" class="form-control <?= !empty($errors['current_password']) ? 'is-invalid' : '' ?>" required autofocus>
                    <button type="button" class="password-toggle-btn" aria-label="Toggle current password visibility" data-target="current_password">
                        <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <?php if (!empty($errors['current_password'])): ?>
                    <span class="field-error"><?= e($errors['current_password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="new_password">New Password *</label>
                <span class="field-hint">Minimum 10 characters with uppercase, lowercase, and numbers</span>
                <div class="password-wrapper">
                    <input type="password" id="new_password" name="new_password" class="form-control <?= !empty($errors['new_password']) ? 'is-invalid' : '' ?>" required>
                    <button type="button" class="password-toggle-btn" aria-label="Toggle new password visibility" data-target="new_password">
                        <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <?php if (!empty($errors['new_password'])): ?>
                    <span class="field-error"><?= e($errors['new_password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password *</label>
                <div class="password-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control <?= !empty($errors['confirm_password']) ? 'is-invalid' : '' ?>" required>
                    <button type="button" class="password-toggle-btn" aria-label="Toggle confirm new password visibility" data-target="confirm_password">
                        <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <?php if (!empty($errors['confirm_password'])): ?>
                    <span class="field-error"><?= e($errors['confirm_password']) ?></span>
                <?php endif; ?>
            </div>

            <div style="margin-top: 24px; margin-bottom: 20px;">
                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </div>
        </form>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/ui.js"></script>
</body>
</html>
