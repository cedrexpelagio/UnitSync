<?php
// User Registration
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

// Fetch controlled lists for dropdowns
$programs = $pdo->query("SELECT id, code, name FROM programs ORDER BY code ASC")->fetchAll();
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY id ASC")->fetchAll();

// Platoons with company info for grouped dropdown
$platoonsQuery = $pdo->query("
    SELECT p.id, p.name AS platoon_name, c.id AS company_id, c.name AS company_name
    FROM platoons p
    JOIN companies c ON p.company_id = c.id
    ORDER BY c.name ASC, p.name ASC
")->fetchAll();

$errors = [];
$first_name = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$student_number = trim($_POST['student_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$role = $_POST['role'] ?? '';
$program_id = $_POST['program_id'] ?? '';
$platoon_option = $_POST['platoon_option'] ?? ''; // Format: "company_id:platoon_id"
$consent = isset($_POST['consent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors['csrf'] = 'Invalid session token. Please try submitting again.';
    }

    // Required basic fields
    if ($first_name === '') $errors['first_name'] = 'First name is required.';
    if ($last_name === '') $errors['last_name'] = 'Last name is required.';
    if ($student_number === '') $errors['student_number'] = 'Student number is required.';

    // Email validation
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // Password validation: minimum 10 chars, mixed character types
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 10) {
        $errors['password'] = 'Password must be at least 10 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must include uppercase, lowercase, and numeric characters.';
    }

    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // Role validation
    $allowed_roles = ['class_president', 'platoon_leader', 'battalion_s1', 'brigade_s1'];
    if (!in_array($role, $allowed_roles, true)) {
        $errors['role'] = 'Please select a valid role.';
    }

    // Role-specific field validation
    $company_id_val = null;
    $platoon_id_val = null;
    $program_id_val = null;

    if ($role === 'class_president') {
        if (empty($program_id)) {
            $errors['program_id'] = 'Program is required for Class President.';
        } else {
            // Verify program exists
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE id = ?");
            $stmt->execute([$program_id]);
            if (!$stmt->fetch()) {
                $errors['program_id'] = 'Selected program is invalid.';
            } else {
                $program_id_val = (int)$program_id;
            }
        }
    } elseif ($role === 'platoon_leader') {
        if (empty($platoon_option)) {
            $errors['platoon_option'] = 'Company and Platoon are required for Platoon Leader.';
        } else {
            $parts = explode(':', $platoon_option);
            if (count($parts) !== 2) {
                $errors['platoon_option'] = 'Invalid platoon selection.';
            } else {
                $c_id = (int)$parts[0];
                $p_id = (int)$parts[1];
                $stmt = $pdo->prepare("SELECT id FROM platoons WHERE id = ? AND company_id = ?");
                $stmt->execute([$p_id, $c_id]);
                if (!$stmt->fetch()) {
                    $errors['platoon_option'] = 'Selected platoon does not match the company.';
                } else {
                    $company_id_val = $c_id;
                    $platoon_id_val = $p_id;
                }
            }
        }
    }

    // Consent check
    if (!$consent) {
        $errors['consent'] = 'You must agree to the Data Privacy Notice.';
    }

    // Check unique student number and email
    if (empty($errors['student_number'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE student_number = ?");
        $stmt->execute([$student_number]);
        if ($stmt->fetch()) {
            $errors['student_number'] = 'This student number is already registered.';
        }
    }

    if (empty($errors['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This email address is already registered.';
        }
    }

    // If no errors, generate username and create user inside transaction
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $role_prefixes = [
                'platoon_leader' => 'PL',
                'class_president' => 'CP',
                'battalion_s1' => 'BN',
                'brigade_s1' => 'BR',
            ];
            $prefix = $role_prefixes[$role] ?? 'US';
            $year = date('Y');
            $likePattern = "{$prefix}-{$year}-%";

            // Find highest sequence number for this prefix and year
            $stmt = $pdo->prepare("
                SELECT username FROM users
                WHERE username LIKE ?
                ORDER BY username DESC
                LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([$likePattern]);
            $lastUser = $stmt->fetch();

            $nextSeq = 1;
            if ($lastUser) {
                $lastUsername = $lastUser['username'];
                $parts = explode('-', $lastUsername);
                if (isset($parts[2]) && is_numeric($parts[2])) {
                    $nextSeq = ((int)$parts[2]) + 1;
                }
            }
            $generatedUsername = sprintf("%s-%s-%04d", $prefix, $year, $nextSeq);

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Insert into users
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username, password_hash, first_name, middle_name, last_name,
                    student_number, email, role, status, must_change_password, created_at
                ) VALUES (
                    :username, :password_hash, :first_name, :middle_name, :last_name,
                    :student_number, :email, :role, 'pending', 0, NOW()
                )
            ");
            $stmt->execute([
                'username' => $generatedUsername,
                'password_hash' => $password_hash,
                'first_name' => $first_name,
                'middle_name' => $middle_name !== '' ? $middle_name : null,
                'last_name' => $last_name,
                'student_number' => $student_number,
                'email' => $email,
                'role' => $role,
            ]);
            $userId = (int)$pdo->lastInsertId();

            // Insert into user_assignments if applicable
            if ($program_id_val !== null || $platoon_id_val !== null) {
                $stmt = $pdo->prepare("
                    INSERT INTO user_assignments (user_id, program_id, company_id, platoon_id, created_at)
                    VALUES (:user_id, :program_id, :company_id, :platoon_id, NOW())
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'program_id' => $program_id_val,
                    'company_id' => $company_id_val,
                    'platoon_id' => $platoon_id_val,
                ]);
            }

            $pdo->commit();

            // Store in session and redirect to confirmation page
            $_SESSION['registration_username'] = $generatedUsername;
            redirect('auth/register_success.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['general'] = 'Registration failed due to a system error. Please try again.';
        }
    }
}

// ---------- View data ----------
// Which wizard step each field lives on
$field_steps = [
    'first_name' => 1, 'middle_name' => 1, 'last_name' => 1, 'student_number' => 1, 'email' => 1,
    'password' => 2, 'confirm_password' => 2,
    'role' => 3, 'program_id' => 3, 'platoon_option' => 3,
    'consent' => 4,
];
$field_errors = array_intersect_key($errors, $field_steps);
$steps_with_err = [];
foreach (array_keys($field_errors) as $k) {
    $steps_with_err[$field_steps[$k]] = true;
}
$start_step = $steps_with_err ? min(array_keys($steps_with_err)) : 1;
$max_reach  = $field_errors ? 4 : 1;
$step_names = [1 => 'Details', 2 => 'Password', 3 => 'Role', 4 => 'Confirm'];

// Role cards: value => [title, description, svg inner markup]
$role_cards = [
    'class_president' => [
        'Class President',
        'Represents one program (class).',
        '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    ],
    'platoon_leader' => [
        'Platoon Leader',
        'Leads a platoon and takes attendance.',
        '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    ],
    'battalion_s1' => [
        'Battalion S1',
        'Battalion personnel and cadet roster.',
        '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    ],
    'brigade_s1' => [
        'Brigade S1',
        'Brigade personnel and cadet roster.',
        '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    ],
];

$svg = function (string $inner, string $class = '', int $size = 18): string {
    return '<svg class="' . $class . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
};
$ico_alert = '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
$ico_check = '<polyline points="20 6 9 17 4 12"/>';
$ico_arrow = '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>';
$ico_arrow_l = '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>';

// Inline message slot (JS fills it; server errors are pre-rendered)
$msg_block = function (string $name, string $text) use ($svg, $ico_alert): void {
    echo '<div class="ua-msg" id="' . e($name) . '-msg" aria-live="polite"><div><p class="ua-msg-inner">'
        . $svg($ico_alert, '', 14) . '<span data-msg>' . e($text) . '</span></p></div></div>';
};

// Generic text/email input field
$old = compact('first_name', 'middle_name', 'last_name', 'student_number', 'email');
$text_field = function (string $name, string $label, array $o = []) use ($errors, $old, $msg_block, $svg, $ico_check): void {
    $type = $o['type'] ?? 'text';
    $required = $o['required'] ?? true;
    $err = $errors[$name] ?? '';
    $span = !empty($o['span2']) ? ' ua-span-2' : '';
    ?>
    <div class="ua-field<?= $span ?> <?= $err !== '' ? 'has-error' : '' ?>" data-field="<?= e($name) ?>">
        <label class="ua-label" for="<?= e($name) ?>">
            <span><?= e($label) ?><?php if ($required): ?><span class="ua-req" aria-hidden="true"> *</span><?php endif; ?></span>
            <?php if (!$required): ?><span class="ua-opt">Optional</span><?php endif; ?>
        </label>
        <div class="ua-control">
            <input type="<?= e($type) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" class="ua-input"
                   value="<?= e($old[$name] ?? '') ?>"
                   autocomplete="<?= e($o['autocomplete'] ?? 'off') ?>"
                   <?= !empty($o['inputmode']) ? 'inputmode="' . e($o['inputmode']) . '"' : '' ?>
                   <?= !empty($o['placeholder']) ? 'placeholder="' . e($o['placeholder']) . '"' : '' ?>
                   <?= !empty($o['autofocus']) ? 'autofocus' : '' ?>
                   aria-describedby="<?= e($name) ?>-msg"
                   <?= $err !== '' ? 'aria-invalid="true"' : '' ?>>
            <?= $svg($ico_check, 'ua-tick') ?>
        </div>
        <?php if (!empty($o['hint'])): ?><span class="ua-hint"><?= e($o['hint']) ?></span><?php endif; ?>
        <?php $msg_block($name, $err); ?>
    </div>
    <?php
};

// Password input with show/hide toggle
$password_field = function (string $name, string $label, bool $with_meter) use ($errors, $msg_block, $svg): void {
    $err = $errors[$name] ?? '';
    ?>
    <div class="ua-field <?= $err !== '' ? 'has-error' : '' ?>" data-field="<?= e($name) ?>">
        <label class="ua-label" for="<?= e($name) ?>"><span><?= e($label) ?><span class="ua-req" aria-hidden="true"> *</span></span></label>
        <div class="ua-control">
            <input type="password" id="<?= e($name) ?>" name="<?= e($name) ?>" class="ua-input ua-input--pw"
                   autocomplete="new-password" aria-describedby="<?= e($name) ?>-msg"
                   <?= $err !== '' ? 'aria-invalid="true"' : '' ?>>
            <button type="button" class="ua-eye" data-target="<?= e($name) ?>" aria-pressed="false" aria-label="Show password">
                <?= $svg('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>', 'eye-open', 20) ?>
                <?= $svg('<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>', 'eye-closed', 20) ?>
            </button>
        </div>
        <?php if ($with_meter): ?>
            <div class="ua-meter-row" aria-hidden="true">
                <div class="ua-meter" id="pwMeter" data-level="0"><span></span><span></span><span></span><span></span></div>
                <span class="ua-meter-label" id="pwMeterLabel"></span>
            </div>
        <?php else: ?>
            <p class="ua-match" id="matchHint" aria-live="polite"></p>
        <?php endif; ?>
        <?php $msg_block($name, $err); ?>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - UnitSync</title>
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
<body class="ua" data-page="register">
    <div class="ua-topbar" aria-hidden="true"></div>
    <p class="ua-sr" id="uaLive" role="status" aria-live="polite"></p>

    <aside class="ua-aside">
        <span class="ua-orb ua-orb-1" aria-hidden="true"></span>
        <span class="ua-orb ua-orb-2" aria-hidden="true"></span>

        <div class="ua-brand">Unit<span>Sync</span></div>

        <div class="ua-aside-copy">
            <h2>Join your unit on UnitSync.</h2>
            <p>Create your account in four short steps. An Administrator verifies it against the roster before you can sign in.</p>
            <ul class="ua-points">
                <li>Takes about two minutes</li>
                <li>Your username is generated for you</li>
                <li>Your data is handled under RA 10173</li>
            </ul>
        </div>

        <p class="ua-aside-foot">ROTC unit management</p>
    </aside>

    <main class="ua-main">
        <div class="ua-panel ua-panel--wide">
            <header class="ua-head ua-rise" style="--i:0">
                <h1 class="ua-title">Create your account</h1>
                <p class="ua-sub">Register as an ROTC unit leader or officer.</p>
            </header>

            <?php if (!empty($errors['general']) || !empty($errors['csrf']) || $field_errors): ?>
                <div class="ua-alerts ua-rise" style="--i:1">
                    <?php if (!empty($errors['general'])): ?>
                        <div class="ua-banner ua-banner-error" role="alert"><?= $svg($ico_alert, '', 20) ?><div><?= e($errors['general']) ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($errors['csrf'])): ?>
                        <div class="ua-banner ua-banner-error" role="alert"><?= $svg($ico_alert, '', 20) ?><div><?= e($errors['csrf']) ?></div></div>
                    <?php endif; ?>
                    <?php if ($field_errors): ?>
                        <div class="ua-banner ua-banner-info" role="alert">
                            <?= $svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>', '', 20) ?>
                            <div>Please check the highlighted <?= count($field_errors) === 1 ? 'field' : count($field_errors) . ' fields' ?>. For security, passwords are never kept, so re-enter yours on the Password step.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <nav class="ua-steps ua-rise" style="--i:2" aria-label="Registration progress">
                <ol>
                    <?php foreach ($step_names as $n => $name): ?>
                        <li class="ua-step <?= $n < $start_step ? 'is-done' : '' ?> <?= $n === $start_step ? 'is-current' : '' ?> <?= isset($steps_with_err[$n]) ? 'has-error' : '' ?>" data-step="<?= $n ?>">
                            <button type="button" class="ua-step-btn" <?= $n === $start_step ? 'aria-current="step"' : '' ?> <?= $n > $max_reach && $n !== $start_step ? 'disabled' : '' ?>>
                                <span class="ua-step-dot">
                                    <span class="ua-step-num"><?= $n ?></span>
                                    <?= $svg($ico_check, 'ua-step-check', 16) ?>
                                </span>
                                <span class="ua-step-name"><?= e($name) ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <form action="<?= BASE_URL ?>/auth/register.php" method="POST" id="registerForm" class="ua-form <?= $start_step === 4 ? 'is-last' : '' ?>" novalidate
                  data-start-step="<?= (int)$start_step ?>" data-max-reach="<?= (int)$max_reach ?>">
                <?= csrf_field() ?>

                <!-- Step 1: Personal details -->
                <section class="ua-pane ua-rise <?= $start_step === 1 ? 'is-active' : '' ?>" style="--i:3" data-pane="1" aria-labelledby="pane-title-1">
                    <div class="ua-pane-head">
                        <p class="ua-eyebrow">Step 1 of 4</p>
                        <h2 class="ua-pane-title" id="pane-title-1" tabindex="-1">Personal details</h2>
                        <p class="ua-pane-desc">Use your full legal name as it appears on the official roster.</p>
                    </div>
                    <div class="ua-grid-2">
                        <?php
                        $text_field('first_name', 'First name', ['autocomplete' => 'given-name', 'autofocus' => !$field_errors]);
                        $text_field('middle_name', 'Middle name', ['required' => false, 'autocomplete' => 'additional-name']);
                        $text_field('last_name', 'Last name', ['autocomplete' => 'family-name']);
                        $text_field('student_number', 'Student number', ['inputmode' => 'numeric', 'hint' => 'The Admin uses this to verify you against the roster.']);
                        $text_field('email', 'Email address', ['type' => 'email', 'autocomplete' => 'email', 'placeholder' => 'name@example.com', 'hint' => 'We will use this for account updates.', 'span2' => true]);
                        ?>
                    </div>
                </section>

                <!-- Step 2: Password -->
                <section class="ua-pane <?= $start_step === 2 ? 'is-active' : '' ?>" data-pane="2" aria-labelledby="pane-title-2">
                    <div class="ua-pane-head">
                        <p class="ua-eyebrow">Step 2 of 4</p>
                        <h2 class="ua-pane-title" id="pane-title-2" tabindex="-1">Secure your account</h2>
                        <p class="ua-pane-desc">Choose a strong password you have not used elsewhere.</p>
                    </div>

                    <div class="ua-note">
                        <?= $svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>') ?>
                        <span>Your username is generated automatically after you submit. You will see it on the next screen, so keep it safe.</span>
                    </div>

                    <?php
                    $password_field('password', 'Password', true);
                    $password_field('confirm_password', 'Confirm password', false);
                    ?>

                    <ul class="ua-rules" id="pwRules" aria-label="Password requirements">
                        <li data-rule="length">At least 10 characters</li>
                        <li data-rule="upper">One uppercase letter</li>
                        <li data-rule="lower">One lowercase letter</li>
                        <li data-rule="number">One number</li>
                    </ul>
                </section>

                <!-- Step 3: Role -->
                <section class="ua-pane <?= $start_step === 3 ? 'is-active' : '' ?>" data-pane="3" aria-labelledby="pane-title-3">
                    <div class="ua-pane-head">
                        <p class="ua-eyebrow">Step 3 of 4</p>
                        <h2 class="ua-pane-title" id="pane-title-3" tabindex="-1">Your role</h2>
                        <p class="ua-pane-desc">Pick the position you hold in the unit.</p>
                    </div>

                    <div class="ua-field <?= !empty($errors['role']) ? 'has-error' : '' ?>" data-field="role">
                        <div class="ua-roles" role="radiogroup" aria-label="Your role" id="role_group">
                            <?php foreach ($role_cards as $value => [$title, $desc, $icon]): ?>
                                <label class="ua-role">
                                    <input type="radio" name="role" value="<?= e($value) ?>" <?= $role === $value ? 'checked' : '' ?>>
                                    <span class="ua-role-body">
                                        <?= $svg($icon, 'ua-role-icon', 22) ?>
                                        <span class="ua-role-title"><?= e($title) ?></span>
                                        <span class="ua-role-desc"><?= e($desc) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php $msg_block('role', $errors['role'] ?? ''); ?>
                    </div>

                    <div class="ua-reveal <?= $role === 'class_president' ? 'is-open' : '' ?>" data-reveal="class_president">
                        <div class="ua-reveal-inner"><div class="ua-reveal-box">
                            <div class="ua-field <?= !empty($errors['program_id']) ? 'has-error' : '' ?>" data-field="program_id">
                                <label class="ua-label" for="program_id"><span>Program <span class="ua-req" aria-hidden="true">*</span></span></label>
                                <div class="ua-control">
                                    <select id="program_id" name="program_id" class="ua-input ua-select" aria-describedby="program_id-msg">
                                        <option value="">Select a program</option>
                                        <?php foreach ($programs as $prog): ?>
                                            <option value="<?= (int)$prog['id'] ?>" <?= (string)$program_id === (string)$prog['id'] ? 'selected' : '' ?>>
                                                <?= e($prog['code']) ?> - <?= e($prog['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <span class="ua-hint">Required for Class President.</span>
                                <?php $msg_block('program_id', $errors['program_id'] ?? ''); ?>
                            </div>
                        </div></div>
                    </div>

                    <div class="ua-reveal <?= $role === 'platoon_leader' ? 'is-open' : '' ?>" data-reveal="platoon_leader">
                        <div class="ua-reveal-inner"><div class="ua-reveal-box">
                            <div class="ua-field <?= !empty($errors['platoon_option']) ? 'has-error' : '' ?>" data-field="platoon_option">
                                <label class="ua-label" for="platoon_option"><span>Company and platoon <span class="ua-req" aria-hidden="true">*</span></span></label>
                                <div class="ua-control">
                                    <select id="platoon_option" name="platoon_option" class="ua-input ua-select" aria-describedby="platoon_option-msg">
                                        <option value="">Select a company and platoon</option>
                                        <?php
                                        $currentCompany = '';
                                        foreach ($platoonsQuery as $row):
                                            if ($currentCompany !== $row['company_name']) {
                                                if ($currentCompany !== '') echo '</optgroup>';
                                                $currentCompany = $row['company_name'];
                                                echo '<optgroup label="' . e($currentCompany) . '">';
                                            }
                                            $val = $row['company_id'] . ':' . $row['id'];
                                        ?>
                                            <option value="<?= e($val) ?>" <?= $platoon_option === $val ? 'selected' : '' ?>>
                                                <?= e($row['company_name']) ?> - <?= e($row['platoon_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($currentCompany !== '') echo '</optgroup>'; ?>
                                    </select>
                                </div>
                                <span class="ua-hint">Required for Platoon Leader.</span>
                                <?php $msg_block('platoon_option', $errors['platoon_option'] ?? ''); ?>
                            </div>
                        </div></div>
                    </div>
                </section>

                <!-- Step 4: Review and consent -->
                <section class="ua-pane <?= $start_step === 4 ? 'is-active' : '' ?>" data-pane="4" aria-labelledby="pane-title-4">
                    <div class="ua-pane-head">
                        <p class="ua-eyebrow">Step 4 of 4</p>
                        <h2 class="ua-pane-title" id="pane-title-4" tabindex="-1">Review and consent</h2>
                        <p class="ua-pane-desc">Check your details, then agree to the privacy notice to submit.</p>
                    </div>

                    <dl class="ua-review" id="uaReview">
                        <div><dt>Name</dt><dd data-review="name"></dd></div>
                        <div><dt>Student number</dt><dd data-review="student"></dd></div>
                        <div><dt>Email</dt><dd data-review="email"></dd></div>
                        <div><dt>Role</dt><dd data-review="role"></dd></div>
                        <div hidden><dt>Assignment</dt><dd data-review="assignment"></dd></div>
                    </dl>

                    <div class="ua-field <?= !empty($errors['consent']) ? 'has-error' : '' ?>" data-field="consent">
                        <label class="ua-check" for="consent">
                            <input type="checkbox" id="consent" name="consent" value="1" <?= $consent ? 'checked' : '' ?> aria-describedby="consent-msg">
                            <span class="ua-check-box"><?= $svg($ico_check, '', 14) ?></span>
                            <span>I agree to the Data Privacy Notice and consent to the collection and processing of my personal details for ROTC management in compliance with RA 10173. <span class="ua-req">*</span></span>
                        </label>
                        <?php $msg_block('consent', $errors['consent'] ?? ''); ?>
                    </div>
                </section>

                <div class="ua-actions">
                    <button type="button" class="ua-btn ua-btn-ghost ua-btn-back" id="regBack" <?= $start_step === 1 ? 'hidden' : '' ?>>
                        <span class="ua-btn-label"><?= $svg($ico_arrow_l) ?>Back</span>
                    </button>
                    <button type="button" class="ua-btn ua-btn-primary ua-btn-next" id="regNext">
                        <span class="ua-btn-label">Continue <?= $svg($ico_arrow, 'ua-arrow-r') ?></span>
                    </button>
                    <button type="submit" class="ua-btn ua-btn-primary ua-btn-submit" id="registerSubmit">
                        <span class="ua-btn-label">Submit registration</span>
                        <span class="ua-btn-busy" aria-hidden="true"><span class="ua-spinner"></span><span>Creating your account&hellip;</span></span>
                    </button>
                </div>
            </form>

            <p class="ua-foot ua-rise" style="--i:6">Already have an account? <a href="<?= BASE_URL ?>/auth/login.php" data-nav="back">Log in</a></p>
        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/auth.js?v=1"></script>
</body>
</html>