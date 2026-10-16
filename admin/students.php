<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'students';
$year = current_school_year();
$pdo = db();
$message = flash_get();

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    verify_csrf();
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $programId = (int) ($_POST['program_id'] ?? 0) ?: null;
    $majorId = (int) ($_POST['major_id'] ?? 0) ?: null;
    $yearLevel = clamp_year_level($pdo, $programId, (int) ($_POST['year_level'] ?? 1));
    $sectionId = (int) ($_POST['section_id'] ?? 0) ?: null;

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        flash_set('error', 'Please provide a valid name, email, and a password of at least 6 characters.');
    } else {
        $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            flash_set('error', 'A user with that email already exists.');
        } else {
            $studentNo = 'GP-' . date('Y') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("
                INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, major_id, year_level, must_change_password)
                VALUES (?, ?, ?, 'student', 'Student', ?, ?, ?, ?, 1)
            ")->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $studentNo, $programId, $majorId, $yearLevel]);
            $newStudentId = (int) $pdo->lastInsertId();

            if ($sectionId) {
                $pdo->prepare("INSERT INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)")
                    ->execute([$newStudentId, $sectionId, $year]);
            }
            log_activity((int) $user['id'], 'Student Added', "Added $name ($studentNo)");
            flash_set('success', "Student added (Student No: $studentNo). They must set their own password on first login.");
        }
    }
    header('Location: students.php');
    exit;
}

