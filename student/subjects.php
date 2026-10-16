<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$active = 'subjects';
$year = current_school_year();
$semester = current_semester();
$pdo = db();

$message = flash_get();

$assessed = fee_amount_for($pdo, $user['program_id'] ?? null, $user['year_level'] ?? null, $semester);
$paid = total_paid($pdo, (int) $user['id'], $year, $semester);
$balance = max(0, $assessed - $paid);
$hasHold = $assessed > 0 && $balance > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['enroll_subject'])) {
        if ($hasHold) {
            flash_set('error', 'You have an outstanding balance for this semester. Please settle it with the Cashier before enrolling in more subjects.');
        } else {
            $subjectId = (int) $_POST['enroll_subject'];
            $stmt = $pdo->prepare("SELECT 1 FROM student_subjects WHERE student_id = ? AND subject_id = ? AND school_year = ?");
            $stmt->execute([$user['id'], $subjectId, $year]);
            if (!$stmt->fetch()) {
                $pdo->prepare("INSERT INTO student_subjects (student_id, subject_id, school_year) VALUES (?, ?, ?)")
                    ->execute([$user['id'], $subjectId, $year]);
                $pdo->prepare("INSERT INTO grade_inputs (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation) VALUES (?, ?, ?, 0,0,0,0,0)")
                    ->execute([$user['id'], $subjectId, $year]);
                log_activity((int) $user['id'], 'Subject Enrolled', 'Added a new subject');
            }
            flash_set('success', 'Subject added. Head to Data Inputs to fill in your scores.');
        }
    } elseif (isset($_POST['drop_subject'])) {
        $subjectId = (int) $_POST['drop_subject'];
        $pdo->prepare("DELETE FROM student_subjects WHERE student_id = ? AND subject_id = ? AND school_year = ?")->execute([$user['id'], $subjectId, $year]);
        $pdo->prepare("DELETE FROM grade_inputs WHERE student_id = ? AND subject_id = ? AND school_year = ?")->execute([$user['id'], $subjectId, $year]);
        flash_set('success', 'Subject removed.');
    }
    header('Location: subjects.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.icon, s.units, p.predicted_grade, p.status
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    LEFT JOIN predictions p ON p.subject_id = s.id AND p.student_id = ss.student_id
        AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = s.id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
    WHERE ss.student_id = ? AND ss.school_year = ?
    ORDER BY s.name
");
$stmt->execute([$user['id'], $year]);
$mySubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
$enrolledIds = array_column($mySubjects, 'id');
$totalUnits = array_sum(array_column($mySubjects, 'units'));

// Available subjects = whatever the curriculum prescribes for this student's program/major/year/semester,
// minus what they're already enrolled in. Falls back to "no curriculum set" messaging if none exists yet.
$curStmt = $pdo->prepare("
    SELECT s.id, s.name, s.icon, s.units
    FROM curriculum_subjects cs JOIN subjects s ON s.id = cs.subject_id
    WHERE cs.program_id = ? AND cs.major_id <=> ? AND cs.year_level = ? AND cs.semester = ?
    ORDER BY s.name
");
$curStmt->execute([$user['program_id'] ?? 0, $user['major_id'] ?? null, $user['year_level'] ?? 0, $semester]);
$curriculumSubjects = $curStmt->fetchAll(PDO::FETCH_ASSOC);
$available = array_filter($curriculumSubjects, fn($s) => !in_array($s['id'], $enrolledIds));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Subjects — GradePredict AI</title>
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
            <div class="page-head"><div><h2>My Subjects</h2><p>Your curriculum-based enrollment for <?= e($semester) ?>, <?= e($year) ?>.</p></div></div>

            <?= render_flash($message) ?>
            <?php if ($hasHold): ?>
                <?= render_alert('error', 'Enrollment hold: you have an outstanding balance of ₱' . number_format($balance, 2) . ' for this semester. New enrollment is paused until it\'s settled with the Cashier.') ?>
            <?php endif; ?>
            <?php if (empty($user['program_id'])): ?>
                <?= render_alert('error', 'Your account has no program assigned yet. Please contact the registrar/admin.') ?>
            <?php endif; ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <div class="card-head"><h3>Enrolled Subjects (<?= count($mySubjects) ?>)</h3><span style="font-size:13px;color:var(--text-muted);"><?= $totalUnits ?> units</span></div>
                    <table>
                        <thead><tr><th>Subject</th><th>Units</th><th>Predicted Grade</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($mySubjects)): ?>
                            <tr><td colspan="5" class="empty-state">You haven't enrolled in any subjects yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($mySubjects as $s): ?>
                            <tr>
                                <td class="row-icon"><span class="subj-icon"><?= e($s['icon']) ?></span><?= e($s['name']) ?></td>
                                <td><?= (int) $s['units'] ?></td>
                                <td><?= $s['predicted_grade'] !== null ? e((string) $s['predicted_grade']) . '%' : '—' ?></td>
                                <td><?php if ($s['status']): ?><span class="badge <?= status_badge_class($s['status']) ?>"><?= e($s['status']) ?></span><?php else: ?>—<?php endif; ?></td>
                                <td>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="drop_subject" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" data-confirm="You'll lose all saved data-input scores for this subject." data-confirm-label="Remove Subject">Remove</button>
                                </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h3>Add a Subject</h3>
                    <?php if (empty($curriculumSubjects)): ?>
                        <p style="color:var(--text-muted);font-size:13.5px;">No curriculum has been set for your program/year/semester yet. Please check with the registrar.</p>
                    <?php elseif (empty($available)): ?>
                        <p style="color:var(--text-muted);font-size:13.5px;">You're enrolled in every subject prescribed for this semester.</p>
                    <?php elseif ($hasHold): ?>
                        <p style="color:var(--text-muted);font-size:13.5px;">Enrollment is on hold until your balance is settled. See the notice above.</p>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label>Choose subject</label>
                                <select name="enroll_subject" class="input" required>
                                    <?php foreach ($available as $a): ?>
                                        <option value="<?= $a['id'] ?>"><?= e($a['icon']) ?> <?= e($a['name']) ?> (<?= (int) $a['units'] ?> units)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">Add Subject</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
