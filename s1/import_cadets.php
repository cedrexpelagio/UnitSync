<?php
// S1 Cadet CSV Import (Battalion S1 and Brigade S1 only)
// Flow: upload CSV -> preview with validation -> confirm to save
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

const IMPORT_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const IMPORT_MAX_ROWS  = 2000;

// ---------- Helpers ----------

function clean_cell(?string $v): string {
    $v = $v ?? '';
    if (!mb_check_encoding($v, 'UTF-8')) {
        // Excel on Windows often saves CSV as Windows-1252 (keeps ñ, é, etc.)
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return trim((string)preg_replace('/\s+/u', ' ', $v));
}

function norm_header(string $h): string {
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($h));
}

function norm_gender(string $g): ?string {
    $g = mb_strtolower(trim($g));
    if (in_array($g, ['m', 'male', 'man', 'boy', 'lalaki'], true)) return 'Male';
    if (in_array($g, ['f', 'female', 'woman', 'girl', 'babae'], true)) return 'Female';
    return null;
}

// Detect which CSV column holds which field. Google Form exports have
// a Timestamp column and long question titles, so we match by keyword.
function map_columns(array $headers): array {
    $aliases = [
        'full_name'   => ['fullname', 'completename', 'cadetname', 'name'],
        'gender'      => ['gender', 'sex'],
        'designation' => ['designation', 'position', 'rank', 'role'],
        'program'     => ['program', 'course', 'degree'],
    ];
    $normalized = array_map('norm_header', $headers);
    $map = [];
    $used = [];

    // Pass 1: exact match. Pass 2: header contains the keyword.
    foreach ([false, true] as $contains) {
        foreach ($aliases as $field => $words) {
            if (isset($map[$field])) continue;
            foreach ($normalized as $i => $n) {
                if (isset($used[$i]) || $n === '' || $n === 'timestamp') continue;
                foreach ($words as $w) {
                    if (($contains && strpos($n, $w) !== false) || (!$contains && $n === $w)) {
                        $map[$field] = $i;
                        $used[$i] = true;
                        continue 3;
                    }
                }
            }
        }
    }
    return $map;
}

function dup_key(string $name, string $program): string {
    return mb_strtolower($name) . '|' . mb_strtolower($program);
}

// "Dela Cruz, Juan A." -> ['Dela Cruz', 'Juan', 'A.']. Returns null when there is no comma.
function split_name(string $full): ?array {
    $pos = strpos($full, ',');
    if ($pos === false) return null;
    $last = trim(substr($full, 0, $pos));
    $rest = trim(substr($full, $pos + 1));
    if ($last === '' || $rest === '') return null;
    $tokens = explode(' ', $rest);
    $middle = '';
    if (count($tokens) > 1) {
        $tail = end($tokens);
        if (preg_match('/^\p{L}\.?$/u', $tail)) { // a single initial such as "A." or "A"
            $middle = $tail;
            array_pop($tokens);
        }
    }
    return [$last, implode(' ', $tokens), $middle];
}

