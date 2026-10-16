<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$active = 'predictions';
$year = current_school_year();
$pdo = db();
$message = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flag_subject'])) {
    verify_csrf();
    $subjectId = (int) $_POST['subject_id'];
    $flagMessage = trim($_POST['flag_message'] ?? '');
    if ($flagMessage === '') {
        flash_set('error', 'Please describe what looks wrong before submitting.');
    } else {
        $pdo->prepare("INSERT INTO grade_flags (student_id, subject_id, message) VALUES (?, ?, ?)")
            ->execute([$user['id'], $subjectId, $flagMessage]);
        // Notify all admins so someone actually sees it.
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $a) {
            notify($pdo, (int) $a['id'], $user['full_name'] . ' flagged a grade for review.', '/admin/grade-flags.php');
        }
        log_activity((int) $user['id'], 'Grade Flagged', 'Flagged a subject prediction for review');
        flash_set('success', 'Thanks — your school administrator has been notified and will follow up.');
    }
    header('Location: predictions.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.icon, gi.past_grades, gi.attendance, gi.assignments, gi.quizzes, gi.participation,
           p.predicted_grade, p.status
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    LEFT JOIN grade_inputs gi ON gi.subject_id = s.id AND gi.student_id = ss.student_id AND gi.school_year = ss.school_year
    LEFT JOIN predictions p ON p.subject_id = s.id AND p.student_id = ss.student_id
        AND p.id = (SELECT id FROM predictions p2 WHERE p2.subject_id = s.id AND p2.student_id = ss.student_id ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)
    WHERE ss.student_id = ? AND ss.school_year = ?
    ORDER BY s.name
");
$stmt->execute([$user['id'], $year]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Which subjects already have an open flag, so we don't show the button twice.
$openFlags = $pdo->prepare("SELECT subject_id FROM grade_flags WHERE student_id = ? AND status = 'Open'");
$openFlags->execute([$user['id']]);
$openFlagSubjectIds = array_column($openFlags->fetchAll(PDO::FETCH_ASSOC), 'subject_id');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Predictions — GradePredict AI</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_student.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head"><div><h2>Predictions</h2><p>A detailed breakdown of how each subject's prediction is calculated.</p></div></div>
            <?= render_flash($message) ?>

            <?php if (empty($rows)): ?>
                <div class="card empty-state">No subjects enrolled yet. <a href="subjects.php" class="link">Add a subject</a>.</div>
            <?php endif; ?>

            <div class="grid grid-2">
            <?php foreach ($rows as $r):
                $pg = (float) $r['predicted_grade'];
                $w = subject_weights($pdo, (int) $r['id']);
                $trend = trend_for($pdo, (int) $user['id'], (int) $r['id']);
                $confidence = confidence_for($pdo, (int) $user['id'], (int) $r['id']);
                $alreadyFlagged = in_array($r['id'], $openFlagSubjectIds, true);
            ?>
                <div class="card">
                    <div class="card-head">
                        <h3 class="row-icon"><span class="subj-icon"><?= e($r['icon']) ?></span><?= e($r['name']) ?></h3>
                        <span class="badge <?= status_badge_class($r['status'] ?? 'Poor') ?>"><?= e($r['status'] ?? 'No data') ?></span>
                    </div>
                    <div style="font-size:30px;font-weight:700;margin-bottom:8px;"><?= $r['predicted_grade'] !== null ? e((string) $r['predicted_grade']) . '%' : '—' ?> <span style="font-size:14px;color:var(--text-muted);font-weight:500;">predicted (<?= e(letter_grade($pg)) ?>)</span></div>
                    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
                        <span class="badge <?= $trend['direction'] === 'up' ? 'badge-verygood' : ($trend['direction'] === 'down' ? 'badge-poor' : 'badge-good') ?>"><?= $trend['icon'] ?> <?= e($trend['label']) ?></span>
                        <span class="badge <?= $confidence['class'] ?>" data-tooltip="Based on <?= $confidence['count'] ?> recorded update(s)">Confidence: <?= $confidence['level'] ?></span>
                    </div>

                    <?php
                    $factors = [
                        ['Past Grades', $r['past_grades'], round($w['w_past'] * 100), 'var(--blue)'],
                        ['Attendance', $r['attendance'], round($w['w_attendance'] * 100), 'var(--green)'],
                        ['Assignments', $r['assignments'], round($w['w_assignments'] * 100), 'var(--amber)'],
                        ['Quizzes', $r['quizzes'], round($w['w_quizzes'] * 100), 'var(--purple)'],
                        ['Participation', $r['participation'], round($w['w_participation'] * 100), '#ec4899'],
                    ];
                    foreach ($factors as $f):
                        [$label, $val, $weight, $color] = $f;
                        $val = (float) ($val ?? 0);
                    ?>
                        <div style="margin-bottom:10px;">
                            <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;">
                                <span><?= e($label) ?> <span style="color:var(--text-muted)">(<?= $weight ?>% weight)</span></span>
                                <span style="font-weight:600;"><?= e((string) $val) ?>%</span>
                            </div>
                            <div class="progress-bar"><div style="width:<?= e((string) $val) ?>%;background:<?= $color ?>;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
                        <a href="data-inputs.php?subject=<?= $r['id'] ?>" class="link">Update data →</a>
                        <?php if ($alreadyFlagged): ?>
                            <span class="badge badge-fair" data-tooltip="An admin has been notified">🚩 Flagged — Pending</span>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('flagForm<?= $r['id'] ?>').classList.toggle('open-flag')">🚩 Flag for Review</button>
                        <?php endif; ?>
                    </div>
                    <?php if (!$alreadyFlagged): ?>
                        <div id="flagForm<?= $r['id'] ?>" class="flag-form">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="subject_id" value="<?= $r['id'] ?>">
                                <div class="form-group" style="margin-top:12px;">
                                    <label>What looks wrong?</label>
                                    <textarea class="input" name="flag_message" rows="3" placeholder="e.g. My attendance looks lower than it should be..." required></textarea>
                                </div>
                                <button type="submit" name="flag_subject" value="1" class="btn btn-primary btn-sm">Submit to Admin</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<style>.flag-form { display: none; } .flag-form.open-flag { display: block; }</style>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
</body>
</html>
