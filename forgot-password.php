<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    header('Location: ' . BASE_URL . portal_path_for(current_user()['role']));
    exit;
}

$error = '';
$resetLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $pdo = db();

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always show the same success message whether or not the email exists,
        // so this form can't be used to check which emails are registered.
        if ($user) {
            $token = create_password_reset($pdo, (int) $user['id']);
            $resetLink = BASE_URL . '/reset-password.php?token=' . $token;
            log_activity((int) $user['id'], 'Password Reset Requested', 'A reset link was generated');
        } else {
            $resetLink = 'sent';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-grid">
    <div class="auth-side">
      <h2>Forgot your password?</h2>
      <p>No worries — enter your email and we'll help you get back in.</p>
      <div class="auth-quote">"Predict today, achieve tomorrow."</div>
    </div>

    <div class="auth-card">
      <?php $brandTagline = 'GradePredict AI System'; include __DIR__ . '/includes/brand.php'; ?>
      <h2>Reset Password</h2>
      <p class="sub">Enter the email address on your account</p>

      <?= $error ? render_alert('error', $error) : '' ?>

      <?php if ($resetLink === 'sent'): ?>
        <?= render_alert('success', 'If that email is registered, a reset link has been generated.') ?>
      <?php elseif ($resetLink): ?>
        <?= render_alert('success', 'Reset link generated below. (This demo has no mail server configured, so the link is shown directly instead of emailed — in production this would be sent to your inbox.)') ?>
        <div class="card" style="padding:16px;word-break:break-all;font-size:13px;margin-bottom:18px;">
          <a href="<?= e($resetLink) ?>" class="link"><?= e($resetLink) ?></a>
        </div>
      <?php else: ?>
        <form method="post" action="forgot-password.php">
          <?= csrf_field() ?>
          <div class="form-group">
            <label>Email Address</label>
            <div class="input-icon-wrap">
              <span class="ic-left">📧</span>
              <input class="input" type="email" name="email" placeholder="Enter your email address" required>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>
      <?php endif; ?>

      <p class="auth-foot">Remembered it? <a href="login.php">Back to Login</a></p>
    </div>
  </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
