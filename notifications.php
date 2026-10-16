<?php
require_once __DIR__ . '/config.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    verify_csrf();
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([(int) $_POST['mark_read'], $user['id']]);
    header('Location: notifications.php');
    exit;
}

$total = (int) (function () use ($pdo, $user) {
    $s = $pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id = ?");
    $s->execute([$user['id']]);
    return $s->fetch()['c'];
})();
$pg = paginate_params($total, 15);

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $pg['perPage'], PDO::PARAM_INT);
$stmt->bindValue(3, $pg['offset'], PDO::PARAM_INT);
$stmt->execute();
$notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$active = 'notifications';
$sidebarFile = match ($user['role']) {
    'admin' => 'sidebar_admin.php',
    'teacher' => 'sidebar_teacher.php',
    'parent' => 'sidebar_parent.php',
    default => 'sidebar_student.php',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/includes/' . $sidebarFile; ?>
    <div class="main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head"><div><h2>Notifications</h2><p>Everything GradePredict AI has let you know about.</p></div></div>

            <div class="card">
                <?php if (empty($notifs)): ?>
                    <div class="empty-state"><div class="empty-ic">🔔</div>Nothing here yet.</div>
                <?php endif; ?>
                <?php foreach ($notifs as $n): ?>
                    <div class="act-item" style="align-items:center;">
                        <div class="rec-ic blue"><?= $n['is_read'] ? '·' : '🔵' ?></div>
                        <div style="flex:1;">
                            <a href="<?= e(BASE_URL . ($n['link'] ?? '#')) ?>" style="color:inherit;">
                                <div class="rec-title" style="<?= $n['is_read'] ? 'font-weight:500;color:var(--text-muted);' : '' ?>"><?= e($n['message']) ?></div>
                            </a>
                            <div class="act-date"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                        </div>
                        <?php if (!$n['is_read']): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="mark_read" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?= render_pagination($pg['page'], $pg['totalPages']) ?>
            </div>
            <?php include __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
