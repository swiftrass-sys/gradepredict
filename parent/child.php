<?php
require_once __DIR__ . '/../config.php';
$user = require_role('parent');
$year = current_school_year();
$pdo = db();

$childId = (int) ($_GET['id'] ?? 0);

// Verify this parent is actually linked to this student.
$linkStmt = $pdo->prepare("SELECT 1 FROM parent_links WHERE parent_id = ? AND student_id = ?");
$linkStmt->execute([$user['id'], $childId]);
if (!$linkStmt->fetch()) {
    header('Location: dashboard.php');
    exit;
}

$childStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$childStmt->execute([$childId]);
$child = $childStmt->fetch(PDO::FETCH_ASSOC);
if (!$child) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.icon, p.predicted_grade, p.status
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    LEFT JOIN predictions p ON p.subject_id = s.id AND p.student_id = ss.student_id
        AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = s.id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
    WHERE ss.student_id = ? AND ss.school_year = ?
    ORDER BY s.name
");
$stmt->execute([$childId, $year]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
$grades = array_filter(array_map(fn($s) => $s['predicted_grade'], $subjects), fn($v) => $v !== null);
$overallAvg = count($grades) ? round(array_sum($grades) / count($grades), 1) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($child['full_name']) ?> — GradePredict AI Parent</title>
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
            <div class="page-head">
                <div><h2><?= e($child['full_name']) ?></h2><p>Student No: <?= e($child['student_no'] ?? '—') ?> · <?= e($year) ?></p></div>
                <a href="dashboard.php" class="btn btn-outline">← Back</a>
            </div>

            <div class="grid grid-4" style="margin-bottom:18px;">
                <div class="card stat-card">
                    <div class="stat-icon purple">📄</div>
                    <div><div class="stat-label">Overall Predicted Average</div><div class="stat-value"><?= $overallAvg !== null ? $overallAvg . '%' : '—' ?></div>
                    <?php if ($overallAvg !== null): ?><div class="stat-sub" style="color:var(--green-text)"><?= status_label($overallAvg) ?></div><?php endif; ?></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon blue">📊</div>
                    <div><div class="stat-label">Subjects</div><div class="stat-value"><?= count($subjects) ?></div></div>
                </div>
            </div>

            <div class="card">
                <h3>Subject Breakdown</h3>
                <table>
                    <thead><tr><th>Subject</th><th>Predicted Grade</th><th>Status</th><th>Trend</th></tr></thead>
                    <tbody>
                    <?php if (empty($subjects)): ?><tr><td colspan="4" class="empty-state">No subjects enrolled yet.</td></tr><?php endif; ?>
                    <?php foreach ($subjects as $s): $trend = trend_for($pdo, $childId, (int) $s['id']); ?>
                        <tr>
                            <td class="row-icon"><span class="subj-icon"><?= e($s['icon']) ?></span><?= e($s['name']) ?></td>
                            <td><?= $s['predicted_grade'] !== null ? e((string) $s['predicted_grade']) . '%' : '—' ?></td>
                            <td><?php if ($s['status']): ?><span class="badge <?= status_badge_class($s['status']) ?>"><?= e($s['status']) ?></span><?php else: ?>—<?php endif; ?></td>
                            <td><?= $trend['icon'] ?> <?= e($trend['label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:14px;">This is a read-only view — grade data is managed by your child's teachers and school administrators.</p>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
