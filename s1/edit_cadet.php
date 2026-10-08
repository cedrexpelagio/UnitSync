<?php
// S1 Edit Cadet: edit details, assign/move company and platoon, set status
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

$status_labels = [
    'active'      => 'Active',
    'dropped'     => 'Dropped',
    'transferred' => 'Transferred',
    'graduated'   => 'Graduated',
];

// Back link keeps the roster filters the user had
$back = 's1/roster.php' . (!empty($_SESSION['roster_qs']) ? '?' . $_SESSION['roster_qs'] : '');

$raw_id = $_POST['id'] ?? ($_GET['id'] ?? '');
$id = (is_string($raw_id) && ctype_digit($raw_id)) ? (int)$raw_id : 0;

// Assignments are stored per term, so we need the active term
$active_term_id = (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();
if (!$active_term_id) {
    set_flash('error', 'There is no active term. An Administrator must activate a term before cadets can be edited.');
    redirect($back);
}

$stmt = $pdo->prepare("
    SELECT c.*, pr.code AS program_code, e.company_id AS e_company_id, e.platoon_id AS e_platoon_id
    FROM cadets c
    JOIN programs pr ON pr.id = c.program_id
    LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ?
    WHERE c.id = ?
");
$stmt->execute([$active_term_id, $id]);
$cadet = $stmt->fetch();
if (!$cadet) {
    set_flash('error', 'Cadet not found.');
    redirect($back);
}

// Dropdown data
$program_ids = [];
foreach ($pdo->query("SELECT id, code FROM programs ORDER BY code") as $pg) {
    $program_ids[$pg['code']] = (int)$pg['id'];
}
$programs = array_keys($program_ids);
$platoons = $pdo->query("
    SELECT p.id, p.name, p.company_id, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();
$platoon_by_id = [];
foreach ($platoons as $pl) $platoon_by_id[(int)$pl['id']] = $pl;

// Form values (current data, replaced by POST when saving)
$form = [
    'last_name'   => $cadet['last_name'],
    'first_name'  => $cadet['first_name'],
    'middle_name' => (string)($cadet['middle_name'] ?? ''),
    'gender'      => $cadet['gender'],
    'designation' => (string)($cadet['designation'] ?? ''),
    'program'     => $cadet['program_code'],
    'platoon_id'  => $cadet['e_platoon_id'] !== null ? (string)$cadet['e_platoon_id'] : '',
    'status'      => $cadet['status'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('s1/edit_cadet.php?id=' . $id);
    }

    foreach (array_keys($form) as $k) {
        $v = $_POST[$k] ?? '';
        $form[$k] = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
    }

    // Validate
    if ($form['last_name'] === '') $errors['last_name'] = 'Last name is required.';
    elseif (mb_strlen($form['last_name']) > 100) $errors['last_name'] = 'Last name is too long.';

    if ($form['first_name'] === '') $errors['first_name'] = 'First name is required.';
    elseif (mb_strlen($form['first_name']) > 100) $errors['first_name'] = 'First name is too long.';

    if (mb_strlen($form['middle_name']) > 100) $errors['middle_name'] = 'Middle name is too long.';

    if (!in_array($form['gender'], ['Male', 'Female'], true)) $errors['gender'] = 'Choose Male or Female.';

    if (mb_strlen($form['designation']) > 100) $errors['designation'] = 'Designation is too long.';

    if (!isset($program_ids[$form['program']])) $errors['program'] = 'Choose a valid program.';

    $new_company = null;
    $new_platoon = null;
    if ($form['platoon_id'] !== '') {
        $pid = ctype_digit($form['platoon_id']) ? (int)$form['platoon_id'] : 0;
        if (!isset($platoon_by_id[$pid])) {
            $errors['platoon_id'] = 'Choose a valid platoon.';
        } else {
            $new_platoon = $pid;
            $new_company = (int)$platoon_by_id[$pid]['company_id']; // company always follows the platoon
        }
    }

    if (!isset($status_labels[$form['status']])) $errors['status'] = 'Choose a valid status.';

    // Duplicate (same name and program as another cadet)
    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM cadets WHERE last_name = ? AND first_name = ? AND program_id = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form['last_name'], $form['first_name'], $program_ids[$form['program']], $id]);
        if ($stmt->fetch()) {
            $errors['first_name'] = 'Another cadet with this name and program already exists.';
        }
    }

    if (!$errors) {
        try {
            // Work out what changed for the audit log
            $old = [
                'last_name' => $cadet['last_name'], 'first_name' => $cadet['first_name'],
                'middle_name' => (string)($cadet['middle_name'] ?? ''), 'gender' => $cadet['gender'],
                'designation' => (string)($cadet['designation'] ?? ''), 'program' => $cadet['program_code'],
                'platoon_id' => $cadet['e_platoon_id'] !== null ? (string)$cadet['e_platoon_id'] : '',
                'status' => $cadet['status'],
            ];
            $new = $form;
            $changes = [];
            foreach ($old as $k => $ov) {
                if ((string)$ov !== (string)$new[$k]) {
                    $changes[] = "$k: '" . $ov . "' -> '" . $new[$k] . "'";
                }
            }

            if (!$changes) {
                set_flash('info', 'No changes were made.');
                redirect($back);
            }

            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE cadets
                SET last_name = :last_name, first_name = :first_name, middle_name = :middle_name,
                    gender = :gender, designation = :designation, program_id = :program_id, status = :status
                WHERE id = :id
            ");
            $upd->execute([
                'last_name'   => $form['last_name'],
                'first_name'  => $form['first_name'],
                'middle_name' => $form['middle_name'] !== '' ? $form['middle_name'] : null,
                'gender'      => $form['gender'],
                'designation' => $form['designation'] !== '' ? $form['designation'] : null,
                'program_id'  => $program_ids[$form['program']],
                'status'      => $form['status'],
                'id'          => $id,
            ]);

            // Company/platoon live in the enrollment row for the active term
            $enr = $pdo->prepare("
                INSERT INTO enrollments (cadet_id, term_id, company_id, platoon_id, created_at, updated_at)
                VALUES (:cadet_id, :term_id, :company_id, :platoon_id, NOW(), NOW())
                ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), platoon_id = VALUES(platoon_id), updated_at = NOW()
            ");
            $enr->execute([
                'cadet_id'   => $id,
                'term_id'    => $active_term_id,
                'company_id' => $new_company,
                'platoon_id' => $new_platoon,
            ]);

            $audit = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (?, 'edit_cadet', 'cadets', ?, ?, NOW())
            ");
            $audit->execute([$user['id'], $id, mb_substr('Edited cadet #' . $id . ' - ' . implode('; ', $changes), 0, 1000)]);
            $pdo->commit();

            set_flash('success', 'Cadet updated.');
            redirect($back);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Could not save changes: ' . $ex->getMessage());
            redirect('s1/edit_cadet.php?id=' . $id);
        }
    }
}

