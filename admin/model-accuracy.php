<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'accuracy';
$pdo = db();
$message = flash_get();
$year = trim((string) ($_GET['year'] ?? $_POST['year'] ?? '')) ?: current_school_year();
$subjectId = (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);

// ---- Save final grades for one subject ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_finals'])) {
    verify_csrf();
    $saved = 0; $bad = 0;
    $enrolled = $pdo->prepare("SELECT 1 FROM student_subjects WHERE student_id = ? AND subject_id = ? AND school_year = ?");
    $upd = $pdo->prepare("UPDATE final_grades SET final_grade = ?, recorded_by = ?, recorded_at = ? WHERE student_id = ? AND subject_id = ? AND school_year = ?");
    $ins = $pdo->prepare("INSERT INTO final_grades (student_id, subject_id, school_year, final_grade, recorded_by) VALUES (?, ?, ?, ?, ?)");
    foreach ((array) ($_POST['final'] ?? []) as $sid => $val) {
        $val = trim((string) $val);
        if ($val === '') { continue; }
        $sid = (int) $sid;
        $enrolled->execute([$sid, $subjectId, $year]);
        if (!is_numeric($val) || (float) $val < 0 || (float) $val > 100 || !$enrolled->fetchColumn()) { $bad++; continue; }
        $upd->execute([round((float) $val, 2), $user['id'], date('Y-m-d H:i:s'), $sid, $subjectId, $year]);
        if ($upd->rowCount() === 0) {
            $chk = $pdo->prepare("SELECT 1 FROM final_grades WHERE student_id = ? AND subject_id = ? AND school_year = ?");
            $chk->execute([$sid, $subjectId, $year]);
            if (!$chk->fetchColumn()) { $ins->execute([$sid, $subjectId, $year, round((float) $val, 2), $user['id']]); }
        }
        $saved++;
    }
    if ($saved) { log_activity((int) $user['id'], 'Final Grades Recorded', "$saved final grade(s) recorded for accuracy checking"); }
    flash_set($bad ? 'error' : 'success', $saved . ' final grade(s) saved.' . ($bad ? " $bad row(s) skipped (needs a number from 0 to 100 for an enrolled student)." : ''));
    header('Location: model-accuracy.php?subject_id=' . $subjectId . '&year=' . urlencode($year));
    exit;
}

// ---- Predictions compared with final grades ----
$cmp = $pdo->prepare("
    SELECT fg.student_id, fg.subject_id, fg.final_grade AS actual, u.full_name, s.name AS subject,
        (SELECT p.predicted_grade FROM predictions p WHERE p.student_id = fg.student_id AND p.subject_id = fg.subject_id AND p.school_year = fg.school_year
         ORDER BY p.created_at DESC, p.id DESC LIMIT 1) AS predicted
    FROM final_grades fg
    JOIN users u ON u.id = fg.student_id
    JOIN subjects s ON s.id = fg.subject_id
    WHERE fg.school_year = ?
    ORDER BY s.name, u.full_name");
$cmp->execute([$year]);
$rows = array_values(array_filter($cmp->fetchAll(PDO::FETCH_ASSOC), fn($r) => $r['predicted'] !== null));
$m = accuracy_metrics($rows);

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="prediction-accuracy-' . preg_replace('/[^0-9A-Za-z-]/', '', $year) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Student', 'Subject', 'Predicted', 'Actual final', 'Difference']);
    foreach ($rows as $r) {
        fputcsv($out, [csv_safe($r['full_name']), csv_safe($r['subject']), $r['predicted'], $r['actual'], round($r['predicted'] - $r['actual'], 1)]);
    }
    exit;
}

$bySubject = [];
foreach ($rows as $r) { $bySubject[$r['subject']][] = $r; }

$subjects = $pdo->prepare("SELECT DISTINCT s.id, s.name FROM student_subjects ss JOIN subjects s ON s.id = ss.subject_id WHERE ss.school_year = ? ORDER BY s.name");
$subjects->execute([$year]);
$subjects = $subjects->fetchAll(PDO::FETCH_ASSOC);

