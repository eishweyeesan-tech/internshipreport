<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';


function getWeekRange(string $internship_start_date, int $week_number): ?array
{
    if ($week_number < 1) {
        return null;
    }

    $start = new DateTime($internship_start_date);

    if ($week_number === 1) {
        $dayOfWeek = (int) $start->format('N');
        $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
        $end = (clone $start)->modify("+{$daysToSat} days");
        return [
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
        ];
    }

    $dayOfWeek = (int) $start->format('N');
    $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
    $endOfWeek1 = (clone $start)->modify("+{$daysToSat} days");

    $weekStart = (clone $endOfWeek1)->modify('+1 day');
    if ($week_number > 2) {
        $weekStart->modify('+' . (($week_number - 2) * 7) . ' days');
    }
    $weekEnd = (clone $weekStart)->modify('+6 days');

    return [
        'start' => $weekStart->format('Y-m-d'),
        'end'   => $weekEnd->format('Y-m-d'),
    ];
}

require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$db       = $mysqli ?? $conn;

// ── Notification redirect URL helper ────────────────────────────
require_once __DIR__ . '/../config/notify.php';

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($db, $sup_id);

// ── Fetch notifications ─────────────────────────────────────────
$unread_notif_q = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->bind_param("i", $sup_id);
$unread_notif_q->execute();
$res = $unread_notif_q->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->bind_param("i", $sup_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$student_id = (int) ($_GET['id'] ?? $_GET['student_id'] ?? $_GET['uid'] ?? 0);

if ($student_id <= 0) {
    header('Location: my-students.php');
    exit;
}

// ── Student selected: continue with normal page ───────────────────

$check = $db->prepare("
    SELECT 1 FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ? AND sp.supervisor_id = ? AND u.role = 'student'
");
$check->bind_param("ii", $student_id, $sup_id);
$check->execute();
$res = $check->get_result();
if (!$res || !$res->fetch_row()) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$profile_r = $db->prepare("
    SELECT sp.*, u.username, u.email, u.profile_pic,
           sup_u.username AS supervisor_name
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE sp.user_id = ?
");
$profile_r->bind_param("i", $student_id);
$profile_r->execute();
$res = $profile_r->get_result();
$profile = $res ? $res->fetch_assoc() : null;

if (!$profile) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$intern_start  = $profile['internship_start_date'] ?? null;
$intern_end    = $profile['internship_end_date'] ?? null;
$student_name  = $profile['full_name'] ?: ($profile['username'] ?? 'Student');
$student_roll  = $profile['student_roll'] ?? '';
$company_name  = $profile['company_name'] ?? '';
$major         = $profile['major'] ?? '';
$job_role      = $profile['job_role'] ?? '';
$phone         = $profile['phone'] ?? '';
$instructor_name = $profile['instructor_name'] ?? '—';
$profile_pic   = $profile['profile_pic'] ?? '';

$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?");
$total_assigned_q->bind_param("i", $sup_id);
$total_assigned_q->execute();
$res = $total_assigned_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);
$selected_year_label = '';

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

$selected_week = 1;
if (isset($_GET['week'])) {
    $w = (int) $_GET['week'];
    if (isset($weeks[$w])) $selected_week = $w;
}

$week_date_range = '';
if (!empty($weeks[$selected_week])) {
    $ws_obj = new DateTime($weeks[$selected_week]['start']);
    $we_obj = new DateTime($weeks[$selected_week]['end']);
    $week_date_range = $ws_obj->format('d M Y') . ' to ' . $we_obj->format('d M Y');
}

if (!empty($weeks)) {
    $log_r = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date DESC");
    $log_r->bind_param("iss", $student_id, $weeks[$selected_week]['start'], $weeks[$selected_week]['end']);
    $log_r->execute();
} else {
    $log_r = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC");
    $log_r->bind_param("i", $student_id);
    $log_r->execute();
}
$res = $log_r->get_result();
$recent_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ── Selected-week summary (used only by the weekly sections below) ─
$week_logs_count = count($recent_logs);

$week_att = !empty($weeks[$selected_week])
    ? internship_attendance($db, $student_id, $weeks[$selected_week]['start'], $weeks[$selected_week]['end'])
    : ['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => 0];
$week_present_count = $week_att['present'];
$week_absent_count  = $week_att['absent'];

// ── Entire-internship summary (statistics cards) ──────────────────
$total_internship_weeks = count($weeks);

$intern_att    = internship_attendance($db, $student_id);
$intern_logs_q = $db->prepare("SELECT log_date, calculated_duration FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$intern_logs_q->bind_param("i", $student_id);
$intern_logs_q->execute();
$res = $intern_logs_q->get_result();
$intern_total_minutes = 0;
$intern_log_days      = 0;
$seen_intern_dates    = [];
if ($res) {
    while ($log = $res->fetch_assoc()) {
        if (!isset($seen_intern_dates[$log['log_date']])) {
            $seen_intern_dates[$log['log_date']] = true;
            $intern_log_days++;
        }
        $dur_parts = explode(':', (string) ($log['calculated_duration'] ?? ''));
        if (count($dur_parts) === 2) {
            $intern_total_minutes += ((int)$dur_parts[0] * 60) + (int)$dur_parts[1];
        }
    }
}
$intern_hours = floor($intern_total_minutes / 60);
$intern_mins  = $intern_total_minutes % 60;

$ref_r = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$ref_r->bind_param("ii", $student_id, $selected_week);
$ref_r->execute();
$res = $ref_r->get_result();
$weekly_refs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$eval_r = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$eval_r->bind_param("ii", $student_id, $selected_week);
$eval_r->execute();
$res = $eval_r->get_result();
$evaluation = $res ? $res->fetch_assoc() : null;

$sup_eval_r = $db->prepare("SELECT * FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
$sup_eval_r->bind_param("ii", $student_id, $selected_week);
$sup_eval_r->execute();
$res = $sup_eval_r->get_result();
$sup_evaluation = $res ? $res->fetch_assoc() : null;

$today_obj = new DateTime();
$today_str = $today_obj->format('Y-m-d');
$dynamic_week = 1;
$not_started = false;
if ($intern_start) {
    $dynamic_week = internship_current_week($intern_start, $intern_end ?: null, $today_obj);
    if ($today_obj < new DateTime($intern_start)) {
        $not_started = true;
    }
} else {
    $not_started = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student_name) ?> – Student View</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] },
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
    <style>
    .glow-indigo { box-shadow: 0 4px 20px rgba(99,102,241,0.25); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- Top Bar -->
        <?php $pageTitle = 'Student Details'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">

                <!-- Back Navigation -->
                <div>
                    <a href="my-students.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition" title="Back to My Students">
                        ← Back to My Students
                    </a>
                </div>

                <!-- Student Info Bar -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5">
                    <div class="flex items-center gap-4">
                        <?php if ($profile_pic): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-14 h-14 rounded-full object-cover border-2 border-white shadow-lg shadow-indigo-500/20 shrink-0">
                        <?php else: ?>
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-lg font-bold shadow-lg shadow-indigo-500/20 shrink-0">
                            <?= strtoupper(substr($student_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-sm font-black text-slate-800"><?= htmlspecialchars($student_name) ?></h2>
                            <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($profile['email'] ?? '') ?></p>
                            <div class="flex items-center gap-3 mt-1">
                                <?php if ($student_roll): ?>
                                <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($student_roll) ?></span>
                                <?php endif; ?>
                                <?php if ($major): ?>
                                <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded"><?= htmlspecialchars($major) ?></span>
                                <?php endif; ?>
                                <?php if ($job_role): ?>
                                <span class="text-xs font-bold text-violet-600 bg-violet-50 px-2 py-0.5 rounded border border-violet-200/60">💼 <?= htmlspecialchars($job_role) ?></span>
                                <?php endif; ?>
                                <?php if ($company_name): ?>
                                <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">🏢 <?= htmlspecialchars($company_name) ?></span>
                                <?php endif; ?>
                                <?php if ($phone): ?>
                                <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">📱 <?= htmlspecialchars($phone) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                        <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                            <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <?= strtoupper(substr(format_supervisor_name($profile['supervisor_name'] ?? 'S'), 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-slate-400 uppercase tracking-wider">Supervisor</p>
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars(format_supervisor_name($profile['supervisor_name'] ?? '—')) ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                            <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <?= strtoupper(substr($instructor_name, 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-slate-400 uppercase tracking-wider">Instructor</p>
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($instructor_name) ?></p>
                            </div>
                        </div>
                        <?php if ($intern_start && $intern_end): ?>
                        <div class="flex items-center gap-3 bg-indigo-50/50 rounded-xl px-4 py-3 border border-indigo-200/50">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-indigo-400 uppercase tracking-wider">Internship Period</p>
                                <p class="text-xs font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <?php elseif ($intern_start): ?>
                        <div class="flex items-center gap-3 bg-indigo-50/50 rounded-xl px-4 py-3 border border-indigo-200/50">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-indigo-400 uppercase tracking-wider">Internship Start</p>
                                <p class="text-xs font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-lg shadow-lg shadow-blue-500/30 mb-3">⏱️</div>
                        <p class="text-2xl font-black text-slate-800"><?= $intern_hours ?>h <?= $intern_mins ?>m</p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Total Hours Worked</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-violet-600 text-white flex items-center justify-center text-lg shadow-lg shadow-violet-500/30 mb-3">📋</div>
                        <p class="text-2xl font-black text-slate-800"><?= $intern_log_days ?></p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Daily Logs Submitted</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-lg shadow-lg shadow-emerald-500/30 mb-3">✅</div>
                        <p class="text-2xl font-black text-slate-800"><?= $intern_att['rate'] ?>%</p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Attendance Rate</p>
                        <p class="text-[10px] text-slate-500 font-semibold mt-0.5"><?= $intern_att['present'] ?>/<?= $intern_att['expected'] ?> days</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-lg shadow-lg shadow-purple-500/30 mb-3">📆</div>
                        <p class="text-2xl font-black text-slate-800"><?= $total_internship_weeks > 0 ? $total_internship_weeks : '—' ?></p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Total Internship Weeks</p>
                    </div>
                </div>

                <!-- Week Selector & Actions Bar -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            <div class="relative" id="week-dropdown">
                                <button onclick="toggleWeekDropdown()" class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition cursor-pointer whitespace-nowrap">
                                    📆 Week <?= $selected_week ?>
                                    <span class="text-slate-400 text-label">▾</span>
                                </button>
                                <div id="week-menu" class="absolute left-0 top-full mt-1 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden overflow-hidden max-h-64 overflow-y-auto">
                                    <?php foreach ($weeks as $wn => $wr): ?>
                                    <a href="?id=<?= $student_id ?>&week=<?= $wn ?>" class="flex items-center justify-between px-3 py-2 text-xs font-semibold <?= $selected_week === $wn ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?> transition">
                                        Week <?= $wn ?>
                                        <span class="text-label text-slate-400"><?= $wr['start'] ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($week_date_range): ?>
                            <span class="text-xs text-slate-400 font-medium"><?= $week_date_range ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="supervisor-reports.php?student_id=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition shadow-xs" title="View all reports for this student">
                                📄 View Reports
                            </a>
                            <a href="../view_student_history.php?uid=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition shadow-xs" title="View complete 13-week log history">
                                📜 Full History
                            </a>
                            <a href="supervisor-review.php?student_id=<?= $student_id ?>&week=<?= $selected_week ?>" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-gradient-to-r from-teal-600 to-[#005f73] hover:from-teal-700 hover:to-[#004e5f] text-white text-xs font-bold rounded-lg shadow-xs transition" title="Review & grade this week's report">
                                📝 Review & Grade
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 2-Column Layout -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- LEFT (2/3): Logs + Reflection -->
                    <div class="lg:col-span-2 space-y-6">

                        <!-- Daily Logs Table -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📝</span> Daily Logs
                                </h2>
                                <span class="text-xs text-slate-400 font-medium"><?= $week_logs_count ?> day(s)</span>
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">✓ Present: <?= $week_present_count ?></span>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded">✕ Absent: <?= $week_absent_count ?></span>
                                </div>
                            </div>
                            <?php if (!empty($recent_logs)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                            <th class="px-4 py-3 text-left">Date</th>
                                            <th class="px-4 py-3 text-left">Status</th>
                                            <th class="px-4 py-3 text-left">Task</th>
                                            <th class="px-4 py-3 text-left">Details</th>
                                            <th class="px-4 py-3 text-left">Duration</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($recent_logs as $log): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                            <td class="px-4 py-3 font-medium text-slate-700 whitespace-nowrap">
                                                <?= (new DateTime($log['log_date']))->format('D, d M') ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">✓ Present</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded" <?= !empty($log['reason_for_absence']) ? 'title="' . htmlspecialchars(($log['attendance_status'] === 'leave' ? 'Leave' : 'Absent') . ': ' . $log['reason_for_absence']) . '"' : '' ?>>✕ Absent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600 max-w-[180px] truncate" title="<?= htmlspecialchars($log['task_title'] ?? '') ?>"><?= htmlspecialchars($log['task_title'] ?? '—') ?></td>
                                            <td class="px-4 py-3 text-slate-600 max-w-[200px] truncate" title="<?= htmlspecialchars($log['task_detail'] ?? '') ?>"><?= htmlspecialchars($log['task_detail'] ?? '—') ?></td>
                                            <td class="px-4 py-3 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="p-8 text-center text-xs text-slate-400">No daily logs found for Week <?= $selected_week ?>.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Weekly Reflection -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span> Weekly Reflection
                                </h2>
                            </div>
                            <?php if (!empty($weekly_refs)): ?>
                            <div class="p-5 space-y-4">
                                <?php foreach ($weekly_refs as $ref): ?>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">❓ What was done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['what_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">⚙️ How was it done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['how_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">🎯 Why was it done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['why_done'] ?? '')) ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-8 text-center text-xs text-slate-400">No weekly reflection submitted for Week <?= $selected_week ?>.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RIGHT (1/3): Evaluation Status -->
                    <div class="lg:col-span-1 space-y-6">
                        <!-- Instructor Evaluation -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-amber-50 text-amber-500 rounded">👨‍🏫</span> Instructor Evaluation
                                </h2>
                            </div>
                            <?php if ($evaluation && ($evaluation['report_status'] === 'approved_by_instructor' || $evaluation['report_status'] === 'approved_by_supervisor')): ?>
                            <div class="p-5 space-y-3">
                                <div class="flex items-center gap-2 text-sm text-emerald-600 bg-emerald-50 px-3 py-2 rounded-xl font-bold">
                                    <span>✅</span> Approved
                                </div>
                                <?php if ($evaluation['grade']): ?>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Grade</span>
                                    <p class="text-sm font-bold text-slate-700 mt-0.5"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $evaluation['grade']))) ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if ($evaluation['comment']): ?>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Comment</span>
                                    <p class="text-xs text-slate-600 leading-relaxed mt-0.5"><?= nl2br(htmlspecialchars($evaluation['comment'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($evaluation && $evaluation['report_status'] === 'rejected'): ?>
                            <div class="p-5 space-y-3">
                                <div class="flex items-center gap-2 text-sm text-red-600 bg-red-50 px-3 py-2 rounded-xl font-bold">
                                    <span>❌</span> Rejected
                                </div>
                                <?php if ($evaluation['instructor_comments']): ?>
                                <div class="bg-red-50 rounded-xl p-3 border border-red-200">
                                    <span class="text-xs font-bold text-red-400 uppercase tracking-wider">Reason</span>
                                    <p class="text-xs text-red-600 leading-relaxed mt-0.5"><?= nl2br(htmlspecialchars($evaluation['instructor_comments'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-5 text-center">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-lg mx-auto mb-2">⏳</div>
                                <p class="text-xs text-slate-400 font-medium">Pending instructor review.</p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Supervisor Evaluation -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-purple-50 text-purple-500 rounded">👩‍🏫</span> Supervisor Grade
                                </h2>
                            </div>
                            <?php if ($sup_evaluation): ?>
                            <div class="p-5 space-y-3">
                                <div class="flex items-center gap-2 text-sm text-emerald-600 bg-emerald-50 px-3 py-2 rounded-xl font-bold">
                                    <span>✅</span> Graded
                                </div>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Grade</span>
                                    <p class="text-sm font-bold text-slate-700 mt-0.5"><?= htmlspecialchars($sup_evaluation['weekly_grade'] ?? '—') ?></p>
                                </div>
                                <?php if (!empty($sup_evaluation['feedback'])): ?>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Feedback</span>
                                    <p class="text-xs text-slate-600 leading-relaxed mt-0.5"><?= nl2br(htmlspecialchars($sup_evaluation['feedback'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-5 text-center">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-lg mx-auto mb-2">⏳</div>
                                <p class="text-xs text-slate-400 font-medium">Not yet graded.</p>
                                <a href="supervisor-review.php?student_id=<?= $student_id ?>" class="mt-2 inline-block text-xs font-bold text-indigo-600 hover:underline">Grade now →</a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Links -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-blue-50 text-blue-500 rounded">🔗</span> Quick Actions
                                </h2>
                            </div>
                            <div class="p-4 space-y-2">
                                <a href="supervisor-review.php?student_id=<?= $student_id ?>" class="flex items-center gap-2 px-3 py-2 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-xs font-bold rounded-xl hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-sm">
                                    View & Grade Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- Announcement Detail Modal -->
<div id="ann-detail-backdrop" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-[2000] transition-opacity duration-200" style="opacity:0"></div>
<div id="ann-detail-modal" class="hidden fixed inset-0 z-[2001] flex items-center justify-center p-4 transition-all duration-200" style="opacity:0;transform:scale(0.95)">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
        <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-8 py-6 shrink-0">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">📢</span>
                    <span class="text-xs font-bold text-blue-200 uppercase tracking-wider bg-white/20 backdrop-blur-sm px-3 py-1 rounded-full">Announcement</span>
                </div>
                <button onclick="closeAnnouncementModal()" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition shrink-0 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <h1 id="ann-detail-title" class="text-xl font-black text-white leading-tight mt-4">Loading...</h1>
            <div class="flex items-center gap-4 mt-3 text-sm text-blue-200 font-medium">
                <span class="flex items-center gap-1.5" id="ann-detail-sender"></span>
                <span class="flex items-center gap-1.5" id="ann-detail-date"></span>
            </div>
        </div>
        <div id="ann-detail-body" class="flex-1 overflow-y-auto px-8 py-6">
            <div class="flex items-center justify-center py-12"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div></div>
        </div>
        <div class="px-8 py-4 border-t border-slate-100 flex items-center justify-end shrink-0 bg-slate-50/80">
            <button onclick="closeAnnouncementModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">Close</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
