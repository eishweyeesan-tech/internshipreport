<?php
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';

$user_id       = $_SESSION['user_id'];
$internship_id = $user_id;

// ══════════════════════════════════════════════════════════════════════
// FETCH INTERNSHIP DATE RANGE
// ══════════════════════════════════════════════════════════════════════
$esc_uid = $conn->real_escape_string($user_id);
$profile_r = $conn->query("SELECT sp.full_name, sp.internship_start_date, sp.internship_end_date,
    sp.company_name, sp.job_role, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = {$esc_uid}");
$profile_row = $profile_r ? $profile_r->fetch_assoc() : null;
$intern_start = $profile_row['internship_start_date'] ?? null;
$intern_end   = $profile_row['internship_end_date'] ?? null;
$student_name = $profile_row['full_name'] ?? $username;
$profile_pic  = $profile_row['profile_pic'] ?? null;

// ══════════════════════════════════════════════════════════════════════
// FETCH PUBLIC HOLIDAYS
// ══════════════════════════════════════════════════════════════════════
$all_holidays = [];
$hol_r = $conn->query("SELECT holiday_date, holiday_name FROM holidays ORDER BY holiday_date ASC");
if ($hol_r) { while ($row = $hol_r->fetch_assoc()) { $all_holidays[] = $row; } }
$holiday_dates = [];
foreach ($all_holidays as $hl) { $holiday_dates[$hl['holiday_date']] = $hl['holiday_name']; }
$holiday_date_list = array_keys($holiday_dates);

// ══════════════════════════════════════════════════════════════════════
// FETCH EXISTING LOG DATES
// ══════════════════════════════════════════════════════════════════════
$esc_iid = $conn->real_escape_string($internship_id);
$log_dates_r = $conn->query("SELECT log_date FROM daily_logs WHERE internship_id = {$esc_iid}");
$existing_logs = [];
if ($log_dates_r) {
    while ($row = $log_dates_r->fetch_assoc()) {
        $existing_logs[] = $row['log_date'];
    }
}

// ══════════════════════════════════════════════════════════════════════
// BUILD WEEK RANGES
// ══════════════════════════════════════════════════════════════════════
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
    foreach ($weeks as $wn => $wr) {
        $esc_wk_s = $conn->real_escape_string($wr['start']);
        $esc_wk_e = $conn->real_escape_string($wr['end']);
        $wc_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_wk_s}' AND '{$esc_wk_e}'");
        if ($wc_r && $wc_r->num_rows > 0 && (int) $wc_r->fetch_row()[0] > 0) {
            $progress_weeks_completed++;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════
// SELECTED WEEK & LOCK STATUS
// ══════════════════════════════════════════════════════════════════════
$selected_week = (int) ($_GET['week'] ?? $_POST['selected_week'] ?? 0);
if ($selected_week < 1 || $selected_week > count($weeks)) {
    $selected_week = count($weeks) > 0 ? count($weeks) : 1;
}

// Check if logs are locked for this week (student signed and not rejected)
$log_locked = false;
$esc_sw = $conn->real_escape_string($selected_week);
$lock_r = $conn->query("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_sw}");
if ($lock_r && $lock_r->num_rows > 0) {
    $lock_row = $lock_r->fetch_assoc();
    if (!empty($lock_row['student_signature_type']) && !empty($lock_row['student_signature_value']) && $lock_row['report_status'] !== 'rejected') {
        $log_locked = true;
    }
}

// ══════════════════════════════════════════════════════════════════════
// HANDLE FORM SUBMISSIONS
// ══════════════════════════════════════════════════════════════════════
$error   = '';
$success = '';

// ── ADD LOG ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $add_week = (int) ($_POST['selected_week'] ?? 0);
    if ($add_week > 0) {
        $esc_aw = $conn->real_escape_string($add_week);
        $add_lock_r = $conn->query("SELECT student_signature_type, student_signature_value, report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_aw}");
        if ($add_lock_r && $add_lock_r->num_rows > 0) {
            $add_lock_row = $add_lock_r->fetch_assoc();
            if (!empty($add_lock_row['student_signature_type']) && !empty($add_lock_row['student_signature_value']) && $add_lock_row['report_status'] !== 'rejected') {
                $error = 'This week has been signed and cannot be edited.';
            }
        }
    }
    $log_date      = trim($_POST['log_date'] ?? '');

    if ($selected_week < 1 || $selected_week > count($weeks)) {
        $error = 'Invalid week selection.';
    } elseif (empty($log_date)) {
        $error = 'Please select a date.';
    } else {
        $week_range = $weeks[$selected_week] ?? null;
        if (!$week_range || $log_date < $week_range['start'] || $log_date > $week_range['end']) {
            $error = "Date must be between {$week_range['start']} and {$week_range['end']} (Week {$selected_week}).";
        } else {
            $esc_log = $conn->real_escape_string($log_date);
            $dup_r = $conn->query("SELECT id FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date = '{$esc_log}' LIMIT 1");
            if ($dup_r && $dup_r->num_rows > 0) {
                $error = "duplicate_log";
            } else {
                $attendance_status  = trim($_POST['attendance_status'] ?? 'present');
                $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');

                if (isset($holiday_dates[$log_date])) {
                    $attendance_status = 'leave';
                    $reason_for_absence = 'Public Holiday - ' . $holiday_dates[$log_date];
                }

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

                $esc_att  = $conn->real_escape_string($attendance_status);
                $esc_rfa  = $conn->real_escape_string($reason_for_absence);
                $esc_it   = $conn->real_escape_string($intended_task);
                $esc_td   = $conn->real_escape_string($task_detail);
                $esc_at   = $conn->real_escape_string($actual_task);
                $esc_tu   = $conn->real_escape_string($tools_used);
                $esc_kg   = $conn->real_escape_string($knowledge_gained);
                $esc_hw   = $conn->real_escape_string($hours_worked);
                $esc_ld   = $conn->real_escape_string($log_date);

                $conn->query("INSERT INTO daily_logs
                    (internship_id, log_date, attendance_status, reason_for_absence,
                     task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
                    VALUES ({$esc_iid}, '{$esc_ld}', '{$esc_att}', '{$esc_rfa}',
                            '{$esc_it}', '{$esc_td}', '{$esc_at}', '{$esc_tu}', '{$esc_kg}', '{$esc_hw}')");

                $success = "Daily log for {$log_date} saved successfully.";
                $existing_logs[] = $log_date;
                sort($existing_logs);
            }
        }
    }
}

// ── UPDATE LOG ──────────────────────────────────────────────────
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

    // Check if this log is locked (student signed and not rejected)
    if ($edit_id && $log_locked) {
        $error = 'This week has been signed and cannot be edited.';
    }

    if ($edit_id && $log_date && !$error) {
        if (isset($holiday_dates[$log_date])) {
            $attendance_status = 'leave';
            $reason_for_absence = 'Public Holiday - ' . $holiday_dates[$log_date];
        }
        if ($attendance_status === 'absent') {
            $intended_task    = $reason_for_absence ?: 'Absent';
            $task_detail      = 'N/A - Absent';
            $actual_task      = 'N/A - Absent';
            $tools_used       = 'N/A - Absent';
            $knowledge_gained = 'N/A - Absent';
            $hours_worked     = '00:00';
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
        $success = "Daily log for {$log_date} updated successfully.";
    }
}

// ── DELETE LOG ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    $del_id = (int) ($_POST['log_id'] ?? 0);
    if ($del_id) {
        // Check if this log is locked (student signed and not rejected)
        if ($log_locked) {
            $error = 'This week has been signed and cannot be edited.';
        } else {
            $esc_del = $conn->real_escape_string($del_id);
            $conn->query("DELETE FROM daily_logs WHERE id = {$esc_del} AND internship_id = {$esc_iid}");
            $success = 'Log entry deleted.';
        }
    }
}

// ══════════════════════════════════════════════════════════════════════
// EDIT MODE
// ══════════════════════════════════════════════════════════════════════
$editing_log = null;
$edit_att = 'present';
if (isset($_GET['edit'])) {
    $esc_edit_id = $conn->real_escape_string((int)$_GET['edit']);
    $edit_r = $conn->query("SELECT * FROM daily_logs WHERE id = {$esc_edit_id} AND internship_id = {$esc_iid}");
    $editing_log = ($edit_r && $edit_r->num_rows > 0) ? $edit_r->fetch_assoc() : null;
    if ($editing_log) {
        // Check if this log is locked (student signed and not rejected)
        if ($log_locked) {
            $editing_log = null;
            $error = 'This week has been signed and cannot be edited.';
        } else {
            $edit_att = $editing_log['attendance_status'] ?? 'present';
        }
    }
}

// ══════════════════════════════════════════════════════════════════════
// TABLE DATA: Fetch all logs for table
// ══════════════════════════════════════════════════════════════════════
$all_logs_r = $conn->query("SELECT * FROM daily_logs WHERE internship_id = {$esc_iid} ORDER BY log_date ASC");
$recent_logs = [];
if ($all_logs_r) {
    while ($row = $all_logs_r->fetch_assoc()) {
        $recent_logs[] = $row;
    }
}

// Build log-by-date for table
$log_by_date = [];
foreach ($recent_logs as $log) {
    $log_by_date[$log['log_date']] = $log;
}

// Generate dates for selected week
$week_dates = [];
if (!empty($weeks[$selected_week])) {
    $ws = new DateTime($weeks[$selected_week]['start']);
    $we = new DateTime($weeks[$selected_week]['end']);
    $ws->setTime(0, 0);
    $we->setTime(0, 0);
    $cursor = clone $ws;
    while ($cursor <= $we) {
        $week_dates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
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
        .glass-sidebar { background: rgba(30, 27, 75, 0.85); backdrop-filter: blur(20px); }
        .nav-link { color: rgba(255,255,255,0.55); font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .active-nav { background: #9333ea; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(147,51,234,0.3); }
        @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 glass-sidebar flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-white/10">
            <span class="text-sm font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-3">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📝</span> Dashboard
            </a>
            <a href="analytics.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Analytics
            </a>
            <a href="daily_log.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📋</span> Daily Log
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📅</span> Intern Period Calendar
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
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
        <?php $pageTitle = 'Daily Log'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 no-print">
            <div class="max-w-5xl mx-auto space-y-6">

                <!-- Header -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
                    <h1 class="text-lg font-black text-slate-800 mb-1">📝 Daily Log Sheet</h1>
                    <p class="text-xs text-slate-400 leading-relaxed">
                        Select a <strong class="text-slate-600">Week</strong> first, then pick a valid <strong class="text-slate-600">Date</strong>.
                        Weekends, public holidays, and previously submitted dates are greyed out.
                    </p>
                </div>

                <!-- Alert Messages -->
                <?php if ($error && $error !== 'duplicate_log'): ?>
                <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-sm shrink-0">✕</div>
                    <div>
                        <h3 class="text-xs font-bold text-red-700">Validation Error</h3>
                        <p class="text-xs text-red-600 mt-0.5"><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center text-sm shrink-0">✓</div>
                    <div>
                        <h3 class="text-xs font-bold text-emerald-700">Success</h3>
                        <p class="text-xs text-emerald-600 mt-0.5"><?= htmlspecialchars($success) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ═══════════ DAILY LOG FORM ═══════════ -->
                <?php if ($log_locked): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-6">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">🔒</span>
                        <div>
                            <h2 class="text-sm font-black text-amber-800 uppercase tracking-wider">Week <?= $selected_week ?> is Locked</h2>
                            <p class="text-xs text-amber-600 mt-1">You have signed this week's report. Daily logs cannot be edited until the instructor reviews and rejects the report.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6 <?= $log_locked ? 'hidden' : '' ?>">
                    <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center gap-2">
                        <span class="p-1 bg-blue-50 text-blue-600 rounded">📝</span> <?= $editing_log ? 'Edit Log Entry' : 'New Log Entry' ?>
                    </h2>

                    <form method="POST" id="logForm" class="space-y-5" onsubmit="return validateSubmit(event)">
                        <?php if ($editing_log): ?>
                        <input type="hidden" name="log_id" value="<?= $editing_log['id'] ?>">
                        <?php endif; ?>

                        <!-- Week Select -->
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">📆 Select Week</label>
                            <select name="selected_week" id="weekSelect" required
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition cursor-pointer">
                                <option value="">— Choose a week —</option>
                                <?php foreach ($weeks as $wn => $wr): ?>
                                <option value="<?= $wn ?>" <?= ($editing_log || $selected_week == $wn) ? 'selected' : '' ?>>
                                    Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p id="weekHint" class="text-sm text-slate-400 mt-1 hidden"></p>
                        </div>

                        <!-- Date Picker -->
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">📅 Log Date</label>
                            <div id="logDateWrap" class="relative">
                                <input type="text" name="log_date" id="logDate" required readonly <?= $editing_log ? '' : 'disabled' ?>
                                    value="<?= $editing_log ? htmlspecialchars($editing_log['log_date']) : '' ?>"
                                    placeholder="<?= $editing_log ? '' : 'Select a week first…' ?>"
                                    class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition disabled:opacity-50 disabled:cursor-not-allowed">
                            </div>
                            <p id="dateHint" class="text-sm text-slate-400 mt-1"><?= $editing_log ? '✓ Editing existing log' : 'Select a week first to open the calendar.' ?></p>
                        </div>

                        <!-- Attendance Status -->
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-2">✅ Attendance Status</label>
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
                                    <label class="block text-sm font-bold text-slate-500 mb-1">⏱️ Start Time</label>
                                    <input type="time" name="start_time" id="start_time" value="<?= $editing_log ? htmlspecialchars(substr($editing_log['calculated_duration'], 0, 5)) : '09:00' ?>" onchange="calcHours()"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">⏱️ End Time</label>
                                    <input type="time" name="end_time" id="end_time" value="17:00" onchange="calcHours()"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">⏳ Duration</label>
                                    <input type="text" id="hours_display" value="<?= $editing_log ? htmlspecialchars($editing_log['calculated_duration']) : '08:00' ?>" readonly
                                        class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-700 font-bold cursor-default">
                                    <input type="hidden" name="hours_worked" id="hours_worked" value="<?= $editing_log ? htmlspecialchars($editing_log['calculated_duration']) : '08:00' ?>">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">💡 Intended Task</label>
                                <input type="text" name="intended_task" value="<?= htmlspecialchars($editing_log['task_title'] ?? '') ?>" placeholder="e.g. UI Design & API Integration"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">📋 Task Detail</label>
                                <textarea name="task_detail" rows="2" placeholder="Describe the planned tasks…"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['task_detail'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">✅ Actual Task Performed</label>
                                <textarea name="actual_task" rows="2" placeholder="What you actually accomplished…"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"><?= htmlspecialchars($editing_log['tasks_performed'] ?? '') ?></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">🛠️ Tools / Tech Used</label>
                                    <input type="text" name="tools_used" value="<?= htmlspecialchars($editing_log['tools_used'] ?? '') ?>" placeholder="PHP, TailwindCSS, MySQL…"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-emerald-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">🧠 Knowledge Gained</label>
                                    <input type="text" name="knowledge_gained" value="<?= htmlspecialchars($editing_log['learnt_skills'] ?? '') ?>" placeholder="Database optimization, REST APIs…"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                </div>
                            </div>
                        </div>

                        <!-- ABSENT FIELDS -->
                        <div id="absent-fields" class="<?= ($editing_log && $edit_att === 'absent') ? '' : 'hidden' ?> space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">📝 Reason for Absence</label>
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
                                <?= $editing_log ? '✏️ Update Log' : '💾 Save Daily Log' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ═══════════ LOG HISTORY TABLE ═══════════ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📋</span> Log History
                            </h3>
                            <span class="text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full"><?= count($log_by_date) ?> / <?= count($week_dates) ?> day(s)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <select id="tableWeekSelect" onchange="window.location.href='daily_log.php?week='+this.value" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-bold text-slate-600 focus:outline-none focus:border-blue-500 transition cursor-pointer">
                                <?php foreach ($weeks as $wn => $wr): ?>
                                <option value="<?= $wn ?>" <?= $selected_week == $wn ? 'selected' : '' ?>>Week <?= $wn ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button onclick="window.print()" class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-slate-600 bg-slate-50 border border-slate-100 rounded-lg hover:bg-emerald-50 hover:border-emerald-200 hover:text-emerald-600 transition group cursor-pointer">
                                <span class="group-hover:scale-110 transition-transform">🖨️</span> Print
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
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60" title="<?= htmlspecialchars($reason) ?>">🇲🇲 Public Holiday</span>
                                                <?php elseif ($att === 'present'): ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60">✅ Present</span>
                                                <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200/60" title="<?= htmlspecialchars($reason) ?>">❌ Absent</span>
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
                                                        <span class="px-2.5 py-1 text-sm font-bold text-slate-400 bg-slate-100 border border-slate-200 rounded-lg cursor-not-allowed">🔒 Signed</span>
                                                    <?php else: ?>
                                                        <a href="?edit=<?= $log['id'] ?>&week=<?= $selected_week ?>" class="px-2.5 py-1 text-sm font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition">✏️ Edit</a>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this log entry for <?= htmlspecialchars($log['log_date']) ?>? This cannot be undone.')">
                                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                                            <button type="submit" name="delete_log" class="px-2.5 py-1 text-sm font-bold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️ Delete</button>
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
                                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200/60">📝 No log yet — click Save Daily Log above</span>
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
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
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
                                <?php foreach ($weeks as $wn => $wr): ?>
                                <?php
                                    $esc_ws = $conn->real_escape_string($wr['start']);
                                    $esc_we = $conn->real_escape_string($wr['end']);
                                    $cnt_r = $conn->query("SELECT COUNT(*) AS cnt FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_ws}' AND '{$esc_we}'");
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
    var holidayDates = <?= json_encode($holiday_date_list, JSON_HEX_TAG) ?>;
    var holidayNames = <?= json_encode($holiday_dates, JSON_HEX_TAG) ?>;
    var existingSet  = {};
    existingLogs.forEach(function (d) { existingSet[d] = true; });
    var holidaySet = {};
    holidayDates.forEach(function (d) { holidaySet[d] = true; });

    var weekSelect = document.getElementById('weekSelect');
    var dateInput  = document.getElementById('logDate');
    var weekHint   = document.getElementById('weekHint');
    var dateHint   = document.getElementById('dateHint');

    function buildDisableList() {
        return existingLogs.concat(holidayDates);
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
            } else if (holidaySet[dateStr]) {
                dayElem.style.background = '#fef2f2';
                dayElem.style.borderColor = '#fecaca';
                dayElem.style.color = '#dc2626';
                dayElem.style.fontWeight = '700';
                dayElem.setAttribute('title', 'Public Holiday - ' + holidayNames[dateStr]);
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
            if (holidaySet[dateStr]) {
                var leaveRadio = document.querySelector('input[name="attendance_status"][value="absent"]');
                if (leaveRadio) { leaveRadio.checked = true; toggleAttendance(); }
                var reasonField = document.querySelector('textarea[name="reason_for_absence"]');
                if (reasonField) { reasonField.value = 'Public Holiday - ' + holidayNames[dateStr]; }
                dateHint.textContent = '⚠️ ' + day + ', ' +
                    d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) +
                    ' — Public Holiday (' + holidayNames[dateStr] + '). Marked as Leave.';
                dateHint.className = 'text-sm text-amber-600 font-semibold mt-1';
                return;
            }
            dateHint.textContent = '✓ ' + day + ', ' +
                d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) +
                ' — ready to submit.';
            dateHint.className = 'text-sm text-emerald-600 font-semibold mt-1';
        }
    });

    weekSelect.addEventListener('change', function () {
        var wn = parseInt(this.value);
        if (!wn || !weekRanges[wn]) {
            fp.set('clickOpens', false);
            fp.clear();
            fp.close();
            dateInput.disabled = true;
            dateInput.value = '';
            dateInput.placeholder = 'Select a week first…';
            dateInput.classList.add('bg-slate-100');
            dateInput.classList.remove('bg-white');
            weekHint.classList.add('hidden');
            dateHint.textContent = 'Select a week first to open the calendar.';
            dateHint.className = 'text-sm text-slate-400 mt-1';
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
        fp.clear();
        var startD = new Date(range.start + 'T00:00:00');
        var endD   = new Date(range.end + 'T00:00:00');
        var totalDays = Math.round((endD - startD) / 86400000) + 1;
        weekHint.textContent = 'Week ' + wn + ': ' +
            dayName(startD) + ', ' + startD.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) +
            ' → ' +
            dayName(endD) + ', ' + endD.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        weekHint.className = 'text-sm text-blue-500 font-semibold mt-1';
        dateHint.textContent = 'Open the calendar — pick any weekday within this ' + totalDays + '-day window.';
        dateHint.className = 'text-sm text-slate-400 mt-1';
        fp.open();
    });

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
