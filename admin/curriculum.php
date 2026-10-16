<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'curriculum';
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['add_curriculum'])) {
        $programId = (int) $_POST['program_id'];
        $majorId = (int) ($_POST['major_id'] ?? 0) ?: null;
        $yearLevel = (int) $_POST['year_level'];
        $semester = $_POST['semester'] ?? '1st Semester';
        $subjectId = (int) $_POST['subject_id'];
        $pdo->prepare("INSERT IGNORE INTO curriculum_subjects (program_id, major_id, year_level, semester, subject_id) VALUES (?, ?, ?, ?, ?)")
            ->execute([$programId, $majorId, $yearLevel, $semester, $subjectId]);
        flash_set('success', 'Subject added to curriculum.');
    } elseif (isset($_POST['remove_curriculum'])) {
        $pdo->prepare("DELETE FROM curriculum_subjects WHERE id = ?")->execute([(int) $_POST['remove_curriculum']]);
        flash_set('success', 'Subject removed from curriculum.');
    }
    header('Location: curriculum.php?' . http_build_query(array_filter([
        'program' => $_POST['program_id'] ?? null,
        'major' => $_POST['major_id'] ?? null,
        'year' => $_POST['year_level'] ?? null,
        'semester' => $_POST['semester'] ?? null,
    ])));
    exit;
}

$programs = $pdo->query("SELECT * FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$allSubjects = $pdo->query("SELECT * FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$semesters = ['1st Semester', '2nd Semester', 'Summer'];

$programId = (int) ($_GET['program'] ?? ($programs[0]['id'] ?? 0));
$majorId = (int) ($_GET['major'] ?? 0) ?: null;
$yearLevel = (int) ($_GET['year'] ?? 1);
$semester = in_array($_GET['semester'] ?? '', $semesters, true) ? $_GET['semester'] : '1st Semester';

$majors = $pdo->prepare("SELECT * FROM majors WHERE program_id = ? ORDER BY code");
$majors->execute([$programId]);
$majors = $majors->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT cs.id, s.id AS subject_id, s.name, s.icon, s.units
    FROM curriculum_subjects cs JOIN subjects s ON s.id = cs.subject_id
    WHERE cs.program_id = ? AND cs.major_id <=> ? AND cs.year_level = ? AND cs.semester = ?
    ORDER BY s.name
");
$stmt->execute([$programId, $majorId, $yearLevel, $semester]);
$curriculumRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalUnits = array_sum(array_column($curriculumRows, 'units'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Curriculum — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Curriculum</h2><p>The prescribed subjects for each program, year level, and semester.</p></div></div>
            <?= render_flash($message) ?>

            <div class="card" style="margin-bottom:18px;">
                <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="margin-bottom:0;min-width:200px;">
                        <label>Program</label>
                        <select name="program" class="input" onchange="this.form.submit()">
                            <?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id'] == $programId ? 'selected' : '' ?>><?= e($p['code']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!empty($majors)): ?>
                    <div class="form-group" style="margin-bottom:0;min-width:180px;">
                        <label>Major</label>
                        <select name="major" class="input" onchange="this.form.submit()">
                            <option value="">— General —</option>
                            <?php foreach ($majors as $m): ?><option value="<?= $m['id'] ?>" <?= $m['id'] == $majorId ? 'selected' : '' ?>><?= e($m['code']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom:0;min-width:120px;">
                        <label>Year Level</label>
                        <select name="year" class="input" onchange="this.form.submit()">
                            <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?= $y ?>" <?= $y == $yearLevel ? 'selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;min-width:160px;">
                        <label>Semester</label>
                        <select name="semester" class="input" onchange="this.form.submit()">
                            <?php foreach ($semesters as $s): ?><option value="<?= e($s) ?>" <?= $s === $semester ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <div class="card-head"><h3>Subjects in this Curriculum</h3><span style="font-size:13px;color:var(--text-muted);"><?= count($curriculumRows) ?> subject(s) · <?= $totalUnits ?> units</span></div>
                    <table>
                        <thead><tr><th>Subject</th><th>Units</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($curriculumRows)): ?><tr><td colspan="3" class="empty-state">No subjects assigned to this slot yet.</td></tr><?php endif; ?>
                        <?php foreach ($curriculumRows as $r): ?>
                            <tr>
                                <td class="row-icon"><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['name']) ?></td>
                                <td><?= (int) $r['units'] ?></td>
                                <td>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="program_id" value="<?= $programId ?>">
                                        <input type="hidden" name="major_id" value="<?= $majorId ?>">
                                        <input type="hidden" name="year_level" value="<?= $yearLevel ?>">
                                        <input type="hidden" name="semester" value="<?= e($semester) ?>">
                                        <input type="hidden" name="remove_curriculum" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Add Subject to this Slot</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="program_id" value="<?= $programId ?>">
                        <input type="hidden" name="major_id" value="<?= $majorId ?>">
                        <input type="hidden" name="year_level" value="<?= $yearLevel ?>">
                        <input type="hidden" name="semester" value="<?= e($semester) ?>">
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject_id" class="input" required>
                                <?php foreach ($allSubjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['icon']) ?> <?= e($s['name']) ?> (<?= (int) $s['units'] ?> units)</option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="add_curriculum" value="1" class="btn btn-primary btn-block">Add to Curriculum</button>
                    </form>
                    <p style="color:var(--text-muted);font-size:12px;margin-top:14px;">Need a new subject? Add it first on the <a href="subjects.php" class="link">Subjects</a> page, then come back here.</p>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
