<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$active = 'history';
$year = current_school_year();
$pdo = db();

$totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM predictions WHERE student_id = ?");
$totalStmt->execute([$user['id']]);
$total = (int) $totalStmt->fetch()['c'];
$pg = paginate_params($total, 15);

$stmt = $pdo->prepare("
    SELECT p.created_at, p.predicted_grade, p.status, s.name, s.icon
    FROM predictions p
    LEFT JOIN subjects s ON s.id = p.subject_id
    WHERE p.student_id = ?
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $user['id']);
$stmt->bindValue(2, $pg['perPage'], PDO::PARAM_INT);
$stmt->bindValue(3, $pg['offset'], PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grade History — GradePredict AI</title>
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
            <div class="page-head"><div><h2>Grade History</h2><p>A log of every prediction that has been generated for you.</p></div></div>

            <div class="card">
                <table>
                    <thead><tr><th>Date</th><th>Subject</th><th>Predicted Grade</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="4" class="empty-state">No prediction history yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                            <td class="row-icon"><?php if ($r['name']): ?><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['name']) ?><?php else: ?><span class="subj-icon">📊</span>Overall Average<?php endif; ?></td>
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
