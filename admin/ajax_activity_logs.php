<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['html' => '', 'has_more' => false]);
    exit;
}

$pdo = db();
$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT al.*, u.full_name FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->query("SELECT COUNT(*) c FROM activity_logs");
$total = (int) $totalStmt->fetch()['c'];
$hasMore = ($offset + $perPage) < $total;

$html = '';
foreach ($logs as $l) {
    $html .= '<tr>'
        . '<td>' . e(date('M j, Y g:i A', strtotime($l['created_at']))) . '</td>'
        . '<td>' . e($l['full_name'] ?? 'Unknown') . '</td>'
        . '<td>' . e($l['action']) . '</td>'
        . '<td>' . e($l['description']) . '</td>'
        . '</tr>';
}

echo json_encode(['html' => $html, 'has_more' => $hasMore]);
