<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'programs';
$pdo = db();
$message = flash_get();

/** Reads "length" + "unit" from the form and returns months, or null if it is outside 6 months to 4 years. */
function posted_duration_months(): ?int
{
    $n = (float) ($_POST['length'] ?? 0);
    $months = ($_POST['unit'] ?? 'months') === 'years' ? (int) round($n * 12) : (int) round($n);
    return ($months >= PROGRAM_MIN_MONTHS && $months <= PROGRAM_MAX_MONTHS) ? $months : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['add_program'])) {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $months = posted_duration_months();
        if ($months === null) {
            flash_set('error', 'The length of a program must be between 6 months and 4 years.');
        } elseif ($code !== '' && $name !== '') {
            $exists = $pdo->prepare("SELECT id FROM programs WHERE code = ?");
            $exists->execute([$code]);
            if ($exists->fetch()) {
                flash_set('error', 'A program with that code already exists.');
            } else {
                $pdo->prepare("INSERT INTO programs (code, name, description, duration_months) VALUES (?, ?, ?, ?)")
                    ->execute([$code, $name, trim($_POST['description'] ?? ''), $months]);
                flash_set('success', 'Program added.');
            }
        } else {
            flash_set('error', 'Please provide both a code and a name.');
        }
    } elseif (isset($_POST['edit_program'])) {
        $id = (int) $_POST['edit_program'];
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $months = posted_duration_months();
        $dupe = $pdo->prepare("SELECT id FROM programs WHERE code = ? AND id <> ?");
        $dupe->execute([$code, $id]);
        if ($code === '' || $name === '') {
            flash_set('error', 'Please provide both a code and a name.');
        } elseif ($months === null) {
            flash_set('error', 'The length of a program must be between 6 months and 4 years.');
        } elseif ($dupe->fetch()) {
            flash_set('error', 'Another program already uses that code.');
        } else {
            $pdo->prepare("UPDATE programs SET code = ?, name = ?, description = ?, duration_months = ? WHERE id = ?")
                ->execute([$code, $name, trim($_POST['description'] ?? ''), $months, $id]);
            // Students can no longer be in a year the program does not have.
            $pdo->prepare("UPDATE users SET year_level = ? WHERE program_id = ? AND role = 'student' AND year_level > ?")
                ->execute([program_year_count($months), $id, program_year_count($months)]);
            log_activity((int) $user['id'], 'Program Updated', "$code now runs " . program_duration_label($months));
            flash_set('success', 'Program updated.');
        }
    } elseif (isset($_POST['delete_program'])) {
        $pdo->prepare("DELETE FROM programs WHERE id = ?")->execute([(int) $_POST['delete_program']]);
        flash_set('success', 'Program deleted.');
    } elseif (isset($_POST['add_major'])) {
        $programId = (int) $_POST['program_id'];
        $code = strtoupper(trim($_POST['major_code'] ?? ''));
        $name = trim($_POST['major_name'] ?? '');
        if ($code !== '' && $name !== '') {
            $pdo->prepare("INSERT IGNORE INTO majors (program_id, code, name) VALUES (?, ?, ?)")->execute([$programId, $code, $name]);
            flash_set('success', 'Major added.');
        }
    } elseif (isset($_POST['delete_major'])) {
        $pdo->prepare("DELETE FROM majors WHERE id = ?")->execute([(int) $_POST['delete_major']]);
        flash_set('success', 'Major deleted.');
    }
    header('Location: programs.php');
    exit;
}

$programs = $pdo->query("
    SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.program_id = p.id AND u.role = 'student' AND u.account_status = 'approved') AS student_count
    FROM programs p ORDER BY p.code
")->fetchAll(PDO::FETCH_ASSOC);

