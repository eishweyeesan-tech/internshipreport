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

// ── Fetch Student Profile ────────────────────────────────────────
$profile_r = $conn->query("SELECT sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
    sp.instructor_name, sp.internship_start_date, sp.internship_end_date, sp.company_id,
    sup_u.username AS supervisor_name, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = {$esc_uid}");
$profile_row = $profile_r ? $profile_r->fetch_assoc() : null;

$student_name     = ($profile_row['full_name'] ?: $username);
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
$all_logs_r = $conn->query("SELECT * FROM daily_logs WHERE internship_id = {$esc_iid} ORDER BY log_date ASC");
$all_logs = [];
if ($all_logs_r) { while ($row = $all_logs_r->fetch_assoc()) { $all_logs[] = $row; } }

$all_refs_r = $conn->query("SELECT * FROM weekly_reflections WHERE internship_id = {$esc_iid} ORDER BY week_number ASC");
$all_refs = [];
if ($all_refs_r) { while ($row = $all_refs_r->fetch_assoc()) { $all_refs[] = $row; } }

$all_evals_r = $conn->query("SELECT * FROM report_evaluations WHERE student_id = {$esc_iid} ORDER BY week_number ASC");
$all_evals = [];
if ($all_evals_r) { while ($row = $all_evals_r->fetch_assoc()) { $all_evals[] = $row; } }

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

$present_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status = 'present'");
$total_present = ($present_r && $present_r->num_rows > 0) ? (int) $present_r->fetch_row()[0] : 0;

$absent_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND attendance_status IN ('absent','leave')");
$total_absent = ($absent_r && $absent_r->num_rows > 0) ? (int) $absent_r->fetch_row()[0] : 0;

$total_weeks = count($weeks);
$total_reflections = count($all_refs);

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
    .active-nav { background: rgba(255,255,255,0.15); color: #fff; border-right: 3px solid #a78bfa; }
    .glass { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); }
    .glass-strong { background: rgba(255,255,255,0.72); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.5); }
    .glass-sidebar { background: rgba(15,23,42,0.82); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-right: 1px solid rgba(255,255,255,0.08); }
    .glass-header { background: rgba(255,255,255,0.6); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid rgba(255,255,255,0.4); }
    .glass-card { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); box-shadow: 0 8px 32px rgba(0,0,0,0.06); }
    @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
    .animated-bg { background: linear-gradient(-45deg, #e0e7ff, #ede9fe, #fce7f3, #dbeafe, #d1fae5); background-size: 400% 400%; animation: gradientShift 20s ease infinite; }
    @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } .glass, .glass-card, .glass-strong, .glass-header, .glass-sidebar { background: white !important; backdrop-filter: none !important; border-color: #e2e8f0 !important; box-shadow: none !important; } }
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
            <a href="student-dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📝</span> Dashboard
            </a>
            <a href="analytics.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📊</span> Analytics
            </a>
            <a href="log-history.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition" data-section="history">
                <span>📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📅</span> Public Holidays
            </a>
            <a href="instructions.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📋</span> Instructions
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-14 glass-header flex items-center justify-between px-6 shrink-0">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-slate-700">📋 InternReport</span>
                <span class="w-px h-5 bg-slate-300/50"></span>
                <span class="text-xs font-semibold text-slate-500">Log History</span>
            </div>
            <div class="flex items-center gap-3">
                <a href="student-dashboard.php" class="flex items-center gap-2 px-3 py-1.5 bg-white/40 hover:bg-white/60 border border-white/40 rounded-xl text-xs font-bold text-slate-600 transition">
                    ← Back to Dashboard
                </a>
                <div class="relative group/profile inline-block">
                    <button class="flex items-center gap-2.5 hover:bg-white/30 rounded-xl px-2 py-1.5 transition">
                        <?php if ($profile_pic): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover border-2 border-white/60 shadow-sm shrink-0">
                        <?php else: ?>
                        <span class="w-8 h-8 rounded-full bg-violet-100 text-violet-600 flex items-center justify-center text-xs font-bold shrink-0"><?= strtoupper(substr($student_name, 0, 1)) ?></span>
                        <?php endif; ?>
                        <div class="text-left hidden sm:block">
                            <p class="text-xs font-bold text-slate-700 leading-tight"><?= htmlspecialchars($student_name) ?></p>
                        </div>
                    </button>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">

            <!-- ════ PAGE HEADER ════ -->
            <div class="glass-card rounded-2xl p-5 mb-6">
                <div class="flex items-start justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-bold shrink-0">
                            <?= strtoupper($student_name[0]) ?>
                        </div>
                        <div>
                            <h1 class="text-sm font-black text-slate-800"><?= htmlspecialchars($student_name) ?> — Log History</h1>
                            <p class="text-sm text-slate-400 font-mono mt-0.5">Roll: <?= htmlspecialchars($student_roll ?: '—') ?></p>
                            <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                <?php if (!empty($profile_row['company_name'])): ?>
                                    <span class="text-sm font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded"><?= htmlspecialchars($profile_row['company_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($supervisor_name && $supervisor_name !== '—'): ?>
                                    <span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Sup: <?= htmlspecialchars($supervisor_name) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════ STATS CARDS ════ -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-black text-slate-800"><?= $total_logs_count ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Logs</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-black text-emerald-600"><?= $total_present ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Present Days</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-black text-red-500"><?= $total_absent ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Absent Days</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-black text-indigo-600"><?= $total_reflections ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Reflections</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
                    <p class="text-2xl font-black text-blue-600"><?= $total_hours ?>h <?= str_pad($total_mins, 2, '0', STR_PAD_LEFT) ?>m</p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Hours</p>
                </div>
            </div>

            <!-- ════ VIEW TOGGLE & FILTERS ════ -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-6 no-print">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <!-- View Mode Toggle -->
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">View:</span>
                        <a href="?mode=weekly<?= $filter_week ? "&week={$filter_week}" : '' ?>" class="px-3 py-1.5 text-xs font-bold rounded-lg transition <?= $view_mode === 'weekly' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">📅 Weekly</a>
                        <a href="?mode=monthly<?= $filter_month ? "&month={$filter_month}" : '' ?>" class="px-3 py-1.5 text-xs font-bold rounded-lg transition <?= $view_mode === 'monthly' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">📆 Monthly</a>
                    </div>

                    <!-- Week/Month Filter -->
                    <?php if ($view_mode === 'weekly' && !empty($weeks)): ?>
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Jump to:</span>
                        <select onchange="if(this.value) window.location.href='?mode=weekly&week='+this.value; else window.location.href='?mode=weekly';" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:border-indigo-500 cursor-pointer">
                            <option value="">All Weeks</option>
                            <?php foreach ($weeks as $wn => $wr): ?>
                            <option value="<?= $wn ?>" <?= $filter_week === $wn ? 'selected' : '' ?>>Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php elseif ($view_mode === 'monthly' && !empty($months)): ?>
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Jump to:</span>
                        <select onchange="if(this.value) window.location.href='?mode=monthly&month='+this.value; else window.location.href='?mode=monthly';" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:border-indigo-500 cursor-pointer">
                            <option value="">All Months</option>
                            <?php foreach ($months as $mk => $mv): ?>
                            <option value="<?= htmlspecialchars($mk) ?>" <?= $filter_month === $mk ? 'selected' : '' ?>><?= htmlspecialchars($mv['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Print -->
                    <button onclick="window.print()" class="flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition shadow-sm cursor-pointer">
                        🖨️ Print
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
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6 <?= !$has_data && $filter_week ? '' : '' ?>">
                        <!-- Week Header -->
                        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-indigo-500/20">
                                    W<?= $wn ?>
                                </div>
                                <div>
                                    <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider">Week <?= $wn ?></h2>
                                    <span class="text-[11px] text-slate-400">
                                        <?= (new DateTime($wr['start']))->format('d M Y') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ <?= $week_present ?> Present</span>
                                <span class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded">❌ <?= $week_absent ?> Absent</span>
                                <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">⏱️ <?= floor($week_minutes / 60) ?>h <?= str_pad($week_minutes % 60, 2, '0', STR_PAD_LEFT) ?></span>
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
                                    <span class="text-[10px] font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-5 space-y-5">
                            <!-- Daily Logs -->
                            <?php if (!empty($week_logs)): ?>
                            <div>
                                <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-[10px]">📋</span> Daily Logs
                                </h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-[10px]">
                                                <th class="px-3 py-2 text-left">Date</th>
                                                <th class="px-3 py-2 text-left">Status</th>
                                                <th class="px-3 py-2 text-left">Intended Task</th>
                                                <th class="px-3 py-2 text-left">Task Detail</th>
                                                <th class="px-3 py-2 text-left">Actual Task</th>
                                                <th class="px-3 py-2 text-left">Tools</th>
                                                <th class="px-3 py-2 text-left">Knowledge</th>
                                                <th class="px-3 py-2 text-left">Duration</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($week_logs as $wl): ?>
                                            <tr class="hover:bg-slate-50 transition">
                                                <td class="px-3 py-2 font-medium text-slate-700 whitespace-nowrap">
                                                    <?= (new DateTime($wl['log_date']))->format('D, d M Y') ?>
                                                </td>
                                                <td class="px-3 py-2 whitespace-nowrap">
                                                    <?php if ($wl['attendance_status'] === 'present'): ?>
                                                        <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">✅ Present</span>
                                                    <?php else: ?>
                                                        <span class="text-[10px] font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">❌ Absent</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php $is_absent = ($wl['attendance_status'] ?? 'present') === 'absent'; ?>
                                                <td class="px-3 py-2 text-slate-600 max-w-[140px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($wl['task_title'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($wl['task_title'] ?: '-') ?></td>
                                                <td class="px-3 py-2 text-slate-600 max-w-[160px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($wl['task_detail'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($wl['task_detail'] ?: '-') ?></td>
                                                <td class="px-3 py-2 text-slate-600 max-w-[160px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($wl['tasks_performed'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($wl['tasks_performed'] ?: '-') ?></td>
                                                <td class="px-3 py-2 text-slate-600"><?= $is_absent ? '-' : htmlspecialchars($wl['tools_used'] ?: '-') ?></td>
                                                <td class="px-3 py-2 text-slate-600 max-w-[140px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($wl['learnt_skills'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($wl['learnt_skills'] ?: '-') ?></td>
                                                <td class="px-3 py-2 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <p class="text-xs text-slate-400">No daily logs submitted for this week.</p>
                            </div>
                            <?php endif; ?>

                            <!-- Weekly Reflection -->
                            <?php if ($week_ref): ?>
                            <div class="border-t border-slate-100 pt-4">
                                <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-[10px]">📊</span> Weekly Reflection
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="bg-slate-50 rounded-xl p-3">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">What was done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['what_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-3">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">How was it done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['how_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-3">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Why was it done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['why_done'] ?? '')) ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Evaluation -->
                            <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
                            <div class="border-t border-slate-100 pt-4">
                                <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-[10px]">⭐</span> Instructor Evaluation
                                </h3>
                                <div class="bg-slate-50 rounded-xl p-3 flex items-start gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <?php
                                            $gs = $gmap[$week_eval['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                                            ?>
                                            <span class="text-[10px] font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                                            <span class="text-[10px] font-bold <?= $week_eval['report_status'] === 'approved_by_instructor' ? 'text-emerald-600 bg-emerald-50' : ($week_eval['report_status'] === 'rejected' ? 'text-red-600 bg-red-50' : 'text-amber-600 bg-amber-50') ?> px-2 py-0.5 rounded"><?= ucfirst(str_replace('_', ' ', $week_eval['report_status'])) ?></span>
                                        </div>
                                        <?php if ($week_eval['comment']): ?>
                                            <p class="text-xs text-slate-600 leading-relaxed mt-1"><?= nl2br(htmlspecialchars($week_eval['comment'])) ?></p>
                                        <?php endif; ?>
                                        <?php if ($week_eval['instructor_comments']): ?>
                                            <div class="bg-red-50 border border-red-100 rounded-lg p-2 mt-2">
                                                <p class="text-[10px] font-bold text-red-500 mb-0.5">Instructor Comments:</p>
                                                <p class="text-xs text-red-600"><?= nl2br(htmlspecialchars($week_eval['instructor_comments'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[10px] text-slate-300 shrink-0"><?= (new DateTime($week_eval['evaluated_at']))->format('d M Y') ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm text-slate-500 font-medium">No weekly data recorded yet.</p>
                        <p class="text-xs text-slate-400 mt-1">Start submitting daily logs to see your history here.</p>
                        <a href="student-dashboard.php" class="inline-block mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition">📝 Go to Dashboard</a>
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
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-purple-500/20">
                                    <?= date('M', strtotime($mk . '-01')) ?>
                                </div>
                                <div>
                                    <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider"><?= htmlspecialchars($mv['label']) ?></h2>
                                    <span class="text-[11px] text-slate-400"><?= count($mv['weeks']) ?> week(s) · <?= count($month_logs) ?> log(s)</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ <?= $month_present ?> Present</span>
                                <span class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded">❌ <?= $month_absent ?> Absent</span>
                                <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">⏱️ <?= floor($month_minutes / 60) ?>h <?= str_pad($month_minutes % 60, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">📊 <?= $month_refs ?> Reflections</span>
                                <span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">⭐ <?= $month_evals ?> Evaluated</span>
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
                                        <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center text-[10px] font-bold"><?= $wn ?></span>
                                        <span class="text-[11px] font-bold text-slate-600">Week <?= $wn ?></span>
                                        <span class="text-[10px] text-slate-400"><?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M') ?></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <?php
                                        $wk_p = 0; $wk_a = 0;
                                        foreach ($week_logs_m as $wl) {
                                            if ($wl['attendance_status'] === 'present') $wk_p++; else $wk_a++;
                                        }
                                        ?>
                                        <span class="text-[9px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">✅ <?= $wk_p ?></span>
                                        <span class="text-[9px] font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">❌ <?= $wk_a ?></span>
                                        <?php if ($week_ref_m): ?>
                                            <span class="text-[9px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">📊 ✓</span>
                                        <?php endif; ?>
                                        <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                            <?php
                                            $ev_status = $week_eval_m['report_status'];
                                            $ev_cls = $ev_status === 'approved_by_instructor' || $ev_status === 'approved_by_supervisor' ? 'text-emerald-600 bg-emerald-50' : ($ev_status === 'rejected' ? 'text-red-600 bg-red-50' : 'text-amber-600 bg-amber-50');
                                            ?>
                                            <span class="text-[9px] font-bold <?= $ev_cls ?> px-1.5 py-0.5 rounded">⭐ <?= $ev_status === 'rejected' ? 'Rejected' : 'Evaluated' ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($week_logs_m)): ?>
                                <div class="divide-y divide-slate-50">
                                    <?php foreach ($week_logs_m as $wl): ?>
                                    <div class="px-4 py-2.5 flex items-center gap-3 text-xs hover:bg-slate-50/50 transition">
                                        <span class="font-medium text-slate-700 whitespace-nowrap w-24"><?= (new DateTime($wl['log_date']))->format('D, d M') ?></span>
                                        <span class="whitespace-nowrap">
                                            <?php if ($wl['attendance_status'] === 'present'): ?>
                                                <span class="text-[10px] font-bold text-emerald-600">✅</span>
                                            <?php else: ?>
                                                <span class="text-[10px] font-bold text-red-600">❌</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-slate-600 truncate flex-1" title="<?= htmlspecialchars($wl['task_title'] ?? '') ?>"><?= htmlspecialchars($wl['task_title'] ?: '-') ?></span>
                                        <span class="font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="px-4 py-3 text-center">
                                    <p class="text-[10px] text-slate-400">No logs for this week.</p>
                                </div>
                                <?php endif; ?>

                                <!-- Week Reflection & Eval Summary -->
                                <?php if ($week_ref_m || ($week_eval_m && $week_eval_m['report_status'] !== 'pending')): ?>
                                <div class="px-4 py-2.5 bg-slate-50/40 border-t border-slate-100 flex items-center gap-4 text-[10px]">
                                    <?php if ($week_ref_m): ?>
                                    <span class="text-slate-500"><strong class="text-slate-600">Reflection:</strong> <?= htmlspecialchars(mb_substr($week_ref_m['what_done'], 0, 80)) ?><?= strlen($week_ref_m['what_done']) > 80 ? '…' : '' ?></span>
                                    <?php endif; ?>
                                    <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                    <span class="text-slate-500"><strong class="text-slate-600">Grade:</strong> <?= ucfirst(str_replace('_', ' ', $week_eval_m['grade'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm text-slate-500 font-medium">No monthly data recorded yet.</p>
                        <p class="text-xs text-slate-400 mt-1">Start submitting daily logs to see your history here.</p>
                        <a href="student-dashboard.php" class="inline-block mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition">📝 Go to Dashboard</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="text-center text-sm text-slate-300 py-4">Powered by InternReport System</div>
        </main>
    </div>
</div>

</body>
</html>
