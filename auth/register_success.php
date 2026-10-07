<?php
// Registration Success Confirmation Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$username = $_SESSION['registration_username'] ?? null;
unset($_SESSION['registration_username']);

if (!$username) {
    redirect('auth/login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Submitted - UnitSync</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-brand">
        <div class="logo-title">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--green-700);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>UnitSync</span>
        </div>
    </div>

    <div class="auth-card" style="text-align: center;">
        <header>
            <div style="width: 48px; height: 48px; border-radius: 50%; background-color: var(--green-100); color: var(--success); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h2>Registration Submitted</h2>
            <p>Your account request is waiting for Admin review and approval.</p>
        </header>

        <p style="margin-bottom: 12px; font-size: 13px;">Your official system-generated username is:</p>
        
        <div style="font-size: 20px; font-weight: 700; color: var(--green-900); background-color: var(--gold-100); border: 1px dashed var(--gold-500); padding: 12px 24px; border-radius: var(--radius-default); display: inline-block; letter-spacing: 1px; margin-bottom: 20px;">
            <?= e($username) ?>
        </div>

        <p style="font-size: 12px; color: var(--gray-700); margin-bottom: 24px; line-height: 1.5;">
            Please record or save this username. You will use it with your chosen password to log in once an Administrator verifies and approves your account.
        </p>

        <div>
            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-block">Return to Log In</a>
        </div>
    </div>
</body>
</html>
