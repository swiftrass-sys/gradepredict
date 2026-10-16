<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'teachers';
$year = current_school_year();
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['add_teacher'])) {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($name && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $exists->execute([$email]);
            if (!$exists->fetch()) {
                $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, must_change_password, can_approve) VALUES (?, ?, ?, 'teacher', 'Teacher', 1, 1)")
                    ->execute([$name, $email, password_hash('teacher123', PASSWORD_DEFAULT)]);
                flash_set('success', 'Teacher added. Default password: teacher123 (they should change it after logging in).');
            } else {
                flash_set('error', 'That email is already registered.');
            }
        } else {
            flash_set('error', 'Please provide a valid name and email.');
        }
    } elseif (isset($_POST['assign_subject'])) {
        $teacherId = (int) $_POST['teacher_id'];
        $subjectId = (int) $_POST['subject_id'];
        $sectionId = (int) $_POST['section_id'];
        $pdo->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, section_id, school_year) VALUES (?, ?, ?, ?)")
            ->execute([$teacherId, $subjectId, $sectionId, $year]);
        flash_set('success', 'Assignment added.');
        header('Location: teachers.php?id=' . $teacherId);
        exit;
    } elseif (isset($_POST['remove_assignment'])) {
        $pdo->prepare("DELETE FROM teacher_subjects WHERE id = ?")->execute([(int) $_POST['remove_assignment']]);
        flash_set('success', 'Assignment removed.');
        header('Location: teachers.php?id=' . (int) $_POST['teacher_id']);
        exit;
    }
    header('Location: teachers.php');
    exit;
}

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$viewTeacher = null;
$assignments = [];
if ($viewId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$viewId]);
    $viewTeacher = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($viewTeacher) {
        $stmt = $pdo->prepare("
            SELECT ts.id, s.name AS subject_name, s.icon, sec.name AS section_name
            FROM teacher_subjects ts
            JOIN subjects s ON s.id = ts.subject_id
            JOIN sections sec ON sec.id = ts.section_id
            WHERE ts.teacher_id = ? AND ts.school_year = ?
            ORDER BY s.name
        ");
        $stmt->execute([$viewId, $year]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$teachers = $pdo->query("SELECT * FROM users WHERE role = 'teacher' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$allSubjects = $pdo->query("SELECT * FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allSections = $pdo->query("SELECT * FROM sections ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teachers — GradePredict AI Admin</title>
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

            <?php if ($viewTeacher): ?>
                <div class="page-head">
                    <div><h2><?= e($viewTeacher['full_name']) ?></h2><p><?= e($viewTeacher['email']) ?></p></div>
                    <a href="teachers.php" class="btn btn-outline">← Back to Teachers</a>
                </div>
                <div class="grid grid-2">
                    <div class="card">
                        <h3>Current Assignments</h3>
                        <table>
                            <thead><tr><th>Subject</th><th>Section</th><th></th></tr></thead>
                            <tbody>
                            <?php if (empty($assignments)): ?><tr><td colspan="3" class="empty-state">No subjects assigned yet.</td></tr><?php endif; ?>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td class="row-icon"><span class="subj-icon"><?= e($a['icon']) ?></span><?= e($a['subject_name']) ?></td>
                                    <td><?= e($a['section_name']) ?></td>
                                    <td>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="teacher_id" value="<?= $viewId ?>">
                                            <input type="hidden" name="remove_assignment" value="<?= $a['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" data-confirm="Remove this teaching assignment?">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card">
                        <h3>Assign a Subject + Section</h3>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="teacher_id" value="<?= $viewId ?>">
                            <div class="form-group">
                                <label>Subject</label>
                                <select name="subject_id" class="input" required>
                                    <?php foreach ($allSubjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['icon']) ?> <?= e($s['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Section</label>
                                <select name="section_id" class="input" required>
                                    <?php foreach ($allSections as $sec): ?><option value="<?= $sec['id'] ?>"><?= e($sec['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="assign_subject" value="1" class="btn btn-primary btn-block">Assign</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="page-head"><div><h2>Teachers</h2><p>Manage teacher accounts and their subject/section assignments.</p></div></div>
                <div class="grid grid-3">
                    <div class="card" style="grid-column: span 2;">
                        <table>
                            <thead><tr><th>Name</th><th>Email</th><th>Joined</th><th></th></tr></thead>
                            <tbody>
                            <?php if (empty($teachers)): ?><tr><td colspan="4" class="empty-state">No teachers yet.</td></tr><?php endif; ?>
                            <?php foreach ($teachers as $t): ?>
                                <tr>
                                    <td class="row-icon"><div class="avatar" style="width:30px;height:30px;font-size:12px;"><?= e(initials($t['full_name'])) ?></div><?= e($t['full_name']) ?></td>
                                    <td><?= e($t['email']) ?></td>
                                    <td><?= e(date('M j, Y', strtotime($t['created_at']))) ?></td>
                                    <td><a href="?id=<?= $t['id'] ?>" class="btn btn-outline btn-sm">Manage Assignments</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card">
                        <h3>Add Teacher</h3>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="form-group"><label>Full Name</label><input class="input" type="text" name="full_name" required></div>
                            <div class="form-group"><label>Email</label><input class="input" type="email" name="email" required></div>
                            <button type="submit" name="add_teacher" value="1" class="btn btn-primary btn-block">Add Teacher</button>
                        </form>
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
