<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';
require_once __DIR__ . '/../config/notify.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'] ?? '';
$db       = $mysqli ?? $conn;

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

// ── Verify student belongs to this supervisor ────────────────────
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
    SELECT sp.*, u.username, u.email, u.phone, u.profile_pic,
           ay.year_label AS academic_year,
           COALESCE(c.company_name, '') AS company_name, c.contact_person, c.contact_email, c.contact_phone,
           sup_u.username AS supervisor_name
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
    LEFT JOIN companies c ON c.id = sp.company_id
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

$intern_start    = $profile['internship_start_date'] ?? null;
$intern_end      = $profile['internship_end_date'] ?? null;
$student_name    = ($profile['username'] ?? 'Student');
$student_roll    = $profile['student_roll'] ?? '';
$company_name    = $profile['company_name'] ?? '';
$major           = $profile['major'] ?? '';
$job_role        = $profile['job_role'] ?? '';
$phone           = $profile['phone'] ?? '';
$instructor_name = '—';
$profile_pic     = $profile['profile_pic'] ?? '';
$academic_year   = $profile['academic_year'] ?? '';

// Total assigned students for supervisor
$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?");
$total_assigned_q->bind_param("i", $sup_id);
$total_assigned_q->execute();
$res = $total_assigned_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);

// ── Determine Week Ranges from internship start date ───────────────
$weeks = [];
$auto_week = 1;
$total_weeks = 12;
$not_started = false;

