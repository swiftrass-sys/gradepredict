<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'predictions';
$year = current_school_year();
$pdo = db();

$baseSql = "
    FROM predictions p
    JOIN users u ON u.id = p.student_id
    JOIN subjects s ON s.id = p.subject_id
    WHERE p.subject_id > 0 AND p.id = (
        SELECT id FROM predictions p2 WHERE p2.student_id = p.student_id AND p2.subject_id = p.subject_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1
    )
";
$total = (int) $pdo->query("SELECT COUNT(*) c $baseSql")->fetch()['c'];
$pg = paginate_params($total, 12);

$sortable = ['grade' => 'p.predicted_grade', 'name' => 'u.full_name', 'subject' => 's.name'];
$sort = in_array($_GET['sort'] ?? '', array_keys($sortable), true) ? $_GET['sort'] : 'grade';
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$stmt = $pdo->prepare("
    SELECT p.predicted_grade, p.status, p.created_at, u.full_name, s.name AS subject_name, s.icon
    $baseSql
    ORDER BY {$sortable[$sort]} $dir
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $pg['perPage'], PDO::PARAM_INT);
$stmt->bindValue(2, $pg['offset'], PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Predictions — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Predictions</h2><p>Current predicted grade for every student and subject, weakest first.</p></div></div>
            <div class="card">
                <table>
                    <thead><tr>
                        <th><?= sort_header('Student', 'name', $sort, $_GET['dir'] ?? 'asc') ?></th>
                        <th><?= sort_header('Subject', 'subject', $sort, $_GET['dir'] ?? 'asc') ?></th>
                        <th><?= sort_header('Predicted Grade', 'grade', $sort, $_GET['dir'] ?? 'asc') ?></th>
                        <th>Status</th><th>Last Updated</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?><tr><td colspan="5" class="empty-state">No predictions yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td class="row-icon"><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['subject_name']) ?></td>
                            <td><?= e((string) $r['predicted_grade']) ?>%</td>
                            <td><span class="badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
                            <td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
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
