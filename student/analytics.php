<?php
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}

$internship_id = $user_id;
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
$intern_start     = $profile_row['internship_start_date'] ?? null;
$intern_end       = $profile_row['internship_end_date'] ?? null;
$student_name     = $profile_row['full_name'] ?: $username;
$student_roll     = $profile_row['student_roll'] ?? '';
$supervisor_name  = $profile_row['supervisor_name'] ?? '—';
$profile_pic      = $profile_row['profile_pic'] ?? '';
$instructor_name  = $profile_row['instructor_name'] ?: '—';

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

$selected_week = 1;
if (isset($_GET['week'])) {
    $w = (int)$_GET['week'];
    if (isset($weeks[$w])) $selected_week = $w;
}

// ══════════════════════════════════════════════════════════════════════
// ANALYTICS DATA
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

// Weeks completed
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

$total_weeks = count($weeks);

// Attendance counts
$present_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status = 'present'");
$present_count = ($present_r && $present_r->num_rows > 0) ? (int) $present_r->fetch_row()[0] : 0;

$absent_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status IN ('absent','leave')");
$absent_count = ($absent_r && $absent_r->num_rows > 0) ? (int) $absent_r->fetch_row()[0] : 0;

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

// Attendance breakdown for donut chart
$att_all_r = $conn->query("SELECT attendance_status, COUNT(*) as cnt FROM daily_logs WHERE internship_id = {$esc_iid} GROUP BY attendance_status");
$att_breakdown = [];
if ($att_all_r) { while ($row = $att_all_r->fetch_assoc()) { $att_breakdown[$row['attendance_status']] = (int) $row['cnt']; } }

// Recent activity (last 5 logs)
$recent_activity_r = $conn->query("SELECT log_date, attendance_status, task_title, calculated_duration FROM daily_logs WHERE internship_id = {$esc_iid} ORDER BY log_date DESC LIMIT 5");
$recent_activities = [];
if ($recent_activity_r) { while ($row = $recent_activity_r->fetch_assoc()) { $recent_activities[] = $row; } }

// Evaluation history
$notif_r = $conn->query("SELECT * FROM report_evaluations WHERE student_id = {$esc_iid} ORDER BY evaluated_at DESC LIMIT 5");
$recent_evaluations = [];
if ($notif_r) { while ($row = $notif_r->fetch_assoc()) { $recent_evaluations[] = $row; } }

// Notifications
$unread_notif_r = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$esc_uid} AND is_read = 0");
$unread_notif_count = ($unread_notif_r && $unread_notif_r->num_rows > 0) ? (int) $unread_notif_r->fetch_row()[0] : 0;

