<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'sections';
$year = current_school_year();
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['add_section'])) {
        $name = trim($_POST['name'] ?? '');
        $adviserId = (int) ($_POST['adviser_id'] ?? 0) ?: null;
        $programId = (int) ($_POST['program_id'] ?? 0) ?: null;
        $yearLevel = (int) ($_POST['year_level'] ?? 0) ?: null;
        if ($name !== '') {
            $pdo->prepare("INSERT INTO sections (name, adviser_id, program_id, year_level) VALUES (?, ?, ?, ?)")
                ->execute([$name, $adviserId, $programId, $yearLevel]);
            flash_set('success', 'Section created.');
        }
    } elseif (isset($_POST['delete_section'])) {
        $pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([(int) $_POST['delete_section']]);
        flash_set('success', 'Section deleted.');
    } elseif (isset($_POST['assign_student'])) {
        $studentId = (int) $_POST['student_id'];
        $sectionId = (int) $_POST['section_id'];
        $pdo->prepare("
            INSERT INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE section_id = VALUES(section_id)
        ")->execute([$studentId, $sectionId, $year]);
        flash_set('success', 'Student assigned to section.');
    }
    header('Location: sections.php');
    exit;
}

$sectionsStmt = $pdo->prepare("
    SELECT sec.*, u.full_name AS adviser_name, p.code AS program_code,
        (SELECT COUNT(*) FROM student_sections ss WHERE ss.section_id = sec.id AND ss.school_year = ?) as student_count
    FROM sections sec
    LEFT JOIN users u ON u.id = sec.adviser_id
    LEFT JOIN programs p ON p.id = sec.program_id
    ORDER BY sec.name
");
$sectionsStmt->execute([$year]);
$sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
$teachers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$programs = $pdo->query("SELECT * FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

$unassigned = $pdo->prepare("
    SELECT u.id, u.full_name FROM users u
    LEFT JOIN student_sections ss ON ss.student_id = u.id AND ss.school_year = ?
    WHERE u.role = 'student' AND u.account_status = 'approved' AND ss.id IS NULL
    ORDER BY u.full_name
");
$unassigned->execute([$year]);
$unassigned = $unassigned->fetchAll(PDO::FETCH_ASSOC);

$allStudents = $pdo->query("SELECT id, full_name FROM users WHERE role = 'student' AND account_status = 'approved' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sections — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Sections</h2><p>Group students into class sections for <?= e($year) ?>.</p></div></div>
            <?= render_flash($message) ?>

            <?php if (!empty($unassigned)): ?>
                <?= render_alert('error', count($unassigned) . ' student(s) have no section yet: ' . implode(', ', array_column($unassigned, 'full_name'))) ?>
            <?php endif; ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <table>
                        <thead><tr><th>Section</th><th>Program</th><th>Year</th><th>Adviser</th><th>Students</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($sections)): ?><tr><td colspan="6" class="empty-state">No sections yet.</td></tr><?php endif; ?>
                        <?php foreach ($sections as $s): ?>
                            <tr>
                                <td><?= e($s['name']) ?></td>
                                <td><?= e($s['program_code'] ?? '—') ?></td>
                                <td><?= $s['year_level'] ? 'Year ' . (int) $s['year_level'] : '—' ?></td>
                                <td><?= e($s['adviser_name'] ?? '—') ?></td>
                                <td><?= (int) $s['student_count'] ?></td>
                                <td>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_section" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" data-confirm="Students in this section will become unassigned.">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Create Section</h3>
                    <form method="post" style="margin-bottom:20px;">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Section Name</label><input class="input" type="text" name="name" required placeholder="e.g. BSIT 2A"></div>
                        <div class="form-group">
                            <label>Program</label>
                            <select name="program_id" class="input">
                                <option value="">— None —</option>
                                <?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['code']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year Level</label>
                            <select name="year_level" class="input">
                                <option value="">— None —</option>
                                <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Adviser (optional)</label>
                            <select name="adviser_id" class="input">
                                <option value="">— None —</option>
                                <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="add_section" value="1" class="btn btn-primary btn-block">Create Section</button>
                    </form>

                    <h3>Assign Student to Section</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label>Student</label>
                            <select name="student_id" class="input" required>
                                <?php foreach ($allStudents as $st): ?><option value="<?= $st['id'] ?>"><?= e($st['full_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Section</label>
                            <select name="section_id" class="input" required>
                                <?php foreach ($sections as $sec): ?><option value="<?= $sec['id'] ?>"><?= e($sec['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="assign_student" value="1" class="btn btn-primary btn-block">Assign</button>
                    </form>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
