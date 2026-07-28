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

// Weekly hours data for chart (all weeks from Week 1)
$weekly_hours_data = [];
$weekly_hours_labels = [];
if (!empty($weeks)) {
    foreach ($weeks as $wn => $wr) {
        $esc_wn_s = $conn->real_escape_string($wr['start']);
        $esc_wn_e = $conn->real_escape_string($wr['end']);
        $wh_r = $conn->query("SELECT calculated_duration FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_wn_s}' AND '{$esc_wn_e}'");
        $week_mins = 0;
        if ($wh_r) {
            while ($row = $wh_r->fetch_assoc()) {
                $p = explode(':', $row['calculated_duration']);
                if (count($p) === 2) $week_mins += ((int)$p[0] * 60) + (int)$p[1];
            }
        }
        $weekly_hours_labels[] = 'Week ' . $wn;
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
    // ── CHART INITIALIZATION ──
    document.addEventListener('DOMContentLoaded', function () {
        // ── Full dataset from PHP ──
        var allLabels = <?= json_encode($weekly_hours_labels, JSON_HEX_TAG) ?>;
        var allData   = <?= json_encode($weekly_hours_data, JSON_HEX_TAG) ?>;

        // ── Weekly Hours Bar Chart ──
        var hoursCtx = document.getElementById('weeklyHoursChart');
        var weeklyChart = null;
        if (hoursCtx) {
            weeklyChart = new Chart(hoursCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: allLabels,
                    datasets: [{
                        label: 'Hours Worked',
                        data: allData,
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
                    animation: { duration: 400, easing: 'easeInOutQuart' },
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

        // ── Week Filter Dropdown ──
        var filterBtn     = document.getElementById('weekFilterBtn');
        var filterMenu    = document.getElementById('weekFilterMenu');
        var filterLabel   = document.getElementById('weekFilterLabel');
        var filterChevron = document.getElementById('weekFilterChevron');
        var filterItems   = document.querySelectorAll('.week-filter-item');

        if (filterBtn && filterMenu) {
            filterBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = !filterMenu.classList.contains('hidden');
                if (isOpen) {
                    filterMenu.classList.add('hidden');
                    filterMenu.style.opacity   = '0';
                    filterMenu.style.transform  = 'scale(0.95)';
                    filterChevron.style.transform = '';
                } else {
                    filterMenu.classList.remove('hidden');
                    requestAnimationFrame(function () {
                        filterMenu.style.opacity   = '1';
                        filterMenu.style.transform  = 'scale(1)';
                    });
                    filterChevron.style.transform = 'rotate(180deg)';
                }
            });

            filterItems.forEach(function (item) {
                item.addEventListener('click', function (e) {
                    e.preventDefault();
                    var filter = this.getAttribute('data-filter');
                    var slicedLabels, slicedData;

                    if (filter === '1-7') {
                        slicedLabels = allLabels.slice(0, 7);
                        slicedData   = allData.slice(0, 7);
                        filterLabel.textContent = 'Filtered: Week 1 - 7';
                    } else if (filter === '8-14') {
                        slicedLabels = allLabels.slice(7, 14);
                        slicedData   = allData.slice(7, 14);
                        filterLabel.textContent = 'Filtered: Week 8 - 14';
                    } else {
                        slicedLabels = allLabels;
                        slicedData   = allData;
                        filterLabel.textContent = 'Select Weeks';
                    }

                    if (weeklyChart) {
                        weeklyChart.data.labels   = slicedLabels;
                        weeklyChart.data.datasets[0].data = slicedData;
                        weeklyChart.update();
                    }

                    filterMenu.classList.add('hidden');
                    filterMenu.style.opacity   = '0';
                    filterMenu.style.transform  = 'scale(0.95)';
                    filterChevron.style.transform = '';
                });
            });

            document.addEventListener('click', function () {
                filterMenu.classList.add('hidden');
                filterMenu.style.opacity   = '0';
                filterMenu.style.transform  = 'scale(0.95)';
                filterChevron.style.transform = '';
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
        body { font-family: 'Inter', sans-serif; }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .animated-bg { background: linear-gradient(-45deg, #e0e7ff, #ede9fe, #fce7f3, #dbeafe, #d1fae5); background-size: 400% 400%; animation: gradientShift 20s ease infinite; }
        .nav-link { color: rgba(255,255,255,0.55); font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .active-nav { background: #9333ea; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(147,51,234,0.3); }
        @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }

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
            <span class="font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-3">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="dashboard" onclick="showDashboard()">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📝</span> Dashboard
            </a>
            <a href="analytics.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="analytics">
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
        <div class="px-3 pb-2">
            <div class="bg-gradient-to-br from-violet-500/80 to-purple-600/80 backdrop-blur-sm rounded-xl p-3 text-white border border-white/20">
                <p class="text-label font-bold uppercase tracking-wider opacity-80 mb-1">Internship Progress</p>
                <div class="w-full bg-white/20 rounded-full h-1.5 mb-1.5">
                    <div class="bg-white rounded-full h-1.5 transition-all duration-500 shadow-sm" style="width: <?= $total_weeks > 0 ? min(round(($weeks_completed / $total_weeks) * 100), 100) : 0 ?>%"></div>
                </div>
                <p class="text-label font-bold"><?= $weeks_completed ?>/<?= $total_weeks ?> Weeks</p>
            </div>
        </div>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'Analytics'; include '../includes/student-topbar.php'; ?>

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
                    <p class="text-label font-bold uppercase tracking-wider opacity-80">Total Hours</p>
                    <p class="text-3xl font-black mt-1"><?= $total_hours ?><span class="text-lg">h <?= str_pad($total_mins, 2, '0', STR_PAD_LEFT) ?>m</span></p>
                </div>
                <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl p-4 text-white shadow-lg shadow-indigo-500/20">
                    <p class="text-label font-bold uppercase tracking-wider opacity-80">Logs Submitted</p>
                    <p class="text-3xl font-black mt-1"><?= $total_logs_count ?></p>
                </div>
                <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl p-4 text-white shadow-lg shadow-emerald-500/20">
                    <p class="text-label font-bold uppercase tracking-wider opacity-80">Attendance</p>
                    <p class="text-3xl font-black mt-1"><?= $attendance_rate ?><span class="text-lg">%</span></p>
                </div>
                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-4 text-white shadow-lg shadow-purple-500/20">
                    <p class="text-label font-bold uppercase tracking-wider opacity-80">Reflections</p>
                    <p class="text-3xl font-black mt-1"><?= $total_reflections_count ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch w-full mb-6">
                <!-- Weekly Hours Chart -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 h-full flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-3 mb-4">
                            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <span class="p-1 bg-blue-50 text-blue-600 rounded">📊</span> Weekly Hours Log
                            </h3>
                            <div class="relative">
                                <button id="weekFilterBtn" class="bg-white border border-gray-200 text-gray-700 rounded-lg px-3 py-1.5 text-sm font-medium hover:bg-gray-50 flex items-center space-x-2 shadow-sm relative transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-purple-500/30">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span id="weekFilterLabel">Select Weeks</span>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" id="weekFilterChevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div id="weekFilterMenu" class="absolute right-0 top-full mt-2 w-48 bg-white border border-gray-100 rounded-xl shadow-lg py-1 z-50 hidden transition-all duration-200 origin-top-right scale-95 opacity-0">
                                    <div class="px-4 py-2 border-b border-gray-100">
                                        <p class="text-label font-bold text-gray-400 uppercase tracking-wider">Filter by Range</p>
                                    </div>
                                    <a href="#" class="week-filter-item flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 cursor-pointer transition-all duration-150" data-filter="all">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                                        All Weeks
                                    </a>
                                    <a href="#" class="week-filter-item flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 cursor-pointer transition-all duration-150" data-filter="1-7">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        Week 1 - 7
                                    </a>
                                    <a href="#" class="week-filter-item flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 cursor-pointer transition-all duration-150" data-filter="8-14">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        Week 8 - 14
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="position:relative; height:280px;">
                        <canvas id="weeklyHoursChart"></canvas>
                    </div>
                </div>

                <!-- Attendance Breakdown Chart -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 h-full flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-3 mb-4">
                            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <span class="p-1 bg-emerald-50 text-emerald-600 rounded">🎯</span> Attendance Breakdown
                            </h3>
                        </div>
                    </div>
                    <div class="flex flex-col items-center justify-center w-full h-full">
                        <div style="position:relative; height:220px; width:100%;">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                        <div class="flex items-center justify-center gap-4 mt-4">
                            <span class="flex items-center gap-1.5 text-caption font-bold text-slate-600">
                                <span class="w-3 h-3 rounded-full bg-emerald-500"></span> Present (<?= $present_count ?>)
                            </span>
                            <span class="flex items-center gap-1.5 text-caption font-bold text-slate-600">
                                <span class="w-3 h-3 rounded-full bg-red-500"></span> Absent (<?= $absent_count ?>)
                            </span>
                        </div>
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
                            <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-caption">
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
                                            <span class="w-6 h-6 rounded-lg bg-indigo-100 flex items-center justify-center text-label font-black"><?= $wn ?></span>
                                            Week <?= $wn ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-caption text-slate-500 font-medium"><?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M') ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <span class="text-xs font-bold <?= $wl_count >= 3 ? 'text-emerald-600' : 'text-slate-600' ?>"><?= $wl_count ?> days</span>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <span class="text-xs font-bold font-mono text-blue-600"><?= floor($wk_mins / 60) ?>h <?= str_pad($wk_mins % 60, 2, '0', STR_PAD_LEFT) ?>m</span>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <?php if ($has_reflection): ?>
                                        <span class="inline-flex items-center gap-1 text-label font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">✅ Submitted</span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <?php if ($eval_status === 'approved_by_instructor' || $eval_status === 'approved_by_supervisor'): ?>
                                        <span class="inline-flex items-center gap-1 text-label font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-200">✅ Approved</span>
                                        <?php elseif ($eval_status === 'rejected'): ?>
                                        <span class="inline-flex items-center gap-1 text-label font-bold text-red-600 bg-red-50 px-2 py-1 rounded-full border border-red-200">❌ Rejected</span>
                                        <?php elseif ($eval_status === 'pending'): ?>
                                        <span class="inline-flex items-center gap-1 text-label font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded-full border border-amber-200">⏳ Pending</span>
                                        <?php else: ?>
                                        <span class="text-label font-bold text-slate-400">—</span>
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
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <!-- Recent Activity -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 h-auto max-h-[450px] overflow-y-auto flex flex-col">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-3 flex items-center gap-2 sticky top-0 bg-white z-20 pb-2">
                        <span class="p-1 bg-blue-50 text-blue-600 rounded">🕐</span> Recent Activity
                    </h3>
                    <?php if (!empty($recent_activities)): ?>
                    <div class="relative">
                        <div class="absolute left-[13px] top-3 bottom-3 w-px bg-slate-200 z-0"></div>
                        <?php foreach ($recent_activities as $act): ?>
                        <div class="flex items-center justify-between py-2.5 pl-1 pr-1 gap-3 hover:bg-slate-50 rounded-lg transition relative z-10">
                            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                <span class="relative z-10 w-5 h-5 rounded-full <?= $act['attendance_status'] === 'present' ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600' ?> flex items-center justify-center text-label shrink-0">
                                    <?= $act['attendance_status'] === 'present' ? '✓' : '✗' ?>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold text-slate-700 truncate leading-tight"><?= htmlspecialchars($act['task_title'] ?: 'No task title') ?></p>
                                    <p class="text-label text-slate-400 leading-tight mt-0.5"><?= (new DateTime($act['log_date']))->format('D, d M') ?> · <?= htmlspecialchars($act['calculated_duration']) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-slate-400 text-center py-4">No activity recorded yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Evaluation History -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 h-auto max-h-[450px] overflow-y-auto flex flex-col">
                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-3 flex items-center gap-2 sticky top-0 bg-white z-20 pb-2">
                        <span class="p-1 bg-amber-50 text-amber-600 rounded">📝</span> Evaluation History
                    </h3>
                    <?php if (!empty($recent_evaluations)): ?>
                    <div class="relative">
                        <div class="absolute left-[13px] top-3 bottom-3 w-px bg-slate-200 z-0"></div>
                        <?php foreach ($recent_evaluations as $ev): ?>
                        <div class="flex items-center justify-between py-2.5 pl-1 pr-1 gap-3 relative z-10">
                            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                <span class="relative z-10 w-5 h-5 rounded-full <?= $ev['report_status'] === 'approved_by_instructor' || $ev['report_status'] === 'approved_by_supervisor' ? 'bg-emerald-100 text-emerald-600' : ($ev['report_status'] === 'rejected' ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600') ?> flex items-center justify-center text-label shrink-0">
                                    <?= $ev['report_status'] === 'approved_by_instructor' || $ev['report_status'] === 'approved_by_supervisor' ? '✓' : ($ev['report_status'] === 'rejected' ? '✗' : '…') ?>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold text-slate-700 leading-tight">Week <?= (int)$ev['week_number'] ?> — <?= ucfirst(str_replace('_', ' ', $ev['report_status'])) ?></p>
                                    <p class="text-label text-slate-400 leading-tight mt-0.5 truncate"><?= $ev['instructor_comments'] ? htmlspecialchars(substr($ev['instructor_comments'], 0, 60)) . (strlen($ev['instructor_comments']) > 60 ? '…' : '') : 'No comments' ?></p>
                                </div>
                            </div>
                            <span class="text-label font-semibold text-slate-400 shrink-0 whitespace-nowrap"><?= (new DateTime($ev['evaluated_at']))->format('d M') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-slate-400 text-center py-4">No evaluations yet.</p>
                    <?php endif; ?>
                    <div class="pt-2 border-t border-slate-100 mt-2 text-right">
                        <a href="log-history.php" class="text-purple-600 text-sm font-medium hover:text-purple-700 transition">View All History →</a>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

</body>
</html>
