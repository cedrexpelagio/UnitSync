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
    'unassigned'  => 'Unassigned',
    'dropped'     => 'Dropped',
    'transferred' => 'Transferred',
    'graduated'   => 'Graduated',
];

// Back link keeps the roster filters the user had
$back = 's1/roster.php' . (!empty($_SESSION['roster_qs']) ? '?' . $_SESSION['roster_qs'] : '');

$raw_id = $_POST['id'] ?? ($_GET['id'] ?? '');
$id = (is_string($raw_id) && ctype_digit($raw_id)) ? (int)$raw_id : 0;

$stmt = $pdo->prepare("SELECT * FROM cadets WHERE id = ?");
$stmt->execute([$id]);
$cadet = $stmt->fetch();
if (!$cadet) {
    set_flash('error', 'Cadet not found.');
    redirect($back);
}

// Dropdown data
$programs = $pdo->query("SELECT code FROM programs ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($cadet['program'], $programs, true)) {
    $programs[] = $cadet['program']; // keep a program that was imported as free text
}
$platoons = $pdo->query("
    SELECT p.id, p.name, p.company_id, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();
$platoon_by_id = [];
foreach ($platoons as $pl) $platoon_by_id[(int)$pl['id']] = $pl;

// Form values (current data, replaced by POST when saving)
$form = [
    'full_name'   => $cadet['full_name'],
    'gender'      => $cadet['gender'],
    'designation' => $cadet['designation'],
    'program'     => $cadet['program'],
    'platoon_id'  => $cadet['platoon_id'] !== null ? (string)$cadet['platoon_id'] : '',
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
    if ($form['full_name'] === '') $errors['full_name'] = 'Full name is required.';
    elseif (mb_strlen($form['full_name']) > 150) $errors['full_name'] = 'Full name is too long.';

    if (!in_array($form['gender'], ['Male', 'Female'], true)) $errors['gender'] = 'Choose Male or Female.';

    if ($form['designation'] === '') $errors['designation'] = 'Designation is required.';
    elseif (mb_strlen($form['designation']) > 100) $errors['designation'] = 'Designation is too long.';

    if (!in_array($form['program'], $programs, true)) $errors['program'] = 'Choose a valid program.';

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

    // Keep status and assignment consistent
    $note = '';
    if (!$errors) {
        if ($new_platoon !== null && $form['status'] === 'unassigned') {
            $form['status'] = 'active';
            $note = ' Status was set to Active because a platoon was assigned.';
        } elseif ($new_platoon === null && $form['status'] === 'active') {
            $form['status'] = 'unassigned';
            $note = ' Status was set to Unassigned because no platoon is assigned.';
        }
    }

    // Duplicate (same full name and program as another cadet)
    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM cadets WHERE full_name = ? AND program = ? AND id <> ? LIMIT 1");
        $stmt->execute([$form['full_name'], $form['program'], $id]);
        if ($stmt->fetch()) {
            $errors['full_name'] = 'Another cadet with this name and program already exists.';
        }
    }

    if (!$errors) {
        try {
            // Work out what changed for the audit log
            $old = [
                'full_name' => $cadet['full_name'], 'gender' => $cadet['gender'],
                'designation' => $cadet['designation'], 'program' => $cadet['program'],
                'platoon_id' => $cadet['platoon_id'] !== null ? (string)$cadet['platoon_id'] : '',
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
                SET full_name = :full_name, gender = :gender, designation = :designation, program = :program,
                    company_id = :company_id, platoon_id = :platoon_id, status = :status
                WHERE id = :id
            ");
            $upd->execute([
                'full_name'   => $form['full_name'],
                'gender'      => $form['gender'],
                'designation' => $form['designation'],
                'program'     => $form['program'],
                'company_id'  => $new_company,
                'platoon_id'  => $new_platoon,
                'status'      => $form['status'],
                'id'          => $id,
            ]);

            $audit = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (?, 'edit_cadet', 'cadets', ?, ?, NOW())
            ");
            $audit->execute([$user['id'], $id, mb_substr('Edited cadet #' . $id . ' - ' . implode('; ', $changes), 0, 1000)]);
            $pdo->commit();

            set_flash('success', 'Cadet updated.' . $note);
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
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['full_name']) ?>" maxlength="150" required>
            <?php if (isset($errors['full_name'])): ?><span class="field-error"><?= e($errors['full_name']) ?></span><?php endif; ?>
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
            <input type="text" id="designation" name="designation" class="form-control<?= isset($errors['designation']) ? ' is-invalid' : '' ?>" value="<?= e($form['designation']) ?>" maxlength="100" required>
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
            <small>Assigning a platoon sets the status to Active. Removing the platoon sets it to Unassigned.</small>
        </div>

        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="<?= BASE_URL . '/' . e($back) ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
