<?php
/**
 * includes/functions.php
 * Shared helper functions used across the whole app.
 */

// ---- Default prediction weights (used when a subject has no custom weights row) ----
const W_PAST_GRADES  = 0.40;
const W_ATTENDANCE   = 0.25;
const W_ASSIGNMENTS  = 0.15;
const W_QUIZZES      = 0.10;
const W_PARTICIPATION = 0.10;

function subject_weights(PDO $pdo, int $subjectId): array
{
    $stmt = $pdo->prepare("SELECT w_past, w_attendance, w_assignments, w_quizzes, w_participation FROM subject_weights WHERE subject_id = ?");
    $stmt->execute([$subjectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['w_past' => W_PAST_GRADES, 'w_attendance' => W_ATTENDANCE, 'w_assignments' => W_ASSIGNMENTS, 'w_quizzes' => W_QUIZZES, 'w_participation' => W_PARTICIPATION];
    }
    return $row;
}

function calculate_prediction(PDO $pdo, int $subjectId, float $past, float $attendance, float $assignments, float $quizzes, float $participation): float
{
    $w = subject_weights($pdo, $subjectId);
    $score = ($past * $w['w_past'])
        + ($attendance * $w['w_attendance'])
        + ($assignments * $w['w_assignments'])
        + ($quizzes * $w['w_quizzes'])
        + ($participation * $w['w_participation']);

    return round(max(0, min(100, $score)), 1);
}

function status_label(float $grade): string
{
    if ($grade >= 90) return 'Excellent';
    if ($grade >= 80) return 'Very Good';
    if ($grade >= 70) return 'Good';
    if ($grade >= 60) return 'Fair';
    return 'Poor';
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'Excellent'  => 'badge-excellent',
        'Very Good'  => 'badge-verygood',
        'Good'       => 'badge-good',
        'Fair'       => 'badge-fair',
        default      => 'badge-poor',
    };
}

function risk_level(float $grade): string
{
    if ($grade < 60) return 'High';
    if ($grade < 70) return 'Medium';
    return 'Low';
}

function risk_badge_class(string $risk): string
{
    return match ($risk) {
        'High'   => 'badge-poor',
        'Medium' => 'badge-fair',
        default  => 'badge-verygood',
    };
}

function letter_grade(float $grade): string
{
    if ($grade >= 97) return 'A+';
    if ($grade >= 93) return 'A';
    if ($grade >= 90) return 'A-';
    if ($grade >= 87) return 'B+';
    if ($grade >= 83) return 'B';
    if ($grade >= 80) return 'B-';
    if ($grade >= 77) return 'C+';
    if ($grade >= 73) return 'C';
    if ($grade >= 70) return 'C-';
    if ($grade >= 60) return 'D';
    return 'F';
}

