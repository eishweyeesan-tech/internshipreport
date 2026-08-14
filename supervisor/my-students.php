<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

require_once __DIR__ . '/../config/notify.php';

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($pdo, $sup_id);

$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

// ── Academic year filter (from session, same as dashboard) ──────
$ay_filter = get_ay_filter($pdo, 'u');

// ── Filters ─────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
if (!in_array($filter_status, ['red', 'amber', 'green', 'none'], true)) $filter_status = '';
$search = trim($_GET['search'] ?? '');

// ── Summary counts (assigned students scope) ───────────────────
$pending_reviews_q = $pdo->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    WHERE re.report_status = 'approved_by_instructor'
      AND re.student_id IN (
          SELECT u.id FROM users u
          JOIN student_profiles sp ON sp.user_id = u.id
          WHERE u.role = 'student' AND sp.supervisor_id = ?" . $ay_filter['sql'] . "
      )
      AND NOT EXISTS (
          SELECT 1 FROM supervisor_weekly_evaluations swe
          WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
      )
");
$pending_reviews_q->execute(array_merge([$sup_id], $ay_filter['params']));
$pending_reviews = (int) $pending_reviews_q->fetchColumn();

$total_assigned_q = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql']);
$total_assigned_q->execute(array_merge([$sup_id], $ay_filter['params']));
$total_assigned = (int) $total_assigned_q->fetchColumn();

// ── Students detail (assigned + active) ─────────────────────────
$sql = "
    SELECT u.id AS uid, u.username, u.email, u.academic_year, u.profile_pic,
           sp.full_name, sp.student_roll, sp.major, sp.phone,
           sp.company_name, sp.job_role,
           sp.instructor_name, sp.instructor_email, sp.instructor_phone,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql'] . "
";
$params = array_merge([$sup_id], $ay_filter['params']);

