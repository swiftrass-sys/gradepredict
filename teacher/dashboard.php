<?php
require_once __DIR__ . '/../config.php';
$user = require_role('teacher');
$active = 'dashboard';
$year = current_school_year();
$pdo = db();
$announcements = announcements_for_user($pdo, $user, 3);

$assignments = $pdo->prepare("
    SELECT ts.id, s.id AS subject_id, s.name AS subject_name, s.icon, sec.id AS section_id, sec.name AS section_name,
        (SELECT COUNT(*) FROM student_sections ss WHERE ss.section_id = sec.id AND ss.school_year = ts.school_year) AS student_count,
        (SELECT AVG(p.predicted_grade) FROM student_sections ss
            JOIN predictions p ON p.student_id = ss.student_id AND p.subject_id = s.id
                AND p.id = (SELECT id FROM predictions p2 WHERE p2.student_id = ss.student_id AND p2.subject_id = s.id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
            WHERE ss.section_id = sec.id AND ss.school_year = ts.school_year
        ) AS avg_g
    FROM teacher_subjects ts
    JOIN subjects s ON s.id = ts.subject_id
    JOIN sections sec ON sec.id = ts.section_id
    WHERE ts.teacher_id = ? AND ts.school_year = ?
    ORDER BY s.name
");
$assignments->execute([$user['id'], $year]);
$assignments = $assignments->fetchAll(PDO::FETCH_ASSOC);

$totalStudents = 0;
$grades = [];
foreach ($assignments as $a) {
    $totalStudents += (int) $a['student_count'];
    if ($a['avg_g'] !== null) $grades[] = (float) $a['avg_g'];
}
$overallAvg = count($grades) ? round(array_sum($grades) / count($grades), 1) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — GradePredict AI Teacher</title>
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
            <div class="page-head"><div><h2>Hello, <?= e(explode(' ', $user['full_name'])[0]) ?>! 👋</h2><p>Here's an overview of your classes for <?= e($year) ?>.</p></div></div>
            <?php include __DIR__ . '/../includes/announcements_banner.php'; ?>

            <div class="grid grid-4" style="margin-bottom:18px;">
                <div class="card stat-card">
                    <div class="stat-icon blue">📘</div>
                    <div><div class="stat-label">Assignments</div><div class="stat-value"><?= count($assignments) ?></div><div class="stat-sub muted">Subject + Section pairs</div></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon purple">👥</div>
                    <div><div class="stat-label">Total Students</div><div class="stat-value"><?= $totalStudents ?></div></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon green">📈</div>
                    <div><div class="stat-label">Avg. Predicted Grade</div><div class="stat-value"><?= $overallAvg !== null ? $overallAvg . '%' : '—' ?></div></div>
                </div>
            </div>

            <div class="card">
                <h3>My Classes</h3>
                <table>
                    <thead><tr><th>Subject</th><th>Section</th><th>Students</th><th>Avg Predicted</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="5" class="empty-state">No classes assigned yet. Ask an admin to assign you a subject and section.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td class="row-icon"><span class="subj-icon"><?= e($a['icon']) ?></span><?= e($a['subject_name']) ?></td>
                            <td><?= e($a['section_name']) ?></td>
                            <td><?= (int) $a['student_count'] ?></td>
                            <td><?= $a['avg_g'] !== null ? round((float) $a['avg_g'], 1) . '%' : '—' ?></td>
                            <td><a href="students.php?subject=<?= $a['subject_id'] ?>&section=<?= $a['section_id'] ?>" class="btn btn-outline btn-sm">View Students</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