function save_prediction(PDO $pdo, int $studentId, int $subjectId, string $schoolYear, float $predicted): void
{
    $pdo->prepare("INSERT INTO predictions (student_id, subject_id, school_year, predicted_grade, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$studentId, $subjectId, $schoolYear, $predicted, status_label($predicted)]);
}

/** Records a snapshot of raw factor scores every time they change, for the audit trail. */
function log_grade_history(PDO $pdo, int $studentId, int $subjectId, string $schoolYear, array $vals, ?int $changedBy): void
{
    $pdo->prepare("INSERT INTO grade_input_history (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$studentId, $subjectId, $schoolYear, $vals[0], $vals[1], $vals[2], $vals[3], $vals[4], $changedBy]);
}

/**
 * Looks at a subject's last few predictions for a student and reports whether
 * the trend is improving, declining, or stable. Purely informational — it does
 * NOT change the predicted grade itself, so the grading formula stays transparent.
 */
function trend_for(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare("SELECT predicted_grade FROM predictions WHERE student_id = ? AND subject_id = ? ORDER BY created_at DESC, id DESC LIMIT 3");
    $stmt->execute([$studentId, $subjectId]);
    $vals = array_map('floatval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'predicted_grade'));
    if (count($vals) < 2) {
        return ['direction' => 'flat', 'label' => 'Not enough history yet', 'icon' => '→', 'delta' => 0];
    }
    $delta = round($vals[0] - end($vals), 1);
    if ($delta >= 1.5) return ['direction' => 'up', 'label' => 'Improving', 'icon' => '↑', 'delta' => $delta];
    if ($delta <= -1.5) return ['direction' => 'down', 'label' => 'Declining', 'icon' => '↓', 'delta' => $delta];
    return ['direction' => 'flat', 'label' => 'Stable', 'icon' => '→', 'delta' => $delta];
}

/**
 * A simple confidence signal based on how many times data has actually been
 * recorded for this student/subject — one entry looks exactly as "certain" as
 * fifty otherwise, which is misleading.
 */
function confidence_for(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM grade_input_history WHERE student_id = ? AND subject_id = ?");
    $stmt->execute([$studentId, $subjectId]);
    $count = (int) $stmt->fetch()['c'];
    if ($count >= 5) return ['level' => 'High', 'class' => 'badge-verygood', 'count' => $count];
    if ($count >= 2) return ['level' => 'Medium', 'class' => 'badge-fair', 'count' => $count];
    return ['level' => 'Low', 'class' => 'badge-poor', 'count' => $count];
}

function log_activity(int $userId, string $action, string $description = ''): void
{
    db()->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)")
        ->execute([$userId, $action, $description]);
}

/* ---------------- Notifications ---------------- */

function notify(PDO $pdo, int $userId, string $message, ?string $link = null): void
{
    $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")->execute([$userId, $message, $link]);
}

function unread_notification_count(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'];
}

function recent_notifications(int $userId, int $limit = 8): array
{
    $stmt = db()->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------- CSRF protection ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Call at the top of every POST handler. Exits with 403 on failure. */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Your session expired or this request could not be verified. Please go back, refresh the page, and try again.');
    }
}

/* ---------------- Idempotency (for the one form where a duplicate really hurts) ---------------- */

/** Hidden field carrying a one-time token; put it in a <form> alongside csrf_field(). */
function idempotency_field(string $formName): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['idem_tokens'][$formName] = $token;
    return '<input type="hidden" name="idem_token" value="' . e($token) . '">';
}

/** Call after verify_csrf(). Returns true once per token — a duplicate/replayed
 *  submit (double-click, back-button resubmit) returns false so the caller can
 *  skip re-inserting instead of silently creating a second record. */
function check_idempotent(string $formName): bool
{
    $expected = $_SESSION['idem_tokens'][$formName] ?? null;
    $given = $_POST['idem_token'] ?? null;
    unset($_SESSION['idem_tokens'][$formName]); // one-time use either way
    return $expected !== null && is_string($given) && hash_equals($expected, $given);
}

/* ---------------- Auth / session security ---------------- */

function current_user(): ?array
{
    if (!empty($_SESSION['user_id'])) {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && ($user['account_status'] ?? 'approved') !== 'approved') {
            // Account was rejected (or put back on hold) after signing in: end the session.
            unset($_SESSION['user_id']);
            $user = null;
        }
        $cached = $user ?: null;
        return $cached;
    }

    // No active session — see if a "remember me" cookie can auto-login the user.
    return attempt_remember_login();
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const SESSION_IDLE_MINUTES = 30;

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // Idle session timeout — only applies to real logged-in sessions, not a
    // fresh "remember me" auto-login (which just set last_activity itself).
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_MINUTES * 60) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Force a password change before letting the user do anything else.
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($user['must_change_password']) && !in_array($currentScript, ['change-password.php', 'logout.php'], true)) {
        header('Location: ' . BASE_URL . '/change-password.php');
        exit;
    }

    return $user;
}

/** Accepts a single role string or an array of allowed roles. */
function require_role($roles): array
{
    $user = require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $roles, true)) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    return $user;
}

function portal_path_for(string $role): string
{
    return match ($role) {
        'admin' => '/admin/dashboard.php',
        'teacher' => '/teacher/dashboard.php',
        'parent' => '/parent/dashboard.php',
        default => '/student/dashboard.php',
    };
}

/* ---------------- Account generator ---------------- */

/** Random temporary password. Leaves out look-alike characters (0/O, 1/l/I) so it can be read
 *  off a printed slip, and always has an upper-case letter, a lower-case letter and a digit. */
