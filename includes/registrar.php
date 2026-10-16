<?php
/**
 * Registrar helpers: grade equivalents, GWA, honors, the data loader for grading sheets, and a small .xlsx writer.
 * Loaded by admin/grading-sheets.php (and the student profile for the honors outlook).
 */

/* ---------------- Settings (portable: no ON DUPLICATE KEY) ---------------- */

function setting_value(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null || $v === '') ? $default : (string) $v;
}

function save_setting(PDO $pdo, string $key, string $value): void
{
    $upd = $pdo->prepare("UPDATE settings SET value = ? WHERE setting_key = ?");
    $upd->execute([$value, $key]);
    if ($upd->rowCount() === 0) {
        $chk = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ?");
        $chk->execute([$key]);
        if (!$chk->fetchColumn()) {
            $pdo->prepare("INSERT INTO settings (setting_key, value) VALUES (?, ?)")->execute([$key, $value]);
        }
    }
}

/** Honor rules. Defaults follow common Philippine college practice; the registrar should set them to the school handbook. */
function honor_config(PDO $pdo): array
{
    return [
        'summa' => (float) setting_value($pdo, 'honors_summa_max', '1.20'),   // GWA up to this = Summa Cum Laude
        'magna' => (float) setting_value($pdo, 'honors_magna_max', '1.45'),
        'cum'   => (float) setting_value($pdo, 'honors_cum_max', '1.75'),
        'lowest' => (float) setting_value($pdo, 'honors_lowest_grade', '2.50'), // no grade worse than this
    ];
}

/* ---------------- Grades ---------------- */

/** Equivalent as text, e.g. "1.75" (5.00 for failing). */
function gp_text(float $gp): string
{
    return number_format($gp, 2);
}

function grade_remark(float $gp): string
{
    return $gp > 3.00 ? 'Failed' : 'Passed';
}

/** General Weighted Average: sum(equivalent x units) / sum(units). Null when there are no graded subjects. */
function compute_gwa(array $items): ?float
{
    $num = 0.0; $units = 0;
    foreach ($items as $it) {
        $num += (float) $it['gp'] * (int) $it['units'];
        $units += (int) $it['units'];
    }
    return $units > 0 ? round($num / $units, 2) : null;
}

/**
 * Honor designation for one student.
 * $items: graded subjects [['gp' => float, 'units' => int, 'subject' => string]], $missing: enrolled subjects that have no grade yet.
 * Returns ['qualified' => bool, 'honor' => string, 'gwa' => ?float, 'reasons' => string[]].
 */
function honor_designation(array $items, int $missing, array $cfg): array
{
    $gwa = compute_gwa($items);
    $reasons = [];
    if ($gwa === null) {
        return ['qualified' => false, 'honor' => 'None', 'gwa' => null, 'reasons' => ['No grades or predictions yet']];
    }
    if ($missing > 0) {
        $reasons[] = $missing . ' subject' . ($missing === 1 ? '' : 's') . ' without a prediction or final grade';
    }
    foreach ($items as $it) {
        if ((float) $it['gp'] > 3.00) {
            $reasons[] = 'Failing grade in ' . $it['subject'];
        } elseif ((float) $it['gp'] > $cfg['lowest'] + 1e-9) {
            $reasons[] = 'Grade of ' . gp_text((float) $it['gp']) . ' in ' . $it['subject'] . ' is worse than ' . gp_text($cfg['lowest']);
        }
    }
    if ($gwa > $cfg['cum'] + 1e-9) {
        $reasons[] = 'GWA ' . gp_text($gwa) . ' is above ' . gp_text($cfg['cum']);
    }
    if ($reasons) {
        return ['qualified' => false, 'honor' => 'None', 'gwa' => $gwa, 'reasons' => $reasons];
    }
    $honor = $gwa <= $cfg['summa'] + 1e-9 ? 'Summa Cum Laude' : ($gwa <= $cfg['magna'] + 1e-9 ? 'Magna Cum Laude' : 'Cum Laude');
    return ['qualified' => true, 'honor' => $honor, 'gwa' => $gwa, 'reasons' => []];
}

/* ---------------- Loading the sheet data ---------------- */