$page_title = 'Edit Cadet';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Edit Cadet</h1>
    <p>Update details and assign this cadet to a company and platoon.</p>
</div>

<div class="summary-card" style="max-width: 650px;">
    <form action="<?= BASE_URL ?>/s1/edit_cadet.php?id=<?= (int)$id ?>" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" class="form-control<?= isset($errors['last_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['last_name']) ?>" maxlength="100" required>
            <?php if (isset($errors['last_name'])): ?><span class="field-error"><?= e($errors['last_name']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" class="form-control<?= isset($errors['first_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['first_name']) ?>" maxlength="100" required>
            <?php if (isset($errors['first_name'])): ?><span class="field-error"><?= e($errors['first_name']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="middle_name">Middle Name (optional)</label>
            <input type="text" id="middle_name" name="middle_name" class="form-control<?= isset($errors['middle_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['middle_name']) ?>" maxlength="100">
            <?php if (isset($errors['middle_name'])): ?><span class="field-error"><?= e($errors['middle_name']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender" class="form-control<?= isset($errors['gender']) ? ' is-invalid' : '' ?>" required>
                <option value="Male" <?= $form['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $form['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
            <?php if (isset($errors['gender'])): ?><span class="field-error"><?= e($errors['gender']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="designation">Designation</label>
            <input type="text" id="designation" name="designation" class="form-control<?= isset($errors['designation']) ? ' is-invalid' : '' ?>" value="<?= e($form['designation']) ?>" maxlength="100">
            <?php if (isset($errors['designation'])): ?><span class="field-error"><?= e($errors['designation']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="program">Program</label>
            <select id="program" name="program" class="form-control<?= isset($errors['program']) ? ' is-invalid' : '' ?>" required>
                <?php foreach ($programs as $code): ?>
                    <option value="<?= e($code) ?>" <?= $form['program'] === $code ? 'selected' : '' ?>><?= e($code) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['program'])): ?><span class="field-error"><?= e($errors['program']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="platoon_id">Company and Platoon</label>
            <select id="platoon_id" name="platoon_id" class="form-control<?= isset($errors['platoon_id']) ? ' is-invalid' : '' ?>">
                <option value="">Unassigned (no company or platoon)</option>
                <?php foreach ($platoons as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>" <?= $form['platoon_id'] === (string)$pl['id'] ? 'selected' : '' ?>>
                        <?= e($pl['company_name'] . ' - ' . $pl['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['platoon_id'])): ?><span class="field-error"><?= e($errors['platoon_id']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" class="form-control<?= isset($errors['status']) ? ' is-invalid' : '' ?>" required>
                <?php foreach ($status_labels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $form['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['status'])): ?><span class="field-error"><?= e($errors['status']) ?></span><?php endif; ?>
            <small>A cadet with status Active and no platoon is shown as Unassigned in the roster.</small>
        </div>

        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="<?= BASE_URL . '/' . e($back) ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>