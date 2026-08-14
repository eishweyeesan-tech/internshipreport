<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$uid = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';
$db = $mysqli ?? $conn;

switch ($action) {
    case 'fetch':
        $count_r = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $count_r->bind_param("i", $uid);
        $count_r->execute();
        $res = $count_r->get_result();
        $row = $res ? $res->fetch_row() : null;
        $unread_count = (int) ($row[0] ?? 0);

        $notifs_r = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15');
        $notifs_r->bind_param("i", $uid);
        $notifs_r->execute();
        $res = $notifs_r->get_result();
        $notifications = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $notifications[] = [
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'type' => $row['type'],
                    'is_read' => (int) $row['is_read'],
                    'created_at' => $row['created_at'],
                    'announcement_id' => isset($row['announcement_id']) ? (int) $row['announcement_id'] : null,
                    'related_week' => isset($row['related_week']) ? (int) $row['related_week'] : null,
                ];
            }
        }

        echo json_encode([
            'unread_count' => $unread_count,
            'notifications' => $notifications,
        ]);
        break;

    case 'mark_read':
        $notif_id = (int) ($_POST['notification_id'] ?? 0);
        if ($notif_id > 0) {
            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->bind_param("ii", $notif_id, $uid);
            $stmt->execute();
        }
        $count_r = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $count_r->bind_param("i", $uid);
        $count_r->execute();
        $res = $count_r->get_result();
        $row = $res ? $res->fetch_row() : null;
        echo json_encode(['unread_count' => (int) ($row[0] ?? 0)]);
        break;

    case 'mark_all_read':
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        echo json_encode(['unread_count' => 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