function generate_temp_password(int $length = 10): string
{
    $length = max(8, $length);
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;
    $chars = [$upper[random_int(0, strlen($upper) - 1)], $lower[random_int(0, strlen($lower) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {   // shuffle so the guaranteed characters are not always first
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** Lower-case a-z0-9 only, accents folded (Peña -> pena). Works without the intl extension. */
function ascii_slug(string $text): string
{
    static $fold = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
    ];
    return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($text, 'UTF-8'), $fold));
}

/**
 * Turns one line of text into a person: ['full' => display name as "Given Last", 'given' => ..., 'last' => ...].
 * Accepts "Juan Dela Cruz" or "Dela Cruz, Juan Miguel P." Returns null if it does not look like a name.
 * Last-name particles (dela, de los, san, ...) stay attached to the surname, and Jr./III are dropped.
 */
function parse_person_name(string $raw): ?array
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($raw === '' || mb_strlen($raw) > 100 || !preg_match("/^[\\p{L}][\\p{L} .,'\\-]*$/u", $raw) || preg_match('/\p{L}.*\p{L}/u', $raw) !== 1) {
        return null;
    }
    $suffixes = ['jr', 'sr', 'ii', 'iii', 'iv'];
    $particles = ['de', 'del', 'dela', 'delos', 'la', 'las', 'los', 'san', 'santa', 'sta', 'van', 'von', 'da', 'di', 'le'];
    $dropSuffix = function (array $tokens) use ($suffixes) {
        while (count($tokens) > 1 && in_array(ascii_slug((string) end($tokens)), $suffixes, true)) {
            array_pop($tokens);
        }
        return $tokens;
    };

    if (strpos($raw, ',') !== false) {
        [$lastPart, $givenPart] = array_map('trim', explode(',', $raw, 2));
        $givenTokens = $dropSuffix(array_values(array_filter(explode(' ', $givenPart), 'strlen')));
        $last = trim($lastPart);
    } else {
        $tokens = $dropSuffix(explode(' ', $raw));
        if (count($tokens) === 1) {
            $givenTokens = $tokens;
            $last = '';
        } else {
            $last = array_pop($tokens);
            while (count($tokens) > 1 && in_array(ascii_slug((string) end($tokens)), $particles, true)) {
                $last = array_pop($tokens) . ' ' . $last;
            }
            $givenTokens = $tokens;
        }
    }
    if (!$givenTokens || ascii_slug(implode('', $givenTokens)) === '') {
        return null;
    }

    $given = implode(' ', $givenTokens);
    $full = trim($given . ' ' . $last);
    if ($full === mb_strtoupper($full, 'UTF-8') || $full === mb_strtolower($full, 'UTF-8')) {   // ALL CAPS / all lower -> Title Case
        $given = mb_convert_case($given, MB_CASE_TITLE, 'UTF-8');
        $last = mb_convert_case($last, MB_CASE_TITLE, 'UTF-8');
        $full = trim($given . ' ' . $last);
    }
    return ['full' => $full, 'given' => $given, 'last' => $last];
}

/** The part before the @, for the chosen pattern. Initials (one-letter names like "P.") are ignored. */
function email_local_part(array $person, string $pattern): string
{
    $givenWords = array_filter(explode(' ', $person['given']), fn($w) => strlen(ascii_slug($w)) > 1);
    $given = ascii_slug(implode('', $givenWords ?: [$person['given']]));
    $last = ascii_slug($person['last']);
    if ($last === '') {
        return substr($given, 0, 40);
    }
    $local = match ($pattern) {
        'flast' => substr($given, 0, 1) . $last,
        'last.first' => $last . '.' . $given,
        default => $given . '.' . $last,
    };
    return substr($local, 0, 40);
}

function valid_email_domain(string $domain): bool
{
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain);
}

/**
 * Picks an address that is not used yet — by an existing account or earlier in the same batch.
 * juan.delacruz, then juan.delacruz2, juan.delacruz3 ...  $reserved is updated as addresses are handed out.
 */
function unique_email(PDO $pdo, string $local, string $domain, array &$reserved): string
{
    $check = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
    for ($n = 1; ; $n++) {
        $candidate = $local . ($n > 1 ? (string) $n : '') . '@' . $domain;
        if (isset($reserved[$candidate])) {
            continue;
        }
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) {
            $reserved[$candidate] = true;
            return $candidate;
        }
    }
}

