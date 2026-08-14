<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}

$internship_id = $user_id;

$db = $mysqli ?? $conn;

// ── Fetch Student Profile ────────────────────────────────────────
$profile_stmt = $db->prepare("SELECT sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
    sp.instructor_name, sp.internship_start_date, sp.internship_end_date, sp.company_id,
    sup_u.username AS supervisor_name, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile_row = $res ? $res->fetch_assoc() : null;

$student_name     = (($profile_row['full_name'] ?? '') ?: $username);
$student_roll     = $profile_row['student_roll'] ?? '';
$intern_start     = $profile_row['internship_start_date'] ?? null;
$intern_end       = $profile_row['internship_end_date'] ?? null;
$supervisor_name  = $profile_row['supervisor_name'] ?? '—';
$profile_pic      = $profile_row['profile_pic'] ?? '';

// ── View Mode: weekly or monthly ─────────────────────────────────
$view_mode = $_GET['mode'] ?? 'weekly';
if (!in_array($view_mode, ['weekly', 'monthly'])) $view_mode = 'weekly';

// ── Build week ranges ────────────────────────────────────────────
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

// ── Fetch all data ───────────────────────────────────────────────
$all_logs_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$all_logs_stmt->bind_param("i", $internship_id);
$all_logs_stmt->execute();
$res = $all_logs_stmt->get_result();
$all_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$all_refs_stmt = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? ORDER BY week_number ASC");
$all_refs_stmt->bind_param("i", $internship_id);
$all_refs_stmt->execute();
$res = $all_refs_stmt->get_result();
$all_refs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$all_evals_stmt = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? ORDER BY week_number ASC");
$all_evals_stmt->bind_param("i", $internship_id);
$all_evals_stmt->execute();
$res = $all_evals_stmt->get_result();
$all_evals = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$eval_by_week = [];
foreach ($all_evals as $ev) {
    $eval_by_week[$ev['week_number']] = $ev;
}

// Group logs by week
$logs_by_week = [];
foreach ($all_logs as $log) {
    $log_date = new DateTime($log['log_date']);
    foreach ($weeks as $wn => $wr) {
        $ws = new DateTime($wr['start']);
        $we = new DateTime($wr['end']);
        if ($log_date >= $ws && $log_date <= $we) {
            $logs_by_week[$wn][] = $log;
            break;
        }
    }
}

$refs_by_week = [];
foreach ($all_refs as $ref) {
    $refs_by_week[$ref['week_number']] = $ref;
}

// ── Stats ────────────────────────────────────────────────────────
$total_logs_count = count($all_logs);

$present_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present'");
$present_stmt->bind_param("i", $internship_id);
$present_stmt->execute();
$res = $present_stmt->get_result();
$p_row = $res ? $res->fetch_row() : null;
$total_present = (int) ($p_row[0] ?? 0);

$absent_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave')");
$absent_stmt->bind_param("i", $internship_id);
$absent_stmt->execute();
$res = $absent_stmt->get_result();
$a_row = $res ? $res->fetch_row() : null;
$total_absent = (int) ($a_row[0] ?? 0);

$total_weeks = count($weeks);
$total_reflections = count($all_refs);

$progress_weeks_completed = 0;
$progress_total_weeks = $total_weeks;
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

// Total hours
$total_minutes = 0;
foreach ($all_logs as $log) {
    $parts = explode(':', $log['calculated_duration']);
    if (count($parts) === 2) {
        $total_minutes += ((int)$parts[0] * 60) + (int)$parts[1];
    }
}
$total_hours = floor($total_minutes / 60);
$total_mins  = $total_minutes % 60;

// ── Filtered period stats ────────────────────────────────────────
$display_logs_count = $total_logs_count;
$display_present    = $total_present;
$display_absent     = $total_absent;
$display_reflections = $total_reflections;
$display_hours      = $total_hours;
$display_mins       = $total_mins;

// ── Build monthly data ───────────────────────────────────────────
$months = [];
foreach ($weeks as $wn => $wr) {
    $month_key = date('Y-m', strtotime($wr['start']));
    $month_label = date('F Y', strtotime($wr['start']));
    if (!isset($months[$month_key])) {
        $months[$month_key] = [
            'label' => $month_label,
            'weeks' => [],
        ];
    }
    $months[$month_key]['weeks'][] = $wn;
}

// ── Filtered week/month (default to first available) ─────────────
$filter_week = isset($_GET['week']) ? (int) $_GET['week'] : (!empty($weeks) ? array_key_first($weeks) : 0);
$filter_month = $_GET['month'] ?? (!empty($months) ? array_key_first($months) : '');

// ── Scoped stats for selected period ─────────────────────────────
if ($view_mode === 'weekly' && $filter_week > 0 && isset($weeks[$filter_week])) {
    $wl = $logs_by_week[$filter_week] ?? [];
    $display_logs_count = count($wl);
    $display_present = 0;
    $display_absent = 0;
    $display_minutes = 0;
    foreach ($wl as $log) {
        if ($log['attendance_status'] === 'present') $display_present++;
        else $display_absent++;
        $p = explode(':', $log['calculated_duration']);
        if (count($p) === 2) $display_minutes += ((int)$p[0] * 60) + (int)$p[1];
    }
    $display_reflections = isset($refs_by_week[$filter_week]) ? 1 : 0;
    $display_hours = floor($display_minutes / 60);
    $display_mins  = $display_minutes % 60;
} elseif ($view_mode === 'monthly' && $filter_month && isset($months[$filter_month])) {
    $display_logs_count = 0;
    $display_present = 0;
    $display_absent = 0;
    $display_minutes = 0;
    $display_reflections = 0;
    foreach ($months[$filter_month]['weeks'] as $wn) {
        $wl = $logs_by_week[$wn] ?? [];
        $display_logs_count += count($wl);
        foreach ($wl as $log) {
            if ($log['attendance_status'] === 'present') $display_present++;
            else $display_absent++;
            $p = explode(':', $log['calculated_duration']);
            if (count($p) === 2) $display_minutes += ((int)$p[0] * 60) + (int)$p[1];
        }
        if (isset($refs_by_week[$wn])) $display_reflections++;
    }
    $display_hours = floor($display_minutes / 60);
    $display_mins  = $display_minutes % 60;
}

// Back link
$back_url = 'student-dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log History – InternReport</title>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
    html { scrollbar-gutter: stable; overflow-y: scroll; }
    .nav-link { color: rgba(255,255,255,0.55); font-weight: 500; }
    .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
    .active-nav { background: #9333ea; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(147,51,234,0.3); }
    .glass-sidebar { background: #0f172a; border-right: 1px solid rgba(255,255,255,0.08); }
    .glass-sidebar nav { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
    .glass-sidebar nav::-webkit-scrollbar { width: 4px; }
    .glass-sidebar nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
    @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }
    </style>
</head>
<body class="bg-slate-100 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-64 glass-sidebar flex flex-col shrink-0">
        <div class="h-16 flex items-center px-5 border-b border-white/10 shrink-0">
            <span class="font-black text-white tracking-tight text-lg">InternReport</span>
        </div>
        <nav class="flex-1 min-h-0 py-4 space-y-1 px-3 overflow-y-auto">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg> Dashboard
            </a>
            <a href="notifications.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> Notifications
            </a>
            <a href="log-history.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="history">
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
        <?php $pageTitle = 'Log History'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 md:p-8">
            <div class="max-w-7xl mx-auto w-full">

            <!-- ════ PAGE HEADER ════ -->
            <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-5 mb-6 no-print">
                <div class="flex items-start justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-base font-bold shrink-0">
                            <?= strtoupper($student_name[0]) ?>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($student_name) ?> — Log History</h1>
                            <p class="text-xs text-gray-400 font-mono mt-0.5">Roll: <?= htmlspecialchars($student_roll ?: '—') ?></p>
                            <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                <?php if (!empty($profile_row['company_name'])): ?>
                                    <span class="text-xs font-medium text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded"><?= htmlspecialchars($profile_row['company_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($supervisor_name && $supervisor_name !== '—'): ?>
                                    <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded">Sup: <?= htmlspecialchars($supervisor_name) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════ STATS CARDS ════ -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6 no-print">
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-bold text-slate-800"><?= $display_logs_count ?></p>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-1">Total Logs</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-600"><?= $display_present ?></p>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-1">Present Days</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-bold text-red-500"><?= $display_absent ?></p>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-1">Absent Days</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-bold text-indigo-600"><?= $display_reflections ?></p>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-1">Reflections</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600"><?= $display_hours ?>h <?= str_pad($display_mins, 2, '0', STR_PAD_LEFT) ?>m</p>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-1">Total Hours</p>
                </div>
            </div>

            <!-- ════ VIEW TOGGLE & FILTERS ════ -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6 no-print">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <!-- View Mode Toggle -->
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">View:</span>
                        <a href="?mode=weekly<?= $filter_week ? "&week={$filter_week}" : '' ?>" class="px-3 py-1.5 text-xs font-medium rounded-lg transition <?= $view_mode === 'weekly' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Weekly</a>
                        <a href="?mode=monthly<?= $filter_month ? "&month={$filter_month}" : '' ?>" class="px-3 py-1.5 text-xs font-medium rounded-lg transition <?= $view_mode === 'monthly' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Monthly</a>
                    </div>

                    <!-- Week/Month Filter -->
                    <?php if ($view_mode === 'weekly' && !empty($weeks)): ?>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Jump to:</span>
                        <select onchange="if(this.value) window.location.href='?mode=weekly&week='+this.value; else window.location.href='?mode=weekly';" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 focus:outline-none focus:border-indigo-500 cursor-pointer">
                            <option value="">All Weeks</option>
                            <?php foreach ($weeks as $wn => $wr): ?>
                            <option value="<?= $wn ?>" <?= $filter_week === $wn ? 'selected' : '' ?>>Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php elseif ($view_mode === 'monthly' && !empty($months)): ?>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Jump to:</span>
                        <select onchange="if(this.value) window.location.href='?mode=monthly&month='+this.value; else window.location.href='?mode=monthly';" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 focus:outline-none focus:border-indigo-500 cursor-pointer">
                            <option value="">All Months</option>
                            <?php foreach ($months as $mk => $mv): ?>
                            <option value="<?= htmlspecialchars($mk) ?>" <?= $filter_month === $mk ? 'selected' : '' ?>><?= htmlspecialchars($mv['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Print -->
                    <button onclick="window.print()" class="flex items-center gap-2 px-3.5 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-50 transition shadow-sm cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-16-5V9a2 2 0 012-2h12a2 2 0 012 2v4m-12 9h8a2 2 0 002-2v-3a2 2 0 00-2-2H8a2 2 0 00-2 2v3a2 2 0 002 2z"/></svg>
                        Print
                    </button>
                </div>
            </div>

            <!-- ════ WEEKLY VIEW ════ -->
            <?php if ($view_mode === 'weekly'): ?>
                <?php
                $display_weeks = $weeks;
                if ($filter_week > 0 && isset($weeks[$filter_week])) {
                    $display_weeks = [$filter_week => $weeks[$filter_week]];
                }
                ?>
                <?php if (!empty($display_weeks)): ?>
                    <?php foreach ($display_weeks as $wn => $wr): ?>
                    <?php
                        $week_logs = $logs_by_week[$wn] ?? [];
                        $week_ref  = $refs_by_week[$wn] ?? null;
                        $week_eval = $eval_by_week[$wn] ?? null;
                        $week_present = 0;
                        $week_absent = 0;
                        $week_minutes = 0;
                        foreach ($week_logs as $wl) {
                            if ($wl['attendance_status'] === 'present') $week_present++;
                            else $week_absent++;
                            $p = explode(':', $wl['calculated_duration']);
                            if (count($p) === 2) $week_minutes += ((int)$p[0] * 60) + (int)$p[1];
                        }
                        $has_data = !empty($week_logs) || $week_ref || $week_eval;
                    ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
                        <!-- Week Header -->
                        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-indigo-500 text-white flex items-center justify-center text-xs font-bold">
                                    W<?= $wn ?>
                                </div>
                                <div>
                                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-700">Week <?= $wn ?></h2>
                                    <span class="text-xs text-gray-400">
                                        <?= (new DateTime($wr['start']))->format('d M Y') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded"><?= $week_present ?> Present</span>
                                <span class="text-xs font-medium text-red-600 bg-red-50 px-2.5 py-0.5 rounded"><?= $week_absent ?> Absent</span>
                                <span class="text-xs font-medium text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded"><?= floor($week_minutes / 60) ?>h <?= str_pad($week_minutes % 60, 2, '0', STR_PAD_LEFT) ?>m</span>
                                <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
                                    <?php
                                    $gmap = [
                                        'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
                                        'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
                                        'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
                                        'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
                                    ];
                                    $gs = $gmap[$week_eval['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                                    ?>
                                    <span class="text-xs font-medium <?= $gs[1] ?> <?= $gs[2] ?> px-2.5 py-0.5 rounded"><?= $gs[0] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-5 space-y-5">
                            <!-- Daily Logs -->
                            <?php if (!empty($week_logs)): ?>
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </span> Daily Logs
                                </h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="bg-slate-50 text-gray-500 font-medium uppercase text-xs">
                                                <th class="px-4 py-2.5 text-left">ရက်စွဲ / နေ့</th>
                                                <th class="px-4 py-2.5 text-left">တက်ရောက်မှုအခြေအနေ</th>
                                                <th class="px-4 py-2.5 text-left">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                                                <th class="px-4 py-2.5 text-left">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                                                <th class="px-4 py-2.5 text-left">အသုံးပြုသောပစ္စည်းများ</th>
                                                <th class="px-4 py-2.5 text-left">လေ့လာသိရှိသော အသိပညာ</th>
                                                <th class="px-4 py-2.5 text-left">ကြာချိန်</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($week_logs as $wl): ?>
                                            <tr class="hover:bg-slate-50 transition">
                                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal whitespace-nowrap">
                                                    <?= (new DateTime($wl['log_date']))->format('D, d M Y') ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <?php if ($wl['attendance_status'] === 'present'): ?>
                                                        <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Present</span>
                                                    <?php else: ?>
                                                        <span class="text-xs font-medium text-red-600 bg-red-50 px-2 py-0.5 rounded">Absent</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php $is_absent = ($wl['attendance_status'] ?? 'present') === 'absent'; ?>
                                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($wl['task_title'] ?: '-') ?></td>
                                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($wl['tasks_performed'] ?: '-') ?></td>
                                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($wl['tools_used'] ?: '-') ?></td>
                                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($wl['learnt_skills'] ?: '-') ?></td>
                                                <td class="px-4 py-3 font-mono text-blue-600 text-sm font-semibold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <p class="text-xs text-gray-400">No daily logs submitted for this week.</p>
                            </div>
                            <?php endif; ?>

                            <!-- Weekly Reflection -->
                            <?php if ($week_ref): ?>
                            <div class="border-t border-slate-100 pt-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                    </span> Weekly Reflection
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="bg-slate-50 rounded-xl p-3.5">
                                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">What was done?</span>
                                        <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($week_ref['what_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-3.5">
                                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">How was it done?</span>
                                        <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($week_ref['how_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-3.5">
                                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">Why was it done?</span>
                                        <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($week_ref['why_done'] ?? '')) ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Evaluation -->
                            <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
                            <div class="border-t border-slate-100 pt-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                    </span> Instructor Evaluation
                                </h3>
                                <div class="bg-slate-50 rounded-xl p-3.5 flex items-start gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <?php
                                            $gs = $gmap[$week_eval['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                                            ?>
                                            <span class="text-xs font-medium <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                                            <span class="text-xs font-medium <?= $week_eval['report_status'] === 'approved_by_instructor' ? 'text-emerald-600 bg-emerald-50' : ($week_eval['report_status'] === 'rejected' ? 'text-red-600 bg-red-50' : 'text-amber-600 bg-amber-50') ?> px-2 py-0.5 rounded"><?= ucfirst(str_replace('_', ' ', $week_eval['report_status'])) ?></span>
                                        </div>
                                        <?php if ($week_eval['comment']): ?>
                                            <p class="text-sm text-gray-700 leading-normal mt-1"><?= nl2br(htmlspecialchars($week_eval['comment'])) ?></p>
                                        <?php endif; ?>
                                        <?php if ($week_eval['instructor_comments']): ?>
                                            <div class="bg-red-50 border border-red-100 rounded-lg p-2.5 mt-2">
                                                <p class="text-xs font-medium text-red-500 mb-0.5">Instructor Comments:</p>
                                                <p class="text-sm text-red-600 leading-normal"><?= nl2br(htmlspecialchars($week_eval['instructor_comments'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-xs text-gray-400 shrink-0"><?= (new DateTime($week_eval['evaluated_at']))->format('d M Y') ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">
                            <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                        </div>
                        <p class="text-sm text-slate-500 font-medium">No weekly data recorded yet.</p>
                        <p class="text-xs text-slate-400 mt-1">Start submitting daily logs to see your history here.</p>
                        <a href="student-dashboard.php" class="inline-block mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition">Go to Dashboard</a>
                    </div>
                <?php endif; ?>

            <!-- ════ MONTHLY VIEW ════ -->
            <?php elseif ($view_mode === 'monthly'): ?>
                <?php
                $display_months = $months;
                if ($filter_month && isset($months[$filter_month])) {
                    $display_months = [$filter_month => $months[$filter_month]];
                }
                ?>
                <?php if (!empty($display_months)): ?>
                    <?php foreach ($display_months as $mk => $mv): ?>
                    <?php
                        // Aggregate month stats
                        $month_logs = [];
                        $month_present = 0;
                        $month_absent = 0;
                        $month_minutes = 0;
                        $month_refs = 0;
                        $month_evals = 0;
                        foreach ($mv['weeks'] as $wn) {
                            $w_logs = $logs_by_week[$wn] ?? [];
                            foreach ($w_logs as $wl) {
                                $month_logs[] = $wl;
                                if ($wl['attendance_status'] === 'present') $month_present++;
                                else $month_absent++;
                                $p = explode(':', $wl['calculated_duration']);
                                if (count($p) === 2) $month_minutes += ((int)$p[0] * 60) + (int)$p[1];
                            }
                            if (isset($refs_by_week[$wn])) $month_refs++;
                            if (isset($eval_by_week[$wn]) && $eval_by_week[$wn]['report_status'] !== 'pending') $month_evals++;
                        }
                    ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
                        <!-- Month Header -->
                        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-purple-500 text-white flex items-center justify-center text-xs font-bold">
                                    <?= date('M', strtotime($mk . '-01')) ?>
                                </div>
                                <div>
                                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-700"><?= htmlspecialchars($mv['label']) ?></h2>
                                    <span class="text-xs text-gray-400"><?= count($mv['weeks']) ?> week(s) · <?= count($month_logs) ?> log(s)</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded"><?= $month_present ?> Present</span>
                                <span class="text-xs font-medium text-red-600 bg-red-50 px-2.5 py-0.5 rounded"><?= $month_absent ?> Absent</span>
                                <span class="text-xs font-medium text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded"><?= floor($month_minutes / 60) ?>h <?= str_pad($month_minutes % 60, 2, '0', STR_PAD_LEFT) ?>m</span>
                                <span class="text-xs font-medium text-indigo-600 bg-indigo-50 px-2.5 py-0.5 rounded"><?= $month_refs ?> Reflections</span>
                                <span class="text-xs font-medium text-amber-600 bg-amber-50 px-2.5 py-0.5 rounded"><?= $month_evals ?> Evaluated</span>
                            </div>
                        </div>

                        <div class="p-5 space-y-4">
                            <?php foreach ($mv['weeks'] as $wn): ?>
                            <?php
                                $week_logs_m = $logs_by_week[$wn] ?? [];
                                $week_ref_m  = $refs_by_week[$wn] ?? null;
                                $week_eval_m = $eval_by_week[$wn] ?? null;
                                $wr = $weeks[$wn];
                            ?>
                            <!-- Week within month -->
                            <div class="border border-slate-100 rounded-xl overflow-hidden">
                                <div class="px-4 py-2.5 bg-slate-50/80 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-bold"><?= $wn ?></span>
                                        <span class="text-xs font-semibold text-slate-700">Week <?= $wn ?></span>
                                        <span class="text-xs text-gray-400"><?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M') ?></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <?php
                                        $wk_p = 0; $wk_a = 0;
                                        foreach ($week_logs_m as $wl) {
                                            if ($wl['attendance_status'] === 'present') $wk_p++; else $wk_a++;
                                        }
                                        ?>
                                        <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded"><?= $wk_p ?></span>
                                        <span class="text-xs font-medium text-red-600 bg-red-50 px-2 py-0.5 rounded"><?= $wk_a ?></span>
                                        <?php if ($week_ref_m): ?>
                                            <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Ref</span>
                                        <?php endif; ?>
                                        <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                            <?php
                                            $ev_status = $week_eval_m['report_status'];
                                            $ev_cls = $ev_status === 'approved_by_instructor' || $ev_status === 'approved_by_supervisor' ? 'text-emerald-600 bg-emerald-50' : ($ev_status === 'rejected' ? 'text-red-600 bg-red-50' : 'text-amber-600 bg-amber-50');
                                            ?>
                                            <span class="text-xs font-medium <?= $ev_cls ?> px-2 py-0.5 rounded"><?= $ev_status === 'rejected' ? 'Rejected' : 'Evaluated' ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($week_logs_m)): ?>
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5 px-4 pt-3">
                                        <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        </span> Daily Logs
                                    </h3>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="bg-slate-50 text-gray-500 font-medium uppercase text-xs">
                                                    <th class="px-4 py-2.5 text-left">ရက်စွဲ / နေ့</th>
                                                    <th class="px-4 py-2.5 text-left">တက်ရောက်မှုအခြေအနေ</th>
                                                    <th class="px-4 py-2.5 text-left">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                                                    <th class="px-4 py-2.5 text-left">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                                                    <th class="px-4 py-2.5 text-left">အသုံးပြုသောပစ္စည်းများ</th>
                                                    <th class="px-4 py-2.5 text-left">လေ့လာသိရှိသော အသိပညာ</th>
                                                    <th class="px-4 py-2.5 text-left">ကြာချိန်</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                <?php foreach ($week_logs_m as $wl): ?>
                                                <tr class="hover:bg-slate-50 transition">
                                                    <td class="px-4 py-3 text-sm text-gray-700 leading-normal whitespace-nowrap">
                                                        <?= (new DateTime($wl['log_date']))->format('D, d M Y') ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap">
                                                        <?php if ($wl['attendance_status'] === 'present'): ?>
                                                            <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Present</span>
                                                        <?php else: ?>
                                                            <span class="text-xs font-medium text-red-600 bg-red-50 px-2 py-0.5 rounded">Absent</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php $is_absent_m = ($wl['attendance_status'] ?? 'present') === 'absent'; ?>
                                                    <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent_m ? '-' : htmlspecialchars($wl['task_title'] ?: '-') ?></td>
                                                    <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent_m ? '-' : htmlspecialchars($wl['tasks_performed'] ?: '-') ?></td>
                                                    <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent_m ? '-' : htmlspecialchars($wl['tools_used'] ?: '-') ?></td>
                                                    <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent_m ? '-' : htmlspecialchars($wl['learnt_skills'] ?: '-') ?></td>
                                                    <td class="px-4 py-3 font-mono text-blue-600 text-sm font-semibold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="px-4 py-3 text-center">
                                    <p class="text-xs text-gray-400">No logs for this week.</p>
                                </div>
                                <?php endif; ?>

                                <!-- Week Reflection -->
                                <?php if ($week_ref_m): ?>
                                <div class="border-t border-slate-100 pt-4 px-4 pb-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
                                        <span class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        </span> Weekly Reflection
                                    </h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div class="bg-slate-50 rounded-xl p-3.5">
                                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">What was done?</span>
                                            <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($week_ref_m['what_done'] ?? '')) ?></p>
                                        </div>
                                        <div class="bg-slate-50 rounded-xl p-3.5">
                                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">How was it done?</span>
                                            <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($week_ref_m['how_done'] ?? '')) ?></p>
                                        </div>
                                        <div class="bg-slate-50 rounded-xl p-3.5">
                                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">Why was it done?</span>
                                            <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($week_ref_m['why_done'] ?? '')) ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Evaluation -->
                                <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                <div class="border-t border-slate-100 pt-4 px-4 pb-3">
                                    <h3 class="text-caption font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                        <span class="w-6 h-6 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                        </span> Instructor Evaluation
                                    </h3>
                                    <div class="bg-slate-50 rounded-xl p-3 flex items-start gap-4">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-1">
                                                <?php
                                                $gmap_m = [
                                                    'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
                                                    'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
                                                    'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
                                                    'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
                                                ];
                                                $gs_m = $gmap_m[$week_eval_m['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                                                ?>
                                                <span class="text-label font-bold <?= $gs_m[1] ?> <?= $gs_m[2] ?> px-2 py-0.5 rounded"><?= $gs_m[0] ?></span>
                                                <span class="text-label font-bold <?= $week_eval_m['report_status'] === 'approved_by_instructor' ? 'text-emerald-600 bg-emerald-50' : ($week_eval_m['report_status'] === 'rejected' ? 'text-red-600 bg-red-50' : 'text-amber-600 bg-amber-50') ?> px-2 py-0.5 rounded"><?= ucfirst(str_replace('_', ' ', $week_eval_m['report_status'])) ?></span>
                                            </div>
                                            <?php if ($week_eval_m['comment']): ?>
                                                <p class="text-xs text-slate-600 leading-relaxed mt-1"><?= nl2br(htmlspecialchars($week_eval_m['comment'])) ?></p>
                                            <?php endif; ?>
                                            <?php if ($week_eval_m['instructor_comments']): ?>
                                                <div class="bg-red-50 border border-red-100 rounded-lg p-2 mt-2">
                                                    <p class="text-label font-bold text-red-500 mb-0.5">Instructor Comments:</p>
                                                    <p class="text-xs text-red-600"><?= nl2br(htmlspecialchars($week_eval_m['instructor_comments'])) ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-label text-slate-300 shrink-0"><?= (new DateTime($week_eval_m['evaluated_at']))->format('d M Y') ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">
                            <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                        </div>
                        <p class="text-sm text-slate-500 font-medium">No monthly data recorded yet.</p>
                        <p class="text-xs text-slate-400 mt-1">Start submitting daily logs to see your history here.</p>
                        <a href="student-dashboard.php" class="inline-block mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition">Go to Dashboard</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="text-center text-sm text-slate-300 py-4 no-print">Powered by InternReport</div>
        </div>
        </main>
    </div>
</div>

</body>
</html>
