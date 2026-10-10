<?php
// My Profile - available to every signed-in role.
// Place this file in the web root (next to /admin, /leader, /s1 ...).

// ---- ADAPT: paths to your layout partials (header.php lives here) ----------
$layout_dir = __DIR__ . '/includes';   // folder containing header.php, helpers.php, auth.php, csrf.php
$footer     = $layout_dir . '/footer.php';
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';   // creates the global $pdo connection
require_once $layout_dir . '/helpers.php';
require_once $layout_dir . '/auth.php';
require_once $layout_dir . '/csrf.php';

$user = current_user();
if (!$user) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// Find the user's id (the key name depends on your auth.php).
$uid = $user['id'] ?? $user['user_id'] ?? null;

// Username is the account's sign-in identity. Set to true to let people change it.
$username_editable = true;

// While testing, show the real database error on the page. Set to false when done.
$debug_errors = true;

/* ---- ADAPT: helpers that depend on parts of your app I could not see ------ */

// Returns the PDO connection created by config/db.php.
function profile_db(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('No PDO connection available. Check that config/db.php is loaded.');
}

// Verifies the CSRF token by reading the field name/value that csrf_field() emits.
// If your csrf.php has a verify function, call it here instead.
function profile_csrf_ok(): bool
{
    $html = csrf_field();
    if (!preg_match('/name=["\']([^"\']+)["\']/', $html, $n) || !preg_match('/value=["\']([^"\']*)["\']/', $html, $v)) {
        return false;
    }
    $sent = $_POST[$n[1]] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($v[1], $sent);
}
/* ------------------------------------------------------------------------- */

$values = [
    'first_name' => (string) $user['first_name'],
    'last_name'  => (string) $user['last_name'],
    'username'   => (string) $user['username'],
];
$errors  = [];
$success = isset($_GET['updated']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!profile_csrf_ok()) {
        $errors['form'] = 'Your session expired. Reload the page and try again.';
    } else {
        $values['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
        $values['last_name']  = trim((string) ($_POST['last_name'] ?? ''));
        if ($username_editable) {
            $values['username'] = trim((string) ($_POST['username'] ?? ''));
        }

        $name_re = '/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]*$/u';
        foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $key => $label) {
            $len = mb_strlen($values[$key]);
            if ($len === 0) {
                $errors[$key] = $label . ' is required.';
            } elseif ($len > 50) {
                $errors[$key] = $label . ' must be 50 characters or fewer.';
            } elseif (!preg_match($name_re, $values[$key])) {
                $errors[$key] = 'Use letters, spaces, hyphens, apostrophes or periods only.';
            }
        }
        if ($username_editable && !preg_match('/^[A-Za-z0-9._-]{3,30}$/', $values['username'])) {
            $errors['username'] = 'Use 3-30 letters, numbers, dots, dashes or underscores.';
        }

        if (!$errors) {
            try {
                if ($uid === null) {
                    throw new RuntimeException('current_user() has no "id" or "user_id" key, so the account cannot be identified.');
                }
                $db = profile_db();

                if ($username_editable && $values['username'] !== $user['username']) {
                    $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                    $stmt->execute([$values['username'], $uid]);
                    if ($stmt->fetch()) {
                        $errors['username'] = 'That username is already taken.';
                    }
                }

                if (!$errors) {
                    if ($username_editable) {
                        $stmt = $db->prepare('UPDATE users SET first_name = ?, last_name = ?, username = ? WHERE id = ?');
                        $stmt->execute([$values['first_name'], $values['last_name'], $values['username'], $uid]);
                    } else {
                        $stmt = $db->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?');
                        $stmt->execute([$values['first_name'], $values['last_name'], $uid]);
                    }

                    // Confirm the database really holds the new values.
                    $check = $db->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
                    $check->execute([$uid]);
                    $row = $check->fetch();
                    if (!$row) {
                        throw new RuntimeException('No row in "users" has id ' . $uid . '. The id column name may differ.');
                    }
                    if ($row['first_name'] !== $values['first_name'] || $row['last_name'] !== $values['last_name']
                        || ($username_editable && $row['username'] !== $values['username'])) {
                        throw new RuntimeException('The update ran but the database kept the old values (trigger or read-only column?).');
                    }

                    // current_user() in auth.php reads these session keys, so refresh them
                    // to update the header and sidebar right away.
                    $_SESSION['first_name'] = $row['first_name'];
                    $_SESSION['last_name']  = $row['last_name'];
                    $_SESSION['username']   = $row['username'];
                    session_write_close();

                    header('Location: ' . BASE_URL . '/profile.php?updated=1');
                    exit;
                }
            } catch (Throwable $ex) {
                error_log('profile.php update failed: ' . $ex->getMessage());
                $errors['form'] = 'We could not save your changes. Try again in a moment.';
                if ($debug_errors) {
                    $errors['form'] .= ' [Details: ' . $ex->getMessage() . ']';
                }
            }
        }
    }
}

