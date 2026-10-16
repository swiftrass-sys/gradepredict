<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'dashboard';
$year = current_school_year();
$pdo = db();
$announcements = announcements_for_user($pdo, $user, 3);

$totalStudents = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role='student' AND account_status='approved'")->fetch()['c'];
$predictionsMade = (int) $pdo->query("SELECT COUNT(*) c FROM predictions WHERE subject_id > 0")->fetch()['c'];
$subjectsTracked = (int) $pdo->query("SELECT COUNT(*) c FROM subjects")->fetch()['c'];

// Latest predicted grade per student per subject
$latestSql = "
    SELECT p.student_id, p.subject_id, p.predicted_grade, p.status, u.full_name, s.name AS subject_name
    FROM predictions p
    JOIN users u ON u.id = p.student_id
    JOIN subjects s ON s.id = p.subject_id
    WHERE p.subject_id > 0 AND p.id = (
        SELECT id FROM predictions p2 WHERE p2.student_id = p.student_id AND p2.subject_id = p.subject_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1
    )
";
$latestRows = $pdo->query($latestSql)->fetchAll(PDO::FETCH_ASSOC);

// Per-student overall average (across their latest subject predictions)
$byStudent = [];
foreach ($latestRows as $r) {
    $byStudent[$r['student_id']]['name'] = $r['full_name'];
    $byStudent[$r['student_id']]['grades'][] = (float) $r['predicted_grade'];
}
$studentAverages = [];
foreach ($byStudent as $sid => $info) {
    $avg = round(array_sum($info['grades']) / count($info['grades']), 1);
    $studentAverages[] = ['id' => $sid, 'name' => $info['name'], 'avg' => $avg];
}
usort($studentAverages, fn($a, $b) => $a['avg'] <=> $b['avg']);
$atRiskCount = count(array_filter($studentAverages, fn($s) => $s['avg'] < 70));
$avgPredicted = count($studentAverages) ? round(array_sum(array_column($studentAverages, 'avg')) / count($studentAverages), 1) : 0;
$topAtRisk = array_slice($studentAverages, 0, 5);

// Grade distribution bands
$bands = ['90-100 (Excellent)' => 0, '80-89 (Very Good)' => 0, '70-79 (Good)' => 0, '60-69 (Fair)' => 0, 'Below 60 (Poor)' => 0];
foreach ($studentAverages as $s) {
    $g = $s['avg'];
    if ($g >= 90) $bands['90-100 (Excellent)']++;
    elseif ($g >= 80) $bands['80-89 (Very Good)']++;
    elseif ($g >= 70) $bands['70-79 (Good)']++;
    elseif ($g >= 60) $bands['60-69 (Fair)']++;
    else $bands['Below 60 (Poor)']++;
}
$totalForBands = max(1, array_sum($bands));
$bandColors = ['90-100 (Excellent)' => 'var(--green)', '80-89 (Very Good)' => 'var(--blue)', '70-79 (Good)' => 'var(--amber)', '60-69 (Fair)' => '#f97316', 'Below 60 (Poor)' => 'var(--red)'];

// Build conic-gradient string
$cursor = 0; $gradientParts = [];
foreach ($bands as $label => $count) {
    $pct = ($count / $totalForBands) * 100;
    $end = $cursor + $pct;
    $gradientParts[] = "{$bandColors[$label]} {$cursor}% {$end}%";
    $cursor = $end;
}
$gradientCss = implode(', ', $gradientParts);

// Predictions over time (system-wide overall snapshots)
$histStmt = $pdo->query("SELECT DATE(created_at) AS created_at, AVG(predicted_grade) avg_g FROM predictions WHERE subject_id = 0 GROUP BY DATE(created_at) ORDER BY created_at ASC LIMIT 6");
$history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($history)) { $history = [['created_at' => date('Y-m-d'), 'avg_g' => $avgPredicted]]; }

$w = 640; $h = 180; $pad = 20;
$vals = array_map(fn($r) => (float) $r['avg_g'], $history);
$n = count($vals);
$points = [];
foreach ($vals as $i => $v) {
    $x = $n > 1 ? $pad + ($i * (($w - 2 * $pad) / ($n - 1))) : $w / 2;
    $y = $h - $pad - (($v - 0) / 100) * ($h - 2 * $pad);
    $points[] = round($x, 1) . ',' . round($y, 1);
}
$polyline = implode(' ', $points);

