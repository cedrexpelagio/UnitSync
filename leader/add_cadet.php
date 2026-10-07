<?php
// Platoon Leader: Add Cadet (Stage PL-3)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('platoon_leader');

$user = current_user();
$errors = [];

// 1. Fetch assignment details for this Platoon Leader
$stmt = $pdo->prepare("
    SELECT ua.company_id, ua.platoon_id, c.name AS company_name, p.name AS platoon_name 
    FROM user_assignments ua 
    LEFT JOIN companies c ON ua.company_id = c.id 
    LEFT JOIN platoons p ON ua.platoon_id = p.id 
    WHERE ua.user_id = ?
");
$stmt->execute([$user['id']]);
$assignment = $stmt->fetch();

$company_id = $assignment['company_id'] ?? null;
$platoon_id = $assignment['platoon_id'] ?? null;
$company_name = $assignment['company_name'] ?? 'Unassigned';
$platoon_name = $assignment['platoon_name'] ?? 'Unassigned';

// 2. Fetch the current active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

// 3. Fetch academic programs list
$stmt = $pdo->query("SELECT id, code, name FROM programs ORDER BY code ASC");
$programs = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('leader/add_cadet.php');
    }

    if (!$active_term) {
        set_flash('error', 'Cannot add cadet: There is no active academic term configured.');
        redirect('leader/add_cadet.php');
    }

    if (!$platoon_id || !$company_id) {
        set_flash('error', 'Cannot add cadet: Your account is not currently assigned to a company and platoon.');
        redirect('leader/add_cadet.php');
    }

    $last_name = trim($_POST['last_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $program_id = (int)($_POST['program_id'] ?? 0);
    $student_number = trim($_POST['student_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');

    // Validation
    if ($last_name === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($last_name) > 100) {
        $errors['last_name'] = 'Last name must not exceed 100 characters.';
    }

    if ($first_name === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($first_name) > 100) {
        $errors['first_name'] = 'First name must not exceed 100 characters.';
    }

    if ($middle_name !== '' && mb_strlen($middle_name) > 100) {
        $errors['middle_name'] = 'Middle name must not exceed 100 characters.';
    }

    if ($birthday === '') {
        $errors['birthday'] = 'Birthday is required.';
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $birthday);
        if (!$d || $d->format('Y-m-d') !== $birthday) {
            $errors['birthday'] = 'Invalid date format (must be YYYY-MM-DD).';
        } elseif ($birthday > date('Y-m-d')) {
            $errors['birthday'] = 'Birthday cannot be a future date.';
        }
    }

    if (!in_array($gender, ['Male', 'Female'], true)) {
        $errors['gender'] = 'Please select a valid gender (Male or Female).';
    }

    if ($program_id <= 0) {
        $errors['program_id'] = 'Please select an academic program.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM programs WHERE id = ?");
        $stmt->execute([$program_id]);
        if (!$stmt->fetch()) {
            $errors['program_id'] = 'Selected academic program does not exist.';
        }
    }

    if ($student_number === '') {
        $errors['student_number'] = 'Student number is required.';
    } elseif (mb_strlen($student_number) > 50) {
        $errors['student_number'] = 'Student number must not exceed 50 characters.';
    } else {
        // Check uniqueness in cadets table
        $stmt = $pdo->prepare("SELECT id FROM cadets WHERE student_number = ? LIMIT 1");
        $stmt->execute([$student_number]);
        if ($stmt->fetch()) {
            $errors['student_number'] = 'This student number is already registered to an existing cadet.';
        }
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please provide a valid email address.';
    } elseif (mb_strlen($email) > 150) {
        $errors['email'] = 'Email must not exceed 150 characters.';
    } else {
        // Check uniqueness in cadets table
        $stmt = $pdo->prepare("SELECT id FROM cadets WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This email address is already in use by another cadet.';
        }
    }

    if ($contact_number !== '' && mb_strlen($contact_number) > 50) {
        $errors['contact_number'] = 'Contact number must not exceed 50 characters.';
    }

    // If no errors, process insertion
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Generate sequential code (e.g. CDT-2026-0001)
            $cadet_code = next_cadet_code($pdo);

            // 1. Insert into cadets table
            $stmt = $pdo->prepare("
                INSERT INTO cadets (
                    cadet_code, last_name, first_name, middle_name, birthday,
                    gender, program_id, student_number, email, contact_number,
                    status, created_by, created_at, updated_at
                ) VALUES (
                    :cadet_code, :last_name, :first_name, :middle_name, :birthday,
                    :gender, :program_id, :student_number, :email, :contact_number,
                    'active', :created_by, NOW(), NOW()
                )
            ");
            $stmt->execute([
                'cadet_code'     => $cadet_code,
                'last_name'      => $last_name,
                'first_name'     => $first_name,
                'middle_name'    => $middle_name !== '' ? $middle_name : null,
                'birthday'       => $birthday,
                'gender'         => $gender,
                'program_id'     => $program_id,
                'student_number' => $student_number,
                'email'          => $email,
                'contact_number' => $contact_number !== '' ? $contact_number : null,
                'created_by'     => $user['id']
            ]);
            $cadet_id = (int)$pdo->lastInsertId();

            // 2. Create enrollment record for the active term
            $stmt = $pdo->prepare("
                INSERT INTO enrollments (
                    cadet_id, term_id, company_id, platoon_id, created_at, updated_at
                ) VALUES (
                    :cadet_id, :term_id, :company_id, :platoon_id, NOW(), NOW()
                )
            ");
            $stmt->execute([
                'cadet_id'   => $cadet_id,
                'term_id'    => (int)$active_term['id'],
                'company_id' => $company_id,
                'platoon_id' => $platoon_id
            ]);

            // 3. Write to audit log
            log_audit(
                $pdo,
                $user['id'],
                'create_cadet',
                'cadet',
                $cadet_id,
                json_encode([
                    'cadet_code'     => $cadet_code,
                    'name'           => "{$last_name}, {$first_name}",
                    'student_number' => $student_number,
                    'company_id'     => $company_id,
                    'platoon_id'     => $platoon_id,
                    'term_id'        => (int)$active_term['id']
                ])
            );

            $pdo->commit();

            set_flash('success', "Cadet <strong>" . e($first_name . ' ' . $last_name) . "</strong> enrolled successfully! Generated Cadet Code: <strong>" . e($cadet_code) . "</strong>");
            redirect('leader/add_cadet.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Failed to save cadet: ' . $e->getMessage());
            redirect('leader/add_cadet.php');
        }
    }
}

$page_title = 'Add Cadet';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Add New Cadet</h1>
    <p>Enroll a new ROTC cadet into your platoon for the active academic term.</p>
</div>

<!-- Active Term & Unit Assignment Banner -->
<?php if (!$active_term): ?>
    <div class="banner banner-error">
        <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
            <strong>Notice:</strong> There is no active academic term configured in the system. An Administrator must create and activate a term before cadets can be enrolled.
        </div>
    </div>
<?php elseif (!$platoon_id || !$company_id): ?>
    <div class="banner banner-warning">
        <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div>
            <strong>Unit Assignment Missing:</strong> Your account is not currently assigned to a company and platoon. Please contact an Administrator to update your assignment.
        </div>
    </div>
<?php else: ?>
    <div class="banner banner-info" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <div>
            <strong>Unit:</strong> Company <?= e($company_name) ?> &mdash; <?= e($platoon_name) ?>
            <span style="margin: 0 8px; color: var(--gray-300);">|</span>
            <strong>Active Term:</strong> <?= e($active_term['name']) ?>
            <p style="font-size: 12px; margin-top: 4px; color: var(--gray-700);">Cadets will automatically be assigned to your platoon for this term.</p>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 820px;">
    <h2 class="card-title">Cadet Registration Form</h2>

    <form method="POST" action="<?= BASE_URL ?>/leader/add_cadet.php" id="add-cadet-form" novalidate>
        <?= csrf_field() ?>

        <!-- Unit Assignment Read-Only Display -->
        <div class="form-row" style="background-color: var(--gray-50); border: 1px solid var(--gray-300); border-radius: var(--radius-default); padding: 12px 16px; margin-bottom: 20px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 12px; color: var(--gray-700);">Assigned Company</label>
                <div style="font-weight: 600; color: var(--green-900);"><?= e($company_name) ?></div>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 12px; color: var(--gray-700);">Assigned Platoon</label>
                <div style="font-weight: 600; color: var(--green-900);"><?= e($platoon_name) ?></div>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 12px; color: var(--gray-700);">Enrollment Term</label>
                <div style="font-weight: 600; color: var(--green-900);"><?= e($active_term['name'] ?? 'None') ?></div>
            </div>
        </div>

        <!-- Name Fields (3 columns on desktop) -->
        <div class="form-row">
            <div class="form-group">
                <label for="last_name">Last Name <span style="color: var(--error);">*</span></label>
                <input type="text" id="last_name" name="last_name" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['last_name'] ?? '') ?>" required>
                <?php if (isset($errors['last_name'])): ?><span class="field-error"><?= e($errors['last_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="first_name">First Name <span style="color: var(--error);">*</span></label>
                <input type="text" id="first_name" name="first_name" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['first_name'] ?? '') ?>" required>
                <?php if (isset($errors['first_name'])): ?><span class="field-error"><?= e($errors['first_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="middle_name">Middle Name</label>
                <input type="text" id="middle_name" name="middle_name" class="form-control <?= isset($errors['middle_name']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['middle_name'] ?? '') ?>" placeholder="Optional">
                <?php if (isset($errors['middle_name'])): ?><span class="field-error"><?= e($errors['middle_name']) ?></span><?php endif; ?>
            </div>
        </div>

        <!-- Demographics & Academic Program -->
        <div class="form-row">
            <div class="form-group">
                <label for="birthday">Birthday <span style="color: var(--error);">*</span></label>
                <input type="date" id="birthday" name="birthday" max="<?= date('Y-m-d') ?>" class="form-control <?= isset($errors['birthday']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['birthday'] ?? '') ?>" required>
                <?php if (isset($errors['birthday'])): ?><span class="field-error"><?= e($errors['birthday']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="gender">Gender <span style="color: var(--error);">*</span></label>
                <select id="gender" name="gender" class="form-control <?= isset($errors['gender']) ? 'is-invalid' : '' ?>" required>
                    <option value="">-- Select Gender --</option>
                    <option value="Male" <?= (($_POST['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                </select>
                <?php if (isset($errors['gender'])): ?><span class="field-error"><?= e($errors['gender']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="program_id">Academic Program <span style="color: var(--error);">*</span></label>
                <select id="program_id" name="program_id" class="form-control <?= isset($errors['program_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">-- Select Program --</option>
                    <?php foreach ($programs as $prog): ?>
                        <option value="<?= (int)$prog['id'] ?>" <?= ((int)($_POST['program_id'] ?? 0) === (int)$prog['id']) ? 'selected' : '' ?>>
                            <?= e($prog['code']) ?> &mdash; <?= e($prog['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['program_id'])): ?><span class="field-error"><?= e($errors['program_id']) ?></span><?php endif; ?>
            </div>
        </div>

        <!-- Student ID, Email & Contact -->
        <div class="form-row">
            <div class="form-group">
                <label for="student_number">Student Number <span style="color: var(--error);">*</span></label>
                <input type="text" id="student_number" name="student_number" class="form-control <?= isset($errors['student_number']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['student_number'] ?? '') ?>" placeholder="e.g. 2024-00123" required>
                <span class="field-hint">Must be unique across all cadets.</span>
                <?php if (isset($errors['student_number'])): ?><span class="field-error"><?= e($errors['student_number']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email">Email Address <span style="color: var(--error);">*</span></label>
                <input type="email" id="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['email'] ?? '') ?>" placeholder="cadet@university.edu" required>
                <span class="field-hint">Must be unique across all cadets.</span>
                <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="contact_number">Contact Number</label>
                <input type="tel" id="contact_number" name="contact_number" class="form-control <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['contact_number'] ?? '') ?>" placeholder="e.g. 09123456789">
                <?php if (isset($errors['contact_number'])): ?><span class="field-error"><?= e($errors['contact_number']) ?></span><?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="btn btn-primary" <?= (!$active_term || !$platoon_id) ? 'disabled' : '' ?>>
                Enroll Cadet
            </button>
            <a href="<?= BASE_URL ?>/leader/dashboard.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<!-- Inline Client Validation Script (Step C: Progressive Enhancement) -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('add-cadet-form');
    if (!form) return;

    form.addEventListener('submit', (e) => {
        let hasClientError = false;
        
        // Helper to set field error
        function setErr(fieldId, msg) {
            const field = document.getElementById(fieldId);
            if (!field) return;
            field.classList.add('is-invalid');
            let err = field.parentElement.querySelector('.field-error');
            if (!err) {
                err = document.createElement('span');
                err.className = 'field-error';
                field.parentElement.appendChild(err);
            }
            err.textContent = msg;
            hasClientError = true;
        }

        // Helper to clear field error
        function clearErr(fieldId) {
            const field = document.getElementById(fieldId);
            if (!field) return;
            field.classList.remove('is-invalid');
            const err = field.parentElement.querySelector('.field-error');
            if (err) err.remove();
        }

        ['last_name', 'first_name', 'birthday', 'gender', 'program_id', 'student_number', 'email'].forEach(clearErr);

        const lastName = document.getElementById('last_name').value.trim();
        const firstName = document.getElementById('first_name').value.trim();
        const birthday = document.getElementById('birthday').value.trim();
        const gender = document.getElementById('gender').value;
        const programId = document.getElementById('program_id').value;
        const studentNumber = document.getElementById('student_number').value.trim();
        const email = document.getElementById('email').value.trim();

        if (!lastName) setErr('last_name', 'Last name is required.');
        if (!firstName) setErr('first_name', 'First name is required.');
        if (!birthday) {
            setErr('birthday', 'Birthday is required.');
        } else {
            const today = new Date().toISOString().split('T')[0];
            if (birthday > today) setErr('birthday', 'Birthday cannot be a future date.');
        }
        if (!gender) setErr('gender', 'Please select a gender.');
        if (!programId) setErr('program_id', 'Please select an academic program.');
        if (!studentNumber) setErr('student_number', 'Student number is required.');
        if (!email) {
            setErr('email', 'Email address is required.');
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setErr('email', 'Please provide a valid email address.');
        }

        if (hasClientError) {
            e.preventDefault();
            const firstInvalid = form.querySelector('.is-invalid');
            if (firstInvalid) firstInvalid.focus();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
