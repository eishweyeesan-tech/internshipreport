<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../includes/academic_year_helper.php';

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'] ?? 'Supervisor';
$db       = $mysqli ?? $conn;

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($db, $sup_id);

// ── Handle Supervisor Update Student Placement & Instructor ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student_placement'])) {
    $target_uid       = (int) ($_POST['student_id'] ?? 0);
    $company_name     = trim($_POST['company_name'] ?? '');
    $job_role         = trim($_POST['job_role'] ?? '');
    $instructor_name  = trim($_POST['instructor_name'] ?? '');
    $instructor_email = trim($_POST['instructor_email'] ?? '');
    $instructor_phone = trim($_POST['instructor_phone'] ?? '');
    $start_date       = !empty($_POST['internship_start_date']) ? $_POST['internship_start_date'] : null;
    $end_date         = !empty($_POST['internship_end_date']) ? $_POST['internship_end_date'] : null;

    if ($target_uid > 0) {
        $chk = $db->prepare("SELECT user_id FROM student_profiles WHERE user_id = ? AND supervisor_id = ?");
        $chk->bind_param("ii", $target_uid, $sup_id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $upd = $db->prepare("UPDATE student_profiles SET
                company_name = ?, job_role = ?,
                instructor_name = ?, instructor_email = ?, instructor_phone = ?,
                internship_start_date = ?, internship_end_date = ?
                WHERE user_id = ?");
            $upd->bind_param("sssssssi", $company_name, $job_role, $instructor_name, $instructor_email, $instructor_phone, $start_date, $end_date, $target_uid);
            $upd->execute();
        }
    }
    header('Location: my-students.php?placement_saved=1');
    exit;
}

// ── Handle Single "Send Warning" for Behind Schedule Student ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_warning'])) {
    $warn_student_id = (int) ($_POST['student_id'] ?? 0);
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($warn_student_id > 0) {
        $chk = $db->prepare("
            SELECT u.id, u.username, u.academic_year, sp.full_name, sp.student_roll, sp.company_name
            FROM users u
            JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.id = ? AND sp.supervisor_id = ? AND u.role = 'student'
        ");
        $chk->bind_param("ii", $warn_student_id, $sup_id);
        $chk->execute();
        $res = $chk->get_result();
        $target_student = $res ? $res->fetch_assoc() : null;

        if ($target_student) {
            $upd_w = $db->prepare("UPDATE users SET is_warned = 1 WHERE id = ? AND role = 'student'");
            $upd_w->bind_param("i", $warn_student_id);
            $upd_w->execute();

            $sup_display = format_supervisor_name($sup_name);
            $notif_title = 'Supervisor Warning: Behind Schedule';
            $notif_msg = 'Your supervisor (' . $sup_display . ') noticed you are behind schedule with your daily logs/reports. Please update and submit your logs promptly.';

            notify_user_once(
                $db,
                $warn_student_id,
                $notif_title,
                $notif_msg,
                'student_behind_schedule',
                null,
                $warn_student_id,
                null,
                true
            );

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'Warning sent successfully.',
                    'student_id' => $warn_student_id
                ]);
                exit;
            }

            header('Location: my-students.php?warned=1');
            exit;
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Student not found or unauthorized.']);
        exit;
    }
}

// ── Unread notifications & recent list ──────────────────────────
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

// ── URL Initial Filter State ────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
if ($filter_status === 'behind') $filter_status = 'red';
if ($filter_status === 'completed') $filter_status = 'green';
if ($filter_status === 'in_progress') $filter_status = 'amber';
if (!in_array($filter_status, ['red', 'amber', 'green', 'none'], true)) $filter_status = '';
$search = trim($_GET['search'] ?? '');
$filter_year = trim($_GET['year'] ?? ($_GET['academic_year'] ?? 'all'));
if (empty($filter_year)) $filter_year = 'all';

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

$total_assigned_q = $db->prepare("
    SELECT COUNT(*) FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
");
$total_assigned_q->bind_param("i", $sup_id);
$total_assigned_q->execute();
$res = $total_assigned_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);

// ── Fetch ALL Assigned Students for this Supervisor ─────────────
$sql = "
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
$students_stmt = $db->prepare($sql);
$students_stmt->bind_param("i", $sup_id);
$students_stmt->execute();
$res = $students_stmt->get_result();
$all_students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ── Attendance per student ──────────────────────────────────────
$attendance = [];
if (!empty($all_students)) {
    $ids = array_map(function ($s) {
        return (int) $s['uid'];
    }, $all_students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_types = str_repeat("i", count($ids));
    $att_q = $db->prepare("
        SELECT dl.internship_id,
               SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
               SUM(CASE WHEN dl.attendance_status IN ('leave','absent') THEN 1 ELSE 0 END) AS absent_count,
               COUNT(*) AS total_count
        FROM daily_logs dl
        WHERE dl.internship_id IN ($in_placeholders)
        GROUP BY dl.internship_id
    ");
    $att_q->bind_param($att_types, ...$ids);
    $att_q->execute();
    $res = $att_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $attendance[(int) $row['internship_id']] = [
                'present_count' => (int) $row['present_count'],
                'absent_count'  => (int) $row['absent_count'],
                'total_count'   => (int) $row['total_count'],
            ];
        }
    }
}

// ── Reports + graded weeks per student ──────────────────────────
$report_counts = [];
$graded_counts = [];
if (!empty($all_students)) {
    $ids = array_map(function ($s) {
        return (int) $s['uid'];
    }, $all_students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rg_types = str_repeat("i", count($ids));

    $rc_q = $db->prepare("SELECT student_id, COUNT(*) AS cnt FROM report_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $rc_q->bind_param($rg_types, ...$ids);
    $rc_q->execute();
    $res = $rc_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $report_counts[(int) $row['student_id']] = (int) $row['cnt'];
        }
    }

    $gc_q = $db->prepare("SELECT student_id, COUNT(*) AS cnt FROM supervisor_weekly_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $gc_q->bind_param($rg_types, ...$ids);
    $gc_q->execute();
    $res = $gc_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $graded_counts[(int) $row['student_id']] = (int) $row['cnt'];
        }
    }
}

