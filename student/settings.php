<?php
require_once __DIR__ . '/../config.php';
$user = require_role('student');
$role = 'student';
$active = 'settings';
require __DIR__ . '/../includes/account_page.php';
