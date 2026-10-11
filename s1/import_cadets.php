<?php
// S1 Cadet CSV Import (Battalion S1 and Brigade S1 only)
// Imports cadets into the roster and enrolls them in the active term.
// Flow: upload CSV -> preview with validation -> confirm to save
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/s1_import_ui.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

const CADET_IMPORT_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const CADET_IMPORT_MAX_ROWS  = 500;
const CADET_DEFAULT_DESIGNATION = 'Cadet';

$active_term_id = (int)$pdo->query("SELECT id FROM terms WHERE is_active = 1 LIMIT 1")->fetchColumn();

// ---------- Helpers ----------

function cad_clean_cell(?string $v): string {
    $v = $v ?? '';
    if (!mb_check_encoding($v, 'UTF-8')) {
        // Excel on Windows often saves CSV as Windows-1252 (keeps ñ, é, etc.)
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return trim((string)preg_replace('/\s+/u', ' ', $v));
}

function cad_norm(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
}

// "Dela Cruz, Juan A." -> ['Dela Cruz', 'Juan', 'A.']. Returns null when there is no comma.
function cad_split_name(string $full): ?array {
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

// "M", "male" -> Male. "F", "female" -> Female.
function cad_norm_gender(string $g): ?string {
    $n = cad_norm($g);
    if (in_array($n, ['m', 'male'], true)) return 'Male';
    if (in_array($n, ['f', 'female'], true)) return 'Female';
    return null;
}

// Detect which CSV column holds which field by matching header keywords.
function cad_map_columns(array $headers): array {
    $aliases = [
        'full_name' => ['fullname', 'completename', 'cadetname', 'name'],
        'gender'    => ['gender', 'sex'],
        'program'   => ['program', 'course'],
        'company'   => ['company'],
        'platoon'   => ['platoon'],
    ];
    $normalized = array_map('cad_norm', $headers);
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

function cad_person_key(string $last, string $first, string $middle): string {
    return mb_strtolower($last) . '|' . mb_strtolower($first) . '|' . mb_strtolower(rtrim($middle, '.'));
}

// Active cadets already saved, indexed by person
function cad_load_existing(PDO $pdo): array {
    $people = [];
    $q = $pdo->query("SELECT last_name, first_name, middle_name FROM cadets WHERE status = 'active'");
    foreach ($q as $r) {
        $people[cad_person_key($r['last_name'], $r['first_name'], (string)$r['middle_name'])] = true;
    }
    return $people;
}

// Next free cadet code: C + year + 4-digit sequence (e.g. C20260001)
function cad_next_code(PDO $pdo): string {
    $prefix = 'C' . date('Y');
    $stmt = $pdo->prepare("SELECT MAX(cadet_code) FROM cadets WHERE cadet_code LIKE :p");
    $stmt->execute(['p' => $prefix . '%']);
    $max = (string)$stmt->fetchColumn();
    $n = ($max !== '' && ctype_digit(substr($max, strlen($prefix)))) ? (int)substr($max, strlen($prefix)) : 0;
    return $prefix . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

// ---------- CSV template download ----------
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cadet_import_template.csv"');
    echo "Full Name,Gender,Program,Company,Platoon\r\n";
    echo "\"Dela Cruz, Juan A.\",Male,BSIT,Alpha,1st Platoon\r\n";
    echo "\"Santos, Maria B.\",Female,BSN,,\r\n";
    exit;
}

// ---------- Download the rows that have errors, ready to fix and upload again ----------
function cad_csv_safe($v): string {
    $s = (string)$v;
    return ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) ? "'" . $s : $s;
}

if (isset($_GET['errors'])) {
    $imp = $_SESSION['cadet_import'] ?? null;
    if (!$imp) {
        set_flash('error', 'No import in progress. Please upload the file again.');
        redirect('s1/import_cadets.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cadet_import_rows_to_fix.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Full Name', 'Gender', 'Program', 'Company', 'Platoon', 'Reason']);
    foreach ($imp['rows'] as $r) {
        if ($r['status'] !== 'error') continue;
        fputcsv($out, array_map('cad_csv_safe', [$r['full_name'], $r['gender'], $r['program'], $r['company'], $r['platoon'], $r['note']]));
    }
    fclose($out);
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

        try {
            $pdo->beginTransaction();

            // Re-check against the database right before saving (data may have changed since the preview)
            $people = cad_load_existing($pdo);

            $ins = $pdo->prepare("
                INSERT INTO cadets (cadet_code, last_name, first_name, middle_name, gender, designation, program_id, status)
                VALUES (:code, :last_name, :first_name, :middle_name, :gender, :designation, :program_id, 'active')
            ");
            $enr = $pdo->prepare("
                INSERT INTO enrollments (cadet_id, term_id, company_id, platoon_id)
                VALUES (:cadet_id, :term_id, :company_id, :platoon_id)
            ");

            $saved = 0;
            $skipped = 0;
            $enrolled = 0;
            foreach ($import['rows'] as $row) {
                if ($row['status'] !== 'ok') continue;
                $pKey = cad_person_key($row['last_name'], $row['first_name'], $row['middle_name']);
                if (isset($people[$pKey])) { $skipped++; continue; }

                $ins->execute([
                    'code'        => cad_next_code($pdo),
                    'last_name'   => $row['last_name'],
                    'first_name'  => $row['first_name'],
                    'middle_name' => $row['middle_name'] !== '' ? $row['middle_name'] : null,
                    'gender'      => $row['gender'],
                    'designation' => CADET_DEFAULT_DESIGNATION,
                    'program_id'  => $row['program_id'],
                ]);
                $cadetId = (int)$pdo->lastInsertId();
                $people[$pKey] = true;
                $saved++;

                // Enroll in the active term when a company was given (platoon may stay empty = Unassigned)
                if ($active_term_id && $row['company_id'] > 0) {
                    $enr->execute([
                        'cadet_id'   => $cadetId,
                        'term_id'    => $active_term_id,
                        'company_id' => $row['company_id'],
                        'platoon_id' => $row['platoon_id'],
                    ]);
                    $enrolled++;
                }
            }

            log_audit($pdo, (int)$user['id'], 'import_cadets', 'cadets', null,
                "Imported {$saved} cadets ({$enrolled} enrolled) from " . $import['file']);

            $pdo->commit();
            unset($_SESSION['cadet_import']);

            $msg = "Import complete: {$saved} cadet(s) saved.";
            if ($skipped > 0) $msg .= " {$skipped} skipped because they were already on the roster.";
            set_flash('success', $msg);
            if ($saved > 0 && !$active_term_id) {
                set_flash('warning', 'No active term is set, so no company or platoon assignments were saved. An Administrator must activate a term.');
            }
            redirect('s1/roster.php');
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

        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'Please choose a CSV file to upload.');
            redirect('s1/import_cadets.php');
        }
        if ($f['size'] > CADET_IMPORT_MAX_BYTES) {
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
        $headers = array_map(fn($x) => cad_clean_cell($x), $headers);

        $map = cad_map_columns($headers);
        $missing = array_diff(['full_name', 'gender', 'program', 'company', 'platoon'], array_keys($map));
        if ($missing) {
            fclose($h);
            $labels = ['full_name' => 'Full Name', 'gender' => 'Gender', 'program' => 'Program', 'company' => 'Company', 'platoon' => 'Platoon'];
            $names = implode(', ', array_map(fn($m) => $labels[$m], $missing));
            set_flash('error', "Missing column(s): {$names}. Columns found in your file: " . implode(', ', $headers) . '.');
            redirect('s1/import_cadets.php');
        }

        // Program lookup by code or name (case-insensitive)
        $programLookup = [];
        foreach ($pdo->query("SELECT id, code, name FROM programs") as $p) {
            $entry = [(int)$p['id'], $p['code']];
            $programLookup[cad_norm($p['code'])] = $entry;
            if ($p['name'] !== null && $p['name'] !== '') $programLookup[cad_norm($p['name'])] = $entry;
        }

        // Companies and platoons lookups (case-insensitive)
        $companyLookup = []; // normalized name (with or without "company") => [id, name]
        foreach ($pdo->query("SELECT id, name FROM companies") as $c) {
            $n = cad_norm($c['name']);
            $companyLookup[$n] = [(int)$c['id'], $c['name']];
            $companyLookup[$n . 'company'] = [(int)$c['id'], $c['name']];
        }
        $platoonLookup = []; // company_id => normalized platoon name => [id, name]
        foreach ($pdo->query("SELECT id, company_id, name FROM platoons") as $p) {
            $n = cad_norm($p['name']);
            $short = str_replace('platoon', '', $n); // "1stplatoon" -> "1st"
            $entry = [(int)$p['id'], $p['name']];
            $platoonLookup[(int)$p['company_id']][$n] = $entry;
            if ($short !== '') {
                $platoonLookup[(int)$p['company_id']][$short] = $entry;
                $digits = preg_replace('/\D/', '', $short);
                if ($digits !== '' && !isset($platoonLookup[(int)$p['company_id']][$digits])) {
                    $platoonLookup[(int)$p['company_id']][$digits] = $entry;
                }
            }
        }

        $existingPeople = cad_load_existing($pdo);

        $rows = [];
        $seenPeople = [];
        $line = 1;
        $tooMany = false;
        while (($data = fgetcsv($h, 0, $delim)) !== false) {
            $line++;
            if (count($data) === 1 && trim((string)$data[0]) === '') continue; // blank line

            $name    = cad_clean_cell($data[$map['full_name']] ?? '');
            $genRaw  = cad_clean_cell($data[$map['gender']] ?? '');
            $progRaw = cad_clean_cell($data[$map['program']] ?? '');
            $compRaw = cad_clean_cell($data[$map['company']] ?? '');
            $platRaw = cad_clean_cell($data[$map['platoon']] ?? '');
            if ($name === '' && $genRaw === '' && $progRaw === '' && $compRaw === '' && $platRaw === '') continue;

            if (count($rows) >= CADET_IMPORT_MAX_ROWS) { $tooMany = true; break; }

            $errors = [];

            // Name
            $parts = ['', '', ''];
            if ($name === '') $errors[] = 'Full name is required';
            elseif (mb_strlen($name) > 150) $errors[] = 'Full name is too long';
            else {
                $split = cad_split_name($name);
                if ($split === null) $errors[] = 'Use the format "Last name, First name M."';
                else $parts = $split;
            }

            // Gender
            $gender = null;
            if ($genRaw === '') $errors[] = 'Gender is required';
            else {
                $gender = cad_norm_gender($genRaw);
                if ($gender === null) $errors[] = 'Gender must be Male or Female';
            }

            // Program
            $programId = 0;
            $programCode = $progRaw;
            if ($progRaw === '') $errors[] = 'Program is required';
            else {
                $pr = $programLookup[cad_norm($progRaw)] ?? null;
                if ($pr === null) $errors[] = 'Unknown program "' . $progRaw . '"';
                else { $programId = $pr[0]; $programCode = $pr[1]; }
            }

            // Company (optional) and Platoon (optional, needs a company)
            $companyId = 0;
            $companyName = $compRaw;
            if ($compRaw !== '') {
                $c = $companyLookup[cad_norm($compRaw)] ?? null;
                if ($c === null) $errors[] = 'Unknown company "' . $compRaw . '"';
                else { $companyId = $c[0]; $companyName = $c[1]; }
            }

            $platoonId = null;
            $platoonName = $platRaw;
            if ($platRaw !== '') {
                if ($compRaw === '') $errors[] = 'Company is required when a Platoon is given';
                elseif ($companyId > 0) {
                    $p = $platoonLookup[$companyId][cad_norm($platRaw)] ?? null;
                    if ($p === null) $errors[] = 'Unknown platoon "' . $platRaw . '" in ' . $companyName . ' Company';
                    else { $platoonId = $p[0]; $platoonName = $p[1]; }
                }
            }

            $status = 'ok';
            $note = '';
            if ($errors) {
                $status = 'error';
                $note = implode('; ', $errors);
            } else {
                $pKey = cad_person_key($parts[0], $parts[1], $parts[2]);
                if (isset($existingPeople[$pKey])) {
                    $status = 'duplicate';
                    $note = 'Already an active cadet';
                } elseif (isset($seenPeople[$pKey])) {
                    $status = 'duplicate';
                    $note = 'Repeated in this file';
                } else {
                    $seenPeople[$pKey] = true;
                }
            }

            $rows[] = [
                'line'        => $line,
                'full_name'   => $name,
                'last_name'   => $parts[0],
                'first_name'  => $parts[1],
                'middle_name' => $parts[2],
                'gender'      => $gender ?? $genRaw,
                'program_id'  => $programId,
                'program'     => $programCode,
                'company_id'  => $companyId,
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
            redirect('s1/import_cadets.php');
        }

        $_SESSION['cadet_import'] = ['file' => $f['name'], 'rows' => $rows];
        if ($tooMany) {
            set_flash('warning', 'Only the first ' . CADET_IMPORT_MAX_ROWS . ' rows were read. Split the file and import the rest separately.');
        }
        redirect('s1/import_cadets.php');
    }

    redirect('s1/import_cadets.php');
}

// ---------- Page data ----------
$import = $_SESSION['cadet_import'] ?? null;
$counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0];
$placed_n = 0;       // ready rows that get a company (and so an enrollment)
$unassigned_n = 0;   // ready rows that will show as Unassigned on the roster (no platoon)
if ($import) {
    foreach ($import['rows'] as $r) {
        $counts[$r['status']]++;
        if ($r['status'] === 'ok') {
            if ($r['company_id'] > 0) $placed_n++;
            if ($r['platoon_id'] === null) $unassigned_n++;
        }
    }
}
$total_rows = $import ? count($import['rows']) : 0;

$cadet_total = (int)$pdo->query("SELECT COUNT(*) FROM cadets WHERE status = 'active'")->fetchColumn();

// Accepted values, shown on the upload screen so the file can be prepared correctly
$ref_programs  = $pdo->query("SELECT code, name FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$ref_companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$ref_platoons  = [];
foreach ($pdo->query("SELECT company_id, name FROM platoons ORDER BY name") as $p) {
    $ref_platoons[(int)$p['company_id']][] = $p['name'];
}

// What went wrong, in plain words, with how many rows are affected
$fix_hints = [];
if ($import && $counts['error'] > 0) {
    $rules = [
        ['Use the format',        'Write names as "Last name, First name M." with a comma after the last name.'],
        ['Full name is required', 'Fill in the Full Name cell.'],
        ['Gender',                'Use Male or Female (M and F also work).'],
        ['Program',               'Use a program code from the list on the upload screen, for example BSIT.'],
        ['Unknown company',       'Spell the company as listed on the upload screen, or leave it empty.'],
        ['Unknown platoon',       'Use a platoon that belongs to that company, for example 1st Platoon.'],
        ['Company is required',   'Add the company for rows that have a platoon, or clear the platoon.'],
    ];
    foreach ($rules as [$needle, $hint]) {
        $n = 0;
        foreach ($import['rows'] as $r) {
            if ($r['status'] === 'error' && strpos($r['note'], $needle) !== false) $n++;
        }
        if ($n > 0) $fix_hints[] = [$n, $hint];
    }
}

$self = BASE_URL . '/s1/import_cadets.php';
$page_title = 'Import Cadets';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

<div class="s1-rv-head">
    <div>
        <h1>Import Cadets</h1>
        <p>Add many cadets to the roster at once from a CSV file. Active cadets on file: <strong><?= $cadet_total ?></strong>.</p>
    </div>
    <div class="s1-rv-nav">
        <a href="<?= BASE_URL ?>/s1/roster.php" class="btn btn-secondary btn-sm">&larr; Back to cadet roster</a>
    </div>
</div>

<?php if (!$active_term_id): ?>
    <div class="s1-alert s1-alert-warn" style="margin-bottom: 16px;">
        <div class="s1-alert-body"><strong>No active term is set.</strong> Cadets will be saved without a company or platoon until an Administrator activates a term.</div>
    </div>
<?php endif; ?>

<?= s1_import_stepper($import ? 2 : 1) ?>

<?php if (!$import): ?>

    <div class="s1-rv-layout">
        <div class="s1-col">
            <div class="s1-card">
                <h3>Upload your CSV file</h3>
                <?= s1_import_dropzone($self, CADET_IMPORT_MAX_BYTES, '.csv only, up to 2 MB and ' . CADET_IMPORT_MAX_ROWS . ' rows') ?>
            </div>

            <div class="s1-card">
                <h3>What your file should look like</h3>
                <p class="s1-sub">Keep all five column headers, even when a column is empty. Extra columns such as Timestamp are ignored.</p>
                <div class="table-responsive" style="margin-bottom: 0;">
                    <table class="s1-sample">
                        <thead>
                            <tr>
                                <th>Full Name<span class="s1-req s1-req-must">Required</span></th>
                                <th>Gender<span class="s1-req s1-req-must">Required</span></th>
                                <th>Program<span class="s1-req s1-req-must">Required</span></th>
                                <th>Company<span class="s1-req s1-req-opt">Optional</span></th>
                                <th>Platoon<span class="s1-req s1-req-opt">Optional</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Dela Cruz, Juan A.</td><td>Male</td><td>BSIT</td><td>Alpha</td><td>1st Platoon</td></tr>
                            <tr><td>Santos, Maria B.</td><td>Female</td><td>BSN</td><td class="is-blank">empty</td><td class="is-blank">empty</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <aside class="s1-rv-side s1-side-after" aria-label="Import guide">
            <div class="s1-card">
                <h3>Before you upload</h3>
                <ul class="s1-checks">
                    <li class="info"><?= s1_import_icon('info') ?><span>Write each name as <strong>Last name, First name M.</strong></span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>Gender is <strong>Male</strong> or <strong>Female</strong>.</span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>Use a <strong>program code</strong> from the list below.</span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>No platoon yet? Leave it empty. The cadet shows as <strong>Unassigned</strong> and you can assign later. A platoon needs its company.</span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>A cadet code is generated for every cadet saved.</span></li>
                </ul>
            </div>
            <div class="s1-card">
                <h3>Accepted values</h3>
                <div class="s1-vals-label">Programs</div>
                <div class="s1-vals">
                    <?php foreach ($ref_programs as $p): ?><span class="s1-val" title="<?= e((string)$p['name']) ?>"><?= e($p['code']) ?></span><?php endforeach; ?>
                    <?php if (!$ref_programs): ?><span class="s1-muted">No programs set up yet</span><?php endif; ?>
                </div>
                <div class="s1-vals-label">Companies and platoons</div>
                <ul class="s1-slots">
                    <?php foreach ($ref_companies as $c): ?>
                        <li>
                            <strong><?= e($c['name']) ?></strong>
                            <div class="s1-vals">
                                <?php foreach ($ref_platoons[(int)$c['id']] ?? [] as $pn): ?><span class="s1-val s1-val-gold"><?= e($pn) ?></span><?php endforeach; ?>
                                <?php if (empty($ref_platoons[(int)$c['id']])): ?><span class="s1-muted">No platoons</span><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$ref_companies): ?><li class="s1-muted">No companies set up yet</li><?php endif; ?>
                </ul>
            </div>
        </aside>
    </div>

<?php else: ?>

    <div class="s1-rv-layout">
        <section data-s1-review aria-label="Rows in your file">
            <?php if ($counts['ok'] === 0): ?>
                <div class="s1-alert s1-alert-bad" style="margin-bottom: 14px;">
                    <div class="s1-alert-body"><strong>Nothing can be saved from this file.</strong> Every row has an error or is already on the roster. Fix the file and upload it again.</div>
                </div>
            <?php elseif ($counts['error'] > 0): ?>
                <div class="s1-alert s1-alert-warn" style="margin-bottom: 14px;">
                    <div class="s1-alert-body"><strong><?= $counts['error'] ?> row(s) have errors and will be skipped.</strong> Fix them in your file and upload again, or save only the <?= $counts['ok'] ?> valid row(s).</div>
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-filter-mark="error" aria-pressed="false">Show errors only</button>
                </div>
            <?php else: ?>
                <div class="s1-alert s1-alert-ok" style="margin-bottom: 14px;">
                    <div class="s1-alert-body"><strong>Every row looks good.</strong> Check the list, then save.</div>
                </div>
            <?php endif; ?>

            <div class="s1-tiles">
                <button type="button" class="s1-tile s1-tile-all" data-s1-filter-mark="all" aria-pressed="true"><span>All rows</span><strong><?= $total_rows ?></strong></button>
                <button type="button" class="s1-tile s1-tile-P" data-s1-filter-mark="ok" aria-pressed="false"><span>Ready to save</span><strong><?= $counts['ok'] ?></strong></button>
                <button type="button" class="s1-tile s1-tile-L" data-s1-filter-mark="duplicate" aria-pressed="false"><span>Duplicates</span><strong><?= $counts['duplicate'] ?></strong></button>
                <button type="button" class="s1-tile s1-tile-A" data-s1-filter-mark="error" aria-pressed="false"><span>Errors</span><strong><?= $counts['error'] ?></strong></button>
            </div>

            <div class="s1-rv-tools">
                <div class="s1-search">
                    <svg class="s1-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="search" class="form-control" placeholder="Find a name" data-s1-marksearch aria-label="Find a name in this file" autocomplete="off">
                </div>
                <span class="s1-rv-count">Showing <strong data-s1-markshown><?= $total_rows ?></strong> of <?= $total_rows ?> &middot; File: <?= e($import['file']) ?></span>
            </div>

            <div class="s1-rv-wrap">
                <table class="s1-table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Full Name</th>
                            <th>Gender</th>
                            <th>Program</th>
                            <th>Company</th>
                            <th>Platoon</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($import['rows'] as $r): ?>
                            <tr data-mark="<?= e($r['status']) ?>" data-name="<?= e(mb_strtolower($r['full_name'])) ?>"
                                class="<?= $r['status'] === 'error' ? 's1-row-err' : ($r['status'] === 'duplicate' ? 's1-row-dup' : '') ?>">
                                <td class="s1-cadet-code"><?= (int)$r['line'] ?></td>
                                <td class="s1-cadet-name"><?= e($r['full_name']) ?></td>
                                <td><?= e($r['gender'] !== '' ? $r['gender'] : '-') ?></td>
                                <td><?= e($r['program'] !== '' ? $r['program'] : '-') ?></td>
                                <td><?= e($r['company'] !== '' ? $r['company'] : '-') ?></td>
                                <td><?= e($r['platoon'] !== '' ? $r['platoon'] : '-') ?></td>
                                <td>
                                    <?= s1_import_pill($r['status']) ?>
                                    <?php if ($r['note'] !== ''): ?><div class="s1-res-note <?= $r['status'] === 'error' ? 'is-bad' : '' ?>"><?= e($r['note']) ?></div><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="s1-rv-empty" data-s1-markempty hidden>No row matches this view. Clear the search or pick another tile.</div>
            </div>
        </section>

        <aside class="s1-rv-side" aria-label="Save or cancel">
            <div class="s1-card s1-decide">
                <h3>Ready to save?</h3>
                <ul class="s1-checks">
                    <li class="<?= $counts['ok'] > 0 ? 'ok' : 'bad is-bad' ?>"><?= s1_import_icon($counts['ok'] > 0 ? 'ok' : 'bad') ?><span><strong><?= $counts['ok'] ?></strong> cadet(s) will be saved</span></li>
                    <?php if ($counts['ok'] > 0): ?>
                        <li class="info"><?= s1_import_icon('info') ?><span><?= $placed_n ?> placed in a company, <?= $unassigned_n ?> will show as Unassigned on the roster</span></li>
                    <?php endif; ?>
                    <?php if ($counts['duplicate'] > 0): ?>
                        <li class="info"><?= s1_import_icon('info') ?><span><?= $counts['duplicate'] ?> duplicate(s) skipped</span></li>
                    <?php endif; ?>
                    <?php if ($counts['error'] > 0): ?>
                        <li class="bad is-bad"><?= s1_import_icon('bad') ?><span><?= $counts['error'] ?> row(s) with errors skipped</span></li>
                    <?php endif; ?>
                </ul>

                <form action="<?= e($self) ?>" method="POST"
                      data-s1-confirm="Save <?= (int)$counts['ok'] ?> cadet(s) to the roster? Rows with errors and duplicates are skipped."
                      data-s1-confirm-title="Save imported cadets" data-s1-confirm-ok="Save">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-primary btn-block" <?= $counts['ok'] === 0 ? 'disabled' : '' ?>>Save <?= $counts['ok'] ?> cadet(s)</button>
                </form>
                <form action="<?= e($self) ?>" method="POST" style="margin-top: 8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-secondary btn-block">Cancel and start over</button>
                </form>
                <?php if ($counts['error'] > 0): ?>
                    <hr>
                    <a href="<?= e($self) ?>?errors=1" class="btn btn-secondary btn-block">Download rows to fix (CSV)</a>
                    <p class="help">Fix the Reason column's problems in that file, then upload it as a new import.</p>
                <?php endif; ?>
            </div>

            <?php if ($fix_hints): ?>
                <div class="s1-card">
                    <h3>How to fix the errors</h3>
                    <ul class="s1-fixlist">
                        <?php foreach ($fix_hints as [$n, $hint]): ?>
                            <li><b><?= $n ?></b><span><?= e($hint) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </aside>
    </div>

<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