// Cadet enrollments belong to a term, so imports need an active one
function active_term_id(PDO $pdo): int {
    return (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();
}

// ---------- CSV template download ----------
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cadet_import_template.csv"');
    echo "Full Name,Gender,Designation,Program\r\n";
    echo "\"Dela Cruz, Juan A.\",Male,Cadet,BSIT\r\n";
    echo "\"Santos, Maria B.\",Female,Cadet,BSCS\r\n";
    exit;
}

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('s1/import_cadets.php');
    }

    $action = $_POST['action'] ?? '';

    // --- Cancel preview ---
    if ($action === 'cancel') {
        unset($_SESSION['cadet_import']);
        set_flash('info', 'Import cancelled. Nothing was saved.');
        redirect('s1/import_cadets.php');
    }

    // --- Confirm and save ---
    if ($action === 'confirm') {
        $import = $_SESSION['cadet_import'] ?? null;
        if (!$import || empty($import['rows'])) {
            set_flash('error', 'No import in progress. Please upload the file again.');
            redirect('s1/import_cadets.php');
        }

        $term_id = active_term_id($pdo);
        if (!$term_id) {
            set_flash('error', 'There is no active term. An Administrator must activate a term before cadets can be imported.');
            redirect('s1/import_cadets.php');
        }

        try {
            // Re-check duplicates against the database right before saving
            $existing = [];
            foreach ($pdo->query("SELECT c.last_name, c.first_name, pr.code FROM cadets c JOIN programs pr ON pr.id = c.program_id") as $r) {
                $existing[dup_key($r['last_name'] . '|' . $r['first_name'], $r['code'])] = true;
            }

            $programIds = [];
            foreach ($pdo->query("SELECT id, code FROM programs") as $p) {
                $programIds[$p['code']] = (int)$p['id'];
            }

            $pdo->beginTransaction();
            $ins = $pdo->prepare("
                INSERT INTO cadets (cadet_code, last_name, first_name, middle_name, gender, designation, program_id, status, created_by, created_at, updated_at)
                VALUES (:cadet_code, :last_name, :first_name, :middle_name, :gender, :designation, :program_id, 'active', :created_by, NOW(), NOW())
            ");
            // Imported cadets are enrolled with no company/platoon (Unassigned) until S1 assigns them
            $enr = $pdo->prepare("
                INSERT INTO enrollments (cadet_id, term_id, company_id, platoon_id, created_at, updated_at)
                VALUES (:cadet_id, :term_id, NULL, NULL, NOW(), NOW())
            ");

            $saved = 0;
            $skipped = 0;
            foreach ($import['rows'] as $row) {
                if ($row['status'] !== 'ok') continue;
                $key = dup_key($row['last_name'] . '|' . $row['first_name'], $row['program']);
                if (isset($existing[$key]) || !isset($programIds[$row['program']])) { $skipped++; continue; }
                $ins->execute([
                    'cadet_code'  => next_cadet_code($pdo),
                    'last_name'   => $row['last_name'],
                    'first_name'  => $row['first_name'],
                    'middle_name' => $row['middle_name'] !== '' ? $row['middle_name'] : null,
                    'gender'      => $row['gender'],
                    'designation' => $row['designation'],
                    'program_id'  => $programIds[$row['program']],
                    'created_by'  => $user['id'],
                ]);
                $enr->execute(['cadet_id' => (int)$pdo->lastInsertId(), 'term_id' => $term_id]);
                $existing[$key] = true;
                $saved++;
            }

            $audit = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (?, 'import_cadets', 'cadets', NULL, ?, NOW())
            ");
            $audit->execute([$user['id'], "Imported {$saved} cadets from " . $import['file']]);

            $pdo->commit();
            unset($_SESSION['cadet_import']);

            $msg = "Import complete: {$saved} cadet(s) saved.";
            if ($skipped > 0) $msg .= " {$skipped} skipped as duplicates.";
            set_flash('success', $msg);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Import failed, nothing was saved. ' . $ex->getMessage());
        }
        redirect('s1/import_cadets.php');
    }

    // --- Upload and build preview ---
    if ($action === 'upload') {
        unset($_SESSION['cadet_import']);
        $f = $_FILES['csv_file'] ?? null;

        if (!active_term_id($pdo)) {
            set_flash('error', 'There is no active term. An Administrator must activate a term before cadets can be imported.');
            redirect('s1/import_cadets.php');
        }

        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'Please choose a CSV file to upload.');
            redirect('s1/import_cadets.php');
        }
        if ($f['size'] > IMPORT_MAX_BYTES) {
            set_flash('error', 'File is too large. Maximum size is 2 MB.');
            redirect('s1/import_cadets.php');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'csv') {
            set_flash('error', 'Only .csv files are accepted. In Google Sheets use File > Download > Comma-separated values.');
            redirect('s1/import_cadets.php');
        }

        $h = fopen($f['tmp_name'], 'r');
        if (!$h) {
            set_flash('error', 'Could not read the uploaded file.');
            redirect('s1/import_cadets.php');
        }

        // Detect delimiter from the first line
        $firstLine = fgets($h);
        rewind($h);
        $delim = ',';
        if ($firstLine !== false) {
            $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
            arsort($counts);
            $delim = array_key_first($counts);
        }

        $headers = fgetcsv($h, 0, $delim);
        if (!$headers) {
            fclose($h);
            set_flash('error', 'The file is empty.');
            redirect('s1/import_cadets.php');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]); // strip BOM
        $headers = array_map(fn($x) => clean_cell($x), $headers);

        $map = map_columns($headers);
        $missing = array_diff(['full_name', 'gender', 'designation', 'program'], array_keys($map));
        if ($missing) {
            fclose($h);
            $labels = ['full_name' => 'Full Name', 'gender' => 'Gender', 'designation' => 'Designation', 'program' => 'Program'];
            $names = implode(', ', array_map(fn($m) => $labels[$m], $missing));
            set_flash('error', "Missing column(s): {$names}. Columns found in your file: " . implode(', ', $headers) . '.');
            redirect('s1/import_cadets.php');
        }

        // Known programs (so "bsit" or the full name becomes the code "BSIT")
        $programLookup = [];
        foreach ($pdo->query("SELECT code, name FROM programs") as $p) {
            $programLookup[mb_strtolower($p['code'])] = $p['code'];
            $programLookup[mb_strtolower($p['name'])] = $p['code'];
        }

        // Existing cadets, for duplicate detection
        $existing = [];
        foreach ($pdo->query("SELECT c.last_name, c.first_name, pr.code FROM cadets c JOIN programs pr ON pr.id = c.program_id") as $r) {
            $existing[dup_key($r['last_name'] . '|' . $r['first_name'], $r['code'])] = true;
        }

        $rows = [];
        $seen = [];
        $line = 1;
        $tooMany = false;
        while (($data = fgetcsv($h, 0, $delim)) !== false) {
            $line++;
            if (count($data) === 1 && trim((string)$data[0]) === '') continue; // blank line

            $name  = clean_cell($data[$map['full_name']] ?? '');
            $genR  = clean_cell($data[$map['gender']] ?? '');
            $desig = clean_cell($data[$map['designation']] ?? '');
            $prog  = clean_cell($data[$map['program']] ?? '');
            if ($name === '' && $genR === '' && $desig === '' && $prog === '') continue;

            if (count($rows) >= IMPORT_MAX_ROWS) { $tooMany = true; break; }

            $errors = [];
            if ($name === '') $errors[] = 'Full name is required';
            elseif (mb_strlen($name) > 150) $errors[] = 'Full name is too long';

            $gender = norm_gender($genR);
            if ($gender === null) $errors[] = 'Gender must be Male or Female';

            if ($desig === '') $errors[] = 'Designation is required';
            elseif (mb_strlen($desig) > 100) $errors[] = 'Designation is too long';

            if ($prog === '') $errors[] = 'Program is required';
            else {
                $resolved = $programLookup[mb_strtolower($prog)] ?? null;
                if ($resolved === null) $errors[] = 'Unknown program "' . $prog . '"';
                else $prog = $resolved;
            }

            $parts = ['', '', ''];
            if ($name !== '') {
                $split = split_name($name);
                if ($split === null) $errors[] = 'Use the format "Last name, First name M."';
                else $parts = $split;
            }

            $status = 'ok';
            $note = '';
            if ($errors) {
                $status = 'error';
                $note = implode('; ', $errors);
            } else {
                $key = dup_key($parts[0] . '|' . $parts[1], $prog);
                if (isset($existing[$key])) {
                    $status = 'duplicate';
                    $note = 'Already in the roster';
                } elseif (isset($seen[$key])) {
                    $status = 'duplicate';
                    $note = 'Repeated in this file';
                } else {
                    $seen[$key] = true;
                }
            }

            $rows[] = [
                'line'        => $line,
                'full_name'   => $name,
                'last_name'   => $parts[0],
                'first_name'  => $parts[1],
                'middle_name' => $parts[2],
                'gender'      => $gender ?? $genR,
                'designation' => $desig,
                'program'     => $prog,
                'status'      => $status,
                'note'        => $note,
            ];
        }
        fclose($h);

        if (!$rows) {
            set_flash('error', 'No data rows found in the file.');
            redirect('s1/import_cadets.php');
        }

        $_SESSION['cadet_import'] = ['file' => $f['name'], 'rows' => $rows];
        if ($tooMany) {
            set_flash('warning', 'Only the first ' . IMPORT_MAX_ROWS . ' rows were read. Split the file and import the rest separately.');
        }
        redirect('s1/import_cadets.php');
    }

    redirect('s1/import_cadets.php');
}

