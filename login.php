<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    $u = current_user();
    header('Location: ' . BASE_URL . portal_path_for($u['role']));
    exit;
}

$error = '';
$notice = '';
if (isset($_GET['timeout'])) {
    $error = 'You were signed out due to inactivity. Please log in again.';
}
if (isset($_GET['pending'])) {
    $notice = 'Your account was created and is waiting for approval. You will be able to sign in once an administrator or teacher approves it.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    $pdo = db();

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && is_locked_out($user)) {
            $minutesLeft = max(1, (int) ceil((strtotime($user['locked_until']) - time()) / 60));
            $error = "Too many failed attempts. Please try again in $minutesLeft minute(s).";
        } elseif ($user && password_verify($password, $user['password_hash']) && ($user['account_status'] ?? 'approved') === 'pending') {
            // Correct password, but nobody has approved this account yet.
            $error = 'Your account is still waiting for approval. Please try again after an administrator or teacher has approved it.';
        } elseif ($user && password_verify($password, $user['password_hash']) && ($user['account_status'] ?? 'approved') === 'rejected') {
            $error = 'Your registration was not approved. Please contact your school administrator.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            reset_failed_login($pdo, (int) $user['id']);
            login_user($user);
            if ($remember) {
                create_remember_token($pdo, (int) $user['id']);
            }
            log_activity((int) $user['id'], 'Login', 'User signed in');
            header('Location: ' . BASE_URL . portal_path_for($user['role']));
            exit;
        } else {
            if ($user) {
                register_failed_login($pdo, (int) $user['id']);
            }
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-grid">
    <div class="auth-side">
      <img src="<?= BASE_URL ?>/assets/img/auth-illustration.jpg" alt="GradePredict AI dashboard preview" class="auth-illustration">
      <h2>Welcome Back!</h2>
      <p>Sign in to access your account and continue predicting success.</p>
      <div class="auth-feature"><div class="dot-ic">⚙️</div><div><h5>AI-Powered Predictions</h5><p>Get accurate grade predictions based on your data.</p></div></div>
      <div class="auth-feature"><div class="dot-ic">📊</div><div><h5>Smart Insights</h5><p>Understand your performance and improve effectively.</p></div></div>
      <div class="auth-feature"><div class="dot-ic">🎯</div><div><h5>Better Outcomes</h5><p>Make data-driven decisions for a brighter future.</p></div></div>
      <div class="auth-quote">"Predict today, achieve tomorrow."</div>
    </div>

    <div class="auth-card">
      <?php $brandTagline = 'GradePredict AI System'; include __DIR__ . '/includes/brand.php'; ?>
      <h2>Welcome Back</h2>
      <p class="sub">Login to your account</p>

      <?= $notice ? render_alert('success', $notice) : '' ?>
      <?= $error ? render_alert('error', $error) : '' ?>

      <form method="post" action="login.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label>Email Address</label>
          <div class="input-icon-wrap">
            <span class="ic-left">📧</span>
            <input class="input" type="email" name="email" placeholder="Enter your email address" required value="<?= e($_POST['email'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Password</label>
          <div class="input-icon-wrap">
            <span class="ic-left">🔒</span>
            <input class="input" type="password" id="loginPass" name="password" placeholder="Enter your password" required>
            <button type="button" class="ic-right" data-toggle-pass="loginPass">👁️</button>
          </div>
        </div>
        <div class="checkbox-row">
          <label><input type="checkbox" name="remember"> Remember me</label>
          <a href="forgot-password.php" class="link">Forgot Password?</a>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Login →</button>
      </form>

      <p class="auth-foot">Don't have an account? <a href="register.php">Register here</a></p>
    </div>
  </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
