<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$active = 'finance';
$year = current_school_year();
$semester = current_semester();
$pdo = db();

$assessed = fee_amount_for($pdo, $user['program_id'] ?? null, $user['year_level'] ?? null, $semester);
$paid = total_paid($pdo, (int) $user['id'], $year, $semester);
$balance = max(0, $assessed - $paid);

$payments = $pdo->prepare("SELECT * FROM payments WHERE student_id = ? ORDER BY created_at DESC");
$payments->execute([$user['id']]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Finance — GradePredict AI</title>
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
            <div class="page-head"><div><h2>My Finance</h2><p>Your tuition &amp; fees for <?= e($semester) ?>, <?= e($year) ?>.</p></div></div>

            <div class="grid grid-4" style="margin-bottom:18px;">
                <div class="card stat-card">
                    <div class="stat-icon blue">📄</div>
                    <div><div class="stat-label">Assessed</div><div class="stat-value">₱<?= number_format($assessed, 2) ?></div></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon green">✅</div>
                    <div><div class="stat-label">Paid</div><div class="stat-value">₱<?= number_format($paid, 2) ?></div></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon <?= $balance > 0 ? 'amber' : 'green' ?>">💰</div>
                    <div><div class="stat-label">Balance</div><div class="stat-value" style="<?= $balance > 0 ? 'color:var(--red);' : '' ?>">₱<?= number_format($balance, 2) ?></div></div>
                </div>
            </div>

            <?php if ($balance > 0 && $assessed > 0): ?>
                <?= render_alert('error', 'You have an outstanding balance of ₱' . number_format($balance, 2) . '. New subject enrollment is on hold until this is settled with the Cashier.') ?>
            <?php elseif ($assessed === 0.0): ?>
                <?= render_alert('error', 'No fee structure has been set for your program/year/semester yet. Please check with the Cashier/Registrar.') ?>
            <?php else: ?>
                <?= render_alert('success', 'You are fully paid for this semester. Thank you!') ?>
            <?php endif; ?>

            <div class="card">
                <h3>Payment History</h3>
                <table>
                    <thead><tr><th>Date</th><th>School Year</th><th>Semester</th><th>OR Number</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php if (empty($payments)): ?><tr><td colspan="5" class="empty-state">No payments recorded yet.</td></tr><?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e(date('M j, Y', strtotime($p['created_at']))) ?></td>
                            <td><?= e($p['school_year']) ?></td>
                            <td><?= e($p['semester']) ?></td>
                            <td><?= e($p['or_number'] ?? '—') ?></td>
                            <td>₱<?= number_format((float) $p['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
