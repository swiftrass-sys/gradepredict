<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
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
$year = current_school_year();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>GradePredict AI — Report</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #1e293b; padding: 30px; }
    h1 { font-size: 20px; margin-bottom: 2px; }
    .sub { color: #64748b; font-size: 13px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
    th { background: #f4f6fb; }
    .badge { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .no-print { margin-bottom: 20px; }
    @media print { .no-print { display: none; } }
</style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding:10px 16px;background:#2563eb;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;">🖨️ Print / Save as PDF</button>
        <a href="reports.php" style="margin-left:10px;">← Back to Reports</a>
    </div>
    <h1>GradePredict AI — Prediction Report</h1>
    <div class="sub">
        School Year <?= e($year) ?><?= $hasRange ? ' · ' . e($from) . ' to ' . e($to) : '' ?> · Generated <?= date('F j, Y g:i A') ?>
    </div>
    <table>
        <thead><tr><th>Student</th><th>Email</th><th>Subject</th><th>Predicted Grade</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= e($r['subject_name']) ?></td>
                <td><?= e((string) $r['predicted_grade']) ?>%</td>
                <td><?= e($r['status']) ?></td>
                <td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body>
</html>
