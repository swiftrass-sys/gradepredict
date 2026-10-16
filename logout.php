<?php
require_once __DIR__ . '/config.php';
if (!empty($_SESSION['user_id'])) {
    log_activity((int) $_SESSION['user_id'], 'Logout', 'User signed out');
    forget_all_tokens_for(db(), (int) $_SESSION['user_id']);
}
clear_remember_cookie();
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit;
