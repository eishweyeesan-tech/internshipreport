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
 * Build the target URL for a notification row based on notification attributes and recipient role.
 *
 * @param array       $notif Array containing type, related_week, student_id, report_id, announcement_id
 * @param string|null $role  Recipient role (admin, instructor, supervisor, student)
 * @return string Relative URL to destination page
 */
function notif_action_url($notif, $role = null)
{
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
                    return $base . 'view-report.php?student_id=' . $student_id . '&week=' . $week . '#evaluation-panel';
                }
                if ($report_id) {
                    return $base . 'view-report.php?id=' . $report_id . '#evaluation-panel';
                }
            default:
                return '#';
        }
    }

    // 3. Student Role Routing
    if ($role === 'student' || $in_student) {
        $base = $in_student ? '' : '../student/';
        switch ($type) {
            case 'instructor_approved':
            case 'instructor_rejected':
            case 'supervisor_approved':
            case 'report_needs_review':
            case 'report_submitted':
                if ($week) {
                    return $base . 'student-dashboard.php?tab=weekly-report&week=' . $week . '#feedback-section';
                }
                return $base . 'student-dashboard.php?tab=weekly-report#feedback-section';

            case 'new_report_submitted':
                if ($week) {
                    return $base . 'student-dashboard.php?tab=weekly-report&week=' . $week . '#weekly-report-view';
                }
                return $base . 'student-dashboard.php?tab=weekly-report';

            case 'daily_log_added':
            case 'daily_log_updated':
                if ($week) {
                    return $base . 'student-dashboard.php?tab=daily-log&week=' . $week . '#daily-log-form';
                }
                return $base . 'student-dashboard.php?tab=daily-log#daily-log-form';

            case 'student_behind_schedule':
                if ($week) {
                    return $base . 'student-dashboard.php?tab=daily-log&week=' . $week . '#daily-log-form';
                }
                return $base . 'student-dashboard.php?tab=daily-log#daily-log-form';

            case 'profile_updated':
            case 'user_registered':
                return $base . 'profile.php';

            default:
                if ($week) {
                    return $base . 'student-dashboard.php?week=' . $week;
                }
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
                $url = $base . 'supervisor-review.php?student_id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                $url .= '#university-evaluation';
                return $url;
            }
            return $base . 'supervisor-reports.php';

        case 'instructor_rejected':
            if ($student_id) {
                $url = $base . 'supervisor-review.php?student_id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                $url .= '#instructor-feedback';
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
                return $base . 'view-student-dashboard.php?id=' . $student_id;
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
                return $base . 'supervisor-review.php?student_id=' . $student_id . '&week=' . $week;
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
