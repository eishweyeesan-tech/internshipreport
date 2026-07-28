<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

$user_id    = $_SESSION['user_id'];
$username   = $_SESSION['username'];
$role       = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}

$internship_id = $user_id;
$message = '';

// ── Fetch student profile info for the banner ────────────────────
$student_info = $pdo->prepare("
    SELECT sp.company_name, sp.internship_start_date, sp.internship_end_date,
           sup_u.username AS supervisor_name
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE sp.user_id = ?
");
$student_info->execute([$user_id]);
$student_info = $student_info->fetch();

// ── FORM A: Daily Log ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $log_date           = trim($_POST['log_date'] ?? '');
    $attendance_status  = trim($_POST['attendance_status'] ?? 'present');
    $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');
    $intended_task      = trim($_POST['intended_task'] ?? '');
    $task_detail        = trim($_POST['task_detail'] ?? '');
    $actual_task        = trim($_POST['actual_task'] ?? '');
    $tools_used         = trim($_POST['tools_used'] ?? '');
    $knowledge_gained   = trim($_POST['knowledge_gained'] ?? '');
    $hours_worked       = trim($_POST['hours_worked'] ?? '0 Hours');

    if ($log_date) {
        if ($attendance_status === 'absent') {
            $intended_task  = $reason_for_absence ?: 'Absent';
            $task_detail    = '';
            $actual_task    = '';
            $tools_used     = '';
            $knowledge_gained = '';
            $hours_worked   = '0 Hours';
        }
        $query = "INSERT INTO daily_logs
            (internship_id, log_date, attendance_status, reason_for_absence, task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            attendance_status = VALUES(attendance_status),
            reason_for_absence = VALUES(reason_for_absence),
            task_title = VALUES(task_title),
            task_detail = VALUES(task_detail),
            tasks_performed = VALUES(tasks_performed),
            tools_used = VALUES(tools_used),
            learnt_skills = VALUES(learnt_skills),
            calculated_duration = VALUES(calculated_duration)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$internship_id, $log_date, $attendance_status, $reason_for_absence, $intended_task, $task_detail, $actual_task, $tools_used, $knowledge_gained, $hours_worked]);
        $message = 'daily_saved';
    }
}

// ── FORM B: Weekly Reflection ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reflection'])) {
    $week_number  = (int) ($_POST['week_number'] ?? 0);
    $what_done    = trim($_POST['what_done'] ?? '');
    $how_done     = trim($_POST['how_done'] ?? '');
    $why_done     = trim($_POST['why_done'] ?? '');

    if ($week_number > 0 && $what_done) {
        $query = "INSERT INTO weekly_reflections
            (internship_id, week_number, what_done, how_done, why_done)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            what_done = VALUES(what_done),
            how_done = VALUES(how_done),
            why_done = VALUES(why_done)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$internship_id, $week_number, $what_done, $how_done, $why_done]);
        $message = 'reflection_saved';
    }
}

// ── MAGIC LINK GENERATION ────────────────────────────────────────
$magic_link = '';

// ── FETCH EXISTING DATA ──────────────────────────────────────────
$all_logs_stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC");
$all_logs_stmt->execute([$internship_id]);
$all_logs = $all_logs_stmt->fetchAll();

// Build week ranges from distinct log dates
$all_dates_stmt = $pdo->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$all_dates_stmt->execute([$internship_id]);
$all_log_dates = $all_dates_stmt->fetchAll(PDO::FETCH_COLUMN);

$weeks = [];
if (!empty($all_log_dates)) {
    $first = new DateTime($all_log_dates[0]);
    $last  = new DateTime(end($all_log_dates));
    $num   = 1;
    $s     = clone $first;
    while ($s <= $last) {
        $e = (clone $s)->modify('+6 days');
        $weeks[$num] = ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d')];
        $s->modify('+7 days');
        $num++;
    }
}

$selected_week = 1;
if (isset($_GET['week'])) {
    $w = (int)$_GET['week'];
    if (isset($weeks[$w])) $selected_week = $w;
}

// Format date range for the selected week
$week_date_range = '';
if (!empty($weeks[$selected_week])) {
    $week_start_obj = new DateTime($weeks[$selected_week]['start']);
    $week_end_obj   = new DateTime($weeks[$selected_week]['end']);
    $week_date_range = $week_start_obj->format('d M Y') . ' to ' . $week_end_obj->format('d M Y');
}

