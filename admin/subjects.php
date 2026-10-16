<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'subjects';
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['add_subject'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '📘') ?: '📘';
        if ($name !== '') {
            $pdo->prepare("INSERT INTO subjects (name, icon) VALUES (?, ?)")->execute([$name, $icon]);
            $pdo->prepare("INSERT INTO subject_weights (subject_id) VALUES (?)")->execute([(int) $pdo->lastInsertId()]);
            flash_set('success', 'Subject added with default weights (40/25/15/10/10).');
        }
    } elseif (isset($_POST['delete_subject'])) {
        $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([(int) $_POST['delete_subject']]);
        flash_set('success', 'Subject deleted.');
    } elseif (isset($_POST['save_weights'])) {
        $subjectId = (int) $_POST['subject_id'];
        $w = [
            'w_past' => (float) $_POST['w_past'],
            'w_attendance' => (float) $_POST['w_attendance'],
            'w_assignments' => (float) $_POST['w_assignments'],
            'w_quizzes' => (float) $_POST['w_quizzes'],
            'w_participation' => (float) $_POST['w_participation'],
        ];
        $sum = array_sum($w);
        if ($sum < 0.98 || $sum > 1.02) {
            flash_set('error', 'Weights must add up to 100% (they added up to ' . round($sum * 100) . '%).');
        } else {
            $pdo->prepare("
                INSERT INTO subject_weights (subject_id, w_past, w_attendance, w_assignments, w_quizzes, w_participation)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE w_past=VALUES(w_past), w_attendance=VALUES(w_attendance),
                    w_assignments=VALUES(w_assignments), w_quizzes=VALUES(w_quizzes), w_participation=VALUES(w_participation)
            ")->execute([$subjectId, $w['w_past'], $w['w_attendance'], $w['w_assignments'], $w['w_quizzes'], $w['w_participation']]);
            log_activity((int) $user['id'], 'Weights Updated', "Updated prediction weights for subject #$subjectId");
            flash_set('success', 'Weights saved. New predictions for this subject will use them going forward.');
        }
        header('Location: subjects.php?weights=' . $subjectId);
        exit;
    }
    header('Location: subjects.php');
    exit;
}

$subjects = $pdo->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM student_subjects ss WHERE ss.subject_id = s.id) as enrolled_count,
        (SELECT AVG(pp.predicted_grade) FROM predictions pp WHERE pp.subject_id = s.id) as avg_g
    FROM subjects s
    ORDER BY s.name
")->fetchAll(PDO::FETCH_ASSOC);

$editWeightsId = isset($_GET['weights']) ? (int) $_GET['weights'] : null;
$editWeights = null;
$editSubject = null;
if ($editWeightsId) {
    foreach ($subjects as $s) {
        if ((int) $s['id'] === $editWeightsId) { $editSubject = $s; break; }
    }
    if ($editSubject) {
        $editWeights = subject_weights($pdo, $editWeightsId);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subjects — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Subjects</h2><p>Manage the subjects tracked by GradePredict AI, and how each one weighs the prediction factors.</p></div></div>
            <?= render_flash($message) ?>

            <?php if ($editSubject): ?>
            <div class="card" style="max-width:480px;margin-bottom:18px;">
                <div class="card-head"><h3><?= e($editSubject['icon']) ?> <?= e($editSubject['name']) ?> — Prediction Weights</h3><a href="subjects.php" class="link">Close</a></div>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px;">Must add up to 100%. Defaults to 40/25/15/10/10 (Past Grades / Attendance / Assignments / Quizzes / Participation).</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="subject_id" value="<?= $editSubject['id'] ?>">
                    <?php
                    $weightFields = [
                        ['w_past', 'Past Grades'], ['w_attendance', 'Attendance'], ['w_assignments', 'Assignments'],
                        ['w_quizzes', 'Quizzes'], ['w_participation', 'Participation'],
                    ];
                    foreach ($weightFields as [$key, $label]):
                        $pct = round($editWeights[$key] * 100);
                    ?>
                        <div class="form-group">
                            <label><?= $label ?></label>
                            <div class="slider-row">
                                <input type="range" min="0" max="100" name="<?= $key ?>_pct" value="<?= $pct ?>" data-live="v_<?= $key ?>">
                                <span class="val" id="v_<?= $key ?>"><?= $pct ?>%</span>
                            </div>
                            <input type="hidden" name="<?= $key ?>" value="<?= $editWeights[$key] ?>" data-weight-fraction-for="<?= $key ?>_pct">
                        </div>
                    <?php endforeach; ?>
                    <div class="field-hint" id="weightTotal">Total: 100%</div>
                    <button type="submit" name="save_weights" value="1" class="btn btn-primary" style="margin-top:10px;">Save Weights</button>
                </form>
            </div>
            <script>
                // Keep the hidden fraction inputs in sync with the visible percentage sliders, and show a live total.
                document.querySelectorAll('[data-weight-fraction-for]').forEach(function (hidden) {
                    var slider = document.querySelector('[name="' + hidden.getAttribute('data-weight-fraction-for') + '"]');
                    if (!slider) return;
                    var sync = function () {
                        hidden.value = (parseFloat(slider.value) / 100).toFixed(2);
                        var total = 0;
                        document.querySelectorAll('[data-weight-fraction-for]').forEach(function (h) { total += parseFloat(h.value || 0); });
                        var totalEl = document.getElementById('weightTotal');
                        if (totalEl) totalEl.textContent = 'Total: ' + Math.round(total * 100) + '%';
                    };
                    slider.addEventListener('input', sync);
                    sync();
                });
            </script>
            <?php endif; ?>

            <div class="grid grid-3">
                <div class="card" style="grid-column: span 2;">
                    <table>
                        <thead><tr><th>Subject</th><th>Enrolled</th><th>Avg Predicted</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($subjects as $s): ?>
                            <tr>
                                <td class="row-icon"><span class="subj-icon"><?= e($s['icon']) ?></span><?= e($s['name']) ?></td>
                                <td><?= (int) $s['enrolled_count'] ?></td>
                                <td><?= $s['avg_g'] !== null ? round((float) $s['avg_g'], 1) . '%' : '—' ?></td>
                                <td style="display:flex;gap:6px;">
                                    <a href="?weights=<?= $s['id'] ?>" class="btn btn-outline btn-sm">Weights</a>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_subject" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" data-confirm="This subject will be removed from every student, and cannot be undone." data-confirm-label="Delete Subject">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Add Subject</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Icon (emoji)</label><input class="input" type="text" name="icon" maxlength="4" placeholder="📘"></div>
                        <div class="form-group"><label>Subject Name</label><input class="input" type="text" name="name" required placeholder="e.g. Computer Science"></div>
                        <button type="submit" name="add_subject" value="1" class="btn btn-primary btn-block">Add Subject</button>
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
