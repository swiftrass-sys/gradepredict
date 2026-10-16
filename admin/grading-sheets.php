<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/registrar.php';
$user = require_role('admin');
$active = 'sheets';
$pdo = db();
$message = flash_get();

$f = [
    'year' => trim((string) ($_GET['year'] ?? '')) ?: current_school_year(),
    'program_id' => (int) ($_GET['program_id'] ?? 0),
    'year_level' => (int) ($_GET['year_level'] ?? 0),
    'semester' => trim((string) ($_GET['semester'] ?? '')),
    'subject_id' => (int) ($_GET['subject_id'] ?? 0),
];
$view = in_array($_GET['view'] ?? '', ['subject', 'honors'], true) ? $_GET['view'] : 'consolidated';
$cfg = honor_config($pdo);

// ---- Save honor rules ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rules'])) {
    verify_csrf();
    $v = fn($k) => round((float) ($_POST[$k] ?? 0), 2);
    [$summa, $magna, $cum, $low] = [$v('summa'), $v('magna'), $v('cum'), $v('lowest')];
    if (!($summa >= 1.0 && $summa <= $magna && $magna <= $cum && $cum <= $low && $low <= 3.0)) {
        flash_set('error', 'The limits must run from best to worst: Summa, then Magna, then Cum Laude, then the lowest allowed grade, all between 1.00 and 3.00.');
    } else {
        save_setting($pdo, 'honors_summa_max', number_format($summa, 2));
        save_setting($pdo, 'honors_magna_max', number_format($magna, 2));
        save_setting($pdo, 'honors_cum_max', number_format($cum, 2));
        save_setting($pdo, 'honors_lowest_grade', number_format($low, 2));
        log_activity((int) $user['id'], 'Honor Rules Updated', "Summa $summa, Magna $magna, Cum Laude $cum, lowest grade $low");
        flash_set('success', 'Honor rules saved.');
    }
    header('Location: grading-sheets.php?' . http_build_query(['view' => 'honors'] + array_filter($f)));
    exit;
}

