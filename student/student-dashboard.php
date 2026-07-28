<?php
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';

$user_id    = $_SESSION['user_id'];
$username   = $_SESSION['username'];
$role       = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}


$internship_id = $user_id;
$message = '';
$esc_uid = $conn->real_escape_string($user_id);
$esc_iid = $conn->real_escape_string($internship_id);

// ══════════════════════════════════════════════════════════════════════
// FETCH INTERNSHIP DATE RANGE + PROFILE INFO
// ══════════════════════════════════════════════════════════════════════
$profile_r = $conn->query("SELECT sp.full_name, sp.student_roll, sp.internship_start_date, sp.internship_end_date, sup_u.username AS supervisor_name, u.profile_pic,
           sp.instructor_name, sp.instructor_email, sp.instructor_id
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = {$esc_uid}");
$profile_row = $profile_r ? $profile_r->fetch_assoc() : null;
$intern_start = $profile_row['internship_start_date'] ?? null;
$intern_end   = $profile_row['internship_end_date'] ?? null;
$student_name = $profile_row['full_name'] ?: $username;
$student_roll = $profile_row['student_roll'] ?? '';
$supervisor_name = $profile_row['supervisor_name'] ?? '—';
$profile_pic = $profile_row['profile_pic'] ?? '';
$instructor_name = $profile_row['instructor_name'] ?: '—';
$instructor_email = $profile_row['instructor_email'] ?? '';

// ══════════════════════════════════════════════════════════════════════
// FETCH WARNING STATUS (fresh from database on every page load)
// ══════════════════════════════════════════════════════════════════════
$warn_r = $conn->query("SELECT is_warned FROM users WHERE id = {$esc_uid}");
$is_warned = ($warn_r && $warn_r->num_rows > 0) ? ((int) $warn_r->fetch_row()[0] === 1) : false;

// ── FORM A: Daily Log ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    // Check if logs are locked for this week (student signed and not rejected)
    $post_week = (int) ($_POST['selected_week'] ?? 0);
    if ($post_week > 0) {
        $esc_pw = $conn->real_escape_string($post_week);
        $lock_r = $conn->query("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_pw}");
        if ($lock_r && $lock_r->num_rows > 0) {
            $lock_row = $lock_r->fetch_assoc();
            if (!empty($lock_row['student_signature_type']) && !empty($lock_row['student_signature_value']) && $lock_row['report_status'] !== 'rejected') {
                $message = 'log_locked';
            }
        }
    }
    if (!$message) {
    $log_date           = trim($_POST['log_date'] ?? '');
    $attendance_status  = trim($_POST['attendance_status'] ?? 'present');
    $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');
    $intended_task      = trim($_POST['intended_task'] ?? '');
    $task_detail        = trim($_POST['task_detail'] ?? '');
    $actual_task        = trim($_POST['actual_task'] ?? '');
    $tools_used         = trim($_POST['tools_used'] ?? '');
    $knowledge_gained   = trim($_POST['knowledge_gained'] ?? '');
    $hours_worked       = trim($_POST['hours_worked'] ?? '00:00');

    if ($log_date) {
        // Server-side date range validation using helper
        $date_error = validateLogDate($log_date, $intern_start, $intern_end);
        if ($date_error) {
            $message = $date_error;
        } else {
            // Server-side week validation: date must fall within the selected week
            $post_week = (int) ($_POST['selected_week'] ?? $selected_week);
            if (!empty($weeks[$post_week])) {
                $ws_start = $weeks[$post_week]['start'];
                $ws_end   = $weeks[$post_week]['end'];
                if ($log_date < $ws_start || $log_date > $ws_end) {
                    $message = 'date_out_of_week';
                }
            }
            // Server-side weekend check
            if (!$message) {
                $log_day = (int)(new DateTime($log_date))->format('N');
                if ($log_day >= 6) {
                    $message = 'date_is_weekend';
                }
            }
        }
        if (!$message) {
            // Auto-set to "leave" for public holidays
            if (isset($holiday_dates[$log_date])) {
                $attendance_status = 'leave';
                $reason_for_absence = 'Public Holiday - ' . $holiday_dates[$log_date];
            }
            if ($attendance_status === 'absent') {
                $intended_task  = $reason_for_absence ?: 'Absent';
                $task_detail    = 'N/A - Absent';
                $actual_task    = 'N/A - Absent';
                $tools_used     = 'N/A - Absent';
                $knowledge_gained = 'N/A - Absent';
                $hours_worked   = '00:00';
            }
            $esc_att  = $conn->real_escape_string($attendance_status);
            $esc_rfa  = $conn->real_escape_string($reason_for_absence);
            $esc_it   = $conn->real_escape_string($intended_task);
            $esc_td   = $conn->real_escape_string($task_detail);
            $esc_at   = $conn->real_escape_string($actual_task);
            $esc_tu   = $conn->real_escape_string($tools_used);
            $esc_kg   = $conn->real_escape_string($knowledge_gained);
            $esc_hw   = $conn->real_escape_string($hours_worked);
            $esc_ld   = $conn->real_escape_string($log_date);

            $query = "INSERT INTO daily_logs
                (internship_id, log_date, attendance_status, reason_for_absence, task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
                VALUES ({$esc_iid}, '{$esc_ld}', '{$esc_att}', '{$esc_rfa}', '{$esc_it}', '{$esc_td}', '{$esc_at}', '{$esc_tu}', '{$esc_kg}', '{$esc_hw}')
                ON DUPLICATE KEY UPDATE
                attendance_status = VALUES(attendance_status),
                reason_for_absence = VALUES(reason_for_absence),
                task_title = VALUES(task_title),
                task_detail = VALUES(task_detail),
                tasks_performed = VALUES(tasks_performed),
                tools_used = VALUES(tools_used),
                learnt_skills = VALUES(learnt_skills),
                calculated_duration = VALUES(calculated_duration)";
            $conn->query($query);
            $message = 'daily_saved';
        }
    }
    }
}

// ── EDIT LOG ──────────────────────────────────────────────────────
$editing_log = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $esc_edit_id = $conn->real_escape_string($edit_id);
    $edit_r = $conn->query("SELECT * FROM daily_logs WHERE id = {$esc_edit_id} AND internship_id = {$esc_iid}");
    $editing_log = ($edit_r && $edit_r->num_rows > 0) ? $edit_r->fetch_assoc() : null;
    if (!$editing_log) {
        $message = 'log_not_found';
    }
    // Check if this log is locked (student signed and not rejected)
    if ($editing_log) {
        $edit_lock_week = getInternshipWeekNumber($intern_start, $editing_log['log_date']);
        $esc_elw = $conn->real_escape_string($edit_lock_week);
        $edit_lock_r = $conn->query("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_elw}");
        if ($edit_lock_r && $edit_lock_r->num_rows > 0) {
            $edit_lock_row = $edit_lock_r->fetch_assoc();
            if (!empty($edit_lock_row['student_signature_type']) && !empty($edit_lock_row['student_signature_value']) && $edit_lock_row['report_status'] !== 'rejected') {
                $editing_log = null;
                $message = 'log_locked';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_log'])) {
    // Check if logs are locked for this week (student signed and not rejected)
    $post_week = (int) ($_POST['selected_week'] ?? 0);
    if ($post_week > 0) {
        $esc_pw = $conn->real_escape_string($post_week);
        $lock_r = $conn->query("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_pw}");
        if ($lock_r && $lock_r->num_rows > 0) {
            $lock_row = $lock_r->fetch_assoc();
            if (!empty($lock_row['student_signature_type']) && !empty($lock_row['student_signature_value']) && $lock_row['report_status'] !== 'rejected') {
                $message = 'log_locked';
            }
        }
    }
    if (!$message) {
    $edit_id           = (int) ($_POST['log_id'] ?? 0);
    $log_date          = trim($_POST['log_date'] ?? '');
    $attendance_status = trim($_POST['attendance_status'] ?? 'present');
    $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');
    $intended_task     = trim($_POST['intended_task'] ?? '');
    $task_detail       = trim($_POST['task_detail'] ?? '');
    $actual_task       = trim($_POST['actual_task'] ?? '');
    $tools_used        = trim($_POST['tools_used'] ?? '');
    $knowledge_gained  = trim($_POST['knowledge_gained'] ?? '');
    $hours_worked      = trim($_POST['hours_worked'] ?? '00:00');

    if ($edit_id && $log_date) {
        $date_error = validateLogDate($log_date, $intern_start, $intern_end);
        if ($date_error) {
            $message = $date_error;
        } else {
            // Server-side week validation: date must fall within the selected week
            $post_week = (int) ($_POST['selected_week'] ?? $selected_week);
            if (!empty($weeks[$post_week])) {
                $ews_start = $weeks[$post_week]['start'];
                $ews_end   = $weeks[$post_week]['end'];
                if ($log_date < $ews_start || $log_date > $ews_end) {
                    $message = 'date_out_of_week';
                }
            }
            // Server-side weekend check
            if (!$message) {
                $log_day = (int)(new DateTime($log_date))->format('N');
                if ($log_day >= 6) {
                    $message = 'date_is_weekend';
                }
            }
        }
        if (!$message) {
            // Auto-set to "leave" for public holidays
            if (isset($holiday_dates[$log_date])) {
                $attendance_status = 'leave';
                $reason_for_absence = 'Public Holiday - ' . $holiday_dates[$log_date];
            }
            if ($attendance_status === 'absent') {
                $intended_task  = $reason_for_absence ?: 'Absent';
                $task_detail    = 'N/A - Absent';
                $actual_task    = 'N/A - Absent';
                $tools_used     = 'N/A - Absent';
                $knowledge_gained = 'N/A - Absent';
                $hours_worked   = '00:00';
            }
            $esc_att  = $conn->real_escape_string($attendance_status);
            $esc_rfa  = $conn->real_escape_string($reason_for_absence);
            $esc_it   = $conn->real_escape_string($intended_task);
            $esc_td   = $conn->real_escape_string($task_detail);
            $esc_at   = $conn->real_escape_string($actual_task);
            $esc_tu   = $conn->real_escape_string($tools_used);
            $esc_kg   = $conn->real_escape_string($knowledge_gained);
            $esc_hw   = $conn->real_escape_string($hours_worked);
            $esc_ld   = $conn->real_escape_string($log_date);
            $esc_eid  = $conn->real_escape_string($edit_id);

            $conn->query("UPDATE daily_logs SET
                log_date = '{$esc_ld}', attendance_status = '{$esc_att}', reason_for_absence = '{$esc_rfa}',
                task_title = '{$esc_it}', task_detail = '{$esc_td}', tasks_performed = '{$esc_at}',
                tools_used = '{$esc_tu}', learnt_skills = '{$esc_kg}', calculated_duration = '{$esc_hw}'
                WHERE id = {$esc_eid} AND internship_id = {$esc_iid}");
            $message = 'log_updated';
        }
    }
    }
}

// ── DELETE LOG ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    $del_id = (int) ($_POST['log_id'] ?? 0);
    if ($del_id) {
        // Check if this log is locked (student signed and not rejected)
        $esc_del_check = $conn->real_escape_string($del_id);
        $del_lock_r = $conn->query("SELECT log_date FROM daily_logs WHERE id = {$esc_del_check} AND internship_id = {$esc_iid}");
        if ($del_lock_r && $del_lock_r->num_rows > 0) {
            $del_lock_log = $del_lock_r->fetch_assoc();
            $del_lock_week = getInternshipWeekNumber($intern_start, $del_lock_log['log_date']);
            $esc_dlw = $conn->real_escape_string($del_lock_week);
            $del_lock_eval = $conn->query("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_dlw}");
            if ($del_lock_eval && $del_lock_eval->num_rows > 0) {
                $del_lock_row = $del_lock_eval->fetch_assoc();
                if (!empty($del_lock_row['student_signature_type']) && !empty($del_lock_row['student_signature_value']) && $del_lock_row['report_status'] !== 'rejected') {
                    $message = 'log_locked';
                }
            }
        }
        if (!$message) {
            $esc_del = $conn->real_escape_string($del_id);
            $conn->query("DELETE FROM daily_logs WHERE id = {$esc_del} AND internship_id = {$esc_iid}");
            $message = 'log_deleted';
        }
    }
}

// ── FORM B: Weekly Reflection ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reflection'])) {
    $week_number  = (int) ($_POST['week_number'] ?? 0);
    $what_done    = trim($_POST['what_done'] ?? '');
    $how_done     = trim($_POST['how_done'] ?? '');
    $why_done     = trim($_POST['why_done'] ?? '');

    if ($week_number > 0 && $what_done) {
        $esc_wn  = $conn->real_escape_string($week_number);
        $esc_wd  = $conn->real_escape_string($what_done);
        $esc_hd  = $conn->real_escape_string($how_done);
        $esc_why = $conn->real_escape_string($why_done);

        $conn->query("INSERT INTO weekly_reflections
            (internship_id, week_number, what_done, how_done, why_done)
            VALUES ({$esc_iid}, '{$esc_wn}', '{$esc_wd}', '{$esc_hd}', '{$esc_why}')
            ON DUPLICATE KEY UPDATE
            what_done = VALUES(what_done),
            how_done = VALUES(how_done),
            why_done = VALUES(why_done)");
        $message = 'reflection_saved';
    }
}

// ── MAGIC LINK GENERATION ────────────────────────────────────────
$magic_link = '';

// ── FETCH EXISTING DATA ──────────────────────────────────────────
$all_logs_r = $conn->query("SELECT * FROM daily_logs WHERE internship_id = {$esc_iid} ORDER BY log_date DESC");
$all_logs = [];
if ($all_logs_r) { while ($row = $all_logs_r->fetch_assoc()) { $all_logs[] = $row; } }

// ── FETCH EXISTING LOG DATES (for duplicate prevention) ─────────
$log_dates_q = "SELECT log_date FROM daily_logs WHERE internship_id = {$esc_iid}";
if ($editing_log) {
    $esc_edid = $conn->real_escape_string($editing_log['id']);
    $log_dates_q .= " AND id != {$esc_edid}";
}
$log_dates_r = $conn->query($log_dates_q);
$existing_log_dates = [];
if ($log_dates_r) { while ($row = $log_dates_r->fetch_assoc()) { $existing_log_dates[] = $row['log_date']; } }

// Build week ranges using custom Sun→Sat logic from internship start date
$weeks = [];
if ($intern_start) {
    $w = 1;
    while (true) {
        $range = getWeekRange($intern_start, $w);
        if (!$range) break;
        // Stop if the week starts after the internship end date
        if ($intern_end && $range['start'] > $intern_end) break;
        $weeks[$w] = $range;
        $w++;
    }
}

$selected_week = 1;
if (isset($_GET['week'])) {
    $w = (int)$_GET['week'];
    if (isset($weeks[$w])) $selected_week = $w;
}

// Format date range for the selected week
$week_date_range = '';
if (!empty($weeks[$selected_week])) {
    $week_start_obj = new DateTime($weeks[$selected_week]['start']);
    $week_end_obj   = new DateTime($weeks[$selected_week]['end']);
    $week_date_range = $week_start_obj->format('d M Y') . ' to ' . $week_end_obj->format('d M Y');
}

// Filter logs by selected week + optional date range
$filter_start = trim($_GET['filter_start'] ?? '');
$filter_end   = trim($_GET['filter_end'] ?? '');

if (!empty($weeks)) {
    $ws = $weeks[$selected_week]['start'];
    $we = $weeks[$selected_week]['end'];
    $esc_ws = $conn->real_escape_string($ws);
    $esc_we = $conn->real_escape_string($we);
    $log_sql  = "SELECT * FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_ws}' AND '{$esc_we}'";
    if ($filter_start) {
        $esc_fs = $conn->real_escape_string($filter_start);
        $log_sql  .= " AND log_date >= '{$esc_fs}'";
    }
    if ($filter_end) {
        $esc_fe = $conn->real_escape_string($filter_end);
        $log_sql  .= " AND log_date <= '{$esc_fe}'";
    }
    $log_sql .= " ORDER BY log_date DESC";
    $logs_r = $conn->query($log_sql);
} else {
    $logs_r = $conn->query("SELECT * FROM daily_logs WHERE internship_id = {$esc_iid} ORDER BY log_date DESC");
}
$recent_logs = [];
if ($logs_r) { while ($row = $logs_r->fetch_assoc()) { $recent_logs[] = $row; } }

// Attendance counts (SQL-based)
$present_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status = 'present'");
$present_count = ($present_r && $present_r->num_rows > 0) ? (int) $present_r->fetch_row()[0] : 0;

$absent_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status IN ('absent','leave')");
$absent_count = ($absent_r && $absent_r->num_rows > 0) ? (int) $absent_r->fetch_row()[0] : 0;

// Overall internship attendance details for tooltips (all weeks)
$present_dates = [];
$absent_logs = [];

$pd_r = $conn->query("SELECT log_date FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status = 'present' ORDER BY log_date ASC");
if ($pd_r) { while ($row = $pd_r->fetch_assoc()) { $present_dates[] = $row['log_date']; } }

$ad_r = $conn->query("SELECT log_date, reason_for_absence FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
if ($ad_r) { while ($row = $ad_r->fetch_assoc()) { $absent_logs[] = $row; } }

// Weekly Reflection unlock logic
$weekly_log_count = 0;
$reflection_submitted = false;
$total_weekdays = 0;
if (!empty($weeks)) {
    $ws = $weeks[$selected_week]['start'];
    $we = $weeks[$selected_week]['end'];
    $esc_ws = $conn->real_escape_string($ws);
    $esc_we = $conn->real_escape_string($we);
    $wls_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_ws}' AND '{$esc_we}'");
    $weekly_log_count = ($wls_r && $wls_r->num_rows > 0) ? (int) $wls_r->fetch_row()[0] : 0;
    // Count only weekdays (Mon-Fri) in this week
    $wd_cursor = new DateTime($ws);
    $wd_end = new DateTime($we);
    while ($wd_cursor <= $wd_end) {
        if ((int)$wd_cursor->format('N') < 6) $total_weekdays++;
        $wd_cursor->modify('+1 day');
    }
}
$reflection_unlocked = $total_weekdays > 0 && $weekly_log_count >= $total_weekdays;

$esc_sw = $conn->real_escape_string($selected_week);
$rc_r = $conn->query("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = {$esc_iid} AND week_number = {$esc_sw}");
$reflection_submitted = ($rc_r && $rc_r->num_rows > 0) ? ((int) $rc_r->fetch_row()[0] > 0) : false;

// Check if instructor rejected this week's report + fetch student signature
$rej_r = $conn->query("SELECT report_status, instructor_comments, student_signature_type, student_signature_value FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_sw}");
$rejection = ($rej_r && $rej_r->num_rows > 0) ? $rej_r->fetch_assoc() : null;
$is_rejected = $rejection && $rejection['report_status'] === 'rejected';
$rejection_reason = $is_rejected ? ($rejection['instructor_comments'] ?? '') : '';

// Check if student has already signed for this week
$student_signed = false;
if ($rejection && !empty($rejection['student_signature_type']) && !empty($rejection['student_signature_value'])) {
    $student_signed = true;
}
// When rejected, allow re-signing
if ($is_rejected) {
    $student_signed = false;
}

// ══════════════════════════════════════════════════════════════════════
// FETCH PUBLIC HOLIDAYS (Myanmar Calendar)
// ══════════════════════════════════════════════════════════════════════
$all_holidays = [];
$hol_r = $conn->query("SELECT holiday_date, holiday_name FROM holidays ORDER BY holiday_date ASC");
if ($hol_r) { while ($row = $hol_r->fetch_assoc()) { $all_holidays[] = $row; } }
$holiday_dates = [];
foreach ($all_holidays as $hl) { $holiday_dates[$hl['holiday_date']] = $hl['holiday_name']; }

// ══════════════════════════════════════════════════════════════════════
// WARNING AUTO-CLEAR: Only clear when student submits a NEW log today
// (i.e., $message === 'daily_saved' means a log was just saved this request)
// ══════════════════════════════════════════════════════════════════════
if ($is_warned && $message === 'daily_saved') {
    $conn->query("UPDATE users SET is_warned = 0 WHERE id = {$esc_uid}");
    $is_warned = 0;
}

// ══════════════════════════════════════════════════════════════════════
// HANDLE NOTIFICATION MARK-AS-READ (AJAX via api/notifications.php)
// ══════════════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════════════
// FETCH NOTIFICATIONS
// ══════════════════════════════════════════════════════════════════════
$unread_notif_r = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$esc_uid} AND is_read = 0");
$unread_notif_count = ($unread_notif_r && $unread_notif_r->num_rows > 0) ? (int) $unread_notif_r->fetch_row()[0] : 0;

$recent_notifs_r = $conn->query("SELECT * FROM notifications WHERE user_id = {$esc_uid} ORDER BY created_at DESC LIMIT 10");
$recent_notifications = [];
if ($recent_notifs_r) { while ($row = $recent_notifs_r->fetch_assoc()) { $recent_notifications[] = $row; } }

// Fetch latest unread approval notification for banner display
$approval_notif_r = $conn->query("SELECT * FROM notifications WHERE user_id = {$esc_uid} AND type = 'instructor_approved' AND is_read = 0 ORDER BY created_at DESC LIMIT 1");
$approval_notif = ($approval_notif_r && $approval_notif_r->num_rows > 0) ? $approval_notif_r->fetch_assoc() : null;

// Rejected status overrides lock conditions — student can always edit when rejected
if ($is_rejected) {
    $reflection_unlocked = true;
    $magic_link_unlocked = true;
} else {
    // Normal flow: daily logs + reflection + signature required
    $magic_link_unlocked = $reflection_unlocked && $reflection_submitted && $student_signed;
}

// Handle student signature POST (separate step before magic link)
if ($reflection_submitted && isset($_POST['save_student_signature'])) {
    $sig_type = $_POST['student_signature_type'] ?? '';
    $sig_val  = null;

    if ($sig_type === 'typed' && !empty(trim($_POST['student_typed_name'] ?? ''))) {
        $sig_val = trim($_POST['student_typed_name']);
    } elseif ($sig_type === 'uploaded' && isset($_FILES['student_signature_file']) && $_FILES['student_signature_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['student_signature_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && $file['size'] <= 2 * 1024 * 1024) {
            $safe_name = 'std_sig_' . $internship_id . '_w' . $selected_week . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = __DIR__ . '/../uploads/signatures/' . $safe_name;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $sig_val = $safe_name;
            }
        }
    }

    if (!empty($sig_val)) {
        $esc_st = $conn->real_escape_string($sig_type);
        $esc_sv = $conn->real_escape_string($sig_val);
        $conn->query("INSERT INTO report_evaluations (student_id, week_number, grade, comment, student_signature_type, student_signature_value, report_status)
            VALUES ({$esc_iid}, {$esc_sw}, 'needs_improvement', '', '{$esc_st}', '{$esc_sv}', 'pending')
            ON DUPLICATE KEY UPDATE
            student_signature_type = VALUES(student_signature_type),
            student_signature_value = VALUES(student_signature_value),
            report_status = 'pending', evaluated_at = NOW()");
        $message = 'signature_saved';

        $rej_r = $conn->query("SELECT report_status, instructor_comments, student_signature_type, student_signature_value FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_sw}");
        $rejection = ($rej_r && $rej_r->num_rows > 0) ? $rej_r->fetch_assoc() : null;
        $is_rejected = $rejection && $rejection['report_status'] === 'rejected';
        $student_signed = false;
        if ($rejection && !empty($rejection['student_signature_type']) && !empty($rejection['student_signature_value'])) {
            $student_signed = true;
        }
        if ($is_rejected) {
            $student_signed = false;
        }
        if ($is_rejected) {
            $magic_link_unlocked = true;
        } else {
            $magic_link_unlocked = $reflection_unlocked && $reflection_submitted && $student_signed;
        }
    } else {
        $message = 'student_sig_required';
    }
}

// Handle magic link generation POST (requires student signature already saved)
if ($magic_link_unlocked && isset($_POST['generate_magic_link'])) {
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $esc_token = $conn->real_escape_string($token);
    $esc_exp   = $conn->real_escape_string($expires_at);

    $conn->query("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
        VALUES ({$esc_iid}, {$esc_sw}, '{$esc_token}', '{$esc_exp}')
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");

    $magic_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
        . '/instructor/view-report.php?token=' . $token;
}

$reflections_r = $conn->query("SELECT * FROM weekly_reflections WHERE internship_id = {$esc_iid} AND week_number = {$esc_sw} ORDER BY week_number DESC");
$weekly_refs = [];
if ($reflections_r) { while ($row = $reflections_r->fetch_assoc()) { $weekly_refs[] = $row; } }

// ══════════════════════════════════════════════════════════════════════
// WORKFLOW STATUS CHAIN DATA (for current week)
// Steps: Logs → Sign → Instructor → Supervisor
// ══════════════════════════════════════════════════════════════════════

// Step 1: Logs count for current week
$wf_step1_done = $total_weekdays > 0 && $weekly_log_count >= $total_weekdays;

// Step 2: Reflection submitted
$wf_step2_done = $reflection_submitted;

// Step 3: Student signed + magic link generated
$wf_step3_done = $student_signed;
$wf_has_link   = !empty($existing_link);

// Step 4: Instructor evaluated
$wf_step4_status = 'pending'; // pending | approved | rejected
if ($rejection) {
    if ($rejection['report_status'] === 'approved_by_instructor' || $rejection['report_status'] === 'approved_by_supervisor') {
        $wf_step4_status = 'approved';
    } elseif ($rejection['report_status'] === 'rejected') {
        $wf_step4_status = 'rejected';
    }
}

// Step 5: Supervisor graded
$wf_step5_done = false;
$sup_eval_r2 = $conn->query("SELECT weekly_grade FROM supervisor_weekly_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_sw} LIMIT 1");
if ($sup_eval_r2 && $sup_eval_r2->num_rows > 0) {
    $wf_step5_done = true;
}

// ══════════════════════════════════════════════════════════════════════
// ANALYTICS DATA FOR DASHBOARD ENHANCEMENTS
// ══════════════════════════════════════════════════════════════════════

// Total hours logged
$hours_r = $conn->query("SELECT calculated_duration FROM daily_logs WHERE internship_id = {$esc_iid}");
$all_durations = [];
if ($hours_r) { while ($row = $hours_r->fetch_assoc()) { $all_durations[] = $row['calculated_duration']; } }
$total_minutes = 0;
foreach ($all_durations as $dur) {
    $parts = explode(':', $dur);
    if (count($parts) === 2) {
        $total_minutes += ((int)$parts[0] * 60) + (int)$parts[1];
    }
}
$total_hours = floor($total_minutes / 60);
$total_mins  = $total_minutes % 60;

// Total logs count
$total_logs_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid}");
$total_logs_count = ($total_logs_r && $total_logs_r->num_rows > 0) ? (int) $total_logs_r->fetch_row()[0] : 0;

// Total reflections count
$total_ref_r = $conn->query("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = {$esc_iid}");
$total_reflections_count = ($total_ref_r && $total_ref_r->num_rows > 0) ? (int) $total_ref_r->fetch_row()[0] : 0;

// Weeks completed (custom Sun→Sat weeks with at least 1 log)
$weeks_completed = 0;
if (!empty($weeks)) {
    foreach ($weeks as $wn => $wr) {
        $esc_wk_s = $conn->real_escape_string($wr['start']);
        $esc_wk_e = $conn->real_escape_string($wr['end']);
        $wc_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_wk_s}' AND '{$esc_wk_e}'");
        if ($wc_r && $wc_r->num_rows > 0 && (int) $wc_r->fetch_row()[0] > 0) {
            $weeks_completed++;
        }
    }
}

// Total internship weeks
$total_weeks = count($weeks);

// Attendance rate
$attendance_rate = ($present_count + $absent_count) > 0 ? round(($present_count / ($present_count + $absent_count)) * 100) : 0;

// Weekly hours data for chart (last 8 weeks)
$weekly_hours_data = [];
$weekly_hours_labels = [];
if (!empty($weeks)) {
    $chart_weeks = array_slice($weeks, -8, 8, true);
    foreach ($chart_weeks as $cw_num => $cw_range) {
        $esc_cw_s = $conn->real_escape_string($cw_range['start']);
        $esc_cw_e = $conn->real_escape_string($cw_range['end']);
        $wh_r = $conn->query("SELECT calculated_duration FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_cw_s}' AND '{$esc_cw_e}'");
        $week_mins = 0;
        if ($wh_r) {
            while ($row = $wh_r->fetch_assoc()) {
                $p = explode(':', $row['calculated_duration']);
                if (count($p) === 2) $week_mins += ((int)$p[0] * 60) + (int)$p[1];
            }
        }
        $weekly_hours_labels[] = 'Week ' . $cw_num;
        $weekly_hours_data[] = round($week_mins / 60, 1);
    }
}

// Attendance breakdown for donut chart (all weeks)
$att_all_r = $conn->query("SELECT attendance_status, COUNT(*) as cnt FROM daily_logs WHERE internship_id = {$esc_iid} GROUP BY attendance_status");
$att_breakdown = [];
if ($att_all_r) { while ($row = $att_all_r->fetch_assoc()) { $att_breakdown[$row['attendance_status']] = (int) $row['cnt']; } }

// Recent activity (last 5 logs)
$recent_activity_r = $conn->query("SELECT log_date, attendance_status, task_title, calculated_duration FROM daily_logs WHERE internship_id = {$esc_iid} ORDER BY log_date DESC LIMIT 5");
$recent_activities = [];
if ($recent_activity_r) { while ($row = $recent_activity_r->fetch_assoc()) { $recent_activities[] = $row; } }

// Notifications
$notif_r = $conn->query("SELECT * FROM report_evaluations WHERE student_id = {$esc_iid} ORDER BY evaluated_at DESC LIMIT 5");
$recent_evaluations = [];
if ($notif_r) { while ($row = $notif_r->fetch_assoc()) { $recent_evaluations[] = $row; } }

// Supervisor weekly evaluations
$sup_eval_r = $conn->query("SELECT * FROM supervisor_weekly_evaluations WHERE student_id = {$esc_iid} ORDER BY week_number DESC LIMIT 5");
$sup_evaluations = [];
if ($sup_eval_r) { while ($row = $sup_eval_r->fetch_assoc()) { $sup_evaluations[] = $row; } }

// Fetch existing valid token for the selected week
$active_token_r = $conn->query("SELECT token, expires_at FROM magic_links WHERE internship_id = {$esc_iid} AND week_number = {$esc_sw} AND expires_at > NOW() LIMIT 1");
$existing_link = ($active_token_r && $active_token_r->num_rows > 0) ? $active_token_r->fetch_assoc() : null;
if ($existing_link && !$magic_link) {
    $magic_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
        . '/instructor/view-report.php?token=' . $existing_link['token'];
}

// Auto-generate magic link when all conditions are met and no active link exists
if ($magic_link_unlocked && empty($magic_link)) {
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $esc_token = $conn->real_escape_string($token);
    $esc_exp   = $conn->real_escape_string($expires_at);

    $conn->query("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
        VALUES ({$esc_iid}, {$esc_sw}, '{$esc_token}', '{$esc_exp}')
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");

    $magic_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
        . '/instructor/view-report.php?token=' . $token;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard – Intern Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontSize: {
                    'micro': '0.5rem',
                    'caption': '0.6875rem',
                    'label': '0.8125rem',
                    'subtitle': '0.9375rem',
                },
            }
        }
    }
    </script>
    <script>
    function calcHours() {
        var start = document.getElementById('start_time').value;
        var end   = document.getElementById('end_time').value;
        if (start && end) {
            var s = start.split(':'), e = end.split(':');
            var sm = parseInt(s[0]) * 60 + parseInt(s[1]);
            var em = parseInt(e[0]) * 60 + parseInt(e[1]);
            if (em < sm) em += 1440;
            var diff = em - sm;
            var h = Math.floor(diff / 60);
            var m = diff % 60;
            var pad = function(n) { return n < 10 ? '0' + n : n; };
            document.getElementById('hours_display').value = pad(h) + ':' + pad(m);
            document.getElementById('hours_worked').value = pad(h) + ':' + pad(m);
        }
    }

    function calcHoursNow() { calcHours(); }

    function copyLink() {
        var input = document.getElementById('magic_link_input');
        if (!input || !input.value) return;
        navigator.clipboard.writeText(input.value).then(function () {
            var btn = document.getElementById('copy_btn');
            btn.textContent = '✓ Copied!';
            setTimeout(function () { btn.textContent = '📋 Copy Link'; }, 2000);
        });
    }

    function switchStudentSigType(type) {
        var typed  = document.getElementById('student-sig-typed-fields');
        var upload = document.getElementById('student-sig-upload-fields');
        var hidden = document.getElementById('student_sig_type_input');
        var btnT   = document.getElementById('btn-student-typed');
        var btnU   = document.getElementById('btn-student-uploaded');
        if (type === 'typed') {
            typed.classList.remove('hidden');
            upload.classList.add('hidden');
            hidden.value = 'typed';
            btnT.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
            btnU.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
        } else {
            typed.classList.add('hidden');
            upload.classList.remove('hidden');
            hidden.value = 'uploaded';
            btnU.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
            btnT.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
        }
    }

    function previewStudentSig() {
        var name = document.getElementById('student_typed_name').value;
        var el   = document.getElementById('student_sig_preview');
        if (el) el.textContent = name || '—';
    }

    // ── Left-column signature form functions ──
    function switchLeftSigType(type) {
        var typed  = document.getElementById('left-sig-typed-fields');
        var upload = document.getElementById('left-sig-upload-fields');
        var hidden = document.getElementById('left-sig-type-input');
        var btnT   = document.getElementById('left-btn-typed');
        var btnU   = document.getElementById('left-btn-uploaded');
        if (type === 'typed') {
            typed.classList.remove('hidden');
            upload.classList.add('hidden');
            hidden.value = 'typed';
            btnT.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
            btnU.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
        } else {
            typed.classList.add('hidden');
            upload.classList.remove('hidden');
            hidden.value = 'uploaded';
            btnU.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
            btnT.className = 'flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
        }
    }

    function previewLeftSig() {
        var name = document.getElementById('left_typed_name').value;
        var el   = document.getElementById('left_sig_preview');
        if (el) el.textContent = name || '—';
    }

    function showProfile() {
        document.getElementById('section-profile').classList.remove('hidden');
        document.getElementById('section-main').classList.add('hidden');
        document.querySelectorAll('.nav-link').forEach(function (el) { el.classList.remove('active-nav'); });
        var link = document.querySelector('[data-section="profile"]');
        if (link) link.classList.add('active-nav');
    }

    function showDashboard() {
        document.getElementById('section-profile').classList.add('hidden');
        document.getElementById('section-main').classList.remove('hidden');
        document.querySelectorAll('.nav-link').forEach(function (el) { el.classList.remove('active-nav'); });
        var link = document.querySelector('[data-section="dashboard"]');
        if (link) link.classList.add('active-nav');
    }

    function showInstructions() {
        window.location.href = 'instructions.php';
    }

    function toggleWeekDropdown() {
        document.getElementById('week-menu').classList.toggle('hidden');
    }

    var internStart = <?= $intern_start ? "'" . htmlspecialchars($intern_start) . "'" : 'null' ?>;
    var internEnd   = <?= $intern_end ? "'" . htmlspecialchars($intern_end) . "'" : 'null' ?>;
    var weekStart   = <?= !empty($weeks[$selected_week]) ? "'" . htmlspecialchars($weeks[$selected_week]['start']) . "'" : 'null' ?>;
    var weekEnd     = <?= !empty($weeks[$selected_week]) ? "'" . htmlspecialchars($weeks[$selected_week]['end']) . "'" : 'null' ?>;
    var selectedWeek = <?= (int) $selected_week ?>;
    var existingLogs = <?= json_encode($existing_log_dates, JSON_HEX_TAG) ?>;
    var existingSet  = {};
    existingLogs.forEach(function (d) { existingSet[d] = true; });

    // Public holidays (Myanmar Calendar)
    var holidays = <?= json_encode($holiday_dates, JSON_HEX_TAG) ?>;
    function isHoliday(d) { return holidays.hasOwnProperty(d); }
    function holidayName(d) { return holidays[d] || ''; }

    // ── Date format helpers (DD.MM.YYYY ↔ YYYY-MM-DD) ──
    function parseDisplayDate(str) {
        // "13.07.2026" → "2026-07-13"
        if (!/^\d{4}-\d{2}-\d{2}$/.test(str)) return null;
        var parts = str.split('-');
        var date = new Date(str + 'T00:00:00');
        if (isNaN(date.getTime()) || date.getFullYear() !== Number(parts[0]) || date.getMonth() + 1 !== Number(parts[1]) || date.getDate() !== Number(parts[2])) return null;
        return str;
    }

    function formatDisplayDate(iso) {
        // "2026-07-13" → "13.07.2026"
        return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB');
    }

    function validateLogDate(e) {
        var dateInput = document.getElementById('log_date');
        if (!dateInput || !dateInput.value) return true;
        var iso = parseDisplayDate(dateInput.value);
        if (!iso) {
            showToast('Please select a valid date from the calendar.', 'error');
            e.preventDefault();
            return false;
        }
        if (internStart && iso < internStart) {
            showToast('Date cannot be before your internship start date (' + formatDisplayDate(internStart) + ').', 'error');
            e.preventDefault();
            return false;
        }
        if (internEnd && iso > internEnd) {
            showToast('Date cannot be after your internship end date (' + formatDisplayDate(internEnd) + ').', 'error');
            e.preventDefault();
            return false;
        }
        // Check if date falls within the selected week
        if (weekStart && iso < weekStart) {
            showToast('You cannot choose this date. Please select a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').', 'error');
            e.preventDefault();
            return false;
        }
        if (weekEnd && iso > weekEnd) {
            showToast('You cannot choose this date. Please select a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').', 'error');
            e.preventDefault();
            return false;
        }
        // Check if date falls on a weekend
        var dayOfWeek = new Date(iso + 'T00:00:00').getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            showToast('Weekend days (Saturday & Sunday) are not available for daily logs. Please select a weekday.', 'error');
            e.preventDefault();
            return false;
        }
        // Check if a daily log already exists for this date
        if (existingSet[iso]) {
            showToast('A daily log for ' + formatDisplayDate(dateInput.value) + ' already exists. Please choose a different date.', 'error');
            e.preventDefault();
            return false;
        }
        // Auto-set leave for public holidays
        if (isHoliday(iso)) {
            var leaveRadio = document.querySelector('input[name="attendance_status"][value="absent"]');
            if (leaveRadio) { leaveRadio.checked = true; toggleAttendance(); }
            var reasonField = document.querySelector('textarea[name="reason_for_absence"]');
            if (reasonField && !reasonField.value) { reasonField.value = 'Public Holiday - ' + holidayName(iso); }
        }
        return true;
    }

    // ── JS Week Number Calculator (mirrors PHP getInternshipWeekNumber) ──
    function calcInternshipWeek(startDate, selectedDate) {
        var start = new Date(startDate + 'T00:00:00');
        var sel   = new Date(selectedDate + 'T00:00:00');
        if (sel < start) return 0;

        // Find end of Week 1: next Saturday on or after start
        var dayOfWeek = start.getDay(); // 0=Sun, 6=Sat
        var daysToSat;
        if (dayOfWeek === 6) {
            daysToSat = 0;
        } else {
            daysToSat = (6 - dayOfWeek + 7) % 7;
        }
        var endOfWeek1 = new Date(start);
        endOfWeek1.setDate(endOfWeek1.getDate() + daysToSat);

        if (sel <= endOfWeek1) return 1;

        var diffMs  = sel.getTime() - endOfWeek1.getTime();
        var diffDays = Math.round(diffMs / 86400000);
        return 1 + Math.ceil(diffDays / 7);
    }

    function updateWeekBadge() {
        var display   = document.getElementById('log_date');
        var badge     = document.getElementById('week-badge');
        var badgeNum  = document.getElementById('week-badge-num');
        if (!display) return;

        var dayLabel = document.getElementById('selected-day');
        var iso = parseDisplayDate(display.value);
        if (!iso) {
            badge.classList.add('hidden');
            if (dayLabel) dayLabel.textContent = 'Choose a date from the calendar.';
            return;
        }

        if (dayLabel) {
            dayLabel.textContent = new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }

        if (!badge || !badgeNum || !internStart) return;

        var wn = calcInternshipWeek(internStart, iso);
        if (wn > 0) {
            badgeNum.textContent = wn;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function toggleAttendance() {
        var status = document.querySelector('input[name="attendance_status"]:checked').value;
        var present = document.getElementById('present-fields');
        var absent  = document.getElementById('absent-fields');
        if (status === 'absent') {
            present.classList.add('hidden');
            absent.classList.remove('hidden');
        } else {
            present.classList.remove('hidden');
            absent.classList.add('hidden');
        }
    }

    function showToast(message, type) {
        var toast = document.createElement('div');
        var bgColor, icon;
        switch (type) {
            case 'success':
                bgColor = 'bg-emerald-600';
                icon = '✓';
                break;
            case 'error':
                bgColor = 'bg-red-600';
                icon = '✕';
                break;
            case 'warning':
                bgColor = 'bg-amber-500';
                icon = '⚠';
                break;
            default:
                bgColor = 'bg-slate-700';
                icon = 'ℹ';
        }
        toast.className = 'fixed bottom-6 right-6 z-[1000] ' + bgColor + ' text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        toast.innerHTML = '<span class="text-base">' + icon + '</span> ' + message;
        document.body.appendChild(toast);
        requestAnimationFrame(function() {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }

    window.onload = function () {
        document.addEventListener('click', function (e) {
            var dd = document.getElementById('week-dropdown');
            if (dd && !dd.contains(e.target)) {
                document.getElementById('week-menu').classList.add('hidden');
            }
        });
        // Update week badge when date changes
        var logDateInput = document.getElementById('log_date');
        if (logDateInput) {
            logDateInput.addEventListener('change', function () {
                updateWeekBadge();
                var iso = this.value;
                if (iso && existingSet[iso]) {
                    showToast('A daily log for ' + formatDisplayDate(this.value) + ' already exists. Please choose a different date.', 'error');
                    this.value = '';
                    updateWeekBadge();
                    return;
                }
                if (iso && weekStart && iso < weekStart) {
                    showToast('You cannot choose this date. Please select a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').', 'error');
                    this.value = '';
                    updateWeekBadge();
                    return;
                }
                if (iso && weekEnd && iso > weekEnd) {
                    showToast('You cannot choose this date. Please select a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').', 'error');
                    this.value = '';
                    updateWeekBadge();
                    return;
                }
                // Auto-set to "leave" for public holidays
                if (iso && isHoliday(iso)) {
                    var leaveRadio = document.querySelector('input[name="attendance_status"][value="absent"]');
                    if (leaveRadio) { leaveRadio.checked = true; toggleAttendance(); }
                    var reasonField = document.querySelector('textarea[name="reason_for_absence"]');
                    if (reasonField) { reasonField.value = 'Public Holiday - ' + holidayName(iso); }
                    showToast('This date is a public holiday (' + holidayName(iso) + '). Attendance will be marked as Leave.', 'warning');
                }
            });
            updateWeekBadge();
        }
        <?php if ($message === 'daily_saved'): ?>
        showToast('Daily log saved successfully.', 'success');
        <?php elseif ($message === 'log_updated'): ?>
        showToast('Daily log updated successfully.', 'success');
        <?php elseif ($message === 'log_deleted'): ?>
        showToast('Daily log deleted successfully.', 'success');
        <?php elseif ($message === 'reflection_saved'): ?>
        showToast('Weekly reflection saved successfully.', 'success');
        <?php elseif ($message === 'student_sig_required'): ?>
        showToast('Please provide your signature before generating the link.', 'error');
        <?php elseif ($message === 'signature_saved'): ?>
        showToast('Student signature saved successfully.', 'success');
        <?php elseif ($message === 'date_out_of_range'): ?>
        showToast('The selected date is outside your internship period. Please choose a valid date.', 'error');
        <?php elseif ($message === 'date_out_of_week'): ?>
        showToast('You cannot choose this date. The date must be within the selected week.', 'error');
        <?php elseif ($message === 'date_is_weekend'): ?>
        showToast('Weekend days (Saturday & Sunday) are not available for daily logs. Please select a weekday.', 'error');
        <?php elseif ($message === 'log_locked'): ?>
        showToast('This week has been signed and cannot be edited. Wait for instructor rejection to make changes.', 'error');
        <?php endif; ?>
    };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Dancing+Script:wght@400;700&family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
    .nav-link { color: rgba(255,255,255,0.55); font-weight: 500; }
    .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
    .active-nav { background: #9333ea; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(147,51,234,0.3); }
    .student-sig-preview { font-family: 'Great Vibes', cursive; font-size: 22px; color: #1e293b; min-height: 36px; line-height: 1.4; }

    /* ── Glassmorphism Utilities ── */
    .glass { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); }
    .glass-strong { background: rgba(255,255,255,0.72); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.5); }
    .glass-input { background: rgba(255,255,255,0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.5); }
    .glass-input:focus { background: rgba(255,255,255,0.85); border-color: rgba(139,92,246,0.5); box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
    .glass-sidebar { background: rgba(15,23,42,0.82); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-right: 1px solid rgba(255,255,255,0.08); }
    .glass-header { background: rgba(255,255,255,0.6); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid rgba(255,255,255,0.4); }
    .glass-card { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); box-shadow: 0 8px 32px rgba(0,0,0,0.06); }
    .glass-card:hover { background: rgba(255,255,255,0.68); box-shadow: 0 8px 32px rgba(0,0,0,0.1); }
    .glass-modal { background: rgba(255,255,255,0.85); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.6); }
    .glass-badge { background: rgba(255,255,255,0.25); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.3); }

    .glow-indigo { box-shadow: 0 4px 20px rgba(99,102,241,0.25); }
    .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .glow-emerald { box-shadow: 0 4px 20px rgba(16,185,129,0.25); }
    .glow-purple { box-shadow: 0 4px 20px rgba(168,85,247,0.25); }
    .glow-blue { box-shadow: 0 4px 20px rgba(59,130,246,0.25); }
    .glow-amber { box-shadow: 0 4px 20px rgba(245,158,11,0.25); }
    .glow-red { box-shadow: 0 4px 20px rgba(239,68,68,0.25); }

    @keyframes gradientShift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }
    .animated-bg {
        background: linear-gradient(-45deg, #e0e7ff, #ede9fe, #fce7f3, #dbeafe, #d1fae5);
        background-size: 400% 400%;
        animation: gradientShift 20s ease infinite;
    }

    @media print {
        aside, header, .no-print { display: none !important; }
        .flex.h-screen { height: auto !important; overflow: visible !important; }
        main { overflow: visible !important; }
        #section-main { display: block !important; }
        body { background: white !important; }
        .glass, .glass-card, .glass-strong, .glass-header, .glass-sidebar, .glass-modal { background: white !important; backdrop-filter: none !important; border-color: #e2e8f0 !important; box-shadow: none !important; }
    }
    </style>
</head>
<body class="animated-bg font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 glass-sidebar flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-white/10">
            <span class="font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-3">
            <a href="#" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="dashboard" onclick="showDashboard()">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📝</span> Dashboard
            </a>
            <a href="analytics.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="analytics">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Analytics
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📅</span> Intern Period Calendar
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" onclick="showInstructions(); return false;">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📋</span> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'Dashboard'; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">

            <!-- ════ STUDENT INFO BAR ════ -->
            <div class="glass-card rounded-2xl p-5 mb-6">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- Supervisor Card -->
                    <div class="flex items-center gap-3 bg-white/40 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/40">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm font-bold shrink-0">
                            <?= strtoupper(substr($supervisor_name, 0, 1)) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-label font-bold text-slate-400 uppercase tracking-wider">Supervisor</p>
                            <p class="font-bold text-slate-700 truncate"><?= htmlspecialchars($supervisor_name) ?></p>
                        </div>
                    </div>
                    <!-- Instructor Card -->
                    <div class="flex items-center gap-3 bg-white/40 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/40">
                        <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-sm font-bold shrink-0">
                            <?= strtoupper(substr($instructor_name, 0, 1)) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-label font-bold text-slate-400 uppercase tracking-wider">Instructor</p>
                            <p class="font-bold text-slate-700 truncate"><?= htmlspecialchars($instructor_name) ?></p>
                        </div>
                    </div>
                    <!-- Internship Period Card -->
                    <?php if ($intern_start && $intern_end): ?>
                    <div class="flex items-center gap-3 bg-violet-50/50 backdrop-blur-sm rounded-xl px-4 py-3 border border-violet-200/50">
                        <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                        <div class="min-w-0">
                            <p class="text-label font-bold text-indigo-400 uppercase tracking-wider">Internship Period</p>
                            <p class="font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ════ WORKFLOW STATUS CHAIN (Current Week) ════ -->
            <div class="glass-card rounded-2xl p-5 mb-6">
                <h3 class="text-caption font-bold text-slate-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🔗</span> Week <?= $selected_week ?> Workflow Status
                </h3>
                <div class="flex items-center justify-between gap-1">
                    <!-- Step 1: Daily Logs -->
                    <div class="flex flex-col items-center flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-full <?= $wf_step1_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-white/40 text-slate-400 border-2 border-dashed border-slate-300/60' ?> flex items-center justify-center text-sm font-bold transition-all duration-300">
                            <?= $wf_step1_done ? '✅' : '📝' ?>
                        </div>
                        <p class="text-label font-bold text-slate-600 mt-2 text-center leading-tight">Daily Logs</p>
                        <p class="text-caption text-slate-400 text-center"><?= $weekly_log_count ?>/3 min</p>
                    </div>
                    <div class="flex-1 h-0.5 <?= $wf_step2_done ? 'bg-emerald-400' : 'bg-slate-200/60 border-dashed border-slate-300/60' ?> rounded-full mx-1 mt-[-22px]"></div>
                    <!-- Step 2: Reflection -->
                    <div class="flex flex-col items-center flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-full <?= $wf_step2_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-white/40 text-slate-400 border-2 border-dashed border-slate-300/60' ?> flex items-center justify-center text-sm font-bold transition-all duration-300">
                            <?= $wf_step2_done ? '✅' : '📊' ?>
                        </div>
                        <p class="text-label font-bold text-slate-600 mt-2 text-center leading-tight">Reflection</p>
                        <p class="text-caption text-slate-400 text-center"><?= $wf_step2_done ? 'Done' : 'Pending' ?></p>
                    </div>
                    <div class="flex-1 h-0.5 <?= $wf_step3_done ? 'bg-emerald-400' : 'bg-slate-200/60 border-dashed border-slate-300/60' ?> rounded-full mx-1 mt-[-22px]"></div>
                    <!-- Step 3: Sign & Submit -->
                    <div class="flex flex-col items-center flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-full <?= $wf_step3_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : ($wf_has_link ? 'bg-amber-400 text-white shadow-lg glow-amber' : 'bg-white/40 text-slate-400 border-2 border-dashed border-slate-300/60') ?> flex items-center justify-center text-sm font-bold transition-all duration-300">
                            <?= $wf_step3_done ? '✅' : '✍️' ?>
                        </div>
                        <p class="text-label font-bold text-slate-600 mt-2 text-center leading-tight">Sign & Submit</p>
                        <p class="text-caption text-slate-400 text-center"><?= $wf_step3_done ? 'Done' : ($wf_has_link ? 'Link sent' : 'Pending') ?></p>
                    </div>
                    <div class="flex-1 h-0.5 <?= $wf_step4_status === 'approved' ? 'bg-emerald-400' : ($wf_step4_status === 'rejected' ? 'bg-red-400' : 'bg-slate-200/60 border-dashed border-slate-300/60') ?> rounded-full mx-1 mt-[-22px]"></div>
                    <!-- Step 4: Instructor Review -->
                    <div class="flex flex-col items-center flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-full <?= $wf_step4_status === 'approved' ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : ($wf_step4_status === 'rejected' ? 'bg-red-500 text-white shadow-lg glow-red' : 'bg-white/40 text-slate-400 border-2 border-dashed border-slate-300/60') ?> flex items-center justify-center text-sm font-bold transition-all duration-300">
                            <?= $wf_step4_status === 'approved' ? '✅' : ($wf_step4_status === 'rejected' ? '❌' : '👨‍🏫') ?>
                        </div>
                        <p class="text-label font-bold text-slate-600 mt-2 text-center leading-tight">Instructor</p>
                        <p class="text-caption text-slate-400 text-center"><?= $wf_step4_status === 'approved' ? 'Approved' : ($wf_step4_status === 'rejected' ? 'Rejected' : 'Pending') ?></p>
                    </div>
                    <div class="flex-1 h-0.5 <?= $wf_step5_done ? 'bg-emerald-400' : 'bg-slate-200/60 border-dashed border-slate-300/60' ?> rounded-full mx-1 mt-[-22px]"></div>
                    <!-- Step 5: Supervisor Grade -->
                    <div class="flex flex-col items-center flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-full <?= $wf_step5_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-white/40 text-slate-400 border-2 border-dashed border-slate-300/60' ?> flex items-center justify-center text-sm font-bold transition-all duration-300">
                            <?= $wf_step5_done ? '✅' : '👩‍🏫' ?>
                        </div>
                        <p class="text-label font-bold text-slate-600 mt-2 text-center leading-tight">Supervisor</p>
                        <p class="text-caption text-slate-400 text-center"><?= $wf_step5_done ? 'Graded' : 'Pending' ?></p>
                    </div>
                </div>
            </div>

            <!-- ════ STATISTICS CARDS ════ -->
            <div class="w-full grid grid-cols-1 md:grid-cols-12 gap-6 mb-6">
                <!-- Total Hours -->
                <div class="md:col-span-3 h-full">
                    <div class="glass-card rounded-2xl p-6 h-full transition-all duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-50/80 text-blue-500 flex items-center justify-center text-lg">⏱️</div>
                            <span class="text-label font-bold text-emerald-600 bg-emerald-50/60 px-2 py-0.5 rounded-full border border-emerald-200/50">Logged</span>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $total_hours ?>h <?= $total_mins ?>m</p>
                        <p class="text-caption text-slate-400 font-medium mt-1">Total Hours Worked</p>
                    </div>
                </div>
                <!-- Daily Logs -->
                <div class="md:col-span-3 h-full">
                    <div class="glass-card rounded-2xl p-6 h-full transition-all duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-violet-50/80 text-violet-500 flex items-center justify-center text-lg">📋</div>
                            <span class="text-label font-bold text-blue-600 bg-blue-50/60 px-2 py-0.5 rounded-full border border-blue-200/50">Entries</span>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $total_logs_count ?></p>
                        <p class="text-caption text-slate-400 font-medium mt-1">Daily Logs Submitted</p>
                    </div>
                </div>
                <!-- Attendance Rate -->
                <div class="md:col-span-3 h-full">
                    <div class="glass-card rounded-2xl p-6 h-full transition-all duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-50/80 text-emerald-500 flex items-center justify-center text-lg">✅</div>
                            <span class="text-label font-bold <?= $attendance_rate >= 80 ? 'text-emerald-600 bg-emerald-50/60 border border-emerald-200/50' : 'text-amber-600 bg-amber-50/60 border border-amber-200/50' ?> px-2 py-0.5 rounded-full"><?= $attendance_rate >= 80 ? 'Good' : 'Watch' ?></span>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $attendance_rate ?>%</p>
                        <p class="text-caption text-slate-400 font-medium mt-1">Attendance Rate</p>
                    </div>
                </div>
                <!-- Weeks Completed -->
                <div class="md:col-span-3 h-full">
                    <div class="glass-card rounded-2xl p-6 h-full transition-all duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-50/80 text-purple-500 flex items-center justify-center text-lg">📆</div>
                            <span class="text-label font-bold text-purple-600 bg-purple-50/60 px-2 py-0.5 rounded-full border border-purple-200/50">Weeks</span>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $weeks_completed ?><span class="text-base font-bold text-slate-400">/<?= $total_weeks ?></span></p>
                        <p class="text-caption text-slate-400 font-medium mt-1">Weeks Completed</p>
                    </div>
                </div>
            </div>

            <!-- ════ PROGRESS OVERVIEW ════ -->
            <div class="glass-card rounded-2xl p-5 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-indigo-50 text-indigo-600 rounded">📈</span> Internship Progress
                    </h3>
                    <?php if ($intern_start && $intern_end):
                        $start_dt = new DateTime($intern_start);
                        $end_dt   = new DateTime($intern_end);
                        $now_dt   = new DateTime();
                        $total_days = $start_dt->diff($end_dt)->days;
                        $elapsed_days = max(0, $start_dt->diff($now_dt)->days);
                        $progress_pct = min(round(($elapsed_days / max($total_days, 1)) * 100), 100);
                    ?>
                    <span class="font-bold text-indigo-600"><?= $progress_pct ?>% Elapsed</span>
                    <?php endif; ?>
                </div>
                <div class="w-full bg-white/40 rounded-full h-3 mb-4">
                    <div class="bg-gradient-to-r from-violet-500 to-purple-500 rounded-full h-3 transition-all duration-700 ease-out shadow-sm" style="width: <?= $total_weeks > 0 ? min(round(($weeks_completed / $total_weeks) * 100), 100) : 0 ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-caption">
                    <div class="flex items-center gap-4">
                        <span class="flex items-center gap-1.5 text-slate-500 font-medium">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span> Weeks Logged: <strong class="text-slate-700"><?= $weeks_completed ?>/<?= $total_weeks ?></strong>
                        </span>
                        <span class="flex items-center gap-1.5 text-slate-500 font-medium">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Reflections: <strong class="text-slate-700"><?= $total_reflections_count ?></strong>
                        </span>
                    </div>
                    <?php if ($intern_start && $intern_end): ?>
                    <span class="text-slate-400 font-medium"><?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_rejected): ?>
            <!-- ════ REJECTION ALERT BANNER ════ -->
            <div class="bg-red-50/60 backdrop-blur-sm border border-red-200/60 rounded-2xl p-5 mb-6">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-lg shrink-0 mt-0.5">❌</div>
                    <div class="flex-1">
                        <h3 class="text-sm font-bold text-red-700 mb-1">Your report for Week <?= $selected_week ?> was rejected by the Instructor</h3>
                        <?php if ($rejection_reason): ?>
                        <div class="bg-white rounded-xl border border-red-100 p-3 mt-2">
                            <p class="text-label font-bold text-red-400 uppercase tracking-wider mb-1">Reason</p>
                            <p class="text-red-600 leading-relaxed"><?= nl2br(htmlspecialchars($rejection_reason)) ?></p>
                        </div>
                        <?php endif; ?>
                        <p class="text-caption text-red-500 mt-2.5">Please revise your daily logs and weekly reflection, then regenerate a new Magic Link to resubmit.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_warned): ?>
            <!-- ════ SUPERVISOR WARNING BANNER ════ -->
            <div class="bg-gradient-to-r from-amber-50 to-yellow-50 border border-amber-200 rounded-2xl p-5 mb-6 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-lg shrink-0 mt-0.5">⚠️</div>
                    <div class="flex-1">
                        <h3 class="font-bold text-amber-700 mb-1">Supervisor Warning</h3>
                        <p class="text-amber-600 leading-relaxed">You are behind schedule for this week. Please submit your daily logs immediately.</p>
                    </div>
                    <div class="shrink-0">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-100 text-amber-700 text-label font-bold rounded-full border border-amber-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            ACTION REQUIRED
                        </span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════ MAIN DASHBOARD (2-COLUMN LAYOUT) ══════ -->
            <section id="section-main">

                <!-- ══════ FILTER & TRACKER ROW ══════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <!-- Left: Week Dropdown + Clear -->
                        <div class="flex items-center gap-3">
                            <div class="relative" id="week-dropdown">
                                <button onclick="toggleWeekDropdown()" class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-100 transition cursor-pointer whitespace-nowrap">
                                    📆 Week <?= $selected_week ?>
                                    <span class="text-slate-400 text-label">▾</span>
                                </button>
                                <div id="week-menu" class="absolute left-0 top-full mt-1 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden overflow-hidden">
                                    <?php if (!empty($weeks)): ?>
                                        <?php foreach ($weeks as $wn => $wr): ?>
                                        <a href="?week=<?= $wn ?>" class="flex items-center justify-between px-3 py-2 font-semibold <?= $selected_week === $wn ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?> transition">
                                            Week <?= $wn ?>
                                            <span class="text-label text-slate-400"><?= $wr['start'] ?></span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="px-3 py-2 text-slate-400">No logs yet</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="student-dashboard.php?week=<?= $selected_week ?>" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold rounded-lg transition cursor-pointer">✕ Clear</a>
                        </div>

                        <!-- Center: Date Range Filter -->
                        <form method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="week" value="<?= $selected_week ?>">
                            <label class="text-label font-bold text-slate-400 uppercase tracking-wider">From</label>
                            <input type="date" name="filter_start" value="<?= htmlspecialchars($filter_start) ?>" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-slate-700 focus:outline-none focus:border-blue-500 transition">
                            <label class="text-label font-bold text-slate-400 uppercase tracking-wider">To</label>
                            <input type="date" name="filter_end" value="<?= htmlspecialchars($filter_end) ?>" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-slate-700 focus:outline-none focus:border-blue-500 transition">
                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg transition cursor-pointer">🔍 Filter</button>
                            <?php if ($filter_start || $filter_end): ?>
                            <a href="student-dashboard.php?week=<?= $selected_week ?>" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold rounded-lg transition cursor-pointer">✕</a>
                            <?php endif; ?>
                        </form>

                        <!-- Right: Attendance Counters with Tooltips -->
                        <div class="flex items-center gap-3">
                            <!-- Present Tooltip -->
                            <div class="relative group">
                                <div class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-100 rounded-lg cursor-pointer hover:bg-emerald-100 transition">
                                    <span class="text-caption font-bold text-emerald-600">✅ Present</span>
                                    <span class="text-sm font-black text-emerald-700"><?= $present_count ?> Days</span>
                                </div>
                                <div class="absolute right-0 top-full mt-2 w-56 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden group-hover:block">
                                    <div class="p-3">
                                        <p class="text-label font-bold text-slate-400 uppercase tracking-wider mb-2">All Present Dates</p>
                                        <div class="max-h-48 overflow-y-auto space-y-1 pr-1">
                                            <?php if (!empty($present_dates)): ?>
                                                <?php foreach ($present_dates as $date): ?>
                                                    <?php $d = new DateTime($date); ?>
                                                    <p class="text-slate-700">• <?= $d->format('D, M d, Y') ?></p>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <p class="text-slate-400">No present days recorded.</p>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-label text-slate-400 mt-2 pt-2 border-t border-slate-100">Total: <?= count($present_dates) ?> day<?= count($present_dates) !== 1 ? 's' : '' ?></p>
                                    </div>
                                </div>
                            </div>
                            <!-- Absent Tooltip -->
                            <div class="relative group">
                                <div class="flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-100 rounded-lg cursor-pointer hover:bg-red-100 transition">
                                    <span class="text-caption font-bold text-red-600">❌ Absent</span>
                                    <span class="text-sm font-black text-red-700"><?= $absent_count ?> Days</span>
                                </div>
                                <div class="absolute right-0 top-full mt-2 w-72 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden group-hover:block">
                                    <div class="p-3">
                                        <p class="text-label font-bold text-slate-400 uppercase tracking-wider mb-2">All Absent Dates</p>
                                        <div class="max-h-48 overflow-y-auto space-y-1 pr-1">
                                            <?php if (!empty($absent_logs)): ?>
                                                <?php foreach ($absent_logs as $log): ?>
                                                    <?php $d = new DateTime($log['log_date']); ?>
                                                    <p class="text-slate-700">• <?= $d->format('D, M d, Y') ?> — <?= htmlspecialchars($log['reason_for_absence'] ?: 'No reason') ?></p>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <p class="text-slate-400">No absences recorded.</p>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-label text-slate-400 mt-2 pt-2 border-t border-slate-100">Total: <?= count($absent_logs) ?> day<?= count($absent_logs) !== 1 ? 's' : '' ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="w-full space-y-6">

                    <!-- ─── ALL CONTENT: Full-Width Stack ─── -->
                    <div class="w-full space-y-6">

                        <!-- Daily Log Sheet Form -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 <?= $student_signed ? 'hidden' : '' ?>">
                            <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <span class="p-1 bg-blue-50 text-blue-600 rounded">📝</span> <?= $editing_log ? 'Edit Daily Log' : 'Daily Log Sheet' ?>
                                </span>
                                <?php if ($editing_log): ?>
                                <a href="student-dashboard.php?week=<?= $selected_week ?>" class="text-label font-bold text-red-500 bg-red-50 border border-red-100 px-2.5 py-1 rounded-full hover:bg-red-100 transition">✕ Cancel Edit</a>
                                <?php elseif ($week_date_range): ?>
                                <span class="flex items-center gap-1.5 text-label font-bold text-blue-600 bg-blue-50 border border-blue-100 px-2.5 py-1 rounded-full">
                                    📅 <?= $week_date_range ?>
                                </span>
                                <?php endif; ?>
                            </h2>
                            <form method="POST" class="space-y-4" onsubmit="return validateLogDate(event)">
                                <?php if ($editing_log): ?>
                                <input type="hidden" name="log_id" value="<?= $editing_log['id'] ?>">
                                <?php endif; ?>
                                <input type="hidden" name="selected_week" value="<?= (int) $selected_week ?>">
                                <!-- Date (always visible) -->
                                <div>
                                    <label class="block text-caption font-bold text-slate-500 mb-1">📅 Date / Day</label>
                                    <input type="date" name="log_date" id="log_date" required
                                        value="<?= htmlspecialchars($editing_log['log_date'] ?? '') ?>"
                                        min="<?= htmlspecialchars($intern_start ?? '') ?>"
                                        max="<?= htmlspecialchars($intern_end ?? '') ?>"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition cursor-pointer">
                                    <?php if (!empty($weeks[$selected_week])): ?>
                                    <p class="text-label text-slate-400 mt-1">Week <?= $selected_week ?> allowed: <?= (new DateTime($weeks[$selected_week]['start']))->format('d.m.Y') ?> – <?= (new DateTime($weeks[$selected_week]['end']))->format('d.m.Y') ?></p>
                                    <?php elseif ($intern_start && $intern_end): ?>
                                    <p class="text-label text-slate-400 mt-1">Allowed: <?= (new DateTime($intern_start))->format('d.m.Y') ?> – <?= (new DateTime($intern_end))->format('d.m.Y') ?></p>
                                    <?php endif; ?>
                                    <p id="selected-day" class="text-label text-slate-500 mt-1">Choose a date from the calendar.</p>
                                    <div id="week-badge" class="hidden mt-1.5">
                                        <span class="inline-flex items-center gap-1 text-label font-bold text-indigo-600 bg-indigo-50 border border-indigo-100 px-2 py-0.5 rounded-full">
                                            📆 Week <span id="week-badge-num">—</span>
                                        </span>
                                    </div>
                                </div>

                                <!-- Attendance Status -->
                                <div>
                                    <label class="block text-caption font-bold text-slate-500 mb-2">✅ Attendance Status <span class="text-slate-300 font-normal">/ တက်ရောက်မှုအခြေအနေ</span></label>
                                    <div class="flex items-center gap-4">
                                        <?php $edit_att = $editing_log['attendance_status'] ?? 'present'; ?>
                                        <label class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-lg cursor-pointer hover:bg-emerald-100 transition">
                                            <input type="radio" name="attendance_status" value="present" <?= $edit_att === 'present' ? 'checked' : '' ?> onchange="toggleAttendance()" class="accent-emerald-600">
                                            <span class="font-bold text-emerald-700">Present / တက်ရောက်</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 rounded-lg cursor-pointer hover:bg-red-100 transition">
                                            <input type="radio" name="attendance_status" value="absent" <?= $edit_att === 'absent' ? 'checked' : '' ?> onchange="toggleAttendance()" class="accent-red-600">
                                            <span class="font-bold text-red-700">Absent / ခွင့်/ပျက်ကွက်</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- ══════ PRESENT FIELDS ══════ -->
                                <div id="present-fields" class="<?= $edit_att === 'absent' ? 'hidden' : '' ?>">
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-caption font-bold text-slate-500 mb-1">⏱️ Start Time</label>
                                            <input type="time" name="start_time" id="start_time" value="<?= htmlspecialchars($editing_log && $editing_log['calculated_duration'] ? substr($editing_log['calculated_duration'], 0, 5) : '09:00') ?>" onchange="calcHours()" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                        </div>
                                        <div>
                                            <label class="block text-caption font-bold text-slate-500 mb-1">⏱️ End Time</label>
                                            <input type="time" name="end_time" id="end_time" value="17:00" onchange="calcHours()" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                        </div>
                                        <div>
                                            <label class="block text-caption font-bold text-slate-500 mb-1">⏳ Duration</label>
                                            <input type="text" id="hours_display" value="<?= htmlspecialchars($editing_log['calculated_duration'] ?? '08:00') ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 font-mono text-blue-700 font-bold focus:outline-none cursor-default">
                                            <input type="hidden" name="hours_worked" id="hours_worked" value="<?= htmlspecialchars($editing_log['calculated_duration'] ?? '08:00') ?>">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-caption font-bold text-slate-500 mb-1">💡 Intended Task <span class="text-slate-300 font-normal">/ ဆောင်ရွက်မည့်လုပ်ငန်း</span></label>
                                        <input type="text" name="intended_task" value="<?= htmlspecialchars($editing_log['task_title'] ?? '') ?>" placeholder="e.g. UI Design & API Integration" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                    </div>
                                    <div>
                                        <label class="block text-caption font-bold text-slate-500 mb-1">📋 Task Detail <span class="text-slate-300 font-normal">/ ဆောင်ရွက်မည့် လုပ်ငန်းစဉ်များ</span></label>
                                        <textarea name="task_detail" rows="3" placeholder="Describe the planned tasks in detail…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['task_detail'] ?? '') ?></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-caption font-bold text-slate-500 mb-1">✅ Actual Task Performed <span class="text-slate-300 font-normal">/ အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</span></label>
                                        <textarea name="actual_task" rows="3" placeholder="What you actually accomplished today…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['tasks_performed'] ?? '') ?></textarea>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-caption font-bold text-slate-500 mb-1">🛠️ Tools / Tech Used <span class="text-slate-300 font-normal">/ အသုံးပြုသောပစ္စည်းများ</span></label>
                                            <input type="text" name="tools_used" value="<?= htmlspecialchars($editing_log['tools_used'] ?? '') ?>" placeholder="PHP, TailwindCSS, MySQL…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 font-mono text-emerald-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                        </div>
                                        <div>
                                            <label class="block text-caption font-bold text-slate-500 mb-1">🧠 Knowledge Gained <span class="text-slate-300 font-normal">/ လေ့လာသိရှိသော အသိပညာ</span></label>
                                            <input type="text" name="knowledge_gained" value="<?= htmlspecialchars($editing_log['learnt_skills'] ?? '') ?>" placeholder="Database optimization, REST APIs…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                        </div>
                                    </div>
                                </div>

                                <!-- ══════ ABSENT FIELDS ══════ -->
                                <div id="absent-fields" class="<?= $edit_att === 'absent' ? '' : 'hidden' ?>">
                                    <div>
                                        <label class="block text-caption font-bold text-slate-500 mb-1">📝 Reason for Absence <span class="text-slate-300 font-normal">/ ခွင့်ယူရသည့်အကြောင်းအရင်း</span></label>
                                        <textarea name="reason_for_absence" rows="2" placeholder="Please state your reason for absence…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['reason_for_absence'] ?? '') ?></textarea>
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit" name="<?= $editing_log ? 'update_log' : 'add_log' ?>" class="px-5 py-2 <?= $editing_log ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-blue-600 hover:bg-blue-700' ?> text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer"> <?= $editing_log ? '✏️ Update Log' : '💾 Save Daily Log' ?></button>
                                </div>
                            </form>
                        </div>

                        <!-- Daily Log History -->
                        <?php include 'daily_logs_table.php'; ?>

                        <!-- Weekly Reflection Form -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 <?= $student_signed ? 'hidden' : (!$reflection_unlocked ? 'hidden' : '') ?>">
                            <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center gap-2">
                                <span class="p-1 bg-emerald-50 text-emerald-600 rounded">📊</span> Weekly Reflection
                                <?php if (!$reflection_unlocked): ?>
                                <span class="ml-auto flex items-center gap-1 text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded">🔒 Locked (<?= $weekly_log_count ?>/<?= $total_weekdays ?>)</span>
                                <?php else: ?>
                                <span class="ml-auto flex items-center gap-1 text-label font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ Unlocked (<?= $weekly_log_count ?>/<?= $total_weekdays ?>)</span>
                                <?php endif; ?>
                            </h2>
                            <?php if (!$reflection_unlocked): ?>
                            <p class="text-slate-400 text-center py-6">📋 Please complete all <strong><?= $total_weekdays ?> daily logs</strong> for <strong>Week <?= $selected_week ?></strong> to unlock this form. You currently have <strong><?= $weekly_log_count ?>/<?= $total_weekdays ?></strong>.</p>
                            <?php endif; ?>
                            <form method="POST" class="space-y-4 <?= !$reflection_unlocked ? 'hidden' : '' ?>">
                                <div>
                                    <label class="block text-caption font-bold text-slate-500 mb-1">📆 Week Number</label>
                                    <input type="number" name="week_number" value="<?= $selected_week ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-indigo-600 focus:outline-none cursor-default">
                                </div>
                                <div>
                                    <label class="block text-caption font-bold text-slate-500 mb-1">❓ What was done? <span class="text-slate-300 font-normal">/ ဘာလုပ်သလဲ</span></label>
                                    <textarea name="what_done" rows="3" required placeholder="What did you accomplish this week?" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
                                </div>
                                <div>
                                    <label class="block text-caption font-bold text-slate-500 mb-1">⚙️ How was it done? <span class="text-slate-300 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></label>
                                    <textarea name="how_done" rows="3" required placeholder="Describe the methods, tools, and approach you used." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
                                </div>
                                <div>
                                    <label class="block text-caption font-bold text-slate-500 mb-1">🎯 Why was it done? <span class="text-slate-300 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></label>
                                    <textarea name="why_done" rows="3" required placeholder="Explain the purpose, goals, and expected outcomes." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
                                </div>
                                <div class="flex justify-end">
                                    <button type="submit" name="add_reflection" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Reflection</button>
                                </div>
                            </form>
                        </div>

                        <!-- Weekly Reflections History -->
                        <?php include 'weekly_reflections_table.php'; ?>

                        <!-- Student Signature Section -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 <?= !$reflection_submitted ? 'opacity-50 pointer-events-none select-none' : '' ?>">
                            <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center gap-2">
                                <span class="p-1 bg-indigo-50 text-indigo-600 rounded">✍️</span> Student Signature
                                <?php if (!$reflection_submitted): ?>
                                <span class="ml-auto text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded">🔒 Locked</span>
                                <?php elseif ($student_signed): ?>
                                <span class="ml-auto text-label font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ Signed</span>
                                <?php else: ?>
                                <span class="ml-auto text-label font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">⏳ Awaiting Signature</span>
                                <?php endif; ?>
                            </h2>

                            <?php if (!$reflection_submitted): ?>
                            <p class="text-slate-400 text-center py-6">✍️ Please submit your weekly reflection first to unlock the signature form.</p>

                            <?php elseif ($student_signed): ?>
                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4 text-center">
                                <p class="text-caption font-bold text-emerald-700 mb-2">Your signature has been saved.</p>
                                <?php if ($rejection && $rejection['student_signature_type'] === 'typed'): ?>
                                <p class="student-sig-preview" style="font-family:'Great Vibes',cursive; font-size:24px; color:#1e293b;"><?= htmlspecialchars($rejection['student_signature_value']) ?></p>
                                <?php elseif ($rejection && $rejection['student_signature_type'] === 'uploaded'): ?>
                                <img src="../uploads/signatures/<?= htmlspecialchars($rejection['student_signature_value']) ?>" alt="Student Signature" class="max-h-14 mx-auto object-contain">
                                <?php endif; ?>
                            </div>

                            <?php else: ?>
                            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                                <!-- Signature Type Toggle -->
                                <div class="flex gap-2">
                                    <button type="button" onclick="switchLeftSigType('typed')" id="left-btn-typed"
                                        class="flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600">
                                        ✏️ Type Name
                                    </button>
                                    <button type="button" onclick="switchLeftSigType('uploaded')" id="left-btn-uploaded"
                                        class="flex-1 px-2 py-1.5 text-label font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50">
                                        📷 Upload Image
                                    </button>
                                </div>

                                <!-- Typed Signature -->
                                <div id="left-sig-typed-fields" class="space-y-2">
                                    <input type="hidden" name="student_signature_type" value="typed" id="left-sig-type-input">
                                    <input type="text" name="student_typed_name" id="left_typed_name" placeholder="e.g. Student Name"
                                        oninput="previewLeftSig()"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 focus:outline-none focus:border-blue-500 transition">
                                    <!-- Live Preview -->
                                    <div class="bg-white border-2 border-dashed border-slate-200 rounded-xl p-2 text-center">
                                        <p class="text-caption text-slate-400 uppercase tracking-wider mb-0.5">Preview</p>
                                        <p id="left_sig_preview" class="student-sig-preview">—</p>
                                    </div>
                                </div>

                                <!-- Upload Signature -->
                                <div id="left-sig-upload-fields" class="hidden">
                                    <div class="bg-slate-50 border border-dashed border-slate-300 rounded-xl p-3 text-center">
                                        <p class="text-label text-slate-500 font-semibold mb-2">Upload handwritten signature</p>
                                        <label class="inline-block px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-label font-bold text-slate-600 hover:bg-slate-50 cursor-pointer transition">
                                            Choose JPG/PNG
                                            <input type="file" name="student_signature_file" accept=".jpg,.jpeg,.png" class="hidden">
                                        </label>
                                        <p class="text-caption text-slate-400 mt-1.5">Max 2MB</p>
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit" name="save_student_signature" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-sm transition cursor-pointer">💾 Save Signature</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- ════ TWO-COLUMN: Weekly Summary + Magic Link ════ -->
                    <div class="w-full grid grid-cols-1 md:grid-cols-12 gap-6 mb-6 items-stretch">

                        <!-- ── LEFT: Weekly Summary ── -->
                        <div class="md:col-span-6">
                            <div class="bg-gradient-to-br from-slate-50 to-indigo-50 rounded-2xl border border-slate-200 shadow-sm p-6 h-full flex flex-col justify-between">
                                <div>
                                    <h3 class="text-caption font-black text-slate-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                        <span class="p-1 bg-indigo-50 text-indigo-600 rounded">📋</span> Weekly Summary — Week <?= $selected_week ?>
                                    </h3>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="flex items-center justify-between bg-white/60 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/40">
                                            <span class="text-caption text-slate-500 font-medium">Daily Logs</span>
                                            <span class="text-caption font-bold text-slate-700"><?= $weekly_log_count ?> days</span>
                                        </div>
                                        <div class="flex items-center justify-between bg-white/60 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/40">
                                            <span class="text-caption text-slate-500 font-medium">Reflection</span>
                                            <span class="text-caption font-bold <?= $reflection_submitted ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $reflection_submitted ? '✅ Submitted' : '— Pending' ?></span>
                                        </div>
                                        <div class="flex items-center justify-between bg-white/60 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/40">
                                            <span class="text-caption text-slate-500 font-medium">Signature</span>
                                            <span class="text-caption font-bold <?= $student_signed ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $student_signed ? '✅ Signed' : '— Pending' ?></span>
                                        </div>
                                        <div class="flex items-center justify-between bg-white/60 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/40">
                                            <span class="text-caption text-slate-500 font-medium">Magic Link</span>
                                            <span class="text-caption font-bold <?= $magic_link_unlocked ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $magic_link_unlocked ? '✅ Ready' : '🔒 Locked' ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-5 pt-4 border-t border-slate-200/60">
                                    <?php
                                        $week_progress = 0;
                                        if ($weekly_log_count >= 3) $week_progress += 40;
                                        if ($reflection_submitted) $week_progress += 30;
                                        if ($student_signed) $week_progress += 30;
                                    ?>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <span class="text-label font-bold text-slate-500 uppercase tracking-wider">Progress</span>
                                        <span class="text-label font-bold text-indigo-600"><?= $week_progress ?>%</span>
                                    </div>
                                    <div class="w-full bg-white/60 rounded-full h-2">
                                        <div class="bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full h-2 transition-all duration-500 shadow-sm" style="width: <?= $week_progress ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── RIGHT: Magic Link & Guide ── -->
                        <div class="md:col-span-6">
                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 h-full flex flex-col">
                                <h3 class="text-caption font-black text-slate-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                    <span class="p-1 bg-purple-50 text-purple-600 rounded">🔗</span> Magic Link
                                    <?php if (!$magic_link_unlocked): ?>
                                    <span class="ml-auto text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded">🔒 Locked</span>
                                    <?php endif; ?>
                                </h3>

                                <?php if ($magic_link_unlocked): ?>

                                    <?php if ($is_rejected): ?>
                                    <div class="bg-red-50 border border-red-200 rounded-xl p-3 mb-3">
                                        <p class="text-label font-bold text-red-600 flex items-center gap-1.5">
                                            <span>🔄</span> Report was rejected. Update your logs and reflection, then regenerate a fresh link.
                                        </p>
                                    </div>
                                    <?php endif; ?>

                                    <!-- 2-column sub-layout: Link Generator + How to Share -->
                                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <!-- Left: Link Generator -->
                                        <div>
                                            <p class="text-caption text-slate-400 mb-3 leading-relaxed">
                                                Generate a secure link for your Company Instructor.
                                            </p>
                                            <?php if ($magic_link): ?>
                                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 mb-3">
                                                <label class="block text-label font-bold text-slate-400 mb-1 uppercase tracking-wider">Your Magic Link</label>
                                                <input type="text" id="magic_link_input" value="<?= htmlspecialchars($magic_link) ?>" readonly class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1.5 text-caption font-mono text-slate-600 focus:outline-none">
                                            </div>
                                            <button id="copy_btn" onclick="copyLink()" class="w-full px-3 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl shadow-sm transition cursor-pointer">📋 Copy Link</button>
                                            <p class="text-label text-slate-400 text-center mt-2">Link expires in 7 days.</p>
                                            <?php else: ?>
                                            <form method="POST">
                                                <p class="text-caption text-slate-500 mb-3">Click below to generate the link.</p>
                                                <button type="submit" name="generate_magic_link" class="w-full px-3 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl shadow-sm transition cursor-pointer">🔗 Generate & Send Link</button>
                                            </form>
                                            <p class="text-label text-slate-400 text-center mt-2">No active link yet.</p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Right: How to Share -->
                                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-4 flex flex-col justify-center">
                                            <h4 class="text-caption font-bold text-slate-600 mb-2.5 flex items-center gap-1.5">
                                                <span>💡</span> How to share
                                            </h4>
                                            <ul class="text-label text-slate-400 space-y-2">
                                                <li class="flex items-start gap-2">
                                                    <span class="w-4 h-4 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-micro font-bold shrink-0 mt-0.5">1</span>
                                                    <span>Copy the link above</span>
                                                </li>
                                                <li class="flex items-start gap-2">
                                                    <span class="w-4 h-4 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-micro font-bold shrink-0 mt-0.5">2</span>
                                                    <span>Paste into Email, Viber, Telegram, etc.</span>
                                                </li>
                                                <li class="flex items-start gap-2">
                                                    <span class="w-4 h-4 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-micro font-bold shrink-0 mt-0.5">3</span>
                                                    <span>Instructor clicks link and sees your reports</span>
                                                </li>
                                                <li class="flex items-start gap-2">
                                                    <span class="w-4 h-4 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-micro font-bold shrink-0 mt-0.5">4</span>
                                                    <span>No login required for them</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                <?php else: ?>
                                <!-- Locked State -->
                                <div class="flex-1 flex flex-col justify-center">
                                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-3">
                                        <div class="flex items-start gap-2">
                                            <span class="text-amber-500 text-sm mt-0.5">⚠️</span>
                                            <div>
                                                <p class="text-caption font-bold text-amber-700 mb-1">Requirements not met for Week <?= $selected_week ?></p>
                                                <ul class="text-label text-amber-600 space-y-0.5">
                                                    <li><?= ($total_weekdays > 0 && $weekly_log_count >= $total_weekdays) ? '✅' : '❌' ?> Daily Logs: <strong><?= $weekly_log_count ?>/<?= $total_weekdays ?></strong> days</li>
                                                    <li><?= $reflection_submitted ? '✅' : '❌' ?> Weekly Reflection: <strong><?= $reflection_submitted ? 'Submitted' : 'Not yet' ?></strong></li>
                                                    <li><?= $student_signed ? '✅' : '❌' ?> Student Signature: <strong><?= $student_signed ? 'Signed' : 'Not yet' ?></strong></li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="opacity-50 pointer-events-none select-none">
                                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 mb-3">
                                            <input type="text" readonly value="················" class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1.5 text-caption font-mono text-slate-300 focus:outline-none">
                                        </div>
                                        <button class="w-full px-3 py-2.5 bg-purple-400 text-white font-bold rounded-xl shadow-sm cursor-not-allowed">🔗 Generate Magic Link</button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>


                </div>
            </section>

            <!-- ══════ SECTION: PROFILE (redirect) ══════ -->
            <section id="section-profile" class="hidden">
                <div class="max-w-lg mx-auto text-center py-20">
                    <?php if ($profile_pic): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-16 h-16 rounded-full object-cover border-2 border-indigo-200 shadow-lg mx-auto mb-3">
                    <?php else: ?>
                    <div class="w-16 h-16 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-2xl font-bold mx-auto mb-3"><?= strtoupper(substr($student_name, 0, 1)) ?></div>
                    <?php endif; ?>
                    <h3 class="text-sm font-bold text-slate-800 mb-0.5"><?= htmlspecialchars($student_name) ?></h3>
                    <?php if ($student_roll): ?>
                    <p class="text-caption font-mono font-bold text-indigo-500 uppercase tracking-wider mb-1"><?= htmlspecialchars($student_roll) ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-slate-400 capitalize mb-5"><?= htmlspecialchars($role) ?></p>
                    <a href="profile.php" class="inline-block px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-sm transition">👤 View Full Profile</a>
                </div>
            </section>

        </main>
    </div>
</div>

<!-- ══════ EXPORT MODAL ══════ -->
<div id="export-modal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('export-modal').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 z-10">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="p-1 bg-indigo-50 text-indigo-600 rounded">📄</span> Export Report
            </h3>
            <button onclick="document.getElementById('export-modal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
        </div>
        <p class="text-slate-500 mb-4">Choose an export option for your internship report data.</p>
        <div class="space-y-3">
            <button onclick="exportAsHTML()" class="w-full flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl hover:bg-indigo-50 hover:border-indigo-200 transition group cursor-pointer">
                <span class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">📋</span>
                <div class="text-left">
                    <p class="font-bold text-slate-700 group-hover:text-indigo-700">Export as HTML Report</p>
                    <p class="text-label text-slate-400">Printable report with all logs and reflections</p>
                </div>
            </button>
            <button onclick="exportAsCSV()" class="w-full flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl hover:bg-emerald-50 hover:border-emerald-200 transition group cursor-pointer">
                <span class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">📊</span>
                <div class="text-left">
                    <p class="font-bold text-slate-700 group-hover:text-emerald-700">Export as CSV</p>
                    <p class="text-label text-slate-400">Spreadsheet-compatible daily logs data</p>
                </div>
            </button>
            <button onclick="window.print()" class="w-full flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl hover:bg-amber-50 hover:border-amber-200 transition group cursor-pointer">
                <span class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">🖨️</span>
                <div class="text-left">
                    <p class="font-bold text-slate-700 group-hover:text-amber-700">Print Dashboard</p>
                    <p class="text-label text-slate-400">Print the current dashboard view</p>
                </div>
            </button>
        </div>
    </div>
</div>

<script>
function exportAsHTML() {
    document.getElementById('export-modal').classList.add('hidden');
    var printWin = window.open('', '_blank');
    var content = '<!DOCTYPE html><html><head><title>Internship Report - <?= htmlspecialchars($student_name) ?></title>';
    content += '<style>body{font-family:Arial,sans-serif;padding:40px;color:#1e293b;}';
    content += 'h1{font-size:22px;border-bottom:2px solid #4f46e5;padding-bottom:8px;color:#4f46e5;}';
    content += 'h2{font-size:16px;margin-top:30px;color:#334155;}';
    content += 'table{width:100%;border-collapse:collapse;margin:15px 0;font-size:12px;}';
    content += 'th,td{border:1px solid #e2e8f0;padding:8px 12px;text-align:left;}';
    content += 'th{background:#f1f5f9;font-weight:bold;color:#475569;}';
    content += '.info{background:#f8fafc;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #e2e8f0;}';
    content += '.info p{margin:4px 0;font-size:13px;}';
    content += '.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:bold;}';
    content += '.present{background:#d1fae5;color:#065f46;}.absent{background:#fee2e2;color:#991b1b;}</style></head><body>';
    content += '<h1>Internship Daily Log Report</h1>';
    content += '<div class="info">';
    content += '<p><strong>Student:</strong> <?= htmlspecialchars($student_name) ?></p>';
    content += '<p><strong>Supervisor:</strong> <?= htmlspecialchars($supervisor_name) ?></p>';
    content += '<p><strong>Period:</strong> <?= $intern_start ? (new DateTime($intern_start))->format('d M Y') . ' – ' . (new DateTime($intern_end))->format('d M Y') : 'N/A' ?></p>';
    content += '<p><strong>Total Hours:</strong> <?= $total_hours ?>h <?= str_pad($total_mins, 2, '0', STR_PAD_LEFT) ?>m</p>';
    content += '<p><strong>Attendance Rate:</strong> <?= $attendance_rate ?>%</p>';
    content += '</div>';
    content += '<h2>Daily Logs</h2>';
    content += '<table><thead><tr><th>Date</th><th>Status</th><th>Task</th><th>Duration</th><th>Tools</th><th>Knowledge</th></tr></thead><tbody>';
    <?php foreach ($all_logs as $log): ?>
    content += '<tr><td><?= htmlspecialchars($log["log_date"]) ?></td>';
    content += '<td><span class="badge <?= ($log["attendance_status"] ?? "present") === "present" ? "present" : "absent" ?>"><?= ucfirst($log["attendance_status"] ?? "present") ?></span></td>';
    content += '<td><?= htmlspecialchars($log["task_title"] ?? "-") ?></td>';
    content += '<td><?= htmlspecialchars($log["calculated_duration"]) ?></td>';
    content += '<td><?= htmlspecialchars($log["tools_used"] ?? "-") ?></td>';
    content += '<td><?= htmlspecialchars($log["learnt_skills"] ?? "-") ?></td></tr>';
    <?php endforeach; ?>
    content += '</tbody></table>';
    content += '<h2>Weekly Reflections</h2>';
    <?php
    $all_refs_r = $conn->query("SELECT * FROM weekly_reflections WHERE internship_id = {$esc_iid} ORDER BY week_number");
    $all_refs = [];
    if ($all_refs_r) { while ($row = $all_refs_r->fetch_assoc()) { $all_refs[] = $row; } }
    foreach ($all_refs as $rf):
    ?>
    content += '<h3>Week <?= (int)$rf["week_number"] ?></h3>';
    content += '<p><strong>What was done:</strong> <?= nl2br(htmlspecialchars($rf["what_done"])) ?></p>';
    content += '<p><strong>How it was done:</strong> <?= nl2br(htmlspecialchars($rf["how_done"])) ?></p>';
    content += '<p><strong>Why it was done:</strong> <?= nl2br(htmlspecialchars($rf["why_done"])) ?></p>';
    <?php endforeach; ?>
    content += '<p style="margin-top:40px;font-size:11px;color:#94a3b8;">Generated on <?= date('d M Y, h:i A') ?> via InternReport System</p>';
    content += '</body></html>';
    printWin.document.write(content);
    printWin.document.close();
    printWin.print();
}

function exportAsCSV() {
    document.getElementById('export-modal').classList.add('hidden');
    var csv = 'Date,Status,Task Title,Task Detail,Actual Task,Tools Used,Knowledge Gained,Duration\n';
    <?php foreach ($all_logs as $log): ?>
    csv += '"<?= $log["log_date"] ?>","<?= $log["attendance_status"] ?? "present" ?>","<?= addslashes($log["task_title"] ?? "") ?>","<?= addslashes($log["task_detail"] ?? "") ?>","<?= addslashes($log["tasks_performed"] ?? "") ?>","<?= addslashes($log["tools_used"] ?? "") ?>","<?= addslashes($log["learnt_skills"] ?? "") ?>","<?= $log["calculated_duration"] ?>"\n';
    <?php endforeach; ?>
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'daily_logs_<?= $user_id ?>_week<?= $selected_week ?>.csv';
    link.click();
}
</script>
</body>
</html>