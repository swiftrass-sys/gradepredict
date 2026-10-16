<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy — GradePredict AI</title>
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
        <h2 style="margin-bottom:6px;">Privacy Policy</h2>
        <p style="color:var(--text-muted);font-size:13px;margin-bottom:24px;">Last updated <?= date('F Y') ?></p>

        <h3>1. What we collect</h3>
        <p>To operate GradePredict AI, we store: your name, email address, role (student/teacher/parent/admin), and — for students — academic data such as past grades, attendance, assignment scores, quizzes, and participation, along with the predictions calculated from that data. If you upload a profile photo, we store that image. We also keep a security/activity log of key account actions (logins, password changes, and data updates) for account security and audit purposes.</p>

        <h3>2. How it's used</h3>
        <p>Your data is used only to operate the platform: generating grade predictions, showing you or your linked accounts (e.g. a parent's linked child) the relevant records, and maintaining account security. We do not sell or share student data with third parties, and we do not use it for advertising.</p>

        <h3>3. Who can see what</h3>
        <p>Students see their own data. Parents see only the children explicitly linked to their account by a school administrator. Teachers see only the students in the subject/section they are assigned to teach. Administrators can see school-wide data as needed to run the platform.</p>

        <h3>4. Data security</h3>
        <p>Passwords are stored using industry-standard one-way hashing (never in plain text). All data-changing actions require a valid, verified session. Uploaded files are validated before storage. Access to the underlying database is restricted to the server itself.</p>

        <h3>5. Data retention</h3>
        <p>Data is retained for as long as your account is active with the school. If you would like your data exported or your account deleted, please contact a school administrator.</p>

        <h3>6. Cookies</h3>
        <p>We use a session cookie to keep you logged in, and — only if you choose "Remember me" — a long-lived login cookie so you don't have to log in every visit. Neither cookie is used for advertising or cross-site tracking.</p>

        <h3>7. Contact</h3>
        <p>Questions about this policy, or requests regarding your data, can be directed to the school administration.</p>
    </div>
    <p style="text-align:center;margin-top:20px;"><a href="index.php" class="link">← Back to home</a></p>
</div>
</body>
</html>
