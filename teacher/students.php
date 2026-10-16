<?php
require_once __DIR__ . '/../config.php';
$user = require_role('teacher');
$active = 'students';
$year = current_school_year();
$pdo = db();
$message = flash_get();

/** Verifies this teacher is actually assigned to teach $subjectId to $sectionId this year. */
function teacher_owns_assignment(PDO $pdo, int $teacherId, int $subjectId, int $sectionId, string $year): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ? AND section_id = ? AND school_year = ?");
    $stmt->execute([$teacherId, $subjectId, $sectionId, $year]);
    return (bool) $stmt->fetch();
}

$subjectId = (int) ($_GET['subject'] ?? 0);
$sectionId = (int) ($_GET['section'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student_to_class'])) {
    verify_csrf();
    $postSubject = (int) $_POST['subject_id'];
    $postSection = (int) $_POST['section_id'];

    if (!teacher_owns_assignment($pdo, (int) $user['id'], $postSubject, $postSection, $year)) {
        flash_set('error', 'You are not assigned to teach this subject/section.');
        header('Location: students.php');
        exit;
    }

    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $existing = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
    $existing->execute([$email]);
    $existingUser = $existing->fetch();

    if ($existingUser && $existingUser['role'] !== 'student') {
        flash_set('error', 'That email belongs to a non-student account.');
        header('Location: students.php?subject=' . $postSubject . '&section=' . $postSection);
        exit;
    }

    if ($existingUser) {
        // Existing student — just enroll them into this class.
        $studentId = (int) $existingUser['id'];
    } else {
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            flash_set('error', 'Please provide a valid name, email, and a password of at least 6 characters for a new student.');
            header('Location: students.php?subject=' . $postSubject . '&section=' . $postSection);
            exit;
        }
        $sectionInfo = $pdo->prepare("SELECT program_id, major_id, year_level FROM sections WHERE id = ?");
        $sectionInfo->execute([$postSection]);
        $sectionInfo = $sectionInfo->fetch();
        $studentNo = 'GP-' . date('Y') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, major_id, year_level, adviser_id, must_change_password)
            VALUES (?, ?, ?, 'student', 'Student', ?, ?, ?, ?, ?, 1)
        ")->execute([
            $name, $email, password_hash($password, PASSWORD_DEFAULT), $studentNo,
            $sectionInfo['program_id'] ?? null, $sectionInfo['major_id'] ?? null, $sectionInfo['year_level'] ?? null, $user['id'],
        ]);
        $studentId = (int) $pdo->lastInsertId();
        log_activity((int) $user['id'], 'Student Added', "Added $name ($studentNo) to a class");
    }

    // Enroll into the section (if not already) and into this specific subject.
    $pdo->prepare("INSERT IGNORE INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)")
        ->execute([$studentId, $postSection, $year]);
    $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id, school_year) VALUES (?, ?, ?)")
        ->execute([$studentId, $postSubject, $year]);
    $pdo->prepare("INSERT IGNORE INTO grade_inputs (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation) VALUES (?, ?, ?, 0,0,0,0,0)")
        ->execute([$studentId, $postSubject, $year]);

    flash_set('success', $existingUser ? 'Existing student added to your class.' : 'New student created and added to your class. They must set their own password on first login.');
    header('Location: students.php?subject=' . $postSubject . '&section=' . $postSection);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grade'])) {
    verify_csrf();
    $postSubject = (int) $_POST['subject_id'];
    $postSection = (int) $_POST['section_id'];
    $studentId = (int) $_POST['student_id'];

    if (!teacher_owns_assignment($pdo, (int) $user['id'], $postSubject, $postSection, $year)) {
        flash_set('error', 'You are not assigned to teach this subject/section.');
        header('Location: students.php');
        exit;
    }

    $past = max(0, min(100, (float) $_POST['past_grades']));
    $attendance = max(0, min(100, (float) $_POST['attendance']));
    $assignments = max(0, min(100, (float) $_POST['assignments']));
    $quizzes = max(0, min(100, (float) $_POST['quizzes']));
    $participation = max(0, min(100, (float) $_POST['participation']));

    $pdo->prepare("
        INSERT INTO grade_inputs (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE past_grades=VALUES(past_grades), attendance=VALUES(attendance), assignments=VALUES(assignments),
                      quizzes=VALUES(quizzes), participation=VALUES(participation), updated_at=NOW()
    ")->execute([$studentId, $postSubject, $year, $past, $attendance, $assignments, $quizzes, $participation]);

    $predicted = calculate_prediction($pdo, $postSubject, $past, $attendance, $assignments, $quizzes, $participation);
    save_prediction($pdo, $studentId, $postSubject, $year, $predicted);
    log_grade_history($pdo, $studentId, $postSubject, $year, [$past, $attendance, $assignments, $quizzes, $participation], (int) $user['id']);
    log_activity((int) $user['id'], 'Teacher Updated Grade', "Updated grade for student #$studentId");
    notify($pdo, $studentId, $user['full_name'] . ' updated your grade data — your prediction has been recalculated.', '/student/predictions.php');
    flash_set('success', 'Grade saved and prediction recalculated.');
    header('Location: students.php?subject=' . $postSubject . '&section=' . $postSection);
    exit;
}

$myAssignments = $pdo->prepare("
    SELECT ts.subject_id, s.name AS subject_name, s.icon, ts.section_id, sec.name AS section_name
    FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id JOIN sections sec ON sec.id = ts.section_id
    WHERE ts.teacher_id = ? AND ts.school_year = ? ORDER BY s.name
");
$myAssignments->execute([$user['id'], $year]);
$myAssignments = $myAssignments->fetchAll(PDO::FETCH_ASSOC);

if (!$subjectId && !empty($myAssignments)) {
    $subjectId = (int) $myAssignments[0]['subject_id'];
    $sectionId = (int) $myAssignments[0]['section_id'];
}

$authorized = $subjectId && $sectionId && teacher_owns_assignment($pdo, (int) $user['id'], $subjectId, $sectionId, $year);
$students = [];
if ($authorized) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.student_no, gi.past_grades, gi.attendance, gi.assignments, gi.quizzes, gi.participation, p.predicted_grade, p.status
        FROM student_sections ss
        JOIN users u ON u.id = ss.student_id
        LEFT JOIN grade_inputs gi ON gi.student_id = u.id AND gi.subject_id = ? AND gi.school_year = ss.school_year
        LEFT JOIN predictions p ON p.student_id = u.id AND p.subject_id = ?
            AND p.id = (SELECT id FROM predictions p2 WHERE p2.student_id = u.id AND p2.subject_id = ? ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
        WHERE ss.section_id = ? AND ss.school_year = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$subjectId, $subjectId, $subjectId, $sectionId, $year]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$editStudentId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editStudent = null;
foreach ($students as $s) {
    if ((int) $s['id'] === $editStudentId) { $editStudent = $s; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Students — GradePredict AI Teacher</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_teacher.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head"><div><h2>My Students</h2><p>Only the subject and section you're assigned to teach are shown here.</p></div></div>
            <?= render_flash($message) ?>

            <?php if (empty($myAssignments)): ?>
                <div class="card empty-state">You have no classes assigned yet. Ask an admin to assign you a subject and section.</div>
            <?php else: ?>
            <div class="card" style="margin-bottom:16px;">
                <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <div class="form-group" style="margin-bottom:0;min-width:260px;">
                        <label>Class</label>
                        <select name="class" class="input" onchange="var v=this.value.split('|'); window.location='students.php?subject='+v[0]+'&section='+v[1];">
                            <?php foreach ($myAssignments as $a): ?>
                                <option value="<?= $a['subject_id'] ?>|<?= $a['section_id'] ?>" <?= ($a['subject_id'] == $subjectId && $a['section_id'] == $sectionId) ? 'selected' : '' ?>>
                                    <?= e($a['icon']) ?> <?= e($a['subject_name']) ?> — <?= e($a['section_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <?php if ($editStudent): ?>
                <div class="card" style="max-width:480px;margin-bottom:18px;">
                    <div class="card-head"><h3><?= e($editStudent['full_name']) ?></h3><a href="students.php?subject=<?= $subjectId ?>&section=<?= $sectionId ?>" class="link">Close</a></div>
                    <?php
                    $trend = trend_for($pdo, (int) $editStudent['id'], $subjectId);
                    $confidence = confidence_for($pdo, (int) $editStudent['id'], $subjectId);
                    ?>
                    <div style="display:flex;gap:8px;margin-bottom:14px;">
                        <span class="badge <?= $trend['direction'] === 'up' ? 'badge-verygood' : ($trend['direction'] === 'down' ? 'badge-poor' : 'badge-good') ?>"><?= $trend['icon'] ?> <?= e($trend['label']) ?></span>
                        <span class="badge <?= $confidence['class'] ?>">Confidence: <?= $confidence['level'] ?></span>
                    </div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="student_id" value="<?= $editStudent['id'] ?>">
                        <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                        <input type="hidden" name="section_id" value="<?= $sectionId ?>">
                        <input type="hidden" name="update_grade" value="1">
                        <?php
                        $fields = [
                            ['past_grades', 'Past Grades', $editStudent['past_grades']],
                            ['attendance', 'Attendance', $editStudent['attendance']],
                            ['assignments', 'Assignments', $editStudent['assignments']],
                            ['quizzes', 'Quizzes', $editStudent['quizzes']],
                            ['participation', 'Participation', $editStudent['participation']],
                        ];
                        foreach ($fields as $f): [$name, $label, $val] = $f; $val = $val ?? 0; ?>
                            <div class="form-group">
                                <label><?= $label ?></label>
                                <div class="slider-row">
                                    <input type="range" min="0" max="100" name="<?= $name ?>" value="<?= e((string) $val) ?>" data-live="v_<?= $name ?>">
                                    <span class="val" id="v_<?= $name ?>"><?= e((string) $val) ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <table>
                        <thead><tr><th>Student</th><th>Student No.</th><th>Predicted Grade</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$authorized): ?><tr><td colspan="5" class="empty-state">Select a class above.</td></tr><?php endif; ?>
                        <?php if ($authorized && empty($students)): ?><tr><td colspan="5" class="empty-state">No students in this section yet.</td></tr><?php endif; ?>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td class="row-icon"><div class="avatar" style="width:28px;height:28px;font-size:11px;"><?= e(initials($s['full_name'])) ?></div><?= e($s['full_name']) ?></td>
                                <td><?= e($s['student_no'] ?? '—') ?></td>
                                <td><?= $s['predicted_grade'] !== null ? e((string) $s['predicted_grade']) . '%' : '—' ?></td>
                                <td><?php if ($s['status']): ?><span class="badge <?= status_badge_class($s['status']) ?>"><?= e($s['status']) ?></span><?php else: ?>—<?php endif; ?></td>
                                <td><a href="?subject=<?= $subjectId ?>&section=<?= $sectionId ?>&edit=<?= $s['id'] ?>" class="btn btn-outline btn-sm">Edit Grades</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($authorized): ?>
                <div class="card">
                    <h3>➕ Add Student to This Class</h3>
                    <p style="color:var(--text-muted);font-size:12.5px;margin-bottom:14px;">Use an existing student's email to just enroll them, or fill in all fields to create a brand-new student account.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                        <input type="hidden" name="section_id" value="<?= $sectionId ?>">
                        <div class="form-group"><label>Full Name <span style="color:var(--text-muted);font-weight:400;">(new students only)</span></label><input class="input" type="text" name="full_name"></div>
                        <div class="form-group"><label>Email</label><input class="input" type="email" name="email" required></div>
                        <div class="form-group"><label>Temporary Password <span style="color:var(--text-muted);font-weight:400;">(new students only)</span></label><input class="input" type="text" name="password" minlength="6" placeholder="At least 6 characters"></div>
                        <button type="submit" name="add_student_to_class" value="1" class="btn btn-primary btn-block">Add to Class</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
