<?php
/**
 * Shared "Profile" and "Settings" page for students, teachers and parents.
 * Both pages show the same layout; the caller sets $user, $role, $active ('profile' or 'settings')
 * and includes this file. Handles its own form posts, then renders.
 */
if (!isset($user, $role, $active)) { http_response_code(404); exit; }
$pdo = db();
$selfUrl = BASE_URL . '/' . $role . '/' . $active . '.php';

/** Saves an uploaded profile photo. Returns the new file name, or null with $err set. */
function account_save_photo(array $user, ?string &$err): ?string
{
    $file = $_FILES['avatar'] ?? null;
    if (!$file || empty($file['name'])) { return null; }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $file['tmp_name'] ? finfo_file($finfo, $file['tmp_name']) : '';
    finfo_close($finfo);
    if ($file['error'] !== UPLOAD_ERR_OK) { $err = 'Photo upload failed. Please try again.'; return null; }
    if ($file['size'] > 2 * 1024 * 1024) { $err = 'Photo must be under 2MB.'; return null; }
    if (!isset($allowed[$mime])) { $err = 'Photo must be a JPG, PNG, or WEBP image.'; return null; }
    $newName = 'u' . $user['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $newName)) { $err = 'Could not save the photo.'; return null; }
    if (!empty($user['avatar_path']) && file_exists(UPLOADS_DIR . '/' . $user['avatar_path'])) {
        @unlink(UPLOADS_DIR . '/' . $user['avatar_path']);
    }
    return $newName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    if ($action === 'prefs') {
        $lang = ($_POST['language'] ?? 'en') === 'en' ? 'en' : 'en';   // only English is available for now
        $pdo->prepare("UPDATE users SET pref_email_notifications = ?, pref_study_reminders = ?, pref_language = ? WHERE id = ?")
            ->execute([isset($_POST['email_notifications']) ? 1 : 0, isset($_POST['study_reminders']) ? 1 : 0, $lang, $user['id']]);
        if ($ajax) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
        flash_set('success', 'Preferences saved.');
    } elseif ($action === 'photo') {
        $err = null;
        $name = account_save_photo($user, $err);
        if ($name) {
            $pdo->prepare("UPDATE users SET avatar_path = ? WHERE id = ?")->execute([$name, $user['id']]);
            log_activity((int) $user['id'], 'Profile Updated', 'Changed profile photo');
            flash_set('success', 'Photo updated.');
        } else {
            flash_set('error', $err ?: 'Choose a photo first.');
        }
    } elseif ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $user['id']]);
        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Please provide a valid name and email.');
        } elseif ($check->fetch()) {
            flash_set('error', 'That email is already used by another account.');
        } else {
            $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?")->execute([$fullName, $email, $user['id']]);
            log_activity((int) $user['id'], 'Profile Updated', 'Updated profile details');
            flash_set('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($current, $user['password_hash'])) {
            flash_set('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            flash_set('error', 'New passwords do not match.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            forget_all_tokens_for($pdo, (int) $user['id']);
            log_activity((int) $user['id'], 'Password Changed', 'Updated account password');
            flash_set('success', 'Password updated. Any "remembered" sessions on other devices were signed out.');
        }
    }
    header('Location: ' . $selfUrl);
    exit;
}

$message = flash_get();
$editing = isset($_GET['edit']);
$avatar = avatar_url($user);
$isStudent = $role === 'student';

