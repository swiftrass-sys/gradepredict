<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'users';
$pdo = db();
$message = flash_get();
$validRoles = ['student', 'teacher', 'parent', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['change_role'])) {
        $targetId = (int) $_POST['user_id'];
        $newRole = in_array($_POST['role'] ?? '', $validRoles, true) ? $_POST['role'] : 'student';
        if ($targetId !== (int) $user['id']) {
            $pdo->prepare("UPDATE users SET can_approve = CASE WHEN ? = 'teacher' THEN 1 WHEN role = 'teacher' THEN 0 ELSE can_approve END, role = ? WHERE id = ?")
                ->execute([$newRole, $newRole, $targetId]);
            flash_set('success', 'Role updated.');
        } else {
            flash_set('error', 'You cannot change your own role.');
        }
    } elseif (isset($_POST['set_approver'])) {
        $targetId = (int) $_POST['user_id'];
        $allow = $_POST['set_approver'] === '1' ? 1 : 0;
        $pdo->prepare("UPDATE users SET can_approve = ? WHERE id = ? AND role <> 'admin' AND account_status = 'approved'")->execute([$allow, $targetId]);
        flash_set('success', $allow ? 'Permission to approve accounts granted.' : 'Permission to approve accounts removed.');
    } elseif (isset($_POST['delete_user'])) {
        $delId = (int) $_POST['delete_user'];
        if ($delId !== (int) $user['id']) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$delId]);
            flash_set('success', 'User deleted.');
        } else {
            flash_set('error', 'You cannot delete your own account.');
        }
    } elseif (isset($_POST['create_user'])) {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['new_role'] ?? '', $validRoles, true) ? $_POST['new_role'] : 'student';
        $password = $_POST['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            flash_set('error', 'Please provide a valid name, email, and a password of at least 6 characters.');
        } else {
            $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $exists->execute([$email]);
            if ($exists->fetch()) {
                flash_set('error', 'That email is already registered.');
            } else {
                $userType = ucfirst($role === 'admin' ? 'Administrator' : $role);
                $studentNo = $role === 'student' ? ('GP-' . date('Y') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)) : null;
                $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, must_change_password, can_approve) VALUES (?, ?, ?, ?, ?, ?, 1, ?)")
                    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $userType, $studentNo, $role === 'teacher' ? 1 : 0]);
                flash_set('success', ucfirst($role) . ' account created.');
            }
        }
    }
    header('Location: users.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Users</h2><p>All accounts with access to GradePredict AI.</p></div><a class="btn btn-primary" href="<?= BASE_URL ?>/admin/account-generator.php">Generate accounts</a></div>
            <?= render_flash($message) ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <table>
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Can approve sign-ups</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="row-icon"><div class="avatar" style="width:30px;height:30px;font-size:12px;"><?= e(initials($u['full_name'])) ?></div><?= e($u['full_name']) ?></td>
                                <td><?= e($u['email']) ?></td>
                                <td>
                                    <form method="post" style="display:flex;gap:6px;align-items:center;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <select name="role" class="input" style="padding:6px 10px;font-size:12.5px;" <?= (int) $u['id'] === (int) $user['id'] ? 'disabled' : '' ?>>
                                            <?php foreach ($validRoles as $r): ?>
                                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                                            <button type="submit" name="change_role" value="1" class="btn btn-outline btn-sm">Save</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($u['account_status'] === 'pending'): ?>
                                        <a href="<?= BASE_URL ?>/approvals.php" class="badge badge-fair">Pending</a>
                                    <?php elseif ($u['account_status'] === 'rejected'): ?>
                                        <span class="badge badge-poor">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge badge-excellent">Approved</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="label-quiet">Always</span>
                                    <?php elseif ($u['account_status'] !== 'approved'): ?>
                                        <span class="label-quiet">After approval</span>
                                    <?php else: ?>
                                        <form method="post" style="display:flex;gap:8px;align-items:center;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                            <span><?= !empty($u['can_approve']) ? 'Yes' : 'No' ?></span>
                                            <button type="submit" name="set_approver" value="<?= !empty($u['can_approve']) ? '0' : '1' ?>" class="btn btn-outline btn-sm"><?= !empty($u['can_approve']) ? 'Remove' : 'Allow' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_user" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" data-confirm="This permanently deletes the user account and all of their data." data-confirm-label="Delete User">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);font-size:12px;">You</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="label-quiet" style="margin:12px 0 0;">Admins approve every kind of sign-up. Teachers and anyone you allow can approve student and parent sign-ups; teacher sign-ups always need an admin.</p>
                </div>
                <div class="card">
                    <h3>Create User</h3>
                    <p style="color:var(--text-muted);font-size:12.5px;margin-bottom:14px;">This is now the only way to create Administrator accounts — public sign-up no longer allows it.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Full Name</label><input class="input" type="text" name="full_name" required></div>
                        <div class="form-group"><label>Email</label><input class="input" type="email" name="email" required></div>
                        <div class="form-group">
                            <label>Role</label>
                            <select name="new_role" class="input">
                                <?php foreach ($validRoles as $r): ?><option value="<?= $r ?>"><?= ucfirst($r) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label for="tmpPw">Temporary Password</label><div style="display:flex;gap:8px;"><input class="input" type="text" id="tmpPw" name="password" required minlength="6" placeholder="At least 6 characters" autocomplete="off"><button type="button" class="btn btn-outline" id="genPw">Generate</button></div></div>
                        <button type="submit" name="create_user" value="1" class="btn btn-primary btn-block">Create Account</button>
                    </form>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
<script>
document.getElementById('genPw').addEventListener('click', function () {
  var sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'], all = sets.join(''), out = [];
  function pick(str) { var a = new Uint32Array(1); crypto.getRandomValues(a); return str[a[0] % str.length]; }
  sets.forEach(function (s) { out.push(pick(s)); });
  while (out.length < 10) out.push(pick(all));
  for (var i = out.length - 1; i > 0; i--) { var a = new Uint32Array(1); crypto.getRandomValues(a); var j = a[0] % (i + 1); var t = out[i]; out[i] = out[j]; out[j] = t; }
  document.getElementById('tmpPw').value = out.join('');
});
</script>
</body>
</html>
