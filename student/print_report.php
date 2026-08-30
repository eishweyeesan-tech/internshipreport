<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$auth_role     = $_SESSION['role'] ?? 'student';
$auth_user_id  = (int) $_SESSION['user_id'];
$db            = $mysqli ?? $conn;

// Target student ID
$target_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (isset($_GET['uid']) ? (int)$_GET['uid'] : 0);
if ($target_student_id <= 0) {
    if ($auth_role === 'student') {
        $target_student_id = $auth_user_id;
    } else {
        // Fallback for supervisor/admin if accessed without student_id
        if ($auth_role === 'supervisor') {
            $f_stmt = $db->prepare("SELECT user_id FROM student_profiles WHERE supervisor_id = ? ORDER BY id ASC LIMIT 1");
            $f_stmt->bind_param("i", $auth_user_id);
            $f_stmt->execute();
            $f_res = $f_stmt->get_result();
            $target_student_id = ($f_res && $f_row = $f_res->fetch_row()) ? (int)$f_row[0] : 0;
        } else {
            $f_stmt = $db->query("SELECT user_id FROM student_profiles ORDER BY id ASC LIMIT 1");
            $target_student_id = ($f_stmt && $f_row = $f_stmt->fetch_row()) ? (int)$f_row[0] : 0;
        }
        if ($target_student_id <= 0) {
            $target_student_id = $auth_user_id;
        }
    }
}

// Access authorization for supervisors
if ($auth_role === 'supervisor' && $target_student_id !== $auth_user_id) {
    $chk = $db->prepare("SELECT 1 FROM student_profiles WHERE user_id = ? AND supervisor_id = ?");
    $chk->bind_param("ii", $target_student_id, $auth_user_id);
    $chk->execute();
    $chk_res = $chk->get_result();
    if (!$chk_res || !$chk_res->fetch_row()) {
        header('Location: ../supervisor/supervisor-dashboard.php');
        exit;
    }
}

$user_id       = $target_student_id;
$username      = $_SESSION['username'];
$internship_id = $user_id;