if ($search) {
    $sql .= " AND (sp.full_name LIKE ? OR u.username LIKE ? OR sp.student_roll LIKE ? OR sp.company_name LIKE ? OR sp.job_role LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY sp.full_name ASC";
$students_stmt = $pdo->prepare($sql);
$students_stmt->execute($params);
$students = $students_stmt->fetchAll();

// ── Attendance per student ──────────────────────────────────────
$attendance = [];
if (!empty($students)) {
    $ids = array_map(function ($s) { return (int) $s['uid']; }, $students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_q = $pdo->prepare("
        SELECT dl.internship_id,
               SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
               COUNT(*) AS total_count
        FROM daily_logs dl
        WHERE dl.internship_id IN ($in_placeholders)
        GROUP BY dl.internship_id
    ");
    $att_q->execute($ids);
    foreach ($att_q->fetchAll() as $row) {
        $attendance[(int) $row['internship_id']] = $row;
    }
}

// ── Reports + graded weeks per student ──────────────────────────
$report_counts = [];
$graded_counts = [];
if (!empty($students)) {
    $ids = array_map(function ($s) { return (int) $s['uid']; }, $students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rc_q = $pdo->prepare("SELECT student_id, COUNT(*) AS cnt FROM report_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $rc_q->execute($ids);
    foreach ($rc_q->fetchAll() as $row) {
        $report_counts[(int) $row['student_id']] = (int) $row['cnt'];
    }
    $gc_q = $pdo->prepare("SELECT student_id, COUNT(*) AS cnt FROM supervisor_weekly_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $gc_q->execute($ids);
    foreach ($gc_q->fetchAll() as $row) {
        $graded_counts[(int) $row['student_id']] = (int) $row['cnt'];
    }
}

// ── Students with a report awaiting supervisor review ───────────
$student_pending = [];
if (!empty($students)) {
    $ids = array_map(function ($s) { return (int) $s['uid']; }, $students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pq = $pdo->prepare("
        SELECT re.student_id, re.week_number FROM report_evaluations re
        WHERE re.report_status = 'approved_by_instructor'
          AND re.student_id IN ($in_placeholders)
          AND NOT EXISTS (
              SELECT 1 FROM supervisor_weekly_evaluations swe
              WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
          )
    ");
    $pq->execute($ids);
    foreach ($pq->fetchAll() as $row) {
        $student_pending[(int) $row['student_id']] = (int) $row['week_number'];
    }
}

// ── Dynamic week + progress status (consistent with dashboard) ──
$today_obj = new DateTime();
$dayOfWeek = (int) $today_obj->format('N');

$student_dynamic_week = [];
$student_not_started = [];
$student_progress = [];
foreach ($students as $sd) {
    $uid = $sd['uid'];
    $dynamic_week = 1;
    $not_started = false;

    if ($sd['internship_start_date']) {
        $start_date = $sd['internship_start_date'];
        $end_date   = $sd['internship_end_date'] ?: null;
        $dynamic_week = internship_current_week($start_date, $end_date, $today_obj);

        if ($today_obj < new DateTime($start_date)) {
            $not_started = true;
        }
    } else {
        $not_started = true;
    }

    $student_dynamic_week[$uid]  = $dynamic_week;
    $student_not_started[$uid]   = $not_started;
    $student_progress[$uid]      = internship_progress($pdo, $uid, $sd['internship_start_date'], $sd['internship_end_date']);
}

$progress_status = [];
$report_status_cache = [];
foreach ($students as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rs_q = $pdo->prepare("SELECT report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $rs_q->execute([$uid, $dw]);
    $report_status_cache[$uid] = $rs_q->fetchColumn() ?: 'pending';
}

foreach ($students as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rstatus = $report_status_cache[$uid] ?? 'pending';
    $not_started = $student_not_started[$uid] ?? false;

    if ($not_started) {
        $progress_status[$uid] = 'none';
        continue;
    }
    if ($rstatus === 'approved_by_supervisor') {
        $progress_status[$uid] = 'green';
        continue;
    }
    if ($sd['internship_start_date']) {
        $stu_start = new DateTime($sd['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        $sws = (new DateTime('monday this week'))->format('Y-m-d');
        $swe = (new DateTime('sunday this week'))->format('Y-m-d');
    }

    $log_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $log_q->execute([$uid, $sws, $swe]);
    $log_count = (int) $log_q->fetchColumn();

    if ($dayOfWeek >= 3 && $log_count === 0) {
        $progress_status[$uid] = 'red';
    } elseif ($log_count >= 1 && $log_count <= 4) {
        $progress_status[$uid] = 'amber';
    } elseif ($log_count >= 5) {
        $progress_status[$uid] = 'green';
    } else {
        $progress_status[$uid] = 'none';
    }
}

// ── Filter students by status ───────────────────────────────────
if ($filter_status) {
    $students = array_values(array_filter($students, function ($s) use ($filter_status, $progress_status) {
        return ($progress_status[$s['uid']] ?? 'none') === $filter_status;
    }));
}

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
    <title>My Students – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        .scroll-margin { scroll-margin-top: 88px; }
    </style>
    <script>
        tailwind.config = {
            darkMode: 'class',
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
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Header -->
        <?php $pageTitle = 'My Students'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ════ REPORTS CONTENT ════ -->
        <main class="flex-1 overflow-y-auto p-8">
    <div class="max-w-7xl mx-auto space-y-6">

        <!-- ═══ PAGE HEADER ═══ -->
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-2xl font-black text-slate-800 tracking-tight">🎓 My Students</h2>
                <p class="text-sm text-slate-400 mt-1 font-medium">Interns currently assigned to you, with live weekly progress</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600">👥 <?= $total_assigned ?> assigned</span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-xl text-xs font-bold text-amber-600">⏳ <?= $pending_reviews ?> awaiting review</span>
            </div>
        </div>

        <!-- ═══ STATUS FILTER CHIPS ═══ -->
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <a href="?<?= http_build_query(build_query_url(['status' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === '' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">All</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'red'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'red' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-red-600 border-red-200 hover:bg-red-50' ?>">🔴 Behind Schedule</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'amber'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'amber' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-amber-600 border-amber-200 hover:bg-amber-50' ?>">🟡 In Progress</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'green'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'green' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-emerald-600 border-emerald-200 hover:bg-emerald-50' ?>">🟢 Complete</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'none'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'none' ? 'bg-slate-600 text-white border-slate-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50' ?>">⚪ Not Started</a>
                </div>

                <div class="flex-1"></div>

                <?php if ($filter_status || $search): ?>
                <a href="my-students.php" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition-all duration-200">✕ Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($students)): ?>

        <!-- ═══ EMPTY STATE ═══ -->
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-16 text-center">
            <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">👥</div>
            <p class="text-base font-bold text-slate-500">No students found</p>
            <p class="text-sm text-slate-400 mt-1.5"><?= $search || $filter_status ? 'Try adjusting your search terms or filters.' : 'No interns are currently assigned to you.' ?></p>
            <?php if ($search || $filter_status): ?>
            <a href="my-students.php" class="mt-5 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear all filters</a>
            <?php endif; ?>
        </div>

        <?php else: ?>

        <!-- ═══ MY STUDENTS TABLE ═══ -->
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gradient-to-r from-slate-50 to-white border-b border-slate-100 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                            <th class="px-5 py-3.5 text-left">Student</th>
                            <th class="px-5 py-3.5 text-left">Company</th>
                            <th class="px-5 py-3.5 text-left">Role</th>
                            <th class="px-5 py-3.5 text-left">Progress</th>
                            <th class="px-5 py-3.5 text-left">Reports</th>
                            <th class="px-5 py-3.5 text-left">Status</th>
                            <th class="px-5 py-3.5 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($students as $s):
                            $uid = (int) $s['uid'];
                            $status = $progress_status[$uid] ?? 'none';
                            $label = progress_status_label($status);
                            $dot = progress_status_dot($status);
                            $not_started = $student_not_started[$uid] ?? false;
                            $att = $attendance[$uid] ?? null;
                            $att_pct = $att && $att['total_count'] > 0 ? (int) round(($att['present_count'] / $att['total_count']) * 100) : 0;
                            $r_count = $report_counts[$uid] ?? 0;
                            $g_count = $graded_counts[$uid] ?? 0;
                            $name = $s['full_name'] ?: $s['username'];
                            $pending_week = $student_pending[$uid] ?? null;
                        ?>
                        <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                            <td class="px-5 py-4">
                                <a href="view-student-dashboard.php?id=<?= $uid ?>" class="flex items-center gap-3 hover:opacity-80 transition">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-md shadow-indigo-500/20">
                                        <?= strtoupper(substr($name, 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-bold text-slate-800 hover:text-indigo-600 transition truncate"><?= htmlspecialchars($name) ?></p>
                                        <p class="text-xs text-slate-400 font-medium mt-0.5 truncate"><?= htmlspecialchars($s['student_roll'] ?: $s['username']) ?><?= !empty($s['academic_year']) ? ' • ' . htmlspecialchars($s['academic_year']) : '' ?></p>
                                        <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($s['email']) ?><?= !empty($s['phone']) ? ' • ' . htmlspecialchars($s['phone']) : '' ?></p>
                                    </div>
                                </a>
                            </td>
                            <td class="px-5 py-4 text-slate-600 font-medium max-w-[170px] truncate" title="<?= htmlspecialchars($s['company_name'] ?? '') ?>"><?= htmlspecialchars($s['company_name'] ?: '—') ?></td>
                            <td class="px-5 py-4 text-slate-600 font-medium text-xs max-w-[140px]">
                                <?= htmlspecialchars($s['job_role'] ?: '—') ?>
                                <?php if (!empty($s['major'])): ?>
                                <p class="text-slate-400 mt-0.5 truncate"><?= htmlspecialchars($s['major']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2 min-w-[110px]">
                                    <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                                        <div class="h-2 rounded-full bg-gradient-to-r <?= progress_bar_color($att_pct) ?> transition-all duration-500" style="width: <?= $att_pct ?>%"></div>
                                    </div>
                                    <span class="text-xs font-bold text-slate-600"><?= $att_pct ?>%</span>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1"><?= $not_started ? 'Not started' : 'Week ' . (int) ($student_progress[$uid]['completed'] ?? 0) . '/' . (int) ($student_progress[$uid]['total'] ?? 0) ?></p>
                                <p class="text-[11px] text-slate-400"><?= $s['internship_start_date'] ? (new DateTime($s['internship_start_date']))->format('d M Y') . ' – ' . ($s['internship_end_date'] ? (new DateTime($s['internship_end_date']))->format('d M Y') : '…') : '—' ?></p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg">📄 <?= $r_count ?></span>
                                    <span class="text-[11px] text-emerald-600 font-bold" title="Graded weeks">✓ <?= $g_count ?></span>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold <?= $label[1] ?> px-2.5 py-1 rounded-lg border whitespace-nowrap">
                                    <span class="w-2 h-2 rounded-full <?= $dot ?>"></span>
                                    <?= $label[0] ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <a href="view-student-dashboard.php?id=<?= $uid ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-indigo-500 to-purple-600 text-white text-xs font-bold rounded-lg hover:from-indigo-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-indigo-500/20">👁️ View</a>
                                    <?php if ($pending_week): ?>
                                    <a href="supervisor-review.php?student_id=<?= $uid ?>&week=<?= (int)$pending_week ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold rounded-lg transition-all duration-200 shadow-sm" title="Report awaiting your grade">📩 Grade</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>

    </div>
</main>

    </div>
</div>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
