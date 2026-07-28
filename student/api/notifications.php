<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = new mysqli('localhost', 'root', 'root', 'intern_report_db');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';

function notif_redirect_url($type, $related_week) {
    $base = 'student-dashboard.php';
    if (in_array($type, ['instructor_approved', 'instructor_rejected', 'supervisor_approved']) && $related_week) {
        return $base . '?week=' . (int)$related_week;
    }
    return $base;
}

switch ($action) {
    case 'fetch':
        $count_r = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$uid} AND is_read = 0");
        $unread_count = ($count_r && $count_r->num_rows > 0) ? (int)$count_r->fetch_row()[0] : 0;

        $notifs_r = $conn->query("SELECT * FROM notifications WHERE user_id = {$uid} ORDER BY created_at DESC LIMIT 15");
        $notifications = [];
        if ($notifs_r) {
            while ($row = $notifs_r->fetch_assoc()) {
                $notifications[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'type' => $row['type'],
                    'is_read' => (int)$row['is_read'],
                    'created_at' => $row['created_at'],
                    'redirect_url' => notif_redirect_url($row['type'], $row['related_week'] ?? null),
                ];
            }
        }

        echo json_encode([
            'unread_count' => $unread_count,
            'notifications' => $notifications,
        ]);
        break;

    case 'mark_read':
        $notif_id = (int)($_POST['notification_id'] ?? 0);
        if ($notif_id > 0) {
            $conn->query("UPDATE notifications SET is_read = 1 WHERE id = {$notif_id} AND user_id = {$uid}");
        }
        $count_r = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$uid} AND is_read = 0");
        $unread_count = ($count_r && $count_r->num_rows > 0) ? (int)$count_r->fetch_row()[0] : 0;
        echo json_encode(['unread_count' => $unread_count]);
        break;

    case 'mark_all_read':
        $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = {$uid} AND is_read = 0");
        echo json_encode(['unread_count' => 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

$conn->close();