// ---- Side column data ----
$overview = ['title' => '', 'big' => '', 'label' => '', 'note' => '', 'link' => null];
$actions = [];
if ($isStudent) {
    $year = current_school_year();
    $q = $pdo->prepare("
        SELECT p.predicted_grade FROM student_subjects ss
        JOIN predictions p ON p.subject_id = ss.subject_id AND p.student_id = ss.student_id
          AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = ss.subject_id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
        WHERE ss.student_id = ? AND ss.school_year = ?");
    $q->execute([$user['id'], $year]);
    $grades = array_map('floatval', $q->fetchAll(PDO::FETCH_COLUMN));
    $avg = $grades ? round(array_sum($grades) / count($grades), 1) : null;
    $h = $pdo->prepare("SELECT predicted_grade FROM predictions WHERE student_id = ? AND subject_id = 0 ORDER BY created_at ASC, id ASC");
    $h->execute([$user['id']]);
    $hist = array_map('floatval', $h->fetchAll(PDO::FETCH_COLUMN));
    $delta = ($avg !== null && count($hist) > 1) ? round($avg - $hist[count($hist) - 2], 1) : null;
    $overview = [
        'title' => 'Your academic overview',
        'big' => $avg !== null ? $avg . '%' : '—',
        'label' => $avg !== null ? 'Predicted final grade' : 'No prediction yet',
        'note' => $delta === null ? ($avg !== null ? 'First prediction on record' : 'Add your grade data to get one') : (($delta >= 0 ? '+' : '−') . abs($delta) . '% from your last prediction'),
        'link' => $avg === null ? [BASE_URL . '/student/data-inputs.php', 'Add grade data'] : null,
    ];
    require_once __DIR__ . '/registrar.php';
    $outlook = consolidate_students(registrar_rows($pdo, ['year' => $year, 'student_id' => (int) $user['id']]), honor_config($pdo));
    if ($outlook && $outlook[0]['gwa'] !== null) {
        $o = $outlook[0];
        $overview['extra'] = 'Projected GWA ' . gp_text($o['gwa']) . ' · ' . ($o['qualified'] ? 'on track for ' . $o['honor'] : 'not on track for honors');
    }
    $actions = [
        [BASE_URL . '/student/predictions.php', 'trend', 'Go to predictions'],
        [BASE_URL . '/student/data-inputs.php', 'edit', 'Update my grade data'],
        [BASE_URL . '/student/subjects.php', 'chart', 'View my subjects'],
    ];
} elseif ($role === 'teacher') {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND account_status = 'approved'")->fetchColumn();
    $overview = ['title' => 'Your overview', 'big' => (string) $n, 'label' => 'Students on record', 'note' => pending_approval_count($pdo, $user) . ' sign-up(s) waiting for approval', 'link' => null];
    $actions = [
        [BASE_URL . '/teacher/dashboard.php', 'home', 'Go to dashboard'],
        [BASE_URL . '/teacher/students.php', 'users', 'View students'],
        [BASE_URL . '/approvals.php', 'inbox', 'Review sign-ups'],
    ];
} else {
    $n = (int) $pdo->prepare("SELECT COUNT(*) FROM parent_links WHERE parent_id = ?")->execute([$user['id']]);
    $c = $pdo->prepare("SELECT COUNT(*) FROM parent_links WHERE parent_id = ?"); $c->execute([$user['id']]);
    $n = (int) $c->fetchColumn();
    $overview = ['title' => 'Your overview', 'big' => (string) $n, 'label' => $n === 1 ? 'Linked student' : 'Linked students', 'note' => $n ? 'Open the dashboard to see their predictions' : 'Ask the school to link your child', 'link' => null];
    $actions = [
        [BASE_URL . '/parent/dashboard.php', 'home', 'Go to dashboard'],
        [BASE_URL . '/notifications.php', 'bell', 'Notifications'],
    ];
}
$supportEmail = (string) $pdo->query("SELECT email FROM users WHERE role = 'admin' AND account_status = 'approved' ORDER BY id LIMIT 1")->fetchColumn();

// ---- Profile details for the read-only view ----
$details = [];
$details[] = ['Full name', $user['full_name']];
$details[] = ['Email address', $user['email']];
if ($isStudent) {
    $details[] = ['Program', program_name($pdo, $user['program_id'] ?? null)];
    $progMonths = null;
    if (!empty($user['program_id'])) {
        $pm = $pdo->prepare("SELECT duration_months FROM programs WHERE id = ?"); $pm->execute([$user['program_id']]);
        $progMonths = $pm->fetchColumn();
    }
    $details[] = ['Year level', $user['year_level'] ? 'Year ' . (int) $user['year_level'] . ($progMonths !== false && $progMonths !== null ? ' of ' . program_year_count((int) $progMonths) : '') : '—'];
    if ($progMonths !== false && $progMonths !== null) { $details[] = ['Program length', program_duration_label((int) $progMonths)]; }
    if (!empty($user['student_no'])) { $details[] = ['Student number', $user['student_no']]; }
} else {
    $details[] = ['Role', ucfirst($role)];
}
$details[] = ['Member since', date('M j, Y', strtotime($user['created_at']))];

$heading = $active === 'profile' ? 'Profile' : 'Settings';
$sub = $active === 'profile' ? 'Your personal information and how you use GradePredict AI.' : 'Manage your account and personalize your GradePredict AI experience.';
$roleTitle = ucfirst($role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($heading) ?> — GradePredict AI</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar_' . $role . '.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/topbar.php'; ?>
        <div class="content">
            <div class="page-head acct-head">
                <span class="acct-head-ic"><?= icon($active === 'profile' ? 'user' : 'settings', 22) ?></span>
                <div><h2><?= e($heading) ?></h2><p><?= e($sub) ?></p></div>
            </div>
            <?= render_flash($message) ?>

            <div class="acct-grid">
                <div class="acct-main">
                    <!-- Profile information -->
                    <section class="card acct-card" id="profile" aria-labelledby="profHead">
                        <div class="acct-card-head">
                            <h3 id="profHead"><?= icon('user', 20) ?> Profile information</h3>
                            <a href="?edit=1#profile" class="btn btn-outline btn-sm" id="editProfileBtn" <?= $editing ? 'hidden' : '' ?>><?= icon('edit', 14) ?> Edit profile</a>
                        </div>
                        <div class="prof-body">
                            <div class="prof-photo">
                                <div class="prof-avatar">
                                    <?php if ($avatar): ?><img src="<?= e($avatar) ?>" alt="Your profile photo"><?php else: ?><div class="avatar prof-initials"><?= e(initials($user['full_name'])) ?></div><?php endif; ?>
                                    <label class="prof-cam" for="photoInput" title="Change photo"><?= icon('camera', 14) ?><span class="sr-only">Change photo</span></label>
                                </div>
                                <form method="post" enctype="multipart/form-data" id="photoForm">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="photo">
                                    <input type="file" name="avatar" id="photoInput" accept="image/png,image/jpeg,image/webp" class="sr-only">
                                    <label for="photoInput" class="btn btn-outline btn-sm">Change photo</label>
                                </form>
                                <div class="field-hint">JPG, PNG or WEBP, under 2MB</div>
                            </div>
                            <div class="prof-fields">
                                <dl class="prof-view" id="profView" <?= $editing ? 'hidden' : '' ?>>
                                    <?php foreach ($details as [$label, $value]): ?>
                                        <div class="prof-row"><dt><?= e($label) ?></dt><dd><?= e((string) $value) ?></dd></div>
                                    <?php endforeach; ?>
                                </dl>
                                <form method="post" id="profEdit" <?= $editing ? '' : 'hidden' ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="profile">
                                    <div class="form-group"><label for="pf_name">Full name</label><input class="input" id="pf_name" type="text" name="full_name" value="<?= e($user['full_name']) ?>" required maxlength="150"></div>
                                    <div class="form-group"><label for="pf_email">Email address</label><input class="input" id="pf_email" type="email" name="email" value="<?= e($user['email']) ?>" required></div>
                                    <div class="btn-row"><button type="submit" class="btn btn-primary">Save changes</button><a href="<?= e($selfUrl) ?>#profile" class="btn btn-outline" id="cancelEdit">Cancel</a></div>
                                    <?php if ($isStudent): ?><p class="field-hint">Your program and year level are set by the school office.</p><?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </section>

                    <!-- Account settings -->
                    <section class="card acct-card" id="settings" aria-labelledby="setHead">
                        <div class="acct-card-head"><h3 id="setHead"><?= icon('settings', 20) ?> Account settings</h3></div>
                        <form method="post" id="prefsForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="prefs">
                            <div class="set-row">
                                <span class="set-ic"><?= icon('mail', 20) ?></span>
                                <div class="set-text"><label for="pref_email">Email notifications</label><p>Receive updates about grades, predictions and sign-ups.</p></div>
                                <label class="theme-switch"><input type="checkbox" id="pref_email" name="email_notifications" value="1" <?= !empty($user['pref_email_notifications']) ? 'checked' : '' ?>><span class="theme-slider"></span></label>
                            </div>
                            <div class="set-row">
                                <span class="set-ic"><?= icon('bell', 20) ?></span>
                                <div class="set-text"><label for="pref_remind"><?= $isStudent ? 'Study reminders' : 'Activity reminders' ?></label><p><?= $isStudent ? 'Get reminders to study and update your grade data.' : 'Get reminders about pending tasks and updates.' ?></p></div>
                                <label class="theme-switch"><input type="checkbox" id="pref_remind" name="study_reminders" value="1" <?= !empty($user['pref_study_reminders']) ? 'checked' : '' ?>><span class="theme-slider"></span></label>
                            </div>
                            <noscript><div class="set-row"><button type="submit" class="btn btn-primary btn-sm">Save preferences</button></div></noscript>
                        </form>
                        <div class="set-row set-row-pass" id="password">
                            <span class="set-ic"><?= icon('key', 20) ?></span>
                            <div class="set-text"><strong>Password</strong><p>Change the password you use to sign in.</p></div>
                            <button type="button" class="btn btn-outline btn-sm" id="passToggle" aria-expanded="false" aria-controls="passForm">Change</button>
                        </div>
                        <form method="post" id="passForm" class="set-expand" hidden>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="password">
                            <div class="form-group"><label for="cp_cur">Current password</label><input class="input" id="cp_cur" type="password" name="current_password" required autocomplete="current-password"></div>
                            <div class="form-row-2">
                                <div class="form-group"><label for="cp_new">New password</label><input class="input" id="cp_new" type="password" name="new_password" required minlength="6" autocomplete="new-password"></div>
                                <div class="form-group"><label for="cp_conf">Confirm new password</label><input class="input" id="cp_conf" type="password" name="confirm_password" required minlength="6" autocomplete="new-password"></div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Update password</button>
                        </form>
                        <div class="set-row">
                            <span class="set-ic"><?= icon('shield', 20) ?></span>
                            <div class="set-text"><strong>Data privacy</strong><p>See how your data is used and protected.</p></div>
                            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/privacy.php">Manage</a>
                        </div>
                        <div class="set-row">
                            <span class="set-ic"><?= icon('globe', 20) ?></span>
                            <div class="set-text"><label for="pref_lang">Language</label><p>Choose your preferred language.</p></div>
                            <select class="input set-select" id="pref_lang" name="language" form="prefsForm">
                                <option value="en" selected>English</option>
                                <option value="fil" disabled>Filipino (coming soon)</option>
                            </select>
                        </div>
                        <div class="set-row">
                            <span class="set-ic"><?= icon('sun', 20) ?></span>
                            <div class="set-text"><label for="themeSelect">Theme</label><p>Switch between light and dark mode. Saved on this device.</p></div>
                            <select class="input set-select" id="themeSelect"><option value="light">Light</option><option value="dark">Dark</option></select>
                        </div>
                    </section>
                </div>

                <aside class="acct-side">
                    <section class="acct-overview" aria-label="<?= e($overview['title']) ?>">
                        <h3><?= e($overview['title']) ?></h3>
                        <div class="ov-big"><?= e($overview['big']) ?></div>
                        <div class="ov-label"><?= e($overview['label']) ?></div>
                        <div class="ov-note"><?= e($overview['note']) ?></div>
                        <?php if (!empty($overview['extra'])): ?><div class="ov-extra"><?= e($overview['extra']) ?></div><?php endif; ?>
                        <?php if ($overview['link']): ?><a class="ov-link" href="<?= e($overview['link'][0]) ?>"><?= e($overview['link'][1]) ?> <?= icon('arrow', 14) ?></a><?php endif; ?>
                    </section>
                    <section class="card acct-card">
                        <div class="acct-card-head"><h3><?= icon('trend', 20) ?> Quick actions</h3></div>
                        <div class="qa-list">
                            <?php foreach ($actions as $i => [$href, $ic, $label]): ?>
                                <a class="qa <?= $i === 0 ? 'qa-primary' : '' ?>" href="<?= e($href) ?>"><span><?= icon($ic, 18) ?> <?= e($label) ?></span><?= icon('arrow', 16) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="card acct-card acct-help">
                        <div class="acct-card-head"><h3><?= icon('help', 20) ?> Need help?</h3></div>
                        <p>Questions about your account or your grades? Your school administrator can help.</p>
                        <?php if ($supportEmail): ?><a class="btn btn-outline btn-sm" href="mailto:<?= e($supportEmail) ?>">Contact support</a><?php endif; ?>
                    </section>
                </aside>
            </div>
            <?php include __DIR__ . '/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
<script>
(function () {
  // Edit profile without leaving the page (the link still works without JavaScript)
  var editBtn = document.getElementById('editProfileBtn'), view = document.getElementById('profView'),
      form = document.getElementById('profEdit'), cancel = document.getElementById('cancelEdit');
  function setEdit(on) { view.hidden = on; form.hidden = !on; editBtn.hidden = on; if (on) document.getElementById('pf_name').focus(); }
  editBtn.addEventListener('click', function (e) { e.preventDefault(); setEdit(true); });
  cancel.addEventListener('click', function (e) { e.preventDefault(); setEdit(false); });

  // Choosing a photo uploads it straight away
  document.getElementById('photoInput').addEventListener('change', function () { if (this.files.length) document.getElementById('photoForm').submit(); });

  // Password row expands in place
  var pt = document.getElementById('passToggle'), pf = document.getElementById('passForm');
  pt.addEventListener('click', function () { pf.hidden = !pf.hidden; pt.setAttribute('aria-expanded', pf.hidden ? 'false' : 'true'); pt.textContent = pf.hidden ? 'Change' : 'Close'; if (!pf.hidden) document.getElementById('cp_cur').focus(); });
  if (location.hash === '#password') pt.click();

  // Notification toggles save as soon as they change
  var prefs = document.getElementById('prefsForm');
  prefs.addEventListener('change', function () {
    fetch(location.href, { method: 'POST', body: new FormData(prefs), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function () { if (window.Toast) Toast.show('Preference saved', 'success'); })
      .catch(function () { if (window.Toast) Toast.show('Could not save. Check your connection and try again.', 'error'); });
  });

  // Theme (kept on this device)
  var sel = document.getElementById('themeSelect');
  sel.value = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  sel.addEventListener('change', function () {
    if (sel.value === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); else document.documentElement.removeAttribute('data-theme');
    try { localStorage.setItem('gp-theme', sel.value); } catch (e) {}
  });
})();
</script>
</body>
</html>
