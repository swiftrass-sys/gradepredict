<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms of Service — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body style="background:var(--bg);">
<div style="max-width:760px;margin:0 auto;padding:50px 24px;">
    <a href="index.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;margin-bottom:30px;">
        <img src="assets/img/logo.png" alt="" class="logo-img" style="width:34px;height:34px;">
        <strong style="color:var(--text-dark);">Colegio de Santa Rita de San Carlos, Inc.</strong>
    </a>
    <div class="card">
        <h2 style="margin-bottom:6px;">Terms of Service</h2>
        <p style="color:var(--text-muted);font-size:13px;margin-bottom:24px;">Last updated <?= date('F Y') ?></p>

        <h3>1. Purpose of this platform</h3>
        <p>GradePredict AI is provided by Colegio de Santa Rita de San Carlos, Inc. to help students, parents, teachers, and administrators track academic performance and view grade predictions generated from recorded academic data.</p>

        <h3>2. Accounts</h3>
        <p>You are responsible for keeping your login credentials confidential and for all activity under your account. Notify a school administrator immediately if you suspect unauthorized access.</p>

        <h3>3. Acceptable use</h3>
        <p>Accounts may only be used for their intended purpose within the school community. Attempting to access another person's records without authorization, or to disrupt or misuse the platform, may result in account suspension.</p>

        <h3>4. Predictions are estimates</h3>
        <p>Predicted grades are calculated from a transparent, weighted formula based on recorded data (past grades, attendance, assignments, quizzes, and participation). They are estimates intended to support academic planning and early intervention — they are not official, final grades.</p>

        <h3>5. Data accuracy</h3>
        <p>Grade and attendance data is entered and maintained by teachers and administrators. If you believe a record is inaccurate, please contact your teacher or the school registrar.</p>

        <h3>6. Changes</h3>
        <p>These terms may be updated from time to time. Continued use of the platform after changes are posted constitutes acceptance of the revised terms.</p>

        <h3>7. Contact</h3>
        <p>Questions about these terms can be directed to the school administration.</p>
    </div>
    <p style="text-align:center;margin-top:20px;"><a href="index.php" class="link">← Back to home</a></p>
</div>
</body>
</html>
