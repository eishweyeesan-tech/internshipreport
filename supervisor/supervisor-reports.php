<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$db       = $mysqli ?? $conn;

// ── Notification redirect URL helper ────────────────────────────
require_once __DIR__ . '/../config/notify.php';

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

// ── Filters ─────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$allowed_statuses = ['pending', 'approved_by_instructor', 'approved_by_supervisor', 'rejected'];
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = '';

$filter_student_id = (int) ($_GET['student_id'] ?? $_GET['uid'] ?? 0);
$filter_week = isset($_GET['week']) && $_GET['week'] !== '' ? (int) $_GET['week'] : null;
$filter_company = trim($_GET['company'] ?? '');
$filter_year = trim($_GET['year'] ?? ($_GET['academic_year'] ?? ''));
$search = trim($_GET['search'] ?? '');

$page = (isset($_GET['page']) && (int) $_GET['page'] > 0) ? (int) $_GET['page'] : 1;
$per_page = 12;

$filtered_student_name = '';
if ($filter_student_id > 0) {
    $st_name_q = $db->prepare("SELECT COALESCE(sp.full_name, u.username) AS sname FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = ? AND sp.supervisor_id = ?");
    $st_name_q->bind_param("ii", $filter_student_id, $sup_id);
    $st_name_q->execute();
    $st_res = $st_name_q->get_result();
    if ($st_row = $st_res->fetch_assoc()) {
        $filtered_student_name = $st_row['sname'];
    }
}

// ── Summary counts (assigned students scope) ───────────────────
$pending_reviews_q = $db->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    WHERE re.report_status = 'approved_by_instructor'
      AND re.student_id IN (
          SELECT u.id FROM users u
          JOIN student_profiles sp ON sp.user_id = u.id
          WHERE u.role = 'student' AND sp.supervisor_id = ?
      )
      AND NOT EXISTS (
          SELECT 1 FROM supervisor_weekly_evaluations swe
          WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
      )
");
$pending_reviews_q->bind_param("i", $sup_id);
$pending_reviews_q->execute();
$res = $pending_reviews_q->get_result();
$row = $res ? $res->fetch_row() : null;
$pending_reviews = (int) ($row[0] ?? 0);

$total_reports_q = $db->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
");
$total_reports_q->bind_param("i", $sup_id);
$total_reports_q->execute();
$res = $total_reports_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_reports = (int) ($row[0] ?? 0);

require_once __DIR__ . '/../includes/academic_year_helper.php';
ensure_academic_years_table($db);

// ── Available filter options ────────────────────────────────────
$available_years = get_academic_years_list($db);
$active_year_label = get_active_academic_year_label($db);

