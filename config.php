<?php
/**
 * config.php
 * Core bootstrap for GradePredict AI.
 * Uses a real MySQL database (e.g. XAMPP's MySQL/MariaDB on localhost) via PDO.
 *
 * ── First-time setup ──────────────────────────────────────────────────────
 * 1. Make sure MySQL is running (e.g. start it from the XAMPP Control Panel).
 * 2. Edit the DB_* constants just below if your MySQL user/password differ
 *    from the XAMPP defaults (root / no password).
 * 3. That's it — just load the site. On first request this file connects,
 *    creates the `gradepredict_ai` database if it doesn't exist yet, creates
 *    all tables, and seeds demo data automatically.
 * A ready-made schema.sql (with the same DDL) is also included in this
 * project if you'd rather import it by hand via phpMyAdmin.
 */

/**
 * Debug mode: set to true temporarily if you need to see PHP errors directly in
 * the browser while troubleshooting. Keep this false on a real/live deployment —
 * errors are still recorded either way, in data/php-error.log.
 */
define('APP_DEBUG', false);

// See seed_data()'s 'schema_version' setting below — bump this any time
// install_schema() changes so it's possible to tell installs on an older
// structure apart from a fresh one.
define('SCHEMA_VERSION', '1.3');

// ---- Database connection settings — edit these to match your MySQL setup ----
// Secrets management: if DB_HOST/DB_USER/DB_PASS/DB_NAME are set as real
// server environment variables (e.g. via Apache SetEnv, a systemd unit, or
// your host's control panel), those take priority over the hardcoded
// defaults below — so production credentials never have to live in this
// file or in version control. Local/XAMPP setups with no env vars set just
// keep using the defaults as before.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'gradepredict_ai');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''); // XAMPP's default MySQL root password is empty

// Connection pooling: PHP opens one connection per request (cheap, and
// already reused for the whole request via the static cache in db() below)
// rather than a long-lived pool. On hosts with many short-lived requests,
// enabling PDO's persistent-connection flag lets PHP hand back a warm
// connection from a small internal pool instead of reconnecting every time.
// Off by default — persistent connections can leak session/lock state if a
// request dies mid-transaction — flip it on only if your host recommends it.
define('DB_PERSISTENT', filter_var(getenv('DB_PERSISTENT') ?: 'false', FILTER_VALIDATE_BOOLEAN));

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookies a bit before starting the session.
    ini_set('session.cookie_httponly', '1');
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

define('BASE_PATH', __DIR__);
define('DATA_DIR', __DIR__ . '/data');           // still used for logs, backups, and local file storage
define('UPLOADS_DIR', __DIR__ . '/uploads/avatars');

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0777, true);
}
ini_set('error_log', DATA_DIR . '/php-error.log');

// A friendly page instead of a blank screen or a leaked stack trace if something goes wrong.
set_exception_handler(function (Throwable $e) {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (APP_DEBUG) {
        echo '<pre style="padding:20px;color:#b91c1c;">' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
        return;
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Something went wrong</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:80px 20px;color:#334155;">'
        . '<h2>Something went wrong on our end.</h2>'
        . '<p>The issue has been logged. Please try again, or contact the school administrator if it continues.</p>'
        . '<a href="' . (defined('BASE_URL') ? BASE_URL : '') . '/index.php" style="color:#2563eb;">Back to home</a>'
        . '</body></html>';
});

// Baseline security headers on every request.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; connect-src 'self' https://api.open-meteo.com; frame-ancestors 'self';");
    // Encryption in transit: once the site is actually served over HTTPS, tell
    // browsers to always use HTTPS for the next year (and on subdomains) so a
    // stray http:// link/bookmark can never silently downgrade the connection.
    // Harmless to send over plain HTTP too — browsers ignore it unless the
    // response that carries it was already served over TLS.
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Figure out the base URL (works whether the app sits at the domain root or in a subfolder)
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$scriptDir = preg_replace('#/(student|admin|teacher|parent)$#', '', $scriptDir);
define('BASE_URL', rtrim($scriptDir, '/'));

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!is_dir(UPLOADS_DIR)) {
        @mkdir(UPLOADS_DIR, 0777, true);
    }

    $dsnBase = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => DB_PERSISTENT,
    ];

    // Connect without selecting a database first, so we can create it if it's missing.
    $bootstrap = new PDO($dsnBase, DB_USER, DB_PASS, $options);
    $bootstrap->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO($dsnBase . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $options);

    $tableExists = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$tableExists) {
        install_schema($pdo);
        seed_data($pdo);
    } else {
        migrate_account_approval($pdo);
        migrate_program_duration_and_prefs($pdo);
    }

    return $pdo;
}

