<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'import';
$year = current_school_year();
$pdo = db();
$message = flash_get();
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    verify_csrf();
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Upload failed. Please try again.');
        header('Location: import-data.php');
        exit;
    }

    $handle = fopen($file['tmp_name'], 'r');
    $header = fgetcsv($handle);
    $header = array_map(fn($h) => strtolower(trim($h)), $header ?: []);
    $required = ['student_email', 'subject', 'past_grades', 'attendance', 'assignments', 'quizzes', 'participation'];
    $missing = array_diff($required, $header);

    $imported = 0; $skipped = 0; $errors = [];

    if (!empty($missing)) {
        $errors[] = 'CSV is missing required columns: ' . implode(', ', $missing);
    } else {
        $colIndex = array_flip($header);
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if (count($data) < count($header)) { $skipped++; continue; }

            $email = trim($data[$colIndex['student_email']]);
            $subjectName = trim($data[$colIndex['subject']]);

            $stStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role='student'");
            $stStmt->execute([$email]);
            $studentRow = $stStmt->fetch();

            $subStmt = $pdo->prepare("SELECT id FROM subjects WHERE name = ?");
            $subStmt->execute([$subjectName]);
            $subjectRow = $subStmt->fetch();

            if (!$studentRow || !$subjectRow) {
                $skipped++;
                $errors[] = "Line $line: could not find student '$email' or subject '$subjectName'";
                continue;
            }

            $studentId = (int) $studentRow['id'];
            $subjectId = (int) $subjectRow['id'];
            $past = (float) $data[$colIndex['past_grades']];
            $attendance = (float) $data[$colIndex['attendance']];
            $assignments = (float) $data[$colIndex['assignments']];
            $quizzes = (float) $data[$colIndex['quizzes']];
            $participation = (float) $data[$colIndex['participation']];

            $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id, school_year) VALUES (?, ?, ?)")
                ->execute([$studentId, $subjectId, $year]);

            $pdo->prepare("
                INSERT INTO grade_inputs (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE past_grades=VALUES(past_grades), attendance=VALUES(attendance), assignments=VALUES(assignments),
                              quizzes=VALUES(quizzes), participation=VALUES(participation), updated_at=NOW()
            ")->execute([$studentId, $subjectId, $year, $past, $attendance, $assignments, $quizzes, $participation]);

            $predicted = calculate_prediction($pdo, $subjectId, $past, $attendance, $assignments, $quizzes, $participation);
            save_prediction($pdo, $studentId, $subjectId, $year, $predicted);
            log_grade_history($pdo, $studentId, $subjectId, $year, [$past, $attendance, $assignments, $quizzes, $participation], (int) $user['id']);
            $imported++;
        }
    }
    fclose($handle);

    log_activity((int) $user['id'], 'Data Imported', "$imported rows imported, $skipped skipped");
    $importResults = ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Data — GradePredict AI Admin</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body data-search-endpoint="ajax_search.php">
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
    <div class="main">
        <?php $showSearch = true; include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head"><div><h2>Import Data</h2><p>Bulk upload student grades, attendance, and assignment scores via CSV.</p></div></div>
            <?= render_flash($message) ?>

            <?php if ($importResults): ?>
                <?= render_alert('success', 'Imported ' . $importResults['imported'] . ' row(s). Skipped ' . $importResults['skipped'] . '.') ?>
                <?php if (!empty($importResults['errors'])): ?>
                    <div class="card" style="margin-bottom:18px;">
                        <h3>Issues</h3>
                        <ul>
                        <?php foreach ($importResults['errors'] as $err): ?><li style="font-size:13px;color:var(--red-text);margin-bottom:4px;"><?= e($err) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="grid grid-2">
                <div class="card">
                    <h3>Upload CSV</h3>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label>CSV File</label>
                            <input class="input" type="file" name="csv_file" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Upload &amp; Import</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Expected Format</h3>
                    <p style="color:var(--text-muted);font-size:13.5px;">Your CSV needs a header row with these exact columns:</p>
                    <pre style="background:var(--bg);padding:14px;border-radius:10px;font-size:12.5px;overflow-x:auto;">student_email,subject,past_grades,attendance,assignments,quizzes,participation
juan@student.gradepredict.ai,Mathematics,90,95,85,82,88</pre>
                    <p style="color:var(--text-muted);font-size:12.5px;">The student email must match an existing student account, and the subject name must match an existing subject exactly.</p>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
