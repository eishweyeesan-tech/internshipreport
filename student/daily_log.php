<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id       = (int) $_SESSION['user_id'];
$username      = $_SESSION['username'];
$internship_id = $user_id;

$db = $mysqli ?? $conn;

// FETCH INTERNSHIP DATE RANGE
$profile_stmt = $db->prepare("SELECT sp.full_name, sp.internship_start_date, sp.internship_end_date,
    sp.company_name, sp.job_role, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile_row = $res ? $res->fetch_assoc() : null;
$intern_start = $profile_row['internship_start_date'] ?? null;
$intern_end   = $profile_row['internship_end_date'] ?? null;
$student_name = ($profile_row['full_name'] ?? '') ?: $username;
$profile_pic  = $profile_row['profile_pic'] ?? null;



// FETCH EXISTING LOG DATES
$log_dates_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ?");
$log_dates_stmt->bind_param("i", $internship_id);
$log_dates_stmt->execute();
$res = $log_dates_stmt->get_result();
$existing_logs = [];
if ($res) {
    while ($row = $res->fetch_row()) {
        $existing_logs[] = $row[0];
    }
}

// BUILD WEEK RANGES
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

$progress_weeks_completed = 0;
$progress_total_weeks = count($weeks);
if (!empty($weeks)) {
    $wc_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    foreach ($weeks as $wn => $wr) {
        $wc_stmt->bind_param("iss", $internship_id, $wr['start'], $wr['end']);
        $wc_stmt->execute();
        $res = $wc_stmt->get_result();
        $wc_row = $res ? $res->fetch_row() : null;
        if ((int) ($wc_row[0] ?? 0) > 0) {
            $progress_weeks_completed++;
        }
    }
}

// SELECTED WEEK & LOCK STATUS
$selected_week = (int) ($_GET['week'] ?? $_POST['selected_week'] ?? 0);
if ($selected_week < 1 || $selected_week > count($weeks)) {
    $selected_week = count($weeks) > 0 ? count($weeks) : 1;
}

$log_locked = false;
$lock_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$lock_stmt->bind_param("ii", $internship_id, $selected_week);
$lock_stmt->execute();
$res = $lock_stmt->get_result();
$lock_row = $res ? $res->fetch_assoc() : null;
if ($lock_row && !empty($lock_row['student_signature_type']) && !empty($lock_row['student_signature_value']) && $lock_row['report_status'] !== 'rejected') {
    $log_locked = true;
}
$weekly_report_submitted = false;
$ref_chk_stmt = $db->prepare("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$ref_chk_stmt->bind_param("ii", $internship_id, $selected_week);
$ref_chk_stmt->execute();
$ref_chk_res = $ref_chk_stmt->get_result();
$ref_chk_row = $ref_chk_res ? $ref_chk_res->fetch_row() : null;
$reflection_submitted = ((int) ($ref_chk_row[0] ?? 0) > 0);

if (($reflection_submitted || $log_locked) && !($lock_row && $lock_row['report_status'] === 'rejected')) {
    $weekly_report_submitted = true;
}

$error   = '';
$success = '';

// ADD LOG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $add_week = (int) ($_POST['selected_week'] ?? 0);
    if ($add_week > 0) {
        $add_lock_stmt = $db->prepare("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
        $add_lock_stmt->bind_param("ii", $internship_id, $add_week);
        $add_lock_stmt->execute();
        $res = $add_lock_stmt->get_result();
        $add_lock_row = $res ? $res->fetch_assoc() : null;
        if ($add_lock_row && !empty($add_lock_row['student_signature_type']) && !empty($add_lock_row['student_signature_value']) && $add_lock_row['report_status'] !== 'rejected') {
            $error = 'This week has been signed and cannot be edited.';
        }
    }
    $log_date = trim($_POST['log_date'] ?? '');

    if ($selected_week < 1 || $selected_week > count($weeks)) {
        $error = 'Invalid week selection.';
    } elseif (empty($log_date)) {
        $error = 'Please select a date.';
    } else {
        $week_range = $weeks[$selected_week] ?? null;
        if (!$week_range || $log_date < $week_range['start'] || $log_date > $week_range['end']) {
            $error = "Date must be between {$week_range['start']} and {$week_range['end']} (Week {$selected_week}).";
        } else {
            $dup_stmt = $db->prepare("SELECT id FROM daily_logs WHERE internship_id = ? AND log_date = ? LIMIT 1");
            $dup_stmt->bind_param("is", $internship_id, $log_date);
            $dup_stmt->execute();
            $res = $dup_stmt->get_result();
            if ($res && $res->fetch_row()) {
                $error = "duplicate_log";
            } else {
                $attendance_status  = trim($_POST['attendance_status'] ?? 'present');
                $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');



                $intended_task      = trim($_POST['intended_task'] ?? '');
                $task_detail        = trim($_POST['task_detail'] ?? '');
                $actual_task        = trim($_POST['actual_task'] ?? '');
                $tools_used         = trim($_POST['tools_used'] ?? '');
                $knowledge_gained   = trim($_POST['knowledge_gained'] ?? '');
                $start_time         = trim($_POST['start_time'] ?? '09:00');
                $end_time           = trim($_POST['end_time'] ?? '17:00');
                $hours_worked       = trim($_POST['hours_worked'] ?? '08:00');

                if ($attendance_status === 'absent') {
                    $intended_task    = $reason_for_absence ?: 'Absent';
                    $task_detail      = 'N/A - Absent';
                    $actual_task      = 'N/A - Absent';
                    $tools_used       = 'N/A - Absent';
                    $knowledge_gained = 'N/A - Absent';
                    $hours_worked     = '00:00';
                }

                $ins_stmt = $db->prepare("INSERT INTO daily_logs
                    (internship_id, log_date, attendance_status, reason_for_absence,
                     task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_stmt->bind_param("isssssssss",
                    $internship_id, $log_date, $attendance_status, $reason_for_absence,
                    $intended_task, $task_detail, $actual_task, $tools_used, $knowledge_gained, $hours_worked
                );
                $ins_stmt->execute();

                $success = "Daily log for {$log_date} saved successfully.";
                $existing_logs[] = $log_date;
                sort($existing_logs);
            }
        }
    }
}

// UPDATE LOG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_log'])) {
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

    if ($edit_id && $log_locked) {
        $error = 'This week has been signed and cannot be edited.';
    }

    if ($edit_id && $log_date && !$error) {

        if ($attendance_status === 'absent') {
            $intended_task    = $reason_for_absence ?: 'Absent';
            $task_detail      = 'N/A - Absent';
            $actual_task      = 'N/A - Absent';
            $tools_used       = 'N/A - Absent';
            $knowledge_gained = 'N/A - Absent';
            $hours_worked     = '00:00';
        }
        $upd_stmt = $db->prepare("UPDATE daily_logs SET
            log_date = ?, attendance_status = ?, reason_for_absence = ?,
            task_title = ?, task_detail = ?, tasks_performed = ?,
            tools_used = ?, learnt_skills = ?, calculated_duration = ?
            WHERE id = ? AND internship_id = ?");
        $upd_stmt->bind_param("sssssssssii",
            $log_date, $attendance_status, $reason_for_absence,
            $intended_task, $task_detail, $actual_task,
            $tools_used, $knowledge_gained, $hours_worked,
            $edit_id, $internship_id
        );
        $upd_stmt->execute();
        $success = "Daily log for {$log_date} updated successfully.";
    }
}

// DELETE LOG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    $del_id = (int) ($_POST['log_id'] ?? 0);
    if ($del_id) {
        if ($log_locked) {
            $error = 'This week has been signed and cannot be edited.';
        } else {
            $del_stmt = $db->prepare("DELETE FROM daily_logs WHERE id = ? AND internship_id = ?");
            $del_stmt->bind_param("ii", $del_id, $internship_id);
            $del_stmt->execute();
            $success = 'Log entry deleted.';
        }
    }
}

// EDIT MODE
$editing_log = null;
$edit_att = 'present';
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_stmt = $db->prepare("SELECT * FROM daily_logs WHERE id = ? AND internship_id = ?");
    $edit_stmt->bind_param("ii", $edit_id, $internship_id);
    $edit_stmt->execute();
    $res = $edit_stmt->get_result();
    $editing_log = $res ? $res->fetch_assoc() : null;
    if ($editing_log) {
        if ($log_locked) {
            $editing_log = null;
            $error = 'This week has been signed and cannot be edited.';
        } else {
            $edit_att = $editing_log['attendance_status'] ?? 'present';
        }
    }
}

// TABLE DATA
$all_logs_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$all_logs_stmt->bind_param("i", $internship_id);
$all_logs_stmt->execute();
$res = $all_logs_stmt->get_result();
$recent_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$log_by_date = [];
foreach ($recent_logs as $log) {
    $log_by_date[$log['log_date']] = $log;
}

$week_dates = [];
if (!empty($weeks[$selected_week])) {
    $ws = new DateTime($weeks[$selected_week]['start']);
    $we = new DateTime($weeks[$selected_week]['end']);
    $ws->setTime(0, 0);
    $we->setTime(0, 0);
    $cursor = clone $ws;
    while ($cursor <= $we) {
        $day_num = (int)$cursor->format('N');
        if ($day_num < 6) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Log - InternReport</title>
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
    <style>
        .flatpickr-calendar { border-radius: 0.75rem !important; border: 1px solid #e2e8f0 !important; box-shadow: 0 10px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1) !important; font-family: inherit !important; overflow: hidden; }
        .flatpickr-months .flatpickr-month { background: linear-gradient(to right, #3b82f6, #6366f1); color: #fff; border-radius: 0; }
        .flatpickr-current-month .flatpickr-monthDropdown-months, .flatpickr-current-month input.cur-year { color: #fff; font-weight: 700; }
        span.flatpickr-weekday { color: #64748b; font-size: 0.8125rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .flatpickr-day { border-radius: 0.5rem !important; font-size: 0.8125rem; font-weight: 500; margin: 2px !important; width: 34px; height: 34px; line-height: 34px; }
        .flatpickr-day:hover:not(.flatpickr-disabled):not(.selected) { background: #eff6ff; border-color: #eff6ff; }
        .flatpickr-day.selected, .flatpickr-day.selected:hover { background: #3b82f6 !important; border-color: #3b82f6 !important; box-shadow: 0 2px 8px rgba(59,130,246,.35); }
        .flatpickr-day.today { border-color: #3b82f6 !important; }
        .flatpickr-day.today:hover { background: #eff6ff !important; }
        .flatpickr-day.flatpickr-disabled { color: #cbd5e1 !important; background: #f8fafc !important; text-decoration: line-through; cursor: not-allowed !important; opacity: 0.55; }
        .flatpickr-day.flatpickr-disabled:hover { background: #f8fafc !important; border-color: transparent !important; }
        .fp-log-exists::after { content: ''; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 5px; height: 5px; border-radius: 50%; background: #ef4444; }
        .flatpickr-day.fp-holiday { background: #fef2f2 !important; border-color: #fecaca !important; color: #dc2626 !important; font-weight: 700; }
        .flatpickr-prev-month, .flatpickr-next-month { fill: #fff !important; stroke: #fff !important; }
        .flatpickr-prev-month:hover, .flatpickr-next-month:hover { fill: #e0e7ff !important; stroke: #e0e7ff !important; }
        #logDateWrap { position: relative; }
        #logDateWrap .fp-input { padding-right: 2.5rem; }
        .glass-sidebar { background: #005f73; border-right: 1px solid rgba(15, 118, 110, 0.4); }
        html { scrollbar-gutter: stable; overflow-y: scroll; }
        .glass-sidebar nav { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
        .glass-sidebar nav::-webkit-scrollbar { width: 4px; }
        .glass-sidebar nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .nav-link { color: #ccfbf1; font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(15, 118, 110, 0.6); }
        .active-nav { background: #0a9396; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3); }
        @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased">

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
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg> Dashboard
            </a>
            <a href="notifications.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> Notifications
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> Log History
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"/></svg> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'Daily Log'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 md:p-8 no-print">
            <div class="max-w-7xl mx-auto w-full space-y-6">

                <!-- Header -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <h1 class="text-xl font-bold text-slate-800 mb-1">Daily Log Sheet</h1>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        Select a <strong class="text-slate-600">Week</strong> first, then pick a valid <strong class="text-slate-600">Date</strong>.
                        Weekends and previously submitted dates are greyed out.
                    </p>
                </div>

                <!-- Alert Messages -->
                <?php if ($error && $error !== 'duplicate_log'): ?>
                <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-sm shrink-0">✕</div>
                    <div>
                        <h3 class="text-xs font-semibold text-red-700 uppercase tracking-wider">Validation Error</h3>
                        <p class="text-xs text-red-600 mt-0.5"><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center text-sm shrink-0">✓</div>
                    <div>
                        <h3 class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Success</h3>
                        <p class="text-xs text-emerald-600 mt-0.5"><?= htmlspecialchars($success) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($weekly_report_submitted): ?>

                <!-- ════ SUBMITTED WEEKLY REPORT — READ-ONLY LOCKED NOTICE ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6 flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200 shadow-2xs">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h.01M5 21h14a2 2 0 001.71-3L13.71 4.86a2 2 0 00-3.42 0L3.29 18a2 2 0 001.71 3z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                                Week <?= $selected_week ?> Daily Logs — Read-Only Mode
                            </h3>
                            <p class="text-caption text-slate-500 font-semibold">Weekly Report for Week <?= $selected_week ?> has been submitted. Daily log entries for this week are locked and read-only.</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-bold rounded-full border bg-amber-50 text-amber-700 border-amber-200 shadow-2xs">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                        Locked - Weekly Report Submitted
                    </span>
                </div>

                <?php else: ?>

                <!-- ══════ WEEKDAY TIMELINE PILLS ══════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3.5 mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Week <?= $selected_week ?> Daily Logs Timeline
                        </span>
                        <span class="text-caption font-bold text-slate-400">Click a day pill to view or log entry</span>
                    </div>
                    <div class="flex items-center gap-2 overflow-x-auto pb-1">
                        <?php foreach ($week_dates as $d_iso): ?>
                            <?php
                            $d_obj = new DateTime($d_iso);
                            $d_label = $d_obj->format('D, d M');
                            $has_log = isset($log_by_date[$d_iso]);
                            $is_active = ($d_iso === $selected_date);
                            ?>
                            <a href="daily_log.php?week=<?= $selected_week ?>&date=<?= $d_iso ?>" class="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all border shrink-0 <?= $is_active ? 'bg-teal-700 text-white border-teal-700 shadow-sm' : ($has_log ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100') ?>">
                                <span><?= $d_label ?></span>
                                <?php if ($has_log): ?>
                                <span class="w-4 h-4 rounded-full <?= $is_active ? 'bg-white text-teal-700' : 'bg-emerald-500 text-white' ?> flex items-center justify-center text-micro">✓</span>
                                <?php else: ?>
                                <span class="w-4 h-4 rounded-full <?= $is_active ? 'bg-teal-600 text-white' : 'bg-slate-200 text-slate-500' ?> flex items-center justify-center text-micro">+</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ═══════════ DAILY LOG FORM / VIEW CARD ═══════════ -->
                <?php if ($log_locked): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-6">
                    <div class="flex items-center gap-3">
                        <svg class="w-6 h-6 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h.01M5 21h14a2 2 0 001.71-3L13.71 4.86a2 2 0 00-3.42 0L3.29 18a2 2 0 001.71 3z"/></svg>
                        <div>
                            <h2 class="text-xs font-semibold text-amber-800 uppercase tracking-wider">Week <?= $selected_week ?> is Locked</h2>
                            <p class="text-xs text-amber-600 mt-1">You have signed this week's report. Daily logs cannot be edited until the instructor reviews and rejects the report.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($active_day_log && !$editing_log): ?>

                <!-- ════ SUBMITTED DAILY LOG — DATA CARD VIEW ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-teal-50/60 to-slate-50 flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-teal-600 text-white flex items-center justify-center font-bold text-sm shadow-sm">
                                <?= (new DateTime($active_day_log['log_date']))->format('D') ?>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800 flex items-center gap-2">
                                    Daily Log — <?= (new DateTime($active_day_log['log_date']))->format('d M Y') ?>
                                </h2>
                                <p class="text-caption text-slate-400 font-semibold">
                                    Week <?= $selected_week ?> • <?= (new DateTime($active_day_log['log_date']))->format('l') ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <?php if (!empty($active_day_log['created_at'])): ?>
                            <span class="inline-flex items-center gap-1.5 text-label font-bold text-slate-600 bg-white border border-slate-200 px-3 py-1 rounded-full shadow-2xs">
                                <svg class="w-3.5 h-3.5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Submitted <?= htmlspecialchars((new DateTime($active_day_log['created_at']))->format('d M Y, h:i A')) ?>
                            </span>
                            <?php endif; ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-bold rounded-full border bg-emerald-50 text-emerald-700 border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Submitted
                            </span>
                            <?php if (!$log_locked): ?>
                            <a href="daily_log.php?week=<?= $selected_week ?>&date=<?= $selected_date ?>&edit=<?= $active_day_log['id'] ?>" class="px-3 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold rounded-full border border-indigo-200 transition">
                                ✏️ Edit Log
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-400 uppercase tracking-wider block mb-1">Attendance Status</span>
                                <?php
                                $att = $active_day_log['attendance_status'] ?? 'present';
                                $reason = $active_day_log['reason_for_absence'] ?? '';
                                $is_holiday = ($att === 'leave' || $att === 'absent') && stripos($reason, 'Public Holiday') === 0;
                                ?>
                                <?php if ($is_holiday): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-700 bg-amber-100 px-2.5 py-1 rounded-lg border border-amber-200">Public Holiday</span>
                                <?php elseif ($att === 'present'): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-100 px-2.5 py-1 rounded-lg border border-emerald-200">Present</span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-700 bg-red-100 px-2.5 py-1 rounded-lg border border-red-200">Absent</span>
                                <?php endif; ?>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-400 uppercase tracking-wider block mb-1">Working Duration</span>
                                <p class="text-sm font-mono font-bold text-teal-700 flex items-center gap-2">
                                    <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <?= htmlspecialchars($active_day_log['calculated_duration'] ?? '08:00') ?> hrs
                                </p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-400 uppercase tracking-wider block mb-1">Log Date</span>
                                <p class="text-sm font-bold text-slate-700"><?= (new DateTime($active_day_log['log_date']))->format('d M Y (D)') ?></p>
                            </div>
                        </div>

                        <?php if ($att === 'absent' || $is_holiday): ?>
                        <div class="bg-red-50/60 rounded-xl p-4 border border-red-100">
                            <span class="text-caption font-bold text-red-500 uppercase tracking-wider block mb-1">Reason for Absence / Leave</span>
                            <p class="text-sm text-red-700 font-medium"><?= htmlspecialchars($reason ?: 'No reason provided.') ?></p>
                        </div>
                        <?php else: ?>

                        <div class="bg-slate-50/70 rounded-xl p-4 border border-slate-100">
                            <span class="text-caption font-bold text-teal-700 uppercase tracking-wider block mb-1">Intended Task / Summary</span>
                            <p class="text-sm font-semibold text-slate-800 leading-relaxed"><?= htmlspecialchars($active_day_log['task_title'] ?: '—') ?></p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-slate-50/70 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-500 uppercase tracking-wider block mb-1">Task Detail Breakdown</span>
                                <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($active_day_log['task_detail'] ?: '—')) ?></p>
                            </div>
                            <div class="bg-slate-50/70 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-500 uppercase tracking-wider block mb-1">Actual Tasks Accomplished</span>
                                <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($active_day_log['tasks_performed'] ?: '—')) ?></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-slate-50/70 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-500 uppercase tracking-wider block mb-2">Tools & Tech Used</span>
                                <?php if (!empty($active_day_log['tools_used'])): ?>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <?php foreach (explode(',', $active_day_log['tools_used']) as $tool): ?>
                                            <span class="px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-xs font-mono font-bold text-teal-700 shadow-2xs"><?= htmlspecialchars(trim($tool)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm text-slate-400 italic">None specified</p>
                                <?php endif; ?>
                            </div>
                            <div class="bg-slate-50/70 rounded-xl p-4 border border-slate-100">
                                <span class="text-caption font-bold text-slate-500 uppercase tracking-wider block mb-1">Knowledge Gained & Skills</span>
                                <p class="text-sm text-slate-700 leading-relaxed"><?= htmlspecialchars($active_day_log['learnt_skills'] ?: '—') ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: ?>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 <?= $log_locked ? 'hidden' : '' ?>">
                    <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg> <?= $editing_log ? 'Edit Log Entry' : 'New Log Entry' ?>
                    </h2>

                    <form method="POST" id="logForm" class="space-y-5" onsubmit="return validateSubmit(event)">
                        <?php if ($editing_log): ?>
                        <input type="hidden" name="log_id" value="<?= $editing_log['id'] ?>">
                        <?php endif; ?>

                        <!-- Week Select -->
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Select Week</label>
                            <select name="selected_week" id="weekSelect" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition cursor-pointer">
                                <option value="">— Choose a week —</option>
                                <?php foreach ($weeks as $wn => $wr): ?>
                                <option value="<?= $wn ?>" <?= ($selected_week == $wn) ? 'selected' : '' ?>>
                                    Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p id="weekHint" class="text-sm text-slate-400 mt-1 hidden"></p>
                        </div>

                        <!-- Date Picker -->
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Log Date</label>
                            <div id="logDateWrap" class="relative">
                                <input type="text" name="log_date" id="logDate" required readonly <?= ($editing_log || $selected_week > 0) ? '' : 'disabled' ?>
                                    value="<?= $editing_log ? htmlspecialchars($editing_log['log_date']) : '' ?>"
                                    placeholder="<?= ($editing_log || $selected_week > 0) ? 'Select a date…' : 'Select a week first…' ?>"
                                    class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition disabled:opacity-50 disabled:cursor-not-allowed">
                            </div>
                            <p id="dateHint" class="text-sm text-slate-400 mt-1"><?= $editing_log ? 'Editing existing log' : 'Select a week first to open the calendar.' ?></p>
                        </div>

                        <!-- Attendance Status -->
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-2">Attendance Status</label>
                            <div class="flex items-center gap-4">
                                <label class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-lg cursor-pointer hover:bg-emerald-100 transition">
                                    <input type="radio" name="attendance_status" value="present" <?= ($editing_log ? $edit_att : 'checked') === 'present' ? 'checked' : '' ?> onchange="toggleAttendance()" class="accent-emerald-600">
                                    <span class="text-xs font-bold text-emerald-700">Present</span>
                                </label>
                                <label class="flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 rounded-lg cursor-pointer hover:bg-red-100 transition">
                                    <input type="radio" name="attendance_status" value="absent" <?= ($editing_log ? $edit_att : '') === 'absent' ? 'checked' : '' ?> onchange="toggleAttendance()" class="accent-red-600">
                                    <span class="text-xs font-bold text-red-700">Absent</span>
                                </label>
                            </div>
                        </div>

                        <!-- PRESENT FIELDS -->
                        <div id="present-fields" class="space-y-4 <?= ($editing_log && $edit_att === 'absent') ? 'hidden' : '' ?>">
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Start Time</label>
                                    <input type="time" name="start_time" id="start_time" value="<?= $editing_log ? htmlspecialchars(substr($editing_log['calculated_duration'], 0, 5)) : '09:00' ?>" onchange="calcHours()"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">End Time</label>
                                    <input type="time" name="end_time" id="end_time" value="17:00" onchange="calcHours()"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Duration</label>
                                    <input type="text" id="hours_display" value="<?= $editing_log ? htmlspecialchars($editing_log['calculated_duration']) : '08:00' ?>" readonly
                                        class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-700 font-bold cursor-default">
                                    <input type="hidden" name="hours_worked" id="hours_worked" value="<?= $editing_log ? htmlspecialchars($editing_log['calculated_duration']) : '08:00' ?>">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Intended Task</label>
                                <input type="text" name="intended_task" value="<?= htmlspecialchars($editing_log['task_title'] ?? '') ?>" placeholder="e.g. UI Design & API Integration"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Task Detail</label>
                                <textarea name="task_detail" rows="2" placeholder="Describe the planned tasks…"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['task_detail'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Actual Task Performed</label>
                                <textarea name="actual_task" rows="2" placeholder="What you actually accomplished…"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['tasks_performed'] ?? '') ?></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Tools / Tech Used</label>
                                    <input type="text" name="tools_used" value="<?= htmlspecialchars($editing_log['tools_used'] ?? '') ?>" placeholder="PHP, TailwindCSS, MySQL…"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-emerald-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Knowledge Gained</label>
                                    <input type="text" name="knowledge_gained" value="<?= htmlspecialchars($editing_log['learnt_skills'] ?? '') ?>" placeholder="Database optimization, REST APIs…"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                            </div>
                        </div>

                        <!-- ABSENT FIELDS -->
                        <div id="absent-fields" class="<?= ($editing_log && $edit_att === 'absent') ? '' : 'hidden' ?> space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Reason for Absence</label>
                                <textarea name="reason_for_absence" rows="2" placeholder="State your reason…"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['reason_for_absence'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <?php if ($editing_log): ?>
                            <a href="daily_log.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition">Cancel Edit</a>
                            <?php endif; ?>
                            <button type="submit" name="<?= $editing_log ? 'update_log' : 'add_log' ?>"
                                class="px-5 py-2 <?= $editing_log ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-blue-600 hover:bg-blue-700' ?> text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">
                                <?= $editing_log ? 'Update Log' : 'Save Daily Log' ?>
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- ═══════════ LOG HISTORY TABLE ═══════════ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </span> Log History
                            </h3>
                            <span class="text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full"><?= count($log_by_date) ?> / <?= count($week_dates) ?> day(s)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <select id="tableWeekSelect" onchange="window.location.href='daily_log.php?week='+this.value" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-bold text-slate-600 focus:outline-none focus:border-blue-500 transition cursor-pointer">
                                <?php foreach ($weeks as $wn => $wr): ?>
                                <option value="<?= $wn ?>" <?= $selected_week == $wn ? 'selected' : '' ?>>Week <?= $wn ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button onclick="window.print()" class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-emerald-50 hover:border-emerald-200 hover:text-emerald-600 transition group cursor-pointer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-16-5V9a2 2 0 012-2h12a2 2 0 012 2v4m-12 9h8a2 2 0 002-2v-3a2 2 0 00-2-2H8a2 2 0 00-2 2v3a2 2 0 002 2z"/></svg> Print
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                    <th class="px-5 py-3 text-left">ရက်စွဲ / နေ့</th>
                                    <th class="px-5 py-3 text-left">တက်ရောက်မှုအခြေအနေ</th>
                                    <th class="px-5 py-3 text-left">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                                    <th class="px-5 py-3 text-left">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                                    <th class="px-5 py-3 text-left">အသုံးပြုသောပစ္စည်းများ</th>
                                    <th class="px-5 py-3 text-left">လေ့လာသိရှိသော အသိပညာ</th>
                                    <th class="px-5 py-3 text-left">ကြာချိန်</th>
                                    <th class="px-5 py-3 text-right">လုပ်ဆောင်ချက်များ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($week_dates)): ?>
                                    <?php foreach ($week_dates as $date): ?>
                                        <?php $log = $log_by_date[$date] ?? null; ?>
                                        <?php
                                        $dt = new DateTime($date);
                                        $day_name = $dt->format('l');
                                        $date_display = $dt->format('d M Y');
                                        ?>
                                        <?php if ($log): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                            <td class="px-5 py-4 font-medium text-slate-700 whitespace-nowrap">
                                                <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                                <div class="text-label text-slate-400 font-semibold"><?= $day_name ?></div>
                                            </td>
                                            <td class="px-5 py-4 whitespace-nowrap">
                                                <?php
                                                $att = $log['attendance_status'] ?? 'present';
                                                $reason = $log['reason_for_absence'] ?? '';
                                                $is_holiday = ($att === 'leave' || $att === 'absent') && stripos($reason, 'Public Holiday') === 0;
                                                ?>
                                                <?php if ($is_holiday): ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60" title="<?= htmlspecialchars($reason) ?>">Public Holiday</span>
                                                <?php elseif ($att === 'present'): ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60">Present</span>
                                                <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200/60" title="<?= htmlspecialchars($reason) ?>">Absent</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent' || (($log['attendance_status'] ?? '') === 'leave' && stripos($log['reason_for_absence'] ?? '', 'Public Holiday') === 0); ?>
                                            <td class="px-5 py-4 text-slate-600 max-w-[160px] truncate font-medium" title="<?= $is_absent ? htmlspecialchars($log['reason_for_absence'] ?? '') : htmlspecialchars($log['task_title'] ?? '') ?>"><?= $is_absent ? htmlspecialchars($log['reason_for_absence'] ?: '-') : htmlspecialchars($log['task_title'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-slate-600 max-w-[200px] truncate font-medium" title="<?= $is_absent ? '' : htmlspecialchars($log['tasks_performed'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-slate-600 font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-slate-600 font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['learnt_skills'] ?? '-') ?></td>
                                            <td class="px-5 py-4 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                                            <td class="px-5 py-4 whitespace-nowrap text-right">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <?php if ($log_locked): ?>
                                                        <span class="px-2.5 py-1 text-sm font-bold text-slate-400 bg-slate-100 border border-slate-200 rounded-lg cursor-not-allowed">Signed</span>
                                                    <?php else: ?>
                                                        <a href="?edit=<?= $log['id'] ?>&week=<?= $selected_week ?>" class="px-2.5 py-1 text-sm font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition">Edit</a>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this log entry for <?= htmlspecialchars($log['log_date']) ?>? This cannot be undone.')">
                                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                                            <button type="submit" name="delete_log" class="px-2.5 py-1 text-sm font-bold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition cursor-pointer">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <tr class="bg-slate-50/30 hover:bg-slate-50/60 transition-colors duration-150">
                                            <td class="px-5 py-4 font-medium text-slate-700 whitespace-nowrap">
                                                <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                                <div class="text-label text-slate-400 font-semibold"><?= $day_name ?></div>
                                            </td>
                                            <td class="px-5 py-4" colspan="6">
                                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200/60">No log yet — click Save Daily Log above</span>
                                            </td>
                                            <td class="px-5 py-4 whitespace-nowrap text-right">
                                                <span class="text-label font-bold text-slate-300">—</span>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Week Reference Table -->
                <?php if (!empty($weeks)): ?>
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                        <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Week Reference (<?= count($weeks) ?> weeks)</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                    <th class="px-5 py-3 text-left">Week</th>
                                    <th class="px-5 py-3 text-left">Start</th>
                                    <th class="px-5 py-3 text-left">End</th>
                                    <th class="px-5 py-3 text-left">Logs</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php
                                    $cnt_stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
                                    foreach ($weeks as $wn => $wr):
                                        $cnt_stmt->bind_param("iss", $internship_id, $wr['start'], $wr['end']);
                                        $cnt_stmt->execute();
                                        $cnt_r = $cnt_stmt->get_result();
                                        $cnt_row = $cnt_r ? $cnt_r->fetch_assoc() : null;
                                        $log_count = $cnt_row ? (int) $cnt_row['cnt'] : 0;
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                    <td class="px-5 py-3 font-bold text-slate-700">
                                        Week <?= $wn ?>
                                        <?php if ($wn === 1): ?>
                                        <span class="text-sm text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1">partial</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-xs font-mono text-slate-600"><?= (new DateTime($wr['start']))->format('D, d M Y') ?></td>
                                    <td class="px-5 py-3 text-xs font-mono text-slate-600"><?= (new DateTime($wr['end']))->format('D, d M Y') ?></td>
                                    <td class="px-5 py-3">
                                        <span class="text-xs font-bold <?= $log_count >= 5 ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $log_count ?>/5</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
(function () {
    var weekRanges   = <?= json_encode($weeks, JSON_HEX_TAG) ?>;
    var existingLogs = <?= json_encode($existing_logs, JSON_HEX_TAG) ?>;
    var existingSet  = {};
    if (Array.isArray(existingLogs)) {
        existingLogs.forEach(function (d) { existingSet[d] = true; });
    }

    var dateInput  = document.getElementById('logDate');
    var weekSelect = document.getElementById('weekSelect');
    var dateHint   = document.getElementById('dateHint');
    var weekHint   = document.getElementById('weekHint');

    function buildDisableList() {
        return existingLogs;
    }

    var fp = flatpickr(dateInput, {
        dateFormat:    'Y-m-d',
        disableMobile: true,
        weekStart:    0,
        disable:      buildDisableList(),
        maxDate:      null,
        minDate:      null,
        clickOpens:   false,
        allowInput:   false,
        onDayCreate: function (dObj, dStr, fp, dayElem) {
            var dateStr = fmtDate(dayElem.dateObj);
            if (existingSet[dateStr]) {
                dayElem.classList.add('fp-log-exists');
                dayElem.setAttribute('title', 'Already submitted — cannot select');
            }
        },
        onChange: function (selectedDates, dateStr) {
            if (!dateStr) return;
            var d   = new Date(dateStr + 'T00:00:00');
            var day = dayName(d);
            if (existingSet[dateStr]) {
                fp.clear();
                showToast('A log for ' + dateStr + ' already exists.', 'error');
                return;
            }

            dateHint.textContent = day + ', ' +
                d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) +
                ' — ready to submit.';
            dateHint.className = 'text-sm text-emerald-600 font-semibold mt-1';
        }
    });

    function applyWeekSelection(wn) {
        if (!wn || !weekRanges[wn]) {
            fp.set('clickOpens', false);
            fp.clear();
            fp.close();
            dateInput.disabled = true;
            dateInput.value = '';
            dateInput.placeholder = 'Select a week first…';
            dateInput.classList.add('bg-slate-100');
            dateInput.classList.remove('bg-white');
            if (weekHint) weekHint.classList.add('hidden');
            if (dateHint) {
                dateHint.textContent = 'Select a week first to open the calendar.';
                dateHint.className = 'text-sm text-slate-400 mt-1';
            }
            return;
        }
        var range = weekRanges[wn];
        dateInput.disabled = false;
        dateInput.classList.remove('bg-slate-100');
        dateInput.classList.add('bg-white');
        fp.set('minDate', range.start);
        fp.set('maxDate', range.end);
        fp.set('disable', buildDisableList());
        fp.set('clickOpens', true);
        var startD = new Date(range.start + 'T00:00:00');
        var endD   = new Date(range.end + 'T00:00:00');
        var totalDays = Math.round((endD - startD) / 86400000) + 1;
        if (weekHint) {
            weekHint.textContent = 'Week ' + wn + ': ' +
                dayName(startD) + ', ' + startD.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) +
                ' → ' +
                dayName(endD) + ', ' + endD.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            weekHint.className = 'text-sm text-blue-500 font-semibold mt-1';
            weekHint.classList.remove('hidden');
        }
        if (dateHint) {
            dateHint.textContent = 'Open the calendar — pick any weekday within this ' + totalDays + '-day window.';
            dateHint.className = 'text-sm text-slate-400 mt-1';
        }
    }

    if (weekSelect) {
        weekSelect.addEventListener('change', function () {
            fp.clear();
            applyWeekSelection(parseInt(this.value));
            fp.open();
        });

        var initWn = parseInt(weekSelect.value);
        if (initWn) {
            applyWeekSelection(initWn);
        }
    }

    window.validateSubmit = function (e) {
        var wn  = parseInt(weekSelect.value);
        var val = dateInput.value;
        if (!wn) {
            showToast('Please select a week first.', 'error');
            e.preventDefault();
            return false;
        }
        if (!val) {
            showToast('Please select a log date from the calendar.', 'error');
            e.preventDefault();
            return false;
        }
        var range = weekRanges[wn];
        if (range && (val < range.start || val > range.end)) {
            showToast('The selected date is outside Week ' + wn + '.', 'error');
            e.preventDefault();
            return false;
        }
        var dow = new Date(val + 'T00:00:00').getDay();
        if (dow === 0 || dow === 6) {
            showToast('Weekends are not allowed. Please select a weekday.', 'error');
            e.preventDefault();
            return false;
        }
        if (existingSet[val]) {
            showToast('A log for this date already exists.', 'error');
            e.preventDefault();
            return false;
        }
        return true;
    };

    function fmtDate(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function dayName(d) {
        return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
    }

    window.calcHours = function () {
        var start = document.getElementById('start_time').value;
        var end   = document.getElementById('end_time').value;
        if (!start || !end) return;
        var s = start.split(':'), e = end.split(':');
        var sm = parseInt(s[0]) * 60 + parseInt(s[1]);
        var em = parseInt(e[0]) * 60 + parseInt(e[1]);
        if (em < sm) em += 1440;
        var diff = em - sm;
        var h = Math.floor(diff / 60);
        var m = diff % 60;
        var pad = function (n) { return n < 10 ? '0' + n : n; };
        var result = pad(h) + ':' + pad(m);
        document.getElementById('hours_display').value = result;
        document.getElementById('hours_worked').value = result;
    };

    window.toggleAttendance = function () {
        var status  = document.querySelector('input[name="attendance_status"]:checked').value;
        var present = document.getElementById('present-fields');
        var absent  = document.getElementById('absent-fields');
        if (status === 'absent') {
            present.classList.add('hidden');
            absent.classList.remove('hidden');
        } else {
            present.classList.remove('hidden');
            absent.classList.add('hidden');
        }
    };
})();
</script>

<script>
function showToast(message, type) {
    var toast = document.createElement('div');
    var bgColor, icon;
    switch (type) {
        case 'success': bgColor = 'bg-emerald-600'; icon = '✓'; break;
        case 'error':   bgColor = 'bg-red-600'; icon = '✕'; break;
        case 'warning': bgColor = 'bg-amber-500'; icon = '⚠'; break;
        default:        bgColor = 'bg-slate-700'; icon = 'ℹ';
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
</script>

<?php if ($error === 'duplicate_log'): ?>
<script>
(function () {
    showToast('A daily log for this date has already been submitted. Please select a different date.', 'error');
    var fp = document.getElementById('logDate');
    if (fp) fp.value = '';
})();
</script>
<?php endif; ?>

</body>
</html>
