<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'inputs';
$year = current_school_year();
$pdo = db();

$totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM grade_inputs WHERE school_year = ?");
$totalStmt->execute([$year]);
$total = (int) $totalStmt->fetch()['c'];
$pg = paginate_params($total, 15);

$rows = $pdo->prepare("
    SELECT gi.*, u.full_name, s.name as subject_name, s.icon
    FROM grade_inputs gi
    JOIN users u ON u.id = gi.student_id
    JOIN subjects s ON s.id = gi.subject_id
    WHERE gi.school_year = ?
    ORDER BY gi.updated_at DESC
    LIMIT ? OFFSET ?
");
$rows->bindValue(1, $year);
$rows->bindValue(2, $pg['perPage'], PDO::PARAM_INT);
$rows->bindValue(3, $pg['offset'], PDO::PARAM_INT);
$rows->execute();
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Inputs — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Data Inputs</h2><p>All raw factor scores submitted for <?= e($year) ?>.</p></div></div>
            <div class="card">
                <table>
                    <thead><tr><th>Student</th><th>Subject</th><th>Past Grades</th><th>Attendance</th><th>Assignments</th><th>Quizzes</th><th>Participation</th><th>Updated</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?><tr><td colspan="9" class="empty-state">No data inputs yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td class="row-icon"><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['subject_name']) ?></td>
                            <td><?= e((string) $r['past_grades']) ?>%</td>
                            <td><?= e((string) $r['attendance']) ?>%</td>
                            <td><?= e((string) $r['assignments']) ?>%</td>
                            <td><?= e((string) $r['quizzes']) ?>%</td>
                            <td><?= e((string) $r['participation']) ?>%</td>
                            <td><?= e(date('M j, Y', strtotime($r['updated_at']))) ?></td>
                            <td><a href="students.php?id=<?= $r['student_id'] ?>" class="link">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?= render_pagination($pg['page'], $pg['totalPages']) ?>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