/**
 * Upgrades a database created before account approval existed. Everyone who
 * already has an account stays approved (nobody gets locked out), and teachers
 * start with permission to approve sign-ups. Does nothing once the columns exist.
 */
function migrate_account_approval(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'account_status'")->fetch()) {
        return;
    }
    $pdo->exec("ALTER TABLE users
        ADD COLUMN account_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
        ADD COLUMN can_approve TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN approved_by INT NULL,
        ADD COLUMN approved_at DATETIME NULL,
        ADD INDEX idx_users_status (account_status, role)");
    $pdo->exec("UPDATE users SET can_approve = 1 WHERE role = 'teacher'");
}

/**
 * Adds a length (in months) to each program, and per-user preference columns.
 * Existing programs default to 4 years (48 months); the admin can change them on the Programs page.
 */
function migrate_program_duration_and_prefs(PDO $pdo): void
{
    if (!$pdo->query("SHOW COLUMNS FROM programs LIKE 'duration_months'")->fetch()) {
        $pdo->exec("ALTER TABLE programs ADD COLUMN duration_months INT NOT NULL DEFAULT 48");
        $pdo->exec("UPDATE programs SET duration_months = 6 WHERE code = 'CG'");
    }
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_email_notifications'")->fetch()) {
        $pdo->exec("ALTER TABLE users
            ADD COLUMN pref_email_notifications TINYINT(1) NOT NULL DEFAULT 1,
            ADD COLUMN pref_study_reminders TINYINT(1) NOT NULL DEFAULT 1,
            ADD COLUMN pref_language VARCHAR(10) NOT NULL DEFAULT 'en'");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'final_grades'")->fetch()) {
        $pdo->exec("CREATE TABLE final_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            final_grade DECIMAL(5,2) NOT NULL,
            recorded_by INT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_final_grade (student_id, subject_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    // Record that this database is now on the current schema.
    $upd = $pdo->prepare("UPDATE settings SET value = ? WHERE setting_key = 'schema_version'");
    $upd->execute([SCHEMA_VERSION]);
}

function install_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('student','teacher','parent','admin') NOT NULL DEFAULT 'student',
            user_type VARCHAR(50) NOT NULL DEFAULT 'Student',
            student_no VARCHAR(50),
            avatar_path VARCHAR(255),
            failed_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            program_id INT NULL,
            major_id INT NULL,
            year_level INT NULL,
            adviser_id INT NULL,
            account_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
            can_approve TINYINT(1) NOT NULL DEFAULT 0,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            pref_email_notifications TINYINT(1) NOT NULL DEFAULT 1,
            pref_study_reminders TINYINT(1) NOT NULL DEFAULT 1,
            pref_language VARCHAR(10) NOT NULL DEFAULT 'en',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_users_status (account_status, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE programs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            duration_months INT NOT NULL DEFAULT 48
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE majors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_id INT NOT NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(150) NOT NULL,
            FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_major (program_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            icon VARCHAR(10) NOT NULL DEFAULT '📘',
            units INT NOT NULL DEFAULT 3
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE subject_weights (
            subject_id INT PRIMARY KEY,
            w_past DECIMAL(4,2) NOT NULL DEFAULT 0.40,
            w_attendance DECIMAL(4,2) NOT NULL DEFAULT 0.25,
            w_assignments DECIMAL(4,2) NOT NULL DEFAULT 0.15,
            w_quizzes DECIMAL(4,2) NOT NULL DEFAULT 0.10,
            w_participation DECIMAL(4,2) NOT NULL DEFAULT 0.10,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            adviser_id INT NULL,
            program_id INT NULL,
            major_id INT NULL,
            year_level INT NULL,
            FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
            FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE curriculum_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_id INT NOT NULL,
            major_id INT NULL,
            year_level INT NOT NULL,
            semester VARCHAR(30) NOT NULL,
            subject_id INT NOT NULL,
            FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
            FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_curriculum (program_id, major_id, year_level, semester, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE parent_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NOT NULL,
            student_id INT NOT NULL,
            FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_parent_link (parent_id, student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE student_sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            section_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_student_year (student_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE teacher_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            subject_id INT NOT NULL,
            section_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_teacher_assignment (teacher_id, subject_id, section_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE student_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_enrollment (student_id, subject_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE grade_inputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            past_grades DECIMAL(5,2) NOT NULL DEFAULT 0,
            attendance DECIMAL(5,2) NOT NULL DEFAULT 0,
            assignments DECIMAL(5,2) NOT NULL DEFAULT 0,
            quizzes DECIMAL(5,2) NOT NULL DEFAULT 0,
            participation DECIMAL(5,2) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_grade_input (student_id, subject_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE grade_input_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            past_grades DECIMAL(5,2) NOT NULL,
            attendance DECIMAL(5,2) NOT NULL,
            assignments DECIMAL(5,2) NOT NULL,
            quizzes DECIMAL(5,2) NOT NULL,
            participation DECIMAL(5,2) NOT NULL,
            changed_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_history_lookup (student_id, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE predictions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL DEFAULT 0,
            school_year VARCHAR(20) NOT NULL,
            predicted_grade DECIMAL(5,2) NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_pred_lookup (student_id, subject_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255),
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_notif_user_unread (user_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector VARCHAR(64) NOT NULL UNIQUE,
            validator_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE final_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            final_grade DECIMAL(5,2) NOT NULL,
            recorded_by INT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_final_grade (student_id, subject_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE grade_flags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            resolved_by INT NULL,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE fee_structures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_id INT NOT NULL,
            year_level INT NOT NULL,
            semester VARCHAR(30) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_fee (program_id, year_level, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            semester VARCHAR(30) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            or_number VARCHAR(50),
            notes VARCHAR(255),
            recorded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE advising_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            adviser_id INT NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            audience VARCHAR(20) NOT NULL DEFAULT 'all',
            program_id INT NULL,
            section_id INT NULL,
            posted_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
            FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            value VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function seed_data(PDO $pdo): void
{
    $pdo->exec("INSERT INTO settings (setting_key, value) VALUES ('school_year', '2025 - 2026')");
    $pdo->exec("INSERT INTO settings (setting_key, value) VALUES ('current_semester', '1st Semester')");
    // Schema versioning: records which structure this database was created with.
    // Bump SCHEMA_VERSION below whenever install_schema()'s table definitions
    // change, so a future request can tell "installed, but on an old schema"
    // apart from "not installed yet" instead of just checking one table's
    // existence — the hook a real migration step would key off of.
    $pdo->exec("INSERT INTO settings (setting_key, value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')");
    $year = '2025 - 2026';
    $sem1 = '1st Semester';

    // ---- Programs ----
    $programs = [
        ['BSIT', 'Bachelor of Science in Information Technology', 48],
        ['BSTM', 'Bachelor of Science in Tourism Management', 48],
        ['BSBA', 'Bachelor of Science in Business Administration', 48],
        ['BSED', 'Bachelor of Secondary Education', 48],
        ['CG', 'Caregiving (NC II)', 6],
        ['BSM', 'Bachelor of Science in Midwifery', 48],
    ];
    $progStmt = $pdo->prepare("INSERT INTO programs (code, name, duration_months) VALUES (?, ?, ?)");
    $progIds = [];
    foreach ($programs as $p) {
        $progStmt->execute($p);
        $progIds[$p[0]] = (int) $pdo->lastInsertId();
    }

    // ---- BSED Majors ----
    $majors = [
        ['ELEM', 'Elementary Education'],
        ['MATH', 'Mathematics'],
        ['FIL', 'Filipino'],
        ['ENG', 'English'],
    ];
    $majorStmt = $pdo->prepare("INSERT INTO majors (program_id, code, name) VALUES (?, ?, ?)");
    $majorIds = [];
    foreach ($majors as $m) {
        $majorStmt->execute([$progIds['BSED'], $m[0], $m[1]]);
        $majorIds[$m[0]] = (int) $pdo->lastInsertId();
    }

    // ---- Subjects (General Education + program-specific), with realistic unit counts ----
    $subjects = [
        ['Purposive Communication', '💬', 3],
        ['Mathematics in the Modern World', '📐', 3],
        ['Understanding the Self', '🧠', 3],
        ['Filipino sa Iba\'t Ibang Disiplina', '🔤', 3],
        ['PATHFit 1', '🏃', 2],
        ['NSTP 1', '🇵🇭', 3],
        ['Introduction to Computing', '💻', 3],
        ['Computer Programming 1', '👨‍💻', 3],
        ['Data Structures and Algorithms', '🗂️', 3],
        ['Tourism and Hospitality Management', '🏨', 3],
        ['Principles of Marketing', '📣', 3],
        ['Financial Accounting', '💰', 3],
        ['Facilitating Learner-Centered Teaching', '🍎', 3],
        ['Teaching Math in the Elementary Grades', '➗', 3],
        ['Caregiving Theories and Concepts', '🩺', 3],
        ['Foundations of Midwifery', '👶', 3],
    ];
    $stmt = $pdo->prepare("INSERT INTO subjects (name, icon, units) VALUES (?, ?, ?)");
    $weightStmt = $pdo->prepare("INSERT INTO subject_weights (subject_id) VALUES (?)");
    $subjIds = [];
    foreach ($subjects as $s) {
        $stmt->execute($s);
        $id = (int) $pdo->lastInsertId();
        $subjIds[$s[0]] = $id;
        $weightStmt->execute([$id]);
    }

    // ---- Curriculum: a handful of subjects per program/year/semester ----
    $curriculumStmt = $pdo->prepare("INSERT INTO curriculum_subjects (program_id, major_id, year_level, semester, subject_id) VALUES (?, ?, ?, ?, ?)");
    $curriculum = [
        [$progIds['BSIT'], null, 1, $sem1, ['Purposive Communication', 'Mathematics in the Modern World', 'Understanding the Self', 'PATHFit 1', 'NSTP 1', 'Introduction to Computing']],
        [$progIds['BSIT'], null, 2, $sem1, ['Computer Programming 1', 'Data Structures and Algorithms', 'Filipino sa Iba\'t Ibang Disiplina']],
        [$progIds['BSTM'], null, 1, $sem1, ['Purposive Communication', 'Understanding the Self', 'Tourism and Hospitality Management', 'PATHFit 1']],
        [$progIds['BSBA'], null, 1, $sem1, ['Purposive Communication', 'Principles of Marketing', 'Financial Accounting', 'PATHFit 1']],
        [$progIds['BSED'], $majorIds['MATH'], 1, $sem1, ['Purposive Communication', 'Mathematics in the Modern World', 'Facilitating Learner-Centered Teaching', 'Teaching Math in the Elementary Grades']],
        [$progIds['CG'], null, 1, $sem1, ['Caregiving Theories and Concepts', 'Understanding the Self', 'PATHFit 1']],
        [$progIds['BSM'], null, 1, $sem1, ['Foundations of Midwifery', 'Understanding the Self', 'PATHFit 1']],
    ];
    foreach ($curriculum as $row) {
        [$pid, $mid, $yr, $sem, $names] = $row;
        foreach ($names as $name) {
            $curriculumStmt->execute([$pid, $mid, $yr, $sem, $subjIds[$name]]);
        }
    }

    // ---- Fee structures ----
    $feeStmt = $pdo->prepare("INSERT INTO fee_structures (program_id, year_level, semester, amount) VALUES (?, ?, ?, ?)");
    foreach ($progIds as $code => $pid) {
        $feeStmt->execute([$pid, 1, $sem1, $code === 'BSM' ? 22000 : 18000]);
        $feeStmt->execute([$pid, 2, $sem1, $code === 'BSM' ? 22000 : 18000]);
    }

    // ---- Sections ----
    $pdo->prepare("INSERT INTO sections (name, program_id, year_level) VALUES (?, ?, ?)")->execute(['BSIT 2A', $progIds['BSIT'], 2]);
    $bsit2a = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO sections (name, program_id, year_level) VALUES (?, ?, ?)")->execute(['BSTM 1A', $progIds['BSTM'], 1]);
    $bstm1a = (int) $pdo->lastInsertId();

    // ---- Admin account ----
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type) VALUES (?, ?, ?, 'admin', 'Administrator')")
        ->execute(['John Doe', 'admin@gradepredict.ai', password_hash('admin123', PASSWORD_DEFAULT)]);

    // ---- Demo teacher/adviser account ----
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, can_approve) VALUES (?, ?, ?, 'teacher', 'Teacher', 1)")
        ->execute(['Maria Cruz', 'teacher@gradepredict.ai', password_hash('teacher123', PASSWORD_DEFAULT)]);
    $teacherId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, section_id, school_year) VALUES (?, ?, ?, ?)")->execute([$teacherId, $subjIds['Computer Programming 1'], $bsit2a, $year]);
    $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, section_id, school_year) VALUES (?, ?, ?, ?)")->execute([$teacherId, $subjIds['Data Structures and Algorithms'], $bsit2a, $year]);

    // ---- Demo student account: Juan, BSIT 2nd Year (the "clean"/happy-path demo student) ----
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, year_level, adviser_id) VALUES (?, ?, ?, 'student', 'Student', ?, ?, ?, ?)")
        ->execute(['Juan Dela Cruz', 'juan@student.gradepredict.ai', password_hash('student123', PASSWORD_DEFAULT), 'GP-2025-0001', $progIds['BSIT'], 2, $teacherId]);

    $studentId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)")->execute([$studentId, $bsit2a, $year]);
    $pdo->prepare("INSERT INTO advising_notes (student_id, adviser_id, note) VALUES (?, ?, ?)")
        ->execute([$studentId, $teacherId, 'Good standing at the start of the semester. Encouraged to keep attendance consistent.']);

    // ---- Demo parent account, linked to Juan ----
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type) VALUES (?, ?, ?, 'parent', 'Parent')")
        ->execute(['Roberto Dela Cruz', 'parent@gradepredict.ai', password_hash('parent123', PASSWORD_DEFAULT)]);
    $parentId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO parent_links (parent_id, student_id) VALUES (?, ?)")->execute([$parentId, $studentId]);

    // ---- Enroll Juan in his BSIT Year 2 Sem 1 curriculum, with sample grade inputs ----
    $sample = [
        $subjIds['Computer Programming 1'] => [90, 95, 85, 82, 88],
        $subjIds['Data Structures and Algorithms'] => [83, 90, 78, 80, 82],
        $subjIds['Filipino sa Iba\'t Ibang Disiplina'] => [86, 92, 82, 80, 85],
    ];

    $enroll = $pdo->prepare("INSERT INTO student_subjects (student_id, subject_id, school_year) VALUES (?, ?, ?)");
    $input  = $pdo->prepare("INSERT INTO grade_inputs (student_id, subject_id, school_year, past_grades, attendance, assignments, quizzes, participation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($sample as $subjectId => $vals) {
        $enroll->execute([$studentId, $subjectId, $year]);
        $input->execute(array_merge([$studentId, $subjectId, $year], $vals));
        $predicted = calculate_prediction($pdo, $subjectId, $vals[0], $vals[1], $vals[2], $vals[3], $vals[4]);
        save_prediction($pdo, $studentId, $subjectId, $year, $predicted);
        log_grade_history($pdo, $studentId, $subjectId, $year, $vals, null);
    }

    // ---- Sample payment (partial) so the Cashier balance view has something to show ----
    $pdo->prepare("INSERT INTO payments (student_id, school_year, semester, amount, or_number, recorded_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$studentId, $year, $sem1, 9000, 'OR-2025-00123', null]);

    // ---- Sample announcement ----
    $pdo->prepare("INSERT INTO announcements (title, body, audience, posted_by) VALUES (?, ?, 'all', ?)")
        ->execute(['Welcome to A.Y. 2025-2026!', 'Classes for the 1st Semester begin this week. Please make sure your enrollment and initial payment are settled with the Cashier.', null]);

    // Backfill a short prediction history so the "Predicted Grade Over Time" chart has a trend
    $history = [72.1, 75.4, 78.0, 80.6, 82.9, null];
    $daysAgo = [49, 35, 21, 14, 7, 0];
    $stmtAvg = $pdo->query("SELECT AVG(predicted_grade) a FROM predictions");
    $overallNow = (float) $stmtAvg->fetch()['a'];

    for ($i = 0; $i < count($history); $i++) {
        $val = $history[$i] ?? $overallNow;
        $date = date('Y-m-d H:i:s', strtotime("-{$daysAgo[$i]} days"));
        $pdo->prepare("INSERT INTO predictions (student_id, subject_id, school_year, predicted_grade, status, created_at) VALUES (?, 0, ?, ?, ?, ?)")
            ->execute([$studentId, $year, $val, status_label($val), $date]);
    }

    // ---- "Messy" demo data on purpose: an at-risk student, an unpaid-balance hold, and an open grade flag ----
    // This gives you real edge cases to show during a defense demo, not just the happy path.
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, year_level, adviser_id) VALUES (?, ?, ?, 'student', 'Student', ?, ?, ?, ?)")
        ->execute(['Kevin Bautista', 'kevin@student.gradepredict.ai', password_hash('student123', PASSWORD_DEFAULT), 'GP-2025-0099', $progIds['BSIT'], 2, $teacherId]);
    $atRiskId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)")->execute([$atRiskId, $bsit2a, $year]);
    // Deliberately low, inconsistent scores -> High risk / Poor status
    $riskyVals = [
        $subjIds['Computer Programming 1'] => [58, 62, 55, 50, 60],
        $subjIds['Data Structures and Algorithms'] => [52, 58, 48, 45, 55],
    ];
    foreach ($riskyVals as $subjectId => $vals) {
        $enroll->execute([$atRiskId, $subjectId, $year]);
        $input->execute(array_merge([$atRiskId, $subjectId, $year], $vals));
        $predicted = calculate_prediction($pdo, $subjectId, $vals[0], $vals[1], $vals[2], $vals[3], $vals[4]);
        save_prediction($pdo, $atRiskId, $subjectId, $year, $predicted);
        log_grade_history($pdo, $atRiskId, $subjectId, $year, $vals, null);
    }
    // No payment recorded at all -> triggers the enrollment hold
    // An open grade flag for the admin/adviser to demo resolving
    $pdo->prepare("INSERT INTO grade_flags (student_id, subject_id, message) VALUES (?, ?, ?)")
        ->execute([$atRiskId, $subjIds['Data Structures and Algorithms'], 'I think my attendance was recorded wrong for the past two weeks — I was present but it shows lower than expected.']);
    $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")
        ->execute([$atRiskId, 'Your predicted average is currently below passing. Please see your adviser.', '/student/predictions.php']);

    // A handful of extra demo students (across different programs) so admin views have data to show
    $demoStudents = [
        ['Maria Santos', 'maria@student.gradepredict.ai', $progIds['BSTM'], 1, $bstm1a],
        ['Pedro Reyes', 'pedro@student.gradepredict.ai', $progIds['BSBA'], 1, null],
        ['Ana Lopez', 'ana@student.gradepredict.ai', $progIds['BSED'], 1, null],
        ['Luis Garcia', 'luis@student.gradepredict.ai', $progIds['BSIT'], 2, $bsit2a],
    ];
    foreach ($demoStudents as $idx => $d) {
        [$name, $email, $pid, $yl, $sectionId] = $d;
        $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, user_type, student_no, program_id, year_level) VALUES (?, ?, ?, 'student', 'Student', ?, ?, ?)")
            ->execute([$name, $email, password_hash('student123', PASSWORD_DEFAULT), 'GP-2025-000' . ($idx + 2), $pid, $yl]);
        $sid = (int) $pdo->lastInsertId();
        if ($sectionId) {
            $pdo->prepare("INSERT INTO student_sections (student_id, section_id, school_year) VALUES (?, ?, ?)")->execute([$sid, $sectionId, $year]);
        }

        $curStmt = $pdo->prepare("SELECT subject_id FROM curriculum_subjects WHERE program_id = ? AND year_level = ? AND semester = ?");
        $curStmt->execute([$pid, $yl, $sem1]);
        foreach ($curStmt->fetchAll() as $c) {
            $subId = (int) $c['subject_id'];
            $base = rand(55, 95);
            $vals = [$base, min(100, $base + rand(-5, 8)), $base + rand(-8, 5), $base + rand(-8, 5), $base + rand(-5, 8)];
            $vals = array_map(fn($v) => max(0, min(100, $v)), $vals);
            $enroll->execute([$sid, $subId, $year]);
            $input->execute(array_merge([$sid, $subId, $year], $vals));
            $pred = calculate_prediction($pdo, $subId, ...$vals);
            save_prediction($pdo, $sid, $subId, $year, $pred);
            log_grade_history($pdo, $sid, $subId, $year, $vals, null);
        }
    }

    $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)")
        ->execute([$studentId, 'Prediction Updated', 'Your predictions were updated']);
    $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)")
        ->execute([$studentId, 'New Grade Added', 'Data Structures and Algorithms - Quiz 3']);
    $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)")
        ->execute([$studentId, 'Data Input Added', 'Attendance - update']);

    $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")
        ->execute([$studentId, 'Your overall predicted average improved to ' . round($overallNow, 1) . '%.', '/student/predictions.php']);
    $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")
        ->execute([$studentId, 'Welcome to GradePredict AI! Fill in your Data Inputs to keep predictions accurate.', '/student/data-inputs.php']);
}

require_once BASE_PATH . '/includes/functions.php';