$weeks_q = $db->prepare("
    SELECT DISTINCT re.week_number
    FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
    ORDER BY re.week_number DESC
");
$weeks_q->bind_param("i", $sup_id);
$weeks_q->execute();
$res = $weeks_q->get_result();
$available_weeks = [];
if ($res) {
    while ($row = $res->fetch_row()) {
        $available_weeks[] = $row[0];
    }
}

$companies_q = $db->prepare("
    SELECT DISTINCT sp.company_name
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
    ORDER BY sp.company_name ASC
");
$companies_q->bind_param("i", $sup_id);
$companies_q->execute();
$res = $companies_q->get_result();
$available_companies = [];
if ($res) {
    while ($row = $res->fetch_row()) {
        $available_companies[] = $row[0];
    }
}

// ── Main reports query ─────────────────────────────────────────
$base_sql = "
    SELECT re.*, u.id AS uid, u.username, u.academic_year,
           sp.full_name, sp.student_roll, sp.company_name,
           swe.weekly_grade
    FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN supervisor_weekly_evaluations swe
           ON swe.student_id = re.student_id AND swe.week_number = re.week_number
    WHERE u.role = 'student' AND sp.supervisor_id = ?
";
$where = '';
$types = 'i';
$params = [$sup_id];

if ($filter_student_id > 0) {
    $where .= " AND re.student_id = ?";
    $types .= "i";
    $params[] = $filter_student_id;
}
if ($filter_status) {
    $where .= " AND re.report_status = ?";
    $types .= "s";
    $params[] = $filter_status;
}
if ($filter_week) {
    $where .= " AND re.week_number = ?";
    $types .= "i";
    $params[] = $filter_week;
}
if ($filter_company) {
    $where .= " AND sp.company_name = ?";
    $types .= "s";
    $params[] = $filter_company;
}
if ($filter_year && $filter_year !== 'all') {
    $where .= " AND u.academic_year = ?";
    $types .= "s";
    $params[] = $filter_year;
}
if ($search) {
    $where .= " AND (sp.full_name LIKE ? OR u.username LIKE ? OR sp.student_roll LIKE ? OR sp.company_name LIKE ?)";
    $like = '%' . $search . '%';
    $types .= "ssss";
    array_push($params, $like, $like, $like, $like);
}

$count_sql = "SELECT COUNT(*) FROM report_evaluations re
              JOIN users u ON u.id = re.student_id
              JOIN student_profiles sp ON sp.user_id = u.id
              WHERE u.role = 'student' AND sp.supervisor_id = ?" . $where;
$count_stmt = $db->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$res = $count_stmt->get_result();
$row = $res ? $res->fetch_row() : null;
$total_filtered = (int) ($row[0] ?? 0);
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = $base_sql . $where . " ORDER BY re.evaluated_at DESC LIMIT ? OFFSET ?";
$fetch_types = $types . "ii";
$fetch_params = array_merge($params, [$per_page, $offset]);

$reports_stmt = $db->prepare($sql);
$reports_stmt->bind_param($fetch_types, ...$fetch_params);
$reports_stmt->execute();
$res = $reports_stmt->get_result();
$reports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

function build_query_url($overrides = []) {
    $q = array_merge($_GET, $overrides);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    unset($q['page']);
    return $q;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Reports – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        .scroll-margin { scroll-margin-top: 88px; }
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
        <?php $pageTitle = 'University Supervisor Reports'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ════ REPORTS CONTENT ════ -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">


                <!-- ═══ PAGE HEADER ═══ -->
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 tracking-tight">📄 Reports</h2>
                        <p class="text-sm text-slate-400 mt-1 font-medium">Weekly reports submitted by your assigned students</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600">📄 <?= $total_reports ?> total</span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 border border-blue-200 rounded-xl text-xs font-bold text-blue-700">🔵 <?= $pending_reviews ?> ready for review</span>
                    </div>
                </div>

                <!-- ═══ FILTERS ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <a href="?<?= http_build_query(build_query_url(['status' => '', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === '' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">All</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'approved_by_instructor', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'approved_by_instructor' ? 'bg-blue-600 text-white border-blue-600 shadow-xs' : 'bg-white text-blue-700 border-blue-200 hover:bg-blue-50' ?>">🔵 Ready for Review</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'approved_by_supervisor', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'approved_by_supervisor' ? 'bg-emerald-600 text-white border-emerald-600 shadow-xs' : 'bg-white text-emerald-700 border-emerald-200 hover:bg-emerald-50' ?>">✅ Graded</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'rejected', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'rejected' ? 'bg-red-600 text-white border-red-600 shadow-xs' : 'bg-white text-red-700 border-red-200 hover:bg-red-50' ?>">✕ Rejected</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'pending', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'pending' ? 'bg-slate-700 text-white border-slate-700 shadow-xs' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">⏳ Waiting for Instructor</a>
                        </div>

                        <div class="flex-1"></div>

                        <form method="GET" class="flex items-center gap-2 flex-wrap">
                            <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                            <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>

                            <?php if (!empty($available_weeks)): ?>
                            <select name="week" onchange="this.form.submit()" class="bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl px-3 py-2 text-xs text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200 cursor-pointer">
                                <option value="">All weeks</option>
                                <?php foreach ($available_weeks as $wk): ?>
                                <option value="<?= (int)$wk ?>" <?= $filter_week === (int)$wk ? 'selected' : '' ?>>Week <?= (int)$wk ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <?php if (!empty($available_companies)): ?>
                            <select name="company" onchange="this.form.submit()" class="bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl px-3 py-2 text-xs text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200 cursor-pointer max-w-[13rem]">
                                <option value="">All companies</option>
                                <?php foreach ($available_companies as $comp): ?>
                                <option value="<?= htmlspecialchars($comp) ?>" <?= $filter_company === $comp ? 'selected' : '' ?>><?= htmlspecialchars($comp) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <!-- Academic Year Filter -->
                            <select name="year" onchange="this.form.submit()" class="bg-white border border-teal-200 text-slate-700 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 cursor-pointer max-w-[14rem]">
                                <?= render_academic_year_options($db, $filter_year, true, 'All Academic Years') ?>
                            </select>

                            <?php if ($filter_status || $filter_week || $filter_company || ($filter_year && $filter_year !== 'all') || $search || $filter_student_id): ?>
                            <a href="supervisor-reports.php" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition-all duration-200">✕ Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <?php if ($filter_student_id > 0 && !empty($filtered_student_name)): ?>
                <!-- Student Filter Indicator Banner -->
                <div class="mb-4 p-3.5 bg-gradient-to-r from-teal-50 to-blue-50 border border-teal-200 rounded-xl flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2.5">
                        <span class="w-6 h-6 rounded-md bg-teal-600 text-white flex items-center justify-center text-xs font-bold">🎓</span>
                        <div>
                            <span class="text-xs font-bold text-teal-800">Showing reports for:</span>
                            <span class="text-xs font-black text-slate-800 ml-1"><?= htmlspecialchars($filtered_student_name) ?></span>
                        </div>
                    </div>
                    <a href="?<?= http_build_query(build_query_url(['student_id' => null, 'uid' => null])) ?>" class="text-xs font-bold text-teal-700 hover:text-teal-900 bg-white/80 hover:bg-white border border-teal-200 px-2.5 py-1 rounded-lg transition shadow-xs">
                        ✕ Show All Students
                    </a>
                </div>
                <?php endif; ?>

                <!-- ═══ REPORTS TABLE ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <?php if (!empty($reports)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gradient-to-r from-slate-50 to-white border-b border-slate-100">
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Week</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Submitted</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($reports as $rep):
                                    $rep_student = $rep['full_name'] ?: $rep['username'];
                                    $rep_status  = $rep['report_status'];
                                ?>
                                <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-md shadow-purple-500/20">
                                                <?= strtoupper(substr($rep_student, 0, 1)) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <a href="view-student-dashboard.php?id=<?= (int)$rep['uid'] ?>" class="text-sm font-bold text-slate-800 hover:text-purple-600 transition-colors duration-150 truncate block"><?= htmlspecialchars($rep_student) ?></a>
                                                <p class="text-xs text-slate-400 font-medium"><?= htmlspecialchars($rep['student_roll'] ?: $rep['username']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="text-sm font-medium text-blue-600 truncate block max-w-[14rem]"><?= htmlspecialchars($rep['company_name'] ?: '—') ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700">
                                            Week <?= (int)$rep['week_number'] ?>
                                            <?php if (!empty($rep['weekly_grade'])): ?>
                                             <span class="text-xs font-black text-emerald-600 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-lg"><?= htmlspecialchars($rep['weekly_grade']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($rep_status === 'approved_by_instructor'): ?>
                                        <div class="inline-flex flex-col items-start gap-1">
                                            <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-lg whitespace-nowrap">
                                                ✅ Instructor Approved
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-md whitespace-nowrap">
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                                                Ready for Review
                                            </span>
                                        </div>
                                        <?php elseif ($rep_status === 'approved_by_supervisor'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg whitespace-nowrap">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            ✅ Supervisor Approved
                                        </span>
                                        <?php elseif ($rep_status === 'rejected'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-1 rounded-lg whitespace-nowrap">
                                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                            ❌ Rejected
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 bg-slate-100 border border-slate-200 px-2.5 py-1 rounded-lg whitespace-nowrap">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                            ⏳ Waiting for Instructor
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="text-xs text-slate-500 font-medium"><?= htmlspecialchars((new DateTime($rep['evaluated_at']))->format('d M Y, h:i A')) ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="inline-flex items-center justify-end gap-1.5 flex-nowrap">
                                            <?php if ($rep_status === 'approved_by_instructor'): ?>
                                            <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-gradient-to-r from-teal-600 to-[#005f73] hover:from-teal-700 hover:to-[#004e5f] text-white text-xs font-bold rounded-lg transition-all duration-200 shadow-xs whitespace-nowrap">
                                                📝 Review & Grade
                                            </a>
                                            <?php else: ?>
                                            <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-all duration-200 whitespace-nowrap">
                                                👁️ View
                                            </a>
                                            <?php endif; ?>
                                            <a href="../view_student_history.php?uid=<?= (int)$rep['student_id'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-lg transition-all duration-200 whitespace-nowrap" title="View 13-week report history">
                                                📜 History
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>


                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between flex-wrap gap-3">
                        <p class="text-xs text-slate-400 font-medium">Showing <?= $total_filtered > 0 ? ($offset + 1) : 0 ?>–<?= min($offset + $per_page, $total_filtered) ?> of <?= $total_filtered ?> reports</p>
                        <div class="flex items-center gap-1.5">
                            <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(build_query_url(['page' => $page - 1])) ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-all duration-200">← Prev</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?<?= http_build_query(build_query_url(['page' => $i])) ?>" class="w-8 h-8 flex items-center justify-center text-xs font-bold rounded-lg transition-all duration-200 <?= $i === $page ? 'bg-slate-800 text-white' : 'text-slate-600 bg-slate-100 hover:bg-slate-200' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(build_query_url(['page' => $page + 1])) ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-all duration-200">Next →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="p-16 text-center">
                        <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">📭</div>
                        <p class="text-base font-bold text-slate-500">No reports found</p>
                        <p class="text-sm text-slate-400 mt-1.5"><?= $search || $filter_status || $filter_week || $filter_company ? 'Try adjusting your filters or search terms.' : 'No weekly reports have been submitted by your students yet.' ?></p>
                        <?php if ($search || $filter_status || $filter_week || $filter_company): ?>
                        <a href="supervisor-reports.php" class="mt-5 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear all filters</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