if ($intern_start) {
    $start_dt = new DateTime($intern_start);
    $total_weeks = internship_total_weeks($intern_start, $intern_end ?: null);
    $today_obj = new DateTime();
    $auto_week = internship_current_week($intern_start, $intern_end ?: null, $today_obj);

    if ($today_obj < new DateTime($intern_start)) {
        $not_started = true;
    }

    for ($i = 1; $i <= $total_weeks; $i++) {
        $ws = clone $start_dt;
        if ($i > 1) $ws->modify('+' . (($i - 1) * 7) . ' days');
        $we = (clone $ws)->modify('+6 days');
        $weeks[$i] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d')];
    }
} else {
    $not_started = true;
    // Fallback: build from log dates
    $all_dates = $db->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE student_id = ? ORDER BY log_date ASC");
    $all_dates->bind_param("i", $student_id);
    $all_dates->execute();
    $res = $all_dates->get_result();
    $log_dates = [];
    if ($res) {
        while ($r = $res->fetch_row()) {
            $log_dates[] = $r[0];
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
        $total_weeks = max(1, count($weeks));
    }
}

$total_internship_weeks = max(1, count($weeks) ?: $total_weeks);

$selected_week = $auto_week;
if (isset($_GET['week'])) {
    $w = (int) $_GET['week'];
    if (isset($weeks[$w])) {
        $selected_week = $w;
    }
}

$week_start = $weeks[$selected_week]['start'] ?? '';
$week_end   = $weeks[$selected_week]['end'] ?? '';

// Format date range (Mon-Fri)
$week_date_range = '';
if ($week_start) {
    $ws_obj = new DateTime($week_start);
    $we_obj = (clone $ws_obj)->modify('+4 days'); // Friday
    $week_date_range = $ws_obj->format('d M Y') . ' to ' . $we_obj->format('d M Y');
}

// ── Handle Supervisor Evaluation POST Submission ──────────────────
$eval_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sup_eval'])) {
    $grade    = $_POST['weekly_grade'] ?? '';
    $comments = trim($_POST['supervisor_comments'] ?? '');
    $allowed  = ['A', 'B', 'C', 'D', 'F'];

    if (!in_array($grade, $allowed, true)) {
        $eval_msg = 'invalid_grade';
    } else {
        $upsert = $db->prepare("
            UPDATE weekly_reports SET
            supervisor_grade = ?,
            supervisor_comments = ?,
            status = 'graded'
            WHERE student_id = ? AND week_number = ?
        ");
        $upsert->bind_param("ssii", $grade, $comments, $student_id, $selected_week);
        $upsert->execute();

        // Notify student
        $student_link = '../student/student-dashboard.php?week=' . (int)$selected_week;
        notify_user_once(
            $db,
            $student_id,
            "Week {$selected_week} Report Graded",
            "Your university supervisor evaluated and graded your Week {$selected_week} report with '{$grade}'.",
            'supervisor_approved',
            $selected_week,
            $student_id,
            null,
            false,
            $student_link
        );

        $eval_msg = 'saved';
    }
}

// ── Lifetime Statistics ────────────────────────────────────────────
$intern_att    = internship_attendance($db, $student_id);
$intern_logs_q = $db->prepare("SELECT log_date, calculated_duration FROM daily_logs WHERE student_id = ? ORDER BY log_date ASC");
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

// Present & Absent counts for tooltip
$pd_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE student_id = ? AND attendance_status = 'present' ORDER BY log_date ASC");
$pd_stmt->bind_param("i", $student_id);
$pd_stmt->execute();
$res = $pd_stmt->get_result();
$present_dates = [];
if ($res) {
    while ($r = $res->fetch_row()) {
        $present_dates[] = $r[0];
    }
}
$total_present = count($present_dates);

$ad_stmt = $db->prepare("SELECT log_date, reason_for_absence FROM daily_logs WHERE student_id = ? AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
$ad_stmt->bind_param("i", $student_id);
$ad_stmt->execute();
$res = $ad_stmt->get_result();
$absent_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$total_absent = count($absent_logs);

// ── Selected Week Data ─────────────────────────────────────────────
$daily_logs = [];
$reflection = null;
$instructor_eval = null;
$supervisor_eval = null;

if ($week_start && $week_end) {
    $dl = $db->prepare("SELECT * FROM daily_logs WHERE student_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC");
    $dl->bind_param("iss", $student_id, $week_start, $week_end);
    $dl->execute();
    $res = $dl->get_result();
    $daily_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    $rep_stmt = $db->prepare("SELECT * FROM weekly_reports WHERE student_id = ? AND week_number = ? LIMIT 1");
    $rep_stmt->bind_param("ii", $student_id, $selected_week);
    $rep_stmt->execute();
    $res = $rep_stmt->get_result();
    $weekly_report = $res ? $res->fetch_assoc() : null;

    if ($weekly_report) {
        $reflection = [
            'what_done' => $weekly_report['what_done'],
            'how_done'  => $weekly_report['how_done'],
            'why_done'  => $weekly_report['why_done'],
        ];
        if (!empty($weekly_report['instructor_grade'])) {
            $instructor_eval = [
                'grade'               => $weekly_report['instructor_grade'],
                'comment'             => $weekly_report['instructor_comments'],
                'instructor_comments' => $weekly_report['instructor_comments'],
                'report_status'       => $weekly_report['status'],
                'evaluated_at'        => $weekly_report['submitted_at'],
            ];
        }
        if (!empty($weekly_report['supervisor_grade'])) {
            $supervisor_eval = [
                'weekly_grade'        => $weekly_report['supervisor_grade'],
                'supervisor_comments' => $weekly_report['supervisor_comments'],
                'evaluated_at'        => $weekly_report['submitted_at'],
            ];
        }
    }
}

// Week Attendance
$week_att = ($week_start && $week_end)
    ? internship_attendance($db, $student_id, $week_start, $week_end)
    : ['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => 0];
$week_present         = $week_att['present'];
$week_expected        = $week_att['expected'];
$week_attendance_rate = $week_att['rate'];

// Total Graded Weeks
$graded_q = $db->prepare("SELECT COUNT(DISTINCT week_number) FROM weekly_reports WHERE student_id = ? AND supervisor_grade IS NOT NULL");
$graded_q->bind_param("i", $student_id);
$graded_q->execute();
$res = $graded_q->get_result();
$row = $res ? $res->fetch_row() : null;
$student_graded = (int) ($row[0] ?? 0);

// Fetch all weekly supervisor evaluations for quick status display in dropdown
$all_sup_evals = [];
$all_rep_evals = [];
$ase_q = $db->prepare("SELECT week_number, supervisor_grade AS weekly_grade, status FROM weekly_reports WHERE student_id = ?");
$ase_q->bind_param("i", $student_id);
$ase_q->execute();
$res = $ase_q->get_result();
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $wn = (int) $r['week_number'];
        if (!empty($r['weekly_grade'])) {
            $all_sup_evals[$wn] = $r['weekly_grade'];
        }
        $all_rep_evals[$wn] = $r['status'];
    }
}

// Grade definitions for Instructor Feedback
$grade_labels = [
    'excellent'         => ['Excellent',          'text-emerald-600', 'bg-emerald-50'],
    'good'              => ['Good',               'text-blue-600',    'bg-blue-50'],
    'average'           => ['Average',            'text-amber-600',   'bg-amber-50'],
    'needs_improvement' => ['Needs Improvement',  'text-red-600',     'bg-red-50'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student_name) ?> – Student Dashboard &amp; Review</title>
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
                font-size: 10pt !important;
                line-height: 1.35 !important;
            }
            aside, header, #sidebarBackdrop, #supervisorSidebarBackdrop,
            .print\:hidden, button, form button, .no-print, #week-dropdown, #week-menu {
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
            .max-w-7xl {
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
            .shadow-sm, .shadow-md, .shadow-lg, .shadow-xl, .shadow-2xl, .shadow-xs {
                box-shadow: none !important;
            }
            table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            th, td {
                border: 1px solid #cbd5e1 !important;
                padding: 5px 7px !important;
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
        if (e) e.stopPropagation();
        var menu = document.getElementById('week-menu');
        if (menu) menu.classList.toggle('hidden');
    }
    document.addEventListener('click', function (e) {
        var dd = document.getElementById('week-dropdown');
        var menu = document.getElementById('week-menu');
        if (dd && menu && !dd.contains(e.target)) {
            menu.classList.add('hidden');
        }
    });

    function toggleProfileDropdown(e) {
        if (e) e.stopPropagation();
        var dd = document.getElementById('profile-dropdown-menu');
        if (dd) dd.classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.contains(e.target) && btn && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });

    function toggleNotifDropdown() {
        var dd = document.getElementById('notif-dropdown');
        if (!dd) return;
        if (dd.classList.contains('show')) {
            dd.classList.remove('show');
            dd.style.opacity = '0';
            dd.style.visibility = 'hidden';
            dd.style.transform = 'translateY(-8px) scale(0.95)';
        } else {
            dd.classList.add('show');
            dd.style.opacity = '1';
            dd.style.visibility = 'visible';
            dd.style.transform = 'translateY(0) scale(1)';
        }
    }
    document.addEventListener('click', function(e) {
        var wrapper = document.getElementById('notif-bell-wrapper');
        var dd = document.getElementById('notif-dropdown');
        if (wrapper && dd && !wrapper.contains(e.target)) {
            dd.classList.remove('show');
            dd.style.opacity = '0';
            dd.style.visibility = 'hidden';
            dd.style.transform = 'translateY(-8px) scale(0.95)';
        }
    });

    function timeAgo(dateStr) {
        var date = new Date(dateStr);
        var now = new Date();
        var seconds = Math.floor((now - date) / 1000);
        if (seconds < 0 || seconds < 60) return 'Just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days === 1) return 'Yesterday';
        if (days < 7) return days + 'd ago';
        return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    }

    function updateNotifTimestamps() {
        document.querySelectorAll('[data-notif-time]').forEach(function(el) {
            el.textContent = timeAgo(el.getAttribute('data-notif-time'));
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateNotifTimestamps();
        setInterval(updateNotifTimestamps, 60000);
    });

    function markNotifRead(el) {
        var notifId = el.getAttribute('data-notif-id');
        var redirectUrl = el.getAttribute('data-redirect-url') || 'supervisor-dashboard.php';
        var fd = new FormData();
        fd.append('notification_id', notifId);
        fd.append('mark_notification_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) { updateNotifBadge(data.unread_count); })
          .catch(function() {});
        window.location.href = redirectUrl;
    }

    function markAllNotifsRead() {
        var fd = new FormData();
        fd.append('mark_all_notifications_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) { updateNotifBadge(data.unread_count); })
          .catch(function() {});
    }

    function updateNotifBadge(count) {
        var existing = document.getElementById('notif-badge');
        if (count > 0) {
            if (existing) {
                existing.textContent = count > 9 ? '9+' : count;
            } else {
                var bell = document.querySelector('#notif-bell-wrapper button');
                if (bell) {
                    var span = document.createElement('span');
                    span.id = 'notif-badge';
                    span.className = 'absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse';
                    span.textContent = count > 9 ? '9+' : count;
                    bell.appendChild(span);
                }
            }
        } else if (existing) {
            existing.remove();
        }
    }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN CONTAINER ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- ─── TOP HEADER ─── -->
        <header class="h-16 bg-white/90 backdrop-blur-xl border-b border-slate-200/80 flex items-center justify-between px-4 lg:px-8 shrink-0 shadow-xs relative z-[1050] print:hidden">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" onclick="toggleSupervisorSidebar()" class="lg:hidden p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle Navigation">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="my-students.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs" title="Back to My Students">
                    ← My Students
                </a>
                <div class="w-px h-5 bg-slate-200 hidden sm:block"></div>
                <div class="hidden sm:flex items-center gap-2 min-w-0">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Student Dashboard</span>
                    <span class="text-slate-300">/</span>
                    <h1 class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($student_name) ?></h1>
                </div>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <!-- Assigned Pill -->
                <div class="hidden md:flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full shadow-xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                </div>

                <!-- Selected Week Pill -->
                <div class="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 border border-indigo-200 rounded-full shadow-xs">
                    <span class="text-xs font-bold text-indigo-700">📆 Week <?= $selected_week ?>/<?= $total_internship_weeks ?></span>
                    <?php if ($selected_week === $auto_week): ?>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" title="Current Active Week"></span>
                    <?php endif; ?>
                </div>

                <!-- Notification Bell -->
                <div class="relative" id="notif-bell-wrapper">
                    <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-slate-100 rounded-xl transition cursor-pointer text-slate-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if ($unread_notif_count > 0): ?>
                        <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notif-dropdown" class="absolute right-0 top-full mt-1 w-[22rem] bg-white border border-slate-200 rounded-xl shadow-xl z-[1060] overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-gradient-to-br from-blue-50/80 to-white/60">
                            <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">Notifications</h4>
                            <?php if ($unread_notif_count > 0): ?>
                            <button onclick="markAllNotifsRead()" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition cursor-pointer">Mark all read</button>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            <?php if (!empty($recent_notifications)): ?>
                            <?php foreach ($recent_notifications as $notif): ?>
                            <?php $notif_url = !empty($notif['link']) ? $notif['link'] : notif_redirect_url($notif['type'] ?? 'info', $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null); ?>
                            <a href="<?= htmlspecialchars($notif_url) ?>" class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition border-b border-slate-100 last:border-0 cursor-pointer block no-underline" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs <?= !$notif['is_read'] ? 'font-bold text-slate-800' : 'font-medium text-slate-600' ?> leading-snug"><?= htmlspecialchars($notif['title']) ?></p>
                                    <p class="text-[11px] text-slate-500 mt-0.5 line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                                    <p class="text-[10px] text-slate-400 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M, h:i A') ?></p>
                                </div>
                            </a>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="p-8 text-center text-xs text-slate-400">No notifications yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Profile Dropdown -->
                <div class="relative shrink-0 pl-3 border-l border-slate-200" id="profileDropdownContainer">
                    <button
                        type="button"
                        onclick="toggleProfileDropdown(event)"
                        id="profile-avatar-btn"
                        class="flex items-center gap-2 p-1 hover:bg-teal-50 border border-transparent hover:border-teal-100 rounded-xl transition cursor-pointer group"
                    >
                        <?php if (!empty($_SESSION['profile_pic'])): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-8 h-8 rounded-xl object-cover border border-teal-200 shadow-xs">
                        <?php else: ?>
                        <div class="w-8 h-8 rounded-xl bg-[#005f73] flex items-center justify-center font-bold text-xs text-white shadow-xs">
                            <?= strtoupper(substr(format_supervisor_name($_SESSION['username'] ?? 'S'), 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-left hidden md:block">
                            <p class="font-semibold text-xs text-slate-800 leading-tight"><?= htmlspecialchars(format_supervisor_name($sup_name)) ?></p>
                            <p class="text-[11px] font-medium text-teal-700">Supervisor</p>
                        </div>
                        <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-xl border border-slate-200 py-1.5 z-50 divide-y divide-slate-100">
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
        </header>

        <!-- ─── PAGE CONTENT ─── -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-5">

                <!-- ═══ FLASH MESSAGES ═══ -->
                <?php if ($eval_msg === 'saved'): ?>
                <div class="p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-semibold flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-base">✓</span>
                        <span>Evaluation for <strong>Week <?= $selected_week ?></strong> has been successfully saved &amp; approved.</span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800 cursor-pointer">✕</button>
                </div>
                <?php elseif ($eval_msg === 'invalid_grade'): ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-semibold flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-base">⚠️</span>
                        <span>Please select a valid grade (A, B, C, D, or F).</span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800 cursor-pointer">✕</button>
                </div>
                <?php endif; ?>

                <!-- ════ TOP CARD: STUDENT IDENTITY & QUICK ACTIONS ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <div class="p-5 border-b border-slate-100">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <!-- Student Profile Strip -->
                            <div class="flex items-center gap-3.5 min-w-0">
                                <?php if ($profile_pic): ?>
                                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-12 h-12 rounded-xl object-cover border-2 border-indigo-100 shadow-xs shrink-0">
                                <?php else: ?>
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-base font-black shrink-0 shadow-xs">
                                    <?= strtoupper(substr($student_name, 0, 1)) ?>
                                </div>
                                <?php endif; ?>

                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h2 class="text-base font-black text-slate-800 leading-tight"><?= htmlspecialchars($student_name) ?></h2>
                                        <?php if ($not_started): ?>
                                        <span class="text-[11px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">Not Started</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-400 font-mono mt-0.5">
                                        Roll: <?= htmlspecialchars($student_roll ?: '—') ?> · <?= htmlspecialchars($profile['email'] ?? '') ?>
                                    </p>
                                    <div class="flex items-center gap-2 mt-2 flex-wrap text-xs">
                                        <?php if ($company_name): ?>
                                            <span class="font-semibold text-blue-700 bg-blue-50 px-2.5 py-0.5 rounded-lg border border-blue-200/60">🏢 <?= htmlspecialchars($company_name) ?></span>
                                        <?php endif; ?>
                                        <?php if ($job_role): ?>
                                            <span class="font-semibold text-violet-700 bg-violet-50 px-2.5 py-0.5 rounded-lg border border-violet-200/60">💼 <?= htmlspecialchars($job_role) ?></span>
                                        <?php endif; ?>
                                        <?php if ($major): ?>
                                            <span class="font-semibold text-slate-600 bg-slate-100 px-2.5 py-0.5 rounded-lg"><?= htmlspecialchars($major) ?></span>
                                        <?php endif; ?>
                                        <?php if ($academic_year): ?>
                                            <span class="font-semibold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-lg font-mono border border-indigo-200/60"><?= htmlspecialchars($academic_year) ?></span>
                                        <?php endif; ?>
                                        <?php if ($intern_start || $intern_end): ?>
                                            <span class="font-semibold text-slate-500 bg-slate-50 px-2.5 py-0.5 rounded-lg border border-slate-200/60">
                                                📅 <?= $intern_start ? (new DateTime($intern_start))->format('d M Y') : '—' ?> – <?= $intern_end ? (new DateTime($intern_end))->format('d M Y') : '—' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Action Buttons -->
                            <div class="flex items-center gap-2 flex-wrap shrink-0 print:hidden">
                                <a href="supervisor-reports.php?student_id=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs">
                                    📄 All Reports
                                </a>
                                <a href="../view_student_history.php?uid=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs" title="View complete 13-week log history">
                                    📜 Full History
                                </a>
                                <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#005f73] hover:bg-[#0a9396] text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                    <span>Print</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Strip -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-slate-100 bg-slate-50/50 text-xs">
                        <div class="p-3.5 text-center">
                            <p class="text-base font-black text-slate-800">⏱️ <?= $intern_hours ?>h <?= $intern_mins ?>m</p>
                            <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Total Hours Worked</p>
                        </div>
                        <div class="p-3.5 text-center">
                            <p class="text-base font-black text-slate-800">📋 <?= $intern_log_days ?></p>
                            <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Daily Logs Submitted</p>
                        </div>
                        <div class="p-3.5 text-center">
                            <p class="text-base font-black text-emerald-700">✅ <?= $intern_att['rate'] ?>%</p>
                            <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Attendance (<?= $intern_att['present'] ?>/<?= $intern_att['expected'] ?> d)</p>
                        </div>
                        <div class="p-3.5 text-center">
                            <p class="text-base font-black text-indigo-700">📊 <?= $student_graded ?> / <?= $total_internship_weeks ?></p>
                            <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Weeks Graded</p>
                        </div>
                    </div>

                    <!-- Week Selector & Navigation Summary Bar -->
                    <div class="px-5 py-3.5 bg-gradient-to-r from-slate-50 to-slate-100/50 flex items-center justify-between flex-wrap gap-3 text-xs border-t border-slate-100">
                        <!-- Left: Week Navigation Controls -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Prev Week -->
                            <?php if ($selected_week > 1): ?>
                            <a href="?id=<?= $student_id ?>&week=<?= $selected_week - 1 ?>" class="inline-flex items-center justify-center w-7 h-7 bg-white hover:bg-slate-100 border border-slate-200 text-slate-600 rounded-lg transition font-bold shadow-xs" title="Previous Week (Week <?= $selected_week - 1 ?>)">←</a>
                            <?php else: ?>
                            <span class="inline-flex items-center justify-center w-7 h-7 bg-slate-100 border border-slate-200 text-slate-300 rounded-lg cursor-not-allowed">←</span>
                            <?php endif; ?>

                            <!-- Week Select Dropdown -->
                            <div class="relative inline-flex items-center">
                                <span class="absolute left-2.5 pointer-events-none text-xs">📆</span>
                                <select id="weekSelectDropdown"
                                        onchange="window.location.href='view-student-dashboard.php?id=<?= $student_id ?>&week=' + this.value"
                                        class="bg-white border border-slate-200 hover:border-indigo-400 focus:border-indigo-500 rounded-xl pl-8 pr-8 py-1.5 text-xs font-bold text-slate-700 shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition cursor-pointer appearance-none">
                                    <?php if (!empty($weeks)): ?>
                                        <?php foreach ($weeks as $wn => $wr):
                                            $opt_s = new DateTime($wr['start']);
                                            $opt_e = (clone $opt_s)->modify('+4 days');
                                            $opt_range = $opt_s->format('d M') . ' – ' . $opt_e->format('d M Y');

                                            $status_text = '';
                                            if (isset($all_sup_evals[$wn])) {
                                                $status_text = ' — ✓ Graded (' . $all_sup_evals[$wn] . ')';
                                            } elseif (isset($all_rep_evals[$wn]) && $all_rep_evals[$wn] === 'approved_by_instructor') {
                                                $status_text = ' — ⏳ Ready for Review';
                                            } elseif (isset($all_rep_evals[$wn]) && $all_rep_evals[$wn] === 'rejected') {
                                                $status_text = ' — ❌ Instructor Rejected';
                                            } elseif ($wn === $auto_week) {
                                                $status_text = ' — ★ Current Week';
                                            }
                                        ?>
                                        <option value="<?= $wn ?>" <?= $wn === $selected_week ? 'selected' : '' ?>>
                                            Week <?= $wn ?> (<?= $opt_range ?>)<?= $status_text ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="1" selected>Week 1</option>
                                    <?php endif; ?>
                                </select>
                                <span class="absolute right-2.5 pointer-events-none text-slate-400 text-[10px]">▼</span>
                            </div>

                            <!-- Next Week -->
                            <?php if ($selected_week < $total_internship_weeks): ?>
                            <a href="?id=<?= $student_id ?>&week=<?= $selected_week + 1 ?>" class="inline-flex items-center justify-center w-7 h-7 bg-white hover:bg-slate-100 border border-slate-200 text-slate-600 rounded-lg transition font-bold shadow-xs" title="Next Week (Week <?= $selected_week + 1 ?>)">→</a>
                            <?php else: ?>
                            <span class="inline-flex items-center justify-center w-7 h-7 bg-slate-100 border border-slate-200 text-slate-300 rounded-lg cursor-not-allowed">→</span>
                            <?php endif; ?>

                            <!-- Date Range Badge -->
                            <?php if ($week_date_range): ?>
                            <span class="inline-flex items-center gap-1 font-semibold text-blue-700 bg-blue-50 border border-blue-200/60 px-2.5 py-1 rounded-lg">
                                📅 <?= $week_date_range ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Right: Weekly Stats & Tooltips -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Week Attendance -->
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg font-bold bg-white border border-slate-200 text-slate-700 shadow-xs">
                                <span class="text-emerald-600">✅</span> <?= $week_present ?>/<?= $week_expected ?> days (<?= $week_attendance_rate ?>%)
                            </span>

                            <!-- Present Tooltip -->
                            <div class="relative group">
                                <div class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200/80 text-emerald-800 rounded-lg font-bold cursor-pointer transition shadow-xs">
                                    <span>✅</span> <?= $total_present ?> Present
                                </div>
                                <div class="absolute right-0 top-full mt-1.5 w-60 bg-white border border-slate-200 rounded-xl shadow-xl z-50 hidden group-hover:block p-3">
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Present Dates (Total: <?= count($present_dates) ?>)</p>
                                    <div class="max-h-36 overflow-y-auto space-y-1 pr-1 text-xs text-slate-700">
                                        <?php if (!empty($present_dates)): ?>
                                            <?php foreach ($present_dates as $date): ?>
                                                <p>• <?= (new DateTime($date))->format('D, M d, Y') ?></p>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-slate-400 italic">No present days recorded.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Absent Tooltip -->
                            <div class="relative group">
                                <div class="inline-flex items-center gap-1 px-2.5 py-1 bg-red-50 hover:bg-red-100 border border-red-200/80 text-red-800 rounded-lg font-bold cursor-pointer transition shadow-xs">
                                    <span>❌</span> <?= $total_absent ?> Absent
                                </div>
                                <div class="absolute right-0 top-full mt-1.5 w-72 bg-white border border-slate-200 rounded-xl shadow-xl z-50 hidden group-hover:block p-3">
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Absent Dates (Total: <?= count($absent_logs) ?>)</p>
                                    <div class="max-h-36 overflow-y-auto space-y-1.5 pr-1 text-xs text-slate-700">
                                        <?php if (!empty($absent_logs)): ?>
                                            <?php foreach ($absent_logs as $log): ?>
                                                <p>• <?= (new DateTime($log['log_date']))->format('D, M d') ?> — <span class="text-slate-500"><?= htmlspecialchars($log['reason_for_absence'] ?: 'No reason') ?></span></p>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-slate-400 italic">No absences recorded.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════ SECTION 1: DAILY LOGS TABLE ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">📝</span>
                            Daily Logs – Week <?= $selected_week ?>
                        </h3>
                        <span class="text-xs text-slate-400 font-medium"><?= count($daily_logs) ?> log entry<?= count($daily_logs) !== 1 ? 's' : '' ?></span>
                    </div>

                    <?php if (!empty($daily_logs)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-slate-50/90 text-slate-500 font-bold uppercase tracking-wider text-[11px] border-b border-slate-100">
                                    <th class="px-4 py-2.5 text-left">Date</th>
                                    <th class="px-4 py-2.5 text-left">Status</th>
                                    <th class="px-4 py-2.5 text-left">Intended Task</th>
                                    <th class="px-4 py-2.5 text-left">Actual Tasks Performed</th>
                                    <th class="px-4 py-2.5 text-left">Tools</th>
                                    <th class="px-4 py-2.5 text-left">Learnt Skills</th>
                                    <th class="px-4 py-2.5 text-left">Duration</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($daily_logs as $log):
                                    $is_absent = ($log['attendance_status'] ?? 'present') === 'absent';
                                ?>
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-700 whitespace-nowrap">
                                        <?= htmlspecialchars((new DateTime($log['log_date']))->format('D, d M Y')) ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <?php if (!$is_absent): ?>
                                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200/60">✅ Present</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-red-700 bg-red-50 px-2 py-0.5 rounded-md border border-red-200/60" title="<?= htmlspecialchars($log['reason_for_absence'] ?? '') ?>">❌ Absent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 max-w-[140px] truncate font-medium" title="<?= $is_absent ? '' : htmlspecialchars($log['task_title'] ?? '') ?>">
                                        <?= $is_absent ? '—' : htmlspecialchars($log['task_title'] ?: '—') ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 max-w-[200px] truncate font-medium" title="<?= $is_absent ? '' : htmlspecialchars($log['tasks_performed'] ?? '') ?>">
                                        <?= $is_absent ? '—' : htmlspecialchars($log['tasks_performed'] ?: '—') ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 font-medium">
                                        <?= $is_absent ? '—' : htmlspecialchars($log['tools_used'] ?: '—') ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 font-medium">
                                        <?= $is_absent ? '—' : htmlspecialchars($log['learnt_skills'] ?: '—') ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-blue-600 font-bold whitespace-nowrap">
                                        <?= htmlspecialchars($log['calculated_duration'] ?: '00:00') ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-2">📭</div>
                        <p class="text-xs font-semibold text-slate-500">No daily logs recorded for Week <?= $selected_week ?>.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ════ SECTION 2: WEEKLY REFLECTION ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">📊</span>
                            Weekly Reflection – Week <?= $selected_week ?>
                        </h3>
                    </div>
                    <?php if ($reflection): ?>
                    <div class="p-5 space-y-3.5 text-xs">
                        <div>
                            <span class="font-bold text-slate-500 uppercase tracking-wider block mb-1">❓ 1. What was done? <span class="text-slate-400 font-normal">/ ဘာလုပ်သလဲ</span></span>
                            <div class="bg-slate-50/70 border border-slate-200/70 rounded-xl p-3 text-slate-700 leading-relaxed">
                                <?= nl2br(htmlspecialchars($reflection['what_done'] ?? '')) ?>
                            </div>
                        </div>
                        <div>
                            <span class="font-bold text-slate-500 uppercase tracking-wider block mb-1">⚙️ 2. How was it done? <span class="text-slate-400 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></span>
                            <div class="bg-slate-50/70 border border-slate-200/70 rounded-xl p-3 text-slate-700 leading-relaxed">
                                <?= nl2br(htmlspecialchars($reflection['how_done'] ?? '')) ?>
                            </div>
                        </div>
                        <div>
                            <span class="font-bold text-slate-500 uppercase tracking-wider block mb-1">🎯 3. Why was it done? <span class="text-slate-400 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></span>
                            <div class="bg-slate-50/70 border border-slate-200/70 rounded-xl p-3 text-slate-700 leading-relaxed">
                                <?= nl2br(htmlspecialchars($reflection['why_done'] ?? '')) ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-2">📭</div>
                        <p class="text-xs font-semibold text-slate-500">No weekly reflection submitted for Week <?= $selected_week ?>.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ════ SECTION 3: COMPANY INSTRUCTOR FEEDBACK (COMPACT SUMMARY CARD) ════ -->
                <?php if ($instructor_eval && in_array($instructor_eval['report_status'], ['approved_by_instructor', 'approved_by_supervisor'], true)):
                    $ig = $grade_labels[$instructor_eval['grade']] ?? ['—', 'text-slate-600', 'bg-slate-100'];
                ?>
                <div class="bg-white rounded-2xl border border-teal-200/80 shadow-xs overflow-hidden">
                    <div class="px-5 py-3 border-b border-teal-100 bg-gradient-to-r from-teal-50/80 to-white flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-xs font-bold text-teal-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-teal-100 text-teal-700 flex items-center justify-center text-xs">🏢</span>
                            Company Instructor Feedback
                        </h3>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700 border border-emerald-200">
                            ✅ Approved
                        </span>
                    </div>
                    <div class="p-4 space-y-2.5 text-xs">
                        <!-- Assessment Score -->
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-500 w-28 shrink-0">Assessment:</span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-md font-bold text-xs <?= $ig[1] ?> <?= $ig[2] ?> border border-current/20">
                                <?= htmlspecialchars($ig[0]) ?>
                            </span>
                        </div>

                        <!-- Instructor Comment -->
                        <div class="flex items-start gap-2">
                            <span class="font-bold text-slate-500 w-28 shrink-0 pt-0.5">Comment:</span>
                            <div class="flex-1 text-slate-700 leading-relaxed bg-slate-50/80 border border-slate-200/60 rounded-lg p-2.5">
                                <?= !empty($instructor_eval['comment']) ? nl2br(htmlspecialchars($instructor_eval['comment'])) : '<span class="italic text-slate-400">No written comments provided.</span>' ?>
                            </div>
                        </div>

                        <!-- Digital Signature -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold text-slate-500 w-28 shrink-0">Digital Signature:</span>
                            <div class="flex items-center gap-2">
                                <?php if (!empty($instructor_eval['signature_value'])): ?>
                                    <?php if ($instructor_eval['signature_type'] === 'typed'): ?>
                                        <span class="px-2.5 py-0.5 bg-slate-50 border border-slate-200 rounded-md font-bold text-slate-800 inline-block" style="font-family:'Great Vibes',cursive; font-size:1.35rem; line-height:1;"><?= htmlspecialchars($instructor_eval['signature_value']) ?></span>
                                    <?php else: ?>
                                        <img src="../uploads/signatures/<?= htmlspecialchars($instructor_eval['signature_value']) ?>" alt="Signature" class="h-6 object-contain inline-block bg-slate-50 border border-slate-200 rounded-md px-1">
                                    <?php endif; ?>
                                    <span class="inline-flex items-center gap-0.5 text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-full text-[10px] font-bold">✓ Signed</span>
                                <?php else: ?>
                                    <span class="italic text-slate-400">No signature recorded</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Evaluated Date -->
                        <div class="flex items-center gap-2 pt-1 border-t border-slate-100 text-[11px] text-slate-400">
                            <span class="font-bold text-slate-500 w-28 shrink-0">Evaluated:</span>
                            <span><?= (new DateTime($instructor_eval['evaluated_at']))->format('d M Y, h:i A') ?></span>
                        </div>
                    </div>
                </div>

                <?php elseif ($instructor_eval && $instructor_eval['report_status'] === 'rejected'): ?>
                <div class="bg-white rounded-2xl border border-red-200/80 shadow-xs overflow-hidden">
                    <div class="px-5 py-3 border-b border-red-100 bg-gradient-to-r from-red-50/80 to-white flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-xs font-bold text-red-900 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-red-100 text-red-700 flex items-center justify-center text-xs">🏢</span>
                            Company Instructor Feedback
                        </h3>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700 border border-red-200">
                            ❌ Rejected
                        </span>
                    </div>
                    <div class="p-4 space-y-2.5 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-500 w-28 shrink-0">Status:</span>
                            <span class="font-bold text-red-700">Rejected by Instructor (Revision required)</span>
                        </div>
                        <?php if ($instructor_eval['instructor_comments']): ?>
                        <div class="flex items-start gap-2">
                            <span class="font-bold text-slate-500 w-28 shrink-0 pt-0.5">Reason:</span>
                            <div class="flex-1 text-red-700 bg-red-50/70 border border-red-200/60 rounded-lg p-2.5 leading-relaxed">
                                <?= nl2br(htmlspecialchars($instructor_eval['instructor_comments'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="flex items-center gap-2 pt-1 border-t border-slate-100 text-[11px] text-slate-400">
                            <span class="font-bold text-slate-500 w-28 shrink-0">Evaluated:</span>
                            <span><?= (new DateTime($instructor_eval['evaluated_at']))->format('d M Y, h:i A') ?></span>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center text-xs">🏢</span>
                            Company Instructor Feedback
                        </h3>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                            ⏳ Waiting for Instructor
                        </span>
                    </div>
                    <div class="p-5 text-center">
                        <p class="text-xs text-slate-500 font-medium">Instructor has not evaluated Week <?= $selected_week ?> yet.</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Report is awaiting Company Instructor review &amp; signature.</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ════ SECTION 4: UNIVERSITY SUPERVISOR EVALUATION FORM ════ -->
                <div class="bg-white rounded-2xl border border-indigo-200/80 shadow-xs overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-indigo-100 bg-gradient-to-r from-indigo-50/80 to-white flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-xs font-bold text-indigo-950 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs">🎓</span>
                            University Supervisor Evaluation – Week <?= $selected_week ?>
                        </h3>
                        <?php if ($supervisor_eval): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-100 text-indigo-700 border border-indigo-200">
                            Grade: <?= htmlspecialchars($supervisor_eval['weekly_grade']) ?>
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-700 border border-amber-200">
                            ⏳ Awaiting Grade
                        </span>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="p-5 space-y-4 text-xs">
                        <?php if ($supervisor_eval): ?>
                        <div class="flex items-center gap-2 text-xs text-indigo-700 bg-indigo-50/70 border border-indigo-200/60 px-3.5 py-2 rounded-xl font-medium">
                            <span class="font-bold">✏️ Existing Evaluation:</span> Evaluated on <?= (new DateTime($supervisor_eval['evaluated_at']))->format('d M Y, h:i A') ?>. You can update the grade or comments below.
                        </div>
                        <?php else: ?>
                        <div class="flex items-center gap-2 text-xs text-amber-800 bg-amber-50/70 border border-amber-200/60 px-3.5 py-2 rounded-xl font-medium">
                            <span class="font-bold">⚠️ Notice:</span> Please select a university grade (A–F) and write your review feedback below.
                        </div>
                        <?php endif; ?>

                        <!-- Grade Selector -->
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-2">Weekly Grade</label>
                            <div class="grid grid-cols-5 gap-2">
                                <?php
                                $existing_grade = $supervisor_eval['weekly_grade'] ?? 'C';
                                $grade_defs = [
                                    'A' => ['Excellent',     'text-emerald-600', 'border-emerald-200', 'hover:border-emerald-400'],
                                    'B' => ['Good',          'text-blue-600',    'border-blue-200',    'hover:border-blue-400'],
                                    'C' => ['Satisfactory',  'text-slate-700',   'border-slate-200',   'hover:border-indigo-400'],
                                    'D' => ['Pass',          'text-amber-600',   'border-amber-200',   'hover:border-amber-400'],
                                    'F' => ['Fail',          'text-red-500',     'border-red-200',     'hover:border-red-400']
                                ];
                                foreach ($grade_defs as $g => $info):
                                    $isSelected = ($g === $existing_grade);
                                ?>
                                <label class="flex flex-col items-center gap-1 p-2.5 bg-gradient-to-b from-white to-slate-50/80 border <?= $isSelected ? 'border-indigo-600 ring-2 ring-indigo-500/20 bg-indigo-50/30' : 'border-slate-200' ?> rounded-xl cursor-pointer hover:shadow-xs transition text-center">
                                    <input type="radio" name="weekly_grade" value="<?= $g ?>" <?= $isSelected ? 'checked' : '' ?> class="accent-indigo-600 text-xs">
                                    <span class="text-base font-black <?= $info[1] ?>"><?= $g ?></span>
                                    <span class="text-[10px] text-slate-500 font-semibold uppercase tracking-wider"><?= $info[0] ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Supervisor Comments -->
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">Supervisor Assessment &amp; Feedback Comments</label>
                            <textarea name="supervisor_comments" rows="4" placeholder="Write university supervisor feedback, recommendations, or grading comments for the student…"
                                class="w-full bg-white border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition resize-none shadow-xs"><?= htmlspecialchars($supervisor_eval['supervisor_comments'] ?? '') ?></textarea>
                        </div>

                        <!-- Submit Button -->
                        <div>
                            <button type="submit" name="submit_sup_eval" class="w-full py-2.5 px-4 bg-gradient-to-r from-[#005f73] via-teal-700 to-[#0a9396] hover:from-[#004e5f] hover:to-[#087f82] text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center justify-center gap-2">
                                <span><?= $supervisor_eval ? '💾 Update University Grade' : '✅ Submit & Approve Evaluation' ?></span>
                            </button>
                        </div>
                    </form>

                    <div class="px-5 py-2.5 border-t border-slate-100 bg-slate-50 text-center">
                        <p class="text-[11px] text-slate-400 font-medium">
                            This evaluation completes the university review process for Week <?= $selected_week ?>.
                        </p>
                    </div>
                </div>

                <div class="text-center text-[11px] text-slate-400 py-2 font-medium">Powered by InternReport</div>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