// ── Students with a report awaiting supervisor review ───────────
$student_pending = [];
if (!empty($all_students)) {
    $ids = array_map(function ($s) {
        return (int) $s['uid'];
    }, $all_students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $p_types = str_repeat("i", count($ids));
    $pq = $db->prepare("
        SELECT re.student_id, re.week_number FROM report_evaluations re
        WHERE re.report_status = 'approved_by_instructor'
          AND re.student_id IN ($in_placeholders)
          AND NOT EXISTS (
              SELECT 1 FROM supervisor_weekly_evaluations swe
              WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
          )
    ");
    $pq->bind_param($p_types, ...$ids);
    $pq->execute();
    $res = $pq->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $student_pending[(int) $row['student_id']] = (int) $row['week_number'];
        }
    }
}

// ── Dynamic week + progress status calculation ──────────────────
$today_obj = new DateTime();
$dayOfWeek = (int) $today_obj->format('N');

$student_dynamic_week = [];
$student_not_started = [];
$student_progress = [];
foreach ($all_students as $sd) {
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
    $student_progress[$uid]      = internship_progress($db, $uid, $sd['internship_start_date'], $sd['internship_end_date']);
}

$progress_status = [];
$report_status_cache = [];
$rs_q = $db->prepare("SELECT report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
foreach ($all_students as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rs_q->bind_param("ii", $uid, $dw);
    $rs_q->execute();
    $res = $rs_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $report_status_cache[$uid] = $row[0] ?? 'pending';
}

$log_q = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
foreach ($all_students as $sd) {
    $uid = (int) $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $not_started = $student_not_started[$uid] ?? false;
    $prog = $student_progress[$uid] ?? ['total' => 12, 'completed' => 0, 'pct' => 0];
    $completed_weeks = (int) ($prog['completed'] ?? 0);
    $total_w = (int) ($prog['total'] ?? 12);
    $has_logs = isset($attendance[$uid]['total_count']) && $attendance[$uid]['total_count'] > 0;

    // 1. Truly Not Started: 0 completed weeks, 0 logs, and not started or start date in the future
    if ($completed_weeks === 0 && !$has_logs && $not_started) {
        $progress_status[$uid] = 'none';
        continue;
    }

    // 2. Complete: Finished all required internship weeks (e.g. 12/12 weeks)
    if ($completed_weeks >= $total_w && $total_w > 0) {
        $progress_status[$uid] = 'green';
        continue;
    }

    // 3. Behind Schedule check:
    $is_ended = $sd['internship_end_date'] && ($today_obj > new DateTime($sd['internship_end_date']));
    $is_behind_weeks = ($dw > 2 && $completed_weeks < ($dw - 1));

    $log_count = 0;
    if ($sd['internship_start_date'] && !$is_ended) {
        $stu_start = new DateTime($sd['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
        $log_q->bind_param("iss", $uid, $sws, $swe);
        $log_q->execute();
        $res = $log_q->get_result();
        $row = $res ? $res->fetch_row() : null;
        $log_count = (int) ($row[0] ?? 0);
    }

    if ($is_ended && $completed_weeks < $total_w) {
        $progress_status[$uid] = $is_behind_weeks ? 'red' : 'amber';
    } elseif ($is_behind_weeks || ($dayOfWeek >= 3 && $log_count === 0 && !$is_ended)) {
        $progress_status[$uid] = 'red';
    } elseif ($completed_weeks > 0 || $has_logs || $log_count > 0) {
        $progress_status[$uid] = 'amber';
    } else {
        $progress_status[$uid] = 'none';
    }
}

// Natural Numeric Sorting: Academic Year DESC, Roll Number Natural ASC, Full Name ASC
usort($all_students, function ($a, $b) {
    $ay_a = (string)($a['academic_year'] ?? '');
    $ay_b = (string)($b['academic_year'] ?? '');
    $ay_cmp = strcasecmp($ay_b, $ay_a);
    if ($ay_cmp !== 0) {
        return $ay_cmp;
    }

    $roll_a = (string)($a['student_roll'] ?: $a['username']);
    $roll_b = (string)($b['student_roll'] ?: $b['username']);
    $roll_cmp = strnatcasecmp($roll_a, $roll_b);
    if ($roll_cmp !== 0) {
        return $roll_cmp;
    }

    $name_a = (string)($a['full_name'] ?: $a['username']);
    $name_b = (string)($b['full_name'] ?: $b['username']);
    return strnatcasecmp($name_a, $name_b);
});

// Count status breakdown & averages
$status_counts = ['all' => count($all_students), 'red' => 0, 'amber' => 0, 'green' => 0, 'none' => 0];
$total_att_pct_sum = 0;
$students_with_att = 0;
$total_prog_pct_sum = 0;

foreach ($all_students as $s) {
    $uid = (int) $s['uid'];
    $st = $progress_status[$uid] ?? 'none';
    if (isset($status_counts[$st])) {
        $status_counts[$st]++;
    }

    // Accurate internship progress percentage (completed reports vs total weeks)
    $p_pct = (int) ($student_progress[$uid]['pct'] ?? 0);
    $total_prog_pct_sum += $p_pct;

    // Attendance percentage based on expected working days
    $total_w = (int) ($student_progress[$uid]['total'] ?? 12);
    $exp_days = internship_expected_working_days($s['internship_start_date'] ?? null, $s['internship_end_date'] ?? null, $total_w);
    $pres_days = (int) ($attendance[$uid]['present_count'] ?? 0);
    $not_started = $student_not_started[$uid] ?? false;

    if (!$not_started && $exp_days > 0) {
        $pct = min(100, (int) round(($pres_days / $exp_days) * 100));
        $total_att_pct_sum += $pct;
        $students_with_att++;
    }
}

$average_progress_rate   = count($all_students) > 0 ? (int) round($total_prog_pct_sum / count($all_students)) : 0;
$average_attendance_rate = $students_with_att > 0 ? (int) round($total_att_pct_sum / $students_with_att) : 0;
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    <style>
        /* Hide scrollbar for Chrome, Safari, Edge, Firefox */
        html, body, main {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        main::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        #studentsTableViewContainer { min-height: 400px; }
        .status-chip { transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease; }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR ─── -->
        <?php $active_page = 'students';
        include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

        <!-- ─── MAIN ─── -->
        <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

            <!-- Top Header -->
            <?php $pageTitle = '🎓 My Assigned Students';
            include __DIR__ . '/includes/supervisor_topbar.php'; ?>

            <!-- ════ PAGE CONTENT ════ -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl w-full mx-auto space-y-6">

                    <!-- ═══ FLASH CONFIRMATION ALERT ═══ -->
                    <?php if (isset($_GET['warned'])): ?>
                        <div id="flashWarnedAlert" class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold px-4 py-3 rounded-2xl flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-7 h-7 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-xs font-bold">✓</span>
                                <span>Warning notification sent successfully to student.</span>
                            </div>
                            <button type="button" onclick="document.getElementById('flashWarnedAlert')?.remove()" class="text-emerald-500 hover:text-emerald-800 text-xs font-bold p-1 cursor-pointer">✕</button>
                        </div>
                    <?php endif; ?>

                    <!-- ═══ CONTROLS CARD: FILTERS, LIVE SEARCH & ACADEMIC YEAR ═══ -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 lg:p-5 space-y-4">
                        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">

                            <!-- Status Filter Chips -->
                            <div class="flex items-center gap-1.5 flex-wrap" id="statusChipsContainer">
                                <button type="button" data-status="" class="status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 <?= $filter_status === '' ? 'bg-slate-800 text-white border-slate-800 shadow-xs' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
                                    All <span class="ml-1 opacity-75">(<?= $status_counts['all'] ?>)</span>
                                </button>
                                <?php if ($pending_reviews > 0): ?>
                                <button type="button" data-status="pending" class="status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 <?= $filter_status === 'pending' ? 'bg-amber-600 text-white border-amber-600 shadow-xs' : 'bg-white text-amber-700 border-amber-200 hover:bg-amber-50' ?>">
                                    ⏳ Awaiting Review (<?= $pending_reviews ?>)
                                </button>
                                <?php endif; ?>
                                <button type="button" data-status="red" class="status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 <?= $filter_status === 'red' ? 'bg-red-500 text-white border-red-500 shadow-xs' : 'bg-white text-red-600 border-red-200 hover:bg-red-50' ?>">
                                    🔴 Behind Schedule <?= $status_counts['red'] > 0 ? '(' . $status_counts['red'] . ')' : '' ?>
                                </button>
                                <button type="button" data-status="amber" class="status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 <?= $filter_status === 'amber' ? 'bg-amber-500 text-white border-amber-500 shadow-xs' : 'bg-white text-amber-600 border-amber-200 hover:bg-amber-50' ?>">
                                    🟡 In Progress (<?= $status_counts['amber'] ?>)
                                </button>
                                <button type="button" data-status="green" class="status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 <?= $filter_status === 'green' ? 'bg-emerald-500 text-white border-emerald-500 shadow-xs' : 'bg-white text-emerald-600 border-emerald-200 hover:bg-emerald-50' ?>">
                                    🟢 Complete (<?= $status_counts['green'] ?>)
                                </button>
                                <button type="button" data-status="none" class="status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 <?= $filter_status === 'none' ? 'bg-slate-600 text-white border-slate-600 shadow-xs' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50' ?>">
                                    ⚪ Not Started (<?= $status_counts['none'] ?>)
                                </button>
                            </div>

                            <!-- Right Filters: Search, Academic Year -->
                            <div class="flex items-center gap-2.5 flex-wrap sm:flex-nowrap shrink-0">

                                <!-- Live Search Input -->
                                <div class="relative w-full sm:w-64">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400 text-xs">
                                        🔍
                                    </span>
                                    <input type="text"
                                        id="studentSearchInput"
                                        value="<?= htmlspecialchars($search) ?>"
                                        placeholder="Search roll, name, company…"
                                        class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-8 pr-8 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200"
                                        autocomplete="off">
                                    <button type="button"
                                        id="clearSearchBtn"
                                        class="<?= empty($search) ? 'hidden' : '' ?> absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-700 transition cursor-pointer font-bold text-xs">
                                        ✕
                                    </button>
                                </div>

                                <!-- Academic Year Select -->
                                <div class="relative min-w-[10.5rem]">
                                    <select id="academicYearSelect" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 text-slate-700 rounded-xl text-xs font-semibold px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 cursor-pointer">
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

                        <!-- Dynamic Count Row -->
                        <div class="pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500 flex-wrap gap-2">
                            <p id="dynamicResultCount" class="font-medium text-slate-600">
                                Showing <span class="font-bold text-slate-800" id="visibleCountNumber"><?= count($all_students) ?></span> students
                            </p>
                        </div>
                    </div>

                    <!-- ═══ STUDENTS TABLE VIEW ═══ -->
                    <div id="studentsTableViewContainer" class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden w-full">
                        <div class="overflow-x-auto w-full">
                            <table class="w-full text-left text-xs sm:text-sm min-w-[850px]" id="studentsTable">
                                <thead>
                                    <tr class="bg-slate-50/90 border-b border-slate-200 text-slate-500 font-semibold uppercase tracking-wider text-[11px]">
                                        <th class="px-4 py-3.5 text-left min-w-[200px]">Student</th>
                                        <th class="px-3 py-3.5 text-left whitespace-nowrap min-w-[90px]">Roll No.</th>
                                        <th class="px-3 py-3.5 text-left min-w-[200px]">Company & Instructor</th>
                                        <th class="px-3 py-3.5 text-left min-w-[130px]">Progress</th>
                                        <th class="px-3 py-3.5 text-center whitespace-nowrap min-w-[80px]">Reports</th>
                                        <th class="px-3 py-3.5 text-left whitespace-nowrap min-w-[120px]">Status</th>
                                        <th class="px-4 py-3.5 text-right whitespace-nowrap min-w-[150px]">Actions</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-100" id="studentsTableBody">
                                    <?php if (empty($all_students)): ?>
                                        <tr id="initialEmptyRow">
                                            <td colspan="7" class="p-16 text-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">👥</div>
                                                <p class="text-base font-bold text-slate-700">No students found</p>
                                                <p class="text-sm text-slate-400 mt-1">No interns are currently assigned to your supervision.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php
                                        $current_batch_year = null;
                                        foreach ($all_students as $s):
                                            $s_ay = $s['academic_year'] ?: 'Unassigned Year';
                                            $is_new_batch = ($s_ay !== $current_batch_year);
                                            if ($is_new_batch) {
                                                $current_batch_year = $s_ay;
                                            }
                                            $uid = (int) $s['uid'];
                                            $status = $progress_status[$uid] ?? 'none';
                                            $label = progress_status_label($status);
                                            $dot = progress_status_dot($status);
                                            $not_started = $student_not_started[$uid] ?? false;
                                            $completed_weeks = (int) ($student_progress[$uid]['completed'] ?? 0);
                                            $total_weeks     = (int) ($student_progress[$uid]['total'] ?? 0);
                                            $progress_pct    = (int) ($student_progress[$uid]['pct'] ?? 0);
                                            $current_week    = (int) ($student_dynamic_week[$uid] ?? 1);

                                            $att = $attendance[$uid] ?? null;
                                            $pres_days = (int) ($att['present_count'] ?? 0);
                                            $exp_days = internship_expected_working_days($s['internship_start_date'] ?? null, $s['internship_end_date'] ?? null, $total_weeks);
                                            $att_pct = (!$not_started && $exp_days > 0) ? min(100, (int) round(($pres_days / $exp_days) * 100)) : 0;

                                            $r_count = $report_counts[$uid] ?? 0;
                                            $g_count = $graded_counts[$uid] ?? 0;
                                            $name = $s['full_name'] ?: $s['username'];
                                            $roll = $s['student_roll'] ?: $s['username'];
                                            $ay   = $s['academic_year'] ?: '—';
                                            $is_warned = (bool) ($s['is_warned'] ?? 0);
                                            $pending_week = $student_pending[$uid] ?? null;
                                        ?>
                                            <?php if ($is_new_batch): ?>
                                                <tr class="academic-year-header-row bg-slate-100/90 border-y border-slate-200" data-group-ay="<?= htmlspecialchars($s_ay) ?>">
                                                    <td colspan="7" class="px-4 py-2.5 text-xs font-bold text-slate-700">
                                                        <div class="flex items-center gap-2">
                                                            <span class="p-1 bg-indigo-100 text-indigo-700 rounded text-xs leading-none">🎓</span>
                                                            <span class="text-slate-500 uppercase tracking-wider text-[11px] font-bold">Academic Batch:</span>
                                                            <span class="font-mono font-bold text-indigo-700 bg-white px-2.5 py-0.5 rounded-md border border-indigo-200 shadow-xs"><?= htmlspecialchars($s_ay) ?></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr class="student-item student-row hover:bg-slate-50/70 transition-colors duration-150"
                                                data-uid="<?= $uid ?>"
                                                data-name="<?= htmlspecialchars(strtolower($name), ENT_QUOTES, 'UTF-8') ?>"
                                                data-raw-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                                data-roll="<?= htmlspecialchars(strtolower($roll), ENT_QUOTES, 'UTF-8') ?>"
                                                data-raw-roll="<?= htmlspecialchars($roll, ENT_QUOTES, 'UTF-8') ?>"
                                                data-company="<?= htmlspecialchars(strtolower($s['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-raw-company="<?= htmlspecialchars($s['company_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>"
                                                data-instructor="<?= htmlspecialchars(strtolower($s['instructor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-raw-instructor="<?= htmlspecialchars($s['instructor_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>"
                                                data-instructor-email="<?= htmlspecialchars($s['instructor_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-instructor-phone="<?= htmlspecialchars($s['instructor_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-major="<?= htmlspecialchars(strtolower($s['major'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-raw-major="<?= htmlspecialchars($s['major'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-job-role="<?= htmlspecialchars($s['job_role'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-phone="<?= htmlspecialchars($s['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-email="<?= htmlspecialchars(strtolower($s['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-academic-year="<?= htmlspecialchars($s['academic_year'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-start-date="<?= htmlspecialchars($s['internship_start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-end-date="<?= htmlspecialchars($s['internship_end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-status="<?= $status ?>"
                                                data-progress-pct="<?= $progress_pct ?>"
                                                data-attendance-pct="<?= $att_pct ?>"
                                                data-present-days="<?= $pres_days ?>"
                                                data-expected-days="<?= $exp_days ?>"
                                                data-completed-weeks="<?= $completed_weeks ?>"
                                                data-total-weeks="<?= $total_weeks ?>"
                                                data-current-week="<?= $current_week ?>"
                                                data-pending-week="<?= $pending_week ? (int)$pending_week : 0 ?>"
                                                data-reports-count="<?= $r_count ?>"
                                                data-graded-count="<?= $g_count ?>">

                                                <!-- 1. Student -->
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center gap-3">
                                                        <?php if (!empty($s['profile_pic'])): ?>
                                                            <img src="../uploads/avatars/<?= htmlspecialchars($s['profile_pic']) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover ring-1 ring-slate-200 shadow-xs shrink-0 cursor-pointer" onclick="openStudentQuickModal(<?= $uid ?>)">
                                                        <?php else: ?>
                                                            <div onclick="openStudentQuickModal(<?= $uid ?>)" class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-600 to-cyan-700 text-white flex items-center justify-center text-xs font-black shrink-0 shadow-xs cursor-pointer">
                                                                <?= strtoupper(substr($name, 0, 1)) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="min-w-0">
                                                            <button type="button" onclick="openStudentQuickModal(<?= $uid ?>)" class="font-bold text-slate-800 hover:text-teal-700 transition text-xs sm:text-sm leading-tight text-left cursor-pointer">
                                                                <?= htmlspecialchars($name) ?>
                                                            </button>
                                                            <p class="text-[11px] text-slate-400 font-medium mt-0.5 truncate max-w-[150px]"><?= htmlspecialchars($s['email']) ?></p>
                                                            <?php if (!empty($s['major'])): ?>
                                                                <span class="inline-block text-[10px] font-semibold text-slate-500 bg-slate-100 px-1.5 py-0.2 rounded mt-0.5"><?= htmlspecialchars($s['major']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- 2. Roll Number -->
                                                <td class="px-3 py-3 whitespace-nowrap">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-mono font-bold bg-slate-100 text-slate-700 border border-slate-200/80">
                                                        <?= htmlspecialchars($roll) ?>
                                                    </span>
                                                </td>

                                                <!-- 3. Company & Instructor -->
                                                <td class="px-3 py-3 align-middle">
                                                    <div class="text-xs text-slate-800 font-semibold leading-snug break-words" title="<?= htmlspecialchars($s['company_name'] ?? '') ?>">
                                                        🏢 <?= htmlspecialchars($s['company_name'] ?: '—') ?>
                                                    </div>
                                                    <?php if (!empty($s['instructor_name'])): ?>
                                                        <div class="text-[11px] text-slate-500 font-medium mt-1 leading-snug break-words" title="<?= htmlspecialchars($s['instructor_name']) ?>">
                                                            👨‍🏫 <?= htmlspecialchars($s['instructor_name']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- 5. Accurate Progress & Weeks Completed -->
                                                <td class="px-3 py-3">
                                                    <div class="flex items-center gap-2">
                                                        <div class="flex-1 bg-slate-100 rounded-full h-1.5 overflow-hidden min-w-[60px]">
                                                            <div class="h-1.5 rounded-full bg-gradient-to-r <?= progress_bar_color($progress_pct) ?> transition-all duration-500" style="width: <?= $progress_pct ?>%"></div>
                                                        </div>
                                                        <span class="text-[11px] font-bold text-slate-700 shrink-0"><?= $progress_pct ?>%</span>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[10px] text-slate-400 mt-0.5">
                                                        <span><?= $not_started ? 'Not started' : 'Week ' . $completed_weeks . '/' . $total_weeks . ' completed' ?></span>
                                                        <?php if (!$not_started && $exp_days > 0): ?>
                                                            <span class="text-slate-500 font-medium ml-1">Att: <?= $att_pct ?>% (<?= $pres_days ?>/<?= $exp_days ?>d)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <!-- 6. Reports -->
                                                <td class="px-3 py-3 text-center whitespace-nowrap">
                                                    <div class="inline-flex flex-col items-center">
                                                        <a href="supervisor-reports.php?student_id=<?= $uid ?>" class="inline-flex items-center gap-1 text-xs font-bold text-slate-700 bg-slate-100 hover:bg-teal-50 hover:text-teal-700 px-2.5 py-0.5 rounded-lg transition" title="View reports for <?= htmlspecialchars($name) ?>">
                                                            📄 <?= $r_count ?>
                                                        </a>
                                                        <?php if ($g_count > 0): ?>
                                                            <span class="text-[10px] text-emerald-600 font-bold mt-0.5" title="Graded weeks">✓ <?= $g_count ?> graded</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <!-- 7. Status (Clean Static Indicator) -->
                                                <td class="px-3 py-3 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-1.5 cursor-default select-none">
                                                        <span class="w-2 h-2 rounded-full shrink-0 <?= $dot ?>"></span>
                                                        <span class="text-xs font-semibold <?= $status === 'red' ? 'text-rose-600' : ($status === 'amber' ? 'text-amber-600' : ($status === 'green' ? 'text-emerald-600' : 'text-slate-500')) ?>">
                                                            <?= $label[0] ?>
                                                        </span>
                                                    </div>
                                                </td>

                                                <!-- 8. Actions -->
                                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                                    <div class="inline-flex items-center justify-end gap-1.5 flex-nowrap">
                                                        <!-- Quick Info Button -->
                                                        <button type="button" onclick="openStudentQuickModal(<?= $uid ?>)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition cursor-pointer" title="Quick Student Info">
                                                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                            <span>Info</span>
                                                        </button>

                                                        <!-- Grade Button (if report pending) -->
                                                        <?php if ($pending_week): ?>
                                                            <a href="supervisor-review.php?student_id=<?= $uid ?>&week=<?= (int)$pending_week ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-lg transition shadow-xs" title="Grade pending report">
                                                                <svg class="w-3.5 h-3.5 text-teal-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                                </svg>
                                                                <span>Grade</span>
                                                            </a>
                                                        <?php endif; ?>

                                                        <!-- Behind Schedule Send Warning Button -->
                                                        <?php if ($status === 'red'): ?>
                                                            <?php if ($is_warned): ?>
                                                                <span class="warning-badge-<?= $uid ?> inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 text-xs font-bold rounded-lg shrink-0" title="Warning notification already sent">
                                                                    <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                                    </svg>
                                                                    <span>Warned</span>
                                                                </span>
                                                            <?php else: ?>
                                                                <button type="button"
                                                                    onclick="sendBehindScheduleWarning(<?= $uid ?>, this)"
                                                                    class="warning-btn-<?= $uid ?> inline-flex items-center gap-1 px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold rounded-lg transition cursor-pointer shadow-xs shrink-0"
                                                                    title="Send behind schedule warning to student">
                                                                    <svg class="w-3.5 h-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                                    </svg>
                                                                    <span>Warn</span>
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <!-- Filter Empty State Row -->
                                    <tr id="filterNoResultsRow" class="hidden">
                                        <td colspan="7" class="p-14 text-center">
                                            <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-3">🔍</div>
                                            <p class="text-sm font-bold text-slate-700">No matching students found</p>
                                            <p class="text-xs text-slate-400 mt-1" id="filterNoResultsMsg">Try adjusting your search terms or academic year filter.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <!-- ═══════════ STUDENT QUICK INFO & CONTACT MODAL / DRAWER ═══════════ -->
    <div id="studentQuickModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeStudentQuickModal()"></div>
        <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-7 z-10 max-h-[90vh] overflow-y-auto">
            <!-- Close button -->
            <button onclick="closeStudentQuickModal()" class="absolute top-5 right-5 w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold flex items-center justify-center transition cursor-pointer">✕</button>

            <!-- Header Profile -->
            <div class="flex items-center gap-4 mb-6 pb-5 border-b border-slate-100">
                <div id="modalAvatarBox" class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-600 to-cyan-700 text-white flex items-center justify-center text-xl font-black shrink-0 shadow-md"></div>
                <div class="min-w-0">
                    <h3 id="modalStudentName" class="text-base sm:text-lg font-black text-slate-800 leading-tight"></h3>
                    <p id="modalStudentEmail" class="text-xs text-slate-400 font-medium mt-0.5"></p>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                        <span id="modalStudentRoll" class="text-xs font-mono font-bold bg-slate-100 text-slate-700 px-2 py-0.5 rounded-lg border border-slate-200"></span>
                        <span id="modalStudentYear" class="text-xs font-semibold bg-teal-50 text-teal-800 px-2 py-0.5 rounded-lg border border-teal-200/60"></span>
                        <span id="modalStudentMajor" class="text-xs font-semibold bg-slate-100 text-slate-600 px-2 py-0.5 rounded-lg"></span>
                    </div>
                </div>
            </div>

            <!-- 2-Column Info Grid: Student Contacts + Company Placement -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 text-xs">
                <!-- Student Contacts -->
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100 space-y-2">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Student Contacts & Progress</p>
                    <div>
                        <span class="text-slate-500">Phone:</span>
                        <p id="modalStudentPhone" class="font-bold text-slate-800 font-mono mt-0.5"></p>
                    </div>
                    <div>
                        <span class="text-slate-500">Internship Period:</span>
                        <p id="modalInternshipPeriod" class="font-bold text-slate-800 mt-0.5"></p>
                    </div>
                    <div>
                        <span class="text-slate-500">Progress:</span>
                        <p id="modalStudentProgress" class="font-bold text-slate-800 mt-0.5"></p>
                    </div>
                </div>

                <!-- Company & Instructor Placement -->
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100 space-y-2">
                    <div class="flex items-center justify-between">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Host Company & Instructor</p>
                        <button type="button" onclick="openEditPlacementModal()" class="text-[11px] font-bold text-teal-700 hover:text-teal-800 bg-teal-50 hover:bg-teal-100 border border-teal-200/80 px-2.5 py-0.5 rounded-lg transition cursor-pointer flex items-center gap-1">
                            ✏️ Edit Placement
                        </button>
                    </div>
                    <div>
                        <span class="text-slate-500">Company:</span>
                        <p id="modalCompanyName" class="font-bold text-slate-800 mt-0.5 break-words leading-snug"></p>
                    </div>
                    <div>
                        <span class="text-slate-500">Company Instructor:</span>
                        <p id="modalInstructorName" class="font-bold text-slate-800 mt-0.5 break-words leading-snug"></p>
                        <p id="modalInstructorContact" class="text-[11px] text-slate-500 mt-0.5 break-words leading-snug"></p>
                    </div>
                </div>
            </div>

            <!-- Quick Action Shortcuts -->
            <div class="space-y-2">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Direct Shortcuts</p>
                <div class="grid grid-cols-2 gap-2.5">
                    <a id="modalLinkDashboard" href="#" class="flex items-center justify-center gap-2 p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
                        <span>📊</span> Student Dashboard
                    </a>
                    <a id="modalLinkReports" href="#" class="flex items-center justify-center gap-2 p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
                        <span>📄</span> Weekly Reports
                    </a>
                    <a id="modalLinkHistory" href="#" class="flex items-center justify-center gap-2 p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
                        <span>📜</span> 13-Week History
                    </a>
                    <a id="modalLinkPrint" href="#" target="_blank" class="flex items-center justify-center gap-2 p-2.5 bg-[#005f73] hover:bg-[#0a9396] text-white text-xs font-bold rounded-xl transition shadow-xs">
                        <span>📄</span> Save as PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ════ SUPERVISOR EDIT PLACEMENT & INSTRUCTOR MODAL ════ -->
    <div id="editPlacementModal" class="fixed inset-0 z-[100] bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center font-bold">🏢</span>
                    Assign Company & Instructor
                </h3>
                <button type="button" onclick="closeEditPlacementModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-sm font-bold transition cursor-pointer">✕</button>
            </div>
            <form method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="update_student_placement" value="1">
                <input type="hidden" name="student_id" id="editPlacementStudentId" value="">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Company / Host Organization *</label>
                    <input type="text" name="company_name" id="editPlacementCompanyName" placeholder="e.g. Ace Data Systems" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition" required>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Internship Job Role</label>
                    <input type="text" name="job_role" id="editPlacementJobRole" placeholder="e.g. Web Developer Intern" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Company Instructor Name *</label>
                        <input type="text" name="instructor_name" id="editPlacementInstructorName" placeholder="e.g. U Kyaw Kyaw" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition" required>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Instructor Email * <span class="text-teal-600 font-normal">(for Evaluation)</span></label>
                        <input type="email" name="instructor_email" id="editPlacementInstructorEmail" placeholder="instructor@company.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition" required>
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Instructor Phone</label>
                    <input type="text" name="instructor_phone" id="editPlacementInstructorPhone" placeholder="09xxxxxxxxx" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Start Date</label>
                        <input type="date" name="internship_start_date" id="editPlacementStartDate" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">End Date</label>
                        <input type="date" name="internship_end_date" id="editPlacementEndDate" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:border-teal-600 focus:bg-white transition">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="closeEditPlacementModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition cursor-pointer">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-teal-600 hover:bg-teal-700 text-white font-bold rounded-xl shadow-xs transition cursor-pointer">Save Placement</button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/notification_delete.php'; ?>

    <!-- ════ CLIENT-SIDE JAVASCRIPT: FILTERS & LIVE SEARCH ════ -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('studentSearchInput');
            const clearSearchBtn = document.getElementById('clearSearchBtn');
            const yearSelect = document.getElementById('academicYearSelect');
            const statusChips = document.querySelectorAll('.status-chip');
            const countNumberEl = document.getElementById('visibleCountNumber');
            const countTextEl = document.getElementById('dynamicResultCount');
            const noResultsRow = document.getElementById('filterNoResultsRow');
            const noResultsMsg = document.getElementById('filterNoResultsMsg');

            let currentStatus = '<?= htmlspecialchars($filter_status, ENT_QUOTES, 'UTF-8') ?>';
            let currentYear = '<?= htmlspecialchars($filter_year, ENT_QUOTES, 'UTF-8') ?>';

            if (yearSelect && currentYear) {
                yearSelect.value = currentYear;
            }

            function applyFilters() {
                const query = (searchInput?.value || '').trim().toLowerCase();
                const selectedYear = yearSelect ? yearSelect.value : 'all';

                const studentRows = document.querySelectorAll('.student-row');
                let visibleCount = 0;

                if (clearSearchBtn) {
                    if (query.length > 0) clearSearchBtn.classList.remove('hidden');
                    else clearSearchBtn.classList.add('hidden');
                }

                // Filter table rows
                studentRows.forEach(function(row) {
                    const name = row.getAttribute('data-name') || '';
                    const roll = row.getAttribute('data-roll') || '';
                    const company = row.getAttribute('data-company') || '';
                    const instructor = row.getAttribute('data-instructor') || '';
                    const major = row.getAttribute('data-major') || '';
                    const email = row.getAttribute('data-email') || '';
                    const rowYear = row.getAttribute('data-academic-year') || '';
                    const rowStat = row.getAttribute('data-status') || 'none';

                    const matchesSearch = !query ||
                        name.includes(query) ||
                        roll.includes(query) ||
                        company.includes(query) ||
                        instructor.includes(query) ||
                        major.includes(query) ||
                        email.includes(query);

                    const matchesYear = (selectedYear === 'all' || !selectedYear) || (rowYear === selectedYear);
                    const matchesStatus = !currentStatus || (currentStatus === 'pending' ? parseInt(row.getAttribute('data-pending-week') || 0) > 0 : rowStat === currentStatus);

                    if (matchesSearch && matchesYear && matchesStatus) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Show/hide academic year header rows based on visible students in that batch
                const ayHeaders = document.querySelectorAll('.academic-year-header-row');
                ayHeaders.forEach(function(header) {
                    const groupAy = header.getAttribute('data-group-ay') || '';
                    let hasVisibleStudents = false;
                    studentRows.forEach(function(row) {
                        if (row.style.display !== 'none') {
                            const rowAy = row.getAttribute('data-academic-year') || 'Unassigned Year';
                            if (rowAy === groupAy) {
                                hasVisibleStudents = true;
                            }
                        }
                    });
                    header.style.display = hasVisibleStudents ? '' : 'none';
                });

                // Update Dynamic Count Text
                if (countNumberEl) countNumberEl.textContent = visibleCount;
                if (countTextEl) {
                    let label = `Showing <span class="font-bold text-slate-800">${visibleCount}</span> student${visibleCount === 1 ? '' : 's'}`;
                    if (selectedYear && selectedYear !== 'all') {
                        label += ` for <span class="font-semibold text-slate-700">${selectedYear}</span>`;
                    }
                    if (currentStatus) {
                        const statusLabels = {
                            'pending': 'Awaiting Review',
                            'red': 'Behind Schedule',
                            'amber': 'In Progress',
                            'green': 'Complete',
                            'none': 'Not Started'
                        };
                        label += ` (${statusLabels[currentStatus] || currentStatus})`;
                    }
                    if (query) {
                        label += ` matching “<span class="font-semibold text-slate-700">${escapeHtml(query)}</span>”`;
                    }
                    countTextEl.innerHTML = label;
                }

                // Empty state handling
                if (noResultsRow) {
                    if (visibleCount === 0 && studentRows.length > 0) {
                        noResultsRow.classList.remove('hidden');
                        if (noResultsMsg) {
                            if (query) {
                                noResultsMsg.textContent = `No students found matching “${query}”. Try adjusting your search.`;
                            } else if (selectedYear && selectedYear !== 'all') {
                                noResultsMsg.textContent = `No students found for Academic Year ${selectedYear}.`;
                            } else {
                                noResultsMsg.textContent = 'Try adjusting your search terms or status filter.';
                            }
                        }
                    } else {
                        noResultsRow.classList.add('hidden');
                    }
                }
            }

            function escapeHtml(str) {
                return str.replace(/[&<>"']/g, function(m) {
                    return ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    })[m];
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', applyFilters);
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        searchInput.value = '';
                        applyFilters();
                    }
                });
            }

            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', function() {
                    if (searchInput) {
                        searchInput.value = '';
                        applyFilters();
                        searchInput.focus();
                    }
                });
            }

            if (yearSelect) {
                yearSelect.addEventListener('change', applyFilters);
            }

            statusChips.forEach(function(chip) {
                chip.addEventListener('click', function() {
                    const selected = this.getAttribute('data-status') || '';
                    currentStatus = selected;

                    statusChips.forEach(function(c) {
                        const s = c.getAttribute('data-status') || '';
                        let cls = 'status-chip min-h-[32px] inline-flex items-center justify-center px-3 py-1.5 text-xs font-bold rounded-xl border cursor-pointer shrink-0 ';
                        if (s === currentStatus) {
                            if (s === '') cls += 'bg-slate-800 text-white border-slate-800 shadow-xs';
                            else if (s === 'pending') cls += 'bg-amber-600 text-white border-amber-600 shadow-xs';
                            else if (s === 'red') cls += 'bg-red-500 text-white border-red-500 shadow-xs';
                            else if (s === 'amber') cls += 'bg-amber-500 text-white border-amber-500 shadow-xs';
                            else if (s === 'green') cls += 'bg-emerald-500 text-white border-emerald-500 shadow-xs';
                            else if (s === 'none') cls += 'bg-slate-600 text-white border-slate-600 shadow-xs';
                        } else {
                            if (s === '') cls += 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
                            else if (s === 'pending') cls += 'bg-white text-amber-700 border-amber-200 hover:bg-amber-50';
                            else if (s === 'red') cls += 'bg-white text-red-600 border-red-200 hover:bg-red-50';
                            else if (s === 'amber') cls += 'bg-white text-amber-600 border-amber-200 hover:bg-amber-50';
                            else if (s === 'green') cls += 'bg-white text-emerald-600 border-emerald-200 hover:bg-emerald-50';
                            else if (s === 'none') cls += 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50';
                        }
                        c.className = cls;
                    });

                    applyFilters();
                });
            });

            window.applyFilters = applyFilters;
            applyFilters();
        });

        /**
         * Filter shortcut helpers from KPI banner
         */
        function filterByPendingReports() {
            const pendingChip = document.querySelector('.status-chip[data-status="pending"]');
            if (pendingChip) {
                pendingChip.click();
            } else {
                const studentRows = document.querySelectorAll('.student-row');
                let visibleCount = 0;
                studentRows.forEach(function(row) {
                    const pendingWeek = parseInt(row.getAttribute('data-pending-week') || 0);
                    if (pendingWeek > 0) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                const countNumberEl = document.getElementById('visibleCountNumber');
                const countTextEl = document.getElementById('dynamicResultCount');
                if (countNumberEl) countNumberEl.textContent = visibleCount;
                if (countTextEl) countTextEl.innerHTML = `Showing <span class="font-bold text-slate-800">${visibleCount}</span> student${visibleCount === 1 ? '' : 's'} (Awaiting Review)`;
            }
        }

        function filterByBehindSchedule() {
            const redChip = document.querySelector('.status-chip[data-status="red"]');
            if (redChip) redChip.click();
        }

        /**
         * Single Warning Handler
         */
        function sendBehindScheduleWarning(studentId, btn) {
            if (!studentId || !btn) return;
            if (btn.disabled) return;

            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="inline-block animate-spin mr-1">⏳</span> Sending…';

            const fd = new FormData();
            fd.append('send_warning', '1');
            fd.append('student_id', studentId);

            fetch('my-students.php', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data && data.success) {
                        alert('Warning sent successfully to student.');
                        const badges = document.querySelectorAll('.warning-btn-' + studentId);
                        badges.forEach(function(b) {
                            const badge = document.createElement('span');
                            badge.className = 'warning-badge-' + studentId + ' inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 text-xs font-bold rounded-lg';
                            badge.innerHTML = '✓ Warned';
                            b.parentNode.replaceChild(badge, b);
                        });
                    } else {
                        alert(data.message || 'Failed to send warning.');
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                })
                .catch(function(err) {
                    alert('Network error while sending warning.');
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                });
        }

        let currentQuickModalStudentId = null;

        /**
         * Quick Student Info Modal
         */
        function openStudentQuickModal(studentId) {
            currentQuickModalStudentId = studentId;
            const row = document.querySelector(`.student-row[data-uid="${studentId}"]`);
            if (!row) return;

            const modal = document.getElementById('studentQuickModal');
            if (!modal) return;

            const name = row.getAttribute('data-raw-name') || '';
            const email = row.getAttribute('data-email') || '';
            const roll = row.getAttribute('data-raw-roll') || '';
            const year = row.getAttribute('data-academic-year') || '';
            const major = row.getAttribute('data-raw-major') || '';
            const phone = row.getAttribute('data-phone') || '—';
            const company = row.getAttribute('data-raw-company') || '—';
            const instructor = row.getAttribute('data-raw-instructor') || '—';
            const instEmail = row.getAttribute('data-instructor-email') || '';
            const instPhone = row.getAttribute('data-instructor-phone') || '';
            const startDate = row.getAttribute('data-start-date') || '';
            const endDate = row.getAttribute('data-end-date') || '';
            const pendingWeek = row.getAttribute('data-pending-week') || '0';
            const progPct = row.getAttribute('data-progress-pct') || '0';
            const completedWeeks = row.getAttribute('data-completed-weeks') || '0';
            const totalWeeks = row.getAttribute('data-total-weeks') || '0';
            const attPct = row.getAttribute('data-attendance-pct') || '0';
            const presDays = row.getAttribute('data-present-days') || '0';
            const expDays = row.getAttribute('data-expected-days') || '0';

            document.getElementById('modalStudentName').textContent = name;
            document.getElementById('modalStudentEmail').textContent = email;
            document.getElementById('modalStudentRoll').textContent = roll;
            document.getElementById('modalStudentYear').textContent = year ? 'AY ' + year : '—';
            document.getElementById('modalStudentMajor').textContent = major || 'Computer Science';
            document.getElementById('modalStudentPhone').textContent = phone;
            document.getElementById('modalCompanyName').textContent = company;
            document.getElementById('modalInstructorName').textContent = instructor;

            let instContact = '';
            if (instEmail) instContact += '✉️ ' + instEmail;
            if (instPhone) instContact += (instContact ? ' · ' : '') + '📱 ' + instPhone;
            document.getElementById('modalInstructorContact').textContent = instContact || 'No contact specified';

            document.getElementById('modalInternshipPeriod').textContent = (startDate && endDate) ?
                (new Date(startDate).toLocaleDateString('en-GB', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }) + ' – ' + new Date(endDate).toLocaleDateString('en-GB', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                })) :
                'Not configured';

            const progEl = document.getElementById('modalStudentProgress');
            if (progEl) {
                if (parseInt(expDays, 10) > 0) {
                    progEl.textContent = `${progPct}% (Week ${completedWeeks}/${totalWeeks} completed) · Attendance: ${attPct}% (${presDays}/${expDays} days)`;
                } else {
                    progEl.textContent = `${progPct}% (Week ${completedWeeks}/${totalWeeks} completed) · Attendance: 0%`;
                }
            }

            const avatarBox = document.getElementById('modalAvatarBox');
            if (avatarBox) {
                avatarBox.textContent = name.charAt(0).toUpperCase();
            }

            // Set shortcut links
            document.getElementById('modalLinkDashboard').href = 'view-student-dashboard.php?id=' + studentId;
            document.getElementById('modalLinkReports').href = 'supervisor-reports.php?student_id=' + studentId;
            document.getElementById('modalLinkHistory').href = '../view_student_history.php?uid=' + studentId;
            document.getElementById('modalLinkPrint').href = '../student/print_report.php?student_id=' + studentId + (pendingWeek > 0 ? '&week=' + pendingWeek : '&week=1');

            modal.classList.remove('hidden');
        }

        function closeStudentQuickModal() {
            const modal = document.getElementById('studentQuickModal');
            if (modal) modal.classList.add('hidden');
        }

        /**
         * Open Edit Placement Modal
         */
        function openEditPlacementModal(studentId) {
            const id = studentId || currentQuickModalStudentId;
            if (!id) return;
            const row = document.querySelector(`.student-row[data-uid="${id}"]`);
            if (!row) return;

            document.getElementById('editPlacementStudentId').value = id;
            const rawComp = row.getAttribute('data-raw-company') || '';
            document.getElementById('editPlacementCompanyName').value = (rawComp !== '—' && rawComp !== '—') ? rawComp : '';
            document.getElementById('editPlacementJobRole').value = row.getAttribute('data-job-role') || '';
            const rawInst = row.getAttribute('data-raw-instructor') || '';
            document.getElementById('editPlacementInstructorName').value = (rawInst !== '—' && rawInst !== '—') ? rawInst : '';
            document.getElementById('editPlacementInstructorEmail').value = row.getAttribute('data-instructor-email') || '';
            document.getElementById('editPlacementInstructorPhone').value = row.getAttribute('data-instructor-phone') || '';
            document.getElementById('editPlacementStartDate').value = row.getAttribute('data-start-date') || '';
            document.getElementById('editPlacementEndDate').value = row.getAttribute('data-end-date') || '';

            document.getElementById('editPlacementModal').classList.remove('hidden');
        }

        function closeEditPlacementModal() {
            const modal = document.getElementById('editPlacementModal');
            if (modal) modal.classList.add('hidden');
        }
    </script>

</body>

</html>