<?php
require_once __DIR__ . '/../config.php';
$user = require_role('parent');
$active = 'dashboard';
$year = current_school_year();
$pdo = db();
$announcements = announcements_for_user($pdo, $user, 3);

$children = $pdo->prepare("
    SELECT u.id, u.full_name, u.student_no,
        (SELECT AVG(p.predicted_grade) FROM student_subjects ss
            JOIN predictions p ON p.subject_id = ss.subject_id AND p.student_id = ss.student_id
                AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = ss.subject_id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
            WHERE ss.student_id = u.id AND ss.school_year = ?
        ) AS avg_g
    FROM parent_links pl
    JOIN users u ON u.id = pl.student_id
    WHERE pl.parent_id = ?
    ORDER BY u.full_name
");
$children->execute([$year, $user['id']]);
$children = $children->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — GradePredict AI Parent</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_parent.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head"><div><h2>Hello, <?= e(explode(' ', $user['full_name'])[0]) ?>! 👋</h2><p>Here's how your child/children are doing.</p></div></div>
            <?php include __DIR__ . '/../includes/announcements_banner.php'; ?>

            <?php if (empty($children)): ?>
                <div class="card empty-state">
                    <div class="empty-ic">🔗</div>
                    No children are linked to your account yet. Ask a school admin to link your account to your child's student profile.
                </div>
            <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($children as $c): $avg = $c['avg_g'] !== null ? round((float) $c['avg_g'], 1) : null; ?>
                    <div class="card">
                        <div class="card-head">
                            <h3 class="row-icon"><div class="avatar" style="width:32px;height:32px;font-size:13px;"><?= e(initials($c['full_name'])) ?></div><?= e($c['full_name']) ?></h3>
                        </div>
                        <p style="color:var(--text-muted);font-size:12.5px;margin-bottom:14px;">Student No: <?= e($c['student_no'] ?? '—') ?></p>
                        <div style="font-size:28px;font-weight:700;margin-bottom:6px;"><?= $avg !== null ? $avg . '%' : '—' ?></div>
                        <?php if ($avg !== null): ?><span class="badge <?= status_badge_class(status_label($avg)) ?>"><?= status_label($avg) ?></span><?php endif; ?>
                        <a href="child.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-block" style="margin-top:16px;">View Full Report</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
