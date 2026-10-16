<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'fees';
$pdo = db();
$message = flash_get();
$semesters = ['1st Semester', '2nd Semester', 'Summer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['save_fee'])) {
        $programId = (int) $_POST['program_id'];
        $yearLevel = (int) $_POST['year_level'];
        $semester = in_array($_POST['semester'] ?? '', $semesters, true) ? $_POST['semester'] : '1st Semester';
        $amount = max(0, (float) $_POST['amount']);
        $pdo->prepare("
            INSERT INTO fee_structures (program_id, year_level, semester, amount) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount)
        ")->execute([$programId, $yearLevel, $semester, $amount]);
        flash_set('success', 'Fee structure saved.');
    } elseif (isset($_POST['delete_fee'])) {
        $pdo->prepare("DELETE FROM fee_structures WHERE id = ?")->execute([(int) $_POST['delete_fee']]);
        flash_set('success', 'Fee structure removed.');
    }
    header('Location: fees.php');
    exit;
}

$programs = $pdo->query("SELECT * FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$fees = $pdo->query("
    SELECT f.*, p.code AS program_code, p.name AS program_name
    FROM fee_structures f JOIN programs p ON p.id = f.program_id
    ORDER BY p.code, f.year_level, f.semester
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fees — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Fee Structures</h2><p>Tuition &amp; fees assessed per program, year level, and semester.</p></div></div>
            <?= render_flash($message) ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <table>
                        <thead><tr><th>Program</th><th>Year</th><th>Semester</th><th>Amount</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($fees)): ?><tr><td colspan="5" class="empty-state">No fee structures set yet.</td></tr><?php endif; ?>
                        <?php foreach ($fees as $f): ?>
                            <tr>
                                <td><?= e($f['program_code']) ?></td>
                                <td>Year <?= (int) $f['year_level'] ?></td>
                                <td><?= e($f['semester']) ?></td>
                                <td>₱<?= number_format((float) $f['amount'], 2) ?></td>
                                <td>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_fee" value="<?= $f['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" data-confirm="Remove this fee structure?">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Set Fee</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label>Program</label>
                            <select name="program_id" class="input" required>
                                <?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['code']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year Level</label>
                            <select name="year_level" class="input">
                                <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Semester</label>
                            <select name="semester" class="input">
                                <?php foreach ($semesters as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Amount (₱)</label><input class="input" type="number" name="amount" min="0" step="0.01" required></div>
                        <button type="submit" name="save_fee" value="1" class="btn btn-primary btn-block">Save Fee</button>
                    </form>
                    <p style="color:var(--text-muted);font-size:12px;margin-top:12px;">Setting a fee for an existing program/year/semester combination updates it.</p>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
