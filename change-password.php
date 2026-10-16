<?php
require_once __DIR__ . '/config.php';

// Deliberately uses current_user() directly (not require_login()) to avoid the
// must-change-password redirect loop, since this IS that redirect's destination.
$user = current_user();
if (!$user) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        forget_all_tokens_for($pdo, (int) $user['id']);
        log_activity((int) $user['id'], 'Password Changed', 'Completed forced password change');
        header('Location: ' . BASE_URL . portal_path_for($user['role']));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set a New Password — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-grid">
    <div class="auth-side">
      <img src="assets/img/auth-illustration.jpg" alt="" class="auth-illustration">
      <h2>Welcome, <?= e($user['full_name']) ?>!</h2>
      <p>For your security, please set your own password before continuing — you're currently using a temporary one.</p>
      <div class="auth-quote">"Predict today, achieve tomorrow."</div>
    </div>

    <div class="auth-card">
      <?php $brandTagline = 'GradePredict AI System'; include __DIR__ . '/includes/brand.php'; ?>
      <h2>Set a New Password</h2>
      <p class="sub">This is required before you can use your account</p>

      <?= $error ? render_alert('error', $error) : '' ?>

      <form method="post" action="change-password.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label>New Password</label>
          <div class="input-icon-wrap">
            <span class="ic-left">🔒</span>
            <input class="input" type="password" id="newPass" name="new_password" placeholder="Create a new password" required minlength="6">
            <button type="button" class="ic-right" data-toggle-pass="newPass">👁️</button>
          </div>
        </div>
        <div class="form-group">
          <label>Confirm New Password</label>
          <div class="input-icon-wrap">
            <span class="ic-left">🔒</span>
            <input class="input" type="password" id="newPass2" name="confirm_password" placeholder="Confirm new password" required minlength="6">
            <button type="button" class="ic-right" data-toggle-pass="newPass2">👁️</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Set Password &amp; Continue</button>
      </form>
    </div>
  </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
