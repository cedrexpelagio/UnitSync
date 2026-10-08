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

// Whether this account is able to enroll cadets right now
$can_enroll = $active_term && $platoon_id && $company_id;

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

            // Plain-text message (no HTML tags) so it displays correctly whether or not
            // the flash renderer escapes its output.
            set_flash('success', 'Cadet ' . e($first_name . ' ' . $last_name) . ' was enrolled successfully. Cadet Code: ' . e($cadet_code));
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

// Field labels used by the error summary
$field_labels = [
    'last_name'      => 'Last name',
    'first_name'     => 'First name',
    'middle_name'    => 'Middle name',
    'birthday'       => 'Birthday',
    'gender'         => 'Gender',
    'program_id'     => 'Academic program',
    'student_number' => 'Student number',
    'email'          => 'Email address',
    'contact_number' => 'Contact number',
];

// Small helper: renders the error message under a field
$field_error = function (string $name) use ($errors): string {
    if (!isset($errors[$name])) {
        return '';
    }
    return '<span class="field-error" id="' . e($name) . '-error" role="alert">' . e($errors[$name]) . '</span>';
};

// Small helper: attributes shared by invalid inputs
$invalid_attrs = function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"'
        : '';
};

$page_title = 'Add Cadet';
// Grab flash messages BEFORE header.php's show_flash() consumes them.
// We render them as persistent banners (not toasts) inside the page body.
$page_banner_flashes = get_flashes();
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Page-specific stylesheet (adjust the path if your CSS folder differs) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/add_cadet.css">