// ---------- Page data ----------
$import = $_SESSION['cadet_import'] ?? null;
$counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0];
if ($import) {
    foreach ($import['rows'] as $r) $counts[$r['status']]++;
}
$total_cadets = (int)$pdo->query("SELECT COUNT(*) FROM cadets WHERE status = 'active'")->fetchColumn();

$page_title = 'Import Cadets';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Import Cadets</h1>
    <p>Upload a CSV (for example, Google Form responses) to add cadets to the roster. Active cadets in roster: <strong><?= $total_cadets ?></strong></p>
</div>

<?php if (!$import): ?>

    <div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
        <h3>Step 1: Upload CSV file</h3>
        <p>The file needs these columns: <strong>Full Name, Gender, Designation, Program</strong>. Extra columns such as Timestamp are ignored.
           Write the name as <strong>Last name, First name M.</strong> (for example <em>Dela Cruz, Juan A.</em>). Gender must be Male or Female. The program must match an existing program code. Duplicates (same name and program) are skipped.</p>
        <p><a href="<?= BASE_URL ?>/s1/import_cadets.php?template=1">Download CSV template</a></p>

        <form action="<?= BASE_URL ?>/s1/import_cadets.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label for="csv_file">CSV file (max 2 MB)</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload and preview</button>
        </form>
    </div>

    <div class="summary-card" style="max-width: 650px;">
        <h3>Getting the CSV from Google Forms</h3>
        <p>Open the form, go to <strong>Responses</strong>, click the green Sheets icon, then in Google Sheets choose
           <strong>File &gt; Download &gt; Comma-separated values (.csv)</strong>.</p>
    </div>