/**
 * One row per student-subject for the filters:
 * year (school year, required), program_id, year_level, semester (matches the curriculum), subject_id, student_id.
 * 'grade' is the recorded final grade when there is one, otherwise the latest prediction; 'basis' says which.
 */
function registrar_rows(PDO $pdo, array $f): array
{
    $sql = "
        SELECT u.id AS student_id, u.full_name, u.student_no, u.year_level, u.program_id, p.code AS program_code,
               ss.subject_id, s.name AS subject, s.units,
               gi.past_grades, gi.attendance, gi.assignments, gi.quizzes, gi.participation,
               fg.final_grade,
               (SELECT pr.predicted_grade FROM predictions pr WHERE pr.student_id = u.id AND pr.subject_id = ss.subject_id AND pr.school_year = ss.school_year
                ORDER BY pr.created_at DESC, pr.id DESC LIMIT 1) AS predicted
        FROM users u
        JOIN student_subjects ss ON ss.student_id = u.id AND ss.school_year = ?
        JOIN subjects s ON s.id = ss.subject_id
        LEFT JOIN programs p ON p.id = u.program_id
        LEFT JOIN grade_inputs gi ON gi.student_id = u.id AND gi.subject_id = ss.subject_id AND gi.school_year = ss.school_year
        LEFT JOIN final_grades fg ON fg.student_id = u.id AND fg.subject_id = ss.subject_id AND fg.school_year = ss.school_year
        WHERE u.role = 'student' AND u.account_status = 'approved'";
    $args = [$f['year']];
    if (!empty($f['program_id'])) { $sql .= " AND u.program_id = ?"; $args[] = (int) $f['program_id']; }
    if (!empty($f['year_level'])) { $sql .= " AND u.year_level = ?"; $args[] = (int) $f['year_level']; }
    if (!empty($f['subject_id'])) { $sql .= " AND ss.subject_id = ?"; $args[] = (int) $f['subject_id']; }
    if (!empty($f['student_id'])) { $sql .= " AND u.id = ?"; $args[] = (int) $f['student_id']; }
    if (!empty($f['semester'])) {
        $sql .= " AND EXISTS (SELECT 1 FROM curriculum_subjects cs WHERE cs.subject_id = ss.subject_id AND cs.program_id = u.program_id AND cs.year_level = u.year_level AND cs.semester = ?)";
        $args[] = $f['semester'];
    }
    $sql .= " ORDER BY p.code, u.year_level, u.full_name, s.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if ($r['final_grade'] !== null) { $r['grade'] = (float) $r['final_grade']; $r['basis'] = 'Final'; }
        elseif ($r['predicted'] !== null) { $r['grade'] = (float) $r['predicted']; $r['basis'] = 'Predicted'; }
        else { $r['grade'] = null; $r['basis'] = null; }
        $r['gp'] = $r['grade'] !== null ? grade_point($r['grade']) : null;
        $r['units'] = (int) $r['units'];
    }
    unset($r);
    return $rows;
}

/** Groups rows by student and computes GWA and honor outlook for each. Sorted best GWA first (students without one last). */
function consolidate_students(array $rows, array $cfg): array
{
    $byStudent = [];
    foreach ($rows as $r) {
        $sid = (int) $r['student_id'];
        $byStudent[$sid]['info'] = ['id' => $sid, 'name' => $r['full_name'], 'no' => $r['student_no'], 'program' => $r['program_code'], 'year' => $r['year_level']];
        $byStudent[$sid]['rows'][] = $r;
    }
    $out = [];
    foreach ($byStudent as $sid => $s) {
        $graded = []; $missing = 0; $units = 0; $basis = [];
        foreach ($s['rows'] as $r) {
            if ($r['gp'] === null) { $missing++; continue; }
            $graded[] = ['gp' => $r['gp'], 'units' => $r['units'], 'subject' => $r['subject']];
            $units += $r['units'];
            $basis[$r['basis']] = true;
        }
        $h = honor_designation($graded, $missing, $cfg);
        $bySubject = [];
        foreach ($s['rows'] as $r) { $bySubject[(int) $r['subject_id']] = $r; }
        $out[] = $s['info'] + [
            'by_subject' => $bySubject, 'units' => $units, 'gwa' => $h['gwa'], 'qualified' => $h['qualified'],
            'honor' => $h['honor'], 'reasons' => $h['reasons'], 'missing' => $missing,
            'basis' => isset($basis['Final']) && isset($basis['Predicted']) ? 'Mixed' : (isset($basis['Final']) ? 'Final' : (isset($basis['Predicted']) ? 'Predicted' : '—')),
        ];
    }
    usort($out, fn($a, $b) => ($a['gwa'] ?? 99) <=> ($b['gwa'] ?? 99) ?: strcmp($a['name'], $b['name']));
    return $out;
}

