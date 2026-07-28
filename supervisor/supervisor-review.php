<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id = $_SESSION['user_id'];
$student_id = (int) ($_GET['student_id'] ?? 0);
$week_num = (int) ($_GET['week'] ?? 0);

if ($student_id <= 0) {
    header('Location: supervisor-dashboard.php');
    exit;
}

// ── Verify this student belongs to this supervisor ─────────────────
$stu = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.academic_year, u.created_at,
           sp.full_name, sp.student_roll, sp.major, sp.company_name,
           sp.job_role, sp.phone, sp.instructor_name, sp.instructor_email,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ? AND u.role = 'student' AND sp.supervisor_id = ?
");
$stu->execute([$student_id, $sup_id]);
$student = $stu->fetch();

if (!$student) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$student_name = $student['full_name'] ?: $student['username'];

// ── Determine Week Ranges from internship start date ───────────────
$weeks = [];
if ($student['internship_start_date']) {
    $start_dt = new DateTime($student['internship_start_date']);
    // Generate 12 weeks from start date
    for ($i = 1; $i <= 12; $i++) {
        $ws = clone $start_dt;
        if ($i > 1) $ws->modify('+' . (($i - 1) * 7) . ' days');
        $we = (clone $ws)->modify('+6 days');
        $weeks[$i] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d')];
    }
} else {
    // Fallback: build from log dates
    $all_dates = $pdo->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
    $all_dates->execute([$student_id]);
    $log_dates = $all_dates->fetchAll(PDO::FETCH_COLUMN);
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
$max_week = 12;
$not_started = false;

if ($student['internship_start_date']) {
    $today_obj = new DateTime();
    $start_date = new DateTime($student['internship_start_date']);
    $end_date = !empty($student['internship_end_date']) ? new DateTime($student['internship_end_date']) : null;

    if ($today_obj < $start_date) {
        $auto_week = 1;
        $not_started = true;
    } elseif ($end_date && $today_obj > $end_date) {
        $auto_week = $max_week;
    } else {
        $days_elapsed = (int) $today_obj->diff($start_date)->days;
        $auto_week = (int) floor($days_elapsed / 7) + 1;
        $auto_week = max(1, min($auto_week, $max_week));
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
$present_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present'");
$present_q->execute([$student_id]);
$total_present = (int) $present_q->fetchColumn();

$absent_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave')");
$absent_q->execute([$student_id]);
$total_absent = (int) $absent_q->fetchColumn();

$total_logs = $total_present + $total_absent;

// ── Attendance Details for Tooltips ────────────────────────────────
$pd_stmt = $pdo->prepare("SELECT log_date FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present' ORDER BY log_date ASC");
$pd_stmt->execute([$student_id]);
$present_dates = $pd_stmt->fetchAll(PDO::FETCH_COLUMN);

$ad_stmt = $pdo->prepare("SELECT log_date, reason_for_absence FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
$ad_stmt->execute([$student_id]);
$absent_logs = $ad_stmt->fetchAll();

// ══════════════════════════════════════════════════════════════════════
// METHOD 1: WEEKLY GRADING HISTORY FOR ALL 12 WEEKS
// ══════════════════════════════════════════════════════════════════════
$filter_start_date = $_GET['filter_start'] ?? '';
$filter_end_date   = $_GET['filter_end'] ?? '';

$all_weeks_grades = [];
for ($i = 1; $i <= 12; $i++) {
    $gq = $pdo->prepare("SELECT weekly_grade, supervisor_comments, evaluated_at FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
    $gq->execute([$student_id, $i]);
    $all_weeks_grades[$i] = $gq->fetch();
}

// Determine which weeks to display based on date range filter
$visible_weeks = [];
foreach ($weeks as $wn => $wr) {
    $show_week = true;

    // Apply date range filter if set
    if ($filter_start_date && $filter_end_date) {
        $week_start_dt = new DateTime($wr['start']);
        $week_end_dt   = new DateTime($wr['end']);
        $filter_start_dt = new DateTime($filter_start_date);
        $filter_end_dt   = new DateTime($filter_end_date);

        // Show week if it overlaps with the filter range
        if ($week_end_dt < $filter_start_dt || $week_start_dt > $filter_end_dt) {
            $show_week = false;
        }
    }

    if ($show_week) {
        $visible_weeks[$wn] = $wr;
    }
}

// ── Fetch Data for Active Week ─────────────────────────────────────
$daily_logs = [];
$reflection = null;
$instructor_eval = null;
$supervisor_eval = $all_weeks_grades[$week_num] ?? null;

if ($week_start && $week_end) {
    $dl = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC");
    $dl->execute([$student_id, $week_start, $week_end]);
    $daily_logs = $dl->fetchAll();

    $rf = $pdo->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
    $rf->execute([$student_id, $week_num]);
    $reflection = $rf->fetch();

    $ie = $pdo->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $ie->execute([$student_id, $week_num]);
    $instructor_eval = $ie->fetch();
}

// ── Handle Supervisor Evaluation Submission (Method 1) ─────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sup_eval'])) {
    $grade    = $_POST['weekly_grade'] ?? '';
    $comments = trim($_POST['supervisor_comments'] ?? '');
    $allowed  = ['A', 'B', 'C', 'D', 'F'];

    if (!in_array($grade, $allowed, true)) {
        $msg = 'invalid_grade';
    } else {
        $upsert = $pdo->prepare("
            INSERT INTO supervisor_weekly_evaluations (student_id, week_number, supervisor_id, weekly_grade, supervisor_comments)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            weekly_grade = VALUES(weekly_grade),
            supervisor_comments = VALUES(supervisor_comments),
            evaluated_at = NOW()
        ");
        $upsert->execute([$student_id, $week_num, $sup_id, $grade, $comments]);

        // Update the main report status to approved_by_supervisor
        $update_status = $pdo->prepare("
            UPDATE report_evaluations SET report_status = 'approved_by_supervisor'
            WHERE student_id = ? AND week_number = ?
        ");
        $update_status->execute([$student_id, $week_num]);

        // Re-fetch current week evaluation
        $gq = $pdo->prepare("SELECT weekly_grade, supervisor_comments, evaluated_at FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
        $gq->execute([$student_id, $week_num]);
        $supervisor_eval = $gq->fetch();
        
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

// Compute attendance rate
$attendance_rate = $total_logs > 0 ? round(($total_present / $total_logs) * 100) : 0;

// Compute overall GPA for this student
$student_gpa = 0;
$student_graded = 0;
$gpa_weighted = 0;
foreach ($all_weeks_grades as $wg) {
    if ($wg && isset($wg['weekly_grade'])) {
        $student_graded++;
        $gpa_weighted += ($grade_point_map[$wg['weekly_grade']] ?? 0);
    }
}
if ($student_graded > 0) {
    $student_gpa = round($gpa_weighted / $student_graded, 2);
}
$grade_point_map = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'F' => 0];
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
    <aside class="w-64 bg-white/80 backdrop-blur-xl border-r border-slate-200/60 flex flex-col shrink-0 shadow-xl shadow-slate-200/20">
        <div class="h-16 flex items-center px-6 border-b border-slate-100/80 bg-gradient-to-r from-indigo-500/5 to-purple-500/5">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                    <span class="text-white text-sm">📋</span>
                </div>
                <div>
                    <span class="text-sm font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    <span class="block text-sm font-bold text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded mt-0.5">SUPERVISOR</span>
                </div>
            </div>
        </div>
        <nav class="flex-1 py-5 px-3 space-y-1">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="#" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🎓</span> Student Review
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>
        </nav>

        <!-- ─── ARCHIVES / HISTORY ─── -->
        <div class="px-4 mb-2">
            <h3 class="text-xs font-bold text-slate-500 tracking-wider uppercase mb-2 px-4">Archives / History</h3>
        </div>
        <a href="supervisor-dashboard.php?tab=trainee-archive" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
            <span class="w-5 h-5 flex items-center justify-center shrink-0">⏪</span> My 2025 Trainees
        </a>

        <div class="p-3 border-t border-slate-100/80">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-subtitle leading-relaxed font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm">
            <div class="flex items-center gap-4">
                <a href="supervisor-dashboard.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-lg transition-all duration-200">
                    ← Back
                </a>
                <h1 class="text-base font-bold text-slate-800">Student Review — <?= htmlspecialchars($student_name) ?></h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-indigo-50 border border-indigo-200 rounded-full">
                    <span class="text-xs font-bold text-indigo-700">📅 Week <?= $week_num ?>/12</span>
                    <?php if ($week_num === $auto_week): ?>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                    <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                        <?php if (!empty($_SESSION['profile_pic'])): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-indigo-500/20">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-indigo-500/20">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </button>
                    <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 z-50 bg-white border border-slate-200 rounded-xl shadow-xl w-48 py-2">
                        <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                            <span>👤</span> My Profile
                        </a>
                        <div class="my-1 border-t border-slate-100"></div>
                        <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-500 hover:bg-red-50 transition">
                            <span>🚪</span> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

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
        </div>
    </div>

    <!-- ════ STUDENT PERFORMANCE CARDS ════ -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-lg shadow-lg shadow-emerald-500/30">✅</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Attendance</p>
                    <p class="text-xl font-black text-slate-800"><?= $attendance_rate ?>%</p>
                    <p class="text-sm text-emerald-500 font-bold"><?= $total_present ?>/<?= $total_logs ?> days</p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-lg shadow-lg shadow-blue-500/30">📊</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">GPA</p>
                    <p class="text-xl font-black text-slate-800"><?= $student_gpa > 0 ? number_format($student_gpa, 1) : '—' ?></p>
                    <p class="text-sm text-blue-500 font-bold"><?= $student_graded ?>/12 graded</p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center text-lg shadow-lg shadow-indigo-500/30">📅</div>
                <div>
                    <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Current Week</p>
                    <p class="text-xl font-black text-slate-800"><?= $week_num ?><span class="text-sm font-bold text-slate-400">/12</span></p>
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
                    <p class="text-xl font-black text-slate-800"><?= count($daily_logs) ?></p>
                    <p class="text-sm <?= count($daily_logs) >= 5 ? 'text-emerald-500' : 'text-amber-500' ?> font-bold"><?= count($daily_logs) >= 5 ? 'Complete' : count($daily_logs) . '/5 minimum' ?></p>
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

    <!-- ════ 2-COLUMN LAYOUT ════ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- ─── LEFT (2/3): Daily Logs + Reflection ─── -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Daily Logs -->
            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📝</span> Daily Logs – Week <?= $week_num ?>
                    </h2>
                    <span class="text-xs text-slate-400 font-medium"><?= count($daily_logs) ?> day(s)</span>
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
            <?php if ($instructor_eval && $instructor_eval['report_status'] === 'approved_by_instructor'): ?>
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

        <!-- ─── RIGHT (1/3): Grading History ─── -->
        <div class="lg:col-span-1 space-y-6">

            <!-- ═══ 12-WEEK GRADING HISTORY SIDEBAR ═══ -->
            <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-500 flex items-center justify-center text-sm">📋</span> Grading History
                    </h2>
                </div>

                <!-- Date Range Filter -->
                <div class="px-5 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50/50 to-white">
                    <form method="GET" class="space-y-3">
                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                        <?php if ($week_num > 0): ?>
                        <input type="hidden" name="week" value="<?= $week_num ?>">
                        <?php endif; ?>
                        <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Filter by Date Range</p>
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <label class="block text-sm text-slate-400 mb-1 font-medium">From</label>
                                <input type="date" name="filter_start" value="<?= htmlspecialchars($filter_start_date) ?>"
                                    class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 shadow-sm">
                            </div>
                            <div class="flex-1">
                                <label class="block text-sm text-slate-400 mb-1 font-medium">To</label>
                                <input type="date" name="filter_end" value="<?= htmlspecialchars($filter_end_date) ?>"
                                    class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 shadow-sm">
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="flex-1 px-3 py-2 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white text-xs font-bold rounded-xl transition-all duration-200 shadow-md shadow-indigo-500/20">
                                Apply
                            </button>
                            <?php if ($filter_start_date || $filter_end_date): ?>
                            <a href="?student_id=<?= $student_id ?>&week=<?= $week_num ?>" class="flex-1 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all duration-200 text-center">
                                Clear
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if ($filter_start_date || $filter_end_date): ?>
                        <p class="text-sm text-slate-400 text-center font-medium">
                            Showing <?= count($visible_weeks) ?> of <?= count($weeks) ?> weeks
                        </p>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Week Grid -->
                <div class="p-5 grid grid-cols-4 gap-2.5">
                    <?php if (!empty($visible_weeks)): ?>
                    <?php foreach ($visible_weeks as $i => $wr):
                        $week_data = $all_weeks_grades[$i];
                        $g = $week_data['weekly_grade'] ?? null;

                        // Color logic per grade
                        $color_class = 'border-slate-200 text-slate-400';
                        $bg_class = 'bg-slate-50';
                        $grade_display = '—';

                        if ($g === 'A') {
                            $color_class = 'border-emerald-300 text-emerald-700';
                            $bg_class = 'bg-gradient-to-br from-emerald-50 to-emerald-100/50';
                            $grade_display = 'A';
                        } elseif ($g === 'B') {
                            $color_class = 'border-blue-300 text-blue-700';
                            $bg_class = 'bg-gradient-to-br from-blue-50 to-blue-100/50';
                            $grade_display = 'B';
                        } elseif ($g === 'C') {
                            $color_class = 'border-amber-300 text-amber-700';
                            $bg_class = 'bg-gradient-to-br from-amber-50 to-amber-100/50';
                            $grade_display = 'C';
                        } elseif ($g === 'D') {
                            $color_class = 'border-orange-300 text-orange-700';
                            $bg_class = 'bg-gradient-to-br from-orange-50 to-orange-100/50';
                            $grade_display = 'D';
                        } elseif ($g === 'F') {
                            $color_class = 'border-red-300 text-red-700';
                            $bg_class = 'bg-gradient-to-br from-red-50 to-red-100/50';
                            $grade_display = 'F';
                        }
                    ?>
                    <div class="text-center p-3 rounded-xl border-2 <?= $color_class ?> <?= $bg_class ?> <?= $i === $week_num ? 'ring-2 ring-indigo-500 ring-offset-2' : '' ?> <?= $i === $auto_week ? 'ring-2 ring-emerald-400 ring-offset-1' : '' ?> transition-all duration-200 hover:scale-105 hover:shadow-md cursor-pointer" title="Week <?= $i ?>: <?= $grade_display !== '—' ? 'Grade ' . $grade_display . ' (' . date('d M Y', strtotime($week_data['evaluated_at'])) . ')' : 'Not evaluated yet' ?><?= $i === $auto_week ? ' [Current Dynamic Week]' : '' ?>">
                        <p class="text-sm font-bold uppercase tracking-wider <?= $i === $week_num ? 'text-indigo-600' : ($i === $auto_week ? 'text-emerald-600' : 'text-slate-400') ?>">Wk <?= $i ?><?= $i === $auto_week ? ' ✓' : '' ?></p>
                        <p class="text-xl font-black mt-1 <?= $g ? '' : 'text-slate-300' ?>"><?= $grade_display ?></p>
                        <?php if ($week_data): ?>
                            <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 mx-auto mt-1.5"></div>
                        <?php else: ?>
                            <div class="w-1.5 h-1.5 rounded-full bg-slate-300 mx-auto mt-1.5"></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="col-span-4 text-center py-6">
                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-3">📭</div>
                        <p class="text-xs text-slate-400 font-medium">No weeks found in selected range.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="px-5 py-3 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white rounded-b-2xl">
                    <div class="flex items-center justify-center gap-4 text-sm text-slate-400 font-medium flex-wrap">
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Graded</span>
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-300"></span> Pending</span>
                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-400 ring-1 ring-emerald-600"></span> Dynamic Current Week</span>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <div class="text-center text-xs text-slate-400 py-3 font-medium">Powered by InternReport System</div>
</div>

        </main>
    </div>
</div>

</body>
</html>
