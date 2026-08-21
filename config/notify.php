<?php
/**
 * Shared notification helpers for InternReport System.
 *
 * The `notifications` table is user-scoped via `user_id` (the recipient).
 * Target context is carried in `student_id`, `report_id` and `related_week`
 * so links can be built dynamically with `notif_action_url()`.
 */

if (!isset($mysqli) && !isset($conn)) {
    require_once __DIR__ . '/db.php';
}

/**
 * Insert a notification for a recipient (supports direct link, title, message).
 *
 * @param mysqli|mixed $db           Database connection ($mysqli or $conn)
 * @param int          $user_id      Recipient user id
 * @param string       $title        Notification title
 * @param string       $message      Notification message body
 * @param string|null  $link         Direct target URL link
 * @param string       $type         Notification type
 * @param int|null     $related_week Related week number
 * @param int|null     $student_id   Related student ID
 * @param int|null     $report_id    Related report ID
 * @return int  New notification id
 */
function createNotification($db, $user_id, $title, $message, $link = null, $type = 'info', $related_week = null, $student_id = null, $report_id = null)
{
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$db) return 0;

    $title   = trim((string)$title);
    $message = trim((string)$message);
    if ($title === '') $title = 'Notification';

    if (empty($link)) {
        $link = notif_action_url([
            'type'            => $type,
            'related_week'    => $related_week,
            'student_id'      => $student_id,
            'report_id'       => $report_id,
        ]);
    }

    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link, is_read) VALUES (?, ?, ?, ?, 0)");
        if ($stmt) {
            $stmt->bind_param("isss", $user_id, $title, $message, $link);
            $stmt->execute();
            $insert_id = (int) $db->insert_id;
            $stmt->close();
            return $insert_id;
        }
    } catch (Exception $e) {
        try {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link, type, related_week, student_id, report_id, is_read)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
            if ($stmt) {
                $stmt->bind_param("issssiii", $user_id, $title, $message, $link, $type, $related_week, $student_id, $report_id);
                $stmt->execute();
                $insert_id = (int) $db->insert_id;
                $stmt->close();
                return $insert_id;
            }
        } catch (Exception $ex) {
            error_log("Failed to insert notification: " . $ex->getMessage());
            return 0;
        }
    }
    return 0;
}

/**
 * Backward compatible wrapper for notify_user.
 */
function notify_user($db, $user_id, $title, $message, $type = 'info', $related_week = null, $student_id = null, $report_id = null, $link = null)
{
    return createNotification($db, $user_id, $title, $message, $link, $type, $related_week, $student_id, $report_id);
}

/**
 * Insert a notification only if a matching one does not already exist (dedup).
 *
 * @return int|false  New notification id, or false when a duplicate exists
 */
function notify_user_once($db, $user_id, $title, $message, $type = 'info', $related_week = null, $student_id = null, $report_id = null, $daily = false, $link = null)
{
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$db) return false;

    $sql = "SELECT id FROM notifications WHERE user_id = ? AND title = ? AND message = ?";
    if ($daily) {
        $sql .= " AND DATE(created_at) = CURDATE()";
    }
    $sql .= " LIMIT 1";

    try {
        $check = $db->prepare($sql);
        if ($check) {
            $check->bind_param("iss", $user_id, $title, $message);
            $check->execute();
            $res = $check->get_result();
            if ($res && $res->fetch_row()) {
                $check->close();
                return false;
            }
            $check->close();
        }
    } catch (Exception $e) {
        error_log("notify_user_once check error: " . $e->getMessage());
    }

    return createNotification($db, $user_id, $title, $message, $link, $type, $related_week, $student_id, $report_id);
}

/**
 * Build the target URL for a notification row based on notification attributes and recipient role.
 *
 * @param array       $notif Array containing link, type, related_week, student_id, report_id, announcement_id
 * @param string|null $role  Recipient role (admin, instructor, supervisor, student)
 * @return string Relative URL to destination page
 */
