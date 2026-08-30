<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../includes/academic_year_helper.php';

if (($_SESSION['role'] ?? '') !== 'supervisor') {
    header('Location: ../login.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'] ?? 'Supervisor';
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

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$recent_notifs_q->bind_param("i", $sup_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ── Available Academic Years ────────────────────────────────────
ensure_academic_years_table($db);
$available_years = get_academic_years_list($db);
$active_year_label = get_active_academic_year_label($db);

// ── Filter Parameters ───────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$allowed_statuses = ['ready', 'graded', 'waiting', 'behind', 'rejected'];
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = '';

$filter_student_id = (int) ($_GET['student_id'] ?? $_GET['uid'] ?? 0);
$filter_company = trim($_GET['company'] ?? '');
$filter_year = trim($_GET['year'] ?? ($_GET['academic_year'] ?? 'all'));
if (empty($filter_year)) $filter_year = 'all';
$search = trim($_GET['search'] ?? '');

// ── Fetch ALL Assigned Students for this Supervisor ─────────────
$students_sql = "
    SELECT u.id AS uid, u.username, u.email, u.academic_year, u.status AS user_status, u.profile_pic, u.is_warned,
           sp.full_name, sp.student_roll, sp.major, sp.phone,
           sp.company_name, sp.job_role,
           sp.instructor_name, sp.instructor_email, sp.instructor_phone,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
    ORDER BY u.academic_year DESC, sp.student_roll ASC, sp.full_name ASC
";
$students_stmt = $db->prepare($students_sql);
$students_stmt->bind_param("i", $sup_id);
$students_stmt->execute();
$res = $students_stmt->get_result();
$all_assigned_students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Available companies for filter
$available_companies = [];
foreach ($all_assigned_students as $s) {
    if (!empty($s['company_name']) && !in_array($s['company_name'], $available_companies, true)) {
        $available_companies[] = $s['company_name'];
    }
}
sort($available_companies);

// ── Collect all Student IDs ─────────────────────────────────────
$student_ids = array_map(function ($s) {
    return (int) $s['uid'];
}, $all_assigned_students);

// ── Batch Fetch: Report Evaluations (Instructor Level) ──────────
$all_evaluations = [];
if (!empty($student_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $types = str_repeat('i', count($student_ids));
    $eval_q = $db->prepare("
        SELECT re.*, u.username, sp.full_name, sp.student_roll, sp.company_name
        FROM report_evaluations re
        JOIN users u ON u.id = re.student_id
        JOIN student_profiles sp ON sp.user_id = u.id
        WHERE re.student_id IN ($in_placeholders)
        ORDER BY re.week_number ASC
    ");
    $eval_q->bind_param($types, ...$student_ids);
    $eval_q->execute();
    $res = $eval_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $all_evaluations[(int) $row['student_id']][(int) $row['week_number']] = $row;
        }
    }
}

// ── Batch Fetch: Supervisor Weekly Evaluations (University Level) ─
$all_sup_evaluations = [];
if (!empty($student_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $types = str_repeat('i', count($student_ids));
    $sup_eval_q = $db->prepare("
        SELECT swe.* FROM supervisor_weekly_evaluations swe
        WHERE swe.student_id IN ($in_placeholders)
        ORDER BY swe.week_number ASC
    ");
    $sup_eval_q->bind_param($types, ...$student_ids);
    $sup_eval_q->execute();
    $res = $sup_eval_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $all_sup_evaluations[(int) $row['student_id']][(int) $row['week_number']] = $row;
        }
    }
}

// ── Batch Fetch: Daily Logs Stats per Week ───────────────────────
$logs_stats = [];
if (!empty($student_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $types = str_repeat('i', count($student_ids));
    $logs_q = $db->prepare("
        SELECT internship_id, log_date, attendance_status, calculated_duration
        FROM daily_logs
        WHERE internship_id IN ($in_placeholders)
        ORDER BY log_date ASC
    ");
    $logs_q->bind_param($types, ...$student_ids);
    $logs_q->execute();
    $res = $logs_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $sid = (int) $row['internship_id'];
            $logs_stats[$sid][] = $row;
        }
    }
}

// ── Grade Mapping Helpers ────────────────────────────────────────
$gmap = [
    'excellent'         => ['Excellent',         'text-emerald-700', 'bg-emerald-50', 'border-emerald-200'],
    'good'              => ['Good',              'text-blue-700',    'bg-blue-50',    'border-blue-200'],
    'average'           => ['Average',           'text-amber-700',   'bg-amber-50',   'border-amber-200'],
    'needs_improvement' => ['Needs Improvement', 'text-rose-700',    'bg-rose-50',    'border-rose-200'],
];

$sgd = [
    'A' => ['Grade A', 'text-emerald-700 bg-emerald-50 border-emerald-200'],
    'B' => ['Grade B', 'text-blue-700 bg-blue-50 border-blue-200'],
    'C' => ['Grade C', 'text-amber-700 bg-amber-50 border-amber-200'],
    'D' => ['Grade D', 'text-orange-700 bg-orange-50 border-orange-200'],
    'F' => ['Grade F', 'text-rose-700 bg-rose-50 border-rose-200'],
];

// ── Build Enriched Student Report Data Matrix ────────────────────
$today_obj = new DateTime();
$students_data = [];
$global_ready_reviews_count = 0;
$global_graded_reports_count = 0;
$global_waiting_instructor_count = 0;
$global_behind_count = 0;
$total_progress_sum = 0;

foreach ($all_assigned_students as $st) {
    $uid = (int) $st['uid'];
    $start_date = $st['internship_start_date'] ?: null;
    $end_date   = $st['internship_end_date'] ?: null;

    $total_weeks = internship_total_weeks($start_date, $end_date);
    if ($total_weeks <= 0) $total_weeks = 12;

    $dynamic_week = internship_current_week($start_date, $end_date, $today_obj);
    $not_started = false;
    if ($start_date && $today_obj < new DateTime($start_date)) {
        $not_started = true;
    } elseif (!$start_date) {
        $not_started = true;
    }

    $st_evals = $all_evaluations[$uid] ?? [];
    $st_sup_evals = $all_sup_evaluations[$uid] ?? [];
    $st_logs = $logs_stats[$uid] ?? [];

    // Build weeks date ranges
    $weeks_ranges = [];
    if ($start_date) {
        for ($w = 1; $w <= $total_weeks; $w++) {
            $range = getWeekRange($start_date, $w);
            if ($range) $weeks_ranges[$w] = $range;
        }
    }

    // Map logs into weeks
    $logs_by_week = [];
    foreach ($st_logs as $lg) {
        $ld = $lg['log_date'];
        $wn = getInternshipWeekNumber($start_date, $ld);
        if ($wn >= 1) {
            $logs_by_week[$wn][] = $lg;
        }
    }

    // Calculate Week-by-Week Matrix
    $week_matrix = [];
    $submitted_weeks_count = 0;
    $graded_weeks_count = 0;
    $ready_weeks_list = [];
    $waiting_instructor_list = [];

    for ($w = 1; $w <= $total_weeks; $w++) {
        $ev = $st_evals[$w] ?? null;
        $sev = $st_sup_evals[$w] ?? null;
        $w_logs = $logs_by_week[$w] ?? [];
        $w_range = $weeks_ranges[$w] ?? null;

        // Calculate hours
        $mins = 0;
        $present_count = 0;
        foreach ($w_logs as $wl) {
            if ($wl['attendance_status'] === 'present') $present_count++;
            $parts = explode(':', (string)($wl['calculated_duration'] ?? ''));
            if (count($parts) === 2) {
                $mins += ((int)$parts[0] * 60) + (int)$parts[1];
            }
        }
        $dur_str = floor($mins / 60) . 'h ' . str_pad($mins % 60, 2, '0', STR_PAD_LEFT) . 'm';

        $status = 'not_submitted';
        $status_label = 'Not Submitted';
        $status_color = 'bg-slate-100 text-slate-500 border-slate-200';

        if ($ev) {
            $submitted_weeks_count++;
            $rep_status = $ev['report_status'];

            if ($rep_status === 'approved_by_supervisor' || $sev) {
                $status = 'graded';
                $status_label = 'Graded by Supervisor (' . ($sev['weekly_grade'] ?? 'Approved') . ')';
                $status_color = 'bg-emerald-500 text-white border-emerald-500';
                $graded_weeks_count++;
                $global_graded_reports_count++;
            } elseif ($rep_status === 'approved_by_instructor') {
                $status = 'ready';
                $status_label = 'Ready for Review (Instructor Approved)';
                $status_color = 'bg-blue-600 text-white border-blue-600 shadow-sm shadow-blue-500/30 animate-pulse';
                $ready_weeks_list[] = $w;
                $global_ready_reviews_count++;
            } elseif ($rep_status === 'rejected') {
                $status = 'rejected';
                $status_label = 'Rejected by Instructor';
                $status_color = 'bg-rose-500 text-white border-rose-500';
            } else {
                $status = 'waiting';
                $status_label = 'Waiting for Instructor';
                $status_color = 'bg-amber-500 text-white border-amber-500';
                $waiting_instructor_list[] = $w;
                $global_waiting_instructor_count++;
            }
        }

        $week_matrix[$w] = [
            'week'              => $w,
            'status'            => $status,
            'status_label'      => $status_label,
            'status_color'      => $status_color,
            'range'             => $w_range,
            'logs_count'        => count($w_logs),
            'present_count'     => $present_count,
            'duration'          => $dur_str,
            'eval'              => $ev,
            'sup_eval'          => $sev,
        ];
    }

    $progress_pct = $total_weeks > 0 ? (int) round(($submitted_weeks_count / $total_weeks) * 100) : 0;
    $total_progress_sum += $progress_pct;

    // Determine latest submitted/active week
    $latest_submitted_week = 0;
    for ($w = 1; $w <= $total_weeks; $w++) {
        if (isset($st_evals[$w]) || !empty($logs_by_week[$w])) {
            $latest_submitted_week = $w;
        }
    }

    // Smart target week resolution:
    // 1. If ready weeks exist -> earliest ready week
    // 2. Else if latest submitted week > 0 -> latest submitted week
    // 3. Else -> dynamic calendar week (or Week 1)
    if (!empty($ready_weeks_list)) {
        $smart_target_week = min($ready_weeks_list);
    } elseif ($latest_submitted_week > 0) {
        $smart_target_week = $latest_submitted_week;
    } else {
        $smart_target_week = ($dynamic_week >= 1 && $dynamic_week <= $total_weeks) ? $dynamic_week : 1;
    }

    // Classification status for student
    $student_status_tag = 'all_good';
    if (!empty($ready_weeks_list)) {
        $student_status_tag = 'ready';
    } elseif (!empty($waiting_instructor_list)) {
        $student_status_tag = 'waiting';
    } elseif ($submitted_weeks_count >= $total_weeks && $total_weeks > 0) {
        $student_status_tag = 'completed';
    } elseif ($dynamic_week > 2 && $submitted_weeks_count < ($dynamic_week - 1)) {
        $student_status_tag = 'behind';
    }

    if ($student_status_tag === 'behind') {
        $global_behind_count++;
    }

    $students_data[] = [
        'profile'                => $st,
        'uid'                    => $uid,
        'name'                   => $st['full_name'] ?: $st['username'],
        'roll'                   => $st['student_roll'] ?: $st['username'],
        'email'                  => $st['email'],
        'company'                => $st['company_name'] ?: '—',
        'instructor'             => $st['instructor_name'] ?: '—',
        'academic_year'          => $st['academic_year'] ?: '—',
        'major'                  => $st['major'] ?: '',
        'total_weeks'            => $total_weeks,
        'dynamic_week'           => $dynamic_week,
        'smart_target_week'      => $smart_target_week,
        'latest_submitted_week'  => $latest_submitted_week,
        'submitted_weeks'        => $submitted_weeks_count,
        'graded_weeks'           => $graded_weeks_count,
        'progress_pct'           => $progress_pct,
        'ready_weeks'            => $ready_weeks_list,
        'waiting_weeks'          => $waiting_instructor_list,
        'week_matrix'            => $week_matrix,
        'status_tag'             => $student_status_tag,
        'not_started'            => $not_started,
    ];
}

$total_assigned_count = count($all_assigned_students);
$average_progress = $total_assigned_count > 0 ? (int) round($total_progress_sum / $total_assigned_count) : 0;

// Natural Numeric Sorting: Academic Year DESC, Roll Number Natural ASC, Name ASC
usort($students_data, function ($a, $b) {
    $ay_cmp = strcasecmp($b['academic_year'], $a['academic_year']);
    if ($ay_cmp !== 0) return $ay_cmp;
    $roll_cmp = strnatcasecmp($a['roll'], $b['roll']);
    if ($roll_cmp !== 0) return $roll_cmp;
    return strnatcasecmp($a['name'], $b['name']);
});

// ── Filter Data based on Query String (Server Fallback) ─────────
$filtered_students = array_filter($students_data, function ($s) use ($filter_status, $filter_year, $filter_company, $search, $filter_student_id) {
    if ($filter_student_id > 0 && $s['uid'] !== $filter_student_id) return false;
    if ($filter_year !== 'all' && $s['academic_year'] !== $filter_year) return false;
    if ($filter_company !== '' && $s['company'] !== $filter_company) return false;

    if ($filter_status === 'ready' && empty($s['ready_weeks'])) return false;
    if ($filter_status === 'graded' && ($s['graded_weeks'] === 0)) return false;
    if ($filter_status === 'waiting' && empty($s['waiting_weeks'])) return false;
    if ($filter_status === 'behind' && $s['status_tag'] !== 'behind') return false;

    if ($search !== '') {
        $q = strtolower($search);
        $match = str_contains(strtolower($s['name']), $q)
              || str_contains(strtolower($s['roll']), $q)
              || str_contains(strtolower($s['company']), $q)
              || str_contains(strtolower($s['instructor']), $q)
              || str_contains(strtolower($s['email']), $q);
        if (!$match) return false;
    }

    return true;
});

function build_query_url($overrides = []) {
    $q = array_merge($_GET, $overrides);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    return $q;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Reports Management – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    },
                }
            }
        }
    </script>
    <style>
        .scroll-margin { scroll-margin-top: 88px; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'reports'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- Top Header -->
        <?php $pageTitle = '📑 Student Reports & Evaluations'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ════ REPORTS CONTENT ════ -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">

                <!-- ═══ 4 KPI INTERACTIVE FILTER CARDS ═══ -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- 1. Total Assigned Interns (All) -->
                    <a href="?<?= http_build_query(build_query_url(['status' => '', 'student_id' => null, 'uid' => null])) ?>"
                       class="rounded-2xl border p-4 sm:p-5 flex items-center gap-3.5 transition-all duration-200 group cursor-pointer <?= empty($filter_status) ? 'bg-slate-900 text-white border-slate-900 shadow-md ring-2 ring-slate-900' : 'bg-white border-slate-200/80 hover:border-slate-400 hover:shadow-md text-slate-800' ?>">
                        <div class="w-12 h-12 rounded-2xl <?= empty($filter_status) ? 'bg-slate-800 text-white' : 'bg-gradient-to-br from-teal-500 to-teal-700 text-white' ?> flex items-center justify-center text-xl shadow-md shrink-0 group-hover:scale-105 transition-transform">
                            👥
                        </div>
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold <?= empty($filter_status) ? 'text-slate-300' : 'text-slate-400' ?> uppercase tracking-wider">Total Interns</p>
                            <p class="text-xl sm:text-2xl font-black <?= empty($filter_status) ? 'text-white' : 'text-slate-800' ?> mt-0.5"><?= $total_assigned_count ?></p>
                            <p class="text-[11px] <?= empty($filter_status) ? 'text-teal-300 font-semibold' : 'text-teal-700 font-bold' ?> mt-0.5 truncate">
                                <?= empty($filter_status) ? '● Viewing All' : 'Click to view all' ?>
                            </p>
                        </div>
                    </a>

                    <!-- 2. Ready for Supervisor Review -->
                    <a href="?<?= http_build_query(build_query_url(['status' => 'ready'])) ?>"
                       class="rounded-2xl border p-4 sm:p-5 flex items-center gap-3.5 transition-all duration-200 group cursor-pointer <?= $filter_status === 'ready' ? 'bg-blue-50 border-blue-500 ring-2 ring-blue-500 shadow-md' : 'bg-white border-slate-200/80 hover:border-blue-300 hover:shadow-md' ?>">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center text-xl shadow-md shadow-blue-600/20 shrink-0 group-hover:scale-105 transition-transform">
                            ⚡
                        </div>
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold <?= $filter_status === 'ready' ? 'text-blue-800' : 'text-slate-400' ?> uppercase tracking-wider">Ready for Review</p>
                            <p class="text-xl sm:text-2xl font-black <?= $filter_status === 'ready' ? 'text-blue-900' : 'text-blue-700' ?> mt-0.5"><?= $global_ready_reviews_count ?></p>
                            <p class="text-[11px] <?= $filter_status === 'ready' ? 'text-blue-700 font-bold' : ($global_ready_reviews_count > 0 ? 'text-blue-600 font-bold' : 'text-slate-400 font-medium') ?> mt-0.5 truncate">
                                <?= $filter_status === 'ready' ? '● Filtering active' : ($global_ready_reviews_count > 0 ? 'Requires Your Grading →' : 'All caught up ✓') ?>
                            </p>
                        </div>
                    </a>

                    <!-- 3. Waiting for Instructor Feedback -->
                    <a href="?<?= http_build_query(build_query_url(['status' => 'waiting'])) ?>"
                       class="rounded-2xl border p-4 sm:p-5 flex items-center gap-3.5 transition-all duration-200 group cursor-pointer <?= $filter_status === 'waiting' ? 'bg-amber-50 border-amber-500 ring-2 ring-amber-500 shadow-md' : 'bg-white border-slate-200/80 hover:border-amber-300 hover:shadow-md' ?>">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center text-xl shadow-md shadow-amber-600/20 shrink-0 group-hover:scale-105 transition-transform">
                            ⏳
                        </div>
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold <?= $filter_status === 'waiting' ? 'text-amber-800' : 'text-slate-400' ?> uppercase tracking-wider">In Company Review</p>
                            <p class="text-xl sm:text-2xl font-black <?= $filter_status === 'waiting' ? 'text-amber-900' : 'text-amber-700' ?> mt-0.5"><?= $global_waiting_instructor_count ?></p>
                            <p class="text-[11px] <?= $filter_status === 'waiting' ? 'text-amber-800 font-bold' : 'text-amber-700 font-bold' ?> mt-0.5 truncate">
                                <?= $filter_status === 'waiting' ? '● Filtering active' : 'Waiting for Instructor' ?>
                            </p>
                        </div>
                    </a>

                    <!-- 4. Fully Graded Reports -->
                    <a href="?<?= http_build_query(build_query_url(['status' => 'graded'])) ?>"
                       class="rounded-2xl border p-4 sm:p-5 flex items-center gap-3.5 transition-all duration-200 group cursor-pointer <?= $filter_status === 'graded' ? 'bg-emerald-50 border-emerald-500 ring-2 ring-emerald-500 shadow-md' : 'bg-white border-slate-200/80 hover:border-emerald-300 hover:shadow-md' ?>">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xl shadow-md shadow-emerald-600/20 shrink-0 group-hover:scale-105 transition-transform">
                            ✅
                        </div>
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold <?= $filter_status === 'graded' ? 'text-emerald-800' : 'text-slate-400' ?> uppercase tracking-wider">Graded Reports</p>
                            <p class="text-xl sm:text-2xl font-black <?= $filter_status === 'graded' ? 'text-emerald-900' : 'text-emerald-700' ?> mt-0.5"><?= $global_graded_reports_count ?></p>
                            <p class="text-[11px] <?= $filter_status === 'graded' ? 'text-emerald-800 font-bold' : 'text-emerald-700 font-bold' ?> mt-0.5 truncate">
                                <?= $filter_status === 'graded' ? '● Filtering active' : 'Avg Progress: ' . $average_progress . '%' ?>
                            </p>
                        </div>
                    </a>
                </div>

                <!-- ═══ CONTROLS & FILTER BAR ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-4 lg:p-5 space-y-3">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">

                        <!-- Left: Status Summary & Active Badges -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <div class="text-xs font-bold text-slate-700 flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                                <span>👥 Showing</span>
                                <span class="text-indigo-600 font-black"><?= count($filtered_students) ?></span>
                                <span class="text-slate-400">/</span>
                                <span><?= $total_assigned_count ?> Students</span>
                            </div>

                            <?php if ($filter_status === 'ready'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-xl text-xs font-bold">
                                <span>⚡ Filter: Ready for Review</span>
                                <a href="?<?= http_build_query(build_query_url(['status' => null])) ?>" class="text-blue-500 hover:text-blue-800 font-black ml-1" title="Clear filter">✕</a>
                            </span>
                            <?php elseif ($filter_status === 'waiting'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl text-xs font-bold">
                                <span>⏳ Filter: In Company Review</span>
                                <a href="?<?= http_build_query(build_query_url(['status' => null])) ?>" class="text-amber-500 hover:text-amber-800 font-black ml-1" title="Clear filter">✕</a>
                            </span>
                            <?php elseif ($filter_status === 'graded'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-bold">
                                <span>✅ Filter: Graded Reports</span>
                                <a href="?<?= http_build_query(build_query_url(['status' => null])) ?>" class="text-emerald-500 hover:text-emerald-800 font-black ml-1" title="Clear filter">✕</a>
                            </span>
                            <?php elseif ($filter_status === 'behind'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold">
                                <span>⚠️ Filter: Behind Schedule</span>
                                <a href="?<?= http_build_query(build_query_url(['status' => null])) ?>" class="text-rose-500 hover:text-rose-800 font-black ml-1" title="Clear filter">✕</a>
                            </span>
                            <?php endif; ?>

                            <?php if ($global_behind_count > 0 && $filter_status !== 'behind'): ?>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'behind'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-700 rounded-xl text-xs font-bold transition shadow-2xs" title="Filter students behind schedule">
                                <span>⚠️ Behind Schedule (<?= $global_behind_count ?>)</span>
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Right: Academic Year, Company, Search -->
                        <div class="flex items-center gap-2.5 flex-wrap">

                            <!-- Live Search Input -->
                            <div class="relative w-full sm:w-60">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400 text-xs">🔍</span>
                                <input type="text"
                                    id="reportSearchInput"
                                    value="<?= htmlspecialchars($search) ?>"
                                    placeholder="Search roll, name, company…"
                                    class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-8 pr-8 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200"
                                    oninput="applyReportFilters()">
                                <button type="button" onclick="document.getElementById('reportSearchInput').value=''; applyReportFilters();" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-700 text-xs font-bold cursor-pointer">✕</button>
                            </div>

                            <!-- Company Filter -->
                            <?php if (!empty($available_companies)): ?>
                            <div class="inline-block">
                                <select id="filterCompanySelect" onchange="applyReportFilters()" class="bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 text-slate-700 rounded-xl text-xs font-semibold px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 cursor-pointer min-w-[10rem] max-w-[18rem]">
                                    <option value="">All Companies</option>
                                    <?php foreach ($available_companies as $comp): ?>
                                    <option value="<?= htmlspecialchars($comp) ?>" <?= $filter_company === $comp ? 'selected' : '' ?>><?= htmlspecialchars($comp) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <!-- Academic Year Filter -->
                            <div class="inline-block">
                                <select id="filterYearSelect" onchange="applyReportFilters()" class="bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 text-slate-700 rounded-xl text-xs font-semibold px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 cursor-pointer max-w-[12rem]">
                                    <option value="all" <?= ($filter_year === 'all' || empty($filter_year)) ? 'selected' : '' ?>>All Academic Years</option>
                                    <?php foreach ($available_years as $ay): ?>
                                        <option value="<?= htmlspecialchars($ay) ?>" <?= $filter_year === $ay ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ay) ?><?= $ay === $active_year_label ? ' (Current)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Status Notice / Active Banner -->
                    <?php if ($filter_student_id > 0): ?>
                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-indigo-700 bg-indigo-50/60 px-3 py-2 rounded-xl">
                        <div class="flex items-center gap-2 font-semibold">
                            <span>🎓 Filtered by Single Student (ID: <?= $filter_student_id ?>)</span>
                        </div>
                        <a href="?<?= http_build_query(build_query_url(['student_id' => null, 'uid' => null])) ?>" class="font-bold hover:underline">✕ Show All Students</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ════ CONSOLIDATED STUDENT REPORTS SUMMARY TABLE ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <?php if (!empty($filtered_students)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left" id="studentsSummaryTable">
                            <thead>
                                <tr class="bg-slate-50/90 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider text-[11px]">
                                    <th class="px-5 py-3.5 min-w-[210px]">Student Details</th>
                                    <th class="px-4 py-3.5 min-w-[200px] max-w-[280px]">Placement Company</th>
                                    <th class="px-4 py-3.5 min-w-[280px]">Weekly Report Status Matrix</th>
                                    <th class="px-4 py-3.5 whitespace-nowrap min-w-[140px]">Progress</th>
                                    <th class="px-4 py-3.5 whitespace-nowrap min-w-[130px]">Review Status</th>
                                    <th class="px-5 py-3.5 text-right whitespace-nowrap min-w-[160px]">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100" id="studentsTableBody">
                                <?php
                                $current_batch_year = null;
                                foreach ($filtered_students as $sd):
                                    $s_ay = $sd['academic_year'] ?: 'Unassigned Year';
                                    $is_new_batch = ($s_ay !== $current_batch_year);
                                    if ($is_new_batch) {
                                        $current_batch_year = $s_ay;
                                    }
                                    $uid = $sd['uid'];
                                    $st = $sd['profile'];
                                    $has_ready = !empty($sd['ready_weeks']);
                                    $earliest_ready_week = $has_ready ? min($sd['ready_weeks']) : 1;
                                    $search_terms = strtolower($sd['name'] . ' ' . $sd['roll'] . ' ' . $sd['company'] . ' ' . $sd['instructor'] . ' ' . $sd['email']);
                                ?>
                                <?php if ($is_new_batch): ?>
                                    <tr class="academic-year-header-row bg-slate-100/90 border-y border-slate-200" data-group-ay="<?= htmlspecialchars($s_ay) ?>">
                                        <td colspan="6" class="px-5 py-2.5 text-xs font-bold text-slate-700">
                                            <div class="flex items-center gap-2">
                                                <span class="p-1 bg-indigo-100 text-indigo-700 rounded text-xs leading-none">🎓</span>
                                                <span class="text-slate-500 uppercase tracking-wider text-[11px] font-bold">Academic Batch:</span>
                                                <span class="font-mono font-bold text-indigo-700 bg-white px-2.5 py-0.5 rounded-md border border-indigo-200 shadow-xs"><?= htmlspecialchars($s_ay) ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="student-report-row hover:bg-slate-50/70 transition-colors duration-150 group"
                                    data-search="<?= htmlspecialchars($search_terms) ?>"
                                    data-company="<?= htmlspecialchars(strtolower($sd['company'] ?? '')) ?>"
                                    data-year="<?= htmlspecialchars(strtolower($sd['academic_year'] ?? '')) ?>">

                                    <!-- 1. Student Details -->
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($st['profile_pic'])): ?>
                                                <img src="../uploads/avatars/<?= htmlspecialchars($st['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover ring-1 ring-slate-200 shadow-xs shrink-0">
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-xs">
                                                    <?= strtoupper(substr($sd['name'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <a href="view-student-dashboard.php?id=<?= $uid ?>" class="text-sm font-bold text-slate-800 hover:text-indigo-600 transition truncate block leading-tight">
                                                    <?= htmlspecialchars($sd['name']) ?>
                                                </a>
                                                <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                                    <span class="text-[11px] font-mono font-bold text-slate-600 bg-slate-100 px-1.5 py-0.2 rounded border border-slate-200">
                                                        <?= htmlspecialchars($sd['roll']) ?>
                                                    </span>
                                                    <?php if (!empty($sd['major'])): ?>
                                                        <span class="text-[11px] text-slate-500 font-medium bg-slate-50 px-1.5 py-0.2 rounded border border-slate-200/60">
                                                            <?= htmlspecialchars($sd['major']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 2. Company & Instructor -->
                                    <td class="px-4 py-4 min-w-[200px] max-w-[280px]">
                                        <?php if (!empty($st['company_name'])): ?>
                                            <div class="text-xs font-bold text-slate-800 leading-snug break-words" title="<?= htmlspecialchars($st['company_name']) ?>">
                                                🏢 <?= htmlspecialchars($st['company_name']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="inline-flex items-center text-[11px] text-slate-400 italic bg-slate-100 px-2 py-0.5 rounded">
                                                🏢 Not assigned
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($st['job_role'])): ?>
                                            <div class="text-[11px] text-indigo-600 font-semibold mt-0.5 leading-tight break-words">
                                                💼 <?= htmlspecialchars($st['job_role']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($st['instructor_name'])): ?>
                                            <div class="text-[11px] text-slate-500 font-medium mt-0.5 leading-tight break-words" title="<?= htmlspecialchars($st['instructor_name']) ?>">
                                                👨‍🏫 <?= htmlspecialchars($st['instructor_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3. Visual Weekly Status Matrix (Option 2: Segmented Progress Bar) -->
                                    <td class="px-4 py-4 min-w-[280px]">
                                        <div class="space-y-1.5">
                                            <!-- Segmented Bar Track -->
                                            <div class="flex items-center gap-1 w-full bg-slate-50/90 p-1 rounded-xl border border-slate-200/80 shadow-2xs">
                                                <?php foreach ($sd['week_matrix'] as $wn => $wm):
                                                    $st_type = $wm['status'];
                                                    $is_graded = ($st_type === 'graded');
                                                    $is_ready  = ($st_type === 'ready');
                                                    $is_waiting = ($st_type === 'waiting');
                                                    $grade_letter = $wm['sup_eval']['weekly_grade'] ?? '';

                                                    // Segment styles
                                                    if ($is_graded) {
                                                        $seg_cls = 'bg-emerald-500 hover:bg-emerald-600 text-white shadow-2xs';
                                                        $seg_text = $grade_letter ?: '✓';
                                                    } elseif ($is_ready) {
                                                        $seg_cls = 'bg-blue-600 hover:bg-blue-700 text-white animate-pulse shadow-xs shadow-blue-500/40 ring-2 ring-blue-300';
                                                        $seg_text = '⚡';
                                                    } elseif ($is_waiting) {
                                                        $seg_cls = 'bg-amber-400 hover:bg-amber-500 text-slate-900 shadow-2xs';
                                                        $seg_text = '⏳';
                                                    } elseif ($st_type === 'rejected') {
                                                        $seg_cls = 'bg-rose-500 hover:bg-rose-600 text-white shadow-2xs';
                                                        $seg_text = '✕';
                                                    } else {
                                                        $seg_cls = 'bg-white hover:bg-slate-200 text-slate-400 border border-slate-200/70';
                                                        $seg_text = $wn;
                                                    }
                                                ?>
                                                <a href="supervisor-review.php?student_id=<?= $uid ?>&week=<?= $wn ?>"
                                                   title="Week <?= $wn ?>: <?= htmlspecialchars($wm['status_label']) ?><?= !empty($wm['duration']) ? ' (' . $wm['duration'] . ')' : '' ?><?= $grade_letter ? ' • Grade: ' . $grade_letter : '' ?>"
                                                   class="flex-1 h-6 rounded-md flex items-center justify-center text-[10px] font-black transition-all duration-150 hover:scale-110 hover:z-10 <?= $seg_cls ?>">
                                                    <?= $seg_text ?>
                                                </a>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- Matrix Summary Sub-indicator -->
                                            <div class="flex items-center justify-between text-[11px] text-slate-500 font-medium px-0.5">
                                                <span class="flex items-center gap-1.5 font-bold text-emerald-700">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                                    <span><?= $sd['graded_weeks'] ?>/<?= $sd['total_weeks'] ?> Graded</span>
                                                </span>
                                                <?php if (!empty($sd['ready_weeks'])): ?>
                                                <span class="inline-flex items-center gap-1 text-blue-700 font-bold bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200 text-[10px]">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-pulse"></span>
                                                    <span><?= count($sd['ready_weeks']) ?> Ready</span>
                                                </span>
                                                <?php elseif (!empty($sd['waiting_weeks'])): ?>
                                                <span class="inline-flex items-center gap-1 text-amber-700 font-semibold bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200 text-[10px]">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                    <span><?= count($sd['waiting_weeks']) ?> in Company</span>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-slate-400 text-[10px]">W1–W<?= $sd['total_weeks'] ?> Track</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- 4. Progress -->
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="w-32">
                                            <div class="flex items-center justify-between text-xs font-bold text-slate-700 mb-1">
                                                <span><?= $sd['submitted_weeks'] ?>/<?= $sd['total_weeks'] ?> Wks</span>
                                                <span class="text-indigo-600"><?= $sd['progress_pct'] ?>%</span>
                                            </div>
                                            <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="h-1.5 rounded-full bg-gradient-to-r <?= progress_bar_color($sd['progress_pct']) ?> transition-all duration-500" style="width: <?= $sd['progress_pct'] ?>%"></div>
                                            </div>
                                            <span class="text-[10px] text-slate-400 mt-1 block">
                                                <?= $sd['not_started'] ? 'Not started yet' : 'Current: Week ' . $sd['dynamic_week'] ?>
                                            </span>
                                        </div>
                                    </td>

                                    <!-- 5. Review Action Status -->
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <?php if ($has_ready): ?>
                                            <div class="inline-flex flex-col items-start gap-0.5">
                                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2.5 py-1 rounded-lg">
                                                    <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                                                    <span>Review Ready (W<?= implode(', W', $sd['ready_weeks']) ?>)</span>
                                                </span>
                                                <span class="text-[10px] text-blue-600 font-medium ml-0.5">Instructor Approved</span>
                                            </div>
                                        <?php elseif (!empty($sd['waiting_weeks'])): ?>
                                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                <span>Company Reviewing (W<?= implode(', W', $sd['waiting_weeks']) ?>)</span>
                                            </span>
                                        <?php elseif ($sd['graded_weeks'] > 0 && $sd['graded_weeks'] === $sd['submitted_weeks']): ?>
                                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                <span>All Graded ✓</span>
                                            </span>
                                        <?php elseif ($sd['status_tag'] === 'behind'): ?>
                                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-rose-700 bg-rose-50 border border-rose-200 px-2.5 py-1 rounded-lg">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                                <span>Behind Schedule</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 bg-slate-100 px-2.5 py-1 rounded-lg">
                                                <span>— Up to Date</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 6. Actions -->
                                    <td class="px-5 py-4 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <?php if ($has_ready): ?>
                                            <a href="supervisor-review.php?student_id=<?= $uid ?>&week=<?= $sd['smart_target_week'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition" title="Review Week <?= $sd['smart_target_week'] ?>">
                                                <span>⚡ Review (W<?= $sd['smart_target_week'] ?>)</span>
                                            </a>
                                            <?php else: ?>
                                            <a href="supervisor-review.php?student_id=<?= $uid ?>&week=<?= $sd['smart_target_week'] ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition" title="View Week <?= $sd['smart_target_week'] ?>">
                                                <i class="fa-regular fa-file-lines text-slate-500 text-xs"></i>
                                                <span>View (W<?= $sd['smart_target_week'] ?>)</span>
                                            </a>
                                            <?php endif; ?>

                                            <!-- Interactive Modal Trigger -->
                                            <button type="button" onclick="openStudentBreakdownModal(<?= $uid ?>)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-slate-600 text-xs font-semibold rounded-xl transition cursor-pointer" title="View Full 12-Week Breakdown">
                                                <i class="fa-solid fa-table-list text-xs"></i>
                                                <span>Weeks (<?= $sd['submitted_weeks'] ?>)</span>
                                            </button>

                                            <!-- Save as PDF Report -->
                                            <a href="../student/print_report.php?student_id=<?= $uid ?>&week=all" target="_blank" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-teal-50 hover:text-teal-700 text-slate-600 text-xs font-semibold rounded-xl transition" title="Save Official 12-Week Report as PDF">
                                                <i class="fa-solid fa-file-pdf text-xs"></i>
                                                <span>Save as PDF</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-16 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">👥</div>
                        <p class="text-base font-bold text-slate-700">No student reports found</p>
                        <p class="text-sm text-slate-400 mt-1">Try adjusting your filters or search query.</p>
                        <a href="supervisor-reports.php" class="mt-4 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Reset all filters</a>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- ════ INTERACTIVE STUDENT 12-WEEK BREAKDOWN MODAL ════ -->
<div id="studentBreakdownModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 hidden opacity-0 transition-opacity duration-200">
    <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] shadow-2xl border border-slate-200 flex flex-col overflow-hidden transform scale-95 transition-transform duration-200" id="breakdownModalBox">

        <!-- Modal Header -->
        <div class="p-5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
            <div class="flex items-center gap-3.5">
                <div id="modalAvatarBox" class="w-12 h-12 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-lg font-black shrink-0 shadow-md shadow-indigo-500/20">
                    S
                </div>
                <div>
                    <h3 id="modalStudentName" class="text-base font-black text-slate-800">Student Name</h3>
                    <div class="flex items-center gap-2 mt-0.5 text-xs text-slate-500 font-medium">
                        <span id="modalRollNumber" class="font-mono font-bold bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">Roll</span>
                        <span id="modalCompany" class="text-slate-600">🏢 Company</span>
                        <span id="modalAcademicYear" class="text-indigo-600 font-semibold bg-indigo-50 px-1.5 py-0.5 rounded">Year</span>
                    </div>
                </div>
            </div>
            <button type="button" onclick="closeStudentBreakdownModal()" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center text-sm font-bold transition cursor-pointer">
                ✕
            </button>
        </div>

        <!-- Modal Body (12-Week Table) -->
        <div class="p-5 overflow-y-auto flex-1 space-y-4">
            <div class="flex items-center justify-between text-xs text-slate-500 bg-slate-50 p-3 rounded-xl border border-slate-200/60">
                <div id="modalProgressText" class="font-bold text-slate-700">Progress: 8 of 12 Weeks Completed</div>
                <div id="modalSummaryStats" class="flex items-center gap-3"></div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="px-4 py-3">Week</th>
                            <th class="px-4 py-3">Date Period</th>
                            <th class="px-4 py-3">Logs &amp; Hours</th>
                            <th class="px-4 py-3">Company Instructor</th>
                            <th class="px-4 py-3">CU Supervisor Grade</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="modalWeeksTableBody" class="divide-y divide-slate-100">
                        <!-- Populated dynamically via JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="p-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between flex-wrap gap-2">
            <a id="modalPrintAllBtn" href="#" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-xs font-bold rounded-xl shadow-xs transition">
                <i class="fa-solid fa-file-pdf"></i>
                <span>Save as PDF</span>
            </a>
            <div class="flex items-center gap-2">
                <a id="modalFullDashboardBtn" href="#" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
                    <span>Full Student Dashboard →</span>
                </a>
                <button type="button" onclick="closeStudentBreakdownModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ════ JAVASCRIPT DATA REPOSITORY & MODAL LOGIC ════ -->
<script>
// JSON repository of student matrix data for instant client-side popup
const studentsDataMap = <?= json_encode($students_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

// Live Filtering for Search, Company, and Academic Year (Zero Reload)
function applyReportFilters() {
    const q = (document.getElementById('reportSearchInput')?.value || '').toLowerCase().trim();
    const comp = (document.getElementById('filterCompanySelect')?.value || '').toLowerCase().trim();
    const yr = (document.getElementById('filterYearSelect')?.value || '').toLowerCase().trim();
    
    const rows = document.querySelectorAll('.student-report-row');
    rows.forEach(row => {
        const searchData = (row.getAttribute('data-search') || '').toLowerCase();
        const rowCompany = (row.getAttribute('data-company') || '').toLowerCase();
        const rowYear = (row.getAttribute('data-year') || '').toLowerCase();
        
        const matchSearch = (q === '' || searchData.includes(q));
        const matchCompany = (comp === '' || rowCompany === comp);
        const matchYear = (yr === '' || yr === 'all' || rowYear === yr);
        
        if (matchSearch && matchCompany && matchYear) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    // Toggle Academic Batch header rows
    const ayHeaders = document.querySelectorAll('.academic-year-header-row');
    ayHeaders.forEach(function(header) {
        const groupAy = (header.getAttribute('data-group-ay') || '').toLowerCase();
        let hasVisible = false;
        rows.forEach(function(row) {
            if (row.style.display !== 'none') {
                const rowYear = (row.getAttribute('data-year') || '').toLowerCase();
                if (rowYear === groupAy) {
                    hasVisible = true;
                }
            }
        });
        header.style.display = hasVisible ? '' : 'none';
    });
}

function resetReportFilters() {
    const searchInput = document.getElementById('reportSearchInput');
    if (searchInput) searchInput.value = '';
    const compSelect = document.getElementById('filterCompanySelect');
    if (compSelect) compSelect.value = '';
    const yrSelect = document.getElementById('filterYearSelect');
    if (yrSelect) yrSelect.value = 'all';
    applyReportFilters();
}

function openStudentBreakdownModal(studentId) {
    const student = studentsDataMap.find(s => s.uid === Number(studentId));
    if (!student) return;

    document.getElementById('modalStudentName').textContent = student.name;
    document.getElementById('modalRollNumber').textContent = student.roll;
    document.getElementById('modalCompany').textContent = '🏢 ' + student.company;
    document.getElementById('modalAcademicYear').textContent = student.academic_year;

    // Avatar
    const avatarBox = document.getElementById('modalAvatarBox');
    if (student.profile && student.profile.profile_pic) {
        avatarBox.innerHTML = `<img src="../uploads/avatars/${student.profile.profile_pic}" class="w-full h-full rounded-2xl object-cover">`;
    } else {
        avatarBox.textContent = (student.name || 'S').charAt(0).toUpperCase();
    }

    // Progress Text
    document.getElementById('modalProgressText').textContent = `Total Progress: ${student.submitted_weeks} of ${student.total_weeks} Weeks Submitted (${student.progress_pct}%)`;

    // Summary Stats
    const statsBox = document.getElementById('modalSummaryStats');
    statsBox.innerHTML = `
        <span class="text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded">✓ ${student.graded_weeks} Graded</span>
        ${student.ready_weeks.length > 0 ? `<span class="text-blue-700 font-bold bg-blue-50 px-2 py-0.5 rounded animate-pulse">⚡ ${student.ready_weeks.length} Ready</span>` : ''}
        ${student.waiting_weeks.length > 0 ? `<span class="text-amber-700 font-semibold bg-amber-50 px-2 py-0.5 rounded">⏳ ${student.waiting_weeks.length} In Review</span>` : ''}
    `;

    // Links
    document.getElementById('modalPrintAllBtn').href = `../student/print_report.php?student_id=${student.uid}&week=all`;
    document.getElementById('modalFullDashboardBtn').href = `view-student-dashboard.php?id=${student.uid}`;

    // Populate Weeks Table
    const tbody = document.getElementById('modalWeeksTableBody');
    tbody.innerHTML = '';

    const weeks = student.week_matrix;
    for (let w = 1; w <= student.total_weeks; w++) {
        const wm = weeks[w] || { status: 'not_submitted', status_label: 'Not Submitted', logs_count: 0, duration: '0h 00m' };
        const isReady = (wm.status === 'ready');
        const isGraded = (wm.status === 'graded');
        const isWaiting = (wm.status === 'waiting');

        let periodText = '—';
        if (wm.range && wm.range.start && wm.range.end) {
            periodText = `${formatDateShort(wm.range.start)} – ${formatDateShort(wm.range.end)}`;
        }

        let instructorBadge = '<span class="text-slate-400">Not Submitted</span>';
        if (wm.eval) {
            if (wm.eval.report_status === 'approved_by_instructor' || wm.eval.report_status === 'approved_by_supervisor') {
                instructorBadge = `<span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded font-bold">Approved (${wm.eval.grade || 'Good'})</span>`;
            } else if (wm.eval.report_status === 'rejected') {
                instructorBadge = `<span class="px-2 py-0.5 bg-rose-50 text-rose-700 border border-rose-200 rounded font-bold">Rejected</span>`;
            } else {
                instructorBadge = `<span class="px-2 py-0.5 bg-amber-50 text-amber-800 border border-amber-200 rounded font-semibold">⏳ In Review</span>`;
            }
        }

        let supervisorBadge = '<span class="text-slate-400">—</span>';
        if (isGraded && wm.sup_eval) {
            supervisorBadge = `<span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded font-bold">Grade: ${wm.sup_eval.weekly_grade}</span>`;
        } else if (isReady) {
            supervisorBadge = `<span class="px-2 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded font-bold animate-pulse">⚡ Ready to Grade</span>`;
        }

        let actionHtml = '';
        if (isReady) {
            actionHtml = `<a href="supervisor-review.php?student_id=${student.uid}&week=${w}" class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg transition">⚡ Grade</a>`;
        } else if (wm.eval || isGraded) {
            actionHtml = `
                <div class="flex items-center justify-end gap-1.5">
                    <a href="supervisor-review.php?student_id=${student.uid}&week=${w}" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg transition">View</a>
                    <a href="../student/print_report.php?student_id=${student.uid}&week=${w}" target="_blank" class="px-2 py-1 bg-slate-100 hover:bg-teal-50 hover:text-teal-700 text-slate-600 font-semibold rounded-lg transition" title="Save Week as PDF"><i class="fa-solid fa-file-pdf"></i></a>
                </div>
            `;
        } else {
            actionHtml = `<span class="text-slate-300 font-mono">—</span>`;
        }

        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50 transition';
        tr.innerHTML = `
            <td class="px-4 py-3 font-bold text-slate-800">
                <span class="inline-block px-2 py-0.5 rounded ${isGraded ? 'bg-emerald-100 text-emerald-800' : (isReady ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-700')}">Week ${w}</span>
            </td>
            <td class="px-4 py-3 text-slate-600">${periodText}</td>
            <td class="px-4 py-3 text-slate-600 font-medium">
                ${wm.logs_count} logs <span class="text-slate-400 font-mono text-[11px]">(${wm.duration || '0h'})</span>
            </td>
            <td class="px-4 py-3">${instructorBadge}</td>
            <td class="px-4 py-3">${supervisorBadge}</td>
            <td class="px-4 py-3 text-right">${actionHtml}</td>
        `;
        tbody.appendChild(tr);
    }

    // Show modal
    const modal = document.getElementById('studentBreakdownModal');
    const box = document.getElementById('breakdownModalBox');
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        box.classList.remove('scale-95');
    }, 10);
}

function closeStudentBreakdownModal() {
    const modal = document.getElementById('studentBreakdownModal');
    const box = document.getElementById('breakdownModalBox');
    modal.classList.add('opacity-0');
    box.classList.add('scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 200);
}

function formatDateShort(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { day: '2-digit', month: 'short' });
}

// Close on backdrop or ESC
document.getElementById('studentBreakdownModal').addEventListener('click', function(e) {
    if (e.target === this) closeStudentBreakdownModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeStudentBreakdownModal();
});
</script>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
