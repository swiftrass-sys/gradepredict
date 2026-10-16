<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'reports';
$pdo = db();

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$validDate = fn($d) => $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$hasRange = $validDate($from) && $validDate($to);

$where = "WHERE p.subject_id > 0";
$params = [];
if ($hasRange) {
    $where .= " AND date(p.created_at) BETWEEN ? AND ?";
    $params = [$from, $to];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("
        SELECT u.full_name, u.email, s.name AS subject_name, p.predicted_grade, p.status, p.created_at
        FROM predictions p
        JOIN users u ON u.id = p.student_id
        JOIN subjects s ON s.id = p.subject_id
        $where
        ORDER BY u.full_name, s.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="gradepredict_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Name', 'Email', 'Subject', 'Predicted Grade', 'Status', 'Generated At']);
    foreach ($rows as $r) {
        fputcsv($out, [csv_safe($r['full_name']), csv_safe($r['email']), csv_safe($r['subject_name']), $r['predicted_grade'], $r['status'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$totalStudents = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role='student' AND account_status='approved'")->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM predictions p $where");
$stmt->execute($params);
$totalPredictions = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT AVG(predicted_grade) a FROM predictions p $where");
$stmt->execute($params);
$avgGrade = round((float) ($stmt->fetch()['a'] ?? 0), 1);

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM predictions p $where AND predicted_grade < 70");
$stmt->execute($params);
$atRisk = (int) $stmt->fetch()['c'];

$exportQuery = $hasRange ? '&from=' . urlencode($from) . '&to=' . urlencode($to) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — GradePredict AI Admin</title>
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
                <div><h2>Reports</h2><p>Summary of prediction data across the school<?= $hasRange ? ', filtered to your selected date range' : '' ?>.</p></div>
                <div style="display:flex;gap:10px;">
                    <a href="reports-print.php<?= $hasRange ? '?' . http_build_query(['from' => $from, 'to' => $to]) : '' ?>" target="_blank" class="btn btn-outline" data-tooltip="Opens a printable report you can Save as PDF">🖨️ Print / PDF</a>
                    <a href="?export=csv<?= $exportQuery ?>" class="btn btn-primary" data-tooltip="Downloads a CSV of every row shown below">⬇️ Export Report (CSV)</a>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px;">
                <form method="get" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                    <div class="form-group" style="margin-bottom:0;min-width:180px;">
                        <label>From</label>
                        <input class="input" type="date" name="from" value="<?= e($from) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;min-width:180px;">
                        <label>To</label>
                        <input class="input" type="date" name="to" value="<?= e($to) ?>">
                    </div>
                    <button type="submit" class="btn btn-outline">Apply Filter</button>
                    <?php if ($hasRange): ?><a href="reports.php" class="btn btn-outline">Clear</a><?php endif; ?>
                </form>
            </div>

            <div class="grid grid-4">
                <div class="card"><div class="stat-label">Total Students</div><div class="stat-value"><?= $totalStudents ?></div></div>
                <div class="card"><div class="stat-label">Predictions<?= $hasRange ? ' in Range' : '' ?></div><div class="stat-value"><?= $totalPredictions ?></div></div>
                <div class="card"><div class="stat-label">Average Predicted Grade</div><div class="stat-value"><?= $avgGrade ?>%</div></div>
                <div class="card"><div class="stat-label">At-Risk Predictions</div><div class="stat-value" style="color:var(--red)"><?= $atRisk ?></div></div>
            </div>

            <?php if ($totalPredictions === 0): ?>
                <div class="card empty-state" style="margin-top:18px;">
                    <div class="empty-ic">📭</div>
                    No predictions were generated in this date range. Try widening it, or <a href="reports.php" class="link">clear the filter</a>.
                </div>
            <?php else: ?>
            <div class="card" style="margin-top:18px;">
                <h3>About this report</h3>
                <p style="color:var(--text-muted);font-size:14px;">
                    The exported CSV contains one row per student per subject, with the student's name, email, subject,
                    latest predicted grade, status, and the date the prediction was generated. Use it to share results
                    with teachers or to archive a snapshot of a grading period.
                </p>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