// Recent predictions (individual subject) latest 5
$recentStmt = $pdo->query("
    SELECT p.created_at, p.predicted_grade, p.status, u.full_name, s.name AS subject_name
    FROM predictions p JOIN users u ON u.id = p.student_id JOIN subjects s ON s.id = p.subject_id
    WHERE p.subject_id > 0 ORDER BY p.created_at DESC, p.id DESC LIMIT 5
");
$recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Subject performance overview
$subjPerf = $pdo->query("
    SELECT s.name, s.icon, AVG(l.predicted_grade) avg_g FROM subjects s
    JOIN ($latestSql) l ON l.subject_id = s.id
    GROUP BY s.id ORDER BY s.name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — GradePredict AI</title>
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
                <div><h2>Dashboard</h2><p>Welcome back, <?= e(explode(' ', $user['full_name'])[0]) ?>! Here's what's happening with your school today.</p></div>
                <div class="select-pill">📅 <?= e(date('F j, Y')) ?> ⌄</div>
            </div>

            <?php include __DIR__ . '/../includes/announcements_banner.php'; ?>
            <div class="grid grid-5" style="margin-bottom:18px;">
                <div class="card stat-card skeleton">
                    <div class="stat-icon blue">👥</div>
                    <div><div class="stat-label">Total Students</div><div class="stat-value"><?= $totalStudents ?></div></div>
                </div>
                <div class="card stat-card skeleton">
                    <div class="stat-icon green">📈</div>
                    <div><div class="stat-label">Predictions Made</div><div class="stat-value"><?= $predictionsMade ?></div></div>
                </div>
                <div class="card stat-card skeleton">
                    <div class="stat-icon purple">📊</div>
                    <div><div class="stat-label">At Risk Students</div><div class="stat-value" style="color:var(--red)"><?= $atRiskCount ?></div></div>
                </div>
                <div class="card stat-card skeleton">
                    <div class="stat-icon amber">📉</div>
                    <div><div class="stat-label">Avg. Predicted Grade</div><div class="stat-value"><?= e((string) $avgPredicted) ?>%</div></div>
                </div>
                <div class="card stat-card skeleton">
                    <div class="stat-icon blue">📋</div>
                    <div><div class="stat-label">Subjects Tracked</div><div class="stat-value"><?= $subjectsTracked ?></div><div class="stat-sub muted">All Active</div></div>
                </div>
            </div>

            <div class="grid grid-3" style="margin-bottom:18px;">
                <div class="card">
                    <h3>Predicted Grade Distribution</h3>
                    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
                        <div class="donut" style="width:160px;height:160px;background:conic-gradient(<?= $gradientCss ?>);">
                            <div class="donut-inner" style="width:105px;height:105px;">
                                <div class="big" style="font-size:20px;"><?= count($studentAverages) ?></div>
                                <div style="font-size:11px;color:var(--text-muted);">Students</div>
                            </div>
                        </div>
                        <div class="legend">
                            <?php foreach ($bands as $label => $count): $pct = round(($count / $totalForBands) * 100); ?>
                            <div class="legend-item"><span class="name"><span class="dot" style="background:<?= $bandColors[$label] ?>"></span><?= e($label) ?></span><span class="val"><?= $pct ?>% (<?= $count ?>)</span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="students.php" class="link">View full report</a>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Predictions Over Time</h3></div>
                    <canvas id="trendChart" height="180"></canvas>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Top At-Risk Students</h3><a href="students.php" class="link">View All</a></div>
                    <table>
                        <thead><tr><th>Student</th><th>Avg Grade</th><th>Risk</th></tr></thead>
                        <tbody>
                        <?php if (empty($topAtRisk)): ?><tr><td colspan="3" class="empty-state">No data yet.</td></tr><?php endif; ?>
                        <?php foreach ($topAtRisk as $s): $risk = risk_level($s['avg']); ?>
                            <tr>
                                <td class="row-icon"><div class="avatar" style="width:28px;height:28px;font-size:11px;"><?= e(initials($s['name'])) ?></div><?= e($s['name']) ?></td>
                                <td><?= e((string) $s['avg']) ?>%</td>
                                <td><span class="badge <?= risk_badge_class($risk) ?>"><?= $risk ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="card" style="grid-column:span 1;">
                    <div class="card-head"><h3>Recent Predictions</h3><a href="predictions.php" class="link">View All</a></div>
                    <table>
                        <thead><tr><th>Student</th><th>Subject</th><th>Grade</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $r): $atRisk = $r['predicted_grade'] < 70; ?>
                            <tr>
                                <td><?= e($r['full_name']) ?></td>
                                <td><?= e($r['subject_name']) ?></td>
                                <td><?= e((string) $r['predicted_grade']) ?>%</td>
                                <td><span class="badge <?= $atRisk ? 'badge-poor' : 'badge-verygood' ?>"><?= $atRisk ? 'At Risk' : 'On Track' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Subjects Performance Overview</h3><a href="subjects.php" class="link">View All</a></div>
                    <?php foreach ($subjPerf as $sp): ?>
                        <div style="margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;">
                                <span><?= e($sp['icon']) ?> <?= e($sp['name']) ?></span><span style="font-weight:700;"><?= round($sp['avg_g'],1) ?>%</span>
                            </div>
                            <div class="progress-bar"><div style="width:<?= round($sp['avg_g']) ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card">
                    <h3>Quick Actions</h3>
                    <div class="rec-item"><div class="rec-ic blue">⬆️</div><div><a href="import-data.php" class="rec-title">Import Student Data</a><div class="rec-desc">Upload grades, attendance and more</div></div></div>
                    <div class="rec-item"><div class="rec-ic blue">🔮</div><div><a href="predictions.php" class="rec-title">Make New Prediction</a><div class="rec-desc">Run AI prediction for students</div></div></div>
                    <div class="rec-item"><div class="rec-ic blue">📄</div><div><a href="reports.php" class="rec-title">Generate Report</a><div class="rec-desc">Create and export reports</div></div></div>
                    <div class="rec-item"><div class="rec-ic blue">📈</div><div><a href="students.php" class="rec-title">View Analytics</a><div class="rec-desc">Explore detailed analytics</div></div></div>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
<script>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($h) => date('M j', strtotime($h['created_at'])), $history)) ?>,
        datasets: [{
            label: 'School Average',
            data: <?= json_encode(array_map(fn($h) => round((float) $h['avg_g'], 1), $history)) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,.08)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: '#2563eb',
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, ticks: { callback: (v) => v + '%' } } },
    }
});
</script>
</body>
</html>
