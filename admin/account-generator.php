<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'generator';
$pdo = db();
header('Cache-Control: no-store');   // this page can show temporary passwords: keep it out of browser/proxy caches

const GEN_MAX_BATCH = 200;
$roleDomains = ['student' => 'student.gradepredict.ai', 'teacher' => 'gradepredict.ai', 'parent' => 'gradepredict.ai'];
$patterns = ['first.last' => 'juan.delacruz', 'flast' => 'jdelacruz', 'last.first' => 'delacruz.juan'];

$programs = $pdo->query("SELECT id, code, name, duration_months FROM programs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$majors = $pdo->query("SELECT id, program_id, code, name FROM majors ORDER BY program_id, code")->fetchAll(PDO::FETCH_ASSOC);
$sections = $pdo->query("SELECT id, name, program_id, major_id, year_level FROM sections ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$mode = ($_POST['mode'] ?? $_GET['mode'] ?? 'bulk') === 'single' ? 'single' : 'bulk';
$in = [
    'role' => 'student', 'pattern' => 'first.last', 'domain' => $roleDomains['student'], 'names' => '',
    'full_name' => '', 'email' => '', 'link_student' => '', 'program_id' => '', 'major_id' => '', 'year_level' => '1', 'section_id' => '',
];
$errors = [];
$preview = null;   // rows with generated emails, shown before anything is created
$results = null;   // accounts that were just created (the only time passwords are visible)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $allowedRoles = $mode === 'single' ? ['student', 'teacher', 'parent'] : ['student', 'teacher'];
    $in['role'] = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'student';
    $in['pattern'] = isset($patterns[$_POST['pattern'] ?? '']) ? $_POST['pattern'] : 'first.last';
    $in['domain'] = strtolower(ltrim(trim((string) ($_POST['domain'] ?? '')), '@'));
    $in['names'] = (string) ($_POST['names'] ?? '');
    $in['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $in['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
    $in['link_student'] = trim((string) ($_POST['link_student'] ?? ''));
    $in['program_id'] = ((int) ($_POST['program_id'] ?? 0)) ?: '';
    $in['major_id'] = ((int) ($_POST['major_id'] ?? 0)) ?: '';
    $in['year_level'] = (string) clamp_year_level($pdo, ((int) ($_POST['program_id'] ?? 0)) ?: null, (int) ($_POST['year_level'] ?? 1));
    $in['section_id'] = ((int) ($_POST['section_id'] ?? 0)) ?: '';

    $role = $in['role'];
    $opts = [
        'role' => $role, 'pattern' => $in['pattern'], 'domain' => $in['domain'],
        'program_id' => (int) $in['program_id'], 'major_id' => (int) $in['major_id'], 'year_level' => (int) $in['year_level'],
        'section_id' => (int) $in['section_id'], 'link_student_id' => 0,
    ];

    // A chosen section decides program, major and year (same rule as when a teacher adds a student to a class).
    if ($role === 'student' && $opts['section_id']) {
        $found = array_values(array_filter($sections, fn($sec) => (int) $sec['id'] === $opts['section_id']));
        if (!$found) {
            $errors[] = 'That section no longer exists.';
        } else {
            $opts['program_id'] = (int) ($found[0]['program_id'] ?: $opts['program_id']);
            $opts['major_id'] = (int) ($found[0]['major_id'] ?: $opts['major_id']);
            $opts['year_level'] = (int) ($found[0]['year_level'] ?: $opts['year_level']);
        }
    }
    if ($role === 'student' && !$errors && !$opts['program_id']) {
        $errors[] = 'Choose a program (or a section) for the students.';
    }
    $typedEmail = $mode === 'single' && $in['email'] !== '';
    if (!$typedEmail && !valid_email_domain($in['domain'])) {
        $errors[] = 'The email domain should look like school.edu.ph (letters, numbers, dots and hyphens).';
    }

    $rows = [];
    if (!$errors && $mode === 'bulk') {
        $rows = parse_name_lines($in['names']);
        $validCount = count(array_filter($rows, fn($r) => $r['person']));
        if (!$rows) {
            $errors[] = 'Paste at least one name, one per line.';
        } elseif (count($rows) > GEN_MAX_BATCH) {
            $errors[] = 'That is ' . count($rows) . ' lines. Please keep each batch to ' . GEN_MAX_BATCH . ' names or fewer.';
        } elseif ($validCount === 0) {
            $errors[] = 'None of those lines look like a name. Use one name per line, for example "Juan Dela Cruz".';
        }
    }
    if (!$errors && $mode === 'single') {
        $person = parse_person_name($in['full_name']);
        if (!$person) {
            $errors[] = 'Enter the full name using letters only, for example "Juan Dela Cruz".';
        } elseif ($typedEmail && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address does not look right.';
        } else {
            $rows = [['line' => 1, 'raw' => $in['full_name'], 'person' => $person, 'error' => null, 'email_fixed' => $typedEmail ? $in['email'] : null]];
        }
        if (!$errors && $role === 'parent') {
            $lookup = $pdo->prepare("SELECT id FROM users WHERE role = 'student' AND account_status = 'approved' AND (student_no = ? OR email = ?)");
            $lookup->execute([$in['link_student'], strtolower($in['link_student'])]);
            $childId = (int) $lookup->fetchColumn();
            if (!$childId) {
                $errors[] = 'Enter the student number or email of the child this parent belongs to. No approved student matches that.';
            }
            $opts['link_student_id'] = $childId;
        }
    }

    if (!$errors && $action === 'preview' && $mode === 'bulk') {
        $preview = plan_generated_emails($pdo, $rows, $in['pattern'], $in['domain']);
    } elseif (!$errors && $action === 'create') {
        if (!check_idempotent('generate_accounts')) {
            $errors[] = 'This request was already processed or has expired, so nothing new was created. Check Users to see the accounts, or preview again if you still need to create them.';
        } else {
            try {
                $results = create_generated_accounts($pdo, $rows, $opts, (int) $user['id']);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage() . ' Nothing was created.';
            } catch (Throwable $e) {
                error_log('Account generator failed: ' . $e->getMessage());
                $errors[] = $e->getCode() === '23000'
                    ? 'One of the email addresses was taken a moment ago. Nothing was created. Please preview again.'
                    : 'Something went wrong and nothing was created. Please try again.';
            }
        }
    }
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
    $host = 'localhost';
}
$signInUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $host . BASE_URL . '/login.php';

$validPreview = $preview ? array_values(array_filter($preview, fn($r) => $r['person'])) : [];
$skipped = $preview ? count($preview) - count($validPreview) : 0;
$isStudentRole = $in['role'] === 'student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account generator — GradePredict AI Admin</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
<script>try{if(localStorage.getItem("gp-theme")==="dark")document.documentElement.setAttribute("data-theme","dark")}catch(e){}</script>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
    <div class="main">
        <?php $showSearch = true; include __DIR__ . '/../includes/topbar.php'; ?>
        <div class="content">
            <div class="page-head no-print">
                <div>
                    <h2>Account generator</h2>
                    <p>Create accounts with email addresses and temporary passwords. Everyone must choose their own password the first time they sign in.</p>
                </div>
                <div class="view-toggle" role="group" aria-label="How many accounts">
                    <a href="?mode=single" class="<?= $mode === 'single' ? 'active' : '' ?>" <?= $mode === 'single' ? 'aria-current="page"' : '' ?>>One account</a>
                    <a href="?mode=bulk" class="<?= $mode === 'bulk' ? 'active' : '' ?>" <?= $mode === 'bulk' ? 'aria-current="page"' : '' ?>>Many accounts</a>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-error no-print" role="alert"><span class="alert-ic">!</span><span><?php foreach ($errors as $err): ?><?= e($err) ?> <?php endforeach; ?></span></div>
            <?php endif; ?>

            <?php if ($results): ?>
            <!-- ============ Accounts were created: the one place the passwords are shown ============ -->
            <div class="card" id="resultsCard">
                <div class="card-head no-print">
                    <h3><?= count($results) ?> <?= count($results) === 1 ? 'account' : 'accounts' ?> created</h3>
                    <a href="?mode=<?= e($mode) ?>" class="link">Create more</a>
                </div>
                <h3 class="print-only">GradePredict AI — account details</h3>
                <div class="callout warning">
                    <strong>Save these now.</strong> Temporary passwords are shown only on this page. They are stored scrambled, so nobody (including you) can look them up later.
                    If one is lost, reset that person's password from the sign-in page ("Forgot password") or create the account again.
                </div>
                <p class="label-quiet" style="margin:12px 0;">Sign-in page: <strong><?= e($signInUrl) ?></strong></p>
                <div class="btn-row no-print">
                    <button type="button" class="btn btn-primary" data-download-csv>Download CSV</button>
                    <button type="button" class="btn btn-outline" data-copy-table>Copy as table</button>
                    <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
                </div>
                <div class="table-scroll">
                    <table class="creds-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Temporary password</th><?php if ($results[0]['student_no']): ?><th>Student no.</th><?php endif; ?><th class="no-print"></th></tr></thead>
                        <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><?= e($r['name']) ?></td>
                                <td><?= e($r['email']) ?></td>
                                <td class="mono"><?= e($r['password']) ?></td>
                                <?php if ($results[0]['student_no']): ?><td><?= e((string) $r['student_no']) ?></td><?php endif; ?>
                                <td class="no-print"><button type="button" class="btn btn-outline btn-sm" data-copy="<?= e($r['email'] . "\t" . $r['password']) ?>" aria-label="Copy email and password for <?= e($r['name']) ?>">Copy</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <script type="application/json" id="batchData"><?= json_encode(['signIn' => $signInUrl, 'rows' => $results], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
            </div>

            <?php else: ?>
            <div class="grid grid-2 gen-grid">
                <!-- ============ Form ============ -->
                <div class="card">
                    <?php if ($mode === 'single'): ?>
                    <h3>One account</h3>
                    <form method="post" autocomplete="off" id="genForm">
                        <?= csrf_field() ?>
                        <?= idempotency_field('generate_accounts') ?>
                        <input type="hidden" name="mode" value="single">
                        <input type="hidden" name="action" value="create">

                        <div class="form-group">
                            <label for="full_name">Full name</label>
                            <input class="input" type="text" id="full_name" name="full_name" required maxlength="100" value="<?= e($in['full_name']) ?>" placeholder="Juan Dela Cruz">
                        </div>
                        <?php include __DIR__ . '/../includes/generator_role.php'; ?>
                        <div class="form-group" id="linkField" <?= $in['role'] === 'parent' ? '' : 'hidden' ?>>
                            <label for="link_student">Child's student number or email</label>
                            <input class="input" type="text" id="link_student" name="link_student" value="<?= e($in['link_student']) ?>" placeholder="GP-2026-0001">
                            <div class="field-hint">The parent will see this student's predictions.</div>
                        </div>
                        <div class="form-group">
                            <label for="email">Email address <span class="label-quiet">(optional)</span></label>
                            <input class="input" type="email" id="email" name="email" value="<?= e($in['email']) ?>" placeholder="Leave empty to generate one from the name">
                        </div>
                        <?php include __DIR__ . '/../includes/generator_email.php'; ?>
                        <?php include __DIR__ . '/../includes/generator_student.php'; ?>
                        <button type="submit" class="btn btn-primary btn-block">Create account</button>
                    </form>

                    <?php else: ?>
                    <h3>Many accounts</h3>
                    <form method="post" autocomplete="off" id="genForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="bulk">
                        <input type="hidden" name="action" value="preview">

                        <div class="form-group">
                            <label for="names">Names, one per line</label>
                            <textarea class="input" id="names" name="names" rows="9" required placeholder="Juan Dela Cruz&#10;Dela Cruz, Maria Santos&#10;Pedro Reyes"><?= e($in['names']) ?></textarea>
                            <div class="field-hint">Up to <?= GEN_MAX_BATCH ?> names. Either "First Last" or "Last, First" works.</div>
                        </div>
                        <?php include __DIR__ . '/../includes/generator_role.php'; ?>
                        <?php include __DIR__ . '/../includes/generator_email.php'; ?>
                        <?php include __DIR__ . '/../includes/generator_student.php'; ?>
                        <button type="submit" class="btn btn-primary btn-block">Preview accounts</button>
                        <p class="field-hint" style="text-align:center;">Nothing is created until you confirm the preview.</p>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- ============ Preview / explanation ============ -->
                <div class="card">
                    <?php if ($preview): ?>
                        <div class="card-head"><h3>Preview</h3><span class="label-quiet"><?= count($validPreview) ?> to create<?= $skipped ? ', ' . $skipped . ' skipped' : '' ?></span></div>
                        <div class="table-scroll" style="max-height:420px;overflow:auto;">
                            <table class="table-compact">
                                <thead><tr><th>#</th><th>Name</th><th>Email</th></tr></thead>
                                <tbody>
                                <?php foreach ($preview as $i => $r): ?>
                                    <tr class="<?= $r['person'] ? '' : 'row-skipped' ?>">
                                        <td><?= $i + 1 ?></td>
                                        <?php if ($r['person']): ?>
                                            <td><?= e($r['person']['full']) ?></td><td><?= e($r['email']) ?></td>
                                        <?php else: ?>
                                            <td><?= e($r['raw']) ?></td><td><span class="badge badge-poor">Skipped</span> <span class="label-quiet"><?= e($r['error']) ?></span></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($skipped): ?><p class="field-hint">Skipped lines will not be created. Fix them on the left and preview again if you need them.</p><?php endif; ?>
                        <form method="post" style="margin-top:16px;">
                            <?= csrf_field() ?>
                            <?= idempotency_field('generate_accounts') ?>
                            <input type="hidden" name="mode" value="bulk">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="role" value="<?= e($in['role']) ?>">
                            <input type="hidden" name="pattern" value="<?= e($in['pattern']) ?>">
                            <input type="hidden" name="domain" value="<?= e($in['domain']) ?>">
                            <input type="hidden" name="program_id" value="<?= e((string) $in['program_id']) ?>">
                            <input type="hidden" name="major_id" value="<?= e((string) $in['major_id']) ?>">
                            <input type="hidden" name="year_level" value="<?= e($in['year_level']) ?>">
                            <input type="hidden" name="section_id" value="<?= e((string) $in['section_id']) ?>">
                            <textarea name="names" hidden><?= e($in['names']) ?></textarea>
                            <button type="submit" class="btn btn-primary btn-block">Create <?= count($validPreview) ?> <?= count($validPreview) === 1 ? 'account' : 'accounts' ?></button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <div class="empty-ic-wrap"><?= icon('key', 24) ?></div>
                            <h4>How it works</h4>
                            <p><?= $mode === 'bulk'
                                ? 'Paste your list, preview the email addresses, then create everyone at once. Each person gets a random temporary password.'
                                : 'Enter the name and choose a role. The email address and a random temporary password are generated for you.' ?></p>
                        </div>
                        <ul class="plain-list">
                            <li>Passwords are shown once, right after creating, and can be downloaded as a CSV or printed.</li>
                            <li>If two people would get the same address, a number is added: juan.delacruz, juan.delacruz2.</li>
                            <li>No email is sent. Hand the details to each person yourself.</li>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </div>
</div>
<script src="<?= asset_url('/assets/js/app.js') ?>"></script>
<script>
(function () {
  var form = document.getElementById('genForm');
  if (!form) return;
  var domain = document.getElementById('domain');
  var domainTouched = <?= json_encode($_SERVER['REQUEST_METHOD'] === 'POST' && $in['domain'] !== ($roleDomains[$in['role']] ?? '')) ?>;
  var defaults = <?= json_encode($roleDomains) ?>;
  var studentFields = document.getElementById('studentFields');
  var linkField = document.getElementById('linkField');
  if (domain) domain.addEventListener('input', function () { domainTouched = true; });
  function onRole() {
    var checked = form.querySelector('input[name="role"]:checked');
    if (!checked) return;
    if (domain && !domainTouched) domain.value = defaults[checked.value] || domain.value;
    if (studentFields) studentFields.hidden = checked.value !== 'student';
    if (linkField) linkField.hidden = checked.value !== 'parent';
  }
  form.querySelectorAll('input[name="role"]').forEach(function (r) { r.addEventListener('change', onRole); });
  // Only offer majors that belong to the chosen program
  var program = document.getElementById('program_id'), major = document.getElementById('major_id');
  function onProgram() {
    if (!program || !major) return;
    Array.prototype.forEach.call(major.options, function (o) {
      var show = !o.value || !o.dataset.program || o.dataset.program === program.value;
      o.hidden = !show; o.disabled = !show;
    });
    if (major.selectedOptions[0] && major.selectedOptions[0].disabled) major.value = '';
  }
  if (program) { program.addEventListener('change', onProgram); onProgram(); }
  onRole();
})();
</script>
</body>
</html>
