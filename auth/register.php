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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - UnitSync</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-brand">
        <div class="logo-title">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--green-700);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>UnitSync</span>
        </div>
    </div>

    <div class="auth-card" style="max-width: 540px;">
        <header>
            <h2>Create an Account</h2>
            <p>Register as an ROTC unit leader or officer. Admin approval is required before sign in.</p>
        </header>

        <?php if (!empty($errors['general'])): ?>
            <div class="banner banner-error" role="alert">
                <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div><?= e($errors['general']) ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors['csrf'])): ?>
            <div class="banner banner-error" role="alert">
                <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div><?= e($errors['csrf']) ?></div>
            </div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/auth/register.php" method="POST">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="first_name">First Name *</label>
                <input type="text" id="first_name" name="first_name" class="form-control <?= !empty($errors['first_name']) ? 'is-invalid' : '' ?>" value="<?= e($first_name) ?>" required>
                <?php if (!empty($errors['first_name'])): ?>
                    <span class="field-error"><?= e($errors['first_name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="middle_name">Middle Name</label>
                <input type="text" id="middle_name" name="middle_name" class="form-control" value="<?= e($middle_name) ?>">
            </div>

            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" id="last_name" name="last_name" class="form-control <?= !empty($errors['last_name']) ? 'is-invalid' : '' ?>" value="<?= e($last_name) ?>" required>
                <?php if (!empty($errors['last_name'])): ?>
                    <span class="field-error"><?= e($errors['last_name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="student_number">Student Number *</label>
                <span class="field-hint">Used by the Admin to verify your identity against the official roster</span>
                <input type="text" id="student_number" name="student_number" class="form-control <?= !empty($errors['student_number']) ? 'is-invalid' : '' ?>" value="<?= e($student_number) ?>" required>
                <?php if (!empty($errors['student_number'])): ?>
                    <span class="field-error"><?= e($errors['student_number']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" class="form-control <?= !empty($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($email) ?>" required>
                <?php if (!empty($errors['email'])): ?>
                    <span class="field-error"><?= e($errors['email']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <span class="field-hint">Minimum 10 characters with uppercase, lowercase, and numbers</span>
                <input type="password" id="password" name="password" class="form-control <?= !empty($errors['password']) ? 'is-invalid' : '' ?>" required>
                <?php if (!empty($errors['password'])): ?>
                    <span class="field-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control <?= !empty($errors['confirm_password']) ? 'is-invalid' : '' ?>" required>
                <?php if (!empty($errors['confirm_password'])): ?>
                    <span class="field-error"><?= e($errors['confirm_password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="role">Role *</label>
                <select id="role" name="role" class="form-control <?= !empty($errors['role']) ? 'is-invalid' : '' ?>" required>
                    <option value="">-- Select Role --</option>
                    <option value="class_president" <?= $role === 'class_president' ? 'selected' : '' ?>>Class President</option>
                    <option value="platoon_leader" <?= $role === 'platoon_leader' ? 'selected' : '' ?>>Platoon Leader</option>
                    <option value="battalion_s1" <?= $role === 'battalion_s1' ? 'selected' : '' ?>>Battalion S1</option>
                    <option value="brigade_s1" <?= $role === 'brigade_s1' ? 'selected' : '' ?>>Brigade S1</option>
                </select>
                <?php if (!empty($errors['role'])): ?>
                    <span class="field-error"><?= e($errors['role']) ?></span>
                <?php endif; ?>
            </div>

            <!-- Role-specific fields -->
            <div id="group_program" class="form-group">
                <label for="program_id">Program (Required for Class President)</label>
                <select id="program_id" name="program_id" class="form-control <?= !empty($errors['program_id']) ? 'is-invalid' : '' ?>">
                    <option value="">-- Select Program --</option>
                    <?php foreach ($programs as $prog): ?>
                        <option value="<?= (int)$prog['id'] ?>" <?= (string)$program_id === (string)$prog['id'] ? 'selected' : '' ?>>
                            <?= e($prog['code']) ?> - <?= e($prog['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['program_id'])): ?>
                    <span class="field-error"><?= e($errors['program_id']) ?></span>
                <?php endif; ?>
            </div>

            <div id="group_platoon" class="form-group">
                <label for="platoon_option">Company & Platoon (Required for Platoon Leader)</label>
                <select id="platoon_option" name="platoon_option" class="form-control <?= !empty($errors['platoon_option']) ? 'is-invalid' : '' ?>">
                    <option value="">-- Select Company & Platoon --</option>
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
                <?php if (!empty($errors['platoon_option'])): ?>
                    <span class="field-error"><?= e($errors['platoon_option']) ?></span>
                <?php endif; ?>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="consent" name="consent" value="1" <?= $consent ? 'checked' : '' ?> required>
                <label for="consent">
                    I agree to the Data Privacy Notice and consent to the collection and processing of my personal details for ROTC management in compliance with RA 10173. *
                </label>
            </div>
            <?php if (!empty($errors['consent'])): ?>
                <span class="field-error" style="margin-top: -12px; margin-bottom: 16px;"><?= e($errors['consent']) ?></span>
            <?php endif; ?>

            <div style="margin-top: 24px; margin-bottom: 20px;">
                <button type="submit" class="btn btn-primary btn-block">Submit Registration</button>
            </div>
        </form>

        <div style="text-align: center; font-size: 13px;">
            <p>Already have an account? <a href="<?= BASE_URL ?>/auth/login.php"><strong>Log In</strong></a></p>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/register.js"></script>
</body>
</html>
