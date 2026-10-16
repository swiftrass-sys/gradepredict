<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    $u = current_user();
    header('Location: ' . BASE_URL . portal_path_for($u['role']));
    exit;
}

$error = '';
$old = ['full_name' => '', 'email' => '', 'user_type' => 'Student', 'child_lookup' => '', 'program_id' => '', 'major_id' => '', 'year_level' => '1'];
$pdo = db();
$programs = $pdo->query("SELECT * FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$majors = $pdo->query("SELECT * FROM majors ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $old['user_type'] = in_array($_POST['user_type'] ?? '', ['Student', 'Teacher', 'Parent'], true) ? $_POST['user_type'] : 'Student';
    $old['child_lookup'] = trim($_POST['child_lookup'] ?? '');
    $old['program_id'] = $_POST['program_id'] ?? '';
    $old['major_id'] = $_POST['major_id'] ?? '';
    $old['year_level'] = $_POST['year_level'] ?? '1';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $agree = isset($_POST['agree']);

    if ($old['full_name'] === '' || $old['email'] === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($old['user_type'] === 'Student' && $old['program_id'] === '') {
        $error = 'Please select your program.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$agree) {
        $error = 'You must agree to the Terms of Service and Privacy Policy.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $childUser = null;
            if ($old['user_type'] === 'Parent') {
                if ($old['child_lookup'] === '') {
                    $error = "Please enter your child's student number or email to link your account.";
                } else {
                    $childStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'student' AND (student_no = ? OR email = ?)");
                    $childStmt->execute([$old['child_lookup'], $old['child_lookup']]);
                    $childUser = $childStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$childUser) {
                        $error = "We couldn't find a student with that student number or email. Double-check it with the school, or ask an admin to link your account after signing up.";
                    }
                }
            }

            if ($error === '') {
                $role = match ($old['user_type']) {
                    'Teacher' => 'teacher',
                    'Parent' => 'parent',
                    default => 'student',
                };
                $studentNo = $role === 'student' ? ('GP-' . date('Y') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)) : null;
                $programId = $role === 'student' ? ((int) $old['program_id'] ?: null) : null;
                $majorId = $role === 'student' ? ((int) $old['major_id'] ?: null) : null;
                $yearLevel = $role === 'student' ? clamp_year_level($pdo, $programId, (int) $old['year_level'] ?: 1) : null;

                // New accounts start as 'pending': they cannot sign in until an admin
                // (or a teacher / delegated approver) approves them on the Approvals page.
                $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, major_id, year_level, account_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')")
                    ->execute([$old['full_name'], $old['email'], password_hash($password, PASSWORD_DEFAULT), $role, $old['user_type'], $studentNo, $programId, $majorId, $yearLevel]);

                $newId = (int) $pdo->lastInsertId();

                if ($role === 'parent' && $childUser) {
                    // The child is told about the link once the parent account is approved.
                    $pdo->prepare("INSERT INTO parent_links (parent_id, student_id) VALUES (?, ?)")->execute([$newId, $childUser['id']]);
                }

                log_activity($newId, 'Account Created', 'Registered as ' . $old['user_type'] . ' (waiting for approval)');
                notify_approvers($pdo, $old['full_name'], $role);
                header('Location: ' . BASE_URL . '/login.php?pending=1');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — GradePredict AI</title>
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-grid">
    <div class="auth-side">
      <img src="<?= BASE_URL ?>/assets/img/auth-illustration.jpg" alt="GradePredict AI dashboard preview" class="auth-illustration">
      <h2>Create an Account</h2>
      <p>Join GradePredict AI and start predicting academic success.</p>
      <div class="auth-feature"><div class="dot-ic">⚙️</div><div><h5>AI-Powered Predictions</h5><p>Get accurate grade predictions based on your data.</p></div></div>
      <div class="auth-feature"><div class="dot-ic">📊</div><div><h5>Smart Insights</h5><p>Understand your performance and improve effectively.</p></div></div>
      <div class="auth-feature"><div class="dot-ic">🎯</div><div><h5>Better Outcomes</h5><p>Make data-driven decisions for a brighter future.</p></div></div>
      <div class="auth-quote">"Predict today, achieve tomorrow."</div>
    </div>

    <div class="auth-card">
      <?php $brandTagline = 'GradePredict AI System'; include __DIR__ . '/includes/brand.php'; ?>
      <h2>Create Account</h2>
      <p class="sub">Fill in the details to create your account</p>

      <?= $error ? render_alert('error', $error) : '' ?>

      <div class="stepper" data-stepper>
        <div class="step-dot-wrap active"><div class="step-dot">1</div><div class="step-label">Account</div></div>
        <div class="step-line"></div>
        <div class="step-dot-wrap"><div class="step-dot">2</div><div class="step-label">Role</div></div>
        <div class="step-line"></div>
        <div class="step-dot-wrap"><div class="step-dot">3</div><div class="step-label">Security</div></div>
      </div>

      <form method="post" action="register.php">
        <?= csrf_field() ?>
        <div class="stepper-panel active">
          <div class="form-group">
            <label>Full Name</label>
            <div class="input-icon-wrap">
              <span class="ic-left">👤</span>
              <input class="input" type="text" name="full_name" placeholder="Enter your full name" required value="<?= e($old['full_name']) ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <div class="input-icon-wrap">
              <span class="ic-left">📧</span>
              <input class="input" type="email" name="email" placeholder="Enter your email address" required value="<?= e($old['email']) ?>">
            </div>
          </div>
          <div class="stepper-nav" style="justify-content:flex-end;">
            <button type="button" class="btn btn-primary" data-step-next>Continue →</button>
          </div>
        </div>

        <div class="stepper-panel">
          <div class="form-group">
            <label>I am a...</label>
            <div class="input-icon-wrap">
              <span class="ic-left">👥</span>
              <select class="input" name="user_type" id="userTypeSelect" style="padding-left:40px;">
                <option <?= $old['user_type'] === 'Student' ? 'selected' : '' ?>>Student</option>
                <option <?= $old['user_type'] === 'Teacher' ? 'selected' : '' ?>>Teacher</option>
                <option <?= $old['user_type'] === 'Parent' ? 'selected' : '' ?>>Parent</option>
              </select>
            </div>
            <div class="field-hint">This decides which portal you land in after signing up. Administrator accounts are created by an existing admin.</div>
          </div>
          <div class="form-group" id="childLookupGroup" style="<?= $old['user_type'] === 'Parent' ? '' : 'display:none;' ?>">
            <label>Your Child's Student Number or Email</label>
            <div class="input-icon-wrap">
              <span class="ic-left">🎓</span>
              <input class="input" type="text" name="child_lookup" placeholder="e.g. GP-2025-0001" value="<?= e($old['child_lookup']) ?>">
            </div>
            <div class="field-hint">We'll link your account so you can see their predictions.</div>
          </div>
          <div id="studentProgramGroup" style="<?= $old['user_type'] === 'Student' ? '' : 'display:none;' ?>">
            <div class="form-group">
              <label>Program</label>
              <select class="input" name="program_id" id="programSelect">
                <option value="">— Select your program —</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" data-years="<?= program_year_count((int) $p['duration_months']) ?>" <?= (string) $old['program_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?> — <?= e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" id="majorGroup" style="display:none;">
              <label>Major</label>
              <select class="input" name="major_id" id="majorSelect">
                <option value="">— Select your major —</option>
                <?php foreach ($majors as $m): ?>
                    <option value="<?= $m['id'] ?>" data-program="<?= $m['program_id'] ?>" <?= (string) $old['major_id'] === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['code']) ?> — <?= e($m['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Year Level</label>
              <select class="input" name="year_level" data-years-from="programSelect">
                <?php for ($y = 1; $y <= 5; $y++): ?><option value="<?= $y ?>" <?= (string) $old['year_level'] === (string) $y ? 'selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
              </select>
            </div>
          </div>
          <div class="stepper-nav">
            <button type="button" class="btn btn-outline" data-step-prev>← Back</button>
            <button type="button" class="btn btn-primary" data-step-next>Continue →</button>
          </div>
        </div>

        <div class="stepper-panel">
          <div class="form-group">
            <label>Password</label>
            <div class="input-icon-wrap">
              <span class="ic-left">🔒</span>
              <input class="input" type="password" id="regPass" name="password" placeholder="Create a password" required minlength="6">
              <button type="button" class="ic-right" data-toggle-pass="regPass">👁️</button>
            </div>
            <div class="field-hint">At least 6 characters.</div>
          </div>
          <div class="form-group">
            <label>Confirm Password</label>
            <div class="input-icon-wrap">
              <span class="ic-left">🔒</span>
              <input class="input" type="password" id="regPass2" name="confirm_password" placeholder="Confirm your password" required minlength="6">
              <button type="button" class="ic-right" data-toggle-pass="regPass2">👁️</button>
            </div>
          </div>
          <div class="checkbox-row">
            <label><input type="checkbox" name="agree" required> I agree to the <a href="terms.php" class="link" target="_blank">Terms of Service</a> and <a href="privacy.php" class="link" target="_blank">Privacy Policy</a></label>
          </div>
          <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 12px;">New accounts need approval from an administrator or teacher before you can sign in.</p>
          <div class="stepper-nav">
            <button type="button" class="btn btn-outline" data-step-prev>← Back</button>
            <button type="submit" class="btn btn-primary">Register →</button>
          </div>
        </div>
      </form>

      <p class="auth-foot">Already have an account? <a href="login.php">Login here</a></p>
    </div>
  </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
<script>
  document.getElementById('userTypeSelect').addEventListener('change', function () {
    document.getElementById('childLookupGroup').style.display = this.value === 'Parent' ? 'block' : 'none';
    document.getElementById('studentProgramGroup').style.display = this.value === 'Student' ? 'block' : 'none';
  });
  var programSelect = document.getElementById('programSelect');
  var majorGroup = document.getElementById('majorGroup');
  function syncMajors() {
    var progId = programSelect.value;
    var hasMajors = false;
    document.querySelectorAll('#majorSelect option[data-program]').forEach(function (opt) {
      var match = opt.getAttribute('data-program') === progId;
      opt.hidden = !match;
      if (match) hasMajors = true;
    });
    majorGroup.style.display = hasMajors ? 'block' : 'none';
  }
  if (programSelect) {
    programSelect.addEventListener('change', syncMajors);
    syncMajors();
  }
</script>
</body>
</html>