$recent_notifs_r = $conn->query("SELECT * FROM notifications WHERE user_id = {$esc_uid} ORDER BY created_at DESC LIMIT 10");
$recent_notifications = [];
if ($recent_notifs_r) { while ($row = $recent_notifs_r->fetch_assoc()) { $recent_notifications[] = $row; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics – Intern Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
    function toggleNotifDropdown() {
        var dd = document.getElementById('notif-dropdown');
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
        if (seconds < 60) return 'Just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    }

    function updateNotifTimestamps() {
        document.querySelectorAll('[data-notif-time]').forEach(function(el) {
            el.textContent = timeAgo(el.getAttribute('data-notif-time'));
        });
    }
    updateNotifTimestamps();
    setInterval(updateNotifTimestamps, 60000);

    // ── CHART INITIALIZATION ──
    document.addEventListener('DOMContentLoaded', function () {
        // Weekly Hours Bar Chart
        var hoursCtx = document.getElementById('weeklyHoursChart');
        if (hoursCtx) {
            new Chart(hoursCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($weekly_hours_labels, JSON_HEX_TAG) ?>,
                    datasets: [{
                        label: 'Hours Worked',
                        data: <?= json_encode($weekly_hours_data, JSON_HEX_TAG) ?>,
                        backgroundColor: 'rgba(99, 102, 241, 0.8)',
                        borderColor: 'rgb(99, 102, 241)',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleFont: { size: 11, weight: 'bold' },
                            bodyFont: { size: 11 },
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(ctx) { return ctx.parsed.y + ' hours'; }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(148, 163, 184, 0.1)' },
                            ticks: { font: { size: 10, weight: '600' }, color: '#94a3b8' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10, weight: '600' }, color: '#94a3b8' }
                        }
                    }
                }
            });
        }

        // Attendance Donut Chart
        var attCtx = document.getElementById('attendanceChart');
        if (attCtx) {
            var presentCount = <?= (int)($att_breakdown['present'] ?? 0) ?>;
            var absentCount  = <?= (int)($att_breakdown['absent'] ?? 0) + (int)($att_breakdown['leave'] ?? 0) ?>;
            new Chart(attCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent'],
                    datasets: [{
                        data: [presentCount, absentCount],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderColor: ['#ffffff', '#ffffff'],
                        borderWidth: 3,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleFont: { size: 11, weight: 'bold' },
                            bodyFont: { size: 11 },
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(ctx) { return ctx.label + ': ' + ctx.parsed + ' days'; }
                            }
                        }
                    }
                }
            });
        }
    });
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Dancing+Script:wght@400;700&family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
    .active-nav { background: rgba(255,255,255,0.15); color: #fff; border-right: 3px solid #a78bfa; }

    .glass { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); }
    .glass-strong { background: rgba(255,255,255,0.72); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.5); }
    .glass-sidebar { background: rgba(15,23,42,0.82); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-right: 1px solid rgba(255,255,255,0.08); }
    .glass-header { background: rgba(255,255,255,0.6); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid rgba(255,255,255,0.4); }
    .glass-card { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); box-shadow: 0 8px 32px rgba(0,0,0,0.06); }
    .glass-card:hover { background: rgba(255,255,255,0.68); box-shadow: 0 8px 32px rgba(0,0,0,0.1); }
    .glass-modal { background: rgba(255,255,255,0.85); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.6); }

    .glow-indigo { box-shadow: 0 4px 20px rgba(99,102,241,0.25); }
    .glow-emerald { box-shadow: 0 4px 20px rgba(16,185,129,0.25); }
    .glow-purple { box-shadow: 0 4px 20px rgba(168,85,247,0.25); }

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
            <span class="text-sm font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📝</span> Dashboard
            </a>
            <a href="analytics.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition" data-section="analytics">
                <span>📊</span> Analytics
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📅</span> Public Holidays
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📋</span> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="px-3 pb-2">
            <div class="bg-gradient-to-br from-violet-500/80 to-purple-600/80 backdrop-blur-sm rounded-xl p-3 text-white border border-white/20">
                <p class="text-[10px] font-bold uppercase tracking-wider opacity-80 mb-1">Internship Progress</p>
                <div class="w-full bg-white/20 rounded-full h-1.5 mb-1.5">
                    <div class="bg-white rounded-full h-1.5 transition-all duration-500 shadow-sm" style="width: <?= $total_weeks > 0 ? min(round(($weeks_completed / $total_weeks) * 100), 100) : 0 ?>%"></div>
                </div>
                <p class="text-[10px] font-bold"><?= $weeks_completed ?>/<?= $total_weeks ?> Weeks</p>
            </div>
        </div>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-14 glass-header flex items-center justify-between px-6 shrink-0 relative z-50">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-slate-700">📋 InternReport</span>
                <span class="w-px h-5 bg-slate-300/50"></span>
                <span class="text-xs font-semibold text-slate-500">Analytics</span>
            </div>
            <div class="relative group">
                <div class="relative mr-3 inline-block" id="notif-bell-wrapper">
                    <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-white/30 rounded-xl transition cursor-pointer">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if ($unread_notif_count > 0): ?>
                        <span class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[8px] font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notif-dropdown" class="absolute right-0 top-full mt-1 w-80 glass-modal rounded-xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                        <div class="p-3 border-b border-white/30 bg-gradient-to-br from-violet-50/80 to-white/60">
                            <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">Notifications</h4>
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            <?php if (!empty($recent_notifications)): ?>
                            <?php foreach ($recent_notifications as $notif): ?>
                            <div class="flex items-start gap-2.5 px-3 py-3 <?= !$notif['is_read'] ? 'bg-violet-50/40' : 'hover:bg-white/40' ?> transition-all duration-150 border-b border-white/20 last:border-0 group">
                                <div class="w-8 h-8 rounded-full <?= $notif['type'] === 'instructor_approved' ? 'bg-emerald-100 text-emerald-600' : ($notif['type'] === 'instructor_rejected' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600') ?> flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-sm">
                                    <?= $notif['type'] === 'instructor_approved' ? '✓' : ($notif['type'] === 'instructor_rejected' ? '✕' : 'ℹ') ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-bold <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-600' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                                    <p class="text-[10px] text-slate-400 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                                    <p class="text-[9px] text-slate-300 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="p-8 text-center">
                                <p class="text-xs font-semibold text-slate-400">No notifications yet</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="relative group/profile inline-block">
                    <button class="flex items-center gap-2.5 hover:bg-white/30 rounded-xl px-2 py-1.5 transition">
                        <?php if ($profile_pic): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover border-2 border-white/60 shadow-sm shrink-0">
                        <?php else: ?>
                        <span class="w-8 h-8 rounded-full bg-violet-100 text-violet-600 flex items-center justify-center text-xs font-bold shrink-0"><?= strtoupper(substr($student_name, 0, 1)) ?></span>
                        <?php endif; ?>
                        <div class="text-left hidden sm:block">
                            <p class="text-xs font-bold text-slate-700 leading-tight"><?= htmlspecialchars($student_name) ?></p>
                            <?php if ($student_roll): ?>
                            <p class="text-[10px] font-mono font-bold text-violet-500 leading-tight"><?= htmlspecialchars($student_roll) ?></p>
                            <?php endif; ?>
                        </div>
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="absolute right-0 top-full mt-1 w-56 glass-modal rounded-xl shadow-xl opacity-0 invisible group-hover/profile:opacity-100 group-hover/profile:visible transition-all z-50 overflow-hidden">
                        <div class="py-1">
                            <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-600 hover:bg-white/40 transition">
                                <span class="w-5 text-center text-sm">👤</span> My Profile
                            </a>
                            <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-500 hover:bg-red-50/40 transition">
                                <span class="w-5 text-center text-sm">🚪</span> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">

            <!-- Analytics Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-lg font-black text-slate-800">Analytics Overview</h2>
                    <p class="text-xs text-slate-400 font-medium">Track your internship performance and progress</p>
                </div>
                <button onclick="window.print()" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition shadow-sm cursor-pointer">
                    🖨️ Print Report
                </button>
            </div>

            <!-- Analytics Summary Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-4 text-white shadow-lg shadow-blue-500/20">
                    <p class="text-[10px] font-bold uppercase tracking-wider opacity-80">Total Hours</p>
                    <p class="text-3xl font-black mt-1"><?= $total_hours ?><span class="text-lg">h <?= str_pad($total_mins, 2, '0', STR_PAD_LEFT) ?>m</span></p>
                </div>
                <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-4 text-white shadow-lg shadow-indigo-500/20">
                    <p class="text-[10px] font-bold uppercase tracking-wider opacity-80">Logs Submitted</p>
                    <p class="text-3xl font-black mt-1"><?= $total_logs_count ?></p>
                </div>
                <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl p-4 text-white shadow-lg shadow-emerald-500/20">
                    <p class="text-[10px] font-bold uppercase tracking-wider opacity-80">Attendance</p>
                    <p class="text-3xl font-black mt-1"><?= $attendance_rate ?><span class="text-lg">%</span></p>
                </div>
                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-4 text-white shadow-lg shadow-purple-500/20">
                    <p class="text-[10px] font-bold uppercase tracking-wider opacity-80">Reflections</p>
                    <p class="text-3xl font-black mt-1"><?= $total_reflections_count ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Weekly Hours Chart -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="p-1 bg-blue-50 text-blue-600 rounded">📊</span> Weekly Hours Log
                    </h3>
                    <div style="position:relative; height:280px;">
                        <canvas id="weeklyHoursChart"></canvas>
                    </div>
                </div>

                <!-- Attendance Breakdown Chart -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="p-1 bg-emerald-50 text-emerald-600 rounded">🎯</span> Attendance Breakdown
                    </h3>
                    <div style="position:relative; height:280px; display:flex; align-items:center; justify-content:center;">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                    <div class="flex items-center justify-center gap-4 mt-4">
                        <span class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600">
                            <span class="w-3 h-3 rounded-full bg-emerald-500"></span> Present (<?= $present_count ?>)
                        </span>
                        <span class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600">
                            <span class="w-3 h-3 rounded-full bg-red-500"></span> Absent (<?= $absent_count ?>)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Week-by-Week Progress Table -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                    <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-purple-50 text-purple-500 flex items-center justify-center text-sm">📆</span> Week-by-Week Progress
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-[11px]">
                                <th class="px-5 py-3 text-left">Week</th>
                                <th class="px-5 py-3 text-left">Date Range</th>
                                <th class="px-5 py-3 text-center">Logs</th>
                                <th class="px-5 py-3 text-center">Hours</th>
                                <th class="px-5 py-3 text-center">Reflection</th>
                                <th class="px-5 py-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!empty($weeks)): ?>
                                <?php foreach ($weeks as $wn => $wr): ?>
                                <?php
                                    $esc_wrs = $conn->real_escape_string($wr['start']);
                                    $esc_wre = $conn->real_escape_string($wr['end']);
                                    $esc_wn2 = $conn->real_escape_string($wn);

                                    $wl_r = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN attendance_status='present' THEN 1 ELSE 0 END) AS present_days FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_wrs}' AND '{$esc_wre}'");
                                    $wl_data = ($wl_r && $wl_r->num_rows > 0) ? $wl_r->fetch_assoc() : null;
                                    $wl_count = $wl_data ? (int)$wl_data['total'] : 0;

                                    $wr_r = $conn->query("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = {$esc_iid} AND week_number = {$esc_wn2}");
                                    $has_reflection = ($wr_r && $wr_r->num_rows > 0) ? ((int) $wr_r->fetch_row()[0] > 0) : false;

                                    $we_r = $conn->query("SELECT report_status FROM report_evaluations WHERE student_id = {$esc_iid} AND week_number = {$esc_wn2}");
                                    $eval_status = ($we_r && $we_r->num_rows > 0) ? $we_r->fetch_row()[0] : null;

                                    $wh_r2 = $conn->query("SELECT calculated_duration FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_wrs}' AND '{$esc_wre}'");
                                    $wk_mins = 0;
                                    if ($wh_r2) {
                                        while ($d_row = $wh_r2->fetch_assoc()) {
                                            $pp = explode(':', $d_row['calculated_duration']);
                                            if (count($pp) === 2) $wk_mins += ((int)$pp[0] * 60) + (int)$pp[1];
                                        }
                                    }
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150 <?= $wn === $selected_week ? 'bg-indigo-50/50' : '' ?>">
                                    <td class="px-5 py-3">
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600">
                                            <span class="w-6 h-6 rounded-lg bg-indigo-100 flex items-center justify-center text-[10px] font-black"><?= $wn ?></span>
                                            Week <?= $wn ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-[11px] text-slate-500 font-medium"><?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M') ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <span class="text-xs font-bold <?= $wl_count >= 3 ? 'text-emerald-600' : 'text-slate-600' ?>"><?= $wl_count ?> days</span>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <span class="text-xs font-bold font-mono text-blue-600"><?= floor($wk_mins / 60) ?>h <?= str_pad($wk_mins % 60, 2, '0', STR_PAD_LEFT) ?>m</span>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <?php if ($has_reflection): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">✅ Submitted</span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <?php if ($eval_status === 'approved_by_instructor' || $eval_status === 'approved_by_supervisor'): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-200">✅ Approved</span>
                                        <?php elseif ($eval_status === 'rejected'): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-red-600 bg-red-50 px-2 py-1 rounded-full border border-red-200">❌ Rejected</span>
                                        <?php elseif ($eval_status === 'pending'): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded-full border border-amber-200">⏳ Pending</span>
                                        <?php else: ?>
                                        <span class="text-[10px] font-bold text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity & Evaluation History -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Activity -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="p-1 bg-blue-50 text-blue-600 rounded">🕐</span> Recent Activity
                    </h3>
                    <?php if (!empty($recent_activities)): ?>
                    <div class="space-y-3">
                        <?php foreach ($recent_activities as $act): ?>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100 hover:bg-slate-100 transition">
                            <div class="w-8 h-8 rounded-lg <?= $act['attendance_status'] === 'present' ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600' ?> flex items-center justify-center text-xs font-bold shrink-0">
                                <?= $act['attendance_status'] === 'present' ? '✅' : '❌' ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($act['task_title'] ?: 'No task title') ?></p>
                                <p class="text-[10px] text-slate-400"><?= (new DateTime($act['log_date']))->format('D, d M Y') ?> · <?= htmlspecialchars($act['calculated_duration']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-slate-400 text-center py-6">No activity recorded yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Evaluation History -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="p-1 bg-amber-50 text-amber-600 rounded">📝</span> Evaluation History
                    </h3>
                    <?php if (!empty($recent_evaluations)): ?>
                    <div class="space-y-3">
                        <?php foreach ($recent_evaluations as $ev): ?>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100">
                            <div class="w-8 h-8 rounded-lg <?= $ev['report_status'] === 'approved_by_instructor' || $ev['report_status'] === 'approved_by_supervisor' ? 'bg-emerald-100 text-emerald-600' : ($ev['report_status'] === 'rejected' ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600') ?> flex items-center justify-center text-xs font-bold shrink-0">
                                <?= $ev['report_status'] === 'approved_by_instructor' || $ev['report_status'] === 'approved_by_supervisor' ? '✅' : ($ev['report_status'] === 'rejected' ? '❌' : '⏳') ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-bold text-slate-700">Week <?= (int)$ev['week_number'] ?> — <?= ucfirst(str_replace('_', ' ', $ev['report_status'])) ?></p>
                                <p class="text-[10px] text-slate-400"><?= $ev['instructor_comments'] ? htmlspecialchars(substr($ev['instructor_comments'], 0, 80)) . (strlen($ev['instructor_comments']) > 80 ? '…' : '') : 'No comments' ?></p>
                            </div>
                            <span class="text-[10px] font-bold text-slate-400 shrink-0"><?= (new DateTime($ev['evaluated_at']))->format('d M') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-slate-400 text-center py-6">No evaluations yet.</p>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

</body>
</html>
