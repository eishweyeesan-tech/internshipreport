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

    $week_number = ($related_week !== null && $related_week !== '') ? (int)$related_week : null;
    $student_id  = ($student_id !== null && $student_id !== '') ? (int)$student_id : null;

    $title   = trim((string)$title);
    $message = trim((string)$message);
    if ($title === '') $title = 'Notification';

    // Auto-detect student_id and week_number from link/title/message if not explicitly passed
    if ($student_id === null && !empty($link)) {
        if (preg_match('/[?&](?:student_id|id)=(\d+)/i', $link, $m)) {
            $student_id = (int)$m[1];
        }
    }
    if ($week_number === null && !empty($link)) {
        if (preg_match('/[?&]week=(\d+)/i', $link, $m)) {
            $week_number = (int)$m[1];
        }
    }
    if ($week_number === null && (!empty($title) || !empty($message))) {
        if (preg_match('/week\s*(\d+)/i', $title . ' ' . $message, $m)) {
            $week_number = (int)$m[1];
        }
    }

    if (empty($link)) {
        $link = notif_action_url([
            'type'            => $type,
            'related_week'    => $week_number,
            'student_id'      => $student_id,
            'report_id'       => $report_id,
        ]);
    }

    // ── Prevent Duplicate Insertion (Strict Check Before INSERT) ───
    try {
        static $has_notif_cols = null;
        if ($has_notif_cols === null) {
            $has_notif_cols = ['student_id' => false, 'week_number' => false];
            $col_q = $db->query("SHOW COLUMNS FROM notifications");
            if ($col_q) {
                while ($col = $col_q->fetch_assoc()) {
                    if ($col['Field'] === 'student_id') $has_notif_cols['student_id'] = true;
                    if ($col['Field'] === 'week_number') $has_notif_cols['week_number'] = true;
                }
            }
        }

        if ($has_notif_cols['student_id'] && $has_notif_cols['week_number']) {
            if ($student_id !== null && $week_number !== null) {
                $check_stmt = $db->prepare("SELECT id FROM notifications WHERE user_id = ? AND student_id = ? AND type = ? AND week_number = ? LIMIT 1");
                if ($check_stmt) {
                    $check_stmt->bind_param("iisi", $user_id, $student_id, $type, $week_number);
                    $check_stmt->execute();
                    $check_res = $check_stmt->get_result();
                    if ($check_res && $check_res->fetch_row()) {
                        $check_stmt->close();
                        return 0; // Skip duplicate insert
                    }
                    $check_stmt->close();
                }
            } elseif ($student_id !== null) {
                $check_stmt = $db->prepare("SELECT id FROM notifications WHERE user_id = ? AND student_id = ? AND type = ? AND week_number IS NULL LIMIT 1");
                if ($check_stmt) {
                    $check_stmt->bind_param("iis", $user_id, $student_id, $type);
                    $check_stmt->execute();
                    $check_res = $check_stmt->get_result();
                    if ($check_res && $check_res->fetch_row()) {
                        $check_stmt->close();
                        return 0;
                    }
                    $check_stmt->close();
                }
            } elseif ($week_number !== null) {
                $check_stmt = $db->prepare("SELECT id FROM notifications WHERE user_id = ? AND student_id IS NULL AND type = ? AND week_number = ? LIMIT 1");
                if ($check_stmt) {
                    $check_stmt->bind_param("isi", $user_id, $type, $week_number);
                    $check_stmt->execute();
                    $check_res = $check_stmt->get_result();
                    if ($check_res && $check_res->fetch_row()) {
                        $check_stmt->close();
                        return 0;
                    }
                    $check_stmt->close();
                }
            } else {
                $check_stmt = $db->prepare("SELECT id FROM notifications WHERE user_id = ? AND student_id IS NULL AND type = ? AND week_number IS NULL AND title = ? AND message = ? LIMIT 1");
                if ($check_stmt) {
                    $check_stmt->bind_param("isss", $user_id, $type, $title, $message);
                    $check_stmt->execute();
                    $check_res = $check_stmt->get_result();
                    if ($check_res && $check_res->fetch_row()) {
                        $check_stmt->close();
                        return 0;
                    }
                    $check_stmt->close();
                }
            }
        } else {
            $check_stmt = $db->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND message = ? LIMIT 1");
            if ($check_stmt) {
                $check_stmt->bind_param("isss", $user_id, $type, $title, $message);
                $check_stmt->execute();
                $check_res = $check_stmt->get_result();
                if ($check_res && $check_res->fetch_row()) {
                    $check_stmt->close();
                    return 0;
                }
                $check_stmt->close();
            }
        }
    } catch (Exception $e) {
        error_log("Failed to check duplicate notification: " . $e->getMessage());
    }

    try {
        if (!empty($has_notif_cols['student_id']) && !empty($has_notif_cols['week_number'])) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, student_id, week_number, title, message, link, type, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            if ($stmt) {
                $stmt->bind_param("iiissss", $user_id, $student_id, $week_number, $title, $message, $link, $type);
                $stmt->execute();
                $insert_id = (int) $db->insert_id;
                $stmt->close();
                return $insert_id;
            }
        } else {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link, type, is_read) VALUES (?, ?, ?, ?, ?, 0)");
            if ($stmt) {
                $stmt->bind_param("issss", $user_id, $title, $message, $link, $type);
                $stmt->execute();
                $insert_id = (int) $db->insert_id;
                $stmt->close();
                return $insert_id;
            }
        }
    } catch (Exception $e) {
        error_log("Failed to insert notification: " . $e->getMessage());
        return 0;
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

    if ($daily) {
        $sql = "SELECT id FROM notifications WHERE user_id = ? AND title = ? AND message = ? AND DATE(created_at) = CURDATE() LIMIT 1";
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
    }

    $id = createNotification($db, $user_id, $title, $message, $link, $type, $related_week, $student_id, $report_id);
    return $id > 0 ? $id : false;
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

    // Direct link sanitization & normalization for student context
    if (($role === 'student' || $in_student) && !empty($notif['link']) && $notif['link'] !== '#') {
        $clean = $notif['link'];
        $clean = preg_replace('#^\.\./student/#i', '', $clean);
        if ($in_student) {
            $clean = preg_replace('#^student/#i', '', $clean);
        }
        if (strpos($clean, 'student-dashboard.php') !== false && strpos($clean, 'week=') !== false && strpos($clean, 'tab=') === false) {
            $clean = str_replace('student-dashboard.php?', 'student-dashboard.php?tab=weekly-report&', $clean);
        }
        if (strpos($clean, 'student-dashboard.php') !== false && strpos($clean, '#') === false && (strpos($clean, 'tab=weekly-report') !== false || strpos($clean, 'week=') !== false)) {
            $clean .= '#instructor-evaluation';
        }
        return $clean;
    }

    if (!empty($notif['link'])) {
        return $notif['link'];
    }

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
                $url = $base . 'admin-dashboard.php?tab=logs';
                if ($student_id) $url .= '&student_id=' . $student_id;
                if ($week) $url .= '&week=' . $week;
                return $url;

            case 'internship_completed':
                if ($student_id) {
                    return $base . 'view-student-dashboard.php?id=' . $student_id;
                }
                return $base . 'admin-dashboard.php?tab=students';

            case 'user_registered':
            case 'student_created':
            case 'profile_updated':
                return $base . 'academic-years.php';

            case 'system_notice':
            case 'announcement':
            default:
                return $base . 'notifications.php';
        }
    }

    // 2. Instructor Role Routing
    if ($role === 'instructor' || $in_instructor) {
        $base = $in_instructor ? '' : '../instructor/';
        switch ($type) {
            case 'new_report_submitted':
            case 'report_needs_review':
            case 'report_submitted':
                if ($student_id) {
                    $url = $base . 'view-report.php?student_id=' . $student_id;
                    if ($week) $url .= '&week=' . $week;
                    return $url;
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
        $title = $notif['title'] ?? '';
        $msg   = $notif['message'] ?? '';
        if (!$week && preg_match('/week\s*(\d+)/i', $title . ' ' . $msg, $m)) {
            $week = (int)$m[1];
        }

        switch ($type) {
            case 'instructor_approved':
            case 'instructor_rejected':
            case 'supervisor_approved':
            case 'report_graded':
            case 'new_report_submitted':
            case 'report_needs_review':
            case 'report_submitted':
                if ($week) {
                    return $base . 'student-dashboard.php?tab=weekly-report&week=' . $week . '#instructor-evaluation';
                }
                return $base . 'student-dashboard.php?tab=weekly-report#instructor-evaluation';

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
                if ($week) {
                    return $base . 'student-dashboard.php?tab=weekly-report&week=' . $week . '#instructor-evaluation';
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
