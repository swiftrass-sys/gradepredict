<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'history';
$pdo = db();

$total = (int) $pdo->query("SELECT COUNT(*) c FROM predictions WHERE subject_id > 0")->fetch()['c'];
$pg = paginate_params($total, 15);

$stmt = $pdo->prepare("
    SELECT p.created_at, p.predicted_grade, p.status, u.full_name, s.name AS subject_name, s.icon
    FROM predictions p
    JOIN users u ON u.id = p.student_id
    LEFT JOIN subjects s ON s.id = p.subject_id
    WHERE p.subject_id > 0
    ORDER BY p.created_at DESC, p.id DESC
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
<title>Grade History — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Grade History</h2><p>Full log of predictions generated across the school.</p></div></div>
            <div class="card">
                <table>
                    <thead><tr><th>Date</th><th>Student</th><th>Subject</th><th>Predicted Grade</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?><tr><td colspan="5" class="empty-state">No history yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td class="row-icon"><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['subject_name']) ?></td>
                            <td><?= e((string) $r['predicted_grade']) ?>%</td>
                            <td><span class="badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
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
