<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id       = (int) $_SESSION['user_id'];
$username      = $_SESSION['username'];
$internship_id = $user_id;
$message       = '';
$db            = $mysqli ?? $conn;
$role          = $_SESSION['role'] ?? 'student';
$holiday_dates = [];

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($is_ajax) {
    ob_start();
}

// Active dashboard tab (overview | daily-log | weekly-report)
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'daily-log', 'weekly-report'], true)) {
    $tab = 'overview';
}

// ══════════════════════════════════════════════════════════════════════
// FETCH INTERNSHIP DATE RANGE + PROFILE INFO
// ══════════════════════════════════════════════════════════════════════
$profile_stmt = $db->prepare("SELECT sp.full_name, sp.student_roll, sp.internship_start_date, sp.internship_end_date, sup_u.username AS supervisor_name, sp.supervisor_id, u.profile_pic,
           sp.instructor_name, sp.instructor_email, sp.instructor_id
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$profile_res = $profile_stmt->get_result();
$profile_row = $profile_res ? $profile_res->fetch_assoc() : null;

$intern_start = $profile_row['internship_start_date'] ?? null;
$intern_end   = $profile_row['internship_end_date'] ?? null;
$student_name = ($profile_row['full_name'] ?? '') ?: $username;
$student_roll = $profile_row['student_roll'] ?? '';
$supervisor_name = $profile_row['supervisor_name'] ?? '—';
$profile_pic = $profile_row['profile_pic'] ?? '';
$instructor_name = ($profile_row['instructor_name'] ?? '') ?: '—';
$instructor_email = $profile_row['instructor_email'] ?? '';

$sup_initial = mb_substr($supervisor_name, 0, 1, 'UTF-8');
$sup_initial_display = ($sup_initial === '—' || empty($sup_initial)) ? 'S' : mb_strtoupper($sup_initial, 'UTF-8');

$inst_initial = mb_substr($instructor_name, 0, 1, 'UTF-8');
$inst_initial_display = ($inst_initial === '—' || empty($inst_initial)) ? 'I' : mb_strtoupper($inst_initial, 'UTF-8');

// ══════════════════════════════════════════════════════════════════════
// FETCH WARNING STATUS
// ══════════════════════════════════════════════════════════════════════
$warn_stmt = $db->prepare("SELECT is_warned FROM users WHERE id = ?");
$warn_stmt->bind_param("i", $user_id);
$warn_stmt->execute();
$warn_res = $warn_stmt->get_result();
$warn_row = $warn_res ? $warn_res->fetch_row() : null;
$is_warned = (bool) ($warn_row[0] ?? 0);

// Build week ranges
$weeks = [];
if ($intern_start) {
    $w = 1;
    while (true) {
        $range = getWeekRange($intern_start, $w);
        if (!$range) break;
        if ($intern_end && $range['start'] > $intern_end) break;
        $weeks[$w] = $range;
        $w++;
    }
}

// Check submitted/completed weeks to auto-advance to next pending week
$submitted_weeks = [];
$sub_weeks_stmt = $db->prepare("
    SELECT DISTINCT re.week_number 
    FROM report_evaluations re
    JOIN weekly_reflections wr ON wr.internship_id = re.student_id AND wr.week_number = re.week_number
    WHERE re.student_id = ? 
      AND re.student_signature_value IS NOT NULL 
      AND re.student_signature_value != ''
      AND re.report_status != 'rejected'
");
$sub_weeks_stmt->bind_param("i", $internship_id);
$sub_weeks_stmt->execute();
$sub_weeks_res = $sub_weeks_stmt->get_result();
if ($sub_weeks_res) {
    while ($sw_row = $sub_weeks_res->fetch_assoc()) {
        $submitted_weeks[(int)$sw_row['week_number']] = true;
    }
}

// Find the first unsubmitted/pending week
$auto_week = 1;
if (!empty($weeks)) {
    foreach (array_keys($weeks) as $wn) {
        if (empty($submitted_weeks[$wn])) {
            $auto_week = $wn;
            break;
        }
    }
    if (empty($auto_week) || !isset($weeks[$auto_week])) {
        $auto_week = max(array_keys($weeks));
    }
}

$selected_week = $auto_week;
if (isset($_GET['week'])) {
    $w = (int)$_GET['week'];
    if (isset($weeks[$w])) $selected_week = $w;
}

// ── FORM A: Add Daily Log ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $post_week = (int) ($_POST['selected_week'] ?? 0);
    if ($post_week > 0) {
        $lock_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
        $lock_stmt->bind_param("ii", $internship_id, $post_week);
        $lock_stmt->execute();
        $lock_res = $lock_stmt->get_result();
        $lock_row = $lock_res ? $lock_res->fetch_assoc() : null;
        if ($lock_row && !empty($lock_row['student_signature_type']) && !empty($lock_row['student_signature_value']) && $lock_row['report_status'] !== 'rejected') {
            $message = 'log_locked';
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
            $date_error = validateLogDate($log_date, $intern_start, $intern_end);
            if ($date_error) {
                $message = $date_error;
            } else {
                $post_week = (int) ($_POST['selected_week'] ?? $selected_week);
                if (!empty($weeks[$post_week])) {
                    $ws_start = $weeks[$post_week]['start'];
                    $ws_end   = $weeks[$post_week]['end'];
                    if ($log_date < $ws_start || $log_date > $ws_end) {
                        $message = 'date_out_of_week';
                    }
                }
                if (!$message) {
                    $log_day = (int)(new DateTime($log_date))->format('N');
                    if ($log_day >= 6) {
                        $message = 'date_is_weekend';
                    }
                }
                if (!$message && empty($_POST['log_id'])) {
                    // Sequential logging check: verify all previous working days are logged
                    $logged_dates_map = [];
                    $ld_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ?");
                    $ld_stmt->bind_param("i", $internship_id);
                    $ld_stmt->execute();
                    $ld_res = $ld_stmt->get_result();
                    if ($ld_res) {
                        while ($ld_row = $ld_res->fetch_assoc()) {
                            $logged_dates_map[$ld_row['log_date']] = true;
                        }
                    }
                    if (!empty($intern_start)) {
                        $start_dt = new DateTime($intern_start);
                        $target_dt = new DateTime($log_date);
                        $start_dt->setTime(0, 0);
                        $target_dt->setTime(0, 0);
                        $chk = clone $start_dt;
                        while ($chk < $target_dt) {
                            $day_of_week = (int)$chk->format('N');
                            if ($day_of_week < 6) { // Weekdays only
                                $chk_str = $chk->format('Y-m-d');
                                if (empty($logged_dates_map[$chk_str])) {
                                    $message = 'skip_date_' . $chk_str;
                                    break;
                                }
                            }
                            $chk->modify('+1 day');
                        }
                    }
                }
            }
            if (!$message) {
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
                $ins_log = $db->prepare("INSERT INTO daily_logs
                    (internship_id, log_date, attendance_status, reason_for_absence, task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    attendance_status = VALUES(attendance_status),
                    reason_for_absence = VALUES(reason_for_absence),
                    task_title = VALUES(task_title),
                    task_detail = VALUES(task_detail),
                    tasks_performed = VALUES(tasks_performed),
                    tools_used = VALUES(tools_used),
                    learnt_skills = VALUES(learnt_skills),
                    calculated_duration = VALUES(calculated_duration)");
                $ins_log->bind_param(
                    "isssssssss",
                    $internship_id,
                    $log_date,
                    $attendance_status,
                    $reason_for_absence,
                    $intended_task,
                    $task_detail,
                    $actual_task,
                    $tools_used,
                    $knowledge_gained,
                    $hours_worked
                );
                $ins_log->execute();
                $message = 'daily_saved';
            }
        }
    }
}

// ── EDIT LOG ──
$editing_log = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $edit_stmt = $db->prepare("SELECT * FROM daily_logs WHERE id = ? AND internship_id = ?");
    $edit_stmt->bind_param("ii", $edit_id, $internship_id);
    $edit_stmt->execute();
    $edit_res = $edit_stmt->get_result();
    $editing_log = $edit_res ? $edit_res->fetch_assoc() : null;
    if (!$editing_log) {
        $message = 'log_not_found';
    } else {
        $edit_lock_week = getInternshipWeekNumber($intern_start, $editing_log['log_date']);
        $edit_lock_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
        $edit_lock_stmt->bind_param("ii", $internship_id, $edit_lock_week);
        $edit_lock_stmt->execute();
        $edit_lock_res = $edit_lock_stmt->get_result();
        $edit_lock_row = $edit_lock_res ? $edit_lock_res->fetch_assoc() : null;
        if ($edit_lock_row && !empty($edit_lock_row['student_signature_type']) && !empty($edit_lock_row['student_signature_value']) && $edit_lock_row['report_status'] !== 'rejected') {
            $editing_log = null;
            $message = 'log_locked';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_log'])) {
    $post_week = (int) ($_POST['selected_week'] ?? 0);
    if ($post_week > 0) {
        $lock_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
        $lock_stmt->bind_param("ii", $internship_id, $post_week);
        $lock_stmt->execute();
        $lock_res = $lock_stmt->get_result();
        $lock_row = $lock_res ? $lock_res->fetch_assoc() : null;
        if ($lock_row && !empty($lock_row['student_signature_type']) && !empty($lock_row['student_signature_value']) && $lock_row['report_status'] !== 'rejected') {
            $message = 'log_locked';
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
                $post_week = (int) ($_POST['selected_week'] ?? $selected_week);
                if (!empty($weeks[$post_week])) {
                    $ews_start = $weeks[$post_week]['start'];
                    $ews_end   = $weeks[$post_week]['end'];
                    if ($log_date < $ews_start || $log_date > $ews_end) {
                        $message = 'date_out_of_week';
                    }
                }
                if (!$message) {
                    $log_day = (int)(new DateTime($log_date))->format('N');
                    if ($log_day >= 6) {
                        $message = 'date_is_weekend';
                    }
                }
            }
            if (!$message) {
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
                $upd_stmt = $db->prepare("UPDATE daily_logs SET
                    log_date = ?, attendance_status = ?, reason_for_absence = ?,
                    task_title = ?, task_detail = ?, tasks_performed = ?,
                    tools_used = ?, learnt_skills = ?, calculated_duration = ?
                    WHERE id = ? AND internship_id = ?");
                $upd_stmt->bind_param(
                    "sssssssssii",
                    $log_date,
                    $attendance_status,
                    $reason_for_absence,
                    $intended_task,
                    $task_detail,
                    $actual_task,
                    $tools_used,
                    $knowledge_gained,
                    $hours_worked,
                    $edit_id,
                    $internship_id
                );
                $upd_stmt->execute();
                $message = 'log_updated';
            }
        }
    }
}

// ── DELETE LOG ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    $del_id = (int) ($_POST['log_id'] ?? 0);
    if ($del_id) {
        $del_check_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE id = ? AND internship_id = ?");
        $del_check_stmt->bind_param("ii", $del_id, $internship_id);
        $del_check_stmt->execute();
        $del_res = $del_check_stmt->get_result();
        $del_log = $del_res ? $del_res->fetch_assoc() : null;
        if ($del_log) {
            $del_lock_week = getInternshipWeekNumber($intern_start, $del_log['log_date']);
            $del_eval_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
            $del_eval_stmt->bind_param("ii", $internship_id, $del_lock_week);
            $del_eval_stmt->execute();
            $del_eval_res = $del_eval_stmt->get_result();
            $del_eval_row = $del_eval_res ? $del_eval_res->fetch_assoc() : null;
            if ($del_eval_row && !empty($del_eval_row['student_signature_type']) && !empty($del_eval_row['student_signature_value']) && $del_eval_row['report_status'] !== 'rejected') {
                $message = 'log_locked';
            }
        }
        if (!$message) {
            $del_stmt = $db->prepare("DELETE FROM daily_logs WHERE id = ? AND internship_id = ?");
            $del_stmt->bind_param("ii", $del_id, $internship_id);
            $del_stmt->execute();
            $message = 'log_deleted';
        }
    }
}

// ── FORM B: Weekly Reflection ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reflection'])) {
    $week_number  = (int) ($_POST['week_number'] ?? 0);
    $what_done    = trim($_POST['what_done'] ?? '');
    $how_done     = trim($_POST['how_done'] ?? '');
    $why_done     = trim($_POST['why_done'] ?? '');

    if ($week_number > 0 && $what_done) {
        $ref_stmt = $db->prepare("INSERT INTO weekly_reflections
            (internship_id, week_number, what_done, how_done, why_done)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            what_done = VALUES(what_done),
            how_done = VALUES(how_done),
            why_done = VALUES(why_done)");
        $ref_stmt->bind_param("iisss", $internship_id, $week_number, $what_done, $how_done, $why_done);
        $ref_stmt->execute();
        $message = 'reflection_saved';
    }
}

$magic_link = '';

// ── FETCH EXISTING DATA ──
$all_logs_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC");
$all_logs_stmt->bind_param("i", $internship_id);
$all_logs_stmt->execute();
$all_res = $all_logs_stmt->get_result();
$all_logs = $all_res ? $all_res->fetch_all(MYSQLI_ASSOC) : [];

// Existing log dates for duplicate prevention
if ($editing_log) {
    $dates_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ? AND id != ?");
    $editing_log_id = (int)$editing_log['id'];
    $dates_stmt->bind_param("ii", $internship_id, $editing_log_id);
} else {
    $dates_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ?");
    $dates_stmt->bind_param("i", $internship_id);
}
$dates_stmt->execute();
$dates_res = $dates_stmt->get_result();
$existing_log_dates = [];
if ($dates_res) {
    while ($row = $dates_res->fetch_row()) {
        $existing_log_dates[] = $row[0];
    }
}

// Format date range for the selected week
$week_date_range = '';
if (!empty($weeks[$selected_week])) {
    $week_start_obj = new DateTime($weeks[$selected_week]['start']);
    $week_end_obj   = new DateTime($weeks[$selected_week]['end']);
    $week_date_range = $week_start_obj->format('d M Y') . ' to ' . $week_end_obj->format('d M Y');
}

// Fetch logs for the selected week
if (!empty($weeks[$selected_week])) {
    $ws = $weeks[$selected_week]['start'];
    $we = $weeks[$selected_week]['end'];
    $logs_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date DESC");
    $logs_stmt->bind_param("iss", $internship_id, $ws, $we);
    $logs_stmt->execute();
    $logs_res = $logs_stmt->get_result();
    $recent_logs = $logs_res ? $logs_res->fetch_all(MYSQLI_ASSOC) : [];
} else {
    $recent_logs = $all_logs;
}

// ── DAILY-LOG VIEW STATE (weekday pills, selected date, active log, lock status) ──
$log_by_date = [];
if (!empty($recent_logs)) {
    foreach ($recent_logs as $log) {
        $log_by_date[$log['log_date']] = $log;
    }
}

$week_dates = [];
if (!empty($weeks[$selected_week])) {
    $ws_obj = new DateTime($weeks[$selected_week]['start']);
    $we_obj = new DateTime($weeks[$selected_week]['end']);
    $ws_obj->setTime(0, 0);
    $we_obj->setTime(0, 0);
    $cursor = clone $ws_obj;
    while ($cursor <= $we_obj) {
        if ((int)$cursor->format('N') < 6) {
            $week_dates[] = $cursor->format('Y-m-d');
        }
        $cursor->modify('+1 day');
    }
}

$selected_date = trim($_GET['date'] ?? $_POST['log_date'] ?? '');
if ($editing_log && !empty($editing_log['log_date'])) {
    $selected_date = $editing_log['log_date'];
} elseif (empty($selected_date) || (!empty($week_dates) && !in_array($selected_date, $week_dates, true))) {
    $today_iso = date('Y-m-d');
    if (!empty($week_dates) && in_array($today_iso, $week_dates, true)) {
        $selected_date = $today_iso;
    } elseif (!empty($week_dates)) {
        $selected_date = $week_dates[0];
    } else {
        $selected_date = '';
    }
}

$active_day_log = null;
if (!empty($selected_date)) {
    $adl_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date = ? LIMIT 1");
    $adl_stmt->bind_param("is", $internship_id, $selected_date);
    $adl_stmt->execute();
    $adl_res = $adl_stmt->get_result();
    $active_day_log = $adl_res ? $adl_res->fetch_assoc() : null;
}

$log_locked = false;
$log_lock_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$log_lock_stmt->bind_param("ii", $internship_id, $selected_week);
$log_lock_stmt->execute();
$log_lock_res = $log_lock_stmt->get_result();
$log_lock_row = $log_lock_res ? $log_lock_res->fetch_assoc() : null;
if ($log_lock_row && !empty($log_lock_row['student_signature_type']) && !empty($log_lock_row['student_signature_value']) && $log_lock_row['report_status'] !== 'rejected') {
    $log_locked = true;
}

// Attendance counts
$p_count_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present'");
$p_count_stmt->bind_param("i", $internship_id);
$p_count_stmt->execute();
$p_res = $p_count_stmt->get_result();
$p_row = $p_res ? $p_res->fetch_row() : null;
$present_count = (int) ($p_row[0] ?? 0);

$a_count_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave')");
$a_count_stmt->bind_param("i", $internship_id);
$a_count_stmt->execute();
$a_res = $a_count_stmt->get_result();
$a_row = $a_res ? $a_res->fetch_row() : null;
$absent_count = (int) ($a_row[0] ?? 0);

$t_count_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IS NOT NULL AND attendance_status != ''");
$t_count_stmt->bind_param("i", $internship_id);
$t_count_stmt->execute();
$t_res = $t_count_stmt->get_result();
$t_row = $t_res ? $t_res->fetch_row() : null;
$total_logged_attendance_days = (int) ($t_row[0] ?? 0);

// Overall internship attendance details for tooltips
$pd_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present' ORDER BY log_date ASC");
$pd_stmt->bind_param("i", $internship_id);
$pd_stmt->execute();
$pd_res = $pd_stmt->get_result();
$present_dates = [];
if ($pd_res) {
    while ($row = $pd_res->fetch_row()) {
        $present_dates[] = $row[0];
    }
}

$ad_stmt = $db->prepare("SELECT log_date, reason_for_absence FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
$ad_stmt->bind_param("i", $internship_id);
$ad_stmt->execute();
$ad_res = $ad_stmt->get_result();
$absent_logs = $ad_res ? $ad_res->fetch_all(MYSQLI_ASSOC) : [];

// Weekly Reflection unlock logic
$weekly_log_count = 0;
$weekly_present_count = 0;
$weekly_absent_count = 0;
$reflection_submitted = false;
$total_weekdays = 0;
if (!empty($weeks[$selected_week])) {
    $ws = $weeks[$selected_week]['start'];
    $we = $weeks[$selected_week]['end'];
    $wls_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $wls_stmt->bind_param("iss", $internship_id, $ws, $we);
    $wls_stmt->execute();
    $wls_res = $wls_stmt->get_result();
    $wls_row = $wls_res ? $wls_res->fetch_row() : null;
    $weekly_log_count = (int) ($wls_row[0] ?? 0);

    $wp_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? AND attendance_status = 'present'");
    $wp_stmt->bind_param("iss", $internship_id, $ws, $we);
    $wp_stmt->execute();
    $wp_res = $wp_stmt->get_result();
    $wp_row = $wp_res ? $wp_res->fetch_row() : null;
    $weekly_present_count = (int) ($wp_row[0] ?? 0);

    $wa_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? AND attendance_status IN ('absent','leave')");
    $wa_stmt->bind_param("iss", $internship_id, $ws, $we);
    $wa_stmt->execute();
    $wa_res = $wa_stmt->get_result();
    $wa_row = $wa_res ? $wa_res->fetch_row() : null;
    $weekly_absent_count = (int) ($wa_row[0] ?? 0);

    $wd_cursor = new DateTime($ws);
    $wd_end = new DateTime($we);
    while ($wd_cursor <= $wd_end) {
        if ((int)$wd_cursor->format('N') < 6) $total_weekdays++;
        $wd_cursor->modify('+1 day');
    }
}
$reflection_unlocked = $total_weekdays > 0 && $weekly_log_count >= $total_weekdays;

$rc_stmt = $db->prepare("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$rc_stmt->bind_param("ii", $internship_id, $selected_week);
$rc_stmt->execute();
$rc_res = $rc_stmt->get_result();
$rc_row = $rc_res ? $rc_res->fetch_row() : null;
$reflection_submitted = ((int) ($rc_row[0] ?? 0) > 0);

// Check if instructor rejected this week's report
$rej_stmt = $db->prepare("SELECT report_status, instructor_comments, student_signature_type, student_signature_value FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$rej_stmt->bind_param("ii", $internship_id, $selected_week);
$rej_stmt->execute();
$rej_res = $rej_stmt->get_result();
$rejection = $rej_res ? $rej_res->fetch_assoc() : null;
$is_rejected = $rejection && $rejection['report_status'] === 'rejected';
$rejection_reason = $is_rejected ? ($rejection['instructor_comments'] ?? '') : '';

// Check if student has already signed for this week
$student_signed = false;
if ($rejection && !empty($rejection['student_signature_type']) && !empty($rejection['student_signature_value'])) {
    $student_signed = true;
}
if ($is_rejected) {
    $student_signed = false;
}

// WARNING AUTO-CLEAR
if ($is_warned && $message === 'daily_saved') {
    $clr_stmt = $db->prepare("UPDATE users SET is_warned = 0 WHERE id = ?");
    $clr_stmt->bind_param("i", $user_id);
    $clr_stmt->execute();
    $is_warned = false;
}

// FETCH NOTIFICATIONS
$unr_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unr_stmt->bind_param("i", $user_id);
$unr_stmt->execute();
$unr_res = $unr_stmt->get_result();
$unr_row = $unr_res ? $unr_res->fetch_row() : null;
$unread_notif_count = (int) ($unr_row[0] ?? 0);

$rec_notif_stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$rec_notif_stmt->bind_param("i", $user_id);
$rec_notif_stmt->execute();
$rec_res = $rec_notif_stmt->get_result();
$recent_notifications = $rec_res ? $rec_res->fetch_all(MYSQLI_ASSOC) : [];

$app_notif_stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = 'instructor_approved' AND is_read = 0 ORDER BY created_at DESC LIMIT 1");
$app_notif_stmt->bind_param("i", $user_id);
$app_notif_stmt->execute();
$app_res = $app_notif_stmt->get_result();
$approval_notif = $app_res ? $app_res->fetch_assoc() : null;

if ($is_rejected) {
    $reflection_unlocked = true;
    $magic_link_unlocked = true;
} else {
    $magic_link_unlocked = $reflection_unlocked && $reflection_submitted && $student_signed;
}

// Handle student signature POST
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
        $sig_stmt = $db->prepare("INSERT INTO report_evaluations (student_id, week_number, grade, comment, student_signature_type, student_signature_value, report_status)
            VALUES (?, ?, 'needs_improvement', '', ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE
            student_signature_type = VALUES(student_signature_type),
            student_signature_value = VALUES(student_signature_value),
            report_status = 'pending', evaluated_at = NOW()");
        $sig_stmt->bind_param("iiss", $internship_id, $selected_week, $sig_type, $sig_val);
        $sig_stmt->execute();

        // Auto-generate Token & Dispatch Review Email directly to Instructor
        $token = bin2hex(random_bytes(16));
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
        $link_stmt = $db->prepare("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
        $link_stmt->bind_param("iiss", $internship_id, $selected_week, $token, $expires_at);
        $link_stmt->execute();

        require_once __DIR__ . '/../config/mailer.php';
        $mail_res = send_instructor_magic_link($db, $internship_id, $selected_week, $token);
        if (!$mail_res['success'] && ($mail_res['mode'] ?? '') === 'no_email') {
            $message = 'sig_saved_no_instructor_email';
        } else {
            $message = 'sig_saved_email_dispatched';
        }

        if (!empty($profile_row['supervisor_id'])) {
            require_once __DIR__ . '/../config/notify.php';
            notify_user_once(
                $db,
                (int) $profile_row['supervisor_id'],
                'New Report Submitted',
                $student_name . ' has submitted Week ' . $selected_week . ' report and it is awaiting review.',
                'new_report_submitted',
                (int) $selected_week,
                (int) $internship_id
            );
        }

        $rej_stmt->bind_param("ii", $internship_id, $selected_week);
        $rej_stmt->execute();
        $rej_res = $rej_stmt->get_result();
        $rejection = $rej_res ? $rej_res->fetch_assoc() : null;
        $is_rejected = $rejection && $rejection['report_status'] === 'rejected';
        $student_signed = false;
        if ($rejection && !empty($rejection['student_signature_type']) && !empty($rejection['student_signature_value'])) {
            $student_signed = true;
        }
        if ($is_rejected) {
            $student_signed = false;
            $magic_link_unlocked = true;
        } else {
            $magic_link_unlocked = $reflection_unlocked && $reflection_submitted && $student_signed;
        }
    } else {
        $message = 'student_sig_required';
    }
}

$is_week_approved = $rejection && in_array($rejection['report_status'], ['approved_by_instructor', 'approved_by_supervisor']);

// Handle resending review email to instructor
if ($magic_link_unlocked && (isset($_POST['resend_instructor_email']) || isset($_POST['generate_magic_link']))) {
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $link_stmt = $db->prepare("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
    $link_stmt->bind_param("iiss", $internship_id, $selected_week, $token, $expires_at);
    $link_stmt->execute();

    require_once __DIR__ . '/../config/mailer.php';
    $mail_res = send_instructor_magic_link($db, $internship_id, $selected_week, $token);

    if ($mail_res['success']) {
        $message = 'email_sent_success';
    } else {
        if (($mail_res['mode'] ?? '') === 'no_email') {
            $message = 'email_no_instructor';
        } else {
            $message = 'email_send_failed';
        }
    }
}

// Fetch active magic link status (only if NOT already approved)
$existing_link = null;

if ($is_week_approved) {
    // If approved, expire/delete any remaining magic link
    $db->query("DELETE FROM magic_links WHERE internship_id = {$internship_id} AND week_number = {$selected_week}");
} else {
    $active_token_stmt = $db->prepare("SELECT token, expires_at FROM magic_links WHERE internship_id = ? AND week_number = ? AND expires_at > NOW() LIMIT 1");
    $active_token_stmt->bind_param("ii", $internship_id, $selected_week);
    $active_token_stmt->execute();
    $act_res = $active_token_stmt->get_result();
    $existing_link = $act_res ? $act_res->fetch_assoc() : null;

    if ($magic_link_unlocked && empty($existing_link) && !$is_rejected) {
        $token = bin2hex(random_bytes(16));
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
        $link_stmt = $db->prepare("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
        $link_stmt->bind_param("iiss", $internship_id, $selected_week, $token, $expires_at);
        $link_stmt->execute();

        $existing_link = ['token' => $token, 'expires_at' => $expires_at];
    }
}

$reflections_stmt = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ? ORDER BY week_number DESC");
$reflections_stmt->bind_param("ii", $internship_id, $selected_week);
$reflections_stmt->execute();
$ref_res = $reflections_stmt->get_result();
$weekly_refs = $ref_res ? $ref_res->fetch_all(MYSQLI_ASSOC) : [];

// ── WORKFLOW CHAIN ──
$wf_step1_done = $total_weekdays > 0 && $weekly_log_count >= $total_weekdays;
$wf_step2_done = $reflection_submitted;
$wf_step3_done = $student_signed;
$wf_has_link   = !empty($existing_link);

$wf_step4_status = 'pending';
if ($rejection) {
    if ($rejection['report_status'] === 'approved_by_instructor' || $rejection['report_status'] === 'approved_by_supervisor') {
        $wf_step4_status = 'approved';
    } elseif ($rejection['report_status'] === 'rejected') {
        $wf_step4_status = 'rejected';
    }
}

$sup_eval_stmt = $db->prepare("SELECT weekly_grade FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ? LIMIT 1");
$sup_eval_stmt->bind_param("ii", $internship_id, $selected_week);
$sup_eval_stmt->execute();
$sup_res = $sup_eval_stmt->get_result();
$wf_step5_done = (bool) ($sup_res ? $sup_res->fetch_assoc() : null);

// ── FEEDBACK & GRADES (Instructor + Supervisor) ──
$instructor_eval_stmt = $db->prepare("SELECT grade, comment, instructor_comments, signature_type, signature_value, report_status, evaluated_at FROM report_evaluations WHERE student_id = ? AND week_number = ? LIMIT 1");
$instructor_eval_stmt->bind_param("ii", $internship_id, $selected_week);
$instructor_eval_stmt->execute();
$instructor_eval_res = $instructor_eval_stmt->get_result();
$instructor_eval = $instructor_eval_res ? $instructor_eval_res->fetch_assoc() : null;

$supervisor_eval_stmt = $db->prepare("SELECT weekly_grade, supervisor_comments, supervisor_id, evaluated_at FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ? LIMIT 1");
$supervisor_eval_stmt->bind_param("ii", $internship_id, $selected_week);
$supervisor_eval_stmt->execute();
$supervisor_eval_res = $supervisor_eval_stmt->get_result();
$supervisor_eval = $supervisor_eval_res ? $supervisor_eval_res->fetch_assoc() : null;

// Reviewer display names
$supervisor_reviewer = $supervisor_name !== '' && $supervisor_name !== '—' ? $supervisor_name : 'Supervisor';
$instructor_reviewer = $instructor_name !== '' && $instructor_name !== '—' ? $instructor_name : 'Instructor';

// Instructor grade labels
$instructor_grade_map = [
    'excellent'         => ['Excellent',         'bg-emerald-100 text-emerald-700'],
    'good'              => ['Good',              'bg-blue-100 text-blue-700'],
    'average'           => ['Average',           'bg-amber-100 text-amber-700'],
    'needs_improvement' => ['Needs Improvement', 'bg-red-100 text-red-700'],
];

// Supervisor grade labels
$supervisor_grade_map = [
    'A' => ['A', 'bg-emerald-100 text-emerald-700'],
    'B' => ['B', 'bg-blue-100 text-blue-700'],
    'C' => ['C', 'bg-amber-100 text-amber-700'],
    'D' => ['D', 'bg-orange-100 text-orange-700'],
    'F' => ['F', 'bg-red-100 text-red-700'],
];

// Report status badge
$weekly_report_submitted = $reflection_submitted && $student_signed && !$is_rejected;
$report_status_label = ($weekly_report_submitted || $student_signed) ? 'Under Review' : 'Pending Review';
$report_status_color = 'text-amber-700 bg-amber-50 border-amber-200';
$report_status_dot   = 'bg-amber-500';
$instructor_evaluated = !empty($instructor_eval)
    && in_array($instructor_eval['report_status'] ?? '', ['approved_by_instructor', 'approved_by_supervisor', 'rejected'], true);
if ($instructor_evaluated) {
    $rs = $instructor_eval['report_status'] ?? '';
    if ($rs === 'rejected') {
        $report_status_label = 'Rejected';
        $report_status_color = 'text-red-700 bg-red-50 border-red-200';
        $report_status_dot   = 'bg-red-500';
    } elseif ($rs === 'approved_by_supervisor') {
        $report_status_label = 'Approved by Supervisor';
        $report_status_color = 'text-emerald-700 bg-emerald-50 border-emerald-200';
        $report_status_dot   = 'bg-emerald-500';
    } elseif ($rs === 'approved_by_instructor') {
        $report_status_label = 'Graded';
        $report_status_color = 'text-teal-700 bg-teal-50 border-teal-200';
        $report_status_dot   = 'bg-teal-500';
    }
}

// ── ANALYTICS DATA ──
$hours_stmt = $db->prepare("SELECT calculated_duration FROM daily_logs WHERE internship_id = ?");
$hours_stmt->bind_param("i", $internship_id);
$hours_stmt->execute();
$hrs_res = $hours_stmt->get_result();
$all_durations = [];
if ($hrs_res) {
    while ($row = $hrs_res->fetch_row()) {
        $all_durations[] = $row[0];
    }
}

$total_minutes = 0;
foreach ($all_durations as $dur) {
    $parts = explode(':', $dur);
    if (count($parts) === 2) {
        $total_minutes += ((int)$parts[0] * 60) + (int)$parts[1];
    }
}
$total_hours = floor($total_minutes / 60);
$total_mins  = $total_minutes % 60;

$total_logs_count = count($all_logs);

$total_ref_stmt = $db->prepare("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = ?");
$total_ref_stmt->bind_param("i", $internship_id);
$total_ref_stmt->execute();
$tr_res = $total_ref_stmt->get_result();
$tr_row = $tr_res ? $tr_res->fetch_row() : null;
$total_reflections_count = (int) ($tr_row[0] ?? 0);

$weeks_completed = 0;
if (!empty($weeks)) {
    $wc_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    foreach ($weeks as $wn => $wr) {
        $wc_stmt->bind_param("iss", $internship_id, $wr['start'], $wr['end']);
        $wc_stmt->execute();
        $wc_res = $wc_stmt->get_result();
        $wc_row = $wc_res ? $wc_res->fetch_row() : null;
        if ((int) ($wc_row[0] ?? 0) > 0) {
            $weeks_completed++;
        }
    }
}
$total_weeks = count($weeks);
$total_present_days = $present_count;
$total_expected_days = 0;
if ($intern_start && $intern_end) {
    $att_cursor = new DateTime($intern_start);
    $att_end    = new DateTime($intern_end);
    while ($att_cursor <= $att_end) {
        if ((int) $att_cursor->format('N') <= 5) $total_expected_days++;
        $att_cursor->modify('+1 day');
    }
}
if ($total_expected_days < 1) $total_expected_days = max(1, $total_weeks * 5);
$attendance_rate = round(($total_present_days / max(1, $total_expected_days)) * 100);

// Chart data
$weekly_hours_data = [];
$weekly_hours_labels = [];
if (!empty($weeks)) {
    $chart_weeks = array_slice($weeks, -8, 8, true);
    $wh_stmt = $db->prepare("SELECT calculated_duration FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    foreach ($chart_weeks as $cw_num => $cw_range) {
        $wh_stmt->bind_param("iss", $internship_id, $cw_range['start'], $cw_range['end']);
        $wh_stmt->execute();
        $wh_res = $wh_stmt->get_result();
        $week_mins = 0;
        if ($wh_res) {
            while ($row = $wh_res->fetch_row()) {
                $p = explode(':', $row[0]);
                if (count($p) === 2) $week_mins += ((int)$p[0] * 60) + (int)$p[1];
            }
        }
        $weekly_hours_labels[] = 'Week ' . $cw_num;
        $weekly_hours_data[] = round($week_mins / 60, 1);
    }
}

$att_all_stmt = $db->prepare("SELECT attendance_status, COUNT(*) as cnt FROM daily_logs WHERE internship_id = ? GROUP BY attendance_status");
$att_all_stmt->bind_param("i", $internship_id);
$att_all_stmt->execute();
$att_res = $att_all_stmt->get_result();
$att_breakdown = [];
if ($att_res) {
    while ($row = $att_res->fetch_assoc()) {
        $att_breakdown[$row['attendance_status']] = (int) $row['cnt'];
    }
}

$recent_act_stmt = $db->prepare("SELECT log_date, attendance_status, task_title, calculated_duration FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC LIMIT 5");
$recent_act_stmt->bind_param("i", $internship_id);
$recent_act_stmt->execute();
$act_res = $recent_act_stmt->get_result();
$recent_activities = $act_res ? $act_res->fetch_all(MYSQLI_ASSOC) : [];

$notif_eval_stmt = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? ORDER BY evaluated_at DESC LIMIT 5");
$notif_eval_stmt->bind_param("i", $internship_id);
$notif_eval_stmt->execute();
$eval_res = $notif_eval_stmt->get_result();
$recent_evaluations = $eval_res ? $eval_res->fetch_all(MYSQLI_ASSOC) : [];

$sup_eval_list_stmt = $db->prepare("SELECT * FROM supervisor_weekly_evaluations WHERE student_id = ? ORDER BY week_number DESC LIMIT 5");
$sup_eval_list_stmt->bind_param("i", $internship_id);
$sup_eval_list_stmt->execute();
$sup_list_res = $sup_eval_list_stmt->get_result();
$sup_evaluations = $sup_list_res ? $sup_list_res->fetch_all(MYSQLI_ASSOC) : [];

if ($magic_link_unlocked && empty($existing_link) && !$is_rejected) {
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    $link_stmt = $db->prepare("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
    $link_stmt->bind_param("iiss", $internship_id, $selected_week, $token, $expires_at);
    $link_stmt->execute();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard – InternReport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
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
            var end = document.getElementById('end_time').value;
            if (start && end) {
                var s = start.split(':'),
                    e = end.split(':');
                var sm = parseInt(s[0]) * 60 + parseInt(s[1]);
                var em = parseInt(e[0]) * 60 + parseInt(e[1]);
                if (em < sm) em += 1440;
                var diff = em - sm;
                var h = Math.floor(diff / 60);
                var m = diff % 60;
                var pad = function(n) {
                    return n < 10 ? '0' + n : n;
                };
                document.getElementById('hours_display').value = pad(h) + ':' + pad(m);
                document.getElementById('hours_worked').value = pad(h) + ':' + pad(m);
            }
        }

        function calcHoursNow() {
            calcHours();
        }

        function copyLink() {
            var input = document.getElementById('magic_link_input');
            if (!input || !input.value) return;
            navigator.clipboard.writeText(input.value).then(function() {
                var btn = document.getElementById('copy_btn');
                if (btn) {
                    btn.innerHTML = '<svg class="w-3.5 h-3.5 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Copied!</span>';
                    btn.classList.add('bg-emerald-600', 'hover:bg-emerald-700');
                    btn.classList.remove('bg-purple-600', 'hover:bg-purple-700');
                    setTimeout(function() {
                        btn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg><span>Copy Link</span>';
                        btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
                        btn.classList.add('bg-purple-600', 'hover:bg-purple-700');
                    }, 2000);
                }
            });
        }

        function switchStudentSigType(type) {
            var typed = document.getElementById('student-sig-typed-fields');
            var upload = document.getElementById('student-sig-upload-fields');
            var hidden = document.getElementById('student_sig_type_input');
            var btnT = document.getElementById('btn-student-typed');
            var btnU = document.getElementById('btn-student-uploaded');
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
            var el = document.getElementById('student_sig_preview');
            if (el) el.textContent = name || '—';
        }

        // ── Left-column signature form functions ──
        function switchLeftSigType(type) {
            var typed = document.getElementById('left-sig-typed-fields');
            var upload = document.getElementById('left-sig-upload-fields');
            var hidden = document.getElementById('left-sig-type-input');
            var btnT = document.getElementById('left-btn-typed');
            var btnU = document.getElementById('left-btn-uploaded');
            if (!typed || !upload || !hidden || !btnT || !btnU) return;
            if (type === 'typed') {
                typed.classList.remove('hidden');
                upload.classList.add('hidden');
                hidden.value = 'typed';
                btnT.className = 'px-3 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600 shadow-2xs';
                btnU.className = 'px-3 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
            } else {
                typed.classList.add('hidden');
                upload.classList.remove('hidden');
                hidden.value = 'uploaded';
                btnU.className = 'px-3 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600 shadow-2xs';
                btnT.className = 'px-3 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
            }
        }

        function previewLeftSig() {
            var input = document.getElementById('left_typed_name');
            var name = input ? input.value : '';
            var el = document.getElementById('left_sig_preview');
            if (el) el.textContent = name.trim() ? name : '—';
        }

        function showInstructions() {
            window.location.href = 'instructions.php';
        }

        function toggleWeekDropdown() {
            var menu = document.getElementById('week-menu');
            if (menu) menu.classList.toggle('hidden');
        }

        document.addEventListener('click', function(e) {
            var dd = document.getElementById('week-dropdown');
            var menu = document.getElementById('week-menu');
            if (dd && menu && !dd.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });

        var internStart = <?= $intern_start ? "'" . htmlspecialchars($intern_start) . "'" : 'null' ?>;
        var internEnd = <?= $intern_end ? "'" . htmlspecialchars($intern_end) . "'" : 'null' ?>;
        var weekStart = <?= !empty($weeks[$selected_week]) ? "'" . htmlspecialchars($weeks[$selected_week]['start']) . "'" : 'null' ?>;
        var weekEnd = <?= !empty($weeks[$selected_week]) ? "'" . htmlspecialchars($weeks[$selected_week]['end']) . "'" : 'null' ?>;
        var selectedWeek = <?= (int) $selected_week ?>;
        var existingLogs = <?= json_encode($existing_log_dates, JSON_HEX_TAG) ?>;
        var submittedWeeks = <?= json_encode($submitted_weeks, JSON_HEX_TAG) ?>;
        var allWeeks = <?= json_encode($weeks, JSON_HEX_TAG) ?>;
        var existingSet = {};
        existingLogs.forEach(function(d) {
            existingSet[d] = true;
        });

        // ── Helper to find which week number a given ISO date belongs to ──
        function getWeekForDate(iso) {
            if (!iso) return 0;
            if (allWeeks) {
                for (var wn in allWeeks) {
                    if (allWeeks.hasOwnProperty(wn)) {
                        var range = allWeeks[wn];
                        if (iso >= range.start && iso <= range.end) {
                            return parseInt(wn, 10);
                        }
                    }
                }
            }
            if (internStart) {
                return calcInternshipWeek(internStart, iso);
            }
            return 0;
        }

        // ── Refresh week-state globals from #tab-content data attributes ──
        function refreshWeekState() {
            var tc = document.getElementById('tab-content');
            if (!tc) return;
            if (tc.getAttribute('data-intern-start')) internStart = tc.getAttribute('data-intern-start');
            if (tc.getAttribute('data-intern-end')) internEnd = tc.getAttribute('data-intern-end');
            if (tc.getAttribute('data-week-start')) weekStart = tc.getAttribute('data-week-start');
            if (tc.getAttribute('data-week-end')) weekEnd = tc.getAttribute('data-week-end');
            if (tc.getAttribute('data-week')) selectedWeek = parseInt(tc.getAttribute('data-week'), 10);
            if (tc.getAttribute('data-existing')) {
                try {
                    existingLogs = JSON.parse(tc.getAttribute('data-existing'));
                    existingSet = {};
                    existingLogs.forEach(function(d) {
                        existingSet[d] = true;
                    });
                } catch (e) {
                    /* keep previous state */
                }
            }
        }

        // ── AJAX tab/week switching (no page reloads) ──
        function ajaxLoad(url) {
            var tc = document.getElementById('tab-content');
            if (!tc) return;
            var sep = url.indexOf('?') !== -1 ? '&' : '?';
            fetch(url + sep + 'ajax=1')
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var frag = tmp.querySelector('#tab-content');
                    if (!frag) {
                        window.location.href = url;
                        return;
                    }
                    var newTc = frag;
                    tc.innerHTML = newTc.innerHTML;
                    ['data-week', 'data-week-start', 'data-week-end', 'data-intern-start', 'data-intern-end', 'data-existing'].forEach(function(k) {
                        tc.setAttribute(k, newTc.getAttribute(k) || '');
                    });
                    refreshWeekState();
                    bindLogDateInput();
                    history.pushState({
                        url: url
                    }, '', url);
                    var wm = document.getElementById('week-menu');
                    if (wm) wm.classList.add('hidden');
                })
                .catch(function() {
                    window.location.href = url;
                });
        }

        // ── Check for any missing working day before target date ──
        function getMissingPriorDate(targetIso) {
            if (!internStart || !targetIso) return null;
            var cur = new Date(internStart + 'T00:00:00');
            var target = new Date(targetIso + 'T00:00:00');
            while (cur < target) {
                var day = cur.getDay(); // 0=Sun, 6=Sat
                if (day > 0 && day < 6) { // Weekdays only
                    var y = cur.getFullYear();
                    var m = String(cur.getMonth() + 1).padStart(2, '0');
                    var d = String(cur.getDate()).padStart(2, '0');
                    var cIso = y + '-' + m + '-' + d;
                    if (!existingSet[cIso]) {
                        return cIso;
                    }
                }
                cur.setDate(cur.getDate() + 1);
            }
            return null;
        }

        // Bind the log-date change handler (re-run after AJAX swaps)
        function bindLogDateInput() {
            var logDateInput = document.getElementById('log_date');
            if (!logDateInput || logDateInput.dataset.bound) return;
            logDateInput.dataset.bound = '1';
            logDateInput.addEventListener('change', function() {
                updateWeekBadge();
                var iso = this.value;
                if (!iso) return;

                // 1. Weekend check
                var dayOfWeek = new Date(iso + 'T00:00:00').getDay();
                if (dayOfWeek === 0 || dayOfWeek === 6) {
                    openDateAlertModal({
                        title: 'ပိတ်ရက် ရွေးချယ်၍ မရပါ',
                        badge: 'Weekend Day',
                        message: 'Weekend days (Saturday & Sunday) are not available for daily logs.',
                        myanmarNote: 'စနေ နှင့် တနင်္ဂနွေ ပိတ်ရက်များတွင် Daily Log ဖြည့်သွင်းခွင့် မရှိပါ။ တနင်္လာ မှ သောကြာ ကြား အလုပ်လုပ်ရက်ကို ရွေးချယ်ပေးပါရန်။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }

                // 2. Internship period bounds check
                if (internStart && iso < internStart) {
                    openDateAlertModal({
                        title: 'Internship မစတင်မီ ရက်စွဲဖြစ်နေပါသည်',
                        badge: 'Invalid Period',
                        message: 'Date cannot be before your internship start date (' + formatDisplayDate(internStart) + ').',
                        myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် သင်၏ Internship စတင်သည့်နေ့ (' + formatDisplayDate(internStart) + ') မတိုင်မီ ဖြစ်နေပါသည်။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }
                if (internEnd && iso > internEnd) {
                    openDateAlertModal({
                        title: 'Internship ပြီးဆုံးပြီး ရက်စွဲဖြစ်နေပါသည်',
                        badge: 'Invalid Period',
                        message: 'Date cannot be after your internship end date (' + formatDisplayDate(internEnd) + ').',
                        myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် သင်၏ Internship ပြီးဆုံးသည့်နေ့ (' + formatDisplayDate(internEnd) + ') ထက် ကျော်လွန်နေပါသည်။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }

                // 3. Check which Week this date belongs to
                var dateWeek = getWeekForDate(iso);
                if (dateWeek > 0 && dateWeek !== selectedWeek) {
                    var isThatWeekSubmitted = submittedWeeks && Boolean(submittedWeeks[dateWeek]);
                    if (isThatWeekSubmitted) {
                        openDateAlertModal({
                            title: 'အစီရင်ခံစာ တင်ပြီးသော ရက်စွဲဖြစ်နေပါသည်',
                            badge: 'Report Submitted (Locked)',
                            message: 'This date belongs to Week ' + dateWeek + ', which has already been submitted and locked.',
                            myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် Week ' + dateWeek + ' မှ ရက်စွဲဖြစ်ပြီး Weekly Report တင်သွင်းပြီးဖြစ်၍ ပြင်ဆင်ခွင့် မရှိတော့ပါ။ လက်ရှိ Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' မှ ' + formatDisplayDate(weekEnd) + ' ထိ) အတွင်းရှိ ရက်စွဲကိုသာ ရွေးချယ်ပေးပါရန်။',
                            type: 'warning'
                        });
                    } else {
                        openDateAlertModal({
                            title: 'အခြား Week မှ ရက်စွဲဖြစ်နေပါသည်',
                            badge: 'Out of Current Week',
                            message: 'This date belongs to Week ' + dateWeek + '. Please choose a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').',
                            myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် Week ' + dateWeek + ' မှ ရက်စွဲဖြစ်ပါသည်။ လက်ရှိ Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' မှ ' + formatDisplayDate(weekEnd) + ' ထိ) အတွင်းရှိ ရက်စွဲကိုသာ ရွေးချယ်ပေးပါရန်။',
                            type: 'warning'
                        });
                    }
                    this.value = '';
                    updateWeekBadge();
                    return;
                }

                // 4. Week bounds fallback
                if ((weekStart && iso < weekStart) || (weekEnd && iso > weekEnd)) {
                    openDateAlertModal({
                        title: 'ရွေးချယ်ထားသော Week တွင် မပါဝင်ပါ',
                        badge: 'Out of Week Range',
                        message: 'Please select a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').',
                        myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' မှ ' + formatDisplayDate(weekEnd) + ' ထိ) အတွင်း မရှိပါ။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }

                // 5. Current Week Submitted / Locked check
                if (submittedWeeks && Boolean(submittedWeeks[selectedWeek])) {
                    openDateAlertModal({
                        title: 'အစီရင်ခံစာ တင်ပြီးဖြစ်ပါသည်',
                        badge: 'Report Submitted (Locked)',
                        message: 'Week ' + selectedWeek + ' report has already been submitted and is locked.',
                        myanmarNote: 'Week ' + selectedWeek + ' အတွက် Weekly Report တင်သွင်းပြီးဖြစ်၍ မှတ်တမ်းအသစ်ဖြည့်ခြင်း သို့မဟုတ် ပြင်ဆင်ခြင်း မပြုလုပ်နိုင်တော့ပါ။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }

                // 6. Already exists in current week check
                if (existingSet[iso]) {
                    openDateAlertModal({
                        title: 'မှတ်တမ်း ဖြည့်သွင်းပြီးဖြစ်ပါသည်',
                        badge: 'Daily Log Exists',
                        message: 'A daily log for ' + formatDisplayDate(this.value) + ' already exists in Week ' + selectedWeek + '.',
                        myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲ (' + formatDisplayDate(this.value) + ') အတွက် Daily Log ရေးသွင်းပြီး ဖြစ်ပါသည်။ ပြင်ဆင်လိုပါက အောက်ပါ History ဇယားရှိ ✏️ Edit ခလုတ်ကို နှိပ်ပါ။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }

                // 7. Sequential check (cannot skip unlogged days)
                var missingDate = getMissingPriorDate(iso);
                if (missingDate) {
                    openDateAlertModal({
                        title: 'ရက်ကျော်၍ ဖြည့်သွင်းခွင့် မရှိပါ',
                        badge: 'Sequential Logging Required',
                        message: 'Cannot skip days. Please log the missing working day (' + formatDisplayDate(missingDate) + ') first before logging this date.',
                        myanmarNote: 'ရက်ကျော်၍ ဖြည့်သွင်းခွင့် မရှိပါ။ ကျန်ရှိနေသော အလုပ်လုပ်ရက် (' + formatDisplayDate(missingDate) + ') အတွက် Daily Log ကို အရင်ဖြည့်ပေးပါရန်။',
                        type: 'warning'
                    });
                    this.value = '';
                    updateWeekBadge();
                    return;
                }
            });
            updateWeekBadge();
        }

        // Delegated handler: intercept tab/week links inside #tab-content
        document.addEventListener('click', function(e) {
            var a = e.target.closest ? e.target.closest('#tab-content a[href]') : null;
            if (!a) return;
            var href = a.getAttribute('href') || '';
            if (href.indexOf('student-dashboard.php?tab=') === 0 || href.indexOf('?tab=') === 0) {
                e.preventDefault();
                ajaxLoad(new URL(a.href, window.location.href).href);
            }
        });

        window.addEventListener('popstate', function() {
            var tc = document.getElementById('tab-content');
            if (!tc) return;
            fetch(window.location.href + (window.location.href.indexOf('?') !== -1 ? '&' : '?') + 'ajax=1')
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var frag = tmp.querySelector('#tab-content');
                    if (!frag) {
                        window.location.reload();
                        return;
                    }
                    tc.innerHTML = frag.innerHTML;
                    ['data-week', 'data-week-start', 'data-week-end', 'data-intern-start', 'data-intern-end', 'data-existing'].forEach(function(k) {
                        tc.setAttribute(k, frag.getAttribute(k) || '');
                    });
                    refreshWeekState();
                    bindLogDateInput();
                });
        });

        // ── Date format helpers (DD.MM.YYYY ↔ YYYY-MM-DD) ──
        function parseDisplayDate(str) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(str)) return null;
            var parts = str.split('-');
            var date = new Date(str + 'T00:00:00');
            if (isNaN(date.getTime()) || date.getFullYear() !== Number(parts[0]) || date.getMonth() + 1 !== Number(parts[1]) || date.getDate() !== Number(parts[2])) return null;
            return str;
        }

        function formatDisplayDate(iso) {
            return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB');
        }

        function validateLogDate(e) {
            var dateInput = document.getElementById('log_date');
            if (!dateInput || !dateInput.value) return true;
            var iso = parseDisplayDate(dateInput.value);
            if (!iso) {
                openDateAlertModal({
                    title: 'ရက်စွဲ ရွေးချယ်ပေးပါ',
                    badge: 'Invalid Date',
                    message: 'Please select a valid date from the calendar.',
                    myanmarNote: 'ပြက္ခဒိန်မှ မှန်ကန်သော ရက်စွဲကို ရွေးချယ်ပေးပါရန်။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }
            var dayOfWeek = new Date(iso + 'T00:00:00').getDay();
            if (dayOfWeek === 0 || dayOfWeek === 6) {
                openDateAlertModal({
                    title: 'ပိတ်ရက် ရွေးချယ်၍ မရပါ',
                    badge: 'Weekend Day',
                    message: 'Weekend days (Saturday & Sunday) are not available for daily logs. Please select a weekday.',
                    myanmarNote: 'စနေ နှင့် တနင်္ဂနွေ ပိတ်ရက်များတွင် Daily Log ဖြည့်သွင်းခွင့် မရှိပါ။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }
            if (internStart && iso < internStart) {
                openDateAlertModal({
                    title: 'Internship မစတင်မီ ရက်စွဲဖြစ်နေပါသည်',
                    badge: 'Invalid Period',
                    message: 'Date cannot be before your internship start date (' + formatDisplayDate(internStart) + ').',
                    myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် သင်၏ Internship စတင်သည့်နေ့ မတိုင်မီ ဖြစ်နေပါသည်။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }
            if (internEnd && iso > internEnd) {
                openDateAlertModal({
                    title: 'Internship ပြီးဆုံးပြီး ရက်စွဲဖြစ်နေပါသည်',
                    badge: 'Invalid Period',
                    message: 'Date cannot be after your internship end date (' + formatDisplayDate(internEnd) + ').',
                    myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် သင်၏ Internship ပြီးဆုံးသည့်နေ့ ထက် ကျော်လွန်နေပါသည်။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }

            var dateWeek = getWeekForDate(iso);
            if (dateWeek > 0 && dateWeek !== selectedWeek) {
                var isThatWeekSubmitted = submittedWeeks && Boolean(submittedWeeks[dateWeek]);
                if (isThatWeekSubmitted) {
                    openDateAlertModal({
                        title: 'အစီရင်ခံစာ တင်ပြီးသော ရက်စွဲဖြစ်နေပါသည်',
                        badge: 'Report Submitted (Locked)',
                        message: 'This date belongs to Week ' + dateWeek + ', which has already been submitted and locked.',
                        myanmarNote: 'ဤရက်စွဲသည် Week ' + dateWeek + ' မှ ရက်စွဲဖြစ်ပြီး Weekly Report တင်သွင်းပြီးဖြစ်၍ ပြင်ဆင်ခွင့် မရှိတော့ပါ။ လက်ရှိ Week ' + selectedWeek + ' အတွင်းရှိ ရက်စွဲကိုသာ ရွေးချယ်ပေးပါရန်။',
                        type: 'warning'
                    });
                } else {
                    openDateAlertModal({
                        title: 'အခြား Week မှ ရက်စွဲဖြစ်နေပါသည်',
                        badge: 'Out of Current Week',
                        message: 'This date belongs to Week ' + dateWeek + '.',
                        myanmarNote: 'ဤရက်စွဲသည် Week ' + dateWeek + ' မှ ရက်စွဲဖြစ်ပါသည်။ လက်ရှိ Week ' + selectedWeek + ' အတွင်းရှိ ရက်စွဲကိုသာ ရွေးချယ်ပေးပါရန်။',
                        type: 'warning'
                    });
                }
                e.preventDefault();
                return false;
            }

            if ((weekStart && iso < weekStart) || (weekEnd && iso > weekEnd)) {
                openDateAlertModal({
                    title: 'ရွေးချယ်ထားသော Week တွင် မပါဝင်ပါ',
                    badge: 'Out of Week Range',
                    message: 'Please select a date within Week ' + selectedWeek + ' (' + formatDisplayDate(weekStart) + ' – ' + formatDisplayDate(weekEnd) + ').',
                    myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် Week ' + selectedWeek + ' အတွင်း မရှိပါ။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }

            if (submittedWeeks && Boolean(submittedWeeks[selectedWeek])) {
                openDateAlertModal({
                    title: 'အစီရင်ခံစာ တင်ပြီးဖြစ်ပါသည်',
                    badge: 'Report Submitted (Locked)',
                    message: 'Week ' + selectedWeek + ' report has already been submitted and is locked.',
                    myanmarNote: 'Week ' + selectedWeek + ' အတွက် Weekly Report တင်သွင်းပြီးဖြစ်၍ မှတ်တမ်းအသစ်ဖြည့်ခြင်း သို့မဟုတ် ပြင်ဆင်ခြင်း မပြုလုပ်နိုင်တော့ပါ။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }

            if (existingSet[iso]) {
                openDateAlertModal({
                    title: 'မှတ်တမ်း ဖြည့်သွင်းပြီးဖြစ်ပါသည်',
                    badge: 'Daily Log Exists',
                    message: 'A daily log for ' + formatDisplayDate(dateInput.value) + ' already exists.',
                    myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲ (' + formatDisplayDate(dateInput.value) + ') အတွက် Daily Log ရေးသွင်းပြီး ဖြစ်ပါသည်။ ပြင်ဆင်လိုပါက အောက်ပါ History ဇယားရှိ ✏️ Edit ခလုတ်ကို နှိပ်ပါ။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }

            var missingDate = getMissingPriorDate(iso);
            if (missingDate) {
                openDateAlertModal({
                    title: 'ရက်ကျော်၍ ဖြည့်သွင်းခွင့် မရှိပါ',
                    badge: 'Sequential Logging Required',
                    message: 'Cannot skip days. Please log the missing working day (' + formatDisplayDate(missingDate) + ') first before logging this date.',
                    myanmarNote: 'ရက်ကျော်၍ ဖြည့်သွင်းခွင့် မရှိပါ။ ကျန်ရှိနေသော အလုပ်လုပ်ရက် (' + formatDisplayDate(missingDate) + ') အတွက် Daily Log ကို အရင်ဖြည့်ပေးပါရန်။',
                    type: 'warning'
                });
                e.preventDefault();
                return false;
            }
            return true;
        }

        // ── JS Week Number Calculator (mirrors PHP getInternshipWeekNumber) ──
        function calcInternshipWeek(startDate, selectedDate) {
            var start = new Date(startDate + 'T00:00:00');
            var sel = new Date(selectedDate + 'T00:00:00');
            if (sel < start) return 0;

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

            var diffMs = sel.getTime() - endOfWeek1.getTime();
            var diffDays = Math.round(diffMs / 86400000);
            return 1 + Math.ceil(diffDays / 7);
        }

        function updateWeekBadge() {
            var display = document.getElementById('log_date');
            var badge = document.getElementById('week-badge');
            var badgeNum = document.getElementById('week-badge-num');
            if (!display) return;

            var dayLabel = document.getElementById('selected-day');
            var iso = parseDisplayDate(display.value);
            if (!iso) {
                badge.classList.add('hidden');
                if (dayLabel) dayLabel.textContent = 'Choose a date from the calendar.';
                return;
            }

            if (dayLabel) {
                dayLabel.textContent = new Date(iso + 'T00:00:00').toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
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
            var absent = document.getElementById('absent-fields');
            if (status === 'absent') {
                present.classList.add('hidden');
                absent.classList.remove('hidden');
            } else {
                present.classList.remove('hidden');
                absent.classList.add('hidden');
            }
        }

        // ── Center Date Alert Board Modal ──
        function openDateAlertModal(opts) {
            var modal = document.getElementById('dateAlertModal');
            var card = document.getElementById('dateAlertCard');
            if (!modal || !card) {
                if (opts.message) showToast(opts.message, 'warning');
                return;
            }

            var titleEl = document.getElementById('modalTitle');
            var msgEl = document.getElementById('modalMessage');
            var myanmarEl = document.getElementById('modalMyanmarNote');
            var badgeEl = document.getElementById('modalBadge');
            var badgeDot = document.getElementById('modalBadgeDot');
            var iconContainer = document.getElementById('modalIconContainer');
            var topBorder = document.getElementById('modalTopBorder');

            var type = opts.type || 'warning';

            if (titleEl) titleEl.textContent = opts.title || 'ရွေးချယ်၍ မရပါ';
            if (msgEl) msgEl.textContent = opts.message || '';
            if (myanmarEl) {
                if (opts.myanmarNote) {
                    myanmarEl.innerHTML = '<svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>' + opts.myanmarNote + '</span>';
                    myanmarEl.classList.remove('hidden');
                } else {
                    myanmarEl.classList.add('hidden');
                }
            }
            if (badgeEl) badgeEl.textContent = opts.badge || 'သတိပေးချက်';

            if (type === 'error') {
                iconContainer.className = 'w-16 h-16 mx-auto mb-3.5 rounded-2xl bg-rose-50 text-rose-500 border border-rose-100 flex items-center justify-center shadow-xs';
                badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-rose-500';
                topBorder.className = 'absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-rose-500 to-red-600';
            } else {
                iconContainer.className = 'w-16 h-16 mx-auto mb-3.5 rounded-2xl bg-amber-50 text-amber-500 border border-amber-100 flex items-center justify-center shadow-xs';
                badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-amber-500';
                topBorder.className = 'absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-amber-400 via-rose-500 to-indigo-500';
            }

            modal.classList.remove('opacity-0', 'pointer-events-none');
            modal.classList.add('opacity-100', 'pointer-events-auto');

            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        }

        function closeDateAlertModal() {
            var modal = document.getElementById('dateAlertModal');
            var card = document.getElementById('dateAlertCard');
            if (!modal || !card) return;

            modal.classList.remove('opacity-100', 'pointer-events-auto');
            modal.classList.add('opacity-0', 'pointer-events-none');

            card.classList.remove('scale-100', 'opacity-100');
            card.classList.add('scale-95', 'opacity-0');
        }

        // ── Center Signature Success Modal ──
        function openSignSuccessModal(opts) {
            var modal = document.getElementById('signSuccessModal');
            var card = document.getElementById('signSuccessCard');
            if (!modal || !card) {
                if (opts && opts.message) showToast(opts.message, 'success');
                return;
            }

            var titleEl = document.getElementById('signModalTitle');
            var msgEl = document.getElementById('signModalMessage');
            var myanmarEl = document.getElementById('signModalMyanmarNote');
            var badgeEl = document.getElementById('signModalBadge');
            var instructorNameEl = document.getElementById('signModalInstructorName');
            var instructorEmailEl = document.getElementById('signModalInstructorEmail');

            if (opts) {
                if (titleEl && opts.title) titleEl.textContent = opts.title;
                if (msgEl && opts.message) msgEl.textContent = opts.message;
                if (badgeEl && opts.badge) badgeEl.textContent = opts.badge;
                if (myanmarEl && opts.myanmarNote) {
                    var noteSpan = myanmarEl.querySelector('.note-text');
                    if (noteSpan) noteSpan.textContent = opts.myanmarNote;
                }
                if (instructorNameEl && opts.instructorName) instructorNameEl.textContent = opts.instructorName;
                if (instructorEmailEl && opts.instructorEmail) instructorEmailEl.textContent = opts.instructorEmail;
            }

            modal.classList.remove('opacity-0', 'pointer-events-none');
            modal.classList.add('opacity-100', 'pointer-events-auto');

            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        }

        function closeSignSuccessModal() {
            var modal = document.getElementById('signSuccessModal');
            var card = document.getElementById('signSuccessCard');
            if (!modal || !card) return;

            modal.classList.remove('opacity-100', 'pointer-events-auto');
            modal.classList.add('opacity-0', 'pointer-events-none');

            card.classList.remove('scale-100', 'opacity-100');
            card.classList.add('scale-95', 'opacity-0');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDateAlertModal();
                closeSignSuccessModal();
            }
        });

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
                setTimeout(function() {
                    toast.remove();
                }, 300);
            }, 3000);
        }

        window.onload = function() {
            document.addEventListener('click', function(e) {
                var dd = document.getElementById('week-dropdown');
                if (dd && !dd.contains(e.target)) {
                    document.getElementById('week-menu').classList.add('hidden');
                }
            });
            bindLogDateInput();
            <?php if ($message === 'daily_saved'): ?>
                showToast('Daily log saved successfully.', 'success');
            <?php elseif ($message === 'log_updated'): ?>
                showToast('Daily log updated successfully.', 'success');
            <?php elseif ($message === 'log_deleted'): ?>
                showToast('Daily log deleted successfully.', 'success');
            <?php elseif ($message === 'reflection_saved'): ?>
                showToast('Weekly reflection saved successfully.', 'success');
            <?php elseif ($message === 'student_sig_required'): ?>
                showToast('Please provide your signature before submitting.', 'error');
            <?php elseif ($message === 'sig_saved_email_dispatched'): ?>
                openSignSuccessModal({
                    title: 'Signed Successfully!',
                    badge: 'Email Dispatched',
                    message: 'Review request email sent to your instructor.',
                    myanmarNote: 'Instructor ထံသို့ စစ်ဆေးလက်မှတ်ထိုးရန် Magic Review Link ပါဝင်သော Email ကို အောင်မြင်စွာ ပို့ဆောင်ပြီးပါပြီ။',
                    instructorName: <?= json_encode($profile_row['instructor_name'] ?? 'Instructor') ?>,
                    instructorEmail: <?= json_encode($profile_row['instructor_email'] ?? '') ?>
                });
            <?php elseif ($message === 'sig_saved_no_instructor_email'): ?>
                showToast('⚠️ Signed successfully, but no instructor email found in profile. Please add instructor email.', 'warning');
            <?php elseif ($message === 'signature_saved'): ?>
                showToast('Student signature saved successfully.', 'success');
            <?php elseif ($message === 'email_sent_success'): ?>
                openSignSuccessModal({
                    title: 'Email Sent Successfully!',
                    badge: 'Resent to Instructor',
                    message: 'Review request email resent to your instructor.',
                    myanmarNote: 'Instructor ထံသို့ စစ်ဆေးလက်မှတ်ထိုးရန် Review Link ကို ပြန်လည် ပို့ဆောင်ပြီးပါပြီ။',
                    instructorName: <?= json_encode($profile_row['instructor_name'] ?? 'Instructor') ?>,
                    instructorEmail: <?= json_encode($profile_row['instructor_email'] ?? '') ?>
                });
            <?php elseif ($message === 'email_no_instructor'): ?>
                showToast('⚠️ No instructor email configured. Please update your profile first.', 'error');
            <?php elseif ($message === 'email_send_failed'): ?>
                showToast('⚠️ Failed to dispatch email. Please check mail configuration.', 'error');
            <?php elseif ($message === 'date_out_of_range'): ?>
                openDateAlertModal({
                    title: 'Internship ကာလအတွင်း မရှိပါ',
                    badge: 'Invalid Period',
                    message: 'The selected date is outside your internship period. Please choose a valid date.',
                    myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် သင်၏ Internship ကာလအတွင်း မရှိပါ။',
                    type: 'warning'
                });
            <?php elseif ($message === 'date_out_of_week'): ?>
                openDateAlertModal({
                    title: 'ရွေးချယ်ထားသော Week တွင် မပါဝင်ပါ',
                    badge: 'Out of Week Range',
                    message: 'You cannot choose this date. The date must be within the selected week.',
                    myanmarNote: 'ရွေးချယ်ထားသော ရက်စွဲသည် လက်ရှိ Week အတွင်း မပါဝင်ပါ။',
                    type: 'warning'
                });
            <?php elseif ($message === 'date_is_weekend'): ?>
                openDateAlertModal({
                    title: 'ပိတ်ရက် ရွေးချယ်၍ မရပါ',
                    badge: 'Weekend Day',
                    message: 'Weekend days (Saturday & Sunday) are not available for daily logs. Please select a weekday.',
                    myanmarNote: 'စနေ နှင့် တနင်္ဂနွေ ပိတ်ရက်များတွင် Daily Log ဖြည့်သွင်းခွင့် မရှိပါ။',
                    type: 'warning'
                });
            <?php elseif ($message === 'duplicate_log'): ?>
                openDateAlertModal({
                    title: 'မှတ်တမ်း ဖြည့်သွင်းပြီးဖြစ်ပါသည်',
                    badge: 'Daily Log Exists',
                    message: 'A daily log already exists for this date. Please choose another date or edit the existing log.',
                    myanmarNote: 'ယခုရွေးချယ်ထားသော ရက်စွဲအတွက် Daily Log ထည့်သွင်းပြီးဖြစ်ပါသည်။',
                    type: 'warning'
                });
            <?php elseif (strpos($message, 'skip_date_') === 0): ?>
                <?php $missing_d = substr($message, 10); ?>
                openDateAlertModal({
                    title: 'ရက်ကျော်၍ ဖြည့်သွင်းခွင့် မရှိပါ',
                    badge: 'Sequential Logging Required',
                    message: 'Cannot skip days. Please log the missing working day (<?= htmlspecialchars((new DateTime($missing_d))->format('d.m.Y')) ?>) first before logging this date.',
                    myanmarNote: 'ရက်ကျော်၍ ဖြည့်သွင်း၍ မရပါ။ ကျန်ရှိနေသော အလုပ်လုပ်ရက် (<?= htmlspecialchars((new DateTime($missing_d))->format('d.m.Y')) ?>) အတွက် Daily Log ကို အရင်ဖြည့်ပေးပါရန်။',
                    type: 'warning'
                });
            <?php elseif ($message === 'log_locked'): ?>
                showToast('This week has been signed and cannot be edited. Wait for instructor rejection to make changes.', 'error');
            <?php endif; ?>
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Dancing+Script:wght@400;700&family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
        html {
            scrollbar-gutter: stable;
            overflow-y: scroll;
        }

        .nav-link {
            color: #ccfbf1;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #fff;
            background: rgba(15, 118, 110, 0.6);
        }

        .active-nav {
            background: #0a9396;
            color: #fff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3);
        }

        .student-sig-preview {
            font-family: 'Great Vibes', cursive;
            font-size: 22px;
            color: #1e293b;
            min-height: 36px;
            line-height: 1.4;
        }

        /* ── Sidebar Utilities ── */
        .glass-sidebar {
            background: #005f73;
            border-right: 1px solid rgba(15, 118, 110, 0.4);
        }

        .glass-sidebar nav {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
        }

        .glass-sidebar nav::-webkit-scrollbar {
            width: 4px;
        }

        .glass-sidebar nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        @media print {

            aside,
            header,
            .no-print {
                display: none !important;
            }

            .flex.h-screen {
                height: auto !important;
                overflow: visible !important;
            }

            main {
                overflow: visible !important;
            }

            #section-main {
                display: block !important;
            }

            body {
                background: white !important;
            }
        }
    </style>
</head>

<body class="bg-slate-100 font-sans antialiased">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
        <div id="studentSidebarBackdrop" onclick="toggleStudentSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

        <!-- ─── SIDEBAR ─── -->
        <aside id="studentSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out glass-sidebar flex flex-col shrink-0 text-white shadow-xl print:hidden">
            <div class="h-16 flex items-center justify-between px-5 border-b border-white/10 shrink-0">
                <span class="font-black text-white tracking-tight text-lg">InternReport</span>
                <button type="button" onclick="toggleStudentSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">✕</button>
            </div>
            <nav class="flex-1 min-h-0 py-4 space-y-1 px-3 overflow-y-auto">
                <a href="student-dashboard.php?tab=overview" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V5zM14 5a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2h-4a2 2 0 01-2-2V5zM4 15a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 15a2 2 0 012-2h4a2 2 0 012 2v4a2 2 0 01-2 2h-4a2 2 0 01-2-2v-4z" />
                    </svg>
                    Dashboard
                </a>
                <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h10" />
                    </svg>
                    Log History
                </a>
                <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" onclick="showInstructions(); return false;">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    Instructions
                </a>
                <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    Profile
                </a>
            </nav>
            <div class="p-3 border-t border-white/10">
                <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 transition-colors duration-200">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Logout
                </a>
            </div>
        </aside>

        <!-- ─── MAIN ─── -->
        <div class="flex-1 flex flex-col min-h-0">

            <!-- Top Bar -->
            <?php $pageTitle = 'Dashboard';
            include '../includes/student-topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto p-6 md:p-8">
                <div class="max-w-7xl mx-auto w-full">

                    <?php $tab_week_qs = !empty($weeks[$selected_week]) ? '&week=' . (int) $selected_week : ''; ?>

                    <!-- AJAX-CONTENT:START -->
                    <div id="tab-content" data-week="<?= (int) $selected_week ?>" data-week-start="<?= htmlspecialchars($weeks[$selected_week]['start'] ?? '') ?>" data-week-end="<?= htmlspecialchars($weeks[$selected_week]['end'] ?? '') ?>" data-intern-start="<?= htmlspecialchars($intern_start ?? '') ?>" data-intern-end="<?= htmlspecialchars($intern_end ?? '') ?>" data-existing="<?= htmlspecialchars(json_encode($existing_log_dates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">

                        <!-- ════ TAB NAVIGATION ════ -->
                        <!-- ════ TAB NAVIGATION & OVERALL ATTENDANCE ════ -->
                        <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-2 mb-6 flex items-center justify-between flex-wrap gap-3">
                            <div class="flex items-center gap-1.5 overflow-x-auto">
                                <a href="student-dashboard.php?tab=overview<?= $tab_week_qs ?>" class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition <?= $tab === 'overview' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                    </svg>
                                    Overview
                                </a>
                                <a href="student-dashboard.php?tab=daily-log<?= $tab_week_qs ?>" class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition <?= $tab === 'daily-log' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
                                    </svg>
                                    Daily Log
                                </a>
                                <a href="student-dashboard.php?tab=weekly-report<?= $tab_week_qs ?>" class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition <?= $tab === 'weekly-report' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                    </svg>
                                    Weekly Report
                                </a>
                            </div>

                            <!-- Right: Overall Internship Attendance Counters -->
                            <div class="flex items-center gap-2.5 px-2">
                                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider hidden lg:inline">Internship Attendance:</span>
                                <!-- Present Tooltip -->
                                <div class="relative group">
                                    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-100 rounded-lg cursor-pointer hover:bg-emerald-100 transition">
                                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span class="text-xs font-bold text-emerald-600">Present</span>
                                        <span class="text-sm font-black text-emerald-700"><?= $present_count ?> Days</span>
                                    </div>
                                    <div class="absolute right-0 top-full mt-2 w-56 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden group-hover:block">
                                        <div class="p-3">
                                            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">All Present Dates</p>
                                            <div class="max-h-48 overflow-y-auto space-y-1 pr-1 text-xs">
                                                <?php if (!empty($present_dates)): ?>
                                                    <?php foreach ($present_dates as $date): ?>
                                                        <?php $d = new DateTime($date); ?>
                                                        <p class="text-slate-700">• <?= $d->format('D, M d, Y') ?></p>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-slate-400">No present days recorded.</p>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-[11px] text-slate-400 mt-2 pt-2 border-t border-slate-100">Total: <?= count($present_dates) ?> day<?= count($present_dates) !== 1 ? 's' : '' ?></p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Absent Tooltip -->
                                <div class="relative group">
                                    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 border border-rose-100 rounded-lg cursor-pointer hover:bg-rose-100 transition">
                                        <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span class="text-xs font-bold text-rose-600">Absent</span>
                                        <span class="text-sm font-black text-rose-700"><?= $absent_count ?> Days</span>
                                    </div>
                                    <div class="absolute right-0 top-full mt-2 w-72 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden group-hover:block">
                                        <div class="p-3">
                                            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">All Absent Dates</p>
                                            <div class="max-h-48 overflow-y-auto space-y-1 pr-1 text-xs">
                                                <?php if (!empty($absent_logs)): ?>
                                                    <?php foreach ($absent_logs as $log): ?>
                                                        <?php $d = new DateTime($log['log_date']); ?>
                                                        <p class="text-slate-700">• <?= $d->format('D, M d, Y') ?> — <?= htmlspecialchars($log['reason_for_absence'] ?: 'No reason') ?></p>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-slate-400">No absences recorded.</p>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-[11px] text-slate-400 mt-2 pt-2 border-t border-slate-100">Total: <?= count($absent_logs) ?> day<?= count($absent_logs) !== 1 ? 's' : '' ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($tab === 'overview'): ?>

                            <?php if (!$intern_start || !$profile_row): ?>
                                <!-- ════ NEW STUDENT SETUP NOTICE ════ -->
                                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200/80 rounded-2xl p-5 mb-6 shadow-xs">
                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center shrink-0 shadow-sm mt-0.5">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h3 class="text-sm font-bold text-slate-800 mb-1">Welcome to InternReport, <?= htmlspecialchars($student_name) ?>!</h3>
                                            <p class="text-xs text-slate-600 leading-relaxed mb-3">Your student profile or internship dates have not been fully configured yet. You can fill in your student profile details or ask your administrator/supervisor to assign your internship dates to unlock weekly reports.</p>
                                            <div class="flex items-center gap-3">
                                                <a href="profile.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-lg transition shadow-xs">
                                                    Complete Profile
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- ════ STUDENT INFO BAR ════ -->
                            <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-5 mb-6">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <!-- Supervisor Card -->
                                    <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-200">
                                        <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs font-bold shrink-0">
                                            <?= htmlspecialchars($sup_initial_display) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Supervisor</p>
                                            <p class="text-sm font-semibold text-slate-700 truncate"><?= htmlspecialchars($supervisor_name) ?></p>
                                        </div>
                                    </div>
                                    <!-- Instructor Card -->
                                    <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-200">
                                        <div class="w-9 h-9 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs font-bold shrink-0">
                                            <?= htmlspecialchars($inst_initial_display) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Instructor</p>
                                            <p class="text-sm font-semibold text-slate-700 truncate"><?= htmlspecialchars($instructor_name) ?></p>
                                        </div>
                                    </div>
                                    <!-- Internship Period Card -->
                                    <?php if ($intern_start && $intern_end): ?>
                                        <div class="flex items-center gap-3 bg-violet-50 rounded-xl px-4 py-3 border border-violet-200">
                                            <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold uppercase tracking-wider text-indigo-500">Internship Period</p>
                                                <p class="text-sm font-semibold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></p>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-200">
                                            <div class="w-9 h-9 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Internship Period</p>
                                                <p class="text-sm font-semibold text-slate-400">Not Configured Yet</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- ════ QUICK ACCESS: DAILY LOG + WEEKLY REPORT ════ -->
                            <div class="w-full grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 items-stretch">

                                <!-- Daily Log shortcut card -->
                                <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-4">
                                            <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
                                                </svg>
                                            </div>
                                            <span class="text-xs font-black uppercase tracking-wider text-slate-400">Week <?= $selected_week ?></span>
                                        </div>
                                        <h3 class="text-base font-black text-slate-800 mb-1">Daily Log</h3>
                                        <?php if (!empty($weeks)): ?>
                                            <p class="text-sm text-slate-500 mb-4"><?= $week_date_range ?: 'Log your daily activities' ?></p>
                                            <div class="flex items-center justify-between mb-1.5">
                                                <span class="text-xs font-bold text-slate-500">Days logged this week</span>
                                                <span class="text-xs font-black text-blue-600"><?= $weekly_log_count ?>/<?= $total_weekdays ?></span>
                                            </div>
                                            <div class="w-full bg-slate-100 rounded-full h-2 mb-1">
                                                <div class="bg-blue-500 rounded-full h-2 transition-all duration-500" style="width: <?= $total_weekdays > 0 ? min(round(($weekly_log_count / $total_weekdays) * 100), 100) : 0 ?>%"></div>
                                            </div>
                                            <?php if ($reflection_unlocked): ?>
                                                <p class="text-xs font-bold text-emerald-600 mt-2">All daily logs for this week are complete.</p>
                                            <?php elseif ($total_weekdays > 0): ?>
                                                <p class="text-xs font-bold text-amber-600 mt-2"><?= ($total_weekdays - $weekly_log_count) ?> more day<?= ($total_weekdays - $weekly_log_count) === 1 ? '' : 's' ?> needed to unlock the weekly report.</p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="text-sm text-slate-400 mb-4">No internship period assigned yet. Please contact the administrator to set up your internship dates.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-5 pt-4 border-t border-slate-200/60">
                                        <a href="student-dashboard.php?tab=daily-log<?= $tab_week_qs ?>" class="flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl shadow-sm transition">
                                            Open Daily Log
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                            </svg>
                                        </a>
                                    </div>
                                </div>

                                <!-- Weekly Report submission overview card -->
                                <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-4">
                                            <div class="w-11 h-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                                </svg>
                                            </div>
                                            <?php
                                            $report_overview_status = 'In Progress';
                                            $report_overview_class = 'text-slate-600 bg-slate-100 border-slate-200';
                                            if (!empty($weeks)) {
                                                if ($is_rejected) {
                                                    $report_overview_status = 'Rejected';
                                                    $report_overview_class = 'text-red-600 bg-red-50 border-red-200';
                                                } elseif ($instructor_evaluated && $instructor_eval['report_status'] === 'approved_by_supervisor') {
                                                    $report_overview_status = 'Approved by Supervisor';
                                                    $report_overview_class = 'text-emerald-600 bg-emerald-50 border-emerald-200';
                                                } elseif ($instructor_evaluated && $instructor_eval['report_status'] === 'approved_by_instructor') {
                                                    $report_overview_status = 'Graded';
                                                    $report_overview_class = 'text-teal-600 bg-teal-50 border-teal-200';
                                                } elseif ($magic_link_unlocked) {
                                                    $report_overview_status = 'Ready to Submit';
                                                    $report_overview_class = 'text-emerald-600 bg-emerald-50 border-emerald-200';
                                                } elseif ($student_signed) {
                                                    $report_overview_status = 'Awaiting Review';
                                                    $report_overview_class = 'text-amber-600 bg-amber-50 border-amber-200';
                                                }
                                            }
                                            ?>
                                            <span class="inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-full border <?= $report_overview_class ?>"><?= $report_overview_status ?></span>
                                        </div>
                                        <h3 class="text-base font-black text-slate-800 mb-4">Weekly Report — Week <?= $selected_week ?></h3>
                                        <?php if (!empty($weeks)): ?>
                                            <ul class="space-y-2.5">
                                                <li class="flex items-center justify-between text-sm">
                                                    <span class="text-slate-500 font-medium">Daily Logs</span>
                                                    <span class="font-bold <?= $reflection_unlocked ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $weekly_log_count ?>/<?= $total_weekdays ?> days</span>
                                                </li>
                                                <li class="flex items-center justify-between text-sm">
                                                    <span class="text-slate-500 font-medium">Weekly Reflection</span>
                                                    <span class="font-bold <?= $reflection_submitted ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $reflection_submitted ? 'Submitted' : 'Pending' ?></span>
                                                </li>
                                                <li class="flex items-center justify-between text-sm">
                                                    <span class="text-slate-500 font-medium">Student Signature</span>
                                                    <span class="font-bold <?= $student_signed ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $student_signed ? 'Signed' : 'Pending' ?></span>
                                                </li>
                                                <li class="flex items-center justify-between text-sm">
                                                    <span class="text-slate-500 font-medium">Instructor Review</span>
                                                    <span class="font-bold <?= $magic_link_unlocked ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $magic_link_unlocked ? 'Email Dispatched' : 'Locked' ?></span>
                                                </li>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-sm text-slate-400">Weekly reports unlock once your internship period is configured.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-5 pt-4 border-t border-slate-200/60">
                                        <a href="student-dashboard.php?tab=weekly-report<?= $tab_week_qs ?>" class="flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold rounded-xl shadow-sm transition">
                                            Open Weekly Report
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                            </svg>
                                        </a>
                                    </div>
                                </div>

                            </div>

                            <!-- ════ STATISTICS CARDS ════ -->
                            <div class="w-full grid grid-cols-1 md:grid-cols-12 gap-6 mb-6">
                                <!-- Total Hours -->
                                <div class="md:col-span-3 h-full">
                                    <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 h-full transition-all duration-300">
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <span class="text-label font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">Logged</span>
                                        </div>
                                        <p class="text-2xl font-black text-slate-800"><?= $total_hours ?>h <?= $total_mins ?>m</p>
                                        <p class="text-xs text-slate-400 font-medium mt-1">Total Hours Worked</p>
                                    </div>
                                </div>
                                <!-- Daily Logs -->
                                <div class="md:col-span-3 h-full">
                                    <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 h-full transition-all duration-300">
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-500 flex items-center justify-center">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
                                                </svg>
                                            </div>
                                            <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">Entries</span>
                                        </div>
                                        <p class="text-2xl font-black text-slate-800"><?= $total_logs_count ?></p>
                                        <p class="text-xs text-slate-400 font-medium mt-1">Daily Logs Submitted</p>
                                    </div>
                                </div>
                                <!-- Attendance Rate -->
                                <div class="md:col-span-3 h-full">
                                    <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 h-full transition-all duration-300">
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <span class="text-xs font-bold <?= $attendance_rate >= 75 ? 'text-emerald-600 bg-emerald-50 border border-emerald-200' : 'text-amber-600 bg-amber-50 border border-amber-200' ?> px-2 py-0.5 rounded-full"><?= $attendance_rate >= 75 ? 'Good' : 'Needs Attention' ?></span>
                                        </div>
                                        <p class="text-2xl font-black text-slate-800"><?= $attendance_rate ?>%</p>
                                        <p class="text-xs text-slate-400 font-medium mt-1"><?= $total_present_days ?> / <?= $total_expected_days ?> days attended</p>
                                    </div>
                                </div>
                                <!-- Weeks Completed -->
                                <div class="md:col-span-3 h-full">
                                    <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 h-full transition-all duration-300">
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-500 flex items-center justify-center">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-full border border-purple-200">Weeks</span>
                                        </div>
                                        <p class="text-2xl font-black text-slate-800"><?= $weeks_completed ?><span class="text-base font-bold text-slate-400">/<?= $total_weeks ?></span></p>
                                        <p class="text-xs text-slate-400 font-medium mt-1">Weeks Completed</p>
                                    </div>
                                </div>
                            </div>

                            <!-- ════ PROGRESS OVERVIEW ════ -->
                            <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-5 mb-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                        Internship Progress
                                    </h3>
                                    <?php $progress_pct = $total_weeks > 0 ? min(round(($weeks_completed / $total_weeks) * 100), 100) : 0; ?>
                                    <span class="font-bold text-indigo-600"><?= $progress_pct ?>% Completed</span>
                                </div>
                                <div class="w-full bg-slate-100 rounded-full h-3 mb-4">
                                    <div class="bg-indigo-500 rounded-full h-3 transition-all duration-700 ease-out shadow-sm" style="width: <?= $progress_pct ?>%;"></div>
                                </div>
                                <div class="flex items-center justify-between text-xs">
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
                                <div class="bg-red-50 border border-red-200 rounded-2xl p-5 mb-6">
                                    <div class="flex items-start gap-3">
                                        <div class="w-10 h-10 rounded-full bg-red-100 text-red-500 flex items-center justify-center shrink-0 mt-0.5">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </div>
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
                                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-6">
                                    <div class="flex items-start gap-3">
                                        <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center shrink-0 mt-0.5">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                            </svg>
                                        </div>
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

                        <?php elseif ($tab === 'daily-log' || $tab === 'weekly-report'): ?>

                            <!-- ══════ FILTER ROW ══════ -->
                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6">
                                <div class="flex items-center justify-between flex-wrap gap-4">
                                    <!-- Left: Week Dropdown -->
                                    <div class="flex items-center gap-3">
                                        <div class="relative" id="week-dropdown">
                                            <button onclick="toggleWeekDropdown()" class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 font-bold text-xs sm:text-sm text-slate-700 hover:bg-slate-100 transition cursor-pointer whitespace-nowrap shadow-2xs">
                                                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                                Week <?= $selected_week ?>
                                                <span class="text-slate-400 text-xs">▾</span>
                                            </button>
                                            <div id="week-menu" class="absolute left-0 top-full mt-1.5 w-60 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden overflow-hidden py-1 max-h-72 overflow-y-auto">
                                                <?php if (!empty($weeks)): ?>
                                                    <?php foreach ($weeks as $wn => $wr): ?>
                                                        <?php
                                                        $w_start = (new DateTime($wr['start']))->format('d M');
                                                        $w_end = (new DateTime($wr['end']))->format('d M Y');
                                                        ?>
                                                        <a href="?tab=<?= urlencode($tab) ?>&week=<?= $wn ?>" class="flex items-center justify-between px-3.5 py-2.5 text-xs font-bold <?= $selected_week === $wn ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700 hover:bg-slate-50' ?> transition">
                                                            <span>Week <?= $wn ?></span>
                                                            <span class="text-[11px] font-medium text-slate-400"><?= $w_start ?> – <?= $w_end ?></span>
                                                        </a>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="px-3.5 py-2 text-xs text-slate-400">No weeks available</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right: Selected Week Date Range -->
                                    <?php if (!empty($weeks[$selected_week])): ?>
                                        <?php
                                        $cur_start = (new DateTime($weeks[$selected_week]['start']))->format('d M Y');
                                        $cur_end = (new DateTime($weeks[$selected_week]['end']))->format('d M Y');
                                        ?>
                                        <div class="flex items-center gap-2 text-xs font-bold text-indigo-700 bg-indigo-50/80 border border-indigo-100 px-3.5 py-2 rounded-xl shadow-2xs">
                                            <svg class="w-4 h-4 text-indigo-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            <span>Week <?= $selected_week ?>: <span class="font-semibold text-indigo-600"><?= $cur_start ?> – <?= $cur_end ?></span></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="w-full space-y-6">

                                <!-- ─── ALL CONTENT: Full-Width Stack ─── -->
                                <div class="w-full space-y-6">

                                    <?php if ($tab === 'daily-log'): ?>

                                        <?php if ($weekly_report_submitted): ?>

                                            <!-- Read-Only Daily Log History Table -->
                                            <?php include 'daily_logs_table.php'; ?>

                                        <?php else: ?>
                                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
                                                <h2 class="text-xs sm:text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center justify-between gap-2">
                                                    <span class="flex items-center gap-2">
                                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
                                                        </svg>
                                                        <?= $editing_log ? 'Edit Daily Log' : 'Daily Log Sheet' ?>
                                                    </span>
                                                    <?php if ($editing_log): ?>
                                                        <a href="student-dashboard.php?tab=daily-log&week=<?= $selected_week ?>" class="text-[11px] font-bold text-rose-600 bg-rose-50 border border-rose-100 px-2.5 py-1 rounded-full hover:bg-rose-100 transition">Cancel Edit</a>
                                                    <?php endif; ?>
                                                </h2>
                                                <form method="POST" class="space-y-4" onsubmit="return validateLogDate(event)">
                                                    <?php if ($editing_log): ?>
                                                        <input type="hidden" name="log_id" value="<?= $editing_log['id'] ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="selected_week" value="<?= (int) $selected_week ?>">
                                                    <!-- Date (always visible) -->
                                                    <div>
                                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">ရက်စွဲ/နေ့</span></label>
                                                        <input type="date" name="log_date" id="log_date" required
                                                            value="<?= htmlspecialchars($form_date ?? '') ?>"
                                                            min="<?= htmlspecialchars($intern_start ?? '') ?>"
                                                            max="<?= htmlspecialchars(!$editing_log && !empty($next_expected_date) ? $next_expected_date : ($intern_end ?? '')) ?>"
                                                            class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition cursor-pointer">
                                                        <?php if (!empty($weeks[$selected_week])): ?>
                                                            <p class="text-[11px] text-slate-400 mt-1">Week <?= $selected_week ?> allowed: <?= (new DateTime($weeks[$selected_week]['start']))->format('d.m.Y') ?> – <?= (new DateTime($weeks[$selected_week]['end']))->format('d.m.Y') ?></p>
                                                        <?php elseif ($intern_start && $intern_end): ?>
                                                            <p class="text-[11px] text-slate-400 mt-1">Allowed: <?= (new DateTime($intern_start))->format('d.m.Y') ?> – <?= (new DateTime($intern_end))->format('d.m.Y') ?></p>
                                                        <?php endif; ?>
                                                        <p id="selected-day" class="text-[11px] text-slate-500 mt-1">Choose a date from the calendar.</p>
                                                        <div id="week-badge" class="hidden mt-1.5">
                                                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-600 bg-indigo-50 border border-indigo-100 px-2 py-0.5 rounded-full">
                                                                Week <span id="week-badge-num">—</span>
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <!-- Attendance Status -->
                                                    <div>
                                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">တက်ရောက်မှုအခြေအနေ</label>
                                                        <div class="flex items-center gap-3 flex-wrap">
                                                            <?php $edit_att = $editing_log['attendance_status'] ?? 'present'; ?>
                                                            <label class="flex items-center gap-2 px-3.5 py-2 bg-emerald-50/80 border border-emerald-200 rounded-xl cursor-pointer hover:bg-emerald-100 text-xs font-bold text-emerald-800 transition">
                                                                <input type="radio" name="attendance_status" value="present" <?= $edit_att === 'present' ? 'checked' : '' ?> onchange="toggleAttendance()" class="w-3.5 h-3.5 accent-emerald-600 cursor-pointer">
                                                                <span>Present <span class="font-normal text-[11px] text-emerald-600">/ တက်ရောက်</span></span>
                                                            </label>
                                                            <label class="flex items-center gap-2 px-3.5 py-2 bg-rose-50/80 border border-rose-200 rounded-xl cursor-pointer hover:bg-rose-100 text-xs font-bold text-rose-800 transition">
                                                                <input type="radio" name="attendance_status" value="absent" <?= $edit_att === 'absent' ? 'checked' : '' ?> onchange="toggleAttendance()" class="w-3.5 h-3.5 accent-rose-600 cursor-pointer">
                                                                <span>Absent <span class="font-normal text-[11px] text-rose-600">/ ခွင့်/ပျက်ကွက်</span></span>
                                                            </label>
                                                        </div>
                                                    </div>

                                                    <!-- ══════ PRESENT FIELDS ══════ -->
                                                    <div id="present-fields" class="<?= $edit_att === 'absent' ? 'hidden' : '' ?>">
                                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                                                            <div>
                                                                <label class="block text-xs font-bold text-slate-700 mb-1.5">Start Time</label>
                                                                <input type="time" name="start_time" id="start_time" value="<?= htmlspecialchars($editing_log && $editing_log['calculated_duration'] ? substr($editing_log['calculated_duration'], 0, 5) : '09:00') ?>" onchange="calcHours()" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3 py-2 font-mono text-xs sm:text-sm text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                                            </div>
                                                            <div>
                                                                <label class="block text-xs font-bold text-slate-700 mb-1.5">End Time</label>
                                                                <input type="time" name="end_time" id="end_time" value="17:00" onchange="calcHours()" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3 py-2 font-mono text-xs sm:text-sm text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                                            </div>
                                                            <div>
                                                                <label class="block text-xs font-bold text-slate-700 mb-1.5">ကြာချိန်</label>
                                                                <input type="text" id="hours_display" value="<?= htmlspecialchars($editing_log['calculated_duration'] ?? '08:00') ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 font-mono text-xs sm:text-sm text-blue-700 font-bold focus:outline-none cursor-default">
                                                                <input type="hidden" name="hours_worked" id="hours_worked" value="<?= htmlspecialchars($editing_log['calculated_duration'] ?? '08:00') ?>">
                                                            </div>
                                                        </div>
                                                        <div class="space-y-4">
                                                            <div>
                                                                <label class="block text-xs font-bold text-slate-700 mb-1.5">ဆောင်ရွက်မည့်လုပ်ငန်း</label>
                                                                <input type="text" name="intended_task" value="<?= htmlspecialchars($editing_log['task_title'] ?? '') ?>" placeholder="e.g. UI Design & API Integration" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                                            </div>
                                                            <div>
                                                                <label class="block text-xs font-bold text-slate-700 mb-1.5">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</label>
                                                                <textarea name="actual_task" rows="3" placeholder="What you actually accomplished today…" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['tasks_performed'] ?? '') ?></textarea>
                                                            </div>
                                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                                <div>
                                                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">အသုံးပြုသောပစ္စည်းများ</label>
                                                                    <input type="text" name="tools_used" value="<?= htmlspecialchars($editing_log['tools_used'] ?? '') ?>" placeholder="PHP, TailwindCSS, MySQL…" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 font-mono text-xs sm:text-sm text-emerald-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">လေ့လာသိရှိသော အသိပညာ</label>
                                                                    <input type="text" name="knowledge_gained" value="<?= htmlspecialchars($editing_log['learnt_skills'] ?? '') ?>" placeholder="Database optimization, REST APIs…" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- ══════ ABSENT FIELDS ══════ -->
                                                    <div id="absent-fields" class="<?= $edit_att === 'absent' ? '' : 'hidden' ?>">
                                                        <div>
                                                            <label class="block text-xs font-bold text-slate-700 mb-1.5">ခွင့်ယူရသည့်အကြောင်းအရင်း</label>
                                                            <textarea name="reason_for_absence" rows="2" placeholder="Please state your reason for absence…" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['reason_for_absence'] ?? '') ?></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="flex justify-end pt-2">
                                                        <button type="submit" name="<?= $editing_log ? 'update_log' : 'add_log' ?>" class="px-5 py-2.5 <?= $editing_log ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-blue-600 hover:bg-blue-700' ?> text-white font-bold text-xs rounded-xl shadow-sm transition active:scale-95 cursor-pointer"><?= $editing_log ? 'Update Log' : 'Save Daily Log' ?></button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Daily Log History -->
                                            <?php include 'daily_logs_table.php'; ?>

                                        <?php endif; ?>

                                        <?php if (!$weekly_report_submitted): ?>

                                            <!-- Weekly Reflection Form -->
                                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 <?= !$reflection_unlocked ? 'hidden' : '' ?>">
                                                <h2 class="text-xs sm:text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center gap-2">
                                                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6m6 6V9m-6 10a9 9 0 118-5.3M5 21h14" />
                                                    </svg>
                                                    Weekly Reflection
                                                    <?php if (!$reflection_unlocked): ?>
                                                        <span class="ml-auto flex items-center gap-1 text-[11px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded">Locked (<?= $weekly_log_count ?>/<?= $total_weekdays ?>)</span>
                                                    <?php elseif ($reflection_submitted): ?>
                                                        <span class="ml-auto flex items-center gap-1 text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Saved (Pending Student Signature)</span>
                                                    <?php else: ?>
                                                        <span class="ml-auto flex items-center gap-1 text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Unlocked (<?= $weekly_log_count ?>/<?= $total_weekdays ?>)</span>
                                                    <?php endif; ?>
                                                </h2>
                                                <?php if (!$reflection_unlocked): ?>
                                                    <p class="text-slate-400 text-center py-6 text-xs">Please complete all <strong><?= $total_weekdays ?> daily logs</strong> for <strong>Week <?= $selected_week ?></strong> to unlock this form. You currently have <strong><?= $weekly_log_count ?>/<?= $total_weekdays ?></strong>.</p>
                                                <?php endif; ?>
                                                <?php $curr_ref = $weekly_refs[0] ?? null; ?>
                                                <form method="POST" class="space-y-4 <?= !$reflection_unlocked ? 'hidden' : '' ?>">
                                                    <div>
                                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Week Number</label>
                                                        <input type="number" name="week_number" value="<?= $selected_week ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3.5 py-2 text-xs font-bold text-indigo-600 focus:outline-none cursor-default">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">What was done? <span class="text-slate-400 font-normal text-[11px]">/ ဘာလုပ်သလဲ</span></label>
                                                        <textarea name="what_done" rows="3" required placeholder="What did you accomplish this week?" class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($curr_ref['what_done'] ?? '') ?></textarea>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">How was it done? <span class="text-slate-400 font-normal text-[11px]">/ ဘယ်လိုလုပ်ပါသလဲ</span></label>
                                                        <textarea name="how_done" rows="3" required placeholder="Describe the methods, tools, and approach you used." class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($curr_ref['how_done'] ?? '') ?></textarea>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Why was it done? <span class="text-slate-400 font-normal text-[11px]">/ ဘာကြောင့်လုပ်ပါသလဲ</span></label>
                                                        <textarea name="why_done" rows="3" required placeholder="Explain the purpose, goals, and expected outcomes." class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($curr_ref['why_done'] ?? '') ?></textarea>
                                                    </div>
                                                    <div class="flex justify-end pt-2">
                                                        <button type="submit" name="add_reflection" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition active:scale-95 cursor-pointer"><?= $reflection_submitted ? 'Update Reflection' : 'Save Reflection' ?></button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Weekly Reflections History -->
                                            <?php include 'weekly_reflections_table.php'; ?>

                                        <?php else: ?>

                                            <!-- ════ SUBMITTED WEEKLY REPORT — VIEW MODE ════ -->
                                            <?php $submitted_ref = $weekly_refs[0] ?? null; ?>
                                            <div id="weekly-report-view" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden scroll-mt-24">
                                                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                                                    <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                                        <span class="flex items-center gap-2">
                                                            <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                            Week <?= $selected_week ?> Weekly Reflection — Submitted
                                                        </span>
                                                    </h2>
                                                    <div class="flex items-center gap-3 flex-wrap">
                                                        <?php if ($submitted_ref && !empty($submitted_ref['created_at'])): ?>
                                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-500 bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-full">
                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>
                                                                Submitted <?= htmlspecialchars((new DateTime($submitted_ref['created_at']))->format('d M Y, h:i A')) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="p-6">
                                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                                            <p class="text-[11px] font-bold text-teal-700 uppercase tracking-wider mb-2">What was done? <span class="text-slate-400 font-normal">/ ဘာလုပ်သလဲ</span></p>
                                                            <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($submitted_ref['what_done'] ?? '—')) ?></p>
                                                        </div>
                                                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                                            <p class="text-[11px] font-bold text-teal-700 uppercase tracking-wider mb-2">How was it done? <span class="text-slate-400 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></p>
                                                            <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($submitted_ref['how_done'] ?? '—')) ?></p>
                                                        </div>
                                                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                                            <p class="text-[11px] font-bold text-teal-700 uppercase tracking-wider mb-2">Why was it done? <span class="text-slate-400 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></p>
                                                            <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($submitted_ref['why_done'] ?? '—')) ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                        <?php endif; ?>

                                        <!-- Student Signature Section -->
                                        <div id="student-signature" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 scroll-mt-24 <?= !$reflection_submitted ? 'opacity-50 pointer-events-none select-none' : '' ?>">
                                            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-5">
                                                <h2 class="text-xs sm:text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                                    <span class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                        </svg>
                                                    </span>
                                                    Student Signature
                                                </h2>
                                                <?php if (!$reflection_submitted): ?>
                                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 bg-slate-100 px-3 py-1 rounded-full">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                        </svg>
                                                        Locked
                                                    </span>
                                                <?php elseif ($student_signed): ?>
                                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-1 rounded-full">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                        Signed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                                        Pending
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!$reflection_submitted): ?>
                                                <p class="text-xs text-slate-400 text-center py-4">Please submit your weekly reflection to sign this report.</p>

                                            <?php elseif ($student_signed): ?>
                                                <!-- Clean Document Signature Block -->
                                                <div class="bg-slate-50/60 border border-slate-200/80 rounded-xl p-4 flex items-center justify-between flex-wrap gap-4">
                                                    <div class="inline-block min-w-[200px]">
                                                        <div class="min-h-[44px] flex items-end pb-1">
                                                            <?php if ($rejection && $rejection['student_signature_type'] === 'typed'): ?>
                                                                <p class="text-3xl text-slate-900 leading-none select-text cursor-default" style="font-family:'Great Vibes',cursive;"><?= htmlspecialchars($rejection['student_signature_value']) ?></p>
                                                            <?php elseif ($rejection && $rejection['student_signature_type'] === 'uploaded'): ?>
                                                                <img src="../uploads/signatures/<?= htmlspecialchars($rejection['student_signature_value']) ?>" alt="Signature" class="max-h-10 object-contain select-none">
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="pt-1 text-xs font-bold text-slate-700">
                                                            <?= htmlspecialchars($student_name) ?>
                                                        </p>
                                                    </div>
                                                    <div class="text-right text-xs text-slate-400">
                                                        <p class="font-medium">Week <?= $selected_week ?></p>
                                                        <p class="text-[11px]"><?= date('d M Y') ?></p>
                                                    </div>
                                                </div>

                                            <?php else: ?>
                                                <!-- Pre-Sign Review Reminder Notice -->
                                                <div class="bg-amber-50/80 border border-amber-200/90 rounded-xl p-3.5 mb-5 flex items-start gap-3 text-xs text-amber-900 shadow-2xs">
                                                    <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <div class="leading-relaxed">
                                                        <span class="font-bold text-amber-950 block mb-0.5">⚠️ လက်မှတ်မထိုးမီ သေချာစွာ ပြန်လည်စစ်ဆေးပါရန် / Please review before signing:</span>
                                                        <span>Student Signature ထိုးပြီး "Save Signature" နှိပ်ပြီးပါက ဤအပတ်အတွက် Daily Logs နှင့် Weekly Reflection များကို ပြင်ဆင်၍ မရတော့ဘဲ Read-Only အဖြစ် အတည်ပြုသွားမည် ဖြစ်ပါသည်။</span>
                                                    </div>
                                                </div>

                                                <!-- Clean Signature Input Form -->
                                                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                                                    <!-- Toggle Mode -->
                                                    <div class="flex items-center gap-2">
                                                        <input type="hidden" name="student_signature_type" value="typed" id="left-sig-type-input">
                                                        <button type="button" onclick="switchLeftSigType('typed')" id="left-btn-typed"
                                                            class="px-3.5 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600 shadow-2xs">
                                                            ✍️ Type Name
                                                        </button>
                                                        <button type="button" onclick="switchLeftSigType('uploaded')" id="left-btn-uploaded"
                                                            class="px-3.5 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50">
                                                            📁 Upload Image
                                                        </button>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                                                        <!-- Left: Input Mode -->
                                                        <div>
                                                            <!-- Typed Mode -->
                                                            <div id="left-sig-typed-fields" class="space-y-1.5">
                                                                <label class="block text-xs font-bold text-slate-700">Enter Name to Sign</label>
                                                                <input type="text" name="student_typed_name" id="left_typed_name"
                                                                    value=""
                                                                    placeholder="e.g. <?= htmlspecialchars($student_name) ?>"
                                                                    oninput="previewLeftSig()"
                                                                    class="w-full bg-slate-50/70 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                                                            </div>

                                                            <!-- Upload Mode -->
                                                            <div id="left-sig-upload-fields" class="hidden space-y-1.5">
                                                                <label class="block text-xs font-bold text-slate-700">Upload Handwritten Signature</label>
                                                                <div class="bg-slate-50/70 border border-dashed border-slate-300 rounded-xl p-3 flex items-center justify-between gap-3">
                                                                    <label class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-700 hover:bg-slate-50 cursor-pointer transition shrink-0 shadow-2xs">
                                                                        Choose File
                                                                        <input type="file" name="student_signature_file" accept=".jpg,.jpeg,.png" class="hidden" onchange="document.getElementById('upload-file-name').textContent = this.files[0] ? this.files[0].name : 'No file chosen'">
                                                                    </label>
                                                                    <span id="upload-file-name" class="text-xs text-slate-500 truncate">JPG/PNG (Max 2MB)</span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Right: Document Signature Preview & Action -->
                                                        <div class="flex flex-col justify-between sm:border-l sm:border-slate-100 sm:pl-6">
                                                            <div>
                                                                <label class="block text-xs font-bold text-slate-500 mb-1">Signature</label>
                                                                <div class="inline-block min-w-[200px] w-full max-w-[260px]">
                                                                    <div class="min-h-[44px] flex items-end pb-1">
                                                                        <p id="left_sig_preview" class="text-3xl text-slate-400 leading-none select-none cursor-default" style="font-family:'Great Vibes',cursive; min-height: 32px;">
                                                                            —
                                                                        </p>
                                                                    </div>
                                                                    <p class="pt-1 text-xs font-bold text-slate-700"><?= htmlspecialchars($student_name) ?></p>
                                                                </div>
                                                            </div>

                                                            <div class="mt-4 pt-3">
                                                                <button type="submit" name="save_student_signature" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer">
                                                                    Save Signature
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                </div>

                            <?php endif; ?>

                            <?php if ($tab === 'weekly-report'): ?>

                                <!-- ════ TWO-COLUMN: Weekly Summary + Instructor Review ════ -->
                                <div class="w-full grid grid-cols-1 md:grid-cols-12 gap-6 mb-6 items-stretch">

                                    <!-- ── LEFT: Weekly Summary ── -->
                                    <div class="md:col-span-6">
                                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 h-full flex flex-col justify-between">
                                            <div>
                                                <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                                    </svg>
                                                    Weekly Summary — Week <?= $selected_week ?>
                                                </h3>
                                                <div class="grid grid-cols-2 gap-3">
                                                    <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                                                        <span class="text-xs text-slate-500 font-medium">Daily Logs</span>
                                                        <span class="text-xs font-bold text-slate-700"><?= $weekly_log_count ?> days</span>
                                                    </div>
                                                    <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                                                        <span class="text-xs text-slate-500 font-medium">Reflection</span>
                                                        <span class="text-xs font-bold <?= $reflection_submitted ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $reflection_submitted ? 'Submitted' : 'Pending' ?></span>
                                                    </div>
                                                    <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                                                        <span class="text-xs text-slate-500 font-medium">Signature</span>
                                                        <span class="text-xs font-bold <?= $student_signed ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $student_signed ? 'Signed' : 'Pending' ?></span>
                                                    </div>
                                                    <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                                                        <span class="text-xs text-slate-500 font-medium">Instructor Email</span>
                                                        <span class="text-xs font-bold <?= $magic_link_unlocked ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $magic_link_unlocked ? 'Dispatched' : 'Locked' ?></span>
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
                                                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Progress</span>
                                                    <span class="text-xs font-bold text-indigo-600"><?= $week_progress ?>%</span>
                                                </div>
                                                <div class="w-full bg-slate-100 rounded-full h-2">
                                                    <div class="bg-indigo-600 rounded-full h-2 transition-all duration-500" style="width: <?= $week_progress ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ── RIGHT: Instructor Review Status & Email Dispatch ── -->
                                    <div class="md:col-span-6">
                                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 h-full flex flex-col">
                                            <div class="flex items-center justify-between mb-4">
                                                <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-2">
                                                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                    </svg>
                                                    Instructor Review &amp; Evaluation
                                                </h3>
                                                <?php if (!$magic_link_unlocked): ?>
                                                    <span class="text-xs font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded">Locked</span>
                                                <?php elseif ($is_week_approved): ?>
                                                    <span class="text-xs font-bold text-emerald-700 bg-emerald-100 px-2.5 py-0.5 rounded-full">Approved</span>
                                                <?php elseif ($is_rejected): ?>
                                                    <span class="text-xs font-bold text-red-700 bg-red-100 px-2.5 py-0.5 rounded-full">Action Required</span>
                                                <?php else: ?>
                                                    <span class="text-xs font-bold text-indigo-700 bg-indigo-100 px-2.5 py-0.5 rounded-full">Email Dispatched</span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($is_week_approved): ?>
                                                <!-- ── Approved & Review Locked State ── -->
                                                <div class="flex-1 flex flex-col justify-center items-center text-center p-6 bg-emerald-50/60 border border-emerald-200 rounded-2xl">
                                                    <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mb-3 text-xl font-bold">
                                                        ✓
                                                    </div>
                                                    <h4 class="text-xs font-black text-emerald-900 uppercase tracking-wider">Instructor Sign-off Completed</h4>
                                                    <p class="text-xs text-emerald-700 mt-1.5 leading-relaxed max-w-sm">
                                                        Company Instructor has signed and approved this report. The review token has expired and submissions are locked.
                                                    </p>
                                                    <div class="mt-4 inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-emerald-600 text-white rounded-full text-xs font-bold shadow-xs">
                                                        <span>🔒</span> Forwarded to University Supervisor
                                                    </div>
                                                </div>
                                            <?php elseif ($magic_link_unlocked): ?>

                                                <?php if ($is_rejected): ?>
                                                    <div class="bg-red-50 border border-red-200 rounded-xl p-3.5 mb-4">
                                                        <p class="text-xs font-bold text-red-700 mb-1 flex items-center gap-1.5">
                                                            <span>⚠️</span> Report Rejected by Instructor
                                                        </p>
                                                        <p class="text-xs text-red-600 leading-relaxed">
                                                            <?= htmlspecialchars($rejection_reason ?: 'Please revise your daily logs and reflection according to your instructor’s feedback, then sign and resend.') ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Instructor Email Status Card -->
                                                <div class="flex-1 flex flex-col justify-between space-y-4">
                                                    <div class="bg-slate-50 border border-slate-200/80 rounded-xl p-4">
                                                        <div class="flex items-center gap-3 mb-3">
                                                            <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center shrink-0">
                                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                                </svg>
                                                            </div>
                                                            <div class="min-w-0 flex-1">
                                                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Assigned Instructor</p>
                                                                <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($instructor_name ?: 'Company Instructor') ?></p>
                                                            </div>
                                                        </div>

                                                        <div class="space-y-2 border-t border-slate-200/60 pt-3">
                                                            <div class="flex items-center justify-between text-xs">
                                                                <span class="text-slate-500 font-medium">Recipient Email</span>
                                                                <?php if (!empty($instructor_email)): ?>
                                                                    <span class="font-mono font-bold text-indigo-600 truncate max-w-[200px]" title="<?= htmlspecialchars($instructor_email) ?>">
                                                                        <?= htmlspecialchars($instructor_email) ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded text-[11px]">Not Configured</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex items-center justify-between text-xs">
                                                                <span class="text-slate-500 font-medium">Delivery Status</span>
                                                                <?php if (!empty($instructor_email)): ?>
                                                                    <span class="inline-flex items-center gap-1 font-bold text-emerald-600">
                                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                                                        Dispatched to Inbox
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="font-bold text-amber-600">Awaiting Email Address</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex items-center justify-between text-xs">
                                                                <span class="text-slate-500 font-medium">Link Validity</span>
                                                                <span class="font-semibold text-slate-600">7 Days (Single Use)</span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <?php if (empty($instructor_email)): ?>
                                                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3.5 text-xs text-amber-800">
                                                            <p class="font-bold mb-1 flex items-center gap-1.5">
                                                                <span>⚠️</span> Instructor Email Missing
                                                            </p>
                                                            <p class="text-amber-700 leading-relaxed mb-3">
                                                                သင်၏ Company Instructor Email လိပ်စာကို ထည့်သွင်းထားခြင်း မရှိသေးပါ။ Email သို့ တိုက်ရိုက် ပို့ဆောင်နိုင်ရန် Profile တွင် အရင် ဖြည့်သွင်းပေးပါ။
                                                            </p>
                                                            <a href="profile.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-lg text-xs transition shadow-xs">
                                                                ✏️ Update Instructor Email in Profile
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="bg-indigo-50/70 border border-indigo-100 rounded-xl p-3 text-[11px] text-indigo-900 leading-relaxed">
                                                            <p class="font-semibold text-indigo-800 mb-1 flex items-center gap-1">
                                                                <span>🔒</span> လုံခြုံရေး အသိပေးချက် / Security Notice:
                                                            </p>
                                                            ကျောင်းသား Screen ပေါ်တွင် Token Link ကို လုံခြုံရေးအရ လုံးဝ ပြသမည်မဟုတ်ဘဲ Instructor ထံသို့ HTML Email ဖြင့် တိုက်ရိုက် ပို့ဆောင်ထားပါသည်။ Instructor မှ Email ရှိ Link ကို နှိပ်၍ တိုက်ရိုက် စစ်ဆေးလက်မှတ်ထိုးပေးနိုင်ပါသည်။
                                                        </div>

                                                        <form method="POST" class="pt-1">
                                                            <button type="submit" name="resend_instructor_email" class="w-full px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 active:scale-[0.99] text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center justify-center gap-2 cursor-pointer">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                                </svg>
                                                                <span>✉️ Resend Review Email to Instructor</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>

                                            <?php else: ?>
                                                <!-- Locked State -->
                                                <div class="flex-1 flex flex-col justify-center">
                                                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-3">
                                                        <div class="flex items-start gap-2">
                                                            <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                            </svg>
                                                            <div>
                                                                <p class="text-caption font-bold text-amber-700 mb-1">Requirements not met for Week <?= $selected_week ?></p>
                                                                <ul class="text-label text-amber-600 space-y-0.5">
                                                                    <li>Daily Logs: <strong><?= $weekly_log_count ?>/<?= $total_weekdays ?></strong> days</li>
                                                                    <li>Weekly Reflection: <strong><?= $reflection_submitted ? 'Submitted' : 'Pending' ?></strong></li>
                                                                    <li>Student Signature: <strong><?= $student_signed ? 'Signed' : 'Pending' ?></strong></li>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <p class="text-caption text-slate-400 text-center">Complete all 3 previous steps to dispatch review email to your instructor.</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                </div>

                                <!-- ════ FEEDBACK & GRADES ════ -->
                                <div id="feedback-section" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6 scroll-mt-24">
                                    <div class="px-4 py-2.5 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                                        <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                            </svg>
                                            Feedback &amp; Grades — Week <?= $selected_week ?>
                                        </h3>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[11px] font-bold rounded-full border <?= $report_status_color ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $report_status_dot ?>"></span>
                                            <?= $report_status_label ?>
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3.5">

                                        <!-- ── Instructor Feedback ── -->
                                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/40 p-3 flex flex-col justify-between">
                                            <div>
                                                <div class="flex items-center justify-between gap-2 pb-2 border-b border-slate-200/60 mb-2.5">
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <span class="text-base">🏢</span>
                                                        <div class="min-w-0">
                                                            <p class="text-xs font-bold text-slate-800 leading-tight">Instructor Feedback</p>
                                                            <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars($instructor_reviewer) ?></p>
                                                        </div>
                                                    </div>
                                                    <?php if ($instructor_evaluated && !empty($instructor_eval['grade'])): ?>
                                                        <?php $ig = $instructor_eval['grade'];
                                                        $igd = $instructor_grade_map[$ig] ?? [ucwords(str_replace('_', ' ', $ig)), 'bg-slate-100 text-slate-600']; ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold <?= $igd[1] ?> shrink-0"><?= htmlspecialchars($igd[0]) ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($instructor_evaluated): ?>
                                                    <div class="space-y-2">
                                                        <?php $inst_comment = trim($instructor_eval['comment'] ?? ''); ?>
                                                        <?php if ($inst_comment !== ''): ?>
                                                            <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($inst_comment)) ?></p>
                                                        <?php else: ?>
                                                            <p class="text-xs italic text-slate-400">No written comments provided.</p>
                                                        <?php endif; ?>

                                                        <?php if (($instructor_eval['report_status'] ?? '') === 'rejected' && !empty($instructor_eval['instructor_comments'])): ?>
                                                            <div class="bg-red-50 border border-red-100 rounded-lg p-2">
                                                                <p class="text-[10px] font-bold text-red-500 uppercase tracking-wider mb-0.5">Revision Requested</p>
                                                                <p class="text-xs text-red-600 leading-relaxed"><?= nl2br(htmlspecialchars($instructor_eval['instructor_comments'])) ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- Instructor Verified Signature -->
                                                    <?php if (!empty($instructor_eval['signature_type']) && !empty($instructor_eval['signature_value'])): ?>
                                                        <div class="mt-3 pt-2 border-t border-dashed border-slate-200 flex items-center justify-between gap-2">
                                                            <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                                                <svg class="w-3 h-3 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                                                </svg>
                                                                Instructor Signed
                                                            </span>
                                                            <div class="text-right">
                                                                <?php if ($instructor_eval['signature_type'] === 'typed'): ?>
                                                                    <span style="font-family:'Great Vibes',cursive; font-size:22px; color:#334155;" class="select-none tracking-wide"><?= htmlspecialchars($instructor_eval['signature_value']) ?></span>
                                                                <?php elseif ($instructor_eval['signature_type'] === 'uploaded'): ?>
                                                                    <?php $inst_sig_file = (strpos($instructor_eval['signature_value'], 'uploads/') !== false) ? $instructor_eval['signature_value'] : '../uploads/signatures/' . $instructor_eval['signature_value']; ?>
                                                                    <img src="<?= htmlspecialchars($inst_sig_file) ?>" alt="Instructor Signature" class="max-h-8 max-w-[120px] object-contain inline-block">
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                <?php else: ?>
                                                    <div class="py-4 text-center">
                                                        <p class="text-xs text-slate-400 italic">Awaiting instructor evaluation…</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($instructor_evaluated): ?>
                                                <p class="text-[10px] text-slate-400 mt-2.5 pt-2 border-t border-slate-200/60 flex items-center gap-1">
                                                    <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Evaluated <?= htmlspecialchars((new DateTime($instructor_eval['evaluated_at']))->format('d M Y, h:i A')) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- ── Supervisor Feedback ── -->
                                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/40 p-3 flex flex-col justify-between">
                                            <div>
                                                <div class="flex items-center justify-between gap-2 pb-2 border-b border-slate-200/60 mb-2.5">
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <span class="text-base">🎓</span>
                                                        <div class="min-w-0">
                                                            <p class="text-xs font-bold text-slate-800 leading-tight">Supervisor Feedback</p>
                                                            <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars($supervisor_reviewer) ?></p>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($supervisor_eval)): ?>
                                                        <?php $sg = $supervisor_eval['weekly_grade'] ?? '';
                                                        $sgd = $supervisor_grade_map[$sg] ?? [$sg ?: '—', 'bg-slate-100 text-slate-600']; ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold <?= $sgd[1] ?> shrink-0"><?= htmlspecialchars($sgd[0]) ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($supervisor_eval)): ?>
                                                    <div>
                                                        <?php $sup_comments = trim($supervisor_eval['supervisor_comments'] ?? ''); ?>
                                                        <?php if ($sup_comments !== ''): ?>
                                                            <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($sup_comments)) ?></p>
                                                        <?php else: ?>
                                                            <p class="text-xs italic text-slate-400">No written comments provided.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="py-4 text-center">
                                                        <p class="text-xs text-slate-400 italic">Awaiting supervisor evaluation…</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($supervisor_eval)): ?>
                                                <p class="text-[10px] text-slate-400 mt-2.5 pt-2 border-t border-slate-200/60 flex items-center gap-1">
                                                    <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Evaluated <?= htmlspecialchars((new DateTime($supervisor_eval['evaluated_at']))->format('d M Y, h:i A')) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                    </div>
                                </div>

                            <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    </div>
                    <!-- AJAX-CONTENT:END -->

                </div>
            </main>
        </div>
    </div>

    <!-- ══════ EXPORT MODAL ══════ -->
    <div id="export-modal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('export-modal').classList.add('hidden')"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 z-10">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Export Report
                </h3>
                <button onclick="document.getElementById('export-modal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
            </div>
            <p class="text-slate-500 mb-4">Choose an export option for your internship report data.</p>
            <div class="space-y-3">
                <button onclick="document.getElementById('export-modal').classList.add('hidden'); window.open('print_report.php?week=<?= $selected_week ?>', '_blank');" class="w-full flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl hover:bg-indigo-50 hover:border-indigo-200 transition group cursor-pointer">
                    <span class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                    </span>
                    <div class="text-left">
                        <p class="font-bold text-slate-700 group-hover:text-indigo-700">Official Weekly Report (Save as PDF)</p>
                        <p class="text-label text-slate-400">Formal document with letterhead, reflections & signatures</p>
                    </div>
                </button>
                <button onclick="document.getElementById('export-modal').classList.add('hidden'); window.open('print_report.php?week=all', '_blank');" class="w-full flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl hover:bg-blue-50 hover:border-blue-200 transition group cursor-pointer">
                    <span class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </span>
                    <div class="text-left">
                        <p class="font-bold text-slate-700 group-hover:text-blue-700">Full Internship Record (All Weeks)</p>
                        <p class="text-label text-slate-400">Complete multi-week training log & summary report</p>
                    </div>
                </button>
                <button onclick="exportAsCSV()" class="w-full flex items-center gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl hover:bg-emerald-50 hover:border-emerald-200 transition group cursor-pointer">
                    <span class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </span>
                    <div class="text-left">
                        <p class="font-bold text-slate-700 group-hover:text-emerald-700">Export as CSV</p>
                        <p class="text-label text-slate-400">Spreadsheet-compatible daily logs data</p>
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
                content += '<td><?= htmlspecialchars($log["calculated_duration"] ?? "") ?></td>';
                content += '<td><?= htmlspecialchars($log["tools_used"] ?? "-") ?></td>';
                content += '<td><?= htmlspecialchars($log["learnt_skills"] ?? "-") ?></td></tr>';
            <?php endforeach; ?>
            content += '</tbody></table>';
            content += '<h2>Weekly Reflections</h2>';
            <?php
            $ref_stmt = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? ORDER BY week_number ASC");
            $ref_stmt->bind_param("i", $user_id);
            $ref_stmt->execute();
            $ref_res = $ref_stmt->get_result();
            $all_refs = $ref_res ? $ref_res->fetch_all(MYSQLI_ASSOC) : [];
            foreach ($all_refs as $rf):
            ?>
                content += '<h3>Week <?= (int)$rf["week_number"] ?></h3>';
                content += '<p><strong>What was done? / ဘာလုပ်သလဲ:</strong> <?= nl2br(htmlspecialchars($rf["what_done"])) ?></p>';
                content += '<p><strong>How was it done? / ဘယ်လိုလုပ်ပါသလဲ:</strong> <?= nl2br(htmlspecialchars($rf["how_done"])) ?></p>';
                content += '<p><strong>Why was it done? / ဘာကြောင့်လုပ်ပါသလဲ:</strong> <?= nl2br(htmlspecialchars($rf["why_done"])) ?></p>';
            <?php endforeach; ?>
            content += '<p style="margin-top:40px;font-size:11px;color:#94a3b8;">Generated on <?= date('d M Y, h:i A') ?> via InternReport</p>';
            content += '</body></html>';
            printWin.document.write(content);
            printWin.document.close();
            printWin.print();
        }

        function exportAsCSV() {
            var modal = document.getElementById('export-modal');
            if (modal) modal.classList.add('hidden');

            var rows = document.querySelectorAll('table tbody tr');
            if (!rows || !rows.length) {
                alert('No daily log entries available to export.');
                return;
            }

            var csvRows = [];
            var headers = ['Date / Day', 'Attendance Status', 'Task Title / Intended', 'Tasks Performed', 'Tools Used', 'Learnt Skills', 'Duration'];
            csvRows.push(headers.map(function(h) {
                return '"' + h.replace(/"/g, '""') + '"';
            }).join(','));

            rows.forEach(function(row) {
                var cols = row.querySelectorAll('td');
                if (cols.length >= 7) {
                    var rowData = [];
                    cols.forEach(function(col) {
                        var text = col.innerText.trim().replace(/\s+/g, ' ');
                        rowData.push('"' + text.replace(/"/g, '""') + '"');
                    });
                    csvRows.push(rowData.join(','));
                }
            });

            var blob = new Blob([csvRows.join('\n')], {
                type: 'text/csv;charset=utf-8;'
            });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'daily_logs_export.csv';
            link.click();
        }
    </script>

    <!-- ═══════════ BEAUTIFUL CENTER ALERT BOARD MODAL ═══════════ -->
    <div id="dateAlertModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-300 opacity-0 pointer-events-none" onclick="if(event.target === this) closeDateAlertModal()">
        <div id="dateAlertCard" class="bg-white rounded-3xl shadow-2xl border border-slate-100/90 max-w-sm sm:max-w-md w-full p-6 sm:p-7 text-center transform transition-all duration-300 scale-95 opacity-0 relative overflow-hidden">
            <!-- Decorative Accent Top Gradient -->
            <div id="modalTopBorder" class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-amber-400 via-rose-500 to-indigo-500"></div>

            <!-- Close button (top right) -->
            <button type="button" onclick="closeDateAlertModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <!-- Icon -->
            <div id="modalIconContainer" class="w-16 h-16 mx-auto mb-3.5 rounded-2xl bg-amber-50 text-amber-500 border border-amber-100 flex items-center justify-center shadow-xs">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <!-- Badge -->
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-label font-bold uppercase tracking-wider bg-slate-100 text-slate-600 mb-2">
                <span id="modalBadgeDot" class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                <span id="modalBadge">သတိပေးချက်</span>
            </div>

            <!-- Title -->
            <h3 id="modalTitle" class="text-base sm:text-lg font-black text-slate-800 tracking-tight mb-2">
                ရွေးချယ်၍ မရပါ
            </h3>

            <!-- English/Technical Message -->
            <p id="modalMessage" class="text-xs sm:text-sm text-slate-500 font-medium leading-relaxed mb-3">
                ဤရက်စွဲအား ရွေးချယ်ခွင့်မရှိပါ။
            </p>

            <!-- Myanmar Highlighted Note -->
            <div id="modalMyanmarNote" class="bg-amber-50/80 border border-amber-200/80 rounded-2xl p-3.5 text-xs text-amber-900 font-semibold mb-5 leading-relaxed text-left flex items-start gap-2.5 shadow-2xs">
                <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>ရက်ကျော်ဖြည့်သွင်းခြင်းမပြုရပါ။</span>
            </div>

            <!-- Action Button -->
            <div class="flex items-center justify-center">
                <button type="button" onclick="closeDateAlertModal()" class="w-full sm:w-auto min-w-[150px] px-6 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-lg transition-all transform active:scale-95 cursor-pointer flex items-center justify-center gap-1.5">
                    <span>နားလည်ပါပြီ</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════ BEAUTIFUL SIGNATURE SUCCESS MODAL ═══════════ -->
    <div id="signSuccessModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md transition-opacity duration-300 opacity-0 pointer-events-none" onclick="if(event.target === this) closeSignSuccessModal()">
        <div id="signSuccessCard" class="bg-white rounded-3xl shadow-2xl border border-emerald-100/90 max-w-sm sm:max-w-md w-full p-6 sm:p-7 text-center transform transition-all duration-300 scale-95 opacity-0 relative overflow-hidden">
            <!-- Decorative Accent Top Gradient -->
            <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-emerald-400 via-teal-500 to-indigo-600"></div>

            <!-- Soft background glow decoration -->
            <div class="absolute -top-16 -right-16 w-36 h-36 bg-emerald-200/30 rounded-full blur-2xl pointer-events-none"></div>
            <div class="absolute -bottom-16 -left-16 w-36 h-36 bg-teal-200/30 rounded-full blur-2xl pointer-events-none"></div>

            <!-- Close button (top right) -->
            <button type="button" onclick="closeSignSuccessModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-2 rounded-full transition cursor-pointer" title="Close">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <!-- Animated Success Icon Container -->
            <div class="w-16 h-16 mx-auto mb-3.5 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-400 text-white flex items-center justify-center shadow-lg shadow-emerald-500/25 relative ring-6 ring-emerald-50">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
                <!-- Small Email Badge Icon -->
                <span class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] shadow-sm border-2 border-white">
                    ✉️
                </span>
            </div>

            <!-- Badge -->
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-label font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200/80 mb-2 shadow-2xs">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span id="signModalBadge">Email Dispatched</span>
            </div>

            <!-- Title -->
            <h3 id="signModalTitle" class="text-base sm:text-lg font-black text-slate-800 tracking-tight mb-1">
                Signed Successfully!
            </h3>

            <!-- English/Technical Message -->
            <p id="signModalMessage" class="text-xs sm:text-sm text-slate-600 font-semibold leading-relaxed mb-3">
                Review request email sent to your instructor.
            </p>

            <!-- Recipient Information Card -->
            <?php if (!empty($profile_row['instructor_email'])): ?>
                <div class="bg-slate-50 border border-slate-200/80 rounded-2xl p-3 mb-3 text-left flex items-center gap-3 shadow-2xs">
                    <div class="w-9 h-9 rounded-xl bg-indigo-50 border border-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[10px] uppercase font-bold tracking-wider text-slate-400 flex items-center gap-1">
                            <span>Recipient</span>
                            <span class="text-emerald-600 font-extrabold">• Sent</span>
                        </div>
                        <div id="signModalInstructorName" class="text-xs font-bold text-slate-800 truncate">
                            <?= htmlspecialchars($profile_row['instructor_name'] ?? 'Instructor') ?>
                        </div>
                        <div id="signModalInstructorEmail" class="text-[11px] text-slate-500 font-mono truncate">
                            <?= htmlspecialchars($profile_row['instructor_email'] ?? '') ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Myanmar Highlighted Note -->
            <div id="signModalMyanmarNote" class="bg-emerald-50/80 border border-emerald-200/80 rounded-2xl p-3 text-xs text-emerald-900 font-medium mb-5 leading-relaxed text-left flex items-start gap-2.5 shadow-2xs">
                <svg class="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="note-text">Instructor ထံသို့ စစ်ဆေးလက်မှတ်ထိုးရန် Magic Review Link ပါဝင်သော Email ကို အောင်မြင်စွာ ပို့ဆောင်ပြီးပါပြီ။</span>
            </div>

            <!-- Action Button -->
            <div class="flex items-center justify-center">
                <button type="button" onclick="closeSignSuccessModal()" class="w-full sm:w-auto min-w-[170px] py-2.5 px-6 bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-600 hover:from-emerald-700 hover:to-teal-700 text-white text-xs font-black rounded-xl shadow-md hover:shadow-lg shadow-emerald-600/20 transition-all transform active:scale-95 cursor-pointer flex items-center justify-center gap-2">
                    <span>နားလည်ပါပြီ (Got it)</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</body>

</html>
<?php if ($is_ajax): ?>
    <?php
    $full = ob_get_clean();
    $start = strpos($full, '<!-- AJAX-CONTENT:START -->');
    $end   = strpos($full, '<!-- AJAX-CONTENT:END -->');
    if ($start !== false && $end !== false) {
        echo substr($full, $start + strlen('<!-- AJAX-CONTENT:START -->'), $end - $start - strlen('<!-- AJAX-CONTENT:START -->'));
    } else {
        echo '';
    }
    ?>
<?php endif; ?>