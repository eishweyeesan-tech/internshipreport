<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';
require_once __DIR__ . '/../config/notify.php';

$sup_id = (int) $_SESSION['user_id'];
$student_id = (int) ($_GET['student_id'] ?? 0);
$week_num = (int) ($_GET['week'] ?? 0);
$db = $mysqli ?? $conn;

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($db, $sup_id);

if ($student_id <= 0) {
    header('Location: supervisor-dashboard.php');
    exit;
}

// ── Verify this student belongs to this supervisor ─────────────────
$stu = $db->prepare("
    SELECT u.id, u.username, u.email, u.academic_year, u.created_at,
           sp.full_name, sp.student_roll, sp.major, sp.company_name,
           sp.job_role, sp.phone, sp.instructor_name, sp.instructor_email,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ? AND u.role = 'student' AND sp.supervisor_id = ?
");
$stu->bind_param("ii", $student_id, $sup_id);
$stu->execute();
$res = $stu->get_result();
$student = $res ? $res->fetch_assoc() : null;

if (!$student) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$student_name = $student['full_name'] ?: $student['username'];

// ── Active Year Badge Data ─────────────────────────────────────────
$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ?");
$total_assigned_q->bind_param("i", $sup_id);
$total_assigned_q->execute();
$res = $total_assigned_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);
$selected_year_label = '';

