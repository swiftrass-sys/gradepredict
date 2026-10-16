<?php
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$pdo = db();
$resetRow = $token ? validate_reset_token($pdo, $token) : null;
$error = '';
$done = false;

if (!$resetRow) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $resetRow['user_id']]);
        consume_reset_token($pdo, (int) $resetRow['id']);
        forget_all_tokens_for($pdo, (int) $resetRow['user_id']); // sign out any "remember me" sessions elsewhere
        log_activity((int) $resetRow['user_id'], 'Password Reset', 'Password changed via reset link');
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-grid">
    <div class="auth-side">
      <h2>Choose a new password</h2>
      <p>Make it something you'll remember, but that others won't guess.</p>
      <div class="auth-quote">"Predict today, achieve tomorrow."</div>
    </div>

    <div class="auth-card">
      <?php $brandTagline = 'GradePredict AI System'; include __DIR__ . '/includes/brand.php'; ?>
      <h2>Reset Password</h2>

      <?php if ($done): ?>
        <?= render_alert('success', 'Your password has been updated. You can now log in.') ?>
        <a href="login.php" class="btn btn-primary btn-block">Go to Login</a>
      <?php else: ?>
        <?= $error ? render_alert('error', $error) : '' ?>
        <?php if ($resetRow): ?>
        <form method="post" action="reset-password.php">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="form-group">
            <label>New Password</label>
            <div class="input-icon-wrap">
              <span class="ic-left">🔒</span>
              <input class="input" type="password" id="newPass" name="password" placeholder="Create a new password" required minlength="6">
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
          <button type="submit" class="btn btn-primary btn-block">Update Password</button>
        </form>
        <?php else: ?>
          <a href="forgot-password.php" class="btn btn-outline btn-block">Request a New Link</a>
        <?php endif; ?>
      <?php endif; ?>

      <p class="auth-foot">Remembered it? <a href="login.php">Back to Login</a></p>
    </div>
  </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