/* ---------------- Excel (.xlsx) writer ---------------- */

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + ($i - 1) % 26) . $s; }
    return $s;
}

/**
 * Builds a workbook from ['Sheet name' => rows]. Each row is a list of string|int|float|null cells.
 * Text is stored as plain strings, never formulas, so names or notes starting with = or + cannot run as formulas.
 * $boldRows: ['Sheet name' => [row indexes to show in bold]].
 */
function xlsx_build(array $sheets, array $boldRows = []): string
{
    if (!class_exists('ZipArchive')) { throw new RuntimeException('The zip extension is not enabled in PHP.'); }
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $names = []; $i = 0;
    foreach ($sheets as $name => $_) {
        $clean = mb_substr(preg_replace('/[\\[\\]:*?\\/\\\\]/u', ' ', (string) $name), 0, 31) ?: 'Sheet';
        $base = $clean; $n = 2;
        while (in_array(mb_strtolower($clean), array_map('mb_strtolower', $names), true)) { $clean = mb_substr($base, 0, 28) . ' ' . $n++; }
        $names[] = $clean;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wbSheets = ''; $wbRels = '';
    foreach (array_values($sheets) as $idx => $rows) {
        $n = $idx + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . $esc($names[$idx]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $row) {
            $bold = isset($boldRows[array_keys($sheets)[$idx]]) && in_array($ri, $boldRows[array_keys($sheets)[$idx]], true);
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $cell) {
                if ($cell === null || $cell === '') { continue; }
                $ref = xlsx_col($ci) . ($ri + 1);
                $s = $bold ? ' s="1"' : '';
                if (is_int($cell) || is_float($cell)) { $x .= '<c r="' . $ref . '"' . $s . '><v>' . $cell . '</v></c>'; }
                else { $x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . $esc($cell) . '</t></is></c>'; }
            }
            $x .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $x . '</sheetData></worksheet>');
    }
    $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->close();
    $bin = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}


/* ---------------- Sheet builders (shared by the screen, CSV and Excel) ---------------- */

/** Each sheet: ['title' => string, 'meta' => string[], 'head' => string[], 'rows' => array[], 'foot' => array[]]. */

function sheet_meta(array $ctx): array
{
    return [
        'Colegio de Santa Rita de San Carlos, Inc.',
        'School year: ' . $ctx['year'] . '   Program: ' . $ctx['program'] . '   Year level: ' . $ctx['year_level'] . '   Semester: ' . $ctx['semester'],
        'Grades marked Predicted are projections from the prediction model; Final means a recorded final grade.',
    ];
}

function sheet_consolidated(array $students, array $subjects, array $ctx): array
{
    $head = ['No.', 'Student no.', 'Name', 'Program', 'Year'];
    foreach ($subjects as $sub) { $head[] = $sub['name'] . ' (' . $sub['units'] . ' units)'; }
    array_push($head, 'Total units', 'GWA', 'Honor outlook', 'Qualified', 'Basis');
    $rows = [];
    foreach ($students as $i => $st) {
        $r = [$i + 1, (string) $st['no'], $st['name'], (string) $st['program'], $st['year'] !== null ? (int) $st['year'] : null];
        foreach ($subjects as $sid => $_) {
            $cell = $st['by_subject'][$sid] ?? null;
            $r[] = ($cell && $cell['grade'] !== null) ? round($cell['grade'], 1) : ($cell ? 'No grade' : '');
        }
        array_push($r, $st['units'], $st['gwa'], $st['honor'], $st['qualified'] ? 'Qualified' : 'Not qualified', $st['basis']);
        $rows[] = $r;
    }
    return ['title' => 'Consolidated grades and GWA', 'meta' => sheet_meta($ctx), 'head' => $head, 'rows' => $rows, 'foot' => [
        ['GWA = sum of (grade equivalent x units) divided by total units. Equivalent scale: 97+ = 1.00, 94 = 1.25, 91 = 1.50, 88 = 1.75, 85 = 2.00, 82 = 2.25, 79 = 2.50, 76 = 2.75, 75 = 3.00, below 75 = 5.00.'],
    ]];
}

