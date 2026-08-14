<?php
/**
 * Centralized Notification POST / AJAX Action Handler
 */

if (!function_exists('handle_notification_ajax_actions')) {
    /**
     * Process POST requests for notification actions (mark_notification_read, mark_all_notifications_read, delete_notification).
     * Automatically returns JSON for AJAX requests or redirects for standard form submits.
     *
     * @param PDO $pdo
     * @param int $user_id
     * @param string|null $redirect_page Page filename to redirect to if standard submit (defaults to current URL)
     */
    function handle_notification_ajax_actions($pdo, $user_id, $redirect_page = null) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $user_id = (int)$user_id;
        if ($user_id <= 0) return;

        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $redirect = $redirect_page ?? ($_SERVER['PHP_SELF'] . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));

        // Action: Mark single notification read
        if (isset($_POST['mark_notification_read'])) {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($notif_id > 0) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->execute([$notif_id, $user_id]);
            }
            if ($is_ajax) {
                header('Content-Type: application/json');
                $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $count_q->execute([$user_id]);
                echo json_encode(['unread_count' => (int)$count_q->fetchColumn()]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        }

        // Action: Mark all notifications read
        if (isset($_POST['mark_all_notifications_read'])) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['unread_count' => 0]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        }

        // Action: Delete notification
        if (isset($_POST['delete_notification'])) {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            $deleted = false;
            if ($notif_id > 0) {
                $del = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
                $del->execute([$notif_id, $user_id]);
                $deleted = $del->rowCount() > 0;
            }
            if ($is_ajax) {
                header('Content-Type: application/json');
                $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $count_q->execute([$user_id]);
                echo json_encode(['success' => $deleted, 'unread_count' => (int)$count_q->fetchColumn()]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}
