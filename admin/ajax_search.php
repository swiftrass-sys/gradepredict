<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');
$results = [];

if (mb_strlen($q) >= 2) {
    $pdo = db();
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role = 'student' AND account_status = 'approved' AND (full_name LIKE ? OR email LIKE ?) ORDER BY full_name LIMIT 6");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $results[] = [
            'type' => 'Students',
            'label' => $s['full_name'] . ' (' . $s['email'] . ')',
            'href' => BASE_URL . '/admin/students.php?id=' . $s['id'],
            'icon' => '🎓',
        ];
    }

    $stmt = $pdo->prepare("SELECT id, name, icon FROM subjects WHERE name LIKE ? ORDER BY name LIMIT 6");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $results[] = [
            'type' => 'Subjects',
            'label' => $s['name'],
            'href' => BASE_URL . '/admin/subjects.php',
            'icon' => $s['icon'],
        ];
    }
}

echo json_encode(['results' => $results]);