// Load the saved values straight from the database for this page.
$profile_user = $user;
if ($uid !== null) {
    try {
        $st = profile_db()->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        if ($fresh = $st->fetch()) {
            $profile_user = array_merge($user, $fresh);
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $values = [
                    'first_name' => (string) $fresh['first_name'],
                    'last_name'  => (string) $fresh['last_name'],
                    'username'   => (string) $fresh['username'],
                ];
            }
        }
    } catch (Throwable $ex) {
        error_log('profile.php refresh failed: ' . $ex->getMessage());
    }
}

$saved_first = (string) $profile_user['first_name'];
$saved_last  = (string) $profile_user['last_name'];
$display_name = trim($values['first_name'] . ' ' . $values['last_name']);
$profile_initials = mb_strtoupper(mb_substr($values['first_name'], 0, 1) . mb_substr($values['last_name'], 0, 1));

$page_title = 'My Profile';
require_once $layout_dir . '/header.php';
$user = $profile_user;   // header.php re-reads current_user(); keep the fresh copy for this page
?>
<div class="us-profile">
    <div class="content-header">
        <h1>My Profile</h1>
        <p>View and update your account information.</p>
    </div>

    <?php if ($success && !$errors): ?>
        <div class="us-banner us-banner-success" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span>Profile updated.</span>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors['form'])): ?>
        <div class="us-banner us-banner-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($errors['form']) ?></span>
        </div>
    <?php endif; ?>

    <div class="us-grid">
        <aside class="us-identity" aria-label="Account summary">
            <div class="us-avatar-lg" data-live-initials><?= e($profile_initials !== '' ? $profile_initials : '?') ?></div>
            <div class="us-identity-name" data-live-name><?= e($display_name) ?></div>
            <div class="us-identity-user">@<span data-live-username><?= e($values['username']) ?></span></div>
            <span class="us-role-pill"><?= e(format_role_name($user['role'])) ?></span>
        </aside>

        <section class="us-card">
            <h2 class="us-card-title">Account details</h2>
            <p class="us-card-sub">Your role is assigned by an administrator and can't be changed here.</p>

            <form id="profile-form" action="<?= BASE_URL ?>/profile.php" method="POST" novalidate>
                <?= csrf_field() ?>

                <div class="us-row">
                    <div class="us-field<?= isset($errors['first_name']) ? ' has-error' : '' ?>">
                        <label class="us-label" for="first_name">First name <span class="us-req">*</span></label>
                        <input class="us-input" type="text" id="first_name" name="first_name" maxlength="50" autocomplete="given-name"
                               value="<?= e($values['first_name']) ?>" data-original="<?= e($saved_first) ?>" required>
                        <p class="us-error" id="first_name-error"><?= e($errors['first_name'] ?? '') ?></p>
                    </div>
                    <div class="us-field<?= isset($errors['last_name']) ? ' has-error' : '' ?>">
                        <label class="us-label" for="last_name">Last name <span class="us-req">*</span></label>
                        <input class="us-input" type="text" id="last_name" name="last_name" maxlength="50" autocomplete="family-name"
                               value="<?= e($values['last_name']) ?>" data-original="<?= e($saved_last) ?>" required>
                        <p class="us-error" id="last_name-error"><?= e($errors['last_name'] ?? '') ?></p>
                    </div>
                </div>

                <div class="us-field<?= isset($errors['username']) ? ' has-error' : '' ?>">
                    <label class="us-label" for="username">
                        <span>Username<?= $username_editable ? ' <span class="us-req">*</span>' : '' ?></span>
                        <?php if (!$username_editable): ?><span class="us-lock">Locked</span><?php endif; ?>
                    </label>
                    <?php if ($username_editable): ?>
                        <input class="us-input" type="text" id="username" name="username" maxlength="30" autocomplete="username"
                               value="<?= e($values['username']) ?>" data-original="<?= e($user['username']) ?>" required>
                    <?php else: ?>
                        <input class="us-input" type="text" id="username" value="<?= e($user['username']) ?>" readonly>
                    <?php endif; ?>
                    <p class="us-error" id="username-error"><?= e($errors['username'] ?? '') ?></p>
                </div>

                <div class="us-field">
                    <label class="us-label" for="role"><span>Role</span><span class="us-lock">Locked</span></label>
                    <input class="us-input" type="text" id="role" value="<?= e(format_role_name($user['role'])) ?>" readonly>
                </div>

                <div class="us-actions">
                    <button type="reset" class="us-btn us-btn-ghost" id="profile-reset">Discard changes</button>
                    <button type="submit" class="us-btn us-btn-primary" id="profile-save">Save changes</button>
                </div>
            </form>
        </section>
    </div>
</div>
<?php require_once $footer; ?>