/** Next free student numbers for this year (GP-2026-0007, GP-2026-0008, ...), never reusing an existing one. */
function next_student_numbers(PDO $pdo, int $count): array
{
    $prefix = 'GP-' . date('Y') . '-';
    $stmt = $pdo->prepare("SELECT student_no FROM users WHERE student_no LIKE ?");
    $stmt->execute([$prefix . '%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $no) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $no, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    $out = [];
    for ($i = 1; $i <= $count; $i++) {
        $out[] = $prefix . str_pad((string) ($max + $i), 4, '0', STR_PAD_LEFT);
    }
    return $out;
}

/**
 * Splits pasted text (one name per line) into rows: ['line' => n, 'raw' => text, 'person' => array|null, 'error' => string|null].
 * Blank lines are ignored.
 */
function parse_name_lines(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $rows = [];
    foreach (preg_split('/\R/u', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $person = parse_person_name($line);
        $rows[] = ['line' => $i + 1, 'raw' => $line, 'person' => $person, 'error' => $person ? null : 'Not a valid name (letters, spaces, . , \' - only)'];
    }
    return $rows;
}

/** Works out the email for each valid row without saving anything (used for the preview and again when creating). */
function plan_generated_emails(PDO $pdo, array $rows, string $pattern, string $domain): array
{
    $reserved = [];
    foreach ($rows as &$row) {
        if (!$row['person']) {
            $row['email'] = null;
        } elseif (!empty($row['email_fixed'])) {          // an address the admin typed in themselves
            $taken = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
            $taken->execute([$row['email_fixed']]);
            if ($taken->fetchColumn() || isset($reserved[$row['email_fixed']])) {
                throw new RuntimeException('That email address is already in use.');
            }
            $reserved[$row['email_fixed']] = true;
            $row['email'] = $row['email_fixed'];
        } else {
            $row['email'] = unique_email($pdo, email_local_part($row['person'], $pattern), $domain, $reserved);
        }
    }
    unset($row);
    return $rows;
}

/**
 * Creates the accounts for every valid row inside one transaction (all or nothing).
 * $opts: role, pattern, domain, program_id, major_id, year_level, section_id, link_student_id (parents only).
 * Returns the created rows with 'email', 'password' and 'student_no'. Throws on failure.
 */
function create_generated_accounts(PDO $pdo, array $rows, array $opts, int $createdBy): array
{
    @set_time_limit(180);   // every password is hashed, so large batches take a while
    $rows = plan_generated_emails($pdo, $rows, $opts['pattern'], $opts['domain']);
    $valid = array_values(array_filter($rows, fn($r) => $r['person'] !== null));
    $role = $opts['role'];
    $isStudent = $role === 'student';
    $numbers = $isStudent ? next_student_numbers($pdo, count($valid)) : [];
    $year = current_school_year();

    $insert = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, major_id, year_level, must_change_password, can_approve)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
    $enroll = $pdo->prepare("INSERT INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)");

    $created = [];
    $pdo->beginTransaction();
    try {
        foreach ($valid as $i => $row) {
            $password = generate_temp_password();
            $studentNo = $numbers[$i] ?? null;
            $insert->execute([
                $row['person']['full'], $row['email'], password_hash($password, PASSWORD_DEFAULT), $role, ucfirst($role), $studentNo,
                $isStudent ? ($opts['program_id'] ?: null) : null, $isStudent ? ($opts['major_id'] ?: null) : null,
                $isStudent ? ($opts['year_level'] ?: 1) : null, $role === 'teacher' ? 1 : 0,
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($isStudent && !empty($opts['section_id'])) {
                $enroll->execute([$newId, $opts['section_id'], $year]);
            }
            if ($role === 'parent' && !empty($opts['link_student_id'])) {
                $pdo->prepare("INSERT INTO parent_links (parent_id, student_id) VALUES (?, ?)")->execute([$newId, $opts['link_student_id']]);
                notify($pdo, (int) $opts['link_student_id'], $row['person']['full'] . ' linked a parent account to your profile.', '/student/profile.php');
            }
            $created[] = ['name' => $row['person']['full'], 'email' => $row['email'], 'password' => $password, 'student_no' => $studentNo, 'role' => ucfirst($role)];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    log_activity($createdBy, 'Accounts Generated', count($created) . ' ' . $role . ' account(s) created with temporary passwords');
    return $created;
}

/* ---------------- Account approval ---------------- */

/** Admins can always approve; anyone else only if an admin granted them permission
 *  (teachers get it by default, an admin can switch it off or grant it to others). */
function can_approve_accounts(?array $u): bool
{
    if (!$u || ($u['account_status'] ?? 'approved') !== 'approved') {
        return false;
    }
    return $u['role'] === 'admin' || !empty($u['can_approve']);
}

/** Which kinds of sign-ups this person may decide on. Only admins handle teacher accounts. */
function approvable_roles(array $approver): array
{
    return $approver['role'] === 'admin' ? ['student', 'teacher', 'parent'] : ['student', 'parent'];
}

function pending_approval_count(PDO $pdo, array $approver): int
{
    if (!can_approve_accounts($approver)) {
        return 0;
    }
    $roles = approvable_roles($approver);
    $in = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_status = 'pending' AND role IN ($in)");
    $stmt->execute($roles);
    return (int) $stmt->fetchColumn();
}

/** Tell everyone who is allowed to decide on this sign-up that it is waiting. */
function notify_approvers(PDO $pdo, string $newName, string $newRole): void
{
    $sql = "SELECT id FROM users WHERE account_status = 'approved' AND (role = 'admin'";
    if ($newRole !== 'teacher') {
        $sql .= " OR can_approve = 1";
    }
    $sql .= ")";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $approverId) {
        notify($pdo, (int) $approverId, "$newName signed up as a $newRole and is waiting for approval.", '/approvals.php');
    }
}

/**
 * Approve or reject a pending account. Returns [bool ok, string message].
 * Re-checks permission here, not just on the page, so a crafted request cannot skip it.
 */
function decide_account(PDO $pdo, array $approver, int $targetId, string $decision): array
{
    if (!can_approve_accounts($approver)) {
        return [false, 'You do not have permission to approve accounts.'];
    }
    if ($targetId === (int) $approver['id']) {
        return [false, 'You cannot approve your own account.'];
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    // Pending sign-ups can be approved or rejected; a rejected one can still be approved later.
    $open = $target && ($target['account_status'] === 'pending' || ($target['account_status'] === 'rejected' && $decision === 'approve'));
    if (!$open) {
        return [false, 'That account is no longer waiting for a decision.'];
    }
    if (!in_array($target['role'], approvable_roles($approver), true)) {
        return [false, 'Only an administrator can decide on ' . $target['role'] . ' accounts.'];
    }

    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $pdo->prepare("UPDATE users SET account_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND account_status IN ('pending', 'rejected')")
        ->execute([$status, $approver['id'], $targetId]);

    if ($status === 'approved') {
        if ($target['role'] === 'teacher') {
            $pdo->prepare("UPDATE users SET can_approve = 1 WHERE id = ?")->execute([$targetId]);
        }
        if ($target['role'] === 'parent') {
            $links = $pdo->prepare("SELECT student_id FROM parent_links WHERE parent_id = ?");
            $links->execute([$targetId]);
            foreach ($links->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
                notify($pdo, (int) $studentId, $target['full_name'] . ' linked a parent account to your profile.', '/student/profile.php');
            }
        }
    }

    log_activity((int) $approver['id'], $status === 'approved' ? 'Account Approved' : 'Account Rejected', $target['full_name'] . ' (' . $target['email'] . ')');
    return [true, $target['full_name'] . ($status === 'approved' ? ' can now sign in.' : ' was not approved.')];
}

/* ---------------- Login throttling ---------------- */

const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 10;

function is_locked_out(array $user): bool
{
    if (empty($user['locked_until'])) {
        return false;
    }
    return strtotime($user['locked_until']) > time();
}

function register_failed_login(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("SELECT failed_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) ($stmt->fetch()['failed_attempts'] ?? 0) + 1;

    if ($attempts >= MAX_FAILED_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime('+' . LOCKOUT_MINUTES . ' minutes'));
        $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?")->execute([$lockedUntil, $userId]);
    } else {
        $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")->execute([$attempts, $userId]);
    }
}

function reset_failed_login(PDO $pdo, int $userId): void
{
    $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$userId]);
}

/* ---------------- Remember-me (persistent login) ---------------- */

const REMEMBER_COOKIE = 'gp_remember';
const REMEMBER_DAYS = 30;

function create_remember_token(PDO $pdo, int $userId): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(33));
    $expires = date('Y-m-d H:i:s', strtotime('+' . REMEMBER_DAYS . ' days'));

    $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, $selector, hash('sha256', $validator), $expires]);

    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => strtotime('+' . REMEMBER_DAYS . ' days'),
        'path' => BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
}

