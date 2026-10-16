<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'logs';
$pdo = db();

$perPage = 20;
$logs = $pdo->prepare("
    SELECT al.*, u.full_name FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT ?
");
$logs->bindValue(1, $perPage, PDO::PARAM_INT);
$logs->execute();
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

$totalLogs = (int) $pdo->query("SELECT COUNT(*) c FROM activity_logs")->fetch()['c'];
$hasMore = $totalLogs > $perPage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Logs — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Activity Logs</h2><p>System-wide audit trail of user and admin actions. Scroll down to load more.</p></div></div>
            <div class="card">
                <table>
                    <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Description</th></tr></thead>
                    <tbody data-infinite-container data-infinite-endpoint="ajax_activity_logs.php">
                    <?php if (empty($logs)): ?><tr><td colspan="4" class="empty-state">No activity yet.</td></tr><?php endif; ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><?= e(date('M j, Y g:i A', strtotime($l['created_at']))) ?></td>
                            <td><?= e($l['full_name'] ?? 'Unknown') ?></td>
                            <td><?= e($l['action']) ?></td>
                            <td><?= e($l['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($hasMore): ?>
                    <div class="scroll-sentinel"></div>
                    <div class="infinite-loading" style="display:none;">Loading more…</div>
                <?php endif; ?>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
