<?php
require_once __DIR__ . '/config.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
}

$referer = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . portal_path_for($user['role']));
header('Location: ' . $referer);
exit;