function attempt_remember_login(): ?array
{
    if (empty($_COOKIE[REMEMBER_COOKIE])) {
        return null;
    }
    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $validator] = $parts;

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM remember_tokens WHERE selector = ?");
    $stmt->execute([$selector]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow || strtotime($tokenRow['expires_at']) < time() || !hash_equals($tokenRow['validator_hash'], hash('sha256', $validator))) {
        clear_remember_cookie();
        return null;
    }

    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$tokenRow['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || ($user['account_status'] ?? 'approved') !== 'approved') {
        clear_remember_cookie();
        return null;
    }

    login_user($user);
    return $user;
}

function clear_remember_cookie(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => BASE_URL . '/']);
        unset($_COOKIE[REMEMBER_COOKIE]);
    }
}

function forget_all_tokens_for(PDO $pdo, int $userId): void
{
    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
}

/* ---------------- Password reset ---------------- */

const RESET_TOKEN_MINUTES = 30;

function create_password_reset(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+' . RESET_TOKEN_MINUTES . ' minutes'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
        ->execute([$userId, hash('sha256', $token), $expires]);
    return $token;
}

function validate_reset_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || strtotime($row['expires_at']) < time()) {
        return null;
    }
    return $row;
}

function consume_reset_token(PDO $pdo, int $resetId): void
{
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$resetId]);
}

