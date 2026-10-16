<?php
require_once __DIR__ . '/../config.php';
$user = require_role('teacher');
$role = 'teacher';
$active = 'settings';
require __DIR__ . '/../includes/account_page.php';