<?php else: ?>

    <div class="summary-card" style="margin-bottom: 16px;">
        <h3>Step 2: Review and confirm</h3>
        <p>File: <strong><?= e($import['file']) ?></strong></p>
        <p>
            <span class="badge badge-approved"><?= $counts['ok'] ?> ready to save</span>
            <span class="badge badge-pending"><?= $counts['duplicate'] ?> duplicate (skipped)</span>
            <span class="badge badge-rejected"><?= $counts['error'] ?> with errors (skipped)</span>
        </p>
        <?php if ($counts['error'] > 0): ?>
            <p>Rows with errors will not be saved. Fix them in your file and upload again, or continue to save only the valid rows.</p>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/s1/import_cadets.php" method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-primary" <?= $counts['ok'] === 0 ? 'disabled' : '' ?>>Save <?= $counts['ok'] ?> cadet(s)</button>
        </form>
        <form action="<?= BASE_URL ?>/s1/import_cadets.php" method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-secondary">Cancel</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Line</th>
                    <th>Full Name</th>
                    <th>Gender</th>
                    <th>Designation</th>
                    <th>Program</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($import['rows'] as $r): ?>
                    <tr>
                        <td><?= (int)$r['line'] ?></td>
                        <td><?= e($r['full_name']) ?></td>
                        <td><?= e($r['gender']) ?></td>
                        <td><?= e($r['designation']) ?></td>
                        <td><?= e($r['program']) ?></td>
                        <td>
                            <?php if ($r['status'] === 'ok'): ?>
                                <span class="badge badge-approved">Ready</span>
                            <?php elseif ($r['status'] === 'duplicate'): ?>
                                <span class="badge badge-pending">Duplicate</span> <?= e($r['note']) ?>
                            <?php else: ?>
                                <span class="badge badge-rejected">Error</span> <?= e($r['note']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>