function notif_action_url($notif, $role = null)
{
    if (!empty($notif['link'])) {
        return $notif['link'];
    }
    $type       = $notif['type'] ?? 'info';
    $week       = !empty($notif['related_week']) ? (int)$notif['related_week'] : null;
    $student_id = !empty($notif['student_id']) ? (int)$notif['student_id'] : null;
    $report_id  = !empty($notif['report_id']) ? (int)$notif['report_id'] : null;
    $ann_id     = !empty($notif['announcement_id']) ? (int)$notif['announcement_id'] : null;

    if (!$role && isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $in_admin      = strpos($script, '/admin/') !== false;
    $in_instructor = strpos($script, '/instructor/') !== false;
    $in_supervisor = strpos($script, '/supervisor/') !== false;
    $in_student    = strpos($script, '/student/') !== false;

    // 1. Admin Role Routing
    if ($role === 'admin' || $in_admin) {
        $base = $in_admin ? '' : '../admin/';
        switch ($type) {
            case 'new_report_submitted':
            case 'report_needs_review':
            case 'report_submitted':
            case 'instructor_approved':
            case 'instructor_rejected':
            case 'supervisor_approved':
                $url = $base . 'admin-dashboard.php?tab=history';
                if ($student_id) $url .= '&student_id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                return $url;

            case 'daily_log_added':
            case 'daily_log_updated':
            case 'student_behind_schedule':
                if ($student_id) return $base . 'admin-dashboard.php?tab=students&id=' . $student_id;
                return $base . 'admin-dashboard.php?tab=students';

            case 'user_registered':
            case 'student_created':
            case 'profile_updated':
                if ($student_id) return $base . 'admin-dashboard.php?tab=students&id=' . $student_id;
                return $base . 'admin-dashboard.php?tab=manage';

            default:
                return $base . 'admin-dashboard.php';
        }
    }

    // 2. Instructor Role Routing
    if ($role === 'instructor' || $in_instructor) {
        $base = $in_instructor ? '' : '../instructor/';
        switch ($type) {
            case 'new_report_submitted':
            case 'report_needs_review':
            case 'report_submitted':
            case 'instructor_approved':
            case 'instructor_rejected':
            case 'supervisor_approved':
                if ($student_id && $week) {
                    return $base . 'view-report.php?student_id=' . $student_id . '&week=' . $week;
                }
                if ($report_id) {
                    return $base . 'view-report.php?id=' . $report_id;
                }
                return $base . 'instructor-dashboard.php';

            case 'daily_log_added':
            case 'daily_log_updated':
            case 'student_behind_schedule':
                if ($student_id) {
                    return $base . 'instructor-dashboard.php?student_id=' . $student_id;
                }
                return $base . 'instructor-dashboard.php';

            case 'user_registered':
            case 'student_created':
            case 'profile_updated':
                if ($student_id) {
                    return $base . 'instructor-dashboard.php?student_id=' . $student_id;
                }
                return $base . 'instructor-dashboard.php';

            default:
                return $base . 'instructor-dashboard.php';
        }
    }

    // 3. Student Role Routing
    if ($role === 'student' || $in_student) {
        $base = $in_student ? '' : '../student/';
        switch ($type) {
            case 'instructor_approved':
            case 'instructor_rejected':
            case 'supervisor_approved':
            case 'new_report_submitted':
            case 'report_needs_review':
            case 'report_submitted':
                if ($week) {
                    return $base . 'student-dashboard.php?week=' . $week;
                }
                return $base . 'log-history.php';

            case 'daily_log_added':
            case 'daily_log_updated':
                if ($week) {
                    return $base . 'daily_log.php?week=' . $week;
                }
                return $base . 'daily_log.php';

            case 'profile_updated':
            case 'user_registered':
                return $base . 'profile.php';

            default:
                return $base . 'student-dashboard.php';
        }
    }

    // 4. Supervisor Role Routing (Default)
    $base = $in_supervisor ? '' : '../supervisor/';
    switch ($type) {
        case 'new_report_submitted':
        case 'report_needs_review':
        case 'report_submitted':
        case 'instructor_approved':
            if ($student_id) {
                $url = $base . 'view-student-dashboard.php?id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                return $url;
            }
            return $base . 'supervisor-reports.php';

        case 'instructor_rejected':
            if ($student_id) {
                $url = $base . 'view-student-dashboard.php?id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                return $url;
            }
            return $base . 'supervisor-reports.php';

        case 'daily_log_added':
        case 'daily_log_updated':
        case 'student_behind_schedule':
        case 'internship_completed':
        case 'supervisor_approved':
        case 'report_graded':
            if ($student_id) {
                $url = $base . 'view-student-dashboard.php?id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                return $url;
            }
            return $base . 'my-students.php';

        case 'user_registered':
        case 'student_created':
        case 'profile_updated':
            if ($student_id) {
                return $base . 'view-student-dashboard.php?id=' . $student_id;
            }
            return $base . 'supervisor-dashboard.php';

        default:
            if ($student_id && $week) {
                return $base . 'view-student-dashboard.php?id=' . $student_id . '&week=' . $week;
            }
            if ($student_id) {
                return $base . 'view-student-dashboard.php?id=' . $student_id;
            }
            return $base . 'supervisor-dashboard.php';
    }
}

/**
 * Shared per-page wrapper. Delegates to notif_action_url().
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
 * Notification type metadata (icon glyph + Tailwind classes).
 */
function notif_type_meta($type)
{
    switch ($type) {
        case 'instructor_approved':
            return ['icon' => '✓', 'classes' => 'bg-emerald-100 text-emerald-600'];
        case 'instructor_rejected':
            return ['icon' => '✕', 'classes' => 'bg-red-100 text-red-600'];
        case 'new_report_submitted':
        case 'report_submitted':
            return ['icon' => '📄', 'classes' => 'bg-teal-100 text-teal-600'];
        case 'report_needs_review':
            return ['icon' => '📝', 'classes' => 'bg-blue-100 text-blue-600'];

        case 'student_behind_schedule':
            return ['icon' => '⚠️', 'classes' => 'bg-red-100 text-red-600'];
        case 'internship_completed':
            return ['icon' => '🎓', 'classes' => 'bg-emerald-100 text-emerald-600'];
        case 'system_notice':
        case 'user_registered':
        case 'student_created':
            return ['icon' => '📢', 'classes' => 'bg-teal-100 text-teal-600'];
        default:
            return ['icon' => '🔔', 'classes' => 'bg-teal-100 text-teal-600'];
    }
}
