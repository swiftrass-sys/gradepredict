<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$active = 'inputs';
$year = current_school_year();
$pdo = db();

$message = flash_get();

// Which subject is being edited
$subjectId = isset($_GET['subject']) ? (int) $_GET['subject'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $subjectId = (int) $_POST['subject_id'];

    // Past Grades is teacher/admin-set and intentionally NOT editable by students here —
    // we re-read the current stored value rather than trust whatever was in the form.
    $existingStmt = $pdo->prepare("SELECT past_grades FROM grade_inputs WHERE student_id = ? AND subject_id = ? AND school_year = ?");
    $existingStmt->execute([$user['id'], $subjectId, $year]);
    $past = (float) ($existingStmt->fetch()['past_grades'] ?? 0);

    $attendance = max(0, min(100, (float) $_POST['attendance']));
    $assignments = max(0, min(100, (float) $_POST['assignments']));
    $quizzes = max(0, min(100, (float) $_POST['quizzes']));
    $participation = max(0, min(100, (float) $_POST['participation']));

    $pdo->prepare("
        INSERT INTO grade_inputs (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE attendance=VALUES(attendance), assignments=VALUES(assignments),
                      quizzes=VALUES(quizzes), participation=VALUES(participation), updated_at=NOW()
    ")->execute([$user['id'], $subjectId, $year, $past, $attendance, $assignments, $quizzes, $participation]);

    $predicted = calculate_prediction($pdo, $subjectId, $past, $attendance, $assignments, $quizzes, $participation);
    save_prediction($pdo, (int) $user['id'], $subjectId, $year, $predicted);
    log_grade_history($pdo, (int) $user['id'], $subjectId, $year, [$past, $attendance, $assignments, $quizzes, $participation], (int) $user['id']);

    // Also snapshot the new overall average so the trend chart moves
    $avgStmt = $pdo->prepare("
        SELECT AVG(p.predicted_grade) a FROM student_subjects ss
        JOIN predictions p ON p.subject_id = ss.subject_id AND p.student_id = ss.student_id
            AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = ss.subject_id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
        WHERE ss.student_id = ? AND ss.school_year = ?
    ");
    $avgStmt->execute([$user['id'], $year]);
    $newAvg = (float) ($avgStmt->fetch()['a'] ?? 0);
    save_prediction($pdo, (int) $user['id'], 0, $year, $newAvg);

    log_activity((int) $user['id'], 'Data Input Added', 'Updated data for a subject');
    flash_set('success', 'Your data was saved and predictions were updated.');
    header('Location: data-inputs.php?subject=' . $subjectId);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.icon, gi.past_grades, gi.attendance, gi.assignments, gi.quizzes, gi.participation, gi.updated_at
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    LEFT JOIN grade_inputs gi ON gi.subject_id = s.id AND gi.student_id = ss.student_id AND gi.school_year = ss.school_year
    WHERE ss.student_id = ? AND ss.school_year = ?
    ORDER BY s.name
");
$stmt->execute([$user['id'], $year]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($subjectId === null && !empty($rows)) {
    $subjectId = (int) $rows[0]['id'];
}
$current = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $subjectId) { $current = $r; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Inputs — GradePredict AI</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_student.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head"><div><h2>Data Inputs</h2><p>Update the factors that drive your grade predictions for <?= e($year) ?>.</p></div></div>
            <?= render_flash($message) ?>

            <?php if (empty($rows)): ?>
                <div class="card empty-state">You have no subjects yet. <a href="subjects.php" class="link">Add a subject</a> first.</div>
            <?php else: ?>
            <div class="grid grid-3">
                <div class="card">
                    <h3>Select Subject</h3>
                    <ul>
                        <?php foreach ($rows as $r): ?>
                            <li>
                                <a href="?subject=<?= $r['id'] ?>" class="nav-item <?= (int) $r['id'] === $subjectId ? 'active' : '' ?>" style="margin-bottom:6px;">
                                    <span class="ic"><?= e($r['icon']) ?></span> <?= e($r['name']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="card" style="grid-column: span 2;">
                    <?php if ($current): ?>
                    <h3><?= e($current['icon']) ?> <?= e($current['name']) ?> — Update Scores</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="subject_id" value="<?= $current['id'] ?>">

                        <div class="form-group">
                            <label>Past Grades (weight 40%)</label>
                            <div class="slider-row">
                                <input type="range" min="0" max="100" step="1" value="<?= e((string) ($current['past_grades'] ?? 0)) ?>" disabled data-tooltip="Set by your teacher or admin">
                                <span class="val"><?= e((string) ($current['past_grades'] ?? 0)) ?>%</span>
                            </div>
                            <div class="field-hint">🔒 Set by your teacher/admin based on your recorded grades — you can't edit this here.</div>
                        </div>
                        <div class="form-group">
                            <label>Attendance (weight 25%)</label>
                            <div class="slider-row">
                                <input type="range" min="0" max="100" step="1" name="attendance" value="<?= e((string) ($current['attendance'] ?? 0)) ?>" data-live="v_att">
                                <span class="val" id="v_att"><?= e((string) ($current['attendance'] ?? 0)) ?>%</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Assignments (weight 15%)</label>
                            <div class="slider-row">
                                <input type="range" min="0" max="100" step="1" name="assignments" value="<?= e((string) ($current['assignments'] ?? 0)) ?>" data-live="v_assign">
                                <span class="val" id="v_assign"><?= e((string) ($current['assignments'] ?? 0)) ?>%</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Quizzes (weight 10%)</label>
                            <div class="slider-row">
                                <input type="range" min="0" max="100" step="1" name="quizzes" value="<?= e((string) ($current['quizzes'] ?? 0)) ?>" data-live="v_quiz">
                                <span class="val" id="v_quiz"><?= e((string) ($current['quizzes'] ?? 0)) ?>%</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Participation (weight 10%)</label>
                            <div class="slider-row">
                                <input type="range" min="0" max="100" step="1" name="participation" value="<?= e((string) ($current['participation'] ?? 0)) ?>" data-live="v_part">
                                <span class="val" id="v_part"><?= e((string) ($current['participation'] ?? 0)) ?>%</span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save &amp; Recalculate Prediction</button>
                    </form>
                    <?php if ($current['updated_at']): ?>
                        <p style="color:var(--text-muted);font-size:12.5px;margin-top:14px;">Last updated <?= e(date('M j, Y g:i A', strtotime($current['updated_at']))) ?> · <a href="grade-history.php" class="link">View history</a></p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
