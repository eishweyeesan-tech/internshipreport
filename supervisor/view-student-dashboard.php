<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

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

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$student_id = (int) ($_GET['id'] ?? 0);
$sup_id = $_SESSION['user_id'];

// ── No student selected: show student picker ──────────────────────
if ($student_id <= 0) {
    $all_stu_q = $pdo->prepare("
        SELECT u.id AS uid, u.username, u.email,
               sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role
        FROM users u
        JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student' AND sp.supervisor_id = ?
        ORDER BY sp.full_name ASC
    ");
    $all_stu_q->execute([$sup_id]);
    $all_students = $all_stu_q->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Student – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { 'inter': ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">
<div class="flex h-screen overflow-hidden">
    <!-- SIDEBAR -->
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
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">📊</span> Dashboard
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">👤</span> Profile
            </a>
            <a href="view-student-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-indigo-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/30 transition-all duration-200">
                <span class="text-base">🎓</span> Student View
            </a>
        </nav>
        <div class="p-3 border-t border-slate-100/80">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-all duration-200">
                <span class="text-base">🚪</span> Logout
            </a>
        </div>
    </aside>
    <!-- MAIN -->
    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm">
            <div class="flex items-center gap-4">
                <a href="supervisor-dashboard.php" class="inline-flex items-center gap-2 text-xs font-bold text-indigo-600 hover:text-indigo-800 transition">
                    <span class="w-7 h-7 rounded-lg bg-indigo-50 flex items-center justify-center text-sm">←</span> Back
                </a>
                <h1 class="text-base font-bold text-slate-800">Select a Student to View</h1>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 bg-indigo-50 border border-indigo-200 rounded-full">
                <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                <span class="text-xs font-bold text-indigo-700"><?= count($all_students) ?> Student<?= count($all_students) !== 1 ? 's' : '' ?></span>
            </div>
        </header>
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">
                <?php if (!empty($all_students)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($all_students as $stu): ?>
                    <a href="view-student-dashboard.php?id=<?= $stu['uid'] ?>" class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md hover:border-indigo-300 transition-all duration-200 cursor-pointer group">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-lg font-bold shrink-0 shadow-lg shadow-indigo-500/20 group-hover:scale-105 transition-transform">
                                <?= strtoupper(($stu['full_name'] ?: $stu['username'])[0]) ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-slate-800 group-hover:text-indigo-600 transition truncate"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($stu['email']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mt-3 flex-wrap">
                            <?php if ($stu['student_roll']): ?>
                            <span class="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($stu['student_roll']) ?></span>
                            <?php endif; ?>
                            <?php if ($stu['job_role']): ?>
                            <span class="text-[10px] font-bold text-violet-600 bg-violet-50 px-2 py-0.5 rounded">💼 <?= htmlspecialchars($stu['job_role']) ?></span>
                            <?php endif; ?>
                            <?php if ($stu['company_name']): ?>
                            <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">🏢 <?= htmlspecialchars($stu['company_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                    <p class="text-sm text-slate-500 font-medium">No students assigned to you yet.</p>
                    <a href="supervisor-dashboard.php" class="mt-3 inline-block text-xs font-bold text-indigo-600 hover:underline">← Back to Dashboard</a>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
</body>
</html>
<?php exit; }
// ── Student selected: continue with normal page ───────────────────

$check = $pdo->prepare("
    SELECT 1 FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ? AND sp.supervisor_id = ? AND u.role = 'student'
");
$check->execute([$student_id, $sup_id]);
if (!$check->fetch()) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$profile_r = $pdo->prepare("
    SELECT sp.*, u.username, u.email, u.profile_pic,
           sup_u.username AS supervisor_name
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE sp.user_id = ?
");
$profile_r->execute([$student_id]);
$profile = $profile_r->fetch();

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
    $esc_ws = $pdo->quote($weeks[$selected_week]['start']);
    $esc_we = $pdo->quote($weeks[$selected_week]['end']);
    $log_r = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date DESC");
    $log_r->execute([$student_id, $weeks[$selected_week]['start'], $weeks[$selected_week]['end']]);
} else {
    $log_r = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC");
    $log_r->execute([$student_id]);
}
$recent_logs = $log_r->fetchAll();

$ref_r = $pdo->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$ref_r->execute([$student_id, $selected_week]);
$weekly_refs = $ref_r->fetchAll();

$att_r = $pdo->prepare("
    SELECT
        SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
        COUNT(*) AS total_count
    FROM daily_logs WHERE internship_id = ?
");
$att_r->execute([$student_id]);
$att_data = $att_r->fetch();
$attendance_rate = $att_data['total_count'] > 0
    ? round(($att_data['present_count'] / $att_data['total_count']) * 100)
    : 0;

$eval_r = $pdo->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$eval_r->execute([$student_id, $selected_week]);
$evaluation = $eval_r->fetch();

$sup_eval_r = $pdo->prepare("SELECT * FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
$sup_eval_r->execute([$student_id, $selected_week]);
$sup_evaluation = $sup_eval_r->fetch();

$logs_count = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ?");
$logs_count->execute([$student_id]);
$total_logs_count = (int) $logs_count->fetchColumn();

$hours_r = $pdo->prepare("SELECT calculated_duration FROM daily_logs WHERE internship_id = ?");
$hours_r->execute([$student_id]);
$all_durations = $hours_r->fetchAll(PDO::FETCH_COLUMN);
$total_minutes = 0;
foreach ($all_durations as $dur) {
    $parts = explode(':', $dur);
    if (count($parts) === 2) {
        $total_minutes += ((int)$parts[0] * 60) + (int)$parts[1];
    }
}
$total_hours = floor($total_minutes / 60);
$total_mins  = $total_minutes % 60;

$today_obj = new DateTime();
$today_str = $today_obj->format('Y-m-d');
$max_week = 12;
$dynamic_week = 1;
$not_started = false;
if ($intern_start) {
    $start_date = new DateTime($intern_start);
    $end_date = $intern_end ? new DateTime($intern_end) : null;
    if ($today_obj < $start_date) {
        $dynamic_week = 1;
        $not_started = true;
    } elseif ($end_date && $today_obj > $end_date) {
        $dynamic_week = $max_week;
    } else {
        $days_elapsed = (int) $today_obj->diff($start_date)->days;
        $dynamic_week = (int) floor($days_elapsed / 7) + 1;
        $dynamic_week = max(1, min($dynamic_week, $max_week));
    }
} else {
    $not_started = true;
}

$wf_log_count = 0;
if (!empty($weeks)) {
    $wf_lr = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $wf_lr->execute([$student_id, $weeks[$selected_week]['start'], $weeks[$selected_week]['end']]);
    $wf_log_count = (int) $wf_lr->fetchColumn();
}
$wf_step1_done = $wf_log_count >= 3;

$wf_ref_r = $pdo->prepare("SELECT COUNT(*) FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$wf_ref_r->execute([$student_id, $selected_week]);
$wf_step2_done = (int) $wf_ref_r->fetchColumn() > 0;

$wf_step4_status = 'pending';
if ($evaluation) {
    if ($evaluation['report_status'] === 'approved_by_instructor' || $evaluation['report_status'] === 'approved_by_supervisor') {
        $wf_step4_status = 'approved';
    } elseif ($evaluation['report_status'] === 'rejected') {
        $wf_step4_status = 'rejected';
    }
}

$wf_step5_done = $sup_evaluation !== false;
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
                }
            }
        }
    </script>
    <script>
    function toggleWeekDropdown() {
        document.getElementById('week-menu').classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('week-dropdown');
        if (dd && !dd.contains(e.target)) {
            document.getElementById('week-menu').classList.add('hidden');
        }
    });
    </script>
    <style>
    .glow-indigo { box-shadow: 0 4px 20px rgba(99,102,241,0.25); }
    .glow-emerald { box-shadow: 0 4px 20px rgba(16,185,129,0.25); }
    .glow-red { box-shadow: 0 4px 20px rgba(239,68,68,0.25); }
    .glow-amber { box-shadow: 0 4px 20px rgba(245,158,11,0.25); }
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
                <div>
                    <span class="text-sm font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    <span class="block text-sm font-bold text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded mt-0.5">SUPERVISOR</span>
                </div>
            </div>
        </div>
        <nav class="flex-1 py-5 px-3 space-y-1">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">📊</span> Dashboard
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">👤</span> Profile
            </a>
            <a href="view-student-dashboard.php?id=<?= $student_id ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-indigo-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/30 transition-all duration-200">
                <span class="text-base">🎓</span> Student View
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
            <div class="flex items-center gap-4">
                <a href="supervisor-dashboard.php" class="inline-flex items-center gap-2 text-xs font-bold text-indigo-600 hover:text-indigo-800 transition">
                    <span class="w-7 h-7 rounded-lg bg-indigo-50 flex items-center justify-center text-sm">←</span> Back
                </a>
                <h1 class="text-base font-bold text-slate-800">Student Dashboard View</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-indigo-50 border border-indigo-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span class="text-xs font-bold text-indigo-700">Week <?= $selected_week ?></span>
                    <?php if ($week_date_range): ?>
                    <span class="text-xs font-bold text-indigo-600 bg-indigo-100 px-1.5 py-0.5 rounded font-mono"><?= $week_date_range ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

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
                                <?= strtoupper(substr($profile['supervisor_name'] ?? '', 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Supervisor</p>
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($profile['supervisor_name'] ?? '—') ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                            <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <?= strtoupper(substr($instructor_name, 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Instructor</p>
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($instructor_name) ?></p>
                            </div>
                        </div>
                        <?php if ($intern_start && $intern_end): ?>
                        <div class="flex items-center gap-3 bg-indigo-50/50 rounded-xl px-4 py-3 border border-indigo-200/50">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-indigo-400 uppercase tracking-wider">Internship Period</p>
                                <p class="text-xs font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <?php elseif ($intern_start): ?>
                        <div class="flex items-center gap-3 bg-indigo-50/50 rounded-xl px-4 py-3 border border-indigo-200/50">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-indigo-400 uppercase tracking-wider">Internship Start</p>
                                <p class="text-xs font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Workflow Status Chain -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🔗</span> Week <?= $selected_week ?> Workflow Status
                    </h3>
                    <div class="flex items-center justify-between gap-1">
                        <div class="flex flex-col items-center flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full <?= $wf_step1_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-slate-100 text-slate-400 border-2 border-dashed border-slate-300' ?> flex items-center justify-center text-sm font-bold">
                                <?= $wf_step1_done ? '✅' : '📝' ?>
                            </div>
                            <p class="text-[10px] font-bold text-slate-600 mt-2 text-center leading-tight">Daily Logs</p>
                            <p class="text-[9px] text-slate-400 text-center"><?= $wf_log_count ?>/3 min</p>
                        </div>
                        <div class="flex-1 h-0.5 <?= $wf_step2_done ? 'bg-emerald-400' : 'bg-slate-200' ?> rounded-full mx-1 mt-[-22px]"></div>
                        <div class="flex flex-col items-center flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full <?= $wf_step2_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-slate-100 text-slate-400 border-2 border-dashed border-slate-300' ?> flex items-center justify-center text-sm font-bold">
                                <?= $wf_step2_done ? '✅' : '📊' ?>
                            </div>
                            <p class="text-[10px] font-bold text-slate-600 mt-2 text-center leading-tight">Reflection</p>
                            <p class="text-[9px] text-slate-400 text-center"><?= $wf_step2_done ? 'Done' : 'Pending' ?></p>
                        </div>
                        <div class="flex-1 h-0.5 <?= ($evaluation && !empty($evaluation['student_signature_value'])) ? 'bg-emerald-400' : 'bg-slate-200' ?> rounded-full mx-1 mt-[-22px]"></div>
                        <div class="flex flex-col items-center flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full <?= ($evaluation && !empty($evaluation['student_signature_value'])) ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-slate-100 text-slate-400 border-2 border-dashed border-slate-300' ?> flex items-center justify-center text-sm font-bold">
                                <?= ($evaluation && !empty($evaluation['student_signature_value'])) ? '✅' : '✍️' ?>
                            </div>
                            <p class="text-[10px] font-bold text-slate-600 mt-2 text-center leading-tight">Sign & Submit</p>
                            <p class="text-[9px] text-slate-400 text-center"><?= ($evaluation && !empty($evaluation['student_signature_value'])) ? 'Done' : 'Pending' ?></p>
                        </div>
                        <div class="flex-1 h-0.5 <?= $wf_step4_status === 'approved' ? 'bg-emerald-400' : ($wf_step4_status === 'rejected' ? 'bg-red-400' : 'bg-slate-200') ?> rounded-full mx-1 mt-[-22px]"></div>
                        <div class="flex flex-col items-center flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full <?= $wf_step4_status === 'approved' ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : ($wf_step4_status === 'rejected' ? 'bg-red-500 text-white shadow-lg glow-red' : 'bg-slate-100 text-slate-400 border-2 border-dashed border-slate-300') ?> flex items-center justify-center text-sm font-bold">
                                <?= $wf_step4_status === 'approved' ? '✅' : ($wf_step4_status === 'rejected' ? '❌' : '👨‍🏫') ?>
                            </div>
                            <p class="text-[10px] font-bold text-slate-600 mt-2 text-center leading-tight">Instructor</p>
                            <p class="text-[9px] text-slate-400 text-center"><?= $wf_step4_status === 'approved' ? 'Approved' : ($wf_step4_status === 'rejected' ? 'Rejected' : 'Pending') ?></p>
                        </div>
                        <div class="flex-1 h-0.5 <?= $wf_step5_done ? 'bg-emerald-400' : 'bg-slate-200' ?> rounded-full mx-1 mt-[-22px]"></div>
                        <div class="flex flex-col items-center flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-full <?= $wf_step5_done ? 'bg-emerald-500 text-white shadow-lg glow-emerald' : 'bg-slate-100 text-slate-400 border-2 border-dashed border-slate-300' ?> flex items-center justify-center text-sm font-bold">
                                <?= $wf_step5_done ? '✅' : '👩‍🏫' ?>
                            </div>
                            <p class="text-[10px] font-bold text-slate-600 mt-2 text-center leading-tight">Supervisor</p>
                            <p class="text-[9px] text-slate-400 text-center"><?= $wf_step5_done ? 'Graded' : 'Pending' ?></p>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-lg shadow-lg shadow-blue-500/30">⏱️</div>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $total_hours ?>h <?= $total_mins ?>m</p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Total Hours Worked</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-violet-600 text-white flex items-center justify-center text-lg shadow-lg shadow-violet-500/30">📋</div>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $total_logs_count ?></p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Daily Logs Submitted</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-lg shadow-lg shadow-emerald-500/30">✅</div>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= $attendance_rate ?>%</p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Attendance Rate</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-lg shadow-lg shadow-purple-500/30">📆</div>
                        </div>
                        <p class="text-2xl font-black text-slate-800"><?= count($weeks) > 0 ? count($weeks) : '—' ?></p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Total Weeks</p>
                    </div>
                </div>

                <!-- Week Selector -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            <div class="relative" id="week-dropdown">
                                <button onclick="toggleWeekDropdown()" class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition cursor-pointer whitespace-nowrap">
                                    📆 Week <?= $selected_week ?>
                                    <span class="text-slate-400 text-[10px]">▾</span>
                                </button>
                                <div id="week-menu" class="absolute left-0 top-full mt-1 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden overflow-hidden max-h-64 overflow-y-auto">
                                    <?php foreach ($weeks as $wn => $wr): ?>
                                    <a href="?id=<?= $student_id ?>&week=<?= $wn ?>" class="flex items-center justify-between px-3 py-2 text-xs font-semibold <?= $selected_week === $wn ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?> transition">
                                        Week <?= $wn ?>
                                        <span class="text-[10px] text-slate-400"><?= $wr['start'] ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($week_date_range): ?>
                            <span class="text-xs text-slate-400 font-medium"><?= $week_date_range ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="supervisor-review.php?student_id=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-xs font-bold rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-purple-500/20">
                            👁️ View & Grade
                        </a>
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
                                <span class="text-xs text-slate-400 font-medium"><?= count($recent_logs) ?> day(s)</span>
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
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ Present</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded">❌ Absent</span>
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
                                    👁️ View & Grade Reports
                                </a>
                                <a href="supervisor-dashboard.php" class="flex items-center gap-2 px-3 py-2 bg-slate-50 border border-slate-200 text-slate-600 text-xs font-bold rounded-xl hover:bg-slate-100 transition-all duration-200">
                                    📊 Back to Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>