function sheet_subject(array $rows, array $subject, array $ctx): array
{
    $head = ['No.', 'Student no.', 'Name', 'Program', 'Year', 'Past grades', 'Attendance', 'Assignments', 'Quizzes', 'Participation', 'Predicted', 'Final', 'Grade used', 'Equivalent', 'Remarks'];
    $body = []; $passed = 0; $failed = 0; $sum = 0.0; $n = 0;
    foreach ($rows as $i => $r) {
        $num = fn($v) => $v === null ? null : round((float) $v, 1);
        $body[] = [$i + 1, (string) $r['student_no'], $r['full_name'], (string) $r['program_code'], $r['year_level'] !== null ? (int) $r['year_level'] : null,
            $num($r['past_grades']), $num($r['attendance']), $num($r['assignments']), $num($r['quizzes']), $num($r['participation']),
            $num($r['predicted']), $num($r['final_grade']), $r['grade'] !== null ? round($r['grade'], 1) : null,
            $r['gp'] !== null ? (float) gp_text($r['gp']) : null, $r['gp'] === null ? 'No grade' : grade_remark($r['gp'])];
        if ($r['gp'] !== null) { $n++; $sum += $r['grade']; $r['gp'] > 3.00 ? $failed++ : $passed++; }
    }
    $ctx['subject'] = $subject['name'];
    $meta = sheet_meta($ctx);
    array_splice($meta, 1, 0, ['Subject: ' . $subject['name'] . '   Units: ' . $subject['units']]);
    return ['title' => 'Grading sheet: ' . $subject['name'], 'meta' => $meta, 'head' => $head, 'rows' => $body, 'foot' => [
        ['Enrolled: ' . count($rows) . '   Graded: ' . $n . '   Passed: ' . $passed . '   Failed: ' . $failed . '   Class average: ' . ($n ? round($sum / $n, 1) : '—')],
    ]];
}

function sheet_honors(array $students, array $cfg, array $ctx): array
{
    $head = ['Rank', 'Student no.', 'Name', 'Program', 'Year', 'Units', 'GWA', 'Honor designation', 'Qualified', 'Reasons if not qualified', 'Basis'];
    $rows = []; $rank = 0;
    foreach ($students as $st) {
        $rank++;
        $rows[] = [$rank, (string) $st['no'], $st['name'], (string) $st['program'], $st['year'] !== null ? (int) $st['year'] : null, $st['units'], $st['gwa'],
            $st['honor'], $st['qualified'] ? 'Qualified' : 'Not qualified', implode('; ', $st['reasons']), $st['basis']];
    }
    return ['title' => 'Honors outlook', 'meta' => sheet_meta($ctx), 'head' => $head, 'rows' => $rows, 'foot' => [
        ['Rules used: Summa Cum Laude GWA up to ' . gp_text($cfg['summa']) . ', Magna Cum Laude up to ' . gp_text($cfg['magna']) . ', Cum Laude up to ' . gp_text($cfg['cum'])
            . '; no grade worse than ' . gp_text($cfg['lowest']) . '; no failing grade; every enrolled subject must have a grade or prediction.'],
    ]];
}

/** Flattens a sheet to rows for CSV or Excel (meta lines, blank line, header, body, blank, footer). Returns [rows, index of header row]. */
function sheet_to_rows(array $sheet): array
{
    $out = [[$sheet['title']]];
    foreach ($sheet['meta'] as $m) { $out[] = [$m]; }
    $out[] = [];
    $headIdx = count($out);
    $out[] = $sheet['head'];
    foreach ($sheet['rows'] as $r) { $out[] = $r; }
    $out[] = [];
    foreach ($sheet['foot'] as $f) { $out[] = $f; }
    return [$out, $headIdx];
}
