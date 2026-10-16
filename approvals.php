<?php
require_once __DIR__ . '/config.php';
$user = require_login();

// Only admins and people an admin has given permission to may open this page.
if (!can_approve_accounts($user)) {
    header('Location: ' . BASE_URL . portal_path_for($user['role']));
    exit;
}

$active = 'approvals';
$pdo = db();
$message = flash_get();
$roles = approvable_roles($user);
$in = implode(',', array_fill(0, count($roles), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $decision = isset($_POST['approve']) ? 'approve' : (isset($_POST['reject']) ? 'reject' : '');
    $targetId = (int) ($_POST['approve'] ?? $_POST['reject'] ?? 0);
    if ($decision !== '' && $targetId > 0) {
        [$ok, $text] = decide_account($pdo, $user, $targetId, $decision);
        flash_set($ok ? 'success' : 'error', $text);
    }
    header('Location: approvals.php');
    exit;
}

$pendingStmt = $pdo->prepare("
    SELECT u.*, p.code AS program_code,
        (SELECT GROUP_CONCAT(c.full_name SEPARATOR ', ') FROM parent_links pl JOIN users c ON c.id = pl.student_id WHERE pl.parent_id = u.id) AS child_names
    FROM users u
    LEFT JOIN programs p ON p.id = u.program_id
    WHERE u.account_status = 'pending' AND u.role IN ($in)
    ORDER BY u.created_at ASC
");
$pendingStmt->execute($roles);
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$recentStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.role, u.account_status, u.approved_at, a.full_name AS decided_by
    FROM users u
    LEFT JOIN users a ON a.id = u.approved_by
    WHERE u.approved_by IS NOT NULL AND u.role IN ($in)
    ORDER BY u.approved_at DESC
    LIMIT 10
");
$recentStmt->execute($roles);
$recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$sidebar = __DIR__ . '/includes/sidebar_' . $user['role'] . '.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approvals — GradePredict AI</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include $sidebar; ?>
    <div class="main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head">
                <div>
                    <h2>Account approvals</h2>
                    <p>People who signed up and are waiting to be let in.<?= $user['role'] === 'admin' ? '' : ' Teacher accounts are approved by an administrator.' ?></p>
                </div>
            </div>
            <?= render_flash($message) ?>

            <div class="card" style="margin-bottom:18px;">
                <div class="card-head"><h3>Waiting for approval (<?= count($pending) ?>)</h3></div>
                <?php if (empty($pending)): ?>
                    <div class="empty-state-card">
                        <div class="empty-ic-wrap"><?= icon('inbox', 24) ?></div>
                        <h4>Nobody is waiting</h4>
                        <p>New sign-ups will show up here and you will get a notification.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Name</th><th>Signed up as</th><th>Details</th><th>Requested</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($pending as $p): ?>
                            <tr>
                                <td><strong><?= e($p['full_name']) ?></strong><br><span class="label-quiet"><?= e($p['email']) ?></span></td>
                                <td><?= e(ucfirst($p['role'])) ?></td>
                                <td>
                                    <?php if ($p['role'] === 'student'): ?>
                                        <?= e(($p['program_code'] ?: 'No program') . ($p['year_level'] ? ', year ' . $p['year_level'] : '')) ?><br><span class="label-quiet"><?= e((string) $p['student_no']) ?></span>
                                    <?php elseif ($p['role'] === 'parent'): ?>
                                        Parent of <?= e($p['child_names'] ?: 'no linked student') ?>
                                    <?php else: ?>
                                        <span class="label-quiet">Needs administrator approval</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date('M j, Y g:i A', strtotime($p['created_at']))) ?></td>
                                <td>
                                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" name="approve" value="<?= (int) $p['id'] ?>" class="btn btn-primary btn-sm">Approve</button>
                                        </form>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="reject" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" data-confirm="<?= e($p['full_name']) ?> will not be able to sign in." data-confirm-label="Reject">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if (!empty($recent)): ?>
            <div class="card">
                <div class="card-head"><h3>Recent decisions</h3></div>
                <table class="table-compact">
                    <thead><tr><th>Name</th><th>Role</th><th>Decision</th><th>By</th><th>When</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e(ucfirst($r['role'])) ?></td>
                            <td><span class="badge <?= $r['account_status'] === 'approved' ? 'badge-excellent' : 'badge-poor' ?>"><?= $r['account_status'] === 'approved' ? 'Approved' : 'Rejected' ?></span></td>
                            <td><?= e($r['decided_by'] ?: '—') ?></td>
                            <td><?= $r['approved_at'] ? e(date('M j, g:i A', strtotime($r['approved_at']))) : '—' ?></td>
                            <td>
                                <?php if ($r['account_status'] === 'rejected'): ?>
                                    <form method="post"><?= csrf_field() ?><button type="submit" name="approve" value="<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Approve instead</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