// Filter logs by selected week
if (!empty($weeks)) {
    $ws = $weeks[$selected_week]['start'];
    $we = $weeks[$selected_week]['end'];
    $logs_stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date DESC");
    $logs_stmt->execute([$internship_id, $ws, $we]);
} else {
    $logs_stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC");
    $logs_stmt->execute([$internship_id]);
}
$recent_logs = $logs_stmt->fetchAll();

// Attendance counts (SQL-based)
$present_stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present'");
$present_stmt->execute([$internship_id]);
$present_count = (int)$present_stmt->fetchColumn();

$absent_stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave')");
$absent_stmt->execute([$internship_id]);
$absent_count = (int)$absent_stmt->fetchColumn();

// Overall internship attendance details for tooltips (all weeks)
$present_dates = [];
$absent_logs = [];

$pd_stmt = $pdo->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present' ORDER BY log_date ASC");
$pd_stmt->execute([$internship_id]);
$present_dates = $pd_stmt->fetchAll(PDO::FETCH_COLUMN);

$ad_stmt = $pdo->prepare("SELECT log_date, reason_for_absence FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
$ad_stmt->execute([$internship_id]);
$absent_logs = $ad_stmt->fetchAll();

// Weekly Reflection unlock logic
$weekly_log_count = 0;
$reflection_submitted = false;
if (!empty($weeks)) {
    $ws = $weeks[$selected_week]['start'];
    $we = $weeks[$selected_week]['end'];
    $wls = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $wls->execute([$internship_id, $ws, $we]);
    $weekly_log_count = (int)$wls->fetchColumn();
}
$reflection_unlocked = $weekly_log_count >= 5;

$rc = $pdo->prepare("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$rc->execute([$internship_id, $selected_week]);
$reflection_submitted = (int)$rc->fetchColumn() > 0;

// Check if instructor rejected this week's report
$rej_stmt = $pdo->prepare("SELECT report_status, instructor_comments FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$rej_stmt->execute([$internship_id, $selected_week]);
$rejection = $rej_stmt->fetch();
$is_rejected = $rejection && $rejection['report_status'] === 'rejected';
$rejection_reason = $is_rejected ? ($rejection['instructor_comments'] ?? '') : '';

// Rejected status overrides lock conditions — student can always edit when rejected
if ($is_rejected) {
    $reflection_unlocked = true;
    $magic_link_unlocked = true;
} else {
    // Normal flow: 5 daily logs AND a reflection required
    $magic_link_unlocked = $reflection_unlocked && $reflection_submitted;
}

// Handle magic link generation POST
if ($magic_link_unlocked && isset($_POST['generate_magic_link'])) {
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $stmt = $pdo->prepare("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
    $stmt->execute([$internship_id, $selected_week, $token, $expires_at]);

    $magic_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
        . '/instructor/view-report.php?token=' . $token;
}

$reflections = $pdo->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? ORDER BY week_number DESC");
$reflections->execute([$internship_id]);
$weekly_refs = $reflections->fetchAll();

// Fetch existing valid token for the selected week
$active_token = $pdo->prepare("SELECT token, expires_at FROM magic_links WHERE internship_id = ? AND week_number = ? AND expires_at > NOW() LIMIT 1");
$active_token->execute([$internship_id, $selected_week]);
$existing_link = $active_token->fetch();
if ($existing_link && !$magic_link) {
    $magic_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
        . '/instructor/view-report.php?token=' . $existing_link['token'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
    function toggleProfileDropdown(e) {
        e.stopPropagation();
        var dd = document.getElementById('profile-dropdown-menu');
        dd.classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
    function calcHours() {
        var start = document.getElementById('start_time').value;
        var end   = document.getElementById('end_time').value;
        if (start && end) {
            var s = start.split(':'), e = end.split(':');
            var sm = parseInt(s[0]) * 60 + parseInt(s[1]);
            var em = parseInt(e[0]) * 60 + parseInt(e[1]);
            if (em < sm) em += 1440;
            var diff = em - sm;
            var h = Math.floor(diff / 60);
            var m = diff % 60;
            var text = h > 0 ? h + ' Hours' : '';
            if (m > 0) text += (text ? ' ' : '') + m + ' Mins';
            document.getElementById('hours_display').value = text || '0 Hours';
            document.getElementById('hours_worked').value = text || '0 Hours';
        }
    }

    function calcHoursNow() { calcHours(); }

    function copyLink() {
        var input = document.getElementById('magic_link_input');
        if (!input || !input.value) return;
        navigator.clipboard.writeText(input.value).then(function () {
            var btn = document.getElementById('copy_btn');
            btn.textContent = '✓ Copied!';
            setTimeout(function () { btn.textContent = '📋 Copy Link'; }, 2000);
        });
    }

    function showProfile() {
        document.getElementById('section-profile').classList.remove('hidden');
        document.getElementById('section-main').classList.add('hidden');
        document.querySelectorAll('.nav-link').forEach(function (el) { el.classList.remove('active-nav'); });
        var link = document.querySelector('[data-section="profile"]');
        if (link) link.classList.add('active-nav');
    }

    function showDashboard() {
        document.getElementById('section-profile').classList.add('hidden');
        document.getElementById('section-main').classList.remove('hidden');
        document.querySelectorAll('.nav-link').forEach(function (el) { el.classList.remove('active-nav'); });
        var link = document.querySelector('[data-section="dashboard"]');
        if (link) link.classList.add('active-nav');
    }

    function toggleWeekDropdown() {
        document.getElementById('week-menu').classList.toggle('hidden');
    }

    function toggleAttendance() {
        var status = document.querySelector('input[name="attendance_status"]:checked').value;
        var present = document.getElementById('present-fields');
        var absent  = document.getElementById('absent-fields');
        if (status === 'absent') {
            present.classList.add('hidden');
            absent.classList.remove('hidden');
        } else {
            present.classList.remove('hidden');
            absent.classList.add('hidden');
        }
    }

    window.onload = function () {
        document.addEventListener('click', function (e) {
            var dd = document.getElementById('week-dropdown');
            if (dd && !dd.contains(e.target)) {
                document.getElementById('week-menu').classList.add('hidden');
            }
        });
        <?php if ($message === 'daily_saved'): ?>
        alert('Daily log saved successfully.');
        <?php elseif ($message === 'reflection_saved'): ?>
        alert('Weekly reflection saved successfully.');
        <?php endif; ?>
    };
    </script>
    <style>
    .active-nav { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-64 bg-white/80 backdrop-blur-xl border-r border-slate-200/60 flex flex-col shrink-0 shadow-xl shadow-slate-200/20">
        <div class="h-16 flex items-center px-6 border-b border-slate-100/80 bg-gradient-to-r from-indigo-500/5 to-purple-500/5">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                    <span class="text-white text-sm">📋</span>
                </div>
                <span class="text-sm font-extrabold text-slate-800 tracking-tight">InternReport</span>
            </div>
        </div>
        <nav class="flex-1 py-5 px-3 space-y-1">
            <a href="#" class="nav-link active-nav flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200" data-section="dashboard" onclick="showDashboard()">
                <span class="text-base">📝</span> Dashboard
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-slate-100/80">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-all duration-200">
                <span class="text-base">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm">
            <h1 class="text-base font-bold text-slate-800">Student Dashboard</h1>
            <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                    <?php if (!empty($_SESSION['profile_pic'])): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-indigo-500/20">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-indigo-500/20">
                        <?= strtoupper($username[0]) ?>
                    </div>
                    <?php endif; ?>
                </button>
                <div class="text-right">
                    <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($username) ?></p>
                    <p class="text-[10px] text-slate-400 capitalize"><?= htmlspecialchars($role) ?></p>
                </div>
                <!-- Profile Dropdown Menu -->
                <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 z-50 bg-white border border-slate-200 rounded-xl shadow-xl w-48 py-2">
                    <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                        <span>👤</span> My Profile
                    </a>
                    <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                        <span>🔑</span> Change Password
                    </a>
                    <div class="my-1 border-t border-slate-100"></div>
                    <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-500 hover:bg-red-50 transition">
                        <span>🚪</span> Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">

            <?php if ($is_rejected): ?>
            <!-- ════ REJECTION ALERT BANNER ════ -->
            <div class="bg-gradient-to-r from-red-50 to-red-100/50 border border-red-200/60 rounded-2xl p-6 mb-6 shadow-sm">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center text-xl shadow-lg shadow-red-500/30 shrink-0">❌</div>
                    <div class="flex-1">
                        <h3 class="text-sm font-bold text-red-700 mb-1">Your report for Week <?= $selected_week ?> was rejected by the Instructor</h3>
                        <?php if ($rejection_reason): ?>
                        <div class="bg-white rounded-xl border border-red-200/60 p-4 mt-3">
                            <p class="text-[11px] font-bold text-red-400 uppercase tracking-wider mb-2">Reason</p>
                            <p class="text-sm text-red-600 leading-relaxed"><?= nl2br(htmlspecialchars($rejection_reason)) ?></p>
                        </div>
                        <?php endif; ?>
                        <p class="text-xs text-red-500 mt-3">Please revise your daily logs and weekly reflection, then regenerate a new Magic Link to resubmit.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════ MAIN DASHBOARD (2-COLUMN LAYOUT) ══════ -->
            <section id="section-main" class="max-w-7xl mx-auto space-y-6">

                <?php if ($student_info): ?>
                <!-- ══════ INTERNSHIP INFO BANNER ══════ -->
                <div class="bg-white shadow-xs rounded-2xl border border-gray-100 p-5">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">📋</span>
                        <h2 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Internship Information</h2>
                    </div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm shrink-0">🏢</div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Company</p>
                                <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($student_info['company_name'] ?: 'Not assigned') ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm shrink-0">👩‍🏫</div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Supervisor</p>
                                <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($student_info['supervisor_name'] ?: 'Not assigned') ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Start Date</p>
                                <p class="text-sm font-bold text-slate-800 truncate"><?= $student_info['internship_start_date'] ? (new DateTime($student_info['internship_start_date']))->format('d M Y') : '—' ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center text-sm shrink-0">🏁</div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">End Date</p>
                                <p class="text-sm font-bold text-slate-800 truncate"><?= $student_info['internship_end_date'] ? (new DateTime($student_info['internship_end_date']))->format('d M Y') : '—' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ══════ FILTER & TRACKER ROW ══════ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <!-- Left: Week Dropdown + Clear -->
                        <div class="flex items-center gap-3">
                            <div class="relative" id="week-dropdown">
                                <button onclick="toggleWeekDropdown()" class="flex items-center gap-2 bg-gradient-to-r from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-2 text-sm font-semibold text-slate-700 hover:border-indigo-300 transition-all duration-200 cursor-pointer whitespace-nowrap shadow-sm">
                                    📆 Week <?= $selected_week ?>
                                    <span class="text-slate-400 text-[10px]">▾</span>
                                </button>
                                <div id="week-menu" class="absolute left-0 top-full mt-2 w-52 bg-white border border-slate-200/60 rounded-xl shadow-xl z-50 hidden overflow-hidden">
                                    <?php if (!empty($weeks)): ?>
                                        <?php foreach ($weeks as $wn => $wr): ?>
                                        <a href="?week=<?= $wn ?>" class="flex items-center justify-between px-4 py-2.5 text-sm font-semibold <?= $selected_week === $wn ? 'bg-gradient-to-r from-indigo-500 to-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50' ?> transition">
                                            Week <?= $wn ?>
                                            <span class="text-[10px] <?= $selected_week === $wn ? 'text-indigo-200' : 'text-slate-400' ?>"><?= $wr['start'] ?></span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="px-4 py-3 text-sm text-slate-400">No logs yet</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="student-dashboard.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-sm rounded-xl transition-all duration-200 cursor-pointer">✕ Clear</a>
                        </div>

                        <!-- Right: Attendance Counters with Tooltips -->
                        <div class="flex items-center gap-3">
                            <!-- Present Tooltip -->
                            <div class="relative group">
                                <div class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-emerald-50 to-emerald-100/50 border border-emerald-200/60 rounded-xl cursor-pointer hover:shadow-md transition-all duration-200">
                                    <span class="text-xs font-bold text-emerald-600">✅ Present</span>
                                    <span class="text-lg font-black text-emerald-700"><?= $present_count ?></span>
                                </div>
                                <div class="absolute right-0 top-full mt-2 w-64 bg-white border border-slate-200/60 rounded-xl shadow-xl z-50 hidden group-hover:block">
                                    <div class="p-4">
                                        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-3">All Present Dates</p>
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
                                        <p class="text-[11px] text-slate-400 mt-3 pt-3 border-t border-slate-100">Total: <?= count($present_dates) ?> day<?= count($present_dates) !== 1 ? 's' : '' ?></p>
                                    </div>
                                </div>
                            </div>
                            <!-- Absent Tooltip -->
                            <div class="relative group">
                                <div class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-50 to-red-100/50 border border-red-200/60 rounded-xl cursor-pointer hover:shadow-md transition-all duration-200">
                                    <span class="text-xs font-bold text-red-600">❌ Absent</span>
                                    <span class="text-lg font-black text-red-700"><?= $absent_count ?></span>
                                </div>
                                <div class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200/60 rounded-xl shadow-xl z-50 hidden group-hover:block">
                                    <div class="p-4">
                                        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-3">All Absent Dates</p>
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
                                        <p class="text-[11px] text-slate-400 mt-3 pt-3 border-t border-slate-100">Total: <?= count($absent_logs) ?> day<?= count($absent_logs) !== 1 ? 's' : '' ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- ─── LEFT COLUMN (2/3): Daily Log + Weekly Reflection ─── -->
                    <div class="lg:col-span-2 space-y-6">

                        <!-- Daily Log Sheet Form -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📝</span> Daily Log Sheet
                                </h2>
                                <?php if ($week_date_range): ?>
                                <span class="flex items-center gap-1.5 text-xs font-bold text-blue-600 bg-blue-50 border border-blue-200/60 px-3 py-1.5 rounded-lg">
                                    📅 <?= $week_date_range ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="p-6">
                                <form method="POST" class="space-y-5">
                                    <!-- Date (always visible) -->
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-2">📅 Date / Day</label>
                                        <input type="date" name="log_date" required class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 shadow-sm">
                                    </div>

                                    <!-- Attendance Status -->
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-3">✅ Attendance Status <span class="text-slate-300 font-normal">/ တက်ရောက်မှုအခြေအနေ</span></label>
                                        <div class="flex items-center gap-4">
                                            <label class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-br from-emerald-50 to-emerald-100/50 border border-emerald-200/60 rounded-xl cursor-pointer hover:shadow-md transition-all duration-200">
                                                <input type="radio" name="attendance_status" value="present" checked onchange="toggleAttendance()" class="accent-emerald-600">
                                                <span class="text-sm font-bold text-emerald-700">Present / တက်ရောက်</span>
                                            </label>
                                            <label class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-br from-red-50 to-red-100/50 border border-red-200/60 rounded-xl cursor-pointer hover:shadow-md transition-all duration-200">
                                                <input type="radio" name="attendance_status" value="absent" onchange="toggleAttendance()" class="accent-red-600">
                                                <span class="text-sm font-bold text-red-700">Absent / ခွင့်/ပျက်ကွက်</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- ══════ PRESENT FIELDS ══════ -->
                                    <div id="present-fields">
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-xs font-bold text-slate-500 mb-2">⏱️ Start Time</label>
                                                <input type="time" name="start_time" id="start_time" value="09:00" onchange="calcHours()" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 shadow-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-500 mb-2">⏱️ End Time</label>
                                                <input type="time" name="end_time" id="end_time" value="17:00" onchange="calcHours()" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 shadow-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-500 mb-2">⏳ Duration</label>
                                                <input type="text" id="hours_display" value="8 Hours" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono text-blue-700 font-bold focus:outline-none cursor-default">
                                                <input type="hidden" name="hours_worked" id="hours_worked" value="8 Hours">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 mb-2">💡 Intended Task <span class="text-slate-300 font-normal">/ ဆောင်ရွက်မည့်လုပ်ငန်း</span></label>
                                            <input type="text" name="intended_task" placeholder="e.g. UI Design & API Integration" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 shadow-sm placeholder:text-slate-400">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 mb-2">📋 Task Detail <span class="text-slate-300 font-normal">/ ဆောင်ရွက်မည့် လုပ်ငန်းစဉ်များ</span></label>
                                            <textarea name="task_detail" rows="3" placeholder="Describe the planned tasks in detail…" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 resize-none shadow-sm placeholder:text-slate-400"></textarea>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 mb-2">✅ Actual Task Performed <span class="text-slate-300 font-normal">/ အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</span></label>
                                            <textarea name="actual_task" rows="3" placeholder="What you actually accomplished today…" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 resize-none shadow-sm placeholder:text-slate-400"></textarea>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-xs font-bold text-slate-500 mb-2">🛠️ Tools / Tech Used <span class="text-slate-300 font-normal">/ အသုံးပြုသောပစ္စည်းများ</span></label>
                                                <input type="text" name="tools_used" placeholder="PHP, TailwindCSS, MySQL…" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono text-emerald-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 shadow-sm placeholder:text-slate-400">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-500 mb-2">🧠 Knowledge Gained <span class="text-slate-300 font-normal">/ လေ့လာသိရှိသော အသိပညာ</span></label>
                                                <input type="text" name="knowledge_gained" placeholder="Database optimization, REST APIs…" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 shadow-sm placeholder:text-slate-400">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ══════ ABSENT FIELDS ══════ -->
                                    <div id="absent-fields" class="hidden">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 mb-2">📝 Reason for Absence <span class="text-slate-300 font-normal">/ ခွင့်ယူရသည့်အကြောင်းအရင်း</span></label>
                                            <textarea name="reason_for_absence" rows="2" placeholder="Please state your reason for absence…" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 resize-none shadow-sm placeholder:text-slate-400"></textarea>
                                        </div>
                                    </div>

                                    <div class="flex justify-end pt-2">
                                        <button type="submit" name="add_log" class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-blue-500/30 transition-all duration-200 cursor-pointer hover:scale-[1.02]">💾 Save Daily Log</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Daily Log History -->
                        <?php include 'daily_logs_table.php'; ?>

                        <!-- Weekly Reflection Form -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden <?= !$reflection_unlocked ? 'opacity-50 pointer-events-none select-none' : '' ?>">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span> Weekly Reflection
                                    <?php if (!$reflection_unlocked): ?>
                                    <span class="ml-auto flex items-center gap-1.5 text-xs font-bold text-slate-400 bg-slate-100 px-3 py-1 rounded-lg">🔒 Locked (<?= $weekly_log_count ?>/5)</span>
                                    <?php else: ?>
                                    <span class="ml-auto flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-3 py-1 rounded-lg border border-emerald-200/60">✅ Unlocked (<?= $weekly_log_count ?>/5)</span>
                                    <?php endif; ?>
                                </h2>
                            </div>
                            <div class="p-6">
                                <?php if (!$reflection_unlocked): ?>
                                <div class="text-center py-8">
                                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">🔒</div>
                                    <p class="text-sm text-slate-500 font-medium">Please complete at least <strong class="text-slate-700">5 daily logs</strong> for <strong class="text-slate-700">Week <?= $selected_week ?></strong> to unlock this form.</p>
                                    <p class="text-xs text-slate-400 mt-2">You currently have <strong><?= $weekly_log_count ?>/5</strong></p>
                                </div>
                                <?php endif; ?>
                                <form method="POST" class="space-y-5 <?= !$reflection_unlocked ? 'hidden' : '' ?>">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-2">📆 Week Number</label>
                                        <input type="number" name="week_number" value="<?= $selected_week ?>" readonly class="w-full bg-slate-100 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-indigo-600 focus:outline-none cursor-default">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-2">❓ What was done? <span class="text-slate-300 font-normal">/ ဘာလုပ်သလဲ</span></label>
                                        <textarea name="what_done" rows="3" required placeholder="What did you accomplish this week?" class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 resize-none shadow-sm placeholder:text-slate-400"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-2">⚙️ How was it done? <span class="text-slate-300 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></label>
                                        <textarea name="how_done" rows="3" required placeholder="Describe the methods, tools, and approach you used." class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 resize-none shadow-sm placeholder:text-slate-400"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 mb-2">🎯 Why was it done? <span class="text-slate-300 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></label>
                                        <textarea name="why_done" rows="3" required placeholder="Explain the purpose, goals, and expected outcomes." class="w-full bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 resize-none shadow-sm placeholder:text-slate-400"></textarea>
                                    </div>
                                    <div class="flex justify-end pt-2">
                                        <button type="submit" name="add_reflection" class="px-6 py-3 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-emerald-500/30 transition-all duration-200 cursor-pointer hover:scale-[1.02]">💾 Save Reflection</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Weekly Reflections History -->
                        <?php include 'weekly_reflections_table.php'; ?>
                    </div>

                    <!-- ─── RIGHT COLUMN (1/3): Magic Link ─── -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden sticky top-6">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-purple-50 text-purple-500 flex items-center justify-center text-sm">🔗</span> Magic Link
                                    <?php if (!$magic_link_unlocked): ?>
                                    <span class="ml-auto text-xs font-bold text-slate-400 bg-slate-100 px-3 py-1 rounded-lg">🔒 Locked</span>
                                    <?php endif; ?>
                                </h2>
                            </div>
                            <div class="p-6">
                                <p class="text-xs text-slate-400 mb-5 leading-relaxed">
                                    Generate a secure link to share with your Company Instructor. They can view your reports without creating an account.
                                </p>

                                <?php if ($magic_link_unlocked): ?>

                                    <?php if ($is_rejected): ?>
                                    <div class="bg-gradient-to-r from-red-50 to-red-100/50 border border-red-200/60 rounded-xl p-4 mb-4">
                                        <p class="text-xs font-bold text-red-600 flex items-center gap-2">
                                            <span>🔄</span> Report was rejected. Update your logs and reflection, then regenerate a fresh link.
                                        </p>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($magic_link): ?>
                                    <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl p-4 mb-4">
                                        <label class="block text-[11px] font-bold text-slate-400 mb-2 uppercase tracking-wider">Your Magic Link</label>
                                        <input type="text" id="magic_link_input" value="<?= htmlspecialchars($magic_link) ?>" readonly class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-600 focus:outline-none">
                                    </div>
                                    <button id="copy_btn" onclick="copyLink()" class="w-full px-4 py-3 bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-purple-500/30 transition-all duration-200 cursor-pointer hover:scale-[1.02] mb-2">📋 Copy Link</button>
                                    <p class="text-[11px] text-slate-400 text-center font-medium">Link expires in 7 days.</p>
                                    <?php else: ?>
                                    <form method="POST">
                                        <button type="submit" name="generate_magic_link" class="w-full px-4 py-3 bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-purple-500/30 transition-all duration-200 cursor-pointer hover:scale-[1.02]">🔗 Generate Magic Link</button>
                                    </form>
                                    <p class="text-[11px] text-slate-400 text-center mt-3 font-medium">No active link yet.</p>
                                    <?php endif; ?>

                                <?php else: ?>
                                <div class="bg-gradient-to-r from-amber-50 to-amber-100/50 border border-amber-200/60 rounded-xl p-4 mb-4">
                                    <div class="flex items-start gap-3">
                                        <div class="w-6 h-6 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center text-xs shrink-0">⚠️</div>
                                        <div>
                                            <p class="text-xs font-bold text-amber-700 mb-2">Requirements not met for Week <?= $selected_week ?></p>
                                            <ul class="text-[11px] text-amber-600 space-y-1">
                                                <li><?= $weekly_log_count >= 5 ? '✅' : '❌' ?> Daily Logs: <strong><?= $weekly_log_count ?>/5</strong> days</li>
                                                <li><?= $reflection_submitted ? '✅' : '❌' ?> Weekly Reflection: <strong><?= $reflection_submitted ? 'Submitted' : 'Not yet' ?></strong></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div class="opacity-50 pointer-events-none select-none">
                                    <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl p-4 mb-4">
                                        <input type="text" readonly value="················" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-slate-300 focus:outline-none">
                                    </div>
                                    <button class="w-full px-4 py-3 bg-gradient-to-r from-purple-400 to-purple-500 text-white font-bold text-sm rounded-xl shadow-sm cursor-not-allowed">🔗 Generate Magic Link</button>
                                </div>
                                <?php endif; ?>

                                <div class="mt-6 pt-5 border-t border-slate-100">
                                    <h3 class="text-xs font-bold text-slate-600 mb-3">How to share:</h3>
                                    <ul class="text-[11px] text-slate-400 space-y-2 list-disc list-inside">
                                        <li>Copy the link above</li>
                                        <li>Paste into Email, Viber, Telegram, etc.</li>
                                        <li>Instructor clicks link → sees your reports</li>
                                        <li>No login required for them</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <!-- ══════ SECTION: PROFILE (redirect) ══════ -->
            <section id="section-profile" class="hidden">
                <div class="max-w-lg mx-auto text-center py-20">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-3xl font-bold mx-auto mb-4 shadow-xl shadow-indigo-500/30"><?= strtoupper($username[0]) ?></div>
                    <h3 class="text-xl font-bold text-slate-800 mb-1"><?= htmlspecialchars($username) ?></h3>
                    <p class="text-sm text-slate-400 capitalize mb-6"><?= htmlspecialchars($role) ?></p>
                    <a href="profile.php" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-indigo-500/30 transition-all duration-200">👤 View Full Profile</a>
                </div>
            </section>

        </main>
    </div>
</div>

</body>
</html>
