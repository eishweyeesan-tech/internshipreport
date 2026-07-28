<?php
require_once __DIR__ . '/../config/database.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'supervisor'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$ann_id = (int) ($_GET['id'] ?? 0);

if ($ann_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid announcement ID']);
    exit;
}

try {
    $access = $pdo->prepare(
        'SELECT 1 FROM notifications WHERE user_id = ? AND announcement_id = ? LIMIT 1'
    );
    $access->execute([$user_id, $ann_id]);
    if (!$access->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Announcement not found']);
        exit;
    }

    $q = $pdo->prepare("
        SELECT a.id, a.title, a.body, a.created_at,
               u.username AS sender_name
        FROM announcements a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.id = ? AND a.is_active = 1
    ");
    $q->execute([$ann_id]);
    $ann = $q->fetch(PDO::FETCH_ASSOC);

    if (!$ann) {
        http_response_code(404);
        echo json_encode(['error' => 'Announcement not found']);
        exit;
    }

    echo json_encode([
        'announcement' => $ann,
        'attachments' => [],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