/* ---------------- Misc ---------------- */

function current_school_year(): string
{
    $stmt = db()->query("SELECT value FROM settings WHERE setting_key = 'school_year'");
    $row = $stmt->fetch();
    return $row ? $row['value'] : '2025 - 2026';
}

function current_semester(): string
{
    $stmt = db()->query("SELECT value FROM settings WHERE setting_key = 'current_semester'");
    $row = $stmt->fetch();
    return $row ? $row['value'] : '1st Semester';
}

/** Converts a 0-100 predicted grade into an approximate 1.00 (highest) - 5.00 (failing) PH college scale, for display alongside the percentage. */
function grade_point(float $percentage): float
{
    if ($percentage >= 97) return 1.00;
    if ($percentage >= 94) return 1.25;
    if ($percentage >= 91) return 1.50;
    if ($percentage >= 88) return 1.75;
    if ($percentage >= 85) return 2.00;
    if ($percentage >= 82) return 2.25;
    if ($percentage >= 79) return 2.50;
    if ($percentage >= 76) return 2.75;
    if ($percentage >= 75) return 3.00;
    return 5.00; // failing
}

/** Total tuition/fee assessment for a student's program+year+semester, from fee_structures. */
function fee_amount_for(PDO $pdo, ?int $programId, ?int $yearLevel, string $semester): float
{
    if (!$programId || !$yearLevel) {
        return 0.0;
    }
    $stmt = $pdo->prepare("SELECT amount FROM fee_structures WHERE program_id = ? AND year_level = ? AND semester = ?");
    $stmt->execute([$programId, $yearLevel, $semester]);
    return (float) ($stmt->fetch()['amount'] ?? 0);
}

/** Total paid by a student for a given school year + semester. */
function total_paid(PDO $pdo, int $studentId, string $schoolYear, string $semester): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) t FROM payments WHERE student_id = ? AND school_year = ? AND semester = ?");
    $stmt->execute([$studentId, $schoolYear, $semester]);
    return (float) $stmt->fetch()['t'];
}

/* ---------------- Model accuracy ---------------- */

/**
 * Compares predictions with the grades students actually finished with.
 * $rows: each ['predicted' => float, 'actual' => float]. Pass/at-risk line is 70 (same as the "Good" band).
 * Returns counts and error measures, or null values when there is nothing to compare.
 */
function accuracy_metrics(array $rows): array
{
    $n = count($rows);
    $out = ['n' => $n, 'mae' => null, 'rmse' => null, 'bias' => null, 'within5' => null, 'band_match' => null,
            'risk_tp' => 0, 'risk_fn' => 0, 'risk_fp' => 0, 'risk_tn' => 0, 'risk_recall' => null, 'risk_precision' => null];
    if ($n === 0) {
        return $out;
    }
    $abs = $sq = $diff = 0.0;
    $within = $band = 0;
    foreach ($rows as $r) {
        $d = (float) $r['predicted'] - (float) $r['actual'];
        $abs += abs($d); $sq += $d * $d; $diff += $d;
        if (abs($d) <= 5) { $within++; }
        if (status_label((float) $r['predicted']) === status_label((float) $r['actual'])) { $band++; }
        $predRisk = (float) $r['predicted'] < 70;
        $actRisk = (float) $r['actual'] < 70;
        if ($predRisk && $actRisk) { $out['risk_tp']++; }
        elseif (!$predRisk && $actRisk) { $out['risk_fn']++; }
        elseif ($predRisk && !$actRisk) { $out['risk_fp']++; }
        else { $out['risk_tn']++; }
    }
    $out['mae'] = round($abs / $n, 2);
    $out['rmse'] = round(sqrt($sq / $n), 2);
    $out['bias'] = round($diff / $n, 2);
    $out['within5'] = round(100 * $within / $n, 1);
    $out['band_match'] = round(100 * $band / $n, 1);
    $actualRisk = $out['risk_tp'] + $out['risk_fn'];
    $flagged = $out['risk_tp'] + $out['risk_fp'];
    $out['risk_recall'] = $actualRisk ? round(100 * $out['risk_tp'] / $actualRisk, 1) : null;
    $out['risk_precision'] = $flagged ? round(100 * $out['risk_tp'] / $flagged, 1) : null;
    return $out;
}

