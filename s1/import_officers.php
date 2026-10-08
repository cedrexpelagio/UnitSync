<?php
// S1 Officer CSV Import (Battalion S1 and Brigade S1 only)
// Imports Company Commanders and Platoon Leaders into the officers roster.
// Flow: upload CSV -> preview with validation -> confirm to save
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

const OFFICER_IMPORT_MAX_BYTES = 1 * 1024 * 1024; // 1 MB
const OFFICER_IMPORT_MAX_ROWS  = 200;

const OFFICER_ROLE_LABELS = [
    'company_commander' => 'Company Commander',
    'platoon_leader'    => 'Platoon Leader',
];

// ---------- Helpers ----------

function off_clean_cell(?string $v): string {
    $v = $v ?? '';
    if (!mb_check_encoding($v, 'UTF-8')) {
        // Excel on Windows often saves CSV as Windows-1252 (keeps ñ, é, etc.)
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return trim((string)preg_replace('/\s+/u', ' ', $v));
}

function off_norm(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
}

// "Dela Cruz, Juan A." -> ['Dela Cruz', 'Juan', 'A.']. Returns null when there is no comma.
function off_split_name(string $full): ?array {
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

// "Company Commander", "CO", "CC" -> company_commander. "Platoon Leader", "PL", "Plt Ldr" -> platoon_leader.
function off_norm_role(string $r): ?string {
    $n = off_norm($r);
    if (in_array($n, ['companycommander', 'companycmdr', 'companycdr', 'cc', 'co', 'commander'], true)) return 'company_commander';
    if (in_array($n, ['platoonleader', 'platoonldr', 'pltleader', 'pltldr', 'pl'], true)) return 'platoon_leader';
    return null;
}

// Detect which CSV column holds which field by matching header keywords.
function off_map_columns(array $headers): array {
    $aliases = [
        'full_name' => ['fullname', 'completename', 'officername', 'name'],
        'role'      => ['role', 'position', 'designation', 'assignment'],
        'company'   => ['company'],
        'platoon'   => ['platoon'],
    ];
    $normalized = array_map('off_norm', $headers);
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

function off_person_key(string $last, string $first): string {
    return mb_strtolower($last) . '|' . mb_strtolower($first);
}

function off_slot_key(string $role, int $companyId, ?int $platoonId): string {
    return $role === 'company_commander' ? "cc:{$companyId}" : "pl:{$platoonId}";
}

// Active officers already saved, indexed by person and by slot
function off_load_existing(PDO $pdo): array {
    $people = [];
    $slots = [];
    $q = $pdo->query("SELECT last_name, first_name, role, company_id, platoon_id FROM officers WHERE status = 'active'");
    foreach ($q as $r) {
        $people[off_person_key($r['last_name'], $r['first_name'])] = true;
        $slots[off_slot_key($r['role'], (int)$r['company_id'], $r['platoon_id'] !== null ? (int)$r['platoon_id'] : null)]
            = $r['last_name'] . ', ' . $r['first_name'];
    }
    return [$people, $slots];
}

// ---------- CSV template download ----------
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="officer_import_template.csv"');
    echo "Full Name,Role,Company,Platoon\r\n";
    echo "\"Reyes, Carlo M.\",Company Commander,Alpha,\r\n";
    echo "\"Santos, Ana B.\",Platoon Leader,Alpha,1st Platoon\r\n";
    exit;
}

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('s1/import_officers.php');
    }

    $action = $_POST['action'] ?? '';

    // --- Cancel preview ---
    if ($action === 'cancel') {
        unset($_SESSION['officer_import']);
        set_flash('info', 'Import cancelled. Nothing was saved.');
        redirect('s1/import_officers.php');
    }

    // --- Confirm and save ---
    if ($action === 'confirm') {
        $import = $_SESSION['officer_import'] ?? null;
        if (!$import || empty($import['rows'])) {
            set_flash('error', 'No import in progress. Please upload the file again.');
            redirect('s1/import_officers.php');
        }

        try {
            $pdo->beginTransaction();

            // Re-check against the database right before saving (data may have changed since the preview)
            [$people, $slots] = off_load_existing($pdo);

            $ins = $pdo->prepare("
                INSERT INTO officers (last_name, first_name, middle_name, role, company_id, platoon_id, status, created_by, created_at, updated_at)
                VALUES (:last_name, :first_name, :middle_name, :role, :company_id, :platoon_id, 'active', :created_by, NOW(), NOW())
            ");

            $saved = 0;
            $skipped = 0;
            foreach ($import['rows'] as $row) {
                if ($row['status'] !== 'ok') continue;
                $pKey = off_person_key($row['last_name'], $row['first_name']);
                $sKey = off_slot_key($row['role'], $row['company_id'], $row['platoon_id']);
                if (isset($people[$pKey]) || isset($slots[$sKey])) { $skipped++; continue; }

                $ins->execute([
                    'last_name'   => $row['last_name'],
                    'first_name'  => $row['first_name'],
                    'middle_name' => $row['middle_name'] !== '' ? $row['middle_name'] : null,
                    'role'        => $row['role'],
                    'company_id'  => $row['company_id'],
                    'platoon_id'  => $row['platoon_id'],
                    'created_by'  => $user['id'],
                ]);
                $people[$pKey] = true;
                $slots[$sKey] = $row['last_name'] . ', ' . $row['first_name'];
                $saved++;
            }

            log_audit($pdo, (int)$user['id'], 'import_officers', 'officers', null, "Imported {$saved} officers from " . $import['file']);

            $pdo->commit();
            unset($_SESSION['officer_import']);

            $msg = "Import complete: {$saved} officer(s) saved.";
            if ($skipped > 0) $msg .= " {$skipped} skipped because they or their position were already filled.";
            set_flash('success', $msg);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Import failed, nothing was saved. ' . $ex->getMessage());
        }
        redirect('s1/import_officers.php');
    }

    // --- Upload and build preview ---
    if ($action === 'upload') {
        unset($_SESSION['officer_import']);
        $f = $_FILES['csv_file'] ?? null;

        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'Please choose a CSV file to upload.');
            redirect('s1/import_officers.php');
        }
        if ($f['size'] > OFFICER_IMPORT_MAX_BYTES) {
            set_flash('error', 'File is too large. Maximum size is 1 MB.');
            redirect('s1/import_officers.php');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'csv') {
            set_flash('error', 'Only .csv files are accepted. In Google Sheets use File > Download > Comma-separated values.');
            redirect('s1/import_officers.php');
        }

        $h = fopen($f['tmp_name'], 'r');
        if (!$h) {
            set_flash('error', 'Could not read the uploaded file.');
            redirect('s1/import_officers.php');
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
            redirect('s1/import_officers.php');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]); // strip BOM
        $headers = array_map(fn($x) => off_clean_cell($x), $headers);

        $map = off_map_columns($headers);
        // Platoon is only required for Platoon Leaders, but the column must exist so S1 notices it
        $missing = array_diff(['full_name', 'role', 'company', 'platoon'], array_keys($map));
        if ($missing) {
            fclose($h);
            $labels = ['full_name' => 'Full Name', 'role' => 'Role', 'company' => 'Company', 'platoon' => 'Platoon'];
            $names = implode(', ', array_map(fn($m) => $labels[$m], $missing));
            set_flash('error', "Missing column(s): {$names}. Columns found in your file: " . implode(', ', $headers) . '.');
            redirect('s1/import_officers.php');
        }

        // Companies and platoons lookups (case-insensitive)
        $companyLookup = []; // normalized name (with or without "company") => [id, name]
        foreach ($pdo->query("SELECT id, name FROM companies") as $c) {
            $n = off_norm($c['name']);
            $companyLookup[$n] = [(int)$c['id'], $c['name']];
            $companyLookup[$n . 'company'] = [(int)$c['id'], $c['name']];
        }
        $platoonLookup = []; // company_id => normalized platoon name => [id, name]
        foreach ($pdo->query("SELECT id, company_id, name FROM platoons") as $p) {
            $n = off_norm($p['name']);
            $short = str_replace('platoon', '', $n); // "1stplatoon" -> "1st"
            $entry = [(int)$p['id'], $p['name']];
            $platoonLookup[(int)$p['company_id']][$n] = $entry;
            if ($short !== '') {
                $platoonLookup[(int)$p['company_id']][$short] = $entry;
                // "1st" also reachable as "1"
                $digits = preg_replace('/\D/', '', $short);
                if ($digits !== '' && !isset($platoonLookup[(int)$p['company_id']][$digits])) {
                    $platoonLookup[(int)$p['company_id']][$digits] = $entry;
                }
            }
        }

        [$existingPeople, $existingSlots] = off_load_existing($pdo);

        $rows = [];
        $seenPeople = [];
        $seenSlots = [];
        $line = 1;
        $tooMany = false;
        while (($data = fgetcsv($h, 0, $delim)) !== false) {
            $line++;
            if (count($data) === 1 && trim((string)$data[0]) === '') continue; // blank line

            $name    = off_clean_cell($data[$map['full_name']] ?? '');
            $roleRaw = off_clean_cell($data[$map['role']] ?? '');
            $compRaw = off_clean_cell($data[$map['company']] ?? '');
            $platRaw = off_clean_cell($data[$map['platoon']] ?? '');
            if ($name === '' && $roleRaw === '' && $compRaw === '' && $platRaw === '') continue;

            if (count($rows) >= OFFICER_IMPORT_MAX_ROWS) { $tooMany = true; break; }

            $errors = [];

            // Name
            $parts = ['', '', ''];
            if ($name === '') $errors[] = 'Full name is required';
            elseif (mb_strlen($name) > 150) $errors[] = 'Full name is too long';
            else {
                $split = off_split_name($name);
                if ($split === null) $errors[] = 'Use the format "Last name, First name M."';
                else $parts = $split;
            }

            // Role
            $role = null;
            if ($roleRaw === '') $errors[] = 'Role is required';
            else {
                $role = off_norm_role($roleRaw);
                if ($role === null) $errors[] = 'Role must be Company Commander or Platoon Leader';
            }

            // Company
            $companyId = null;
            $companyName = $compRaw;
            if ($compRaw === '') $errors[] = 'Company is required';
            else {
                $c = $companyLookup[off_norm($compRaw)] ?? null;
                if ($c === null) $errors[] = 'Unknown company "' . $compRaw . '"';
                else { $companyId = $c[0]; $companyName = $c[1]; }
            }

            // Platoon (required for Platoon Leader, must be blank for Company Commander)
            $platoonId = null;
            $platoonName = '';
            if ($role === 'platoon_leader') {
                if ($platRaw === '') $errors[] = 'Platoon is required for a Platoon Leader';
                elseif ($companyId !== null) {
                    $p = $platoonLookup[$companyId][off_norm($platRaw)] ?? null;
                    if ($p === null) $errors[] = 'Unknown platoon "' . $platRaw . '" in ' . $companyName . ' Company';
                    else { $platoonId = $p[0]; $platoonName = $p[1]; }
                } else {
                    $platoonName = $platRaw;
                }
            } elseif ($role === 'company_commander') {
                if ($platRaw !== '') $errors[] = 'A Company Commander has no platoon; leave Platoon empty';
            } else {
                $platoonName = $platRaw;
            }

            $status = 'ok';
            $note = '';
            if ($errors) {
                $status = 'error';
                $note = implode('; ', $errors);
            } else {
                $pKey = off_person_key($parts[0], $parts[1]);
                $sKey = off_slot_key($role, $companyId, $platoonId);
                $slotLabel = $role === 'company_commander' ? "{$companyName} Company" : "{$companyName} {$platoonName}";

                if (isset($existingPeople[$pKey])) {
                    $status = 'duplicate';
                    $note = 'Already an active officer';
                } elseif (isset($seenPeople[$pKey])) {
                    $status = 'duplicate';
                    $note = 'Repeated in this file';
                } elseif (isset($existingSlots[$sKey])) {
                    $status = 'error';
                    $note = "{$slotLabel} already has a " . OFFICER_ROLE_LABELS[$role] . ' (' . $existingSlots[$sKey] . ')';
                } elseif (isset($seenSlots[$sKey])) {
                    $status = 'error';
                    $note = "{$slotLabel} is given to more than one " . OFFICER_ROLE_LABELS[$role] . ' in this file';
                } else {
                    $seenPeople[$pKey] = true;
                    $seenSlots[$sKey] = true;
                }
            }

            $rows[] = [
                'line'        => $line,
                'full_name'   => $name,
                'last_name'   => $parts[0],
                'first_name'  => $parts[1],
                'middle_name' => $parts[2],
                'role'        => $role ?? '',
                'role_label'  => $role ? OFFICER_ROLE_LABELS[$role] : $roleRaw,
                'company_id'  => $companyId ?? 0,
                'company'     => $companyName,
                'platoon_id'  => $platoonId,
                'platoon'     => $platoonName,
                'status'      => $status,
                'note'        => $note,
            ];
        }
        fclose($h);

        if (!$rows) {
            set_flash('error', 'No data rows found in the file.');
            redirect('s1/import_officers.php');
        }

        $_SESSION['officer_import'] = ['file' => $f['name'], 'rows' => $rows];
        if ($tooMany) {
            set_flash('warning', 'Only the first ' . OFFICER_IMPORT_MAX_ROWS . ' rows were read. Split the file and import the rest separately.');
        }
        redirect('s1/import_officers.php');
    }

    redirect('s1/import_officers.php');
}

