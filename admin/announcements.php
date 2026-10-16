<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'announcements';
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['post_announcement'])) {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $audience = in_array($_POST['audience'] ?? '', ['all', 'program', 'section'], true) ? $_POST['audience'] : 'all';
        $programId = $audience === 'program' ? (int) $_POST['program_id'] : null;
        $sectionId = $audience === 'section' ? (int) $_POST['section_id'] : null;

        if ($title === '' || $body === '') {
            flash_set('error', 'Please provide both a title and message.');
        } else {
            $pdo->prepare("INSERT INTO announcements (title, body, audience, program_id, section_id, posted_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $body, $audience, $programId, $sectionId, $user['id']]);
            log_activity((int) $user['id'], 'Announcement Posted', $title);
            flash_set('success', 'Announcement posted.');
        }
    } elseif (isset($_POST['delete_announcement'])) {
        $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([(int) $_POST['delete_announcement']]);
        flash_set('success', 'Announcement deleted.');
    }
    header('Location: announcements.php');
    exit;
}

$programs = $pdo->query("SELECT * FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$sections = $pdo->query("SELECT * FROM sections ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$announcements = $pdo->query("
    SELECT a.*, u.full_name AS author, p.code AS program_code, s.name AS section_name
    FROM announcements a
    LEFT JOIN users u ON u.id = a.posted_by
    LEFT JOIN programs p ON p.id = a.program_id
    LEFT JOIN sections s ON s.id = a.section_id
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Announcements</h2><p>Post updates for the whole school, a program, or a section.</p></div></div>
            <?= render_flash($message) ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">No announcements posted yet.</div>
                    <?php endif; ?>
                    <?php foreach ($announcements as $a): ?>
                        <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                                <div>
                                    <strong><?= e($a['title']) ?></strong>
                                    <span class="badge badge-good" style="margin-left:8px;">
                                        <?= $a['audience'] === 'all' ? 'Whole School' : ($a['audience'] === 'program' ? e($a['program_code'] ?? 'Program') : e($a['section_name'] ?? 'Section')) ?>
                                    </span>
                                </div>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_announcement" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" data-confirm="Delete this announcement?">Delete</button>
                                </form>
                            </div>
                            <p style="font-size:13.5px;margin:8px 0;"><?= nl2br(e($a['body'])) ?></p>
                            <div style="font-size:11.5px;color:var(--text-muted);">By <?= e($a['author'] ?? 'System') ?> · <?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card">
                    <h3>Post Announcement</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Title</label><input class="input" type="text" name="title" required></div>
                        <div class="form-group"><label>Message</label><textarea class="input" name="body" rows="4" required></textarea></div>
                        <div class="form-group">
                            <label>Audience</label>
                            <select name="audience" class="input" id="audienceSelect" onchange="document.getElementById('progGroup').style.display=this.value==='program'?'block':'none';document.getElementById('secGroup').style.display=this.value==='section'?'block':'none';">
                                <option value="all">Whole School</option>
                                <option value="program">A Specific Program</option>
                                <option value="section">A Specific Section</option>
                            </select>
                        </div>
                        <div class="form-group" id="progGroup" style="display:none;">
                            <label>Program</label>
                            <select name="program_id" class="input">
                                <?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['code']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="secGroup" style="display:none;">
                            <label>Section</label>
                            <select name="section_id" class="input">
                                <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="post_announcement" value="1" class="btn btn-primary btn-block">Post</button>
                    </form>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