$majorsByProgram = [];
$allMajors = $pdo->query("SELECT * FROM majors ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allMajors as $m) {
    $majorsByProgram[$m['program_id']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Programs — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Programs &amp; Majors</h2><p>The academic programs offered by the college.</p></div></div>
            <?= render_flash($message) ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <?php foreach ($programs as $p): ?>
                        <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                                <div>
                                    <strong style="font-size:15px;"><?= e($p['code']) ?></strong> — <?= e($p['name']) ?>
                                    <div style="color:var(--text-muted);font-size:12.5px;margin-top:2px;"><?= e(program_duration_label((int) $p['duration_months'])) ?> &middot; <?= (int) $p['student_count'] ?> student(s) enrolled</div>
                                </div>
                                <div style="display:flex;gap:8px;">
                                <button type="button" class="btn btn-outline btn-sm" data-toggle-edit="edit-<?= (int) $p['id'] ?>" aria-expanded="false" aria-controls="edit-<?= (int) $p['id'] ?>">Edit</button>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_program" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" data-confirm="This removes the program. Students already assigned to it will keep their record but lose the program link.">Delete</button>
                                </form>
                                </div>
                            </div>
                            <?php
                            $mo = (int) $p['duration_months'];
                            $inYears = $mo % 12 === 0;
                            ?>
                            <form method="post" id="edit-<?= (int) $p['id'] ?>" hidden style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);">
                                <?= csrf_field() ?>
                                <div class="form-row-2">
                                    <div class="form-group"><label for="c<?= (int) $p['id'] ?>">Program code</label><input class="input" id="c<?= (int) $p['id'] ?>" type="text" name="code" value="<?= e($p['code']) ?>" required maxlength="20"></div>
                                    <div class="form-group"><label for="n<?= (int) $p['id'] ?>">Program name</label><input class="input" id="n<?= (int) $p['id'] ?>" type="text" name="name" value="<?= e($p['name']) ?>" required maxlength="150"></div>
                                </div>
                                <div class="form-group"><label>Length of program</label>
                                    <div class="length-field">
                                        <input class="input" type="number" name="length" value="<?= $inYears ? $mo / 12 : $mo ?>" min="1" max="48" step="any" required aria-label="Length">
                                        <select class="input" name="unit" aria-label="Unit"><option value="months" <?= $inYears ? '' : 'selected' ?>>months</option><option value="years" <?= $inYears ? 'selected' : '' ?>>years</option></select>
                                    </div>
                                    <div class="field-hint">Anything from 6 months to 4 years.</div>
                                </div>
                                <div class="form-group"><label for="d<?= (int) $p['id'] ?>">Description</label><textarea class="input" id="d<?= (int) $p['id'] ?>" name="description" rows="2"><?= e($p['description'] ?? '') ?></textarea></div>
                                <div class="btn-row"><button type="submit" name="edit_program" value="<?= (int) $p['id'] ?>" class="btn btn-primary btn-sm">Save changes</button><button type="button" class="btn btn-outline btn-sm" data-toggle-edit="edit-<?= (int) $p['id'] ?>">Cancel</button></div>
                            </form>

                            <?php if ($p['code'] === 'BSED' || !empty($majorsByProgram[$p['id']])): ?>
                            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);">
                                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">Majors</div>
                                <?php foreach ($majorsByProgram[$p['id']] ?? [] as $m): ?>
                                    <span class="badge badge-good" style="margin-right:6px;margin-bottom:6px;display:inline-flex;align-items:center;gap:6px;">
                                        <?= e($m['code']) ?> - <?= e($m['name']) ?>
                                        <form method="post" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_major" value="<?= $m['id'] ?>">
                                            <button type="submit" style="background:none;border:none;color:inherit;cursor:pointer;font-size:11px;" data-confirm="Remove this major?">✕</button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                                <form method="post" style="display:flex;gap:6px;margin-top:8px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="program_id" value="<?= $p['id'] ?>">
                                    <input class="input" type="text" name="major_code" placeholder="Code (e.g. MATH)" style="max-width:140px;font-size:12.5px;padding:7px 10px;" required>
                                    <input class="input" type="text" name="major_name" placeholder="Major name" style="font-size:12.5px;padding:7px 10px;" required>
                                    <button type="submit" name="add_major" value="1" class="btn btn-outline btn-sm">Add Major</button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card">
                    <h3>Add Program</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Program Code</label><input class="input" type="text" name="code" required placeholder="e.g. BSCS"></div>
                        <div class="form-group"><label>Program Name</label><input class="input" type="text" name="name" required placeholder="e.g. Bachelor of Science in Computer Science"></div>
                        <div class="form-group"><label>Length of program</label>
                            <div class="length-field">
                                <input class="input" type="number" name="length" value="4" min="1" max="48" step="any" required aria-label="Length">
                                <select class="input" name="unit" aria-label="Unit"><option value="months">months</option><option value="years" selected>years</option></select>
                            </div>
                            <div class="field-hint">Anything from 6 months (short courses) to 4 years.</div>
                        </div>
                        <div class="form-group"><label>Description (optional)</label><textarea class="input" name="description" rows="3"></textarea></div>
                        <button type="submit" name="add_program" value="1" class="btn btn-primary btn-block">Add Program</button>
                    </form>
                </div>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
<script>
document.querySelectorAll('[data-toggle-edit]').forEach(function (b) {
  b.addEventListener('click', function () {
    var f = document.getElementById(b.getAttribute('data-toggle-edit'));
    f.hidden = !f.hidden;
    document.querySelectorAll('[data-toggle-edit="' + f.id + '"][aria-expanded]').forEach(function (t) { t.setAttribute('aria-expanded', f.hidden ? 'false' : 'true'); });
    if (!f.hidden) f.querySelector('input').focus();
  });
});
</script>
</body>
</html>