// ---------- Page data ----------
$import = $_SESSION['officer_import'] ?? null;
$counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0];
if ($import) {
    foreach ($import['rows'] as $r) $counts[$r['status']]++;
}

$officers = $pdo->query("
    SELECT o.last_name, o.first_name, o.middle_name, o.role, c.name AS company, p.name AS platoon
    FROM officers o
    JOIN companies c ON c.id = o.company_id
    LEFT JOIN platoons p ON p.id = o.platoon_id
    WHERE o.status = 'active'
    ORDER BY c.name, o.role = 'platoon_leader', p.name, o.last_name, o.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Import Officers';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Import Officers</h1>
    <p>Upload a CSV to add Company Commanders and Platoon Leaders. Active officers on file: <strong><?= count($officers) ?></strong></p>
</div>

<?php if (!$import): ?>

    <div class="summary-card" style="max-width: 650px; margin-bottom: 24px;">
        <h3>Step 1: Upload CSV file</h3>
        <p>The file needs these columns: <strong>Full Name, Role, Company, Platoon</strong>. Extra columns such as Timestamp are ignored.
           Write the name as <strong>Last name, First name M.</strong> (for example <em>Dela Cruz, Juan A.</em>).
           Role is <strong>Company Commander</strong> or <strong>Platoon Leader</strong>.
           A Platoon Leader needs a Platoon (for example <em>1st Platoon</em>); leave Platoon empty for a Company Commander.
           Each company can have one Company Commander and each platoon one Platoon Leader.</p>
        <p><a href="<?= BASE_URL ?>/s1/import_officers.php?template=1">Download CSV template</a></p>

        <form action="<?= BASE_URL ?>/s1/import_officers.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label for="csv_file">CSV file (max 1 MB)</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload and preview</button>
        </form>
    </div>

    <div class="summary-card">
        <h3>Current officers</h3>
        <?php if (!$officers): ?>
            <p>No officers have been imported yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Company</th>
                            <th>Platoon</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($officers as $o): ?>
                            <tr>
                                <td><?= e($o['last_name'] . ', ' . $o['first_name'] . ($o['middle_name'] ? ' ' . $o['middle_name'] : '')) ?></td>
                                <td><?= e(OFFICER_ROLE_LABELS[$o['role']] ?? $o['role']) ?></td>
                                <td><?= e($o['company']) ?></td>
                                <td><?= e($o['platoon'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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

        <form action="<?= BASE_URL ?>/s1/import_officers.php" method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-primary" <?= $counts['ok'] === 0 ? 'disabled' : '' ?>>Save <?= $counts['ok'] ?> officer(s)</button>
        </form>
        <form action="<?= BASE_URL ?>/s1/import_officers.php" method="POST" style="display:inline;">
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
                    <th>Role</th>
                    <th>Company</th>
                    <th>Platoon</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($import['rows'] as $r): ?>
                    <tr>
                        <td><?= (int)$r['line'] ?></td>
                        <td><?= e($r['full_name']) ?></td>
                        <td><?= e($r['role_label']) ?></td>
                        <td><?= e($r['company']) ?></td>
                        <td><?= e($r['platoon'] !== '' ? $r['platoon'] : '-') ?></td>
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
