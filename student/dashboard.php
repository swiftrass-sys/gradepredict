<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$active = 'dashboard';
$year = current_school_year();
$pdo = db();
$announcements = announcements_for_user($pdo, $user, 3);

// Latest prediction per real subject (subject_id > 0) for this student/year
$stmt = $pdo->prepare("
    SELECT s.id as subject_id, s.name, s.icon, p.predicted_grade, p.status
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    LEFT JOIN predictions p ON p.subject_id = s.id AND p.student_id = ss.student_id
        AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = s.id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
    WHERE ss.student_id = ? AND ss.school_year = ?
    ORDER BY s.id
");
$stmt->execute([$user['id'], $year]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grades = array_filter(array_map(fn($s) => $s['predicted_grade'], $subjects), fn($v) => $v !== null);
$overallAvg = count($grades) ? round(array_sum($grades) / count($grades), 1) : 0;
$overallStatus = status_label($overallAvg);

// Overall snapshot history (subject_id = 0 rows) for the trend line
$histStmt = $pdo->prepare("SELECT predicted_grade, created_at FROM predictions WHERE student_id = ? AND subject_id = 0 ORDER BY created_at ASC LIMIT 6");
$histStmt->execute([$user['id']]);
$history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($history)) {
    $history = [['predicted_grade' => $overallAvg, 'created_at' => date('Y-m-d')]];
}
$prevAvg = count($history) > 1 ? $history[count($history) - 2]['predicted_grade'] : $overallAvg;
$deltaAvg = round($overallAvg - $prevAvg, 1);

$lastUpdate = $pdo->prepare("SELECT MAX(created_at) m FROM predictions WHERE student_id = ?");
$lastUpdate->execute([$user['id']]);
$lastUpdateVal = $lastUpdate->fetch()['m'];

$activityStmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 3");
$activityStmt->execute([$user['id']]);
$activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

// Best / weakest subject for recommendations
$sorted = $subjects;
usort($sorted, fn($a, $b) => ($b['predicted_grade'] ?? 0) <=> ($a['predicted_grade'] ?? 0));
$best = $sorted[0] ?? null;
$weakest = end($sorted) ?: null;

// Line chart points (SVG)
$w = 640; $h = 180; $pad = 20;
$vals = array_map(fn($r) => (float) $r['predicted_grade'], $history);
$minV = 0; $maxV = 100;
$n = count($vals);
$points = [];
foreach ($vals as $i => $v) {
    $x = $n > 1 ? $pad + ($i * (($w - 2 * $pad) / ($n - 1))) : $w / 2;
    $y = $h - $pad - (($v - $minV) / ($maxV - $minV)) * ($h - 2 * $pad);
    $points[] = round($x, 1) . ',' . round($y, 1);
}
$polyline = implode(' ', $points);

// Small sparkline for the "Overall Predicted Average" data card (84x30)
$sw = 84; $sh = 30; $spad = 3;
$spoints = [];
foreach ($vals as $i => $v) {
    $x = $n > 1 ? $spad + ($i * (($sw - 2 * $spad) / ($n - 1))) : $sw / 2;
    $y = $sh - $spad - (($v - $minV) / ($maxV - $minV)) * ($sh - 2 * $spad);
    $spoints[] = round($x, 1) . ',' . round($y, 1);
}
$sparkline = implode(' ', $spoints);
$sparkFill = $sparkline !== '' ? ($spad . ',' . $sh . ' ' . $sparkline . ' ' . ($sw - $spad) . ',' . $sh) : '';

// Mini bars for the "Subjects" data card — one bar per enrolled subject
$barVals = array_map(fn($s) => $s['predicted_grade'] !== null ? (float) $s['predicted_grade'] : 0, array_slice($subjects, 0, 8));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — GradePredict AI</title>
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
            <div class="page-head">
                <div class="ph-left">
                    <div class="ph-avatar">
                        <?php if ($avatarUrl = avatar_url($user)): ?>
                            <img src="<?= e($avatarUrl) ?>" alt="">
                        <?php else: ?>
                            <?= e(initials($user['full_name'])) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2><?= e(daypart_greeting()) ?>, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
                        <p>School year <?= e($year) ?></p>
                    </div>
                </div>
                <div class="ph-actions">
                    <a href="data-inputs.php" class="btn btn-outline btn-sm"><?= icon('edit', 16) ?> Update my data</a>
                    <a href="predictions.php" class="btn btn-primary btn-sm"><?= icon('trend', 16) ?> View predictions</a>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/announcements_banner.php'; ?>

            <div class="grid grid-4" style="margin-bottom:18px;align-items:stretch;">
                <div class="card data-card">
                    <div class="dc-top">
                        <div>
                            <div class="label-quiet">Overall Predicted Average</div>
                            <div class="stat-value"><?= e((string) $overallAvg) ?>%</div>
                        </div>
                        <?php if ($sparkline !== ''): ?>
                        <svg class="dc-spark" viewBox="0 0 <?= $sw ?> <?= $sh ?>" preserveAspectRatio="none" aria-hidden="true">
                            <polygon class="fill" points="<?= e($sparkFill) ?>" fill="var(--blue)"></polygon>
                            <polyline points="<?= e($sparkline) ?>" fill="none" stroke="var(--blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <div class="stat-sub" style="color:var(--green-text)"><?= e($overallStatus) ?></div>
                    <div class="stat-sub <?= $deltaAvg >= 0 ? 'up' : 'down' ?>"><?= $deltaAvg >= 0 ? '↑' : '↓' ?> <?= e((string) abs($deltaAvg)) ?>% vs last prediction</div>
                </div>

                <div class="card data-card">
                    <div class="dc-top">
                        <div>
                            <div class="label-quiet">Predicted GPA</div>
                            <div class="stat-value"><?= number_format(grade_point($overallAvg), 2) ?></div>
                        </div>
                        <div class="stat-icon green" aria-hidden="true"><?= icon('trend', 20) ?></div>
                    </div>
                    <div class="progress-bar thin success" style="margin-top:10px;" data-tooltip="<?= e((string) $overallAvg) ?>% of 100%">
                        <i style="width:<?= max(3, min(100, $overallAvg)) ?>%"></i>
                    </div>
                    <div class="stat-sub" style="color:var(--green-text)"><?= e($overallStatus) ?> (<?= e(letter_grade($overallAvg)) ?>)</div>
                </div>

                <div class="card data-card">
                    <div class="dc-top">
                        <div>
                            <div class="label-quiet">Subjects</div>
                            <div class="stat-value"><?= count($subjects) ?></div>
                        </div>
                        <?php if (!empty($barVals)): ?>
                        <div class="dc-minibar" aria-hidden="true">
                            <?php foreach ($barVals as $bv): ?>
                                <span class="<?= $bv >= 75 ? 'hi' : '' ?>" style="height:<?= max(4, round(($bv / 100) * 28)) ?>px"></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="stat-sub muted">Enrolled</div>
                </div>

                <div class="card data-card">
                    <div class="dc-top">
                        <div>
                            <div class="label-quiet">Predictions Updated</div>
                            <div class="stat-value" style="font-size:16px;"><?= $lastUpdateVal ? e(date('M j, Y', strtotime($lastUpdateVal))) : 'Not yet' ?></div>
                        </div>
                        <div class="stat-icon amber" aria-hidden="true"><?= icon('calendar', 20) ?></div>
                    </div>
                    <div id="smartWeatherCard" class="weather-card loading" data-unit="c" style="padding:0;margin-top:10px;">
                        <div><div class="wc-temp skeleton" style="width:38px;border-radius:6px;">--°</div><div class="wc-meta">Loading weather</div></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-3" style="margin-bottom:18px;">
                <div class="card">
                    <h3>Predicted Grade Overview</h3>
                    <div class="donut-wrap">
                        <div class="donut" style="background:conic-gradient(var(--blue) 0% <?= e((string)$overallAvg) ?>%, var(--gray-200) <?= e((string)$overallAvg) ?>% 100%);">
                            <div class="donut-inner">
                                <div class="big"><?= e((string) $overallAvg) ?>%</div>
                                <div class="lbl"><?= e($overallStatus) ?></div>
                            </div>
                        </div>
                        <div class="donut-scale"><span>0%</span><span>50%</span><span>100%</span></div>
                        <p style="text-align:center;color:var(--text-muted);font-size:13px;margin-top:14px;">Your overall academic performance looks <?= $overallAvg >= 80 ? 'great' : ($overallAvg >= 70 ? 'good' : 'like it needs attention') ?>. <?= $overallAvg >= 70 ? 'Keep up the good work.' : 'Update your data inputs for a better prediction.' ?></p>
                        <a href="predictions.php" class="btn btn-outline btn-block">View Detailed Analysis</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h3>Subjects</h3>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if (!empty($subjects)): ?>
                            <div class="view-toggle" role="group" aria-label="Switch view">
                                <button type="button" id="subjViewTable" class="active" aria-pressed="true">Table</button>
                                <button type="button" id="subjViewCompact" aria-pressed="false">Progress</button>
                            </div>
                            <?php endif; ?>
                            <a href="subjects.php" class="link">View All</a>
                        </div>
                    </div>
                    <?php if (empty($subjects)): ?>
                        <div class="empty-state-card">
                            <div class="empty-ic-wrap"><?= icon('book', 24) ?></div>
                            <h4>No subjects enrolled yet</h4>
                            <p>Predictions appear here once you're enrolled in a subject.</p>
                            <a href="subjects.php" class="btn btn-primary btn-sm">Go to My Subjects</a>
                        </div>
                    <?php else: ?>
                        <table id="subjTableView">
                            <thead><tr><th>Subject</th><th>Predicted Grade</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($subjects as $s): ?>
                                <tr>
                                    <td class="row-icon"><span class="subj-icon"><?= e($s['icon']) ?></span><?= e($s['name']) ?></td>
                                    <td><?= $s['predicted_grade'] !== null ? e((string) $s['predicted_grade']) . '%' : '—' ?></td>
                                    <td><?php if ($s['status']): ?><span class="badge <?= status_badge_class($s['status']) ?>"><?= e($s['status']) ?></span><?php else: ?>—<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="subjCompactView" style="display:none;">
                            <?php foreach ($subjects as $s): $g = $s['predicted_grade'] !== null ? (float) $s['predicted_grade'] : 0; ?>
                                <div class="progress-row">
                                    <span class="pr-label"><span class="subj-icon" style="width:22px;height:22px;font-size:11px;"><?= e($s['icon']) ?></span><?= e($s['name']) ?></span>
                                    <div class="progress-bar thin <?= $g >= 85 ? 'success' : ($g >= 70 ? '' : 'warning') ?>"><i style="width:<?= max(2, $g) ?>%"></i></div>
                                    <span class="pr-val"><?= $s['predicted_grade'] !== null ? e((string) $s['predicted_grade']) . '%' : '—' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <script>
                        (function(){
                          var t=document.getElementById('subjViewTable'),c=document.getElementById('subjViewCompact'),
                              tv=document.getElementById('subjTableView'),cv=document.getElementById('subjCompactView');
                          if(!t||!c) return;
                          t.addEventListener('click',function(){t.classList.add('active');c.classList.remove('active');t.setAttribute('aria-pressed','true');c.setAttribute('aria-pressed','false');tv.style.display='';cv.style.display='none';});
                          c.addEventListener('click',function(){c.classList.add('active');t.classList.remove('active');c.setAttribute('aria-pressed','true');t.setAttribute('aria-pressed','false');tv.style.display='none';cv.style.display='';});
                        })();
                        </script>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Predicted Grade Over Time</h3></div>
                    <canvas id="trendChart" height="180" data-tooltip-hint="Hover a point for the exact value"></canvas>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="card">
                    <h3>What Affects Your Grades?</h3>
                    <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                        <div class="donut" style="width:150px;height:150px;background:conic-gradient(var(--blue) 0% 40%, var(--green) 40% 65%, var(--amber) 65% 80%, var(--purple) 80% 90%, #ec4899 90% 100%);">
                            <div class="donut-inner" style="width:95px;height:95px;font-size:11px;text-align:center;font-weight:700;color:var(--text-muted);">Key<br>Factors</div>
                        </div>
                        <div class="legend">
                            <div class="legend-item"><span class="name"><span class="dot" style="background:var(--blue)"></span>Past Grades</span><span class="val">40%</span></div>
                            <div class="legend-item"><span class="name"><span class="dot" style="background:var(--green)"></span>Attendance</span><span class="val">25%</span></div>
                            <div class="legend-item"><span class="name"><span class="dot" style="background:var(--amber)"></span>Assignments</span><span class="val">15%</span></div>
                            <div class="legend-item"><span class="name"><span class="dot" style="background:var(--purple)"></span>Quizzes</span><span class="val">10%</span></div>
                            <div class="legend-item"><span class="name"><span class="dot" style="background:#ec4899"></span>Participation</span><span class="val">10%</span></div>
                        </div>
                    </div>
                    <p style="color:var(--text-muted);font-size:12.5px;margin-top:14px;">These factors are based on AI analysis of your data.</p>
                </div>

                <div class="card">
                    <h3>Recommendations</h3>
                    <?php if ($best): ?>
                    <div class="rec-item">
                        <div class="rec-ic green">✅</div>
                        <div><div class="rec-title">Keep up your great performance in <?= e($best['name']) ?>!</div>
                        <div class="rec-desc">You're predicted at <?= e((string) $best['predicted_grade']) ?>% — your strongest subject.</div></div>
                    </div>
                    <?php endif; ?>
                    <div class="rec-item">
                        <div class="rec-ic amber">⏰</div>
                        <div><div class="rec-title">Try to improve your attendance</div>
                        <div class="rec-desc">Consistent attendance can boost your grades.</div></div>
                    </div>
                    <?php if ($weakest): ?>
                    <div class="rec-item">
                        <div class="rec-ic blue">📝</div>
                        <div><div class="rec-title">Focus more on <?= e($weakest['name']) ?></div>
                        <div class="rec-desc">Submit more assignments on time to improve this subject's score.</div></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Recent Activity</h3><a href="#" class="link">View All</a></div>
                    <?php if (empty($activities)): ?>
                        <div class="empty-state-card">
                            <div class="empty-ic-wrap"><?= icon('clock', 24) ?></div>
                            <h4>No recent activity</h4>
                            <p>Your logins and data changes will be listed here.</p>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($activities as $a): ?>
                        <div class="act-item">
                            <div class="rec-ic blue"><?= $a['action'] === 'Login' ? '🔑' : '➕' ?></div>
                            <div>
                                <div class="rec-title"><?= e($a['action']) ?></div>
                                <div class="rec-desc"><?= e($a['description']) ?></div>
                                <div class="act-date"><?= e(date('M j, Y • g:i A', strtotime($a['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
            label: 'Predicted Average',
            data: <?= json_encode(array_map(fn($h) => (float) $h['predicted_grade'], $history)) ?>,
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
