<?php
// Registration Success Confirmation Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$username = $_SESSION['registration_username'] ?? null;
unset($_SESSION['registration_username']);

if (!$username) {
    redirect('auth/login.php');
}

$check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Submitted - UnitSync</title>
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
<body class="ua" data-page="success">
    <aside class="ua-aside">
        <span class="ua-orb ua-orb-1" aria-hidden="true"></span>
        <span class="ua-orb ua-orb-2" aria-hidden="true"></span>

        <div class="ua-brand">Unit<span>Sync</span></div>

        <div class="ua-aside-copy">
            <h2>Request received.</h2>
            <p>An Administrator will verify your details against the roster. You will be able to sign in as soon as you are approved.</p>
            <ul class="ua-points">
                <li>Your details are safely submitted</li>
                <li>Your username has been generated</li>
                <li>Approval is the only step left</li>
            </ul>
        </div>

        <p class="ua-aside-foot">ROTC unit management</p>
    </aside>

    <main class="ua-main">
        <div class="ua-panel">
            <div class="ua-seal" aria-hidden="true">
                <svg viewBox="0 0 96 96">
                    <circle class="ua-seal-fill" cx="48" cy="48" r="44"/>
                    <circle class="ua-seal-ring" cx="48" cy="48" r="44"/>
                    <path class="ua-seal-check" d="M30 50l13 13 24-28"/>
                </svg>
                <div class="ua-confetti" data-confetti></div>
            </div>

            <header class="ua-head ua-center ua-rise" style="--i:5">
                <h1 class="ua-title">Registration submitted</h1>
                <p class="ua-sub">Your account request is waiting for Administrator review and approval.</p>
            </header>

            <div class="ua-id-card ua-rise" style="--i:7">
                <p class="ua-id-label">Your username</p>
                <div class="ua-id" data-username><?= e($username) ?></div>
                <button type="button" class="ua-copy" data-copy>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    <span data-copy-label>Copy username</span>
                </button>
                <p class="ua-id-note">Save this now. You will sign in with it and the password you just chose once your account is approved.</p>
            </div>

            <ol class="ua-timeline ua-rise" style="--i:9" aria-label="What happens next">
                <li class="is-done">
                    <span class="ua-node"><?= $check ?></span>
                    <div><div class="ua-tl-title">Request submitted</div><div class="ua-tl-desc">Your details are saved.</div></div>
                </li>
                <li class="is-current">
                    <span class="ua-node">2</span>
                    <div><div class="ua-tl-title">Administrator review</div><div class="ua-tl-desc">Your student number is checked against the roster.</div></div>
                </li>
                <li>
                    <span class="ua-node">3</span>
                    <div><div class="ua-tl-title">Approval</div><div class="ua-tl-desc">Sign in with your username and password.</div></div>
                </li>
            </ol>

            <div class="ua-rise" style="--i:10">
                <a href="<?= BASE_URL ?>/auth/login.php" class="ua-btn ua-btn-primary" data-nav="back">
                    <span class="ua-btn-label">Continue to log in</span>
                </a>
            </div>
        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/auth.js?v=1"></script>
</body>
</html>