<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'flags';
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_flag'])) {
    verify_csrf();
    $flagId = (int) $_POST['resolve_flag'];
    $pdo->prepare("UPDATE grade_flags SET status = 'Resolved', resolved_at = NOW(), resolved_by = ? WHERE id = ?")
        ->execute([$user['id'], $flagId]);

    $flagStmt = $pdo->prepare("SELECT student_id, subject_id FROM grade_flags WHERE id = ?");
    $flagStmt->execute([$flagId]);
    $flag = $flagStmt->fetch(PDO::FETCH_ASSOC);
    if ($flag) {
        notify($pdo, (int) $flag['student_id'], 'Your flagged grade has been reviewed by an admin.', '/student/predictions.php');
    }
    log_activity((int) $user['id'], 'Grade Flag Resolved', "Resolved flag #$flagId");
    flash_set('success', 'Marked as resolved.');
    header('Location: grade-flags.php');
    exit;
}

$filter = ($_GET['status'] ?? 'Open') === 'Resolved' ? 'Resolved' : 'Open';
$stmt = $pdo->prepare("
    SELECT gf.*, u.full_name AS student_name, s.name AS subject_name, s.icon
    FROM grade_flags gf
    JOIN users u ON u.id = gf.student_id
    JOIN subjects s ON s.id = gf.subject_id
    WHERE gf.status = ?
    ORDER BY gf.created_at DESC
");
$stmt->execute([$filter]);
$flags = $stmt->fetchAll(PDO::FETCH_ASSOC);
$openCount = (int) $pdo->query("SELECT COUNT(*) c FROM grade_flags WHERE status = 'Open'")->fetch()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grade Flags — GradePredict AI Admin</title>
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
            <div class="page-head">
                <div><h2>Grade Flags</h2><p>Grades that students have flagged as possibly incorrect.</p></div>
                <div style="display:flex;gap:8px;">
                    <a href="?status=Open" class="btn <?= $filter === 'Open' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Open (<?= $openCount ?>)</a>
                    <a href="?status=Resolved" class="btn <?= $filter === 'Resolved' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Resolved</a>
                </div>
            </div>
            <?= render_flash($message) ?>

            <div class="grid grid-2">
            <?php if (empty($flags)): ?>
                <div class="card empty-state" style="grid-column:span 2;">
                    <div class="empty-ic">✅</div>
                    <?= $filter === 'Open' ? 'No open flags right now.' : 'No resolved flags yet.' ?>
                </div>
            <?php endif; ?>
            <?php foreach ($flags as $f): ?>
                <div class="card">
                    <div class="card-head">
                        <h3 class="row-icon"><span class="subj-icon"><?= e($f['icon']) ?></span><?= e($f['subject_name']) ?></h3>
                        <span class="badge <?= $f['status'] === 'Open' ? 'badge-fair' : 'badge-verygood' ?>"><?= e($f['status']) ?></span>
                    </div>
                    <p style="font-size:13px;color:var(--text-muted);margin-bottom:6px;">From <strong style="color:var(--text-dark);"><?= e($f['student_name']) ?></strong> · <?= e(date('M j, Y g:i A', strtotime($f['created_at']))) ?></p>
                    <p style="font-size:14px;margin-bottom:16px;"><?= nl2br(e($f['message'])) ?></p>
                    <div style="display:flex;gap:8px;">
                        <a href="students.php?id=<?= $f['student_id'] ?>" class="btn btn-outline btn-sm">View Student</a>
                        <?php if ($f['status'] === 'Open'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="resolve_flag" value="<?= $f['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Mark Resolved</button>
                            </form>
                        <?php else: ?>
                            <span style="font-size:12px;color:var(--text-muted);align-self:center;">Resolved <?= e(date('M j, Y', strtotime($f['resolved_at']))) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
