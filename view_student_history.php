<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/week_helper.php';
require_once __DIR__ . '/includes/ui_helpers.php';

// ── Authentication & Access Control ───────────────────────────────
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'supervisor'], true)) {
    header('Location: login.php');
    exit;
}

$uid = (int) ($_GET['uid'] ?? $_GET['student_id'] ?? $_GET['id'] ?? 0);
$db  = $mysqli ?? $conn;

if ($uid <= 0) {
    if ($role === 'admin') header('Location: admin/admin-dashboard.php?tab=history');
    elseif ($role === 'supervisor') header('Location: supervisor/my-students.php');
    else header('Location: dashboard.php');
    exit;
}

// ── Fetch Student Profile ────────────────────────────────────────
$profile_stmt = $db->prepare("
    SELECT u.id, u.username, u.email, ay.year_label AS academic_year, u.created_at, u.profile_pic,
           u.username AS full_name, sp.student_roll, sp.major,
           COALESCE(c.company_name, '') AS company_name, u.position AS job_role,
           sp.internship_start_date, sp.internship_end_date, sp.company_id,
           sup_u.username AS supervisor_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
    LEFT JOIN companies c ON c.id = sp.company_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE u.id = ? AND u.role = 'student'
");
$profile_stmt->bind_param("i", $uid);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$student = $res ? $res->fetch_assoc() : null;

if (!$student) {
    if ($role === 'admin') header('Location: admin/admin-dashboard.php?tab=history');
    else header('Location: supervisor/my-students.php');
    exit;
}

$student_name     = ($student['username'] ?? 'Student');
$student_roll     = $student['student_roll'] ?? '';
$intern_start     = $student['internship_start_date'] ?? null;
$intern_end       = $student['internship_end_date'] ?? null;
$supervisor_name  = $student['supervisor_name'] ?? '—';
$profile_pic      = $student['profile_pic'] ?? '';
$academic_year    = $student['academic_year'] ?? '';
$company_name     = $student['company_name'] ?? '';
$job_role         = $student['job_role'] ?? '';
$major            = $student['major'] ?? '';

// ── View Mode: weekly or monthly ─────────────────────────────────
$view_mode = $_GET['mode'] ?? 'weekly';
if (!in_array($view_mode, ['weekly', 'monthly'], true)) $view_mode = 'weekly';

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

// Fallback: If no internship_start_date, compute from daily_logs dates
if (empty($weeks)) {
    $all_dates = $db->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE student_id = ? ORDER BY log_date ASC");
    $all_dates->bind_param("i", $uid);
    $all_dates->execute();
    $res = $all_dates->get_result();
    $log_dates = [];
    if ($res) {
        while ($row = $res->fetch_row()) {
            $log_dates[] = $row[0];
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
    }
}

// ── Fetch all data ───────────────────────────────────────────────
if ($intern_start && $intern_end) {
    $all_logs_stmt = $db->prepare("SELECT * FROM daily_logs WHERE student_id = ? AND log_date >= ? AND log_date <= ? ORDER BY log_date ASC");
    $all_logs_stmt->bind_param("iss", $uid, $intern_start, $intern_end);
} else {
    $all_logs_stmt = $db->prepare("SELECT * FROM daily_logs WHERE student_id = ? ORDER BY log_date ASC");
    $all_logs_stmt->bind_param("i", $uid);
}
$all_logs_stmt->execute();
$res = $all_logs_stmt->get_result();
$all_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$all_reports_stmt = $db->prepare("SELECT wr.*, sup.username AS supervisor_name FROM weekly_reports wr LEFT JOIN student_profiles sp ON sp.user_id = wr.student_id LEFT JOIN users sup ON sup.id = sp.supervisor_id WHERE wr.student_id = ? ORDER BY wr.week_number ASC");
$all_reports_stmt->bind_param("i", $uid);
$all_reports_stmt->execute();
$res = $all_reports_stmt->get_result();
$all_reports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$all_refs = [];
$eval_by_week = [];
$sup_eval_by_week = [];
$refs_by_week = [];

foreach ($all_reports as $r) {
    $wn = (int) $r['week_number'];
    if (!empty($r['what_done'])) {
        $ref_item = [
            'week_number' => $wn,
            'what_done'   => $r['what_done'],
            'how_done'    => $r['how_done'],
            'why_done'    => $r['why_done'],
            'created_at'  => $r['submitted_at'],
        ];
        $all_refs[] = $ref_item;
        $refs_by_week[$wn] = $ref_item;
    }
    $eval_by_week[$wn] = [
        'week_number'             => $wn,
        'grade'                   => $r['instructor_grade'],
        'comment'                 => $r['instructor_comments'],
        'instructor_comments'     => $r['instructor_comments'],
        'report_status'           => $r['status'],
        'evaluated_at'            => $r['submitted_at'],
        'student_signature_type'  => $r['student_signature_type'] ?? null,
        'student_signature_value' => $r['student_signature_value'] ?? null,
        'student_signed_at'       => $r['student_signed_at'] ?? null,
    ];
    if (!empty($r['supervisor_grade'])) {
        $sup_eval_by_week[$wn] = [
            'week_number'         => $wn,
            'weekly_grade'        => $r['supervisor_grade'],
            'supervisor_comments' => $r['supervisor_comments'],
            'supervisor_name'     => $r['supervisor_name'],
            'evaluated_at'        => $r['submitted_at'],
        ];
    }
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

// ── Stats ────────────────────────────────────────────────────────
$total_logs_count = count($all_logs);

if ($intern_start && $intern_end) {
    $present_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND log_date >= ? AND log_date <= ? AND attendance_status = 'present'");
    $present_stmt->bind_param("iss", $uid, $intern_start, $intern_end);
    $present_stmt->execute();
    $res = $present_stmt->get_result();
    $p_row = $res ? $res->fetch_row() : null;
    $total_present = (int) ($p_row[0] ?? 0);

    $absent_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND log_date >= ? AND log_date <= ? AND attendance_status IN ('absent','leave')");
    $absent_stmt->bind_param("iss", $uid, $intern_start, $intern_end);
    $absent_stmt->execute();
    $res = $absent_stmt->get_result();
    $a_row = $res ? $res->fetch_row() : null;
    $total_absent = (int) ($a_row[0] ?? 0);
} else {
    $present_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND attendance_status = 'present'");
    $present_stmt->bind_param("i", $uid);
    $present_stmt->execute();
    $res = $present_stmt->get_result();
    $p_row = $res ? $res->fetch_row() : null;
    $total_present = (int) ($p_row[0] ?? 0);

    $absent_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND attendance_status IN ('absent','leave')");
    $absent_stmt->bind_param("i", $uid);
    $absent_stmt->execute();
    $res = $absent_stmt->get_result();
    $a_row = $res ? $res->fetch_row() : null;
    $total_absent = (int) ($a_row[0] ?? 0);
}

$total_weeks = count($weeks);
$total_reflections = count($all_refs);

$progress_weeks_completed = 0;
if (!empty($weeks)) {
    $wc_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND log_date BETWEEN ? AND ?");
    foreach ($weeks as $wn => $wr) {
        $wc_stmt->bind_param("iss", $uid, $wr['start'], $wr['end']);
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

// ── Filtered week/month ──────────────────────────────────────────
$default_week = !empty($weeks) ? (isset($weeks[1]) ? 1 : (int) array_key_first($weeks)) : 0;
$default_month = !empty($months) ? (string) array_key_first($months) : '';

if ($view_mode === 'weekly') {
    if (isset($_GET['week'])) {
        $filter_week = ($_GET['week'] === 'all' || $_GET['week'] === '0') ? 0 : (int) $_GET['week'];
    } else {
        $filter_week = $default_week;
    }
    $filter_month = '';
} else {
    if (isset($_GET['month'])) {
        $filter_month = ($_GET['month'] === 'all' || $_GET['month'] === '0') ? '' : trim($_GET['month']);
    } else {
        $filter_month = $default_month;
    }
    $filter_week = 0;
}

// ── Stats for full internship period ─────────────────────────────
$display_logs_count  = $total_logs_count;
$display_present     = $total_present;
$display_absent      = $total_absent;
$display_reflections = $total_reflections;
$display_hours       = $total_hours;
$display_mins        = $total_mins;
$all_recorded_days   = $display_present + $display_absent;
$all_att_rate        = $all_recorded_days > 0 ? (int)round(($display_present / $all_recorded_days) * 100) : 0;

// Back link
$back_url = ($role === 'admin') ? 'admin/admin-dashboard.php?tab=history' : 'supervisor/my-students.php';
if (!empty($_SERVER['HTTP_REFERER']) && !str_contains($_SERVER['HTTP_REFERER'], 'view_student_history.php')) {
    $back_url = $_SERVER['HTTP_REFERER'];
}

$gmap = [
    'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
    'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
    'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
    'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
];

$sgd = [
    'A' => ['Grade: A', 'text-emerald-600 bg-emerald-50'],
    'B' => ['Grade: B', 'text-blue-600 bg-blue-50'],
    'C' => ['Grade: C', 'text-amber-600 bg-amber-50'],
    'D' => ['Grade: D', 'text-orange-600 bg-orange-50'],
    'F' => ['Grade: F', 'text-red-600 bg-red-50'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student_name) ?> – Log History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Great+Vibes&display=swap" rel="stylesheet">
    <script src="assets/js/main.js"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    'sans': ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
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
    html { scrollbar-gutter: stable; overflow-y: scroll; }
    @media print {
        @page {
            size: A4 portrait;
            margin: 15mm 15mm 15mm 15mm;
        }
        body {
            background: #ffffff !important;
            color: #000000 !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
            font-size: 9.5pt !important;
            line-height: 1.35 !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        #web-app-container, .no-print, button, form button, #printModal {
            display: none !important;
        }
        #printable-report {
            display: block !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .print-doc-table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin-top: 6px !important;
            margin-bottom: 12px !important;
        }
        .print-doc-table thead {
            display: table-header-group !important;
        }
        .print-doc-table tr {
            page-break-inside: avoid !important;
        }
        .print-doc-table th, .print-doc-table td {
            border: 1px solid #334155 !important;
            padding: 4px 6px !important;
            font-size: 8.5pt !important;
        }
        .print-doc-table th {
            background-color: #f8fafc !important;
            color: #0f172a !important;
            font-weight: 700 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .print-card-box {
            border: 1px solid #cbd5e1 !important;
            page-break-inside: avoid !important;
            margin-bottom: 10px !important;
            padding: 10px !important;
            border-radius: 4px !important;
        }
        .print-section-title {
            font-size: 11pt !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            border-bottom: 1.5px solid #0f172a !important;
            padding-bottom: 3px !important;
            margin-top: 16px !important;
            margin-bottom: 8px !important;
            color: #0f172a !important;
        }
    }
    </style>
    <script>
    function openPrintModal() {
        var m = document.getElementById('printModal');
        if (m) m.classList.remove('hidden');
    }
    function closePrintModal() {
        var m = document.getElementById('printModal');
        if (m) m.classList.add('hidden');
    }
    function toggleFullReport(cb) {
        var sum = document.getElementById('print_opt_summary');
        var logs = document.getElementById('print_opt_logs');
        var ref = document.getElementById('print_opt_reflections');
        var ev = document.getElementById('print_opt_evaluations');
        if (sum) sum.checked = cb.checked;
        if (logs) logs.checked = cb.checked;
        if (ref) ref.checked = cb.checked;
        if (ev) ev.checked = cb.checked;
    }
    function executePrint() {
        var sum = document.getElementById('print_opt_summary') ? document.getElementById('print_opt_summary').checked : true;
        var logs = document.getElementById('print_opt_logs') ? document.getElementById('print_opt_logs').checked : true;
        var ref = document.getElementById('print_opt_reflections') ? document.getElementById('print_opt_reflections').checked : true;
        var ev = document.getElementById('print_opt_evaluations') ? document.getElementById('print_opt_evaluations').checked : true;
        
        var elSum = document.getElementById('print-doc-summary');
        var elLogs = document.getElementById('print-doc-logs');
        var elRef = document.getElementById('print-doc-reflections');
        var elEv = document.getElementById('print-doc-evaluations');
        
        if (elSum) elSum.style.display = sum ? 'block' : 'none';
        if (elLogs) elLogs.style.display = logs ? 'block' : 'none';
        if (elRef) elRef.style.display = ref ? 'block' : 'none';
        if (elEv) elEv.style.display = ev ? 'block' : 'none';
        
        closePrintModal();
        setTimeout(function() {
            window.print();
        }, 100);
    }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-sans antialiased p-4 sm:p-6 md:p-8">

<div id="web-app-container" class="max-w-7xl mx-auto w-full space-y-6">

    <!-- ════ TOP NAVIGATION ════ -->
    <div class="flex items-center justify-between no-print">
        <a href="<?= htmlspecialchars($back_url) ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-slate-50 border border-slate-200/80 text-slate-700 text-xs font-semibold rounded-xl shadow-xs transition-all duration-200 ease-in-out">
            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="text-xs text-slate-400 font-medium">
            Student Management &amp; Audit Log
        </div>
    </div>

    <!-- ════ PAGE HEADER / STUDENT PROFILE BANNER ════ -->
    <div class="bg-white border border-slate-200/80 shadow-sm rounded-2xl p-6 no-print">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <?php if (!empty($profile_pic)): ?>
                <img src="uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-14 h-14 rounded-2xl object-cover border border-slate-200 shadow-sm shrink-0">
                <?php else: ?>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xl font-bold shrink-0 shadow-md shadow-indigo-500/20">
                    <?= strtoupper(substr($student_name, 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div>
                    <h1 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($student_name) ?> — Log History</h1>
                    <p class="text-xs text-slate-400 font-mono mt-0.5">Roll: <?= htmlspecialchars($student_roll ?: '—') ?> · <?= htmlspecialchars($student['email']) ?></p>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                        <?php if (!empty($company_name)): ?>
                            <span class="text-xs font-medium text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded border border-blue-200/60">🏢 <?= htmlspecialchars($company_name) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($academic_year)): ?>
                            <span class="text-xs font-medium text-indigo-600 bg-indigo-50 px-2.5 py-0.5 rounded font-mono border border-indigo-200/60"><?= htmlspecialchars($academic_year) ?></span>
                        <?php endif; ?>
                        <?php if ($supervisor_name && $supervisor_name !== '—'): ?>
                            <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded border border-emerald-200/60">👨‍🏫 Sup: <?= htmlspecialchars(format_supervisor_name($supervisor_name)) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($major)): ?>
                            <span class="text-xs font-medium text-slate-600 bg-slate-100 px-2.5 py-0.5 rounded"><?= htmlspecialchars($major) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="text-right text-xs text-slate-400">
                <p>Member since <?= (new DateTime($student['created_at']))->format('d M Y') ?></p>
                <?php if ($intern_start && $intern_end): ?>
                <p class="mt-0.5 font-medium text-slate-500">📅 <?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ════ STATS CARDS (EXACT REPLICATION OF STUDENT LOG HISTORY) ════ -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 no-print">
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
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 no-print">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <!-- View Mode Toggle -->
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">View:</span>
                <a href="?uid=<?= $uid ?>&mode=weekly" class="px-3.5 py-1.5 text-xs font-bold rounded-xl transition <?= $view_mode === 'weekly' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Weekly</a>
                <a href="?uid=<?= $uid ?>&mode=monthly" class="px-3.5 py-1.5 text-xs font-bold rounded-xl transition <?= $view_mode === 'monthly' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Monthly</a>
            </div>

            <!-- Week / Month Filter Jump Selectors -->
            <?php if ($view_mode === 'weekly' && !empty($weeks)): ?>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Jump to:</span>
                <select onchange="window.location.href='?uid=<?= $uid ?>&mode=weekly&week=' + this.value;" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                    <option value="all" <?= ($filter_week === 0) ? 'selected' : '' ?>>All Weeks (<?= count($weeks) ?> weeks)</option>
                    <?php foreach ($weeks as $wn => $wr): ?>
                    <option value="<?= $wn ?>" <?= ($filter_week === $wn) ? 'selected' : '' ?>>Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif ($view_mode === 'monthly' && !empty($months)): ?>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Jump to:</span>
                <select onchange="window.location.href='?uid=<?= $uid ?>&mode=monthly&month=' + encodeURIComponent(this.value);" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                    <option value="all" <?= ($filter_month === '' || $filter_month === 'all') ? 'selected' : '' ?>>All Months (<?= count($months) ?> months)</option>
                    <?php foreach ($months as $mk => $mv): ?>
                    <option value="<?= htmlspecialchars($mk) ?>" <?= ($filter_month === $mk) ? 'selected' : '' ?>><?= htmlspecialchars($mv['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Print Action -->
            <button onclick="openPrintModal()" class="flex items-center gap-2 px-3.5 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition shadow-xs cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-16-5V9a2 2 0 012-2h12a2 2 0 012 2v4m-12 9h8a2 2 0 002-2v-3a2 2 0 00-2-2H8a2 2 0 00-2 2v3a2 2 0 002 2z"/></svg>
                Print Report
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
                $week_sup_eval = $sup_eval_by_week[$wn] ?? null;
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
                <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2 bg-gradient-to-r from-slate-50 to-white">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xs font-bold shadow-sm shadow-indigo-500/20">
                            W<?= $wn ?>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-800">Week <?= $wn ?></h2>
                            <span class="text-xs text-slate-400">
                                <?= (new DateTime($wr['start']))->format('d M Y') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-bold text-emerald-600 bg-emerald-50 border border-emerald-200/60 px-2.5 py-0.5 rounded-lg"><?= $week_present ?> Present</span>
                        <span class="text-xs font-bold text-red-600 bg-red-50 border border-red-200/60 px-2.5 py-0.5 rounded-lg"><?= $week_absent ?> Absent</span>
                        <span class="text-xs font-bold text-blue-600 bg-blue-50 border border-blue-200/60 px-2.5 py-0.5 rounded-lg"><?= floor($week_minutes / 60) ?>h <?= str_pad($week_minutes % 60, 2, '0', STR_PAD_LEFT) ?>m</span>
                        <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
                            <?php
                            $gs = $gmap[$week_eval['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                            ?>
                            <span class="text-xs font-bold <?= $gs[1] ?> <?= $gs[2] ?> border px-2.5 py-0.5 rounded-lg"><?= $gs[0] ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-5 space-y-5">
                    <!-- Daily Logs -->
                    <?php if (!empty($week_logs)): ?>
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2.5 flex items-center gap-1.5">
                            <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </span> Daily Logs
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-xs border-b border-slate-100">
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
                                        <td class="px-4 py-3 text-sm text-slate-700 font-medium whitespace-nowrap">
                                            <?= (new DateTime($wl['log_date']))->format('D, d M Y') ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if ($wl['attendance_status'] === 'present'): ?>
                                                <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg border border-emerald-200/60">Present</span>
                                            <?php else: ?>
                                                <span class="text-xs font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded-lg border border-red-200/60">Absent</span>
                                                <?php if (!empty($wl['reason_for_absence'])): ?>
                                                    <span class="text-[11px] text-slate-400 block mt-0.5" title="<?= htmlspecialchars($wl['reason_for_absence']) ?>">Reason noted</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <?php $is_absent = ($wl['attendance_status'] ?? 'present') === 'absent'; ?>
                                        <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[150px]"><?= $is_absent ? '-' : htmlspecialchars($wl['task_title'] ?: '-') ?></td>
                                        <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[200px]"><?= $is_absent ? '-' : htmlspecialchars($wl['tasks_performed'] ?: '-') ?></td>
                                        <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[150px]"><?= $is_absent ? '-' : htmlspecialchars($wl['tools_used'] ?: '-') ?></td>
                                        <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[150px]"><?= $is_absent ? '-' : htmlspecialchars($wl['learnt_skills'] ?: '-') ?></td>
                                        <td class="px-4 py-3 font-mono text-blue-600 text-sm font-bold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></td>
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
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2.5 flex items-center gap-1.5">
                            <span class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </span> Weekly Reflection
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-slate-50 rounded-xl p-3.5 border border-slate-100">
                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">What was done?</span>
                                <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['what_done'] ?? '')) ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3.5 border border-slate-100">
                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">How was it done?</span>
                                <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['how_done'] ?? '')) ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3.5 border border-slate-100">
                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Why was it done?</span>
                                <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['why_done'] ?? '')) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Student Signature (compact inline) -->
                    <?php if (!empty($week_eval['student_signature_value'])): ?>
                    <div class="border-t border-slate-100 pt-3 flex items-center justify-end gap-2">
                        <span class="text-caption text-slate-400 font-medium">Student Signature:</span>
                        <?php if (!empty($week_eval['student_signature_type']) && $week_eval['student_signature_type'] === 'typed'): ?>
                            <span class="inline-flex items-center px-3 py-1 bg-white border border-slate-200 rounded-lg max-h-[40px] overflow-hidden" style="font-family: 'Great Vibes', cursive; font-size: 1.1rem; line-height: 1;"><?= htmlspecialchars($week_eval['student_signature_value']) ?></span>
                        <?php else: ?>
                            <?php
                            $sig_src_w = $week_eval['student_signature_value'];
                            if (!str_starts_with($sig_src_w, 'data:') && !str_starts_with($sig_src_w, 'http') && !str_starts_with($sig_src_w, 'uploads/')) {
                                $sig_src_w = 'uploads/signatures/' . $sig_src_w;
                            }
                            ?>
                            <img src="<?= htmlspecialchars($sig_src_w) ?>" alt="Student Signature" class="h-8 object-contain bg-white border border-slate-200 rounded-lg px-2 py-0.5">
                        <?php endif; ?>
                        <span class="inline-flex items-center gap-1 text-emerald-600 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full text-caption font-bold shrink-0">&#10003; Signed</span>
                    </div>
                    <?php endif; ?>

                    <!-- Evaluations & Feedback (Two Columns) -->
                    <?php if ($week_eval || $week_sup_eval): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <!-- Company Instructor Feedback -->
                        <div class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50/70 to-white p-3.5 flex flex-col">
                            <div class="flex items-center gap-2.5 mb-2">
                                <div class="w-7 h-7 rounded-lg bg-teal-600 text-white flex items-center justify-center shrink-0 shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-16 0H3m14-4h-2m2-4h-2m-4 8v-4m-4 0H7m2 4H7"/></svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-slate-800">Company Instructor</p>
                                    <p class="text-[11px] text-slate-400 font-medium">Evaluation &amp; Comments</p>
                                </div>
                                <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
                                <?php $ig = $week_eval['grade'] ?? ''; $igd = $gmap[$ig] ?? ['—', 'text-slate-400', 'bg-slate-50']; ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold <?= $igd[1] ?> <?= $igd[2] ?> border shrink-0"><?= htmlspecialchars($igd[0]) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
                            <div class="flex-1 space-y-2">
                                <span class="inline-flex items-center text-[11px] font-bold <?= $week_eval['report_status'] === 'approved_by_instructor' ? 'text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200/60' : ($week_eval['report_status'] === 'rejected' ? 'text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-200/60' : 'text-amber-600 bg-amber-50 px-2 py-0.5 rounded border border-amber-200/60') ?>">
                                    <?= $week_eval['report_status'] === 'approved_by_instructor' ? 'Approved by Instructor' : ($week_eval['report_status'] === 'rejected' ? 'Rejected' : ucfirst(str_replace('_', ' ', $week_eval['report_status']))) ?>
                                </span>
                                <?php if ($week_eval['comment']): ?>
                                <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_eval['comment'])) ?></p>
                                <?php else: ?>
                                <p class="text-xs italic text-slate-400">No written comments provided.</p>
                                <?php endif; ?>
                                <?php if ($week_eval['instructor_comments']): ?>
                                <div class="bg-red-50 border border-red-100 rounded-lg p-2">
                                    <p class="text-[11px] font-bold text-red-400 uppercase tracking-wider mb-0.5">Revision Requested</p>
                                    <p class="text-xs text-red-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_eval['instructor_comments'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                                <p class="text-[11px] text-slate-400 flex items-center gap-1.5">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <?= htmlspecialchars((new DateTime($week_eval['evaluated_at']))->format('d M Y, h:i A')) ?>
                                </p>
                                <?php if (!empty($week_eval['signature_value'])): ?>
                                <div class="flex items-center gap-1.5">
                                    <?php if (($week_eval['signature_type'] ?? '') === 'typed'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 bg-white border border-slate-200 rounded max-h-[26px] overflow-hidden" style="font-family: 'Great Vibes', cursive; font-size: 0.8rem; line-height: 1;"><?= htmlspecialchars($week_eval['signature_value']) ?></span>
                                    <?php else: ?>
                                        <img src="<?= htmlspecialchars($week_eval['signature_value']) ?>" alt="Instructor Signature" class="h-5 object-contain">
                                    <?php endif; ?>
                                    <span class="inline-flex items-center gap-0.5 text-emerald-600 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-full text-[10px] font-bold">&#10003; Signed</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="flex-1 flex items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50/60 py-4 text-center">
                                <div>
                                    <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-1">
                                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <p class="text-xs font-semibold text-slate-500">Awaiting instructor evaluation…</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">Not reviewed yet.</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- CU Supervisor Feedback -->
                        <div class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50/70 to-white p-3.5 flex flex-col">
                            <div class="flex items-center gap-2.5 mb-2">
                                <div class="w-7 h-7 rounded-lg bg-teal-700 text-white flex items-center justify-center shrink-0 shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-slate-800">CU Supervisor</p>
                                    <p class="text-[11px] text-slate-400 font-medium"><?= htmlspecialchars(format_supervisor_name($week_sup_eval['supervisor_name'] ?? $supervisor_name)) ?></p>
                                </div>
                                <?php if (!empty($week_sup_eval['weekly_grade'])): ?>
                                <?php $sg = $week_sup_eval['weekly_grade']; $sgdi = $sgd[$sg] ?? [$sg, 'text-slate-700 bg-slate-50']; ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold <?= $sgdi[1] ?> border shrink-0"><?= htmlspecialchars($sgdi[0]) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($week_sup_eval)): ?>
                            <div class="flex-1">
                                <?php $sup_comments = trim($week_sup_eval['supervisor_comments'] ?? ''); ?>
                                <?php if ($sup_comments !== ''): ?>
                                <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($sup_comments)) ?></p>
                                <?php else: ?>
                                <p class="text-xs italic text-slate-400">No written comments provided.</p>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($week_sup_eval['evaluated_at'])): ?>
                            <p class="text-[11px] text-slate-400 mt-2 pt-2 border-t border-slate-100 flex items-center gap-1.5">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?= htmlspecialchars((new DateTime($week_sup_eval['evaluated_at']))->format('d M Y, h:i A')) ?>
                            </p>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="flex-1 flex items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50/60 py-4 text-center">
                                <div>
                                    <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-1">
                                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <p class="text-xs font-semibold text-slate-500">Awaiting CU supervisor review…</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">Not reviewed yet.</p>
                                </div>
                            </div>
                            <?php endif; ?>
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
                <p class="text-sm text-slate-500 font-bold">No weekly data recorded yet.</p>
                <p class="text-xs text-slate-400 mt-1">This student has not submitted any daily logs yet.</p>
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
                <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2 bg-gradient-to-r from-slate-50 to-white">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-purple-600 text-white flex items-center justify-center text-xs font-bold shadow-sm shadow-purple-500/20">
                            <?= date('M', strtotime($mk . '-01')) ?>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-800"><?= htmlspecialchars($mv['label']) ?></h2>
                            <span class="text-xs text-slate-400"><?= count($mv['weeks']) ?> week(s) · <?= count($month_logs) ?> log(s)</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-bold text-emerald-600 bg-emerald-50 border border-emerald-200/60 px-2.5 py-0.5 rounded-lg"><?= $month_present ?> Present</span>
                        <span class="text-xs font-bold text-red-600 bg-red-50 border border-red-200/60 px-2.5 py-0.5 rounded-lg"><?= $month_absent ?> Absent</span>
                        <span class="text-xs font-bold text-blue-600 bg-blue-50 border border-blue-200/60 px-2.5 py-0.5 rounded-lg"><?= floor($month_minutes / 60) ?>h <?= str_pad($month_minutes % 60, 2, '0', STR_PAD_LEFT) ?>m</span>
                        <span class="text-xs font-bold text-indigo-600 bg-indigo-50 border border-indigo-200/60 px-2.5 py-0.5 rounded-lg"><?= $month_refs ?> Reflections</span>
                        <span class="text-xs font-bold text-amber-600 bg-amber-50 border border-amber-200/60 px-2.5 py-0.5 rounded-lg"><?= $month_evals ?> Evaluated</span>
                    </div>
                </div>

                <div class="p-5 space-y-4">
                    <?php foreach ($mv['weeks'] as $wn): ?>
                    <?php
                        $week_logs_m = $logs_by_week[$wn] ?? [];
                        $week_ref_m  = $refs_by_week[$wn] ?? null;
                        $week_eval_m = $eval_by_week[$wn] ?? null;
                        $week_sup_eval_m = $sup_eval_by_week[$wn] ?? null;
                        $wr = $weeks[$wn];
                    ?>
                    <!-- Week within month -->
                    <div class="border border-slate-200 rounded-xl overflow-hidden shadow-xs">
                        <div class="px-4 py-2.5 bg-slate-50/90 flex items-center justify-between border-b border-slate-100">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-bold"><?= $wn ?></span>
                                <span class="text-xs font-bold text-slate-800">Week <?= $wn ?></span>
                                <span class="text-xs text-slate-400"><?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <?php
                                $wk_p = 0; $wk_a = 0;
                                foreach ($week_logs_m as $wl) {
                                    if ($wl['attendance_status'] === 'present') $wk_p++; else $wk_a++;
                                }
                                ?>
                                <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded"><?= $wk_p ?></span>
                                <span class="text-xs font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded"><?= $wk_a ?></span>
                                <?php if ($week_ref_m): ?>
                                    <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">Ref</span>
                                <?php endif; ?>
                                <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                    <?php
                                    $ev_status = $week_eval_m['report_status'];
                                    $ev_cls = $ev_status === 'approved_by_instructor' || $ev_status === 'approved_by_supervisor' ? 'text-emerald-600 bg-emerald-50 border border-emerald-200/60' : ($ev_status === 'rejected' ? 'text-red-600 bg-red-50 border border-red-200/60' : 'text-amber-600 bg-amber-50 border border-amber-200/60');
                                    ?>
                                    <span class="text-xs font-bold <?= $ev_cls ?> px-2 py-0.5 rounded"><?= $ev_status === 'rejected' ? 'Rejected' : 'Evaluated' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($week_logs_m)): ?>
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5 px-4 pt-3">
                                <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </span> Daily Logs
                            </h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-xs border-b border-slate-100">
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
                                            <td class="px-4 py-3 text-sm text-slate-700 font-medium whitespace-nowrap">
                                                <?= (new DateTime($wl['log_date']))->format('D, d M Y') ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if ($wl['attendance_status'] === 'present'): ?>
                                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200/60">Present</span>
                                                <?php else: ?>
                                                    <span class="text-xs font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-200/60">Absent</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php $is_absent_m = ($wl['attendance_status'] ?? 'present') === 'absent'; ?>
                                            <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[150px]"><?= $is_absent_m ? '-' : htmlspecialchars($wl['task_title'] ?: '-') ?></td>
                                            <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[200px]"><?= $is_absent_m ? '-' : htmlspecialchars($wl['tasks_performed'] ?: '-') ?></td>
                                            <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[150px]"><?= $is_absent_m ? '-' : htmlspecialchars($wl['tools_used'] ?: '-') ?></td>
                                            <td class="px-4 py-3 text-sm text-slate-700 align-top break-words max-w-[150px]"><?= $is_absent_m ? '-' : htmlspecialchars($wl['learnt_skills'] ?: '-') ?></td>
                                            <td class="px-4 py-3 font-mono text-blue-600 text-sm font-bold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="px-4 py-3 text-center">
                            <p class="text-xs text-slate-400">No logs for this week.</p>
                        </div>
                        <?php endif; ?>

                        <!-- Week Reflection (Monthly) -->
                        <?php if ($week_ref_m): ?>
                        <div class="border-t border-slate-100 pt-4 px-4 pb-3">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
                                <span class="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                </span> Weekly Reflection
                            </h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div class="bg-slate-50 rounded-xl p-3.5 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">What was done?</span>
                                    <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref_m['what_done'] ?? '')) ?></p>
                                </div>
                                <div class="bg-slate-50 rounded-xl p-3.5 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">How was it done?</span>
                                    <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref_m['how_done'] ?? '')) ?></p>
                                </div>
                                <div class="bg-slate-50 rounded-xl p-3.5 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block mb-1">Why was it done?</span>
                                    <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref_m['why_done'] ?? '')) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Student Signature (compact inline, Monthly) -->
                        <?php if (!empty($week_eval_m['student_signature_value'])): ?>
                        <div class="border-t border-slate-100 pt-3 px-4 flex items-center justify-end gap-2">
                            <span class="text-caption text-slate-400 font-medium">Student Signature:</span>
                            <?php if (!empty($week_eval_m['student_signature_type']) && $week_eval_m['student_signature_type'] === 'typed'): ?>
                                <span class="inline-flex items-center px-3 py-1 bg-white border border-slate-200 rounded-lg max-h-[40px] overflow-hidden" style="font-family: 'Great Vibes', cursive; font-size: 1.1rem; line-height: 1;"><?= htmlspecialchars($week_eval_m['student_signature_value']) ?></span>
                            <?php else: ?>
                                <?php
                                $sig_src_m = $week_eval_m['student_signature_value'];
                                if (!str_starts_with($sig_src_m, 'data:') && !str_starts_with($sig_src_m, 'http') && !str_starts_with($sig_src_m, 'uploads/')) {
                                    $sig_src_m = 'uploads/signatures/' . $sig_src_m;
                                }
                                ?>
                                <img src="<?= htmlspecialchars($sig_src_m) ?>" alt="Student Signature" class="h-8 object-contain bg-white border border-slate-200 rounded-lg px-2 py-0.5">
                            <?php endif; ?>
                            <span class="inline-flex items-center gap-1 text-emerald-600 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full text-caption font-bold shrink-0">&#10003; Signed</span>
                        </div>
                        <?php endif; ?>

                        <!-- Evaluations & Feedback (Monthly) -->
                        <?php if ($week_eval_m || $week_sup_eval_m): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3 px-4 pb-3">
                            <!-- Company Instructor Feedback -->
                            <div class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50/70 to-white p-3.5 flex flex-col">
                                <div class="flex items-center gap-2.5 mb-2">
                                    <div class="w-7 h-7 rounded-lg bg-teal-600 text-white flex items-center justify-center shrink-0 shadow-sm">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-16 0H3m14-4h-2m2-4h-2m-4 8v-4m-4 0H7m2 4H7"/></svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-bold text-slate-800">Company Instructor</p>
                                        <p class="text-[11px] text-slate-400 font-medium">Evaluation &amp; Comments</p>
                                    </div>
                                    <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                    <?php $ig_m = $week_eval_m['grade'] ?? ''; $igd_m = $gmap[$ig_m] ?? ['—', 'text-slate-400', 'bg-slate-50']; ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold <?= $igd_m[1] ?> <?= $igd_m[2] ?> border shrink-0"><?= htmlspecialchars($igd_m[0]) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($week_eval_m && $week_eval_m['report_status'] !== 'pending'): ?>
                                <div class="flex-1 space-y-2">
                                    <span class="inline-flex items-center text-[11px] font-bold <?= $week_eval_m['report_status'] === 'approved_by_instructor' ? 'text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200/60' : ($week_eval_m['report_status'] === 'rejected' ? 'text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-200/60' : 'text-amber-600 bg-amber-50 px-2 py-0.5 rounded border border-amber-200/60') ?>">
                                        <?= $week_eval_m['report_status'] === 'approved_by_instructor' ? 'Approved by Instructor' : ($week_eval_m['report_status'] === 'rejected' ? 'Rejected' : ucfirst(str_replace('_', ' ', $week_eval_m['report_status']))) ?>
                                    </span>
                                    <?php if ($week_eval_m['comment']): ?>
                                    <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_eval_m['comment'])) ?></p>
                                    <?php else: ?>
                                    <p class="text-xs italic text-slate-400">No written comments provided.</p>
                                    <?php endif; ?>
                                    <?php if ($week_eval_m['instructor_comments']): ?>
                                    <div class="bg-red-50 border border-red-100 rounded-lg p-2">
                                        <p class="text-[11px] font-bold text-red-400 uppercase tracking-wider mb-0.5">Revision Requested</p>
                                        <p class="text-xs text-red-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_eval_m['instructor_comments'])) ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                                    <p class="text-[11px] text-slate-400 flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <?= htmlspecialchars((new DateTime($week_eval_m['evaluated_at']))->format('d M Y, h:i A')) ?>
                                    </p>
                                    <?php if (!empty($week_eval_m['signature_value'])): ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php if (($week_eval_m['signature_type'] ?? '') === 'typed'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 bg-white border border-slate-200 rounded max-h-[26px] overflow-hidden" style="font-family: 'Great Vibes', cursive; font-size: 0.8rem; line-height: 1;"><?= htmlspecialchars($week_eval_m['signature_value']) ?></span>
                                        <?php else: ?>
                                            <img src="<?= htmlspecialchars($week_eval_m['signature_value']) ?>" alt="Instructor Signature" class="h-5 object-contain">
                                        <?php endif; ?>
                                        <span class="inline-flex items-center gap-0.5 text-emerald-600 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-full text-[10px] font-bold">&#10003; Signed</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="flex-1 flex items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50/60 py-4 text-center">
                                    <div>
                                        <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-1">
                                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </div>
                                        <p class="text-xs font-semibold text-slate-500">Awaiting instructor evaluation…</p>
                                        <p class="text-[11px] text-slate-400 mt-0.5">Not reviewed yet.</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- CU Supervisor Feedback (Monthly) -->
                            <div class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50/70 to-white p-3.5 flex flex-col">
                                <div class="flex items-center gap-2.5 mb-2">
                                    <div class="w-7 h-7 rounded-lg bg-teal-700 text-white flex items-center justify-center shrink-0 shadow-sm">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-slate-800">CU Supervisor</p>
                                    <p class="text-[11px] text-slate-400 font-medium"><?= htmlspecialchars(format_supervisor_name($week_sup_eval_m['supervisor_name'] ?? $supervisor_name)) ?></p>
                                </div>
                                <?php if (!empty($week_sup_eval_m['weekly_grade'])): ?>
                                <?php $sg_m = $week_sup_eval_m['weekly_grade']; $sgdi_m = $sgd[$sg_m] ?? [$sg_m, 'text-slate-700 bg-slate-50']; ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold <?= $sgdi_m[1] ?> border shrink-0"><?= htmlspecialchars($sgdi_m[0]) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($week_sup_eval_m)): ?>
                            <div class="flex-1">
                                <?php $sup_comments_m = trim($week_sup_eval_m['supervisor_comments'] ?? ''); ?>
                                <?php if ($sup_comments_m !== ''): ?>
                                <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($sup_comments_m)) ?></p>
                                <?php else: ?>
                                <p class="text-xs italic text-slate-400">No written comments provided.</p>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($week_sup_eval_m['evaluated_at'])): ?>
                            <p class="text-[11px] text-slate-400 mt-2 pt-2 border-t border-slate-100 flex items-center gap-1.5">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?= htmlspecialchars((new DateTime($week_sup_eval_m['evaluated_at']))->format('d M Y, h:i A')) ?>
                            </p>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="flex-1 flex items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50/60 py-4 text-center">
                                <div>
                                    <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-1">
                                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <p class="text-xs font-semibold text-slate-500">Awaiting CU supervisor review…</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">Not reviewed yet.</p>
                                </div>
                            </div>
                            <?php endif; ?>
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
            <p class="text-sm text-slate-500 font-bold">No monthly data recorded yet.</p>
            <p class="text-xs text-slate-400 mt-1">This student has not submitted any daily logs yet.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ══════════════════ PRINT SELECTION MODAL DIALOG ═════════════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="printModal" class="hidden fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 print:hidden">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-sm w-full p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
            <h3 class="text-sm font-bold text-slate-800">Print Internship Report</h3>
            <button type="button" onclick="closePrintModal()" class="text-slate-400 hover:text-slate-600 text-lg leading-none cursor-pointer">✕</button>
        </div>
        
        <p class="text-xs text-slate-500">Choose sections to include in the printable report:</p>
        
        <div class="space-y-2 text-xs text-slate-700 font-medium">
            <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                <input type="checkbox" id="print_opt_summary" checked class="accent-indigo-600 rounded">
                <span>Student Summary</span>
            </label>
            <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                <input type="checkbox" id="print_opt_logs" checked class="accent-indigo-600 rounded">
                <span>Daily Logs</span>
            </label>
            <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                <input type="checkbox" id="print_opt_reflections" checked class="accent-indigo-600 rounded">
                <span>Weekly Reflections</span>
            </label>
            <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                <input type="checkbox" id="print_opt_evaluations" checked class="accent-indigo-600 rounded">
                <span>Signatures &amp; Evaluations</span>
            </label>
            <div class="pt-1">
                <label class="flex items-center gap-2.5 p-2 rounded-xl bg-indigo-50/60 hover:bg-indigo-50 cursor-pointer border border-indigo-200 text-indigo-900 font-bold">
                    <input type="checkbox" id="print_opt_full" onchange="toggleFullReport(this)" checked class="accent-indigo-600 rounded">
                    <span>Full Internship Report</span>
                </label>
            </div>
        </div>
        
        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
            <button type="button" onclick="closePrintModal()" class="px-3.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition cursor-pointer">
                Cancel
            </button>
            <button type="button" onclick="executePrint()" class="px-4 py-1.5 text-xs font-bold text-white bg-slate-800 hover:bg-slate-900 rounded-xl shadow-xs transition cursor-pointer flex items-center gap-1.5">
                <span>Print Selected</span>
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ══════════════════ REALISTIC A4 UNIVERSITY PRINT LAYOUT ═════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="printable-report" class="hidden print:block text-black bg-white">
    
    <!-- ── Document Header ── -->
    <div class="text-center border-b-2 border-black pb-3 mb-4">
        <h1 class="text-base font-black uppercase tracking-wider text-black">UNIVERSITY INTERNSHIP REPORT / LOGBOOK</h1>
        <p class="text-[9pt] text-gray-700 font-semibold mt-0.5 uppercase tracking-wide">Student Internship Training &amp; Evaluation Record</p>
    </div>

    <!-- ── 1. Student Summary & Overall Attendance ── -->
    <div id="print-doc-summary" class="mb-4">
        <div class="print-section-title">1. Student Information</div>
        <table class="w-full text-xs mb-3 border-collapse">
            <tbody>
                <tr>
                    <td class="font-bold py-1 w-1/4">Student Name:</td>
                    <td class="py-1 w-1/4"><?= htmlspecialchars($student_name) ?></td>
                    <td class="font-bold py-1 w-1/4">Roll Number:</td>
                    <td class="py-1 w-1/4"><?= htmlspecialchars($student_roll ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="font-bold py-1">Major / Department:</td>
                    <td class="py-1"><?= htmlspecialchars($major ?: '—') ?></td>
                    <td class="font-bold py-1">Academic Year:</td>
                    <td class="py-1"><?= htmlspecialchars($academic_year ?: '—') ?></td>
                </tr>
                <tr>
                    <td class="font-bold py-1">Host Company:</td>
                    <td class="py-1"><?= htmlspecialchars($company_name ?: '—') ?></td>
                    <td class="font-bold py-1">Job Role / Position:</td>
                    <td class="py-1"><?= htmlspecialchars($student['job_role'] ?? '—') ?></td>
                </tr>
                <tr>
                    <td class="font-bold py-1">University Supervisor:</td>
                    <td class="py-1"><?= htmlspecialchars($supervisor_name ?: '—') ?></td>
                    <td class="font-bold py-1">Company Instructor:</td>
                    <td class="py-1"><?= htmlspecialchars($student['contact_person'] ?? '—') ?></td>
                </tr>
                <tr>
                    <td class="font-bold py-1">Internship Period:</td>
                    <td class="py-1" colspan="3">
                        <?= $intern_start ? (new DateTime($intern_start))->format('d M Y') : '—' ?> to <?= $intern_end ? (new DateTime($intern_end))->format('d M Y') : '—' ?>
                        (<?= $total_weeks ?> Weeks)
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="print-section-title">2. Internship Summary &amp; Attendance</div>
        <table class="w-full text-xs mb-3 border-collapse">
            <tbody>
                <tr>
                    <td class="font-bold py-1 w-1/4">Total Recorded Days:</td>
                    <td class="py-1 w-1/4"><?= $all_recorded_days ?> days</td>
                    <td class="font-bold py-1 w-1/4">Overall Attendance Rate:</td>
                    <td class="py-1 w-1/4 font-bold"><?= $all_att_rate ?>%</td>
                </tr>
                <tr>
                    <td class="font-bold py-1">Days Present:</td>
                    <td class="py-1"><?= $total_present ?> days</td>
                    <td class="font-bold py-1">Days Absent / Leave:</td>
                    <td class="py-1"><?= $total_absent ?> days</td>
                </tr>
                <tr>
                    <td class="font-bold py-1">Total Hours Worked:</td>
                    <td class="py-1" colspan="3"><?= $total_hours ?>h <?= $total_mins ?>m</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ── 2. Daily Activity Logs ── -->
    <div id="print-doc-logs" class="mb-4">
        <div class="print-section-title">3. Daily Activity Logs</div>
        <?php if (!empty($all_logs)): ?>
        <table class="print-doc-table w-full text-xs border-collapse">
            <thead>
                <tr>
                    <th class="py-1 px-1.5 text-left font-bold w-[75px]">Date</th>
                    <th class="py-1 px-1.5 text-left font-bold w-[65px]">Attendance</th>
                    <th class="py-1 px-1.5 text-left font-bold w-[120px]">Intended Task</th>
                    <th class="py-1 px-1.5 text-left font-bold">Actual Tasks Performed</th>
                    <th class="py-1 px-1.5 text-left font-bold w-[95px]">Tools / Tech</th>
                    <th class="py-1 px-1.5 text-left font-bold w-[95px]">Skills Learned</th>
                    <th class="py-1 px-1.5 text-center font-bold w-[45px]">Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_logs as $log):
                    $st = strtolower($log['attendance_status'] ?? 'present');
                    $is_abs = in_array($st, ['absent', 'leave'], true);
                    $is_hol = ($st === 'leave') || stripos($log['reason_for_absence'] ?? '', 'Public Holiday') === 0;
                ?>
                <tr>
                    <td class="py-1 px-1.5 font-semibold whitespace-nowrap align-top">
                        <?= (new DateTime($log['log_date']))->format('d/m/Y') ?><br>
                        <span class="text-[8pt] text-gray-500 font-normal"><?= (new DateTime($log['log_date']))->format('D') ?></span>
                    </td>
                    <td class="py-1 px-1.5 whitespace-nowrap align-top font-medium">
                        <?= $is_hol ? 'Holiday' : ($is_abs ? 'Absent' : 'Present') ?>
                    </td>
                    <td class="py-1 px-1.5 align-top">
                        <?= $is_abs ? (!empty($log['reason_for_absence']) ? 'Reason: ' . htmlspecialchars($log['reason_for_absence']) : '—') : htmlspecialchars($log['task_title'] ?: '—') ?>
                    </td>
                    <td class="py-1 px-1.5 align-top">
                        <?= $is_abs ? '—' : nl2br(htmlspecialchars($log['tasks_performed'] ?: ($log['actual_tasks'] ?? '—'))) ?>
                    </td>
                    <td class="py-1 px-1.5 align-top text-[8pt]">
                        <?= ($is_abs || empty($log['tools_used'])) ? '—' : htmlspecialchars($log['tools_used']) ?>
                    </td>
                    <td class="py-1 px-1.5 align-top text-[8pt]">
                        <?= ($is_abs || empty($log['learnt_skills'])) ? '—' : nl2br(htmlspecialchars($log['learnt_skills'])) ?>
                    </td>
                    <td class="py-1 px-1.5 text-center font-mono align-top text-[8.5pt]">
                        <?= htmlspecialchars($log['calculated_duration'] ?: '00:00') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-xs italic text-slate-500 py-2">No daily log records available.</p>
        <?php endif; ?>
    </div>

    <!-- ── 3. Weekly Reflections ── -->
    <div id="print-doc-reflections" class="mb-4">
        <div class="print-section-title">4. Weekly Reflections</div>
        <?php if (!empty($weeks)): ?>
            <?php foreach ($weeks as $wn => $wr):
                $w_rep = $refs_by_week[$wn] ?? null;
                $has_ref = $w_rep && (!empty($w_rep['what_done']) || !empty($w_rep['how_done']) || !empty($w_rep['why_done']));
                $w_start_fmt = (new DateTime($wr['start']))->format('d M Y');
                $w_end_fmt   = (new DateTime($wr['end']))->format('d M Y');
            ?>
            <div class="print-card-box">
                <div class="font-bold text-xs uppercase border-b border-gray-300 pb-1 mb-1.5 text-black">
                    Week <?= $wn ?> (<?= $w_start_fmt ?> – <?= $w_end_fmt ?>)
                </div>
                <?php if ($has_ref): ?>
                <div class="space-y-1.5 text-xs">
                    <div>
                        <span class="font-bold text-black">What was done:</span>
                        <p class="mt-0.5 text-gray-900"><?= nl2br(htmlspecialchars($w_rep['what_done'] ?? '—')) ?></p>
                    </div>
                    <div>
                        <span class="font-bold text-black">How was it done:</span>
                        <p class="mt-0.5 text-gray-900"><?= nl2br(htmlspecialchars($w_rep['how_done'] ?? '—')) ?></p>
                    </div>
                    <div>
                        <span class="font-bold text-black">Why was it done:</span>
                        <p class="mt-0.5 text-gray-900"><?= nl2br(htmlspecialchars($w_rep['why_done'] ?? '—')) ?></p>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-xs italic text-gray-500">No weekly reflection submitted for Week <?= $wn ?>.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── 4. Signatures & Evaluations ── -->
    <div id="print-doc-evaluations" class="mb-4">
        <div class="print-section-title">5. Signatures &amp; Evaluations</div>
        <?php if (!empty($weeks)): ?>
            <?php foreach ($weeks as $wn => $wr):
                $w_rep = $refs_by_week[$wn] ?? null;
                $w_start_fmt = (new DateTime($wr['start']))->format('d M Y');
                $w_end_fmt   = (new DateTime($wr['end']))->format('d M Y');
            ?>
            <div class="print-card-box">
                <div class="font-bold text-xs uppercase border-b border-gray-300 pb-1 mb-2 text-black">
                    Week <?= $wn ?> (<?= $w_start_fmt ?> – <?= $w_end_fmt ?>)
                </div>
                <div class="grid grid-cols-3 gap-3 text-xs">
                    <!-- Student Signature -->
                    <div class="border-r border-gray-300 pr-2">
                        <p class="font-bold uppercase text-[9pt] text-gray-700">Student Signature</p>
                        <?php if (!empty($w_rep['student_signature_value'])): ?>
                            <?php if (($w_rep['student_signature_type'] ?? '') === 'typed'): ?>
                                <div class="text-sm font-bold italic font-serif my-1"><?= htmlspecialchars($w_rep['student_signature_value']) ?></div>
                            <?php else: ?>
                                <?php
                                $sig_src = $w_rep['student_signature_value'];
                                if (!str_starts_with($sig_src, 'data:') && !str_starts_with($sig_src, 'http') && !str_starts_with($sig_src, 'uploads/')) {
                                    $sig_src = 'uploads/signatures/' . $sig_src;
                                }
                                ?>
                                <img src="<?= htmlspecialchars($sig_src) ?>" alt="Signature" class="max-h-7 my-1 object-contain">
                            <?php endif; ?>
                            <p class="text-[8pt] text-gray-500">Date: <?= !empty($w_rep['student_signed_at']) ? (new DateTime($w_rep['student_signed_at']))->format('d M Y') : (!empty($w_rep['submitted_at']) ? (new DateTime($w_rep['submitted_at']))->format('d M Y') : '—') ?></p>
                        <?php else: ?>
                            <p class="text-gray-400 italic text-[8.5pt]">Not signed</p>
                        <?php endif; ?>
                    </div>

                    <!-- Instructor Feedback -->
                    <div class="border-r border-gray-300 pr-2">
                        <p class="font-bold uppercase text-[9pt] text-gray-700">Instructor Assessment</p>
                        <?php if (!empty($w_rep['instructor_grade']) || in_array($w_rep['status'] ?? '', ['approved_by_instructor', 'graded', 'approved_by_supervisor'], true)): ?>
                            <p class="font-semibold">Grade/Status: <?= htmlspecialchars(ucfirst($w_rep['instructor_grade'] ?? 'Approved')) ?></p>
                            <?php if (!empty($w_rep['instructor_comments'])): ?>
                                <p class="text-[8.5pt] text-gray-700 italic my-0.5">"<?= htmlspecialchars($w_rep['instructor_comments']) ?>"</p>
                            <?php endif; ?>
                            <?php if (!empty($w_rep['instructor_signature_value'])): ?>
                                <?php
                                $i_sig_src = $w_rep['instructor_signature_value'];
                                if (!str_starts_with($i_sig_src, 'data:') && !str_starts_with($i_sig_src, 'http') && !str_starts_with($i_sig_src, 'uploads/')) {
                                    $i_sig_src = 'uploads/signatures/' . $i_sig_src;
                                }
                                ?>
                                <img src="<?= htmlspecialchars($i_sig_src) ?>" alt="Instructor Signature" class="max-h-7 my-1 object-contain">
                            <?php endif; ?>
                            <p class="text-[8pt] text-gray-500">Date: <?= !empty($w_rep['instructor_signed_at']) ? (new DateTime($w_rep['instructor_signed_at']))->format('d M Y') : '—' ?></p>
                        <?php else: ?>
                            <p class="text-gray-400 italic text-[8.5pt]">Pending Review</p>
                        <?php endif; ?>
                    </div>

                    <!-- Supervisor Evaluation -->
                    <div>
                        <p class="font-bold uppercase text-[9pt] text-gray-700">Supervisor Evaluation</p>
                        <?php if (!empty($w_rep['supervisor_grade'])): ?>
                            <p class="font-bold">Grade: <?= htmlspecialchars($w_rep['supervisor_grade']) ?></p>
                            <?php if (!empty($w_rep['supervisor_comments'])): ?>
                                <p class="text-[8.5pt] text-gray-700 italic my-0.5">"<?= htmlspecialchars($w_rep['supervisor_comments']) ?>"</p>
                            <?php endif; ?>
                            <p class="text-[8pt] text-gray-500">Supervisor: <?= htmlspecialchars($w_rep['supervisor_name'] ?: ($supervisor_name ?: 'Supervisor')) ?></p>
                            <p class="text-[8pt] text-gray-500">Date: <?= !empty($w_rep['evaluated_at']) ? (new DateTime($w_rep['evaluated_at']))->format('d M Y') : (!empty($w_rep['submitted_at']) ? (new DateTime($w_rep['submitted_at']))->format('d M Y') : '—') ?></p>
                        <?php else: ?>
                            <p class="text-gray-400 italic text-[8.5pt]">Pending Evaluation</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Document Footer ── -->
    <div class="mt-6 pt-3 border-t border-black text-center text-[8pt] text-gray-600 flex justify-between">
        <span>Student: <?= htmlspecialchars($student_name) ?> (Roll: <?= htmlspecialchars($student_roll ?: '—') ?>)</span>
        <span>Official University Internship Logbook &amp; Report</span>
        <span>Date: <?= date('d M Y') ?></span>
    </div>
</div>

</body>
</html>