/* ---------------- Program length ---------------- */

const PROGRAM_MIN_MONTHS = 6;
const PROGRAM_MAX_MONTHS = 48;

/** "6 months", "1 year", "2 years 6 months", "4 years" */
function program_duration_label(int $months): string
{
    $months = max(1, $months);
    if ($months < 12) {
        return $months . ' ' . ($months === 1 ? 'month' : 'months');
    }
    $y = intdiv($months, 12);
    $m = $months % 12;
    return $y . ' ' . ($y === 1 ? 'year' : 'years') . ($m ? ' ' . $m . ' ' . ($m === 1 ? 'month' : 'months') : '');
}

/** How many year levels a program of this length has (6 months -> 1, 2.5 years -> 3, 4 years -> 4). */
function program_year_count(int $months): int
{
    return max(1, (int) ceil($months / 12));
}

/** Keeps a year level inside what the chosen program actually has. */
function clamp_year_level(PDO $pdo, ?int $programId, int $year): int
{
    $max = 5;
    if ($programId) {
        $stmt = $pdo->prepare("SELECT duration_months FROM programs WHERE id = ?");
        $stmt->execute([$programId]);
        $months = $stmt->fetchColumn();
        if ($months !== false) {
            $max = program_year_count((int) $months);
        }
    }
    return max(1, min($max, $year));
}

