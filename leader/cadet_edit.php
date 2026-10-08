<?php
// Platoon Leader: Edit Cadet (Stage PL-4)
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

$company_id   = $assignment['company_id'] ?? null;
$platoon_id   = $assignment['platoon_id'] ?? null;
$company_name = $assignment['company_name'] ?? 'Unassigned';
$platoon_name = $assignment['platoon_name'] ?? 'Unassigned';

// 2. Fetch current active academic term
$stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt->fetch();

// 3. Read cadet ID from request
$raw_id = $_POST['id'] ?? ($_GET['id'] ?? '');
$cadet_id = (is_string($raw_id) && ctype_digit($raw_id)) ? (int)$raw_id : (is_int($raw_id) ? $raw_id : 0);

// 4. Verify cadet exists AND is enrolled in this leader's platoon for the active term
$cadet = null;
if ($cadet_id > 0 && $active_term && $platoon_id) {
    $stmt = $pdo->prepare("
        SELECT c.*, e.company_id, e.platoon_id, e.term_id,
               co.name AS company_name, p.name AS platoon_name,
               pr.name AS program_name, pr.code AS program_code
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
        LEFT JOIN companies co ON e.company_id = co.id
        LEFT JOIN platoons p ON e.platoon_id = p.id
        LEFT JOIN programs pr ON c.program_id = pr.id
        WHERE c.id = ? AND e.platoon_id = ?
    ");
    $stmt->execute([(int)$active_term['id'], $cadet_id, (int)$platoon_id]);
    $cadet = $stmt->fetch();
}

// If cadet not found in this platoon, show 404 Not Found page
if (!$cadet) {
    http_response_code(404);
    $page_title = 'Cadet Not Found';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="content-header">
        <h1>Cadet Not Found</h1>
        <p>The cadet you requested does not exist or is not enrolled in your platoon.</p>
    </div>
    <div class="card" style="max-width: 600px; text-align: center; padding: 40px 20px;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--error); margin-bottom: 16px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <h3 style="font-size: 18px; color: var(--green-900); margin-bottom: 8px;">Access Restricted or Record Missing</h3>
        <p style="font-size: 14px; color: var(--gray-700); margin-bottom: 24px;">
            You can only view and manage cadets currently enrolled in your assigned platoon for the active academic term.
        </p>
        <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-primary">
            &larr; Return to Cadets Roster
        </a>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// 5. Fetch academic programs list
$stmt = $pdo->query("SELECT id, code, name FROM programs ORDER BY code ASC");
$programs = $stmt->fetchAll();

// 6. Allowed cadet statuses
$status_options = [
    'active'      => 'Active',
    'dropped'     => 'Dropped',
    'transferred' => 'Transferred',
    'graduated'   => 'Graduated',
];

// Current / form values
$form = [
    'last_name'      => $cadet['last_name'],
    'first_name'     => $cadet['first_name'],
    'middle_name'    => $cadet['middle_name'] ?? '',
    'birthday'       => $cadet['birthday'],
    'gender'         => $cadet['gender'],
    'program_id'     => (int)$cadet['program_id'],
    'student_number' => $cadet['student_number'],
    'email'          => $cadet['email'],
    'contact_number' => $cadet['contact_number'] ?? '',
    'status'         => $cadet['status'],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('leader/cadet_edit.php?id=' . $cadet_id);
    }

    $form['last_name']      = trim($_POST['last_name'] ?? '');
    $form['first_name']     = trim($_POST['first_name'] ?? '');
    $form['middle_name']    = trim($_POST['middle_name'] ?? '');
    $form['birthday']       = trim($_POST['birthday'] ?? '');
    $form['gender']         = trim($_POST['gender'] ?? '');
    $form['program_id']     = (int)($_POST['program_id'] ?? 0);
    $form['student_number'] = trim($_POST['student_number'] ?? '');
    $form['email']          = trim($_POST['email'] ?? '');
    $form['contact_number'] = trim($_POST['contact_number'] ?? '');
    $form['status']         = trim($_POST['status'] ?? '');

    // Validation
    if ($form['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($form['last_name']) > 100) {
        $errors['last_name'] = 'Last name must not exceed 100 characters.';
    }

    if ($form['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($form['first_name']) > 100) {
        $errors['first_name'] = 'First name must not exceed 100 characters.';
    }

    if ($form['middle_name'] !== '' && mb_strlen($form['middle_name']) > 100) {
        $errors['middle_name'] = 'Middle name must not exceed 100 characters.';
    }

    if ($form['birthday'] === '') {
        $errors['birthday'] = 'Birthday is required.';
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $form['birthday']);
        if (!$d || $d->format('Y-m-d') !== $form['birthday']) {
            $errors['birthday'] = 'Invalid date format (must be YYYY-MM-DD).';
        } elseif ($form['birthday'] > date('Y-m-d')) {
            $errors['birthday'] = 'Birthday cannot be a future date.';
        }
    }

    if (!in_array($form['gender'], ['Male', 'Female'], true)) {
        $errors['gender'] = 'Please select a valid gender (Male or Female).';
    }

    if ($form['program_id'] <= 0) {
        $errors['program_id'] = 'Please select an academic program.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM programs WHERE id = ?");
        $stmt->execute([$form['program_id']]);
        if (!$stmt->fetch()) {
            $errors['program_id'] = 'Selected academic program does not exist.';
        }
    }

    if ($form['student_number'] === '') {
        $errors['student_number'] = 'Student number is required.';
    } elseif (mb_strlen($form['student_number']) > 50) {
        $errors['student_number'] = 'Student number must not exceed 50 characters.';
    } else {
        // Unique check ignoring this cadet's own record
        $stmt = $pdo->prepare("SELECT id FROM cadets WHERE student_number = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form['student_number'], $cadet_id]);
        if ($stmt->fetch()) {
            $errors['student_number'] = 'This student number is already registered to another cadet.';
        }
    }

    if ($form['email'] === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please provide a valid email address.';
    } elseif (mb_strlen($form['email']) > 150) {
        $errors['email'] = 'Email must not exceed 150 characters.';
    } else {
        // Unique check ignoring this cadet's own record
        $stmt = $pdo->prepare("SELECT id FROM cadets WHERE email = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form['email'], $cadet_id]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This email address is already in use by another cadet.';
        }
    }

    if ($form['contact_number'] !== '' && mb_strlen($form['contact_number']) > 50) {
        $errors['contact_number'] = 'Contact number must not exceed 50 characters.';
    }

    if (!isset($status_options[$form['status']])) {
        $errors['status'] = 'Please select a valid cadet status.';
    }

    // Process update if valid
    if (empty($errors)) {
        // Compare old and new values
        $old_values = [
            'last_name'      => $cadet['last_name'],
            'first_name'     => $cadet['first_name'],
            'middle_name'    => $cadet['middle_name'] ?? '',
            'birthday'       => $cadet['birthday'],
            'gender'         => $cadet['gender'],
            'program_id'     => (int)$cadet['program_id'],
            'student_number' => $cadet['student_number'],
            'email'          => $cadet['email'],
            'contact_number' => $cadet['contact_number'] ?? '',
            'status'         => $cadet['status'],
        ];

        $new_values = [
            'last_name'      => $form['last_name'],
            'first_name'     => $form['first_name'],
            'middle_name'    => $form['middle_name'],
            'birthday'       => $form['birthday'],
            'gender'         => $form['gender'],
            'program_id'     => $form['program_id'],
            'student_number' => $form['student_number'],
            'email'          => $form['email'],
            'contact_number' => $form['contact_number'],
            'status'         => $form['status'],
        ];

        $changes = [];
        foreach ($old_values as $key => $old_val) {
            $new_val = $new_values[$key];
            if ((string)$old_val !== (string)$new_val) {
                $changes[$key] = [
                    'old' => $old_val,
                    'new' => $new_val,
                ];
            }
        }

        if (empty($changes)) {
            set_flash('info', 'No changes were made to the cadet record.');
            redirect('leader/cadet_edit.php?id=' . $cadet_id);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE cadets SET
                    last_name      = :last_name,
                    first_name     = :first_name,
                    middle_name    = :middle_name,
                    birthday       = :birthday,
                    gender         = :gender,
                    program_id     = :program_id,
                    student_number = :student_number,
                    email          = :email,
                    contact_number = :contact_number,
                    status         = :status,
                    updated_at     = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'last_name'      => $form['last_name'],
                'first_name'     => $form['first_name'],
                'middle_name'    => $form['middle_name'] !== '' ? $form['middle_name'] : null,
                'birthday'       => $form['birthday'],
                'gender'         => $form['gender'],
                'program_id'     => $form['program_id'],
                'student_number' => $form['student_number'],
                'email'          => $form['email'],
                'contact_number' => $form['contact_number'] !== '' ? $form['contact_number'] : null,
                'status'         => $form['status'],
                'id'             => $cadet_id,
            ]);

            // Write to audit log
            log_audit(
                $pdo,
                $user['id'],
                'update_cadet',
                'cadet',
                $cadet_id,
                json_encode([
                    'cadet_code' => $cadet['cadet_code'],
                    'changes'    => $changes,
                    'old'        => $old_values,
                    'new'        => $new_values,
                ])
            );

            $pdo->commit();

            set_flash(
                'success',
                'Cadet ' . e($form['first_name'] . ' ' . $form['last_name']) .
                ' (' . e($cadet['cadet_code']) . ') was updated successfully.'
            );
            redirect('leader/cadets.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Failed to update cadet: ' . $e->getMessage());
            redirect('leader/cadet_edit.php?id=' . $cadet_id);
        }
    }
}

// Helper to render field error
$field_error = function (string $name) use ($errors): string {
    if (!isset($errors[$name])) return '';
    return '<span class="field-error-msg" style="color: var(--error); font-size: 12px; margin-top: 4px; display: block;">' . e($errors[$name]) . '</span>';
};

$page_title = 'Edit Cadet - ' . $cadet['cadet_code'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1>Edit Cadet Details</h1>
            <p style="margin: 0; color: var(--gray-700);">
                Manage cadet profile and status for <strong><?= e($cadet['cadet_code']) ?></strong>
            </p>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary btn-sm">
                &larr; Back to Cadets
            </a>
        </div>
    </div>
</div>

<div class="card" style="max-width: 800px; margin-bottom: 32px;">
    <!-- Non-editable Assignment & Code Info Banner -->
    <div style="background-color: var(--gray-50); border: 1px solid var(--gray-300); border-radius: var(--radius-default); padding: 16px; margin-bottom: 24px;">
        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-700); margin-bottom: 8px;">
            Enrolled Unit & Identity (Read-only)
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; font-size: 14px;">
            <div>
                <span style="color: var(--gray-700); display: block; font-size: 12px;">Cadet Code:</span>
                <strong style="font-family: monospace; font-size: 14px; color: var(--green-900);"><?= e($cadet['cadet_code']) ?></strong>
            </div>
            <div>
                <span style="color: var(--gray-700); display: block; font-size: 12px;">Company:</span>
                <strong>Company <?= e($company_name) ?></strong>
            </div>
            <div>
                <span style="color: var(--gray-700); display: block; font-size: 12px;">Platoon:</span>
                <strong><?= e($platoon_name) ?></strong>
            </div>
            <div>
                <span style="color: var(--gray-700); display: block; font-size: 12px;">Active Term:</span>
                <strong><?= e($active_term['name']) ?></strong>
            </div>
        </div>
        <div style="margin-top: 10px; font-size: 12px; color: var(--gray-700);">
            <em>Cadet codes and platoon assignments cannot be altered here. Cadets may be unassigned from the roster page.</em>
        </div>
    </div>

    <!-- Edit Form -->
    <form method="POST" action="<?= BASE_URL ?>/leader/cadet_edit.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$cadet['id'] ?>">

        <h3 style="font-size: 16px; color: var(--green-900); border-bottom: 1px solid var(--gray-300); padding-bottom: 8px; margin-bottom: 16px;">
            Personal Information
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div class="form-group" style="margin: 0;">
                <label for="last_name">Last Name <span style="color: var(--error);">*</span></label>
                <input type="text" id="last_name" name="last_name" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" value="<?= e($form['last_name']) ?>" required>
                <?= $field_error('last_name') ?>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="first_name">First Name <span style="color: var(--error);">*</span></label>
                <input type="text" id="first_name" name="first_name" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" value="<?= e($form['first_name']) ?>" required>
                <?= $field_error('first_name') ?>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="middle_name">Middle Name <span style="font-weight: normal; color: var(--gray-500);">(Optional)</span></label>
                <input type="text" id="middle_name" name="middle_name" class="form-control <?= isset($errors['middle_name']) ? 'is-invalid' : '' ?>" value="<?= e($form['middle_name']) ?>">
                <?= $field_error('middle_name') ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div class="form-group" style="margin: 0;">
                <label for="birthday">Birthday <span style="color: var(--error);">*</span></label>
                <input type="date" id="birthday" name="birthday" max="<?= date('Y-m-d') ?>" class="form-control <?= isset($errors['birthday']) ? 'is-invalid' : '' ?>" value="<?= e($form['birthday']) ?>" required>
                <?= $field_error('birthday') ?>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="gender">Gender <span style="color: var(--error);">*</span></label>
                <select id="gender" name="gender" class="form-control <?= isset($errors['gender']) ? 'is-invalid' : '' ?>" required>
                    <option value="">-- Select Gender --</option>
                    <option value="Male" <?= $form['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= $form['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
                <?= $field_error('gender') ?>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="program_id">Academic Program <span style="color: var(--error);">*</span></label>
                <select id="program_id" name="program_id" class="form-control <?= isset($errors['program_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">-- Select Program --</option>
                    <?php foreach ($programs as $prog): ?>
                        <option value="<?= (int)$prog['id'] ?>" <?= $form['program_id'] === (int)$prog['id'] ? 'selected' : '' ?>>
                            <?= e($prog['code'] . ' - ' . $prog['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $field_error('program_id') ?>
            </div>
        </div>

        <h3 style="font-size: 16px; color: var(--green-900); border-bottom: 1px solid var(--gray-300); padding-bottom: 8px; margin-bottom: 16px;">
            Student Records & Contact
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div class="form-group" style="margin: 0;">
                <label for="student_number">Student Number <span style="color: var(--error);">*</span></label>
                <input type="text" id="student_number" name="student_number" class="form-control <?= isset($errors['student_number']) ? 'is-invalid' : '' ?>" value="<?= e($form['student_number']) ?>" required>
                <?= $field_error('student_number') ?>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="email">Email Address <span style="color: var(--error);">*</span></label>
                <input type="email" id="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($form['email']) ?>" required>
                <?= $field_error('email') ?>
            </div>

            <div class="form-group" style="margin: 0;">
                <label for="contact_number">Contact Number <span style="font-weight: normal; color: var(--gray-500);">(Optional)</span></label>
                <input type="text" id="contact_number" name="contact_number" class="form-control <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>" value="<?= e($form['contact_number']) ?>">
                <?= $field_error('contact_number') ?>
            </div>
        </div>

        <h3 style="font-size: 16px; color: var(--green-900); border-bottom: 1px solid var(--gray-300); padding-bottom: 8px; margin-bottom: 16px;">
            Cadet Status
        </h3>

        <div style="max-width: 300px; margin-bottom: 24px;">
            <div class="form-group" style="margin: 0;">
                <label for="status">Enrollment Status <span style="color: var(--error);">*</span></label>
                <select id="status" name="status" class="form-control <?= isset($errors['status']) ? 'is-invalid' : '' ?>" required>
                    <?php foreach ($status_options as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $form['status'] === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $field_error('status') ?>
                <span style="font-size: 12px; color: var(--gray-500); margin-top: 4px; display: block;">
                    Cadet status within the unit (active, dropped, transferred, or graduated).
                </span>
            </div>
        </div>

        <div style="display: flex; gap: 12px; align-items: center; border-top: 1px solid var(--gray-300); padding-top: 20px;">
            <button type="submit" class="btn btn-primary">
                Save Changes
            </button>
            <a href="<?= BASE_URL ?>/leader/cadets.php" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
