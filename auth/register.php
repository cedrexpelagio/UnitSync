<?php
// User Registration (Stage 1 - Step A: Plain HTML and PHP)
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

// ---------- View helpers ----------
// Field error key => id of the element to jump to (used by the error summary)
$field_anchors = [
    'first_name'       => 'first_name',
    'last_name'        => 'last_name',
    'student_number'   => 'student_number',
    'email'            => 'email',
    'password'         => 'password',
    'confirm_password' => 'confirm_password',
    'role'             => 'role_group',
    'program_id'       => 'program_id',
    'platoon_option'   => 'platoon_option',
    'consent'          => 'consent',
];
$summary_errors = array_intersect_key($errors, $field_anchors);

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
        '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    ],
    'brigade_s1' => [
        'Brigade S1',
        'Brigade personnel and cadet roster.',
        '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    ],
];

$err_icon = '<svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
$eye_btn = function (string $target, string $label): string {
    return '<button type="button" class="password-toggle-btn" aria-label="' . e($label) . '" data-target="' . e($target) . '">'
        . '<svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
        . '<svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>'
        . '</button>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - UnitSync</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/register.css?v=2">
</head>
<body class="auth-page reg-page">
    <div class="auth-brand">
        <div class="logo-title">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--green-700);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>UnitSync</span>
        </div>
    </div>

    <div class="auth-card reg-card">
        <header class="reg-header">
            <h2>Create an account</h2>
            <p>Register as an ROTC unit leader or officer. An Administrator must approve your account before you can sign in.</p>
        </header>

        <?php if (!empty($errors['general']) || !empty($errors['csrf']) || $summary_errors): ?>
            <div class="reg-banners">
                <?php if (!empty($errors['general'])): ?>
                    <div class="banner banner-error" role="alert"><?= $err_icon ?><div><?= e($errors['general']) ?></div></div>
                <?php endif; ?>
                <?php if (!empty($errors['csrf'])): ?>
                    <div class="banner banner-error" role="alert"><?= $err_icon ?><div><?= e($errors['csrf']) ?></div></div>
                <?php endif; ?>
                <?php if ($summary_errors): ?>
                    <div class="reg-summary" id="regSummary" role="alert" tabindex="-1">
                        <strong>Please fix <?= count($summary_errors) === 1 ? 'this item' : 'these ' . count($summary_errors) . ' items' ?> to continue:</strong>
                        <ul>
                            <?php foreach ($summary_errors as $key => $msg): ?>
                                <li><a href="#<?= e($field_anchors[$key]) ?>"><?= e($msg) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/auth/register.php" method="POST" id="registerForm" class="reg-body">
            <?= csrf_field() ?>

            <!-- 1. Personal details -->
            <section class="reg-section" aria-labelledby="sec-personal">
                <div class="reg-section-head">
                    <span class="reg-step" aria-hidden="true">1</span>
                    <div>
                        <h3 id="sec-personal">Personal details</h3>
                        <p>Use your full legal name as it appears on the official roster.</p>
                    </div>
                </div>

                <div class="reg-grid">
                    <div class="reg-field reg-col-2">
                        <label class="reg-label" for="first_name">First name <span class="reg-req" aria-hidden="true">*</span></label>
                        <input type="text" id="first_name" name="first_name" autocomplete="given-name"
                               class="form-control <?= !empty($errors['first_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($first_name) ?>" required>
                        <?php if (!empty($errors['first_name'])): ?><span class="field-error"><?= e($errors['first_name']) ?></span><?php endif; ?>
                    </div>

                    <div class="reg-field reg-col-2">
                        <label class="reg-label" for="middle_name">Middle name <span class="reg-opt">Optional</span></label>
                        <input type="text" id="middle_name" name="middle_name" autocomplete="additional-name"
                               class="form-control" value="<?= e($middle_name) ?>">
                    </div>

                    <div class="reg-field reg-col-2">
                        <label class="reg-label" for="last_name">Last name <span class="reg-req" aria-hidden="true">*</span></label>
                        <input type="text" id="last_name" name="last_name" autocomplete="family-name"
                               class="form-control <?= !empty($errors['last_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($last_name) ?>" required>
                        <?php if (!empty($errors['last_name'])): ?><span class="field-error"><?= e($errors['last_name']) ?></span><?php endif; ?>
                    </div>

                    <div class="reg-field reg-col-3">
                        <label class="reg-label" for="student_number">Student number <span class="reg-req" aria-hidden="true">*</span></label>
                        <span class="reg-hint">The Admin uses this to verify you against the roster.</span>
                        <input type="text" id="student_number" name="student_number" inputmode="numeric" autocomplete="off"
                               class="form-control <?= !empty($errors['student_number']) ? 'is-invalid' : '' ?>"
                               value="<?= e($student_number) ?>" required>
                        <?php if (!empty($errors['student_number'])): ?><span class="field-error"><?= e($errors['student_number']) ?></span><?php endif; ?>
                    </div>

                    <div class="reg-field reg-col-3">
                        <label class="reg-label" for="email">Email address <span class="reg-req" aria-hidden="true">*</span></label>
                        <span class="reg-hint">We will use this for account updates.</span>
                        <input type="email" id="email" name="email" autocomplete="email" placeholder="name@example.com"
                               class="form-control <?= !empty($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= e($email) ?>" required>
                        <?php if (!empty($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- 2. Password -->
            <section class="reg-section" aria-labelledby="sec-account">
                <div class="reg-section-head">
                    <span class="reg-step" aria-hidden="true">2</span>
                    <div>
                        <h3 id="sec-account">Secure your account</h3>
                        <p>Choose a strong password.</p>
                    </div>
                </div>

                <div class="reg-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span>Your username is generated automatically after you submit. You will see it on the next screen, so keep it safe.</span>
                </div>

                <div class="reg-grid">
                    <div class="reg-field reg-col-3">
                        <label class="reg-label" for="password">Password <span class="reg-req" aria-hidden="true">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" autocomplete="new-password"
                                   class="form-control <?= !empty($errors['password']) ? 'is-invalid' : '' ?>" required>
                            <?= $eye_btn('password', 'Show or hide password') ?>
                        </div>
                        <?php if (!empty($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>

                        <div class="reg-meter" id="pw_meter" data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                        <div class="reg-meter-label" id="pw_meter_label" aria-live="polite"></div>
                    </div>

                    <div class="reg-field reg-col-3">
                        <label class="reg-label" for="confirm_password">Confirm password <span class="reg-req" aria-hidden="true">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
                                   class="form-control <?= !empty($errors['confirm_password']) ? 'is-invalid' : '' ?>" required>
                            <?= $eye_btn('confirm_password', 'Show or hide confirm password') ?>
                        </div>
                        <?php if (!empty($errors['confirm_password'])): ?><span class="field-error"><?= e($errors['confirm_password']) ?></span><?php endif; ?>
                        <div class="reg-match" id="confirm_hint" aria-live="polite"></div>
                    </div>

                    <div class="reg-field reg-col-6">
                        <ul class="reg-rules" id="pw_rules" aria-label="Password requirements">
                            <li data-rule="length">At least 10 characters</li>
                            <li data-rule="upper">One uppercase letter</li>
                            <li data-rule="lower">One lowercase letter</li>
                            <li data-rule="number">One number</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 3. Role -->
            <section class="reg-section" aria-labelledby="sec-role">
                <div class="reg-section-head">
                    <span class="reg-step" aria-hidden="true">3</span>
                    <div>
                        <h3 id="sec-role">Your role</h3>
                        <p>Pick the position you hold in the unit.</p>
                    </div>
                </div>

                <div class="reg-roles <?= !empty($errors['role']) ? 'is-invalid' : '' ?>" id="role_group" role="radiogroup" aria-labelledby="sec-role" tabindex="-1">
                    <?php foreach ($role_cards as $value => [$title, $desc, $icon]): ?>
                        <label class="reg-role">
                            <input type="radio" name="role" value="<?= e($value) ?>" <?= $role === $value ? 'checked' : '' ?> required>
                            <span class="reg-role-body">
                                <svg class="reg-role-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg>
                                <span class="reg-role-title"><?= e($title) ?></span>
                                <span class="reg-role-desc"><?= e($desc) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($errors['role'])): ?><span class="field-error"><?= e($errors['role']) ?></span><?php endif; ?>

                <!-- Role-specific fields (JS shows only the one that applies) -->
                <div id="group_program" class="reg-group <?= $role === 'class_president' ? 'is-visible' : '' ?>" data-role-group>
                    <div class="reg-group-box reg-field">
                        <label class="reg-label" for="program_id">Program <span class="reg-req" aria-hidden="true">*</span></label>
                        <span class="reg-hint">Required for Class President.</span>
                        <select id="program_id" name="program_id" class="form-control <?= !empty($errors['program_id']) ? 'is-invalid' : '' ?>">
                            <option value="">Select a program</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?= (int)$prog['id'] ?>" <?= (string)$program_id === (string)$prog['id'] ? 'selected' : '' ?>>
                                    <?= e($prog['code']) ?> - <?= e($prog['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['program_id'])): ?><span class="field-error"><?= e($errors['program_id']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div id="group_platoon" class="reg-group <?= $role === 'platoon_leader' ? 'is-visible' : '' ?>" data-role-group>
                    <div class="reg-group-box reg-field">
                        <label class="reg-label" for="platoon_option">Company and platoon <span class="reg-req" aria-hidden="true">*</span></label>
                        <span class="reg-hint">Required for Platoon Leader.</span>
                        <select id="platoon_option" name="platoon_option" class="form-control <?= !empty($errors['platoon_option']) ? 'is-invalid' : '' ?>">
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
                        <?php if (!empty($errors['platoon_option'])): ?><span class="field-error"><?= e($errors['platoon_option']) ?></span><?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- 4. Consent + submit -->
            <section class="reg-section" aria-labelledby="sec-consent">
                <div class="reg-section-head">
                    <span class="reg-step" aria-hidden="true">4</span>
                    <div>
                        <h3 id="sec-consent">Consent</h3>
                        <p>Required to process your registration.</p>
                    </div>
                </div>

                <div class="reg-consent <?= !empty($errors['consent']) ? 'is-invalid' : '' ?>">
                    <input type="checkbox" id="consent" name="consent" value="1" <?= $consent ? 'checked' : '' ?> required>
                    <label for="consent">
                        I agree to the Data Privacy Notice and consent to the collection and processing of my personal details for ROTC management in compliance with RA 10173. <span class="reg-req">*</span>
                    </label>
                </div>
                <?php if (!empty($errors['consent'])): ?><span class="field-error"><?= e($errors['consent']) ?></span><?php endif; ?>

                <div class="reg-actions">
                    <button type="submit" id="registerSubmit" class="btn btn-primary btn-block reg-submit">Submit Registration</button>
                </div>

                <p class="reg-login">Already have an account? <a href="<?= BASE_URL ?>/auth/login.php"><strong>Log in</strong></a></p>
            </section>
        </form>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/register.js?v=2"></script>
    <script src="<?= BASE_URL ?>/assets/js/ui.js"></script>
</body>
</html>