function program_name(PDO $pdo, ?int $programId): string
{
    if (!$programId) {
        return '—';
    }
    $stmt = $pdo->prepare("SELECT code, name FROM programs WHERE id = ?");
    $stmt->execute([$programId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['code'] . ' — ' . $row['name'] : '—';
}

/** Announcements relevant to this user: whole-school, their program, or their section. */
function announcements_for_user(PDO $pdo, array $user, int $limit = 5): array
{
    $sectionId = null;
    if ($user['role'] === 'student') {
        $stmt = $pdo->prepare("SELECT section_id FROM student_sections WHERE student_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $sectionId = $stmt->fetch()['section_id'] ?? null;
    }
    $programId = $user['program_id'] ?? null;

    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS author
        FROM announcements a
        LEFT JOIN users u ON u.id = a.posted_by
        WHERE a.audience = 'all'
           OR (a.audience = 'program' AND a.program_id = ?)
           OR (a.audience = 'section' AND a.section_id = ?)
        ORDER BY a.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $programId, $programId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(2, $sectionId, $sectionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials ?: '?';
}

/** Inline SVG icon (24px grid, 1.75 stroke, inherits currentColor). Replaces emoji so icons
 *  match the text color, scale with the font and look the same on every OS. */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-6h4v6"/>',
        'book'     => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 5.5V21"/>',
        'trend'    => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'wallet'   => '<path d="M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><path d="M16 14h2"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
        'logout'   => '<path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5"/><path d="M15 8l4 4-4 4M19 12H9"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'chart'    => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
        'cloud'    => '<path d="M7 18a4 4 0 0 1-.5-7.97A5.5 5.5 0 0 1 17 8.5a4.75 4.75 0 0 1 0 9.5z"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><path d="M16 4.7a3.5 3.5 0 0 1 0 6.6M18 14.8c2.2.6 3.5 2.3 3.5 5.2"/>',
        'upload'   => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 20h16"/>',
        'cap'      => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11.5V17c0 1.5 2.7 3 6 3s6-1.5 6-3v-5.5"/>',
        'building' => '<path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6M3 21h18"/>',
        'file'     => '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'megaphone'=> '<path d="M3 10v4h3l8 4V6L6 10z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
        'folder'   => '<path d="M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/>',
        'flag'     => '<path d="M5 21V4"/><path d="M5 4h12l-2 4 2 4H5"/>',
        'bell'     => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'chevron'  => '<path d="M6 9l6 6 6-6"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'shield'   => '<path d="M12 3l7.5 3v6c0 4.5-3.2 8-7.5 9-4.3-1-7.5-4.5-7.5-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8"/>',
        'camera'   => '<path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
        'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 5 .3c0 1.7-2.5 2-2.5 3.7"/><path d="M12 17h.01"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'inbox'    => '<path d="M3 13l2.5-7.5A1 1 0 0 1 6.45 5h11.1a1 1 0 0 1 .95.5L21 13"/><path d="M3 13v6a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-6h-5l-1.5 2.5h-7L8 13z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Time-of-day greeting for personalized headers, e.g. "Good morning". */
function daypart_greeting(): string
{
    $h = (int) date('G');
    if ($h < 12) return 'Good morning';
    if ($h < 18) return 'Good afternoon';
    return 'Good evening';
}

function avatar_url(array $user): ?string
{
    if (!empty($user['avatar_path']) && file_exists(BASE_PATH . '/uploads/avatars/' . $user['avatar_path'])) {
        return BASE_URL . '/uploads/avatars/' . $user['avatar_path'];
    }
    return null;
}

/** Cache invalidation: static asset URL with a version query string based on
 *  the file's last-modified time, so the long-lived Cache-Control header in
 *  .htaccess is safe — browsers/CDNs cache the file for a year, but editing
 *  it changes the URL, so visitors always get the new version immediately. */
function asset_url(string $path): string
{
    $full = BASE_PATH . $path;
    $v = file_exists($full) ? filemtime($full) : time();
    return BASE_URL . $path . '?v=' . $v;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/* ---------------- Pagination ---------------- */

function paginate_params(int $totalItems, int $perPage = 10): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    return ['page' => $page, 'perPage' => $perPage, 'totalPages' => $totalPages, 'offset' => $offset, 'total' => $totalItems];
}

/** Renders a page-number pagination bar, preserving any extra query params (e.g. filters/sort). */
function render_pagination(int $page, int $totalPages, array $extraParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }
    $buildUrl = function ($p) use ($extraParams) {
        $qs = $extraParams;
        $qs['page'] = $p;
        return '?' . http_build_query($qs);
    };
    $html = '<div class="pagination"><div class="pg-info">Page ' . $page . ' of ' . $totalPages . '</div><div class="pg-links">';
    $html .= '<a class="pg-btn ' . ($page <= 1 ? 'disabled' : '') . '" href="' . e($buildUrl(max(1, $page - 1))) . '">‹ Prev</a>';
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<a class="pg-btn ' . ($i === $page ? 'active' : '') . '" href="' . e($buildUrl($i)) . '">' . $i . '</a>';
    }
    $html .= '<a class="pg-btn ' . ($page >= $totalPages ? 'disabled' : '') . '" href="' . e($buildUrl(min($totalPages, $page + 1))) . '">Next ›</a>';
    $html .= '</div></div>';
    return $html;
}

/** Renders a sortable <th>: current sort state is preserved in the query string. */
function sort_header(string $label, string $field, string $currentSort, string $currentDir, array $extraParams = []): string
{
    $newDir = ($currentSort === $field && $currentDir === 'asc') ? 'desc' : 'asc';
    $qs = $extraParams;
    $qs['sort'] = $field;
    $qs['dir'] = $newDir;
    $arrow = '';
    if ($currentSort === $field) {
        $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
    }
    return '<a href="?' . e(http_build_query($qs)) . '" style="color:inherit;">' . e($label) . $arrow . '</a>';
}

function render_alert(string $type, string $text): string
{
    $type = $type === 'success' ? 'success' : 'error';
    $icon = $type === 'success' ? '✓' : '!';
    return '<div class="alert alert-' . $type . '" data-flash="' . $type . '"><span class="alert-ic">' . $icon . '</span><span>' . e($text) . '</span></div>';
}

function render_flash(?array $flash): string
{
    if (!$flash) {
        return '';
    }
    return render_alert($flash['type'] ?? 'error', $flash['message'] ?? '');
}

/**
 * Prevents CSV formula injection: if a cell starts with =, +, -, @, tab, or CR,
 * spreadsheet apps may interpret it as a formula when the file is opened.
 * Prefixing a single quote forces it to be treated as plain text.
 */
function csv_safe($value): string
{
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}