// ── Determine Week Ranges from internship start date ───────────────
$weeks = [];
if ($student['internship_start_date']) {
    $start_dt = new DateTime($student['internship_start_date']);
    $total_w = internship_total_weeks($student['internship_start_date'], $student['internship_end_date'] ?? null);
    // Generate weeks from start date
    for ($i = 1; $i <= $total_w; $i++) {
        $ws = clone $start_dt;
        if ($i > 1) $ws->modify('+' . (($i - 1) * 7) . ' days');
        $we = (clone $ws)->modify('+6 days');
        $weeks[$i] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d')];
    }
} else {
    // Fallback: build from log dates
    $all_dates = $db->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
    $all_dates->bind_param("i", $student_id);
    $all_dates->execute();
    $res = $all_dates->get_result();
    $log_dates = [];
    if ($res) {
        while ($row = $res->fetch_row()) {
            $log_dates[] = $row[0];
        }
    }
    if (!empty($log_dates)) {
        $first = new DateTime($log_dates[0]);
        $last  = new DateTime(end($log_dates));
        $num   = 1;
        $s     = clone $first;
        while ($s <= $last) {
            $e = (clone $s)->modify('+6 days');
            $weeks[$num] = ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d')];
            $s->modify('+7 days');
            $num++;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC CURRENT WEEK AUTO-DETECTION
// ══════════════════════════════════════════════════════════════════════
$auto_week = 1;
$total_weeks = 12;
$not_started = false;

if ($student['internship_start_date']) {
    $today_obj = new DateTime();
    $start_date = $student['internship_start_date'];
    $end_date = !empty($student['internship_end_date']) ? $student['internship_end_date'] : null;
    $total_weeks = internship_total_weeks($start_date, $end_date);
    $auto_week = internship_current_week($start_date, $end_date, $today_obj);

    if ($today_obj < new DateTime($start_date)) {
        $not_started = true;
    }
} else {
    // No start date configured → treat as not started
    $not_started = true;
}

// Smart week auto-resolution if no valid week is specified in URL
if ($week_num <= 0 || !isset($weeks[$week_num])) {
    // 1. Check for earliest ready week waiting for supervisor review
    $ready_w_q = $db->prepare("SELECT MIN(week_number) FROM report_evaluations WHERE student_id = ? AND report_status = 'approved_by_instructor'");
    $ready_w_q->bind_param("i", $student_id);
    $ready_w_q->execute();
    $rw_res = $ready_w_q->get_result();
    $rw_row = $rw_res ? $rw_res->fetch_row() : null;
    $earliest_ready_w = (int)($rw_row[0] ?? 0);

    if ($earliest_ready_w > 0 && isset($weeks[$earliest_ready_w])) {
        $week_num = $earliest_ready_w;
    } else {
        // 2. Check latest submitted/evaluated week
        $sub_w_q = $db->prepare("SELECT MAX(week_number) FROM report_evaluations WHERE student_id = ?");
        $sub_w_q->bind_param("i", $student_id);
        $sub_w_q->execute();
        $sw_res = $sub_w_q->get_result();
        $sw_row = $sw_res ? $sw_res->fetch_row() : null;
        $latest_sub_w = (int)($sw_row[0] ?? 0);

        if ($latest_sub_w > 0 && isset($weeks[$latest_sub_w])) {
            $week_num = $latest_sub_w;
        } else {
            // 3. Fallback to dynamic calendar current week
            $week_num = ($auto_week > 0 && isset($weeks[$auto_week])) ? $auto_week : 1;
        }
    }
}

$week_start = $weeks[$week_num]['start'] ?? '';
$week_end   = $weeks[$week_num]['end'] ?? '';

// Format date range for display (Mon–Fri = first 5 days of the week)
$week_date_range = '';
if ($week_start) {
    $ws_obj = new DateTime($week_start);
    $we_obj = (clone $ws_obj)->modify('+4 days'); // Friday
    $week_date_range = $ws_obj->format('d M Y') . ' to ' . $we_obj->format('d M Y');
}

// ── Lifetime Attendance ────────────────────────────────────────────
$present_q = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present'");
$present_q->bind_param("i", $student_id);
$present_q->execute();
$res = $present_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_present = (int) ($row[0] ?? 0);

$absent_q = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave')");
$absent_q->bind_param("i", $student_id);
$absent_q->execute();
$res = $absent_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_absent = (int) ($row[0] ?? 0);

$total_logs = $total_present + $total_absent;

// ── Overall Attendance Rate (75% Threshold Target) ───────────────
$intern_start = $student['internship_start_date'] ?? null;
$intern_end   = !empty($student['internship_end_date']) ? $student['internship_end_date'] : null;
$total_expected_days = 0;
if ($intern_start && $intern_end) {
    $att_cursor = new DateTime($intern_start);
    $att_end_dt = new DateTime($intern_end);
    while ($att_cursor <= $att_end_dt) {
        if ((int) $att_cursor->format('N') <= 5) $total_expected_days++;
        $att_cursor->modify('+1 day');
    }
}
if ($total_expected_days < 1) {
    $total_expected_days = max(1, $total_weeks * 5);
}
$overall_attendance_rate = round(($total_present / max(1, $total_expected_days)) * 100);

// ── Attendance Details for Tooltips ────────────────────────────────
$pd_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present' ORDER BY log_date ASC");
$pd_stmt->bind_param("i", $student_id);
$pd_stmt->execute();
$res = $pd_stmt->get_result();
$present_dates = [];
if ($res) {
    while ($row = $res->fetch_row()) {
        $present_dates[] = $row[0];
    }
}

$ad_stmt = $db->prepare("SELECT log_date, reason_for_absence FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
$ad_stmt->bind_param("i", $student_id);
$ad_stmt->execute();
$res = $ad_stmt->get_result();
$absent_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ══════════════════════════════════════════════════════════════════════
// WEEKLY GRADING HISTORY (used for current-week grade prefill + GPA card)
// ══════════════════════════════════════════════════════════════════════
$all_weeks_grades = [];
$gq = $db->prepare("SELECT weekly_grade, supervisor_comments, evaluated_at FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
for ($i = 1; $i <= $total_weeks; $i++) {
    $gq->bind_param("ii", $student_id, $i);
    $gq->execute();
    $res = $gq->get_result();
    $all_weeks_grades[$i] = $res ? $res->fetch_assoc() : null;
}

// ── Fetch Data for Active Week ─────────────────────────────────────
$daily_logs = [];
$reflection = null;
$instructor_eval = null;
$supervisor_eval = $all_weeks_grades[$week_num] ?? null;

if ($week_start && $week_end) {
    $dl = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC");
    $dl->bind_param("iss", $student_id, $week_start, $week_end);
    $dl->execute();
    $res = $dl->get_result();
    $daily_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    $rf = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
    $rf->bind_param("ii", $student_id, $week_num);
    $rf->execute();
    $res = $rf->get_result();
    $reflection = $res ? $res->fetch_assoc() : null;

    $ie = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $ie->bind_param("ii", $student_id, $week_num);
    $ie->execute();
    $res = $ie->get_result();
    $instructor_eval = $res ? $res->fetch_assoc() : null;
}

// ── Week Attendance & Logs (summary cards) ─────────────────────────
// Shared with view-student-dashboard.php via config/internship_progress.php
$week_att = ($week_start && $week_end)
    ? internship_attendance($db, $student_id, $week_start, $week_end)
    : ['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => 0];
$week_present         = $week_att['present'];
$week_expected        = $week_att['expected'];
$week_attendance_rate = $week_att['rate'];
$week_log_days        = $week_att['expected'];

// ── Handle Supervisor Evaluation Submission (Method 1) ─────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sup_eval'])) {
    $grade    = $_POST['weekly_grade'] ?? '';
    $comments = trim($_POST['supervisor_comments'] ?? '');
    $allowed  = ['A', 'B', 'C', 'D', 'F'];

    if (!in_array($grade, $allowed, true)) {
        $msg = 'invalid_grade';
    } else {
        $upsert = $db->prepare("
            INSERT INTO supervisor_weekly_evaluations (student_id, week_number, supervisor_id, weekly_grade, supervisor_comments)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            weekly_grade = VALUES(weekly_grade),
            supervisor_comments = VALUES(supervisor_comments),
            evaluated_at = NOW()
        ");
        $upsert->bind_param("iiiss", $student_id, $week_num, $sup_id, $grade, $comments);
        $upsert->execute();

        // Update the main report status to approved_by_supervisor
        $update_status = $db->prepare("
            UPDATE report_evaluations SET report_status = 'approved_by_supervisor'
            WHERE student_id = ? AND week_number = ?
        ");
        $update_status->bind_param("ii", $student_id, $week_num);
        $update_status->execute();

        // Notify student that report has been graded
        notify_user_once(
            $db,
            $student_id,
            "Week {$week_num} Report Graded",
            "Your university supervisor evaluated and graded your Week {$week_num} report with '{$grade}'.",
            'supervisor_approved',
            $week_num,
            $student_id,
            null
        );

        // Re-fetch current week evaluation
        $gq = $db->prepare("SELECT weekly_grade, supervisor_comments, evaluated_at FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
        $gq->bind_param("ii", $student_id, $week_num);
        $gq->execute();
        $res = $gq->get_result();
        $supervisor_eval = $res ? $res->fetch_assoc() : null;

        // Update history cache
        $all_weeks_grades[$week_num] = $supervisor_eval;

        $msg = 'saved';
    }
}

// Grade labels for instructor evaluation
$grade_labels = [
    'excellent'         => ['Excellent',          'text-emerald-600', 'bg-emerald-50'],
    'good'              => ['Good',               'text-blue-600',    'bg-blue-50'],
    'average'           => ['Average',            'text-amber-600',   'bg-amber-50'],
    'needs_improvement' => ['Needs Improvement',  'text-red-600',     'bg-red-50'],
];

// ── Weekly Grades Count (summary card) ─────────────────────────────
// Counts only weekly reports that have an actual supervisor grade
$student_graded = 0;
foreach ($all_weeks_grades as $wg) {
    if ($wg && isset($wg['weekly_grade'])) {
        $student_graded++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review – <?= htmlspecialchars($student_name) ?> – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
        .scroll-margin {
            scroll-margin-top: 88px;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 15mm;
            }

            body {
                background: #ffffff !important;
                color: #0f172a !important;
                font-size: 10.5pt !important;
                line-height: 1.4 !important;
            }

            aside,
            header,
            #sidebarBackdrop,
            #supervisorSidebarBackdrop,
            .print\:hidden,
            button,
            form button,
            .no-print {
                display: none !important;
            }

            .flex.h-screen,
            .h-screen {
                height: auto !important;
                overflow: visible !important;
                display: block !important;
            }

            main {
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }

            .max-w-7xl,
            .max-w-4xl,
            .max-w-5xl {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .bg-white,
            .bg-slate-50,
            .bg-teal-50,
            .bg-indigo-50,
            .bg-emerald-50 {
                background: #ffffff !important;
            }

            .border,
            .border-slate-200,
            .border-slate-100,
            .border-teal-100 {
                border-color: #cbd5e1 !important;
            }

            .shadow-sm,
            .shadow-md,
            .shadow-lg,
            .shadow-xl,
            .shadow-2xl {
                box-shadow: none !important;
            }

            .print-card-avoid {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }

            table {
                width: 100% !important;
                border-collapse: collapse !important;
            }

            th,
            td {
                border: 1px solid #cbd5e1 !important;
                padding: 6px 8px !important;
            }
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                    },
                    fontSize: {
                        'micro': '0.5rem',
                        'caption': '0.6875rem',
                        'label': '0.8125rem',
                        'subtitle': '0.9375rem',
                        'body': '1rem',
                    },
                }
            }
        }
    </script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
    <script>
        function toggleWeekDropdown(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
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
    </script>
</head>

<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR ─── -->
        <?php $active_page = 'reports';
        include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

        <!-- ─── MAIN ─── -->
        <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

            <!-- Top Bar -->
            <?php $pageTitle = '📝 Weekly Report Evaluation';
            include __DIR__ . '/includes/supervisor_topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl w-full mx-auto space-y-6">

                    <!-- Back to reports navigation link -->
                    <div>
                        <a href="supervisor-reports.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition" title="Back to Reports">
                            ← Back to Reports
                        </a>
                    </div>

                    <!-- ════ FLASH MESSAGE ════ -->
                    <?php if ($msg === 'saved'): ?>
                        <div class="bg-gradient-to-r from-emerald-50 to-emerald-100/50 border border-emerald-200/60 text-emerald-700 text-sm font-semibold px-5 py-4 rounded-2xl flex items-center gap-3 shadow-sm">
                            <div class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-sm shadow-lg shadow-emerald-500/30">✅</div>
                            <span>Weekly grade saved successfully for <strong>Week <?= $week_num ?></strong>.</span>
                        </div>
                    <?php elseif ($msg === 'invalid_grade'): ?>
                        <div class="bg-gradient-to-r from-red-50 to-red-100/50 border border-red-200/60 text-red-700 text-sm font-semibold px-5 py-4 rounded-2xl flex items-center gap-3 shadow-sm">
                            <div class="w-8 h-8 rounded-xl bg-red-500 text-white flex items-center justify-center text-sm shadow-lg shadow-red-500/30">❌</div>
                            <span>Invalid grade selected. Please choose A, B, C, D, or F.</span>
                        </div>
                    <?php endif; ?>

                    <!-- ════ STUDENT SUMMARY ════ -->
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
                        <div class="flex items-start justify-between flex-wrap gap-5">
                            <div class="flex items-center gap-5">
                                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-600 to-cyan-700 text-white flex items-center justify-center text-2xl font-bold shrink-0 shadow-md">
                                    <?= strtoupper($student_name[0]) ?>
                                </div>
                                <div>
                                    <h1 class="text-lg font-bold text-slate-800"><?= htmlspecialchars($student_name) ?></h1>
                                    <?php if ($not_started): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200 ml-2">
                                            <span class="w-2 h-2 rounded-full bg-slate-400"></span> NOT STARTED
                                        </span>
                                    <?php endif; ?>
                                    <p class="text-sm text-slate-400 font-mono mt-1">Roll: <?= htmlspecialchars($student['student_roll'] ?: '—') ?> · <?= htmlspecialchars($student['email']) ?></p>
                                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                                        <?php if ($student['major']): ?>
                                            <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-3 py-1 rounded-lg"><?= htmlspecialchars($student['major']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($student['company_name']): ?>
                                            <span class="text-xs font-semibold text-teal-700 bg-teal-50 px-3 py-1 rounded-lg border border-teal-200/60">🏢 <?= htmlspecialchars($student['company_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($student['phone']): ?>
                                            <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-3 py-1 rounded-lg">📱 <?= htmlspecialchars($student['phone']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($student['instructor_email']): ?>
                                            <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-3 py-1 rounded-lg border border-amber-200/60">✉️ <?= htmlspecialchars($student['instructor_email']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($student['academic_year']): ?>
                                            <span class="text-xs font-semibold text-teal-800 bg-teal-50 px-3 py-1 rounded-lg font-mono border border-teal-200/60"><?= htmlspecialchars($student['academic_year']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($student['internship_start_date'] || $student['internship_end_date']): ?>
                                        <div class="flex items-center gap-2 mt-1.5">
                                            <span class="text-xs font-semibold text-slate-500 bg-slate-50 px-3 py-1 rounded-lg border border-slate-200/60">
                                                📅 <?= $student['internship_start_date'] ? (new DateTime($student['internship_start_date']))->format('d M Y') : '—' ?> – <?= $student['internship_end_date'] ? (new DateTime($student['internship_end_date']))->format('d M Y') : '—' ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap shrink-0 print:hidden">
                                <a href="../view_student_history.php?uid=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition shadow-xs" title="View 13-week complete history">
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>History</span>
                                </a>
                                <a href="../student/print_report.php?student_id=<?= $student_id ?>&week=<?= $week_num ?>" target="_blank" class="inline-flex items-center gap-2 px-3.5 py-2 bg-[#005f73] hover:bg-[#0a9396] text-white text-xs font-bold rounded-xl shadow-md shadow-teal-700/20 transition-all duration-200 cursor-pointer" title="Save Official Internship Report as PDF">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span>Save as PDF</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ════ FILTER & TRACKER ROW ════ -->
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <!-- Left: Week Dropdown + Date Range -->
                            <div class="flex items-center gap-3 flex-wrap">
                                <!-- Week Selector Dropdown -->
                                <div class="relative" id="week-dropdown">
                                    <button onclick="toggleWeekDropdown(event)" class="flex items-center gap-2 bg-gradient-to-r from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-2 text-sm font-semibold text-slate-700 hover:border-indigo-300 transition-all duration-200 cursor-pointer whitespace-nowrap shadow-sm">
                                        📆 Week <?= $week_num ?>
                                        <span class="text-slate-400 text-sm">▾</span>
                                    </button>
                                    <div id="week-menu" class="absolute left-0 top-full mt-2 w-52 bg-white border border-slate-200/60 rounded-xl shadow-xl z-50 hidden overflow-hidden">
                                        <?php if (!empty($weeks)): ?>
                                            <?php foreach ($weeks as $wn => $wr): ?>
                                                <a href="?student_id=<?= $student_id ?>&week=<?= $wn ?>" class="flex items-center justify-between px-4 py-2.5 text-sm font-semibold <?= $wn === $week_num ? 'bg-gradient-to-r from-indigo-500 to-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50' ?> transition">
                                                    Week <?= $wn ?><?= $wn === $auto_week ? ' ✓' : '' ?>
                                                    <span class="text-sm <?= $wn === $week_num ? 'text-indigo-200' : 'text-slate-400' ?>"><?= $wr['start'] ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="px-4 py-3 text-sm text-slate-400">No weeks configured</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Date Range Display -->
                                <?php if ($week_date_range): ?>
                                    <span class="flex items-center gap-1.5 text-xs font-bold text-blue-600 bg-blue-50 border border-blue-200/60 px-3 py-1.5 rounded-lg">
                                        📅 <?= $week_date_range ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Right: Attendance Counters with Tooltips -->
                            <div class="flex items-center gap-3 flex-wrap">
                                <!-- Attendance Rate (75% Target) -->
                                <div class="flex items-center gap-2 px-3.5 py-2 <?= $overall_attendance_rate >= 75 ? 'bg-emerald-50/80 border-emerald-200/80' : 'bg-amber-50/80 border-amber-200/80' ?> border rounded-xl shadow-xs">
                                    <div class="w-8 h-8 rounded-lg <?= $overall_attendance_rate >= 75 ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600' ?> flex items-center justify-center text-xs font-bold shrink-0">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-base font-black <?= $overall_attendance_rate >= 75 ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $overall_attendance_rate ?>%</span>
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border <?= $overall_attendance_rate >= 75 ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-amber-100 text-amber-700 border-amber-200' ?>">
                                                <?= $overall_attendance_rate >= 75 ? 'Good' : 'Needs Attention' ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] text-slate-500 font-medium"><?= $total_present ?> / <?= $total_expected_days ?> days attended</p>
                                    </div>
                                </div>

                                <!-- Present Tooltip -->
                                <div class="relative group">
                                    <div class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-emerald-50 to-emerald-100/50 border border-emerald-200/60 rounded-xl cursor-pointer hover:shadow-md transition-all duration-200">
                                        <span class="text-xs font-bold text-emerald-600">✅ Present</span>
                                        <span class="text-lg font-black text-emerald-700"><?= $total_present ?></span>
                                    </div>
                                    <div class="absolute right-0 top-full mt-2 w-64 bg-white border border-slate-200/60 rounded-xl shadow-xl z-50 hidden group-hover:block">
                                        <div class="p-4">
                                            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-3">All Present Dates</p>
                                            <div class="max-h-48 overflow-y-auto space-y-1.5 pr-1">
                                                <?php if (!empty($present_dates)): ?>
                                                    <?php foreach ($present_dates as $date): ?>
                                                        <?php $d = new DateTime($date); ?>
                                                        <p class="text-xs text-slate-700">• <?= $d->format('D, M d, Y') ?></p>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-xs text-slate-400">No present days recorded.</p>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm text-slate-400 mt-3 pt-3 border-t border-slate-100">Total: <?= count($present_dates) ?> day<?= count($present_dates) !== 1 ? 's' : '' ?></p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Absent Tooltip -->
                                <div class="relative group">
                                    <div class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-50 to-red-100/50 border border-red-200/60 rounded-xl cursor-pointer hover:shadow-md transition-all duration-200">
                                        <span class="text-xs font-bold text-red-600">❌ Absent</span>
                                        <span class="text-lg font-black text-red-700"><?= $total_absent ?></span>
                                    </div>
                                    <div class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200/60 rounded-xl shadow-xl z-50 hidden group-hover:block">
                                        <div class="p-4">
                                            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-3">All Absent Dates</p>
                                            <div class="max-h-48 overflow-y-auto space-y-1.5 pr-1">
                                                <?php if (!empty($absent_logs)): ?>
                                                    <?php foreach ($absent_logs as $log): ?>
                                                        <?php $d = new DateTime($log['log_date']); ?>
                                                        <p class="text-xs text-slate-700">• <?= $d->format('D, M d, Y') ?> — <?= htmlspecialchars($log['reason_for_absence'] ?: 'No reason') ?></p>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-xs text-slate-400">No absences recorded.</p>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm text-slate-400 mt-3 pt-3 border-t border-slate-100">Total: <?= count($absent_logs) ?> day<?= count($absent_logs) !== 1 ? 's' : '' ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ════ CONTENT LAYOUT ════ -->
                    <div class="space-y-6">

                        <!-- ─── Daily Logs + Reflection ─── -->
                        <div class="space-y-6">

                            <!-- Daily Logs -->
                            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                        <span class="w-8 h-8 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center text-sm">📝</span> Daily Logs – Week <?= $week_num ?>
                                    </h2>
                                    <span class="text-xs text-slate-400 font-medium"><?= $week_log_days ?> day(s)</span>
                                </div>
                                <?php if (!empty($daily_logs)): ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="bg-slate-50/90 text-slate-500 font-bold uppercase text-xs border-b border-slate-100">
                                                    <th class="px-4 py-3 text-left whitespace-nowrap">ရက်စွဲ / နေ့</th>
                                                    <th class="px-4 py-3 text-left whitespace-nowrap">တက်ရောက်မှုအခြေအနေ</th>
                                                    <th class="px-4 py-3 text-left">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                                                    <th class="px-4 py-3 text-left">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                                                    <th class="px-4 py-3 text-left">အသုံးပြုသောပစ္စည်းများ</th>
                                                    <th class="px-4 py-3 text-left">လေ့လာသိရှိသော အသိပညာ</th>
                                                    <th class="px-4 py-3 text-left whitespace-nowrap">ကြာချိန်</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                <?php foreach ($daily_logs as $log): ?>
                                                    <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                                        <td class="px-4 py-3.5 font-medium text-slate-700 whitespace-nowrap text-xs sm:text-sm">
                                                            <?= (new DateTime($log['log_date']))->format('D, d M Y') ?>
                                                        </td>
                                                        <td class="px-4 py-3.5 whitespace-nowrap">
                                                            <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                                                                <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg border border-emerald-200/60">Present</span>
                                                            <?php else: ?>
                                                                <span class="inline-flex items-center gap-1 text-xs font-bold text-rose-600 bg-rose-50 px-2 py-0.5 rounded-lg border border-rose-200/60" title="<?= htmlspecialchars($log['reason_for_absence'] ?? '') ?>">Absent</span>
                                                                <?php if (!empty($log['reason_for_absence'])): ?>
                                                                    <span class="text-[11px] text-slate-400 block mt-0.5" title="<?= htmlspecialchars($log['reason_for_absence']) ?>">Reason noted</span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent'; ?>
                                                        <td class="px-4 py-3.5 text-xs sm:text-sm text-slate-700 align-top break-words max-w-[160px] font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['task_title'] ?: '-') ?></td>
                                                        <td class="px-4 py-3.5 text-xs sm:text-sm text-slate-700 align-top break-words max-w-[220px] font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?: '-') ?></td>
                                                        <td class="px-4 py-3.5 text-xs sm:text-sm text-slate-700 align-top break-words max-w-[150px] font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?: '-') ?></td>
                                                        <td class="px-4 py-3.5 text-xs sm:text-sm text-slate-700 align-top break-words max-w-[150px] font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['learnt_skills'] ?: '-') ?></td>
                                                        <td class="px-4 py-3.5 font-mono text-teal-700 text-xs sm:text-sm font-bold whitespace-nowrap align-top"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="p-12 text-center">
                                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                                        <p class="text-sm text-slate-500 font-medium">No daily logs for Week <?= $week_num ?>.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Weekly Reflection & Student Signature -->
                            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                        <span class="w-8 h-8 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center text-sm">📊</span> Weekly Reflection – Week <?= $week_num ?>
                                    </h2>
                                </div>
                                <?php if ($reflection): ?>
                                    <div class="p-6 space-y-5">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1.5">What was done? <span class="text-slate-400 font-normal">/ ဘာလုပ်သလဲ</span></span>
                                                <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($reflection['what_done'] ?? '')) ?></p>
                                            </div>
                                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1.5">How was it done? <span class="text-slate-400 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></span>
                                                <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($reflection['how_done'] ?? '')) ?></p>
                                            </div>
                                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1.5">Why was it done? <span class="text-slate-400 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></span>
                                                <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($reflection['why_done'] ?? '')) ?></p>
                                            </div>
                                        </div>

                                        <!-- Student Signature (Realistic document signature) -->
                                        <?php if (!empty($instructor_eval['student_signature_value'])): ?>
                                            <div class="border-t border-slate-100 pt-4 flex items-center justify-end gap-3 flex-wrap">
                                                <div class="inline-flex items-baseline gap-2 select-none cursor-default">
                                                    <span class="text-xs text-slate-400 font-medium">Student Signature:</span>
                                                    <div class="border-b border-slate-300 pb-0.5 px-2 inline-flex items-center">
                                                        <?php if ($instructor_eval['student_signature_type'] === 'typed'): ?>
                                                            <span class="text-2xl text-slate-800 tracking-wide select-none" style="font-family: 'Great Vibes', cursive; line-height: 1;"><?= htmlspecialchars($instructor_eval['student_signature_value']) ?></span>
                                                        <?php else: ?>
                                                            <img src="<?= htmlspecialchars($instructor_eval['student_signature_value']) ?>" alt="Student Signature" class="h-8 max-w-[140px] object-contain select-none pointer-events-none">
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200/60">✓ Signed</span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="p-12 text-center">
                                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                                        <p class="text-sm text-slate-500 font-medium">No weekly reflection for Week <?= $week_num ?>.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Company Instructor Feedback & Signature (Compact Card) -->
                            <?php if ($instructor_eval && in_array($instructor_eval['report_status'], ['approved_by_instructor', 'approved_by_supervisor'], true)): ?>
                                <div id="instructor-feedback" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-5 scroll-mt-24">
                                    <div class="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-slate-100">
                                        <div class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-lg bg-teal-600 text-white flex items-center justify-center text-xs shadow-xs shrink-0">
                                                🏢
                                            </div>
                                            <h3 class="text-xs sm:text-sm font-bold text-slate-800">Company Instructor Evaluation</h3>
                                        </div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <?php $ig = $grade_labels[$instructor_eval['grade']] ?? ['—', 'text-slate-600', 'bg-slate-100']; ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-bold <?= $ig[1] ?> <?= $ig[2] ?> border border-slate-200/80">
                                                Score: <?= $ig[0] ?>
                                            </span>
                                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200/70 px-2 py-0.5 rounded-lg">
                                                ✓ Approved
                                            </span>
                                        </div>
                                    </div>

                                    <div class="pt-3 space-y-3">
                                        <?php if (!empty($instructor_eval['comment'])): ?>
                                            <div class="bg-slate-50 border border-slate-100 rounded-xl p-3">
                                                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Instructor Comment</span>
                                                <p class="text-xs sm:text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($instructor_eval['comment'])) ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex items-center justify-between flex-wrap gap-3 pt-2 border-t border-slate-100">
                                            <div class="inline-flex items-baseline gap-2 select-none cursor-default">
                                                <span class="text-xs text-slate-400 font-medium">Instructor Signature:</span>
                                                <div class="border-b border-slate-300 pb-0.5 px-2 inline-flex items-center">
                                                    <?php if ($instructor_eval['signature_type'] === 'typed' && !empty($instructor_eval['signature_value'])): ?>
                                                        <span class="text-2xl text-slate-800 tracking-wide select-none" style="font-family: 'Great Vibes', cursive; line-height: 1;"><?= htmlspecialchars($instructor_eval['signature_value']) ?></span>
                                                    <?php elseif (!empty($instructor_eval['signature_value'])): ?>
                                                        <img src="../uploads/signatures/<?= htmlspecialchars($instructor_eval['signature_value']) ?>" alt="Instructor Signature" class="h-7 max-w-[130px] object-contain select-none pointer-events-none">
                                                    <?php endif; ?>
                                                </div>
                                                <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200/60">✓ Verified</span>
                                            </div>
                                            <span class="text-[11px] text-slate-400 font-medium">
                                                Evaluated on <?= (new DateTime($instructor_eval['evaluated_at']))->format('d M Y, h:i A') ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($instructor_eval && $instructor_eval['report_status'] === 'rejected'): ?>
                                <div class="bg-white rounded-2xl border border-rose-200 shadow-sm p-4 sm:p-5">
                                    <div class="flex items-center justify-between pb-3 border-b border-rose-100">
                                        <div class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center text-xs shrink-0">🏢</div>
                                            <h3 class="text-xs sm:text-sm font-bold text-rose-800">Company Instructor Evaluation</h3>
                                        </div>
                                        <span class="text-xs font-bold text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-lg">❌ Rejected</span>
                                    </div>
                                    <div class="pt-3">
                                        <?php if ($instructor_eval['instructor_comments']): ?>
                                            <p class="text-xs text-rose-700 bg-rose-50 border border-rose-200/60 rounded-xl p-3"><?= nl2br(htmlspecialchars($instructor_eval['instructor_comments'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                                    <p class="text-xs text-slate-500 font-medium">🏢 Waiting for Company Instructor to evaluate this report.</p>
                                </div>
                            <?php endif; ?>

                            <!-- ═══ UNIVERSITY EVALUATION FORM (Compact & Space-Saving) ═══ -->
                            <div id="university-evaluation" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-5 scroll-mt-24">
                                <div class="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-slate-100">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-teal-700 text-white flex items-center justify-center text-xs shadow-xs shrink-0">🎓</div>
                                        <h3 class="text-xs sm:text-sm font-bold text-slate-800">University Evaluation – Week <?= $week_num ?></h3>
                                    </div>
                                    <?php if ($supervisor_eval): ?>
                                        <span class="text-[11px] font-bold text-teal-800 bg-teal-50 border border-teal-200/60 px-2 py-0.5 rounded-lg">
                                            Evaluated: <?= (new DateTime($supervisor_eval['evaluated_at']))->format('d M Y') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200/70 px-2 py-0.5 rounded-lg">
                                            Awaiting University Grade
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <form method="POST" class="pt-3 space-y-3.5">
                                    <!-- Compact Weekly Grade Selector -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label class="block text-xs font-bold text-slate-700">Weekly Grade <span class="text-rose-500">*</span></label>
                                            <?php if (empty($supervisor_eval['weekly_grade'])): ?>
                                                <span class="text-[10px] text-amber-600 font-semibold bg-amber-50 border border-amber-200/60 px-2 py-0.5 rounded-md">Please select a grade</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="grid grid-cols-5 gap-2">
                                            <?php
                                            $existing_grade = $supervisor_eval['weekly_grade'] ?? '';
                                            $labels = ['A' => 'Excellent', 'B' => 'Good', 'C' => 'Satisfactory', 'D' => 'Pass', 'F' => 'Fail'];
                                            foreach (['A', 'B', 'C', 'D', 'F'] as $g):
                                            ?>
                                                <label class="flex items-center justify-center gap-1.5 py-2 px-2 bg-slate-50 hover:bg-teal-50/50 border border-slate-200 hover:border-teal-300 rounded-xl cursor-pointer transition text-center group">
                                                    <input type="radio" name="weekly_grade" value="<?= $g ?>" <?= ($existing_grade !== '' && $g === $existing_grade) ? 'checked' : '' ?> required class="accent-teal-600 w-3.5 h-3.5 cursor-pointer">
                                                    <span class="text-sm font-black <?= $g === 'A' ? 'text-emerald-600' : ($g === 'F' ? 'text-rose-500' : 'text-slate-700') ?>"><?= $g ?></span>
                                                    <span class="text-[10px] text-slate-400 font-semibold hidden sm:inline">(<?= $labels[$g] ?>)</span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Compact Supervisor Comments -->
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-1">Supervisor Comments</label>
                                        <textarea name="supervisor_comments" rows="2" placeholder="Write feedback and recommendations for the student…"
                                            class="w-full bg-slate-50 focus:bg-white border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition resize-none shadow-xs"><?= htmlspecialchars($supervisor_eval['supervisor_comments'] ?? '') ?></textarea>
                                    </div>

                                    <!-- Compact Submit Button -->
                                    <div class="flex items-center justify-between gap-3 pt-1">
                                        <p class="text-[11px] text-slate-400 font-medium hidden sm:block">
                                            Final university assessment for Week <?= $week_num ?>.
                                        </p>
                                        <button type="submit" name="submit_sup_eval" class="w-full sm:w-auto px-5 py-2 bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer flex items-center justify-center gap-1.5 ml-auto">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span><?= $supervisor_eval ? 'Update Grade' : 'Submit & Approve' ?></span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                        </div>

                    </div>

                    <div class="text-center text-xs text-slate-400 py-3 font-medium">Powered by InternReport</div>
                </div>

            </main>
        </div>
    </div>

</body>

</html>