// Handle admin editing a student's subject data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grade'])) {
    verify_csrf();
    $studentId = (int) $_POST['student_id'];
    $subjectId = (int) $_POST['subject_id'];
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
    ")->execute([$studentId, $subjectId, $year, $past, $attendance, $assignments, $quizzes, $participation]);

    $predicted = calculate_prediction($pdo, $subjectId, $past, $attendance, $assignments, $quizzes, $participation);
    save_prediction($pdo, $studentId, $subjectId, $year, $predicted);
    log_grade_history($pdo, $studentId, $subjectId, $year, [$past, $attendance, $assignments, $quizzes, $participation], (int) $user['id']);
    log_activity((int) $user['id'], 'Admin Updated Grade', "Updated data for student #$studentId");
    notify($pdo, $studentId, 'Your grade data was updated by an admin — your prediction has been recalculated.', '/student/predictions.php');
    flash_set('success', 'Student data updated and prediction recalculated.');
    header('Location: students.php?id=' . $studentId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    verify_csrf();
    $delId = (int) $_POST['delete_student'];
    $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'")->execute([$delId]);
    flash_set('success', 'Student removed.');
    header('Location: students.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    verify_csrf();
    $studentId = (int) $_POST['student_id'];
    // Idempotency: a double-click or a back-button resubmit on this form must
    // never record the same payment twice. check_idempotent() returns false
    // on a repeat submission, so we just redirect back without inserting again.
    if (!check_idempotent('record_payment')) {
        header('Location: students.php?id=' . $studentId);
        exit;
    }
    $amount = max(0, (float) $_POST['amount']);
    $orNumber = trim($_POST['or_number'] ?? '');
    $semester = current_semester();
    if ($amount > 0) {
        $pdo->prepare("INSERT INTO payments (student_id, school_year, semester, amount, or_number, recorded_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$studentId, $year, $semester, $amount, $orNumber ?: null, $user['id']]);
        log_activity((int) $user['id'], 'Payment Recorded', "₱$amount for student #$studentId");
        notify($pdo, $studentId, 'A payment of ₱' . number_format($amount, 2) . ' was recorded on your account.', '/student/finance.php');
        flash_set('success', 'Payment recorded.');
    }
    header('Location: students.php?id=' . $studentId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_advising_note'])) {
    verify_csrf();
    $studentId = (int) $_POST['student_id'];
    $note = trim($_POST['note'] ?? '');
    if ($note !== '') {
        $pdo->prepare("INSERT INTO advising_notes (student_id, adviser_id, note) VALUES (?, ?, ?)")->execute([$studentId, $user['id'], $note]);
        flash_set('success', 'Advising note added.');
    }
    header('Location: students.php?id=' . $studentId);
    exit;
}

if ($viewId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role='student'");
    $stmt->execute([$viewId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.name, s.icon, gi.past_grades, gi.attendance, gi.assignments, gi.quizzes, gi.participation, p.predicted_grade, p.status
            FROM student_subjects ss
            JOIN subjects s ON s.id = ss.subject_id
            LEFT JOIN grade_inputs gi ON gi.subject_id = s.id AND gi.student_id = ss.student_id AND gi.school_year = ss.school_year
            LEFT JOIN predictions p ON p.subject_id = s.id AND p.student_id = ss.student_id
                AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = s.id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
            WHERE ss.student_id = ? AND ss.school_year = ?
            ORDER BY s.name
        ");
        $stmt->execute([$viewId, $year]);
        $subjectRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sectionStmt = $pdo->prepare("SELECT sec.name FROM student_sections ss JOIN sections sec ON sec.id = ss.section_id WHERE ss.student_id = ? AND ss.school_year = ?");
        $sectionStmt->execute([$viewId, $year]);
        $sectionName = $sectionStmt->fetch()['name'] ?? null;

        $semester = current_semester();
        $assessed = fee_amount_for($pdo, $student['program_id'] ?? null, $student['year_level'] ?? null, $semester);
        $paidTotal = total_paid($pdo, $viewId, $year, $semester);
        $balance = max(0, $assessed - $paidTotal);

        $paymentHistory = $pdo->prepare("SELECT * FROM payments WHERE student_id = ? ORDER BY created_at DESC LIMIT 10");
        $paymentHistory->execute([$viewId]);
        $paymentHistory = $paymentHistory->fetchAll(PDO::FETCH_ASSOC);

        $advisingNotes = $pdo->prepare("
            SELECT an.*, u.full_name AS adviser_name FROM advising_notes an
            JOIN users u ON u.id = an.adviser_id WHERE an.student_id = ? ORDER BY an.created_at DESC
        ");
        $advisingNotes->execute([$viewId]);
        $advisingNotes = $advisingNotes->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $sortable = ['name' => 'full_name', 'avg' => 'avg_g'];
    $sort = in_array($_GET['sort'] ?? '', array_keys($sortable), true) ? $_GET['sort'] : 'name';
    $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $riskFilter = in_array($_GET['risk'] ?? '', ['High', 'Medium', 'Low'], true) ? $_GET['risk'] : '';

    $baseSql = "
        FROM users u
        LEFT JOIN (
            SELECT ss.student_id, AVG(p.predicted_grade) avg_g FROM student_subjects ss
            JOIN predictions p ON p.subject_id = ss.subject_id AND p.student_id = ss.student_id
                AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = ss.subject_id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
            WHERE ss.school_year = :year1
            GROUP BY ss.student_id
        ) agg ON agg.student_id = u.id
        WHERE u.role = 'student' AND u.account_status = 'approved'
    ";
    $params = ['year1' => $year];
    if ($riskFilter === 'High') { $baseSql .= " AND agg.avg_g < 60"; }
    elseif ($riskFilter === 'Medium') { $baseSql .= " AND agg.avg_g >= 60 AND agg.avg_g < 70"; }
    elseif ($riskFilter === 'Low') { $baseSql .= " AND agg.avg_g >= 70"; }

    $total = (int) (function () use ($pdo, $baseSql, $params) {
        $s = $pdo->prepare("SELECT COUNT(*) c $baseSql");
        $s->execute($params);
        return $s->fetch()['c'];
    })();
    $pg = paginate_params($total, 8);

    $orderCol = $sort === 'avg' ? 'agg.avg_g' : 'u.full_name';
    $stmt = $pdo->prepare("
        SELECT u.*, agg.avg_g
        $baseSql
        ORDER BY $orderCol $dir
        LIMIT :perpage OFFSET :offset
    ");
    foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
    $stmt->bindValue(':perpage', $pg['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pg['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $studentData = array_map(fn($s) => ['user' => $s, 'avg' => $s['avg_g'] !== null ? round((float) $s['avg_g'], 1) : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $extraParams = array_filter(['risk' => $riskFilter]);

    $programsForForm = $pdo->query("SELECT * FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
    $majorsForForm = $pdo->query("SELECT * FROM majors ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
    $sectionsForForm = $pdo->query("SELECT * FROM sections ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Students — GradePredict AI</title>
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
            <?= render_flash($message) ?>

            <?php if ($viewId && !empty($student)): ?>
                <div class="page-head">
                    <div><h2><?= e($student['full_name']) ?></h2><p><?= e($student['email']) ?> · <?= e($student['student_no'] ?? '—') ?><?= $sectionName ? ' · ' . e($sectionName) : '' ?> · <?= e(program_name($pdo, $student['program_id'] ?? null)) ?><?= $student['year_level'] ? ' · Year ' . (int) $student['year_level'] : '' ?></p></div>
                    <a href="students.php" class="btn btn-outline">← Back to Students</a>
                </div>
                <div class="grid grid-2">
                <?php foreach ($subjectRows as $r):
                    $trend = trend_for($pdo, $viewId, (int) $r['id']);
                    $confidence = confidence_for($pdo, $viewId, (int) $r['id']);
                ?>
                    <div class="card">
                        <div class="card-head">
                            <h3 class="row-icon"><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['name']) ?></h3>
                            <span class="badge <?= status_badge_class($r['status'] ?? 'Poor') ?>"><?= e($r['status'] ?? 'No data') ?></span>
                        </div>
                        <div style="display:flex;gap:8px;margin-bottom:14px;">
                            <span class="badge <?= $trend['direction'] === 'up' ? 'badge-verygood' : ($trend['direction'] === 'down' ? 'badge-poor' : 'badge-good') ?>" data-tooltip="Based on the last few predictions"><?= $trend['icon'] ?> <?= e($trend['label']) ?></span>
                            <span class="badge <?= $confidence['class'] ?>" data-tooltip="<?= $confidence['count'] ?> data update(s) recorded">Confidence: <?= $confidence['level'] ?></span>
                        </div>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="student_id" value="<?= $viewId ?>">
                            <input type="hidden" name="subject_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="update_grade" value="1">
                            <?php
                            $fields = [
                                ['past_grades', 'Past Grades', $r['past_grades']],
                                ['attendance', 'Attendance', $r['attendance']],
                                ['assignments', 'Assignments', $r['assignments']],
                                ['quizzes', 'Quizzes', $r['quizzes']],
                                ['participation', 'Participation', $r['participation']],
                            ];
                            foreach ($fields as $f): [$name, $label, $val] = $f; $val = $val ?? 0; ?>
                                <div class="form-group">
                                    <label><?= $label ?></label>
                                    <div class="slider-row">
                                        <input type="range" min="0" max="100" name="<?= $name ?>" value="<?= e((string) $val) ?>" data-live="v_<?= $name ?>_<?= $r['id'] ?>">
                                        <span class="val" id="v_<?= $name ?>_<?= $r['id'] ?>"><?= e((string) $val) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div>

                <div class="grid grid-2" style="margin-top:18px;">
                    <div class="card">
                        <div class="card-head"><h3>💰 Cashier</h3><span class="badge <?= $balance > 0 ? 'badge-poor' : 'badge-verygood' ?>">Balance: ₱<?= number_format($balance, 2) ?></span></div>
                        <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Assessed ₱<?= number_format($assessed, 2) ?> · Paid ₱<?= number_format($paidTotal, 2) ?> for <?= e($semester) ?></p>
                        <table style="margin-bottom:14px;">
                            <thead><tr><th>Date</th><th>OR #</th><th>Amount</th></tr></thead>
                            <tbody>
                            <?php if (empty($paymentHistory)): ?><tr><td colspan="3" class="empty-state">No payments yet.</td></tr><?php endif; ?>
                            <?php foreach ($paymentHistory as $p): ?>
                                <tr><td><?= e(date('M j, Y', strtotime($p['created_at']))) ?></td><td><?= e($p['or_number'] ?? '—') ?></td><td>₱<?= number_format((float) $p['amount'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                            <?= csrf_field() ?>
                            <?= idempotency_field('record_payment') ?>
                            <input type="hidden" name="student_id" value="<?= $viewId ?>">
                            <div class="form-group" style="margin-bottom:0;"><label>Amount (₱)</label><input class="input" type="number" name="amount" min="0" step="0.01" required style="max-width:140px;"></div>
                            <div class="form-group" style="margin-bottom:0;"><label>OR Number</label><input class="input" type="text" name="or_number" style="max-width:140px;"></div>
                            <button type="submit" name="record_payment" value="1" class="btn btn-primary">Record Payment</button>
                        </form>
                    </div>

                    <div class="card">
                        <h3>🧭 Advising Notes</h3>
                        <?php if (empty($advisingNotes)): ?>
                            <p class="empty-state" style="padding:20px 0;">No advising notes yet.</p>
                        <?php endif; ?>
                        <?php foreach ($advisingNotes as $n): ?>
                            <div style="border-bottom:1px solid var(--border);padding:10px 0;">
                                <p style="font-size:13.5px;margin:0 0 4px;"><?= nl2br(e($n['note'])) ?></p>
                                <div style="font-size:11.5px;color:var(--text-muted);">By <?= e($n['adviser_name']) ?> · <?= e(date('M j, Y', strtotime($n['created_at']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" style="margin-top:14px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="student_id" value="<?= $viewId ?>">
                            <div class="form-group"><textarea class="input" name="note" rows="2" placeholder="Add an advising note..." required></textarea></div>
                            <button type="submit" name="add_advising_note" value="1" class="btn btn-outline btn-sm">Add Note</button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <div class="page-head"><div><h2>Students</h2><p>All enrolled students and their predicted performance.</p></div></div>

                <div class="card" style="margin-bottom:16px;">
                    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                        <div class="form-group" style="margin-bottom:0;min-width:200px;">
                            <label>Filter by Risk</label>
                            <select name="risk" class="input" onchange="this.form.submit()">
                                <option value="">All students</option>
                                <option value="High" <?= $riskFilter === 'High' ? 'selected' : '' ?>>High risk (&lt;60%)</option>
                                <option value="Medium" <?= $riskFilter === 'Medium' ? 'selected' : '' ?>>Medium risk (60–69%)</option>
                                <option value="Low" <?= $riskFilter === 'Low' ? 'selected' : '' ?>>On track (70%+)</option>
                            </select>
                        </div>
                        <noscript><button type="submit" class="btn btn-outline">Apply</button></noscript>
                    </form>
                </div>

                <div class="grid grid-3">
                    <div class="card" style="grid-column: span 2;">
                        <table>
                            <thead><tr>
                                <th><?= sort_header('Student', 'name', $sort, $_GET['dir'] ?? 'asc', $extraParams) ?></th>
                                <th>Student No.</th>
                                <th><?= sort_header('Predicted Avg', 'avg', $sort, $_GET['dir'] ?? 'asc', $extraParams) ?></th>
                                <th>Status</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php if (empty($studentData)): ?><tr><td colspan="5" class="empty-state">No students match this filter.</td></tr><?php endif; ?>
                            <?php foreach ($studentData as $sd): $s = $sd['user']; ?>
                                <tr>
                                    <td class="row-icon"><div class="avatar" style="width:30px;height:30px;font-size:12px;"><?= e(initials($s['full_name'])) ?></div><?= e($s['full_name']) ?></td>
                                    <td><?= e($s['student_no'] ?? '—') ?></td>
                                    <td><?= $sd['avg'] !== null ? e((string) $sd['avg']) . '%' : '—' ?></td>
                                    <td><?php if ($sd['avg'] !== null): ?><span class="badge <?= status_badge_class(status_label($sd['avg'])) ?>"><?= status_label($sd['avg']) ?></span><?php else: ?>—<?php endif; ?></td>
                                    <td style="display:flex;gap:6px;">
                                        <a href="?id=<?= $s['id'] ?>" class="btn btn-outline btn-sm">View / Edit</a>
                                        <form method="post" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_student" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" data-confirm="This removes the student account and all of their grade data permanently." data-confirm-label="Delete Student">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?= render_pagination($pg['page'], $pg['totalPages'], $extraParams) ?>
                    </div>

                    <div class="card">
                        <h3>➕ Add Student</h3>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="form-group"><label>Full Name</label><input class="input" type="text" name="full_name" required></div>
                            <div class="form-group"><label>Email</label><input class="input" type="email" name="email" required></div>
                            <div class="form-group"><label>Temporary Password</label><input class="input" type="text" name="password" required minlength="6" placeholder="At least 6 characters"></div>
                            <div class="form-group">
                                <label>Program</label>
                                <select name="program_id" class="input" id="addStudentProgram">
                                    <option value="">— Select —</option>
                                    <?php foreach ($programsForForm as $p): ?><option value="<?= $p['id'] ?>" data-years="<?= program_year_count((int) $p['duration_months']) ?>"><?= e($p['code']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="addStudentMajorGroup" style="display:none;">
                                <label>Major</label>
                                <select name="major_id" class="input" id="addStudentMajor">
                                    <option value="">— General —</option>
                                    <?php foreach ($majorsForForm as $m): ?><option value="<?= $m['id'] ?>" data-program="<?= $m['program_id'] ?>"><?= e($m['code']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Year Level</label>
                                <select name="year_level" class="input" data-years-from="addStudentProgram">
                                    <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Section (optional)</label>
                                <select name="section_id" class="input">
                                    <option value="">— None yet —</option>
                                    <?php foreach ($sectionsForForm as $sec): ?><option value="<?= $sec['id'] ?>"><?= e($sec['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="add_student" value="1" class="btn btn-primary btn-block">Add Student</button>
                        </form>
                        <p style="color:var(--text-muted);font-size:12px;margin-top:10px;">The student must change this password the first time they log in.</p>
                    </div>
                </div>
                <script>
                    (function () {
                        var progSel = document.getElementById('addStudentProgram');
                        var majorGroup = document.getElementById('addStudentMajorGroup');
                        if (!progSel) return;
                        progSel.addEventListener('change', function () {
                            var hasMajors = false;
                            document.querySelectorAll('#addStudentMajor option[data-program]').forEach(function (opt) {
                                var match = opt.getAttribute('data-program') === progSel.value;
                                opt.hidden = !match;
                                if (match) hasMajors = true;
                            });
                            majorGroup.style.display = hasMajors ? 'block' : 'none';
                        });
                    })();
                </script>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
