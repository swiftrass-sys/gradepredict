<?php
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
$active = 'settings';
$pdo = db();
$message = flash_get();

/** Dumps every table as CREATE TABLE + INSERT statements — a portable backup with no shell/mysqldump dependency. */
function generate_sql_backup(PDO $pdo): string
{
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $sql = "-- GradePredict AI backup — " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $createRow['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
            $sql .= "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

/** Splits a .sql file into individual statements, respecting quoted strings (so semicolons inside text don't break it). */
function split_sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $current .= $char;
        if ($inString) {
            if ($char === $stringChar) {
                if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                    $current .= $sql[$i + 1];
                    $i++;
                } else {
                    $inString = false;
                }
            }
        } elseif ($char === "'" || $char === '"') {
            $inString = true;
            $stringChar = $char;
        } elseif ($char === ';') {
            $trimmed = trim($current, "; \t\n\r");
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
        }
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

if (isset($_GET['backup'])) {
    // Read-only export action — safe as a GET request (no state change).
    $sql = generate_sql_backup($pdo);
    $filename = 'gradepredict_backup_' . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['update_year'])) {
        $year = trim($_POST['school_year'] ?? '');
        if ($year !== '') {
            $pdo->prepare("INSERT INTO settings (setting_key, value) VALUES ('school_year', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
                ->execute([$year]);
            flash_set('success', 'School year updated.');
        }
    } elseif (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!password_verify($current, $user['password_hash'])) {
            flash_set('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            flash_set('error', 'New passwords do not match.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            forget_all_tokens_for($pdo, (int) $user['id']);
            flash_set('success', 'Password updated.');
        }
    } elseif (isset($_POST['restore_backup']) && isset($_FILES['backup_file'])) {
        $file = $_FILES['backup_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Upload failed. Please try again.');
        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'sql') {
            flash_set('error', 'Please upload a .sql backup file.');
        } else {
            $content = file_get_contents($file['tmp_name']);
            if ($content === false || stripos($content, 'CREATE TABLE') === false) {
                flash_set('error', 'That file does not look like a valid GradePredict AI backup.');
            } else {
                // Keep a safety copy of the current database before touching anything.
                $safetyFile = DATA_DIR . '/pre-restore-' . date('Y-m-d_His') . '.sql';
                @file_put_contents($safetyFile, generate_sql_backup($pdo));

                $statements = split_sql_statements($content);
                $errors = 0;
                foreach ($statements as $stmt) {
                    try {
                        $pdo->exec($stmt);
                    } catch (Throwable $e) {
                        $errors++;
                        error_log('Restore statement failed: ' . $e->getMessage());
                    }
                }

                log_activity((int) $user['id'], 'Database Restored', "Restored from an uploaded backup ($errors statement error(s))");
                if ($errors === 0) {
                    flash_set('success', 'Backup restored successfully. A safety copy of the previous data was saved in data/.');
                } else {
                    flash_set('error', "Restore finished with $errors statement error(s) — check data/php-error.log. A safety copy of your previous data was saved in data/ before this attempt.");
                }
                header('Location: settings.php');
                exit;
            }
        }
    }
    header('Location: settings.php');
    exit;
}

$year = current_school_year();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — GradePredict AI Admin</title>
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
            <div class="page-head"><div><h2>Settings</h2><p>System configuration for GradePredict AI.</p></div></div>
            <?= render_flash($message) ?>

            <div class="grid grid-2">
                <div class="card">
                    <h3>School Year</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Active School Year</label><input class="input" type="text" name="school_year" value="<?= e($year) ?>" required></div>
                        <button type="submit" name="update_year" value="1" class="btn btn-primary">Save</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Change Password</h3>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group"><label>Current Password</label><input class="input" type="password" name="current_password" required></div>
                        <div class="form-group"><label>New Password</label><input class="input" type="password" name="new_password" required minlength="6"></div>
                        <div class="form-group"><label>Confirm New Password</label><input class="input" type="password" name="confirm_password" required minlength="6"></div>
                        <button type="submit" name="change_password" value="1" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Appearance</h3>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div style="font-weight:600;font-size:14px;">Dark Mode</div>
                            <div style="color:var(--text-muted);font-size:12.5px;">Applies across the whole app on this device.</div>
                        </div>
                        <label class="theme-switch"><input type="checkbox" id="darkModeToggle"><span class="theme-slider"></span></label>
                    </div>
                </div>
                <div class="card">
                    <h3>Manage Sections &amp; Weights</h3>
                    <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:14px;">Class sections and per-subject prediction weights are managed on their own pages.</p>
                    <a href="sections.php" class="btn btn-outline btn-block" style="margin-bottom:10px;">Manage Sections</a>
                    <a href="subjects.php" class="btn btn-outline btn-block">Manage Subject Weights</a>
                </div>
                <div class="card">
                    <h3>Backup &amp; Restore</h3>
                    <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:14px;">Downloads a full SQL dump of the database (works on any MySQL host — no shell access needed). You can also import this file directly via phpMyAdmin if you prefer.</p>
                    <a href="?backup=1" class="btn btn-outline btn-block" style="margin-bottom:14px;" data-tooltip="Downloads a .sql file of the live database">⬇️ Download Backup (.sql)</a>
                    <form method="post" enctype="multipart/form-data" data-confirm="This replaces ALL current data with the uploaded backup. A safety copy of the current database will be saved automatically, but this should not be done lightly." data-confirm-label="Restore Backup">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label>Restore from Backup File (.sql)</label>
                            <input class="input" type="file" name="backup_file" accept=".sql" required>
                        </div>
                        <button type="submit" name="restore_backup" value="1" class="btn btn-danger btn-block">⬆️ Restore Backup</button>
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