$programs = $pdo->query("SELECT id, code, name FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$semesters = $pdo->query("SELECT DISTINCT semester FROM curriculum_subjects ORDER BY semester")->fetchAll(PDO::FETCH_COLUMN);
$subjStmt = $pdo->prepare("SELECT DISTINCT s.id, s.name, s.units FROM student_subjects ss JOIN subjects s ON s.id = ss.subject_id WHERE ss.school_year = ? ORDER BY s.name");
$subjStmt->execute([$f['year']]);
$subjectOptions = $subjStmt->fetchAll(PDO::FETCH_ASSOC);

$programLabel = 'All programs';
foreach ($programs as $p) { if ((int) $p['id'] === $f['program_id']) { $programLabel = $p['code']; } }
$subjectFilterName = '';
foreach ($subjectOptions as $s) { if ((int) $s['id'] === $f['subject_id']) { $subjectFilterName = $s['name']; } }
$ctx = ['year' => $f['year'], 'program' => $programLabel, 'year_level' => $f['year_level'] ? 'Year ' . $f['year_level'] : 'All years', 'semester' => $f['semester'] ?: 'All semesters'];

$rows = registrar_rows($pdo, $f);
$students = consolidate_students($rows, $cfg);
$subjects = [];
foreach ($rows as $r) { $subjects[(int) $r['subject_id']] = ['name' => $r['subject'], 'units' => $r['units']]; }
uasort($subjects, fn($a, $b) => strcmp($a['name'], $b['name']));

$bySubjectRows = [];
foreach ($rows as $r) { $bySubjectRows[(int) $r['subject_id']][] = $r; }

$sheetConsolidated = sheet_consolidated($students, $subjects, $ctx);
$sheetHonors = sheet_honors($students, $cfg, $ctx);
$subjectSheets = [];
foreach ($subjects as $sid => $sub) { $subjectSheets[$sid] = sheet_subject($bySubjectRows[$sid] ?? [], $sub, $ctx); }

// ---- Downloads ----
if (isset($_GET['download'])) {
    $what = $_GET['download'];
    $stamp = preg_replace('/[^0-9A-Za-z-]/', '', $f['year']) . '_' . date('Ymd');
    if ($what === 'xlsx') {
        $sheets = ['Consolidated' => sheet_to_rows($sheetConsolidated)[0], 'Honors' => sheet_to_rows($sheetHonors)[0]];
        $bold = ['Consolidated' => [0, sheet_to_rows($sheetConsolidated)[1]], 'Honors' => [0, sheet_to_rows($sheetHonors)[1]]];
        foreach ($subjectSheets as $sid => $sh) {
            $key = $subjects[$sid]['name'];
            [$rws, $hi] = sheet_to_rows($sh);
            $sheets[$key] = $rws;
            $bold[$key] = [0, $hi];
        }
        // sheet names are made unique and trimmed inside xlsx_build, so remap bold rows by position
        $boldByName = [];
        $names = array_keys($sheets);
        foreach ($names as $nm) { $boldByName[$nm] = $bold[$nm]; }
        try {
            $bin = xlsx_build($sheets, $boldByName);
        } catch (RuntimeException $e) {
            flash_set('error', 'Excel export needs the PHP zip extension. Use the CSV download instead.');
            header('Location: grading-sheets.php?' . http_build_query(array_filter($f) + ['view' => $view]));
            exit;
        }
        log_activity((int) $user['id'], 'Grading Sheets Downloaded', 'Excel workbook, ' . count($sheets) . ' sheets');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="grading-sheets_' . $stamp . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    if ($what === 'csv') {
        if ($view === 'subject') {
            $parts = $f['subject_id'] && isset($subjectSheets[$f['subject_id']]) ? [$subjectSheets[$f['subject_id']]] : array_values($subjectSheets);
        } else {
            $parts = [$view === 'honors' ? $sheetHonors : $sheetConsolidated];
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="grading-sheet-' . $view . '_' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($parts as $pi => $sh) {
            if ($pi > 0) { fputcsv($out, []); fputcsv($out, []); }
            foreach (sheet_to_rows($sh)[0] as $row) {
                fputcsv($out, array_map(fn($c) => is_string($c) ? csv_safe($c) : $c, $row));
            }
        }
        log_activity((int) $user['id'], 'Grading Sheets Downloaded', 'CSV, ' . $view . ' view');
        exit;
    }
}

$qs = fn(array $over = []) => http_build_query(array_filter(array_merge($f, ['view' => $view], $over), fn($v) => $v !== '' && $v !== 0 && $v !== null));
$qualifiedCount = count(array_filter($students, fn($s) => $s['qualified']));

/** Renders one sheet as a table. */
function render_sheet_table(array $sheet, string $class = ''): string
{
    $h = '<div class="table-scroll"><table class="table-compact sheet-table ' . $class . '"><thead><tr>';
    foreach ($sheet['head'] as $c) { $h .= '<th>' . e((string) $c) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    if (!$sheet['rows']) { $h .= '<tr><td colspan="' . count($sheet['head']) . '" class="label-quiet">No students match these filters.</td></tr>'; }
    foreach ($sheet['rows'] as $row) {
        $h .= '<tr>';
        foreach ($row as $ci => $c) {
            $txt = is_float($c) ? rtrim(rtrim(number_format($c, 2), '0'), '.') : (string) $c;
            if ($c === 'Qualified') { $txt = '<span class="badge badge-excellent">Qualified</span>'; }
            elseif ($c === 'Not qualified') { $txt = '<span class="badge badge-poor">Not qualified</span>'; }
            elseif ($c === 'Passed') { $txt = '<span class="badge badge-excellent">Passed</span>'; }
            elseif ($c === 'Failed') { $txt = '<span class="badge badge-poor">Failed</span>'; }
            else { $txt = e($txt); }
            $h .= '<td>' . $txt . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grading sheets — GradePredict AI Admin</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
    <div class="main">
        <?php $showSearch = true; include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head no-print">
                <div><h2>Grading sheets</h2><p>Per-subject grading sheets, consolidated grades with GWA, and the honors outlook.</p></div>
                <div class="btn-row" style="margin:0;">
                    <a class="btn btn-primary" href="?<?= e($qs(['download' => 'xlsx'])) ?>">Download Excel workbook</a>
                    <a class="btn btn-outline" href="?<?= e($qs(['download' => 'csv'])) ?>">Download CSV (this view)</a>
                    <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
                </div>
            </div>
            <?= render_flash($message) ?>

            <form method="get" class="card filter-bar no-print" aria-label="Filters">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <div class="form-group"><label for="fy">School year</label><input class="input" id="fy" name="year" value="<?= e($f['year']) ?>"></div>
                <div class="form-group"><label for="fp">Program</label>
                    <select class="input" id="fp" name="program_id"><option value="">All programs</option>
                        <?php foreach ($programs as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $f['program_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label for="fl">Year level</label>
                    <select class="input" id="fl" name="year_level"><option value="">All years</option>
                        <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?= $y ?>" <?= $f['year_level'] === $y ? 'selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
                    </select></div>
                <div class="form-group"><label for="fs">Semester</label>
                    <select class="input" id="fs" name="semester"><option value="">All semesters</option>
                        <?php foreach ($semesters as $sem): ?><option <?= $f['semester'] === $sem ? 'selected' : '' ?>><?= e($sem) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label for="fj">Subject</label>
                    <select class="input" id="fj" name="subject_id"><option value="">All subjects</option>
                        <?php foreach ($subjectOptions as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $f['subject_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group filter-actions"><button type="submit" class="btn btn-primary">Apply</button><a class="btn btn-outline" href="grading-sheets.php?view=<?= e($view) ?>">Clear</a></div>
            </form>

            <p class="label-quiet no-print" style="margin:-4px 0 14px;">Downloads follow the filters above. With no filters you get one workbook with the consolidated sheet, the honors sheet and a sheet for every subject.</p>

            <div class="tabs no-print" role="tablist" aria-label="Sheet type">
                <a role="tab" class="tab <?= $view === 'consolidated' ? 'active' : '' ?>" aria-selected="<?= $view === 'consolidated' ? 'true' : 'false' ?>" href="?<?= e($qs(['view' => 'consolidated'])) ?>">Consolidated and GWA</a>
                <a role="tab" class="tab <?= $view === 'subject' ? 'active' : '' ?>" aria-selected="<?= $view === 'subject' ? 'true' : 'false' ?>" href="?<?= e($qs(['view' => 'subject'])) ?>">Per subject</a>
                <a role="tab" class="tab <?= $view === 'honors' ? 'active' : '' ?>" aria-selected="<?= $view === 'honors' ? 'true' : 'false' ?>" href="?<?= e($qs(['view' => 'honors'])) ?>">Honors outlook</a>
            </div>

            <div class="print-only sheet-head">
                <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="" width="56" height="56">
                <div><strong>Colegio de Santa Rita de San Carlos, Inc.</strong><br>Grading sheet &middot; School year <?= e($f['year']) ?><br><?= e($ctx['program']) ?> &middot; <?= e($ctx['year_level']) ?> &middot; <?= e($ctx['semester']) ?></div>
            </div>

            <?php if (!$rows): ?>
                <div class="card empty-state-card">
                    <div class="empty-ic-wrap"><?= icon('file', 24) ?></div>
                    <h4>No students match these filters</h4>
                    <p>Sheets list approved students who are enrolled in subjects for <?= e($f['year']) ?>. Try clearing a filter or check the school year.</p>
                </div>
            <?php elseif ($view === 'consolidated'): ?>
                <div class="card">
                    <div class="card-head"><h3>Consolidated grades and GWA</h3><span class="label-quiet no-print"><?= count($students) ?> students, <?= count($subjects) ?> subjects</span></div>
                    <?= render_sheet_table($sheetConsolidated) ?>
                    <?php if (count($subjects) > 8 && !$f['program_id']): ?><p class="label-quiet sheet-note">This list mixes programs, so many subject columns are empty. Choose a program and year level for a compact sheet.</p><?php endif; ?>
                    <p class="label-quiet sheet-note"><?= e($sheetConsolidated['foot'][0][0]) ?> Grades marked Predicted are projections; the Basis column shows Final, Predicted or Mixed.</p>
                </div>

            <?php elseif ($view === 'subject'): ?>
                <?php if (!$f['subject_id']): ?>
                    <div class="card"><h3>Choose a subject</h3>
                        <p class="label-quiet">Pick a subject in the filter above to see its grading sheet, or download the CSV or Excel file to get every subject at once.</p>
                        <?php if ($subjects): ?><div class="choice-row" style="margin-top:12px;">
                            <?php foreach ($subjects as $sid => $sub): ?><a class="btn btn-outline btn-sm" href="?<?= e($qs(['subject_id' => $sid])) ?>"><?= e($sub['name']) ?></a><?php endforeach; ?>
                        </div><?php endif; ?>
                    </div>
                <?php else: foreach ($subjectSheets as $sid => $sh): if ($sid !== $f['subject_id']) { continue; } ?>
                    <div class="card sheet-block">
                        <div class="card-head"><h3><?= e($sh['title']) ?></h3></div>
                        <p class="label-quiet" style="margin:0 0 12px;"><?= e($sh['meta'][1]) ?></p>
                        <?= render_sheet_table($sh) ?>
                        <p class="sheet-note"><strong><?= e($sh['foot'][0][0]) ?></strong></p>
                        <div class="sign-row print-only"><div>Prepared by (Instructor)</div><div>Checked by (Registrar)</div><div>Approved by (Dean)</div></div>
                    </div>
                <?php endforeach; endif; ?>

            <?php else: ?>
                <div class="grid grid-3 no-print">
                    <div class="card stat"><div class="stat-label">Students in this list</div><div class="stat-value"><?= count($students) ?></div></div>
                    <div class="card stat"><div class="stat-label">Qualified for honors</div><div class="stat-value"><?= $qualifiedCount ?></div></div>
                    <div class="card stat"><div class="stat-label">Not qualified</div><div class="stat-value"><?= count($students) - $qualifiedCount ?></div></div>
                </div>
                <div class="card" style="margin-top:18px;">
                    <div class="card-head"><h3>Honors outlook</h3></div>
                    <?= render_sheet_table($sheetHonors) ?>
                    <p class="label-quiet sheet-note"><?= e($sheetHonors['foot'][0][0]) ?></p>
                </div>
                <form method="post" class="card no-print" style="margin-top:18px;">
                    <?= csrf_field() ?>
                    <h3>Honor rules</h3>
                    <p class="label-quiet" style="margin:0 0 12px;">Set these to what your student handbook says. The outlook above recalculates immediately. Lower numbers are better (1.00 is the highest grade).</p>
                    <div class="grid grid-4">
                        <div class="form-group"><label for="r1">Summa Cum Laude, GWA up to</label><input class="input" id="r1" type="number" step="0.01" min="1" max="3" name="summa" value="<?= e(number_format($cfg['summa'], 2)) ?>"></div>
                        <div class="form-group"><label for="r2">Magna Cum Laude, up to</label><input class="input" id="r2" type="number" step="0.01" min="1" max="3" name="magna" value="<?= e(number_format($cfg['magna'], 2)) ?>"></div>
                        <div class="form-group"><label for="r3">Cum Laude, up to</label><input class="input" id="r3" type="number" step="0.01" min="1" max="3" name="cum" value="<?= e(number_format($cfg['cum'], 2)) ?>"></div>
                        <div class="form-group"><label for="r4">Lowest grade allowed</label><input class="input" id="r4" type="number" step="0.01" min="1" max="3" name="lowest" value="<?= e(number_format($cfg['lowest'], 2)) ?>"></div>
                    </div>
                    <button type="submit" name="save_rules" value="1" class="btn btn-primary">Save rules</button>
                </form>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
