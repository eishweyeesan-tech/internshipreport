<?php
/**
 * Shared notification helpers for InternReport System.
 *
 * The `notifications` table is user-scoped via `user_id` (the recipient).
 * Supervisors receive their own rows; target context is carried in
 * `student_id`, `report_id` and `related_week` so links can be built
 * with `notif_action_url()`.
 */

if (!isset($mysqli) && !isset($conn)) {
    require_once __DIR__ . '/db.php';
}

/**
 * Insert a notification for a recipient.
 *
 * @param mysqli|mixed $db           Database connection ($mysqli or $conn)
 * @param int          $user_id      Recipient user id
 * @param string       $title
 * @param string       $message
 * @param string       $type         Must be a valid `notifications.type` enum value
 * @param int|null     $related_week
 * @param int|null     $student_id Related student (for action links)
 * @param int|null     $report_id  Related report_evaluations id (optional)
 * @return int  New notification id
 */
function notify_user($db, $user_id, $title, $message, $type = 'info', $related_week = null, $student_id = null, $report_id = null)
{
    $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, related_week, student_id, report_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssiii", $user_id, $title, $message, $type, $related_week, $student_id, $report_id);
    $stmt->execute();
    return (int) $db->insert_id;
}

/**
 * Insert a notification only if a matching one does not already exist (dedup).
 * Duplicates are matched on (recipient, type, student, week); when `$daily`
 * is true the match is further restricted to notifications created today.
 *
 * @return int|false  New notification id, or false when a duplicate exists
 */
function notify_user_once($db, $user_id, $title, $message, $type = 'info', $related_week = null, $student_id = null, $report_id = null, $daily = false)
{
    $sql = "SELECT id FROM notifications
            WHERE user_id = ? AND type = ?
              AND COALESCE(student_id, 0) = COALESCE(?, 0)
              AND COALESCE(related_week, 0) = COALESCE(?, 0)";
    if ($daily) {
        $sql .= " AND DATE(created_at) = CURDATE()";
    }
    $sql .= " LIMIT 1";

    $check = $db->prepare($sql);
    $check->bind_param("isii", $user_id, $type, $student_id, $related_week);
    $check->execute();
    $res = $check->get_result();
    if ($res && $res->fetch_row()) {
        return false;
    }

    return notify_user($db, $user_id, $title, $message, $type, $related_week, $student_id, $report_id);
}

/**
 * Build the target URL for a notification row (or a partial array of keys).
 * Returns a relative URL that lives in the supervisor/ directory.
 */
function notif_action_url($notif)
{
    $type       = $notif['type'] ?? 'info';
    $week       = $notif['related_week'] ?? null;
    $student_id = $notif['student_id'] ?? null;
    $report_id  = $notif['report_id'] ?? null;

    if (!empty($notif['announcement_id'])) {
        return 'announcement-detail.php?id=' . (int) $notif['announcement_id'];
    }

    switch ($type) {
        case 'new_report_submitted':
        case 'report_needs_review':
            if ($student_id) {
                $url = 'supervisor-review.php?student_id=' . (int) $student_id;
                if ($week) {
                    $url .= '&week=' . (int) $week;
                }
                return $url;
            }
            return 'supervisor-reports.php';

        case 'student_behind_schedule':
        case 'internship_completed':
        case 'supervisor_approved':
            if ($student_id) {
                return 'view-student-dashboard.php?id=' . (int) $student_id;
            }
            return 'my-students.php';

        case 'system_notice':
            if (!empty($notif['announcement_id'])) {
                return 'announcement-detail.php?id=' . (int) $notif['announcement_id'];
            }
            return 'supervisor-dashboard.php';

        default:
            if ($week) {
                return 'supervisor-dashboard.php?week=' . (int) $week;
            }
            return 'supervisor-dashboard.php';
    }
}

/**
 * Shared per-page wrapper used by supervisor pages. Delegates to
 * notif_action_url() so every bell/dropdown stays consistent.
 */
function notif_redirect_url($type, $related_week, $announcement_id = null, $student_id = null)
{
    return notif_action_url([
        'type'            => $type,
        'related_week'    => $related_week,
        'announcement_id' => $announcement_id,
        'student_id'      => $student_id,
    ]);
}

/**
 * Notification type metadata (icon glyph + Tailwind classes) shared by the
 * bell dropdowns and the full Notifications page.
 */
function notif_type_meta($type)
{
    switch ($type) {
        case 'instructor_approved':
            return ['icon' => '✓', 'classes' => 'bg-emerald-100 text-emerald-600'];
        case 'instructor_rejected':
            return ['icon' => '✕', 'classes' => 'bg-red-100 text-red-600'];
        case 'new_report_submitted':
            return ['icon' => '📄', 'classes' => 'bg-blue-100 text-blue-600'];
        case 'report_needs_review':
            return ['icon' => '🔍', 'classes' => 'bg-amber-100 text-amber-600'];
        case 'student_behind_schedule':
            return ['icon' => '⚠️', 'classes' => 'bg-red-100 text-red-600'];
        case 'internship_completed':
            return ['icon' => '🎓', 'classes' => 'bg-emerald-100 text-emerald-600'];
        case 'system_notice':
            return ['icon' => '📢', 'classes' => 'bg-indigo-100 text-indigo-600'];
        default:
            return ['icon' => '🔔', 'classes' => 'bg-blue-100 text-blue-600'];
    }
}