// 1. FETCH STUDENT & SUPERVISOR & INSTRUCTOR PROFILE
$profile_stmt = $db->prepare("
    SELECT sp.full_name, sp.student_roll, sp.internship_start_date, sp.internship_end_date, 
           sup_u.username AS supervisor_name, sup_u.email AS supervisor_email, sp.supervisor_id, 
           sp.instructor_name, sp.instructor_email, sp.instructor_id, u.email AS student_email, u.username AS student_username, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?
");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$profile_res = $profile_stmt->get_result();
$profile = $profile_res ? $profile_res->fetch_assoc() : null;

$student_name     = ($profile['full_name'] ?? '') ?: ($profile['student_username'] ?? 'Student');
$student_roll     = $profile['student_roll'] ?? '—';
$student_email    = $profile['student_email'] ?? '—';
$supervisor_name  = format_supervisor_name($profile['supervisor_name'] ?? '—');
$supervisor_email = $profile['supervisor_email'] ?? '—';
$instructor_name  = ($profile['instructor_name'] ?? '') ?: '—';
$instructor_email = $profile['instructor_email'] ?? '—';
$intern_start     = $profile['internship_start_date'] ?? null;
$intern_end       = $profile['internship_end_date'] ?? null;

// Build Week Ranges
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

// Selected Week (Default to Week 1 or active week)
$is_all_weeks = isset($_GET['week']) && $_GET['week'] === 'all';
$selected_week = isset($_GET['week']) && $_GET['week'] !== 'all' ? (int)$_GET['week'] : 1;
if ($selected_week < 1 || (!empty($weeks) && !isset($weeks[$selected_week]))) {
    $selected_week = 1;
}

$current_range = $weeks[$selected_week] ?? null;
$week_start    = $current_range['start'] ?? '';
$week_end      = $current_range['end'] ?? '';

// Fetch Daily Logs for selected week or all weeks
if ($is_all_weeks) {
    $log_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
    $log_stmt->bind_param("i", $internship_id);
} else {
    $log_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC");
    $log_stmt->bind_param("iss", $internship_id, $week_start, $week_end);
}
$log_stmt->execute();
$logs_res = $log_stmt->get_result();
$daily_logs = $logs_res ? $logs_res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch Weekly Reflection(s)
if ($is_all_weeks) {
    $ref_stmt = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? ORDER BY week_number ASC");
    $ref_stmt->bind_param("i", $internship_id);
    $ref_stmt->execute();
    $ref_res = $ref_stmt->get_result();
    $all_weekly_reflections = $ref_res ? $ref_res->fetch_all(MYSQLI_ASSOC) : [];
    $weekly_reflection = $all_weekly_reflections[0] ?? null;
} else {
    $ref_stmt = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
    $ref_stmt->bind_param("ii", $internship_id, $selected_week);
    $ref_stmt->execute();
    $ref_res = $ref_stmt->get_result();
    $weekly_reflection = $ref_res ? $ref_res->fetch_assoc() : null;
    $all_weekly_reflections = $weekly_reflection ? [$weekly_reflection] : [];
}

// Fetch Evaluation / Signatures (Company Instructor)
if ($is_all_weeks) {
    $eval_stmt = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? ORDER BY week_number ASC");
    $eval_stmt->bind_param("i", $internship_id);
    $eval_stmt->execute();
    $eval_res = $eval_stmt->get_result();
    $all_evaluations = $eval_res ? $eval_res->fetch_all(MYSQLI_ASSOC) : [];
    $evaluation = !empty($all_evaluations) ? end($all_evaluations) : null;
} else {
    $eval_stmt = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $eval_stmt->bind_param("ii", $internship_id, $selected_week);
    $eval_stmt->execute();
    $eval_res = $eval_stmt->get_result();
    $evaluation = $eval_res ? $eval_res->fetch_assoc() : null;
    $all_evaluations = $evaluation ? [$evaluation] : [];
}

// Fetch Supervisor Weekly Evaluation / Reflection
if ($is_all_weeks) {
    $sup_eval_stmt = $db->prepare("
        SELECT swe.*, u.username AS supervisor_name 
        FROM supervisor_weekly_evaluations swe 
        LEFT JOIN users u ON u.id = swe.supervisor_id 
        WHERE swe.student_id = ?
        ORDER BY swe.week_number ASC
    ");
    $sup_eval_stmt->bind_param("i", $internship_id);
    $sup_eval_stmt->execute();
    $sup_eval_res = $sup_eval_stmt->get_result();
    $all_sup_evaluations = $sup_eval_res ? $sup_eval_res->fetch_all(MYSQLI_ASSOC) : [];
    $supervisor_evaluation = !empty($all_sup_evaluations) ? end($all_sup_evaluations) : null;
} else {
    $sup_eval_stmt = $db->prepare("
        SELECT swe.*, u.username AS supervisor_name 
        FROM supervisor_weekly_evaluations swe 
        LEFT JOIN users u ON u.id = swe.supervisor_id 
        WHERE swe.student_id = ? AND swe.week_number = ?
    ");
    $sup_eval_stmt->bind_param("ii", $internship_id, $selected_week);
    $sup_eval_stmt->execute();
    $sup_eval_res = $sup_eval_stmt->get_result();
    $supervisor_evaluation = $sup_eval_res ? $sup_eval_res->fetch_assoc() : null;
    $all_sup_evaluations = $supervisor_evaluation ? [$supervisor_evaluation] : [];
}

// Calculate Summary Metrics
$total_logged_days = count($daily_logs);
$present_days = 0;
$absent_days = 0;
$total_minutes = 0;

foreach ($daily_logs as $log) {
    if (($log['attendance_status'] ?? 'present') === 'present') {
        $present_days++;
        $duration = $log['calculated_duration'] ?? $log['hours_worked'] ?? '00:00';
        if (preg_match('/^(\d+):(\d+)$/', $duration, $m)) {
            $total_minutes += (int)$m[1] * 60 + (int)$m[2];
        }
    } else {
        $absent_days++;
    }
}
$total_hours_formatted = floor($total_minutes / 60) . ' hrs ' . ($total_minutes % 60) . ' mins';

$doc_ref_id = 'IR-' . date('Y') . '-' . str_pad($user_id, 4, '0', STR_PAD_LEFT) . '-' . ($is_all_weeks ? 'ALL' : 'W' . $selected_week);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Internship Report - Week <?= $selected_week ?> - <?= htmlspecialchars($student_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Great+Vibes&family=Inter:wght@400;500;600;700;800;900&family=Merriweather:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 15mm 15mm;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #0f172a;
            background-color: #f8fafc;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .serif-heading {
            font-family: 'Merriweather', Georgia, serif;
        }

        .sig-font-great {
            font-family: 'Great Vibes', cursive;
        }

        .sig-font-alex {
            font-family: 'Alex Brush', cursive;
        }

        @media print {
            body {
                background: white !important;
                padding: 0 !important;
            }

            .no-print,
            .print-toolbar {
                display: none !important;
            }

            .page-container {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }

            .avoid-break {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
    </style>
</head>

<body class="p-4 sm:p-8 min-h-screen">

    <?php
    $cur_query = $_GET;
    unset($cur_query['week']);
    $preserved_query = http_build_query($cur_query);
    $week_switch_prefix = $preserved_query ? 'print_report.php?' . $preserved_query . '&week=' : 'print_report.php?week=';

    $back_url = 'log-history.php';
    if ($auth_role === 'supervisor') {
        $back_url = '../supervisor/supervisor-review.php?student_id=' . $user_id . '&week=' . ($selected_week ?: 1);
    } elseif ($auth_role === 'admin') {
        $back_url = '../view_student_history.php?uid=' . $user_id;
    } else {
        $back_url = 'log-history.php' . ($selected_week && !$is_all_weeks ? "?mode=weekly&week={$selected_week}" : '');
    }
    ?>
    <!-- ═══════════ TOP ACTION TOOLBAR (SCREEN ONLY) ═══════════ -->
    <div class="print-toolbar max-w-5xl mx-auto mb-6 bg-white/90 backdrop-blur-md border border-slate-200/80 rounded-2xl p-4 shadow-lg flex items-center justify-between flex-wrap gap-4 sticky top-4 z-50">
        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars($back_url) ?>" class="inline-flex items-center gap-2 px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Log History
            </a>
            <span class="text-slate-300">|</span>
            <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-500">Select Week:</label>
                <select onchange="window.location.href='<?= $week_switch_prefix ?>' + this.value" class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-700 focus:outline-none focus:border-indigo-500 cursor-pointer">
                    <option value="all" <?= $is_all_weeks ? 'selected' : '' ?>>All Weeks (Complete History)</option>
                    <?php foreach ($weeks as $wn => $wr): ?>
                        <option value="<?= $wn ?>" <?= $selected_week == $wn && !$is_all_weeks ? 'selected' : '' ?>>Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> - <?= (new DateTime($wr['end']))->format('d M') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-indigo-500/20 active:scale-95 transition cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Save as PDF
            </button>
        </div>
    </div>

    <!-- ═══════════ OFFICIAL REPORT CONTAINER (A4 FORMAT) ═══════════ -->
    <div class="page-container max-w-5xl mx-auto bg-white border border-slate-200 rounded-2xl shadow-xl p-8 sm:p-12 print:p-0 print:border-none print:shadow-none">

        <!-- ── HEADER / OFFICIAL LETTERHEAD ── -->
        <header class="border-b-2 border-slate-900 pb-5 mb-6">
            <div class="flex items-start justify-between gap-6 flex-wrap">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-indigo-900 text-white flex items-center justify-center font-black text-2xl tracking-tighter shrink-0 print:bg-slate-900">
                        IR
                    </div>
                    <div>
                        <h1 class="serif-heading text-lg sm:text-xl font-black text-slate-900 uppercase tracking-tight">
                            Internship Training Record & Weekly Report
                        </h1>
                        <p class="text-xs font-bold text-slate-600 tracking-wide mt-0.5">
                            အလုပ်သင်လက်တွေ့သင်တန်း နေ့စဉ်မှတ်တမ်းနှင့် အပတ်စဉ်အစီရင်ခံစာ
                        </p>
                    </div>
                </div>

                <div class="text-right sm:text-right">
                    <div class="inline-block bg-slate-100 border border-slate-200 rounded-lg px-3 py-1 text-[11px] font-mono font-bold text-slate-700 mb-1">
                        DOC REF: <?= $doc_ref_id ?>
                    </div>
                    <p class="text-[11px] text-slate-400 font-medium">Issue Date: <?= date('d M Y') ?></p>
                </div>
            </div>
        </header>

        <!-- ── SECTION 1: FORMAL METADATA BOX ── -->
        <section class="bg-slate-50/80 border border-slate-200 rounded-xl p-4 sm:p-5 mb-6 text-xs avoid-break">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-y-3 gap-x-6">

                <!-- Col 1: Student Information -->
                <div class="space-y-1.5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 border-b border-slate-200 pb-1">
                        1. Student Details / ကျောင်းသားအချက်အလက်
                    </p>
                    <div class="flex justify-between"><span class="text-slate-500">Name:</span> <strong class="text-slate-800"><?= htmlspecialchars($student_name) ?></strong></div>
                    <div class="flex justify-between"><span class="text-slate-500">Roll No:</span> <strong class="text-slate-800 font-mono"><?= htmlspecialchars($student_roll) ?></strong></div>
                    <div class="flex justify-between"><span class="text-slate-500">Email:</span> <span class="text-slate-700 font-mono truncate max-w-[150px]"><?= htmlspecialchars($student_email) ?></span></div>
                </div>

                <!-- Col 2: Company & Instructors -->
                <div class="space-y-1.5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 border-b border-slate-200 pb-1">
                        2. Supervision / ကြီးကြပ်မှု
                    </p>
                    <div class="flex justify-between"><span class="text-slate-500">Company Instructor:</span> <strong class="text-slate-800"><?= htmlspecialchars($instructor_name) ?></strong></div>
                    <div class="flex justify-between"><span class="text-slate-500">University Supervisor:</span> <strong class="text-slate-800"><?= htmlspecialchars($supervisor_name) ?></strong></div>
                    <div class="flex justify-between"><span class="text-slate-500">Instructor Contact:</span> <span class="text-slate-700 font-mono truncate max-w-[140px]"><?= htmlspecialchars($instructor_email ?: '—') ?></span></div>
                </div>

                <!-- Col 3: Internship & Report Period -->
                <div class="space-y-1.5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 border-b border-slate-200 pb-1">
                        3. Period & Status / ကာလနှင့် အခြေအနေ
                    </p>
                    <div class="flex justify-between"><span class="text-slate-500">Report Week:</span> <strong class="text-indigo-900 font-bold"><?= $is_all_weeks ? 'All Weeks (Complete History)' : 'Week ' . $selected_week ?></strong></div>
                    <div class="flex justify-between"><span class="text-slate-500">Week Duration:</span> <strong class="text-slate-800"><?= $is_all_weeks ? ($intern_start ? (new DateTime($intern_start))->format('d M Y') . ' – ' . (new DateTime($intern_end))->format('d M Y') : '—') : ($week_start ? (new DateTime($week_start))->format('d M') . ' – ' . (new DateTime($week_end))->format('d M Y') : '—') ?></strong></div>
                    <div class="flex justify-between"><span class="text-slate-500">Total Internship:</span> <span class="text-slate-700"><?= $intern_start ? (new DateTime($intern_start))->format('d M Y') . ' – ' . (new DateTime($intern_end))->format('d M Y') : '—' ?></span></div>
                </div>

            </div>
        </section>

        <!-- ── SECTION 2: WEEKLY PERFORMANCE SUMMARY ── -->
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6 text-center avoid-break">
            <div class="border border-slate-200 rounded-xl p-3 bg-white">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Logs</p>
                <p class="text-base font-black text-slate-800 mt-0.5"><?= $total_logged_days ?> Days</p>
            </div>
            <div class="border border-slate-200 rounded-xl p-3 bg-white">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Attendance</p>
                <p class="text-base font-black text-emerald-600 mt-0.5"><?= $present_days ?> Present <span class="text-xs font-normal text-slate-400">/ <?= $absent_days ?> Absent</span></p>
            </div>
            <div class="border border-slate-200 rounded-xl p-3 bg-white">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Hours Worked</p>
                <p class="text-base font-black text-slate-800 mt-0.5"><?= $total_hours_formatted ?></p>
            </div>
            <div class="border border-slate-200 rounded-xl p-3 bg-white">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Report Status</p>
                <p class="text-xs font-black uppercase mt-1.5 <?= ($evaluation && ($evaluation['report_status'] ?? '') === 'approved') ? 'text-emerald-700' : 'text-slate-700' ?>">
                    <?= $evaluation ? htmlspecialchars(strtoupper($evaluation['report_status'] ?? 'SUBMITTED')) : 'IN PROGRESS' ?>
                </p>
            </div>
        </section>

        <!-- ── SECTION 3: DAILY WORK LOGS SHEET TABLE ── -->
        <section class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-xs font-black uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                    <span>📅</span> Daily Work Logs / နေ့စဉ်လုပ်ငန်းမှတ်တမ်းများ
                </h2>
                <span class="text-[10px] font-bold text-slate-400"><?= $is_all_weeks ? 'All Weeks' : 'Week ' . $selected_week ?></span>
            </div>

            <div class="overflow-x-auto border border-slate-300 rounded-xl">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-slate-700 font-bold uppercase tracking-wider text-[10px] border-b border-slate-300">
                            <th class="py-2.5 px-3 border-r border-slate-200 w-24">Date / Day</th>
                            <th class="py-2.5 px-2 border-r border-slate-200 w-20 text-center">Status</th>
                            <th class="py-2.5 px-3 border-r border-slate-200 w-1/4">Intended Task</th>
                            <th class="py-2.5 px-3 border-r border-slate-200 w-1/3">Actual Tasks Performed</th>
                            <th class="py-2.5 px-2 border-r border-slate-200 w-24">Tools / Tech</th>
                            <th class="py-2.5 px-2 border-r border-slate-200">Knowledge Gained</th>
                            <th class="py-2.5 px-2 w-14 text-center">Hours</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        <?php if (empty($daily_logs)): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-slate-400 italic">No daily logs recorded for this period.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daily_logs as $log): ?>
                                <?php
                                $dObj = new DateTime($log['log_date']);
                                $is_present = ($log['attendance_status'] ?? 'present') === 'present';
                                ?>
                                <tr class="avoid-break <?= !$is_present ? 'bg-amber-50/30' : '' ?>">
                                    <td class="py-2.5 px-3 border-r border-slate-200 font-medium">
                                        <div class="font-bold text-slate-900"><?= $dObj->format('d.m.Y') ?></div>
                                        <div class="text-[10px] text-slate-400"><?= $dObj->format('l') ?></div>
                                    </td>
                                    <td class="py-2.5 px-2 border-r border-slate-200 text-center">
                                        <?php
                                        $att = $log['attendance_status'] ?? 'present';
                                        $reason = $log['reason_for_absence'] ?? '';
                                        $is_holiday = ($att === 'leave' || $att === 'absent') && stripos($reason, 'Public Holiday') === 0;
                                        ?>
                                        <?php if ($is_holiday): ?>
                                            <span class="inline-block px-2 py-0.5 bg-amber-100 text-amber-800 text-[10px] font-bold rounded">Public Holiday</span>
                                        <?php elseif ($is_present): ?>
                                            <span class="inline-block px-2 py-0.5 bg-emerald-100 text-emerald-800 text-[10px] font-bold rounded">Present</span>
                                        <?php else: ?>
                                            <span class="inline-block px-2 py-0.5 bg-rose-100 text-rose-800 text-[10px] font-bold rounded">Absent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 border-r border-slate-200 font-medium">
                                        <?php if ($is_holiday): ?>
                                            <span class="text-amber-700 font-medium"><?= htmlspecialchars($reason) ?></span>
                                        <?php elseif ($is_present): ?>
                                            <?= htmlspecialchars($log['task_title'] ?: ($log['intended_task'] ?? '—')) ?>
                                        <?php else: ?>
                                            <span class="text-rose-600 italic">Reason: <?= htmlspecialchars($reason ?: 'Absent') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 border-r border-slate-200 leading-relaxed">
                                        <?php if ($is_present && !$is_holiday): ?>
                                            <?= nl2br(htmlspecialchars($log['tasks_performed'] ?: ($log['actual_tasks'] ?? ($log['task_detail'] ?? ($log['actual_task'] ?? ($log['task_description'] ?? '—')))))) ?>
                                        <?php else: ?>
                                            <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-2 border-r border-slate-200 font-mono text-[11px] text-slate-600">
                                        <?= htmlspecialchars($log['tools_used'] ?? '—') ?>
                                    </td>
                                    <td class="py-2.5 px-2 border-r border-slate-200 text-slate-600">
                                        <?= htmlspecialchars($log['learnt_skills'] ?? '—') ?>
                                    </td>
                                    <td class="py-2.5 px-2 text-center font-bold text-slate-800 font-mono text-[11px]">
                                        <?= htmlspecialchars($log['calculated_duration'] ?? $log['hours_worked'] ?? '—') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ── SECTION 4: WEEKLY REFLECTION & EVALUATION (AVOID BREAK) ── -->
        <section class="space-y-4 mb-6 avoid-break">
            <?php if ($is_all_weeks && !empty($all_weekly_reflections)): ?>
                <div class="space-y-3">
                    <?php foreach ($all_weekly_reflections as $ref): ?>
                        <div class="border border-slate-300 rounded-xl p-4 bg-slate-50/50">
                            <h2 class="text-xs font-black uppercase tracking-wider text-slate-800 mb-2.5 flex items-center gap-1.5">
                                <span>💡</span> Week <?= (int)$ref['week_number'] ?> Weekly Reflection / အပတ်စဉ် သုံးသပ်ချက်
                            </h2>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">1. What was done? / ဘာလုပ်သလဲ</p>
                                    <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($ref['what_done'] ?? '—')) ?></p>
                                </div>
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">2. How was it done? / ဘယ်လိုလုပ်ပါသလဲ</p>
                                    <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($ref['how_done'] ?? '—')) ?></p>
                                </div>
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">3. Why was it done? / ဘာကြောင့်လုပ်ပါသလဲ</p>
                                    <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($ref['why_done'] ?? '—')) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="border border-slate-300 rounded-xl p-4 bg-slate-50/50">
                    <h2 class="text-xs font-black uppercase tracking-wider text-slate-800 mb-3 flex items-center gap-1.5">
                        <span>💡</span> <?= $is_all_weeks ? 'Weekly Reflections / အပတ်စဉ် သုံးသပ်ချက်များ' : 'Weekly Reflection / အပတ်စဉ် သုံးသပ်ချက်' ?>
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                        <div class="bg-white border border-slate-200 rounded-lg p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">1. What was done? / ဘာလုပ်သလဲ</p>
                            <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($weekly_reflection['what_done'] ?? 'Not submitted yet')) ?></p>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-lg p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">2. How was it done? / ဘယ်လိုလုပ်ပါသလဲ</p>
                            <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($weekly_reflection['how_done'] ?? 'Not submitted yet')) ?></p>
                        </div>
                        <div class="bg-white border border-slate-200 rounded-lg p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">3. Why was it done? / ဘာကြောင့်လုပ်ပါသလဲ</p>
                            <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($weekly_reflection['why_done'] ?? 'Not submitted yet')) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Evaluations & Feedback Section (Company Instructor & University Supervisor) -->
            <?php
            $has_instructor_feedback = $evaluation && (!empty($evaluation['comment']) || !empty($evaluation['grade']) || !empty($evaluation['instructor_comments']));
            $has_supervisor_feedback = $supervisor_evaluation && (!empty($supervisor_evaluation['supervisor_comments']) || !empty($supervisor_evaluation['weekly_grade']));
            ?>
            <?php if ($has_instructor_feedback || $has_supervisor_feedback): ?>
                <div class="grid grid-cols-1 <?= ($has_instructor_feedback && $has_supervisor_feedback) ? 'md:grid-cols-2' : '' ?> gap-4">
                    <!-- Company Instructor Feedback & Grade -->
                    <?php if ($has_instructor_feedback): ?>
                        <div class="border border-slate-300 rounded-xl p-4 bg-white flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between mb-2 pb-1.5 border-b border-slate-100">
                                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                                        <span>🏢</span> Company Instructor Evaluation
                                    </h3>
                                    <?php if (!empty($evaluation['grade'])): ?>
                                        <span class="px-2 py-0.5 rounded text-[11px] font-black bg-indigo-50 text-indigo-700 border border-indigo-200">Grade: <?= htmlspecialchars(strtoupper($evaluation['grade'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($evaluation['comment'] ?: 'No written comments provided.')) ?></p>
                                <?php if (!empty($evaluation['instructor_comments'])): ?>
                                    <div class="mt-2 p-2 bg-rose-50 border border-rose-100 rounded-lg text-xs text-rose-700">
                                        <strong>Revision Note:</strong> <?= nl2br(htmlspecialchars($evaluation['instructor_comments'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($evaluation['evaluated_at'])): ?>
                                <p class="text-[10px] text-slate-400 mt-2.5 pt-1.5 border-t border-slate-100">Evaluated on: <?= (new DateTime($evaluation['evaluated_at']))->format('d M Y, h:i A') ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- University Supervisor Review & Reflection -->
                    <?php if ($has_supervisor_feedback): ?>
                        <div class="border border-slate-300 rounded-xl p-4 bg-white flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between mb-2 pb-1.5 border-b border-slate-100">
                                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                                        <span>🎓</span> University Supervisor Reflection / Review
                                    </h3>
                                    <?php if (!empty($supervisor_evaluation['weekly_grade'])): ?>
                                        <span class="px-2 py-0.5 rounded text-[11px] font-black bg-teal-50 text-teal-700 border border-teal-200">Grade: <?= htmlspecialchars(strtoupper($supervisor_evaluation['weekly_grade'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($supervisor_evaluation['supervisor_comments'] ?: 'No written comments provided.')) ?></p>
                            </div>
                            <?php if (!empty($supervisor_evaluation['evaluated_at'])): ?>
                                <p class="text-[10px] text-slate-400 mt-2.5 pt-1.5 border-t border-slate-100">Reviewed on: <?= (new DateTime($supervisor_evaluation['evaluated_at']))->format('d M Y, h:i A') ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── SECTION 5: FORMAL 3-COLUMN SIGNATURE BLOCKS ── -->
        <section class="border-t-2 border-slate-800 pt-6 mt-8 avoid-break">
            <h2 class="text-xs font-black uppercase tracking-wider text-slate-800 mb-4 text-center">
                Signatures & Formal Approvals / လက်မှတ်ရေးထိုး အတည်ပြုချက်များ
            </h2>

            <div class="grid grid-cols-3 gap-6 text-center text-xs">

                <!-- (A) Student Signature -->
                <div class="border border-slate-300 rounded-xl p-3 bg-slate-50/40 flex flex-col justify-between h-36">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Trainee / ကျောင်းသားလက်မှတ်</p>
                    <div class="my-auto flex items-center justify-center min-h-[48px]">
                        <?php if ($evaluation && !empty($evaluation['student_signature_value'])): ?>
                            <?php if (($evaluation['student_signature_type'] ?? '') === 'typed'): ?>
                                <span class="sig-font-great text-2xl text-slate-800"><?= htmlspecialchars($evaluation['student_signature_value']) ?></span>
                            <?php else: ?>
                                <img src="../uploads/signatures/<?= htmlspecialchars($evaluation['student_signature_value']) ?>" alt="Signature" class="max-h-12 max-w-[120px] object-contain mx-auto">
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-slate-300 border-b border-dashed border-slate-400 w-32 inline-block"></span>
                        <?php endif; ?>
                    </div>
                    <div class="border-t border-slate-200 pt-1.5">
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($student_name) ?></p>
                        <p class="text-[10px] text-slate-400">Date: <?= ($weekly_reflection && !empty($weekly_reflection['created_at'])) ? (new DateTime($weekly_reflection['created_at']))->format('d.m.Y') : '___/___/2026' ?></p>
                    </div>
                </div>

                <!-- (B) Company Instructor Signature -->
                <div class="border border-slate-300 rounded-xl p-3 bg-slate-50/40 flex flex-col justify-between h-36">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Company Instructor / ကြီးကြပ်သူ</p>
                    <div class="my-auto flex items-center justify-center min-h-[48px]">
                        <?php if ($evaluation && !empty($evaluation['signature_value'])): ?>
                            <?php if (($evaluation['signature_type'] ?? '') === 'typed'): ?>
                                <span class="sig-font-great text-2xl text-slate-800"><?= htmlspecialchars($evaluation['signature_value']) ?></span>
                            <?php else: ?>
                                <img src="../uploads/signatures/<?= htmlspecialchars($evaluation['signature_value']) ?>" alt="Signature" class="max-h-12 max-w-[120px] object-contain mx-auto">
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-slate-300 border-b border-dashed border-slate-400 w-32 inline-block"></span>
                        <?php endif; ?>
                    </div>
                    <div class="border-t border-slate-200 pt-1.5">
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($instructor_name) ?></p>
                        <p class="text-[10px] text-slate-400">Date: <?= ($evaluation && !empty($evaluation['evaluated_at'])) ? (new DateTime($evaluation['evaluated_at']))->format('d.m.Y') : '___/___/2026' ?></p>
                    </div>
                </div>

                <!-- (C) University Supervisor Signature -->
                <div class="border border-slate-300 rounded-xl p-3 bg-slate-50/40 flex flex-col justify-between h-36">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">University Supervisor / ကြီးကြပ်ဆရာ</p>
                    <div class="my-auto flex items-center justify-center min-h-[48px]">
                        <?php if ($supervisor_evaluation && !empty($supervisor_evaluation['weekly_grade'])): ?>
                            <div class="text-center">
                                <span class="inline-flex items-center gap-1 text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full text-[10px] font-bold">&#10003; Evaluated (Grade <?= htmlspecialchars($supervisor_evaluation['weekly_grade']) ?>)</span>
                            </div>
                        <?php else: ?>
                            <span class="text-slate-300 border-b border-dashed border-slate-400 w-32 inline-block"></span>
                        <?php endif; ?>
                    </div>
                    <div class="border-t border-slate-200 pt-1.5">
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($supervisor_name) ?></p>
                        <p class="text-[10px] text-slate-400">Date: <?= ($supervisor_evaluation && !empty($supervisor_evaluation['evaluated_at'])) ? (new DateTime($supervisor_evaluation['evaluated_at']))->format('d.m.Y') : '___/___/2026' ?></p>
                    </div>
                </div>

            </div>
        </section>

        <!-- ── FOOTER NOTICE ── -->
        <footer class="mt-8 pt-4 border-t border-slate-200 text-center text-[10px] text-slate-400 flex items-center justify-between">
            <span>Confidential & Official Internship Documentation System</span>
            <span>Generated on <?= date('d M Y, h:i A') ?> • Page 1 of 1</span>
        </footer>

    </div>

</body>

</html>