<div class="ac-page">

    <div class="content-header">
        <h1>Add New Cadet</h1>
        <p>Enroll a new ROTC cadet into your platoon for the active academic term.</p>
    </div>

    <?php if (!empty($page_banner_flashes)): ?>
    <div class="flash-container">
        <?php foreach ($page_banner_flashes as $flash):
            $ft = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
            $fm = $flash['message']; // HTML allowed (e.g. <strong>)
        ?>
        <div class="flash-message flash-<?= $ft ?>" data-persist="true" role="alert"><?= $fm ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Status banners -->
    <?php if (!$active_term): ?>
        <div class="banner banner-error" role="alert">
            <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
                <strong>No active term:</strong> An Administrator must create and activate an academic term before cadets can be enrolled.
            </div>
        </div>
    <?php elseif (!$platoon_id || !$company_id): ?>
        <div class="banner banner-warning" role="alert">
            <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div>
                <strong>Unit assignment missing:</strong> Your account is not assigned to a company and platoon. Please contact an Administrator to update your assignment.
            </div>
        </div>
    <?php endif; ?>

    <!-- Unit & term summary -->
    <div class="ac-summary" aria-label="Enrollment details">
        <div class="ac-summary-item">
            <span class="ac-summary-label">Company</span>
            <span class="ac-summary-value <?= $company_id ? '' : 'is-missing' ?>"><?= e($company_name) ?></span>
        </div>
        <div class="ac-summary-item">
            <span class="ac-summary-label">Platoon</span>
            <span class="ac-summary-value <?= $platoon_id ? '' : 'is-missing' ?>"><?= e($platoon_name) ?></span>
        </div>
        <div class="ac-summary-item">
            <span class="ac-summary-label">Enrollment Term</span>
            <span class="ac-summary-value <?= $active_term ? '' : 'is-missing' ?>"><?= e($active_term['name'] ?? 'None active') ?></span>
        </div>
    </div>

    <!-- Server-side error summary -->
    <?php if (!empty($errors)): ?>
        <div class="ac-error-summary" id="error-summary" role="alert" tabindex="-1">
            <strong>Please fix the following <?= count($errors) === 1 ? 'issue' : count($errors) . ' issues' ?> before enrolling:</strong>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><a href="#<?= e($field === 'gender' ? 'gender_male' : $field) ?>"><?= e($field_labels[$field] ?? $field) ?></a>: <?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/leader/add_cadet.php" id="add-cadet-form" novalidate>
        <?= csrf_field() ?>

        <div class="ac-card <?= $can_enroll ? '' : 'is-locked' ?>">
            <div class="ac-card-head">
                <h2>Cadet Registration Form</h2>
                <p>Fields marked <span class="ac-required-note">*</span> are required. The cadet is automatically placed in your platoon.</p>
            </div>

            <!-- 1. Personal information -->
            <fieldset class="ac-section" <?= $can_enroll ? '' : 'disabled' ?>>
                <legend><span class="ac-step">1</span> Personal Information</legend>

                <div class="ac-grid">
                    <div class="ac-field ac-col-2">
                        <label for="last_name">Last Name <span class="ac-req" aria-hidden="true">*</span></label>
                        <input type="text" id="last_name" name="last_name"
                               class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['last_name'] ?? '') ?>"
                               autocomplete="family-name" maxlength="100" required
                               <?= $invalid_attrs('last_name') ?>>
                        <?= $field_error('last_name') ?>
                    </div>

                    <div class="ac-field ac-col-2">
                        <label for="first_name">First Name <span class="ac-req" aria-hidden="true">*</span></label>
                        <input type="text" id="first_name" name="first_name"
                               class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['first_name'] ?? '') ?>"
                               autocomplete="given-name" maxlength="100" required
                               <?= $invalid_attrs('first_name') ?>>
                        <?= $field_error('first_name') ?>
                    </div>

                    <div class="ac-field ac-col-2">
                        <label for="middle_name">Middle Name <span class="ac-optional">(optional)</span></label>
                        <input type="text" id="middle_name" name="middle_name"
                               class="form-control <?= isset($errors['middle_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['middle_name'] ?? '') ?>"
                               autocomplete="additional-name" maxlength="100"
                               <?= $invalid_attrs('middle_name') ?>>
                        <?= $field_error('middle_name') ?>
                    </div>

                    <div class="ac-field ac-col-3">
                        <label for="birthday">Birthday <span class="ac-req" aria-hidden="true">*</span></label>
                        <input type="date" id="birthday" name="birthday"
                               max="<?= date('Y-m-d') ?>"
                               class="form-control <?= isset($errors['birthday']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['birthday'] ?? '') ?>"
                               autocomplete="bday" required
                               <?= $invalid_attrs('birthday') ?>>
                        <?= $field_error('birthday') ?>
                    </div>

                    <div class="ac-field ac-col-3">
                        <label id="gender-label">Gender <span class="ac-req" aria-hidden="true">*</span></label>
                        <div class="ac-segmented <?= isset($errors['gender']) ? 'is-invalid' : '' ?>" role="radiogroup" aria-labelledby="gender-label" id="gender-group">
                            <input type="radio" id="gender_male" name="gender" value="Male"
                                   <?= (($_POST['gender'] ?? '') === 'Male') ? 'checked' : '' ?> required>
                            <label for="gender_male">Male</label>

                            <input type="radio" id="gender_female" name="gender" value="Female"
                                   <?= (($_POST['gender'] ?? '') === 'Female') ? 'checked' : '' ?>>
                            <label for="gender_female">Female</label>
                        </div>
                        <?= $field_error('gender') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 2. Academic information -->
            <fieldset class="ac-section" <?= $can_enroll ? '' : 'disabled' ?>>
                <legend><span class="ac-step">2</span> Academic Information</legend>

                <div class="ac-grid">
                    <div class="ac-field ac-col-3">
                        <label for="student_number">Student Number <span class="ac-req" aria-hidden="true">*</span></label>
                        <input type="text" id="student_number" name="student_number"
                               class="form-control <?= isset($errors['student_number']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['student_number'] ?? '') ?>"
                               placeholder="e.g. 2024-00123" maxlength="50"
                               autocomplete="off" required
                               aria-describedby="student_number-hint<?= isset($errors['student_number']) ? ' student_number-error' : '' ?>"
                               <?= isset($errors['student_number']) ? 'aria-invalid="true"' : '' ?>>
                        <span class="ac-hint" id="student_number-hint">Must be unique across all cadets.</span>
                        <?= $field_error('student_number') ?>
                    </div>

                    <div class="ac-field ac-col-3">
                        <label for="program_id">Academic Program <span class="ac-req" aria-hidden="true">*</span></label>
                        <select id="program_id" name="program_id"
                                class="form-control <?= isset($errors['program_id']) ? 'is-invalid' : '' ?>" required
                                <?= $invalid_attrs('program_id') ?>>
                            <option value="">Select a program&hellip;</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?= (int)$prog['id'] ?>" <?= ((int)($_POST['program_id'] ?? 0) === (int)$prog['id']) ? 'selected' : '' ?>>
                                    <?= e($prog['code']) ?> &mdash; <?= e($prog['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $field_error('program_id') ?>
                    </div>
                </div>
            </fieldset>

            <!-- 3. Contact information -->
            <fieldset class="ac-section" <?= $can_enroll ? '' : 'disabled' ?>>
                <legend><span class="ac-step">3</span> Contact Information</legend>

                <div class="ac-grid">
                    <div class="ac-field ac-col-3">
                        <label for="email">Email Address <span class="ac-req" aria-hidden="true">*</span></label>
                        <input type="email" id="email" name="email"
                               class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['email'] ?? '') ?>"
                               placeholder="cadet@university.edu" maxlength="150"
                               autocomplete="email" inputmode="email" required
                               aria-describedby="email-hint<?= isset($errors['email']) ? ' email-error' : '' ?>"
                               <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
                        <span class="ac-hint" id="email-hint">Must be unique across all cadets.</span>
                        <?= $field_error('email') ?>
                    </div>

                    <div class="ac-field ac-col-3">
                        <label for="contact_number">Contact Number <span class="ac-optional">(optional)</span></label>
                        <input type="tel" id="contact_number" name="contact_number"
                               class="form-control <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>"
                               value="<?= e($_POST['contact_number'] ?? '') ?>"
                               placeholder="e.g. 09123456789" maxlength="50"
                               autocomplete="tel" inputmode="tel"
                               <?= $invalid_attrs('contact_number') ?>>
                        <?= $field_error('contact_number') ?>
                    </div>
                </div>
            </fieldset>

            <!-- Actions -->
            <div class="ac-actions">
                <a href="<?= BASE_URL ?>/leader/dashboard.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" id="enroll-cadet-btn" <?= $can_enroll ? '' : 'disabled' ?>>
                    <span class="ac-spinner" aria-hidden="true"></span>
                    <span class="ac-btn-label">Enroll Cadet</span>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Client-side validation (progressive enhancement; server validation remains authoritative) -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('add-cadet-form');
    if (!form) return;

    const submitBtn = document.getElementById('enroll-cadet-btn');
    const summary = document.getElementById('error-summary');
    if (summary) summary.focus();

    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    // Rules return an error message, or '' when the field is valid
    const rules = {
        last_name:      v => v ? '' : 'Last name is required.',
        first_name:     v => v ? '' : 'First name is required.',
        birthday:       v => {
            if (!v) return 'Birthday is required.';
            return v > new Date().toISOString().split('T')[0] ? 'Birthday cannot be a future date.' : '';
        },
        program_id:     v => v ? '' : 'Please select an academic program.',
        student_number: v => v ? '' : 'Student number is required.',
        email:          v => !v ? 'Email address is required.' : (emailRe.test(v) ? '' : 'Please provide a valid email address.')
    };

    const getValue = id => {
        const el = document.getElementById(id);
        return el ? el.value.trim() : '';
    };

    function clearErr(id) {
        const field = document.getElementById(id);
        if (!field) return;
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        const err = field.parentElement.querySelector('.field-error');
        if (err) err.remove();
    }

    function setErr(id, msg) {
        const field = document.getElementById(id);
        if (!field) return;
        clearErr(id);
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        const err = document.createElement('span');
        err.className = 'field-error';
        err.id = id + '-error';
        err.setAttribute('role', 'alert');
        err.textContent = msg;
        field.parentElement.appendChild(err);
    }

    function validateField(id) {
        const msg = rules[id](getValue(id));
        if (msg) { setErr(id, msg); return false; }
        clearErr(id);
        return true;
    }

    // Gender radio group
    const genderGroup = document.getElementById('gender-group');
    function validateGender() {
        const checked = form.querySelector('input[name="gender"]:checked');
        const wrap = genderGroup.parentElement;
        const old = wrap.querySelector('.field-error');
        if (old) old.remove();
        genderGroup.classList.remove('is-invalid');
        if (!checked) {
            genderGroup.classList.add('is-invalid');
            const err = document.createElement('span');
            err.className = 'field-error';
            err.setAttribute('role', 'alert');
            err.textContent = 'Please select a gender.';
            wrap.appendChild(err);
            return false;
        }
        return true;
    }

    // Validate on blur; clear the error as soon as the user fixes it
    Object.keys(rules).forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', () => { if (el.value !== '' || el.classList.contains('is-invalid')) validateField(id); });
        el.addEventListener('input', () => { if (el.classList.contains('is-invalid')) validateField(id); });
        el.addEventListener('change', () => { if (el.classList.contains('is-invalid')) validateField(id); });
    });
    form.querySelectorAll('input[name="gender"]').forEach(r => r.addEventListener('change', validateGender));

    form.addEventListener('submit', e => {
        let firstInvalidId = null;

        Object.keys(rules).forEach(id => {
            if (!validateField(id) && !firstInvalidId) firstInvalidId = id;
        });
        if (!validateGender() && !firstInvalidId) firstInvalidId = 'gender_male';
        // Keep visual order: gender sits between birthday and program, so re-pick the first visible invalid control
        const firstInvalid = form.querySelector('.is-invalid');

        if (firstInvalidId) {
            e.preventDefault();
            if (typeof showToast === 'function') {
                showToast('Please check the highlighted fields.', 'error');
            }
            const target = firstInvalid && firstInvalid.matches('input, select')
                ? firstInvalid
                : document.getElementById(firstInvalidId);
            if (target) target.focus();
            return;
        }

        // Prevent double submissions
        submitBtn.disabled = true;
        submitBtn.classList.add('is-loading');
        submitBtn.querySelector('.ac-btn-label').textContent = 'Enrolling…';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>