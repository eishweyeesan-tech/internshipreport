<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$sup_id = (int) $_SESSION['user_id'];
$student_id = (int) ($_GET['student_id'] ?? 0);
$week_num = (int) ($_GET['week'] ?? 0);
$db = $mysqli ?? $conn;

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
$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?");
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

// Use the auto-detected week as default if no week specified
if ($week_num <= 0 || !isset($weeks[$week_num])) {
    $week_num = $auto_week;
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
        .scroll-margin { scroll-margin-top: 88px; }

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
            aside, header, #sidebarBackdrop, #supervisorSidebarBackdrop,
            .print\:hidden, button, form button, .no-print {
                display: none !important;
            }
            .flex.h-screen, .h-screen {
                height: auto !important;
                overflow: visible !important;
                display: block !important;
            }
            main {
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }
            .max-w-7xl, .max-w-4xl, .max-w-5xl {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .bg-white, .bg-slate-50, .bg-teal-50, .bg-indigo-50, .bg-emerald-50 {
                background: #ffffff !important;
            }
            .border, .border-slate-200, .border-slate-100, .border-teal-100 {
                border-color: #cbd5e1 !important;
            }
            .shadow-sm, .shadow-md, .shadow-lg, .shadow-xl, .shadow-2xl {
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
            th, td {
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
    <script>
    function toggleWeekDropdown(e) {
        e.stopPropagation();
        document.getElementById('week-menu').classList.toggle('hidden');
    }
    document.addEventListener('click', function (e) {
        var dd = document.getElementById('week-dropdown');
        var menu = document.getElementById('week-menu');
        if (dd && !dd.contains(e.target)) {
            menu.classList.add('hidden');
        }
    });
    function toggleProfileDropdown(e) {
        e.stopPropagation();
        document.getElementById('profile-dropdown-menu').classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-4 lg:px-8 shrink-0 shadow-sm print:hidden">

            <div class="flex items-center gap-4">
                <a href="supervisor-reports.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-lg transition-all duration-200" title="Back to Reports">
                    ← Back to Reports
                </a>
                <h1 class="text-base font-bold text-slate-800">Student Review — <?= htmlspecialchars($student_name) ?></h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if (!empty($selected_year_label)): ?>
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year_label) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 bg-indigo-50 border border-indigo-200 rounded-full">
                    <span class="text-xs font-bold text-indigo-700">📅 Week <?= $week_num ?>/<?= $total_weeks ?></span>
                    <?php if ($week_num === $auto_week): ?>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                    <div class="relative shrink-0" id="profileDropdownContainer">
                        <button
                            type="button"
                            onclick="toggleProfileDropdown(event)"
                            id="profile-avatar-btn"
                            class="flex items-center gap-2.5 p-1.5 hover:bg-teal-50 border border-transparent hover:border-teal-100 rounded-xl transition-all cursor-pointer group"
                        >
                            <?php if (!empty($_SESSION['profile_pic'])): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover border border-teal-200 shadow-sm">
                            <?php else: ?>
                            <div class="w-9 h-9 rounded-xl bg-teal-700 flex items-center justify-center font-bold text-sm text-white shadow-sm">
                                <?= strtoupper(substr(format_supervisor_name($_SESSION['username'] ?? 'S'), 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-left hidden sm:block">
                                <p class="font-semibold text-sm text-slate-800 leading-tight"><?= htmlspecialchars(format_supervisor_name($sup_name ?? '')) ?></p>
                                <p class="text-xs font-medium text-teal-700 capitalize">Supervisor</p>
                            </div>

                            <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Profile Dropdown Menu -->
                        <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-lg border border-teal-100 py-1.5 z-50 divide-y divide-slate-100">
                            <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                                <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> My Profile
                            </a>
                            <a href="profile.php#security-section" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                                <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> Change Password
                            </a>
                            <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                                <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">


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
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-2xl font-bold shrink-0 shadow-xl shadow-indigo-500/30">
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
                        <?php if ($student['job_role']): ?>
                            <span class="text-xs font-semibold text-violet-600 bg-violet-50 px-3 py-1 rounded-lg border border-violet-200/60">💼 <?= htmlspecialchars($student['job_role']) ?></span>
                        <?php endif; ?>
                        <?php if ($student['company_name']): ?>
                            <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-3 py-1 rounded-lg border border-blue-200/60">🏢 <?= htmlspecialchars($student['company_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($student['phone']): ?>
                            <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-3 py-1 rounded-lg">📱 <?= htmlspecialchars($student['phone']) ?></span>
                        <?php endif; ?>
                        <?php if ($student['instructor_email']): ?>
                            <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-3 py-1 rounded-lg border border-amber-200/60">✉️ <?= htmlspecialchars($student['instructor_email']) ?></span>
                        <?php endif; ?>
                        <?php if ($student['academic_year']): ?>
                            <span class="text-xs font-semibold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-lg font-mono border border-indigo-200/60"><?= htmlspecialchars($student['academic_year']) ?></span>
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
                <a href="view-student-dashboard.php?id=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs">
                    👁️ Dashboard
                </a>
                <a href="supervisor-reports.php?student_id=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs">
                    📄 Reports
                </a>
                <a href="../view_student_history.php?uid=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs" title="View 13-week complete history">
                    📜 History
                </a>
                <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 px-3.5 py-2 bg-[#005f73] hover:bg-[#0a9396] text-white text-xs font-bold rounded-xl shadow-md shadow-teal-700/20 transition-all duration-200 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Print</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ════ STUDENT PERFORMANCE CARDS ════ -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-lg shadow-lg shadow-emerald-500/30">✅</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Attendance</p>
                    <p class="text-xl font-black text-slate-800"><?= $week_attendance_rate ?>%</p>
                    <p class="text-sm text-emerald-500 font-bold"><?= $week_present ?> of <?= $week_expected ?> day<?= $week_expected !== 1 ? 's' : '' ?></p>
                    <p class="text-xs text-slate-400 font-medium">Week <?= $week_num ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-lg shadow-lg shadow-blue-500/30">📊</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Weekly Grades</p>
                    <p class="text-xl font-black text-slate-800"><?= $student_graded ?><span class="text-sm font-bold text-slate-400">/<?= $total_weeks ?></span></p>
                    <p class="text-sm text-blue-500 font-bold">Reports graded</p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center text-lg shadow-lg shadow-indigo-500/30">📅</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Current Week</p>
                    <p class="text-xl font-black text-slate-800"><?= $week_num ?><span class="text-sm font-bold text-slate-400">/<?= $total_weeks ?></span></p>
                    <?php if ($week_date_range): ?>
                    <p class="text-sm text-indigo-500 font-bold"><?= $week_date_range ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center text-lg shadow-lg shadow-amber-500/30">📝</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Logs This Week</p>
                    <p class="text-xl font-black text-slate-800"><?= $week_log_days ?></p>
                    <p class="text-sm text-amber-500 font-bold"><?= $week_log_days ?> day<?= $week_log_days !== 1 ? 's' : '' ?> submitted</p>
                </div>
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
            <div class="flex items-center gap-3">
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
                        <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📝</span> Daily Logs – Week <?= $week_num ?>
                    </h2>
                    <span class="text-xs text-slate-400 font-medium"><?= $week_log_days ?> day(s)</span>
                </div>
                <?php if (!empty($daily_logs)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                <th class="px-5 py-3 text-left">Date</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th class="px-5 py-3 text-left">Intended Task</th>
                                <th class="px-5 py-3 text-left">Actual Task</th>
                                <th class="px-5 py-3 text-left">Tools</th>
                                <th class="px-5 py-3 text-left">Knowledge</th>
                                <th class="px-5 py-3 text-left">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($daily_logs as $log): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                <td class="px-5 py-4 font-medium text-slate-700 whitespace-nowrap">
                                    <?= htmlspecialchars($log['log_date']) ?>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60">✅ Present</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200/60" title="<?= htmlspecialchars($log['reason_for_absence'] ?? '') ?>">❌ Absent</span>
                                    <?php endif; ?>
                                </td>
                                <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent'; ?>
                                <td class="px-5 py-4 text-slate-600 max-w-[160px] truncate font-medium" title="<?= $is_absent ? '' : htmlspecialchars($log['task_title'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['task_title'] ?: '-') ?></td>
                                <td class="px-5 py-4 text-slate-600 max-w-[200px] truncate font-medium" title="<?= $is_absent ? '' : htmlspecialchars($log['tasks_performed'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?: '-') ?></td>
                                <td class="px-5 py-4 text-slate-600 font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?: '-') ?></td>
                                <td class="px-5 py-4 text-slate-600 font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['learnt_skills'] ?: '-') ?></td>
                                <td class="px-5 py-4 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
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

            <!-- Weekly Reflection -->
            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span> Weekly Reflection – Week <?= $week_num ?>
                    </h2>
                </div>
                <?php if ($reflection): ?>
                <div class="p-6 space-y-5">
                    <div>
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">❓ What was done? <span class="text-slate-300 font-normal">/ ဘာလုပ်သလဲ</span></span>
                        <p class="text-sm text-slate-600 leading-relaxed bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4"><?= nl2br(htmlspecialchars($reflection['what_done'] ?? '')) ?></p>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">⚙️ How was it done? <span class="text-slate-300 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></span>
                        <p class="text-sm text-slate-600 leading-relaxed bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4"><?= nl2br(htmlspecialchars($reflection['how_done'] ?? '')) ?></p>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">🎯 Why was it done? <span class="text-slate-300 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></span>
                        <p class="text-sm text-slate-600 leading-relaxed bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4"><?= nl2br(htmlspecialchars($reflection['why_done'] ?? '')) ?></p>
                    </div>
                </div>
                <?php else: ?>
                <div class="p-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                    <p class="text-sm text-slate-500 font-medium">No weekly reflection for Week <?= $week_num ?>.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Company Instructor Feedback (read-only) -->
            <?php if ($instructor_eval && in_array($instructor_eval['report_status'], ['approved_by_instructor', 'approved_by_supervisor'], true)): ?>

            <div class="bg-white rounded-2xl border border-emerald-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-emerald-100 bg-gradient-to-r from-emerald-50 to-white">
                    <h2 class="text-sm font-bold text-emerald-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm">🏢</span> Company Instructor Feedback
                        <span class="ml-auto text-sm font-bold text-emerald-600 bg-emerald-100 px-2.5 py-1 rounded-lg border border-emerald-200">✅ Approved</span>
                    </h2>
                </div>
                <div class="p-6 space-y-5">
                    <!-- Assessment Score -->
                    <div class="flex items-center gap-4 p-4 bg-gradient-to-br from-emerald-50 to-white border border-emerald-100 rounded-xl">
                        <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center text-lg shrink-0">📊</div>
                        <div>
                            <p class="text-sm font-bold text-emerald-600 uppercase tracking-wider">Assessment Score</p>
                            <?php $ig = $grade_labels[$instructor_eval['grade']] ?? ['—', 'text-slate-600', 'bg-slate-100']; ?>
                            <p class="text-lg font-black <?= $ig[1] ?> mt-0.5"><?= $ig[0] ?></p>
                        </div>
                    </div>
                    <!-- Comment -->
                    <?php if ($instructor_eval['comment']): ?>
                    <div>
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-2">💬 Instructor Comment</span>
                        <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4">
                            <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($instructor_eval['comment'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Digital Signature -->
                    <?php if ($instructor_eval['signature_type'] === 'typed' && $instructor_eval['signature_value']): ?>
                    <div>
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-2">✍️ Digital Signature</span>
                        <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl p-5">
                            <p style="font-family:'Great Vibes',cursive; font-size:32px; color:#1e293b;"><?= htmlspecialchars($instructor_eval['signature_value']) ?></p>
                        </div>
                    </div>
                    <?php elseif ($instructor_eval['signature_type'] === 'uploaded' && $instructor_eval['signature_value']): ?>
                    <div>
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-2">✍️ Digital Signature</span>
                        <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl p-5">
                            <img src="../uploads/signatures/<?= htmlspecialchars($instructor_eval['signature_value']) ?>" alt="Instructor Signature" class="max-h-20 object-contain">
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Timestamp -->
                    <div class="flex items-center gap-2 pt-3 border-t border-slate-100">
                        <span class="text-sm text-slate-400 font-medium">Evaluated on <?= (new DateTime($instructor_eval['evaluated_at']))->format('d M Y, h:i A') ?></span>
                    </div>
                </div>
            </div>
            <?php elseif ($instructor_eval && $instructor_eval['report_status'] === 'rejected'): ?>
            <div class="bg-white rounded-2xl border border-red-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-red-100 bg-gradient-to-r from-red-50 to-white">
                    <h2 class="text-sm font-bold text-red-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-red-100 text-red-500 flex items-center justify-center text-sm">🏢</span> Company Instructor Feedback
                        <span class="ml-auto text-sm font-bold text-red-600 bg-red-100 px-2.5 py-1 rounded-lg border border-red-200">❌ Rejected</span>
                    </h2>
                </div>
                <div class="p-6 space-y-3">
                    <div class="flex items-center gap-3 p-4 bg-gradient-to-br from-red-50 to-white border border-red-100 rounded-xl">
                        <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center text-lg shrink-0">❌</div>
                        <div>
                            <p class="text-sm font-bold text-red-700">Report Rejected by Instructor</p>
                            <p class="text-xs text-red-500 mt-0.5">Student must revise and resubmit</p>
                        </div>
                    </div>
                    <?php if ($instructor_eval['instructor_comments']): ?>
                    <div>
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-2">💬 Rejection Reason</span>
                        <p class="text-sm text-red-600 bg-gradient-to-br from-red-50 to-red-100/50 border border-red-200/60 rounded-xl p-4"><?= nl2br(htmlspecialchars($instructor_eval['instructor_comments'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-slate-100 text-slate-400 flex items-center justify-center text-sm">🏢</span> Company Instructor Feedback
                    </h2>
                </div>
                <div class="p-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                    <p class="text-sm text-slate-500 font-medium">Instructor has not reviewed Week <?= $week_num ?> yet.</p>
                    <p class="text-xs text-slate-400 mt-1">Waiting for Company Instructor to evaluate this report.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ UNIVERSITY EVALUATION FORM ═══ -->
            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-indigo-50 to-white">
                    <h2 class="text-sm font-bold text-indigo-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm">🎓</span> University Evaluation – Week <?= $week_num ?>
                    </h2>
                </div>

                <!-- ── Editable Evaluation Form (always shown) ── -->
                <form method="POST" class="p-6 space-y-5">
                    <?php if ($supervisor_eval): ?>
                    <div class="flex items-center gap-2 text-xs text-indigo-600 bg-gradient-to-r from-indigo-50 to-indigo-100/50 border border-indigo-200/60 px-4 py-2.5 rounded-xl font-bold mb-2">
                        <div class="w-5 h-5 rounded-full bg-indigo-500 text-white flex items-center justify-center text-sm">✏️</div>
                        Editing existing grade — previously evaluated on <?= (new DateTime($supervisor_eval['evaluated_at']))->format('d M Y, h:i A') ?>
                    </div>
                    <?php else: ?>
                    <div class="bg-gradient-to-r from-amber-50 to-amber-100/50 border border-amber-200/60 rounded-xl p-4 mb-2">
                        <p class="text-xs font-bold text-amber-700 flex items-center gap-2">
                            <span>⚠️</span> Instructor has approved this report. Please enter your final university grade below.
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- Weekly Grade -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-3">Weekly Grade</label>
                        <div class="grid grid-cols-5 gap-2">
                            <?php
                            $existing_grade = $supervisor_eval['weekly_grade'] ?? 'C';
                            foreach (['A', 'B', 'C', 'D', 'F'] as $g):
                            ?>
                            <label class="flex flex-col items-center gap-1.5 px-2 py-3 bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl cursor-pointer hover:border-indigo-300 hover:shadow-md transition-all duration-200 text-center">
                                <input type="radio" name="weekly_grade" value="<?= $g ?>" <?= $g === $existing_grade ? 'checked' : '' ?> class="accent-indigo-600">
                                <span class="text-lg font-black <?= $g === 'A' ? 'text-emerald-600' : ($g === 'F' ? 'text-red-500' : 'text-slate-700') ?>"><?= $g ?></span>
                                <span class="text-sm text-slate-400 uppercase font-medium">
                                    <?php
                                    $labels = ['A' => 'Excellent', 'B' => 'Good', 'C' => 'Satisfactory', 'D' => 'Pass', 'F' => 'Fail'];
                                    echo $labels[$g];
                                    ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Supervisor Comments -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-2">Supervisor Comments</label>
                        <textarea name="supervisor_comments" rows="5" placeholder="Write your assessment, feedback, and recommendations for the student…"
                            class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 resize-none shadow-sm"><?= htmlspecialchars($supervisor_eval['supervisor_comments'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" name="submit_sup_eval" class="w-full px-5 py-3 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-indigo-500/30 transition-all duration-200 cursor-pointer">
                        <?= $supervisor_eval ? '✏️ Update Grade' : '📤 Submit & Approve' ?>
                    </button>
                </form>

                <div class="px-6 py-4 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white rounded-b-2xl">
                    <p class="text-sm text-slate-400 text-center leading-relaxed font-medium">
                        This grade is the final assessment for this week's internship performance.
                    </p>
                </div>
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