$entry = [];
if ($subjectId) {
    $e = $pdo->prepare("
        SELECT u.id, u.full_name, fg.final_grade,
            (SELECT p.predicted_grade FROM predictions p WHERE p.student_id = u.id AND p.subject_id = ss.subject_id AND p.school_year = ss.school_year ORDER BY p.created_at DESC, p.id DESC LIMIT 1) AS predicted
        FROM student_subjects ss
        JOIN users u ON u.id = ss.student_id AND u.account_status = 'approved'
        LEFT JOIN final_grades fg ON fg.student_id = u.id AND fg.subject_id = ss.subject_id AND fg.school_year = ss.school_year
        WHERE ss.subject_id = ? AND ss.school_year = ? ORDER BY u.full_name");
    $e->execute([$subjectId, $year]);
    $entry = $e->fetchAll(PDO::FETCH_ASSOC);
}
$dw = [['Past grades', W_PAST_GRADES], ['Attendance', W_ATTENDANCE], ['Assignments', W_ASSIGNMENTS], ['Quizzes', W_QUIZZES], ['Participation', W_PARTICIPATION]];
$pct = fn($v) => $v === null ? '—' : $v . '%';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Model accuracy — GradePredict AI Admin</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
    <div class="main">
        <?php $showSearch = true; include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head">
                <div><h2>Model accuracy</h2><p>How close the predictions came to the grades students actually finished with, for <?= e($year) ?>.</p></div>
                <?php if ($rows): ?><a class="btn btn-outline" href="?export=1&amp;year=<?= urlencode($year) ?>">Download CSV</a><?php endif; ?>
            </div>
            <?= render_flash($message) ?>

            <?php if (!$rows): ?>
                <div class="card empty-state-card">
                    <div class="empty-ic-wrap"><?= icon('chart', 24) ?></div>
                    <h4>No final grades recorded yet</h4>
                    <p>Once a subject ends, enter the final grades below. The system then compares each one with the last prediction made for that student.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-4 acc-stats">
                    <div class="card stat"><div class="stat-label">Records compared</div><div class="stat-value"><?= (int) $m['n'] ?></div></div>
                    <div class="card stat"><div class="stat-label">Average error</div><div class="stat-value"><?= e((string) $m['mae']) ?> pts</div><div class="stat-sub">Mean absolute error</div></div>
                    <div class="card stat"><div class="stat-label">Within 5 points</div><div class="stat-value"><?= e($pct($m['within5'])) ?></div><div class="stat-sub">of predictions</div></div>
                    <div class="card stat"><div class="stat-label">Same grade band</div><div class="stat-value"><?= e($pct($m['band_match'])) ?></div><div class="stat-sub">Excellent, Very Good, Good, Fair, Poor</div></div>
                </div>

                <div class="grid grid-2" style="margin-top:18px;">
                    <div class="card">
                        <h3>At-risk detection</h3>
                        <p class="label-quiet" style="margin:0 0 12px;">"At risk" means below 70. This shows how well a low prediction matched a low final grade.</p>
                        <table class="table-compact">
                            <thead><tr><th></th><th>Final below 70</th><th>Final 70 or above</th></tr></thead>
                            <tbody>
                                <tr><th scope="row">Predicted below 70</th><td><?= (int) $m['risk_tp'] ?></td><td><?= (int) $m['risk_fp'] ?></td></tr>
                                <tr><th scope="row">Predicted 70 or above</th><td><?= (int) $m['risk_fn'] ?></td><td><?= (int) $m['risk_tn'] ?></td></tr>
                            </tbody>
                        </table>
                        <p style="margin:12px 0 0;font-size:13.5px;">Caught <strong><?= e($pct($m['risk_recall'])) ?></strong> of students who finished below 70.
                            Of those flagged, <strong><?= e($pct($m['risk_precision'])) ?></strong> really did.</p>
                        <p class="label-quiet" style="margin:10px 0 0;">Typical error (RMSE) <?= e((string) $m['rmse']) ?> pts. Average bias <?= e(($m['bias'] > 0 ? '+' : '') . $m['bias']) ?> pts (<?= $m['bias'] > 0.5 ? 'predictions run high' : ($m['bias'] < -0.5 ? 'predictions run low' : 'no consistent lean') ?>).</p>
                    </div>
                    <div class="card">
                        <h3>By subject</h3>
                        <table class="table-compact">
                            <thead><tr><th>Subject</th><th>Records</th><th>Avg error</th><th>Bias</th></tr></thead>
                            <tbody>
                            <?php foreach ($bySubject as $name => $list): $sm = accuracy_metrics($list); ?>
                                <tr><td><?= e($name) ?></td><td><?= (int) $sm['n'] ?></td><td><?= e((string) $sm['mae']) ?></td><td><?= e(($sm['bias'] > 0 ? '+' : '') . $sm['bias']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" style="margin-top:18px;">
                    <h3>All comparisons</h3>
                    <div class="table-scroll">
                    <table class="table-compact">
                        <thead><tr><th>Student</th><th>Subject</th><th>Predicted</th><th>Actual final</th><th>Difference</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): $d = round($r['predicted'] - $r['actual'], 1); ?>
                            <tr><td><?= e($r['full_name']) ?></td><td><?= e($r['subject']) ?></td><td><?= e((string) $r['predicted']) ?></td><td><?= e((string) $r['actual']) ?></td><td><?= e(($d > 0 ? '+' : '') . $d) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-2" style="margin-top:18px;">
                <div class="card">
                    <h3>Record final grades</h3>
                    <form method="get" class="btn-row" style="align-items:flex-end;">
                        <div class="form-group" style="margin:0;flex:1;min-width:200px;"><label for="subject_id">Subject</label>
                            <select class="input" id="subject_id" name="subject_id">
                                <option value="">Choose a subject</option>
                                <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group" style="margin:0;"><label for="year">School year</label><input class="input" id="year" name="year" value="<?= e($year) ?>" style="width:150px;"></div>
                        <button class="btn btn-outline" type="submit">Load</button>
                    </form>
                    <?php if ($subjectId && !$entry): ?><p class="label-quiet" style="margin-top:14px;">No approved students are enrolled in that subject for <?= e($year) ?>.</p><?php endif; ?>
                    <?php if ($entry): ?>
                    <form method="post" style="margin-top:14px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="year" value="<?= e($year) ?>">
                        <table class="table-compact">
                            <thead><tr><th>Student</th><th>Last prediction</th><th>Final grade</th></tr></thead>
                            <tbody>
                            <?php foreach ($entry as $r): ?>
                                <tr><td><?= e($r['full_name']) ?></td><td><?= $r['predicted'] !== null ? e((string) $r['predicted']) . '%' : '<span class="label-quiet">None</span>' ?></td>
                                    <td><input class="input" type="number" min="0" max="100" step="0.01" name="final[<?= (int) $r['id'] ?>]" value="<?= $r['final_grade'] !== null ? e((string) $r['final_grade']) : '' ?>" style="width:110px;padding:6px 10px;" aria-label="Final grade for <?= e($r['full_name']) ?>"></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="submit" name="save_finals" value="1" class="btn btn-primary" style="margin-top:14px;">Save final grades</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <h3>How a prediction is made</h3>
                    <p style="font-size:13.5px;margin:0 0 10px;">The predicted grade is a weighted sum of five scores (each 0 to 100) the school records for the student in that subject:</p>
                    <table class="table-compact">
                        <tbody>
                        <?php foreach ($dw as [$label, $w]): ?><tr><td><?= e($label) ?></td><td><?= (int) round($w * 100) ?>%</td></tr><?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="label-quiet" style="margin:12px 0 0;">These are the defaults. A subject can use its own weights (set under Subjects). The result is kept between 0 and 100, then labelled Excellent (90+), Very Good (80+), Good (70+), Fair (60+) or Poor. Because the formula is fixed and visible, every prediction can be traced back to its inputs. The figures on this page show how well it holds up against real results, so the weights can be adjusted when the error is large or the bias leans one way.</p>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
