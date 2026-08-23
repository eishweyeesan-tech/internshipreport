<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$db       = $mysqli ?? $conn;

// Get supervisor email for alerts
$sup_email_q = $db->prepare("SELECT email FROM users WHERE id = ?");
$sup_email_q->bind_param("i", $sup_id);
$sup_email_q->execute();
$res = $sup_email_q->get_result();
$row = $res ? $res->fetch_row() : null;
$sup_email = $row[0] ?? '';

// ══════════════════════════════════════════════════════════════════════
// WARNING NOTIFICATION HANDLER
// When supervisor clicks "Send Warning", set is_warned = 1 for that student
// ══════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_warning'])) {
    $warn_student_id = (int) ($_POST['student_id'] ?? 0);
    if ($warn_student_id > 0) {
        $warn_q = $db->prepare("UPDATE users SET is_warned = 1 WHERE id = ? AND role = 'student'");
        $warn_q->bind_param("i", $warn_student_id);
        $warn_q->execute();
        header('Location: supervisor-dashboard.php?warned=1');
        exit;
    }
}

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

// ══════════════════════════════════════════════════════════════════════
// EMAIL / IN-APP ALERT HELPER FUNCTION
// ══════════════════════════════════════════════════════════════════════
function sendRedBadgeAlert($db, $supervisor_id, $supervisor_name, $supervisor_email, $student_id, $student_name, $student_roll, $company_name) {
    require_once __DIR__ . '/../config/notify.php';
    return (bool) notify_user_once(
        $db,
        $supervisor_id,
        'Student Behind Schedule',
        $student_name . ' (' . ($student_roll ?: 'No roll no.') . ') has not submitted any daily logs this week and is behind schedule.',
        'student_behind_schedule',
        null,
        $student_id,
        null,
        true
    );
}

// ── Current Week Boundaries ─────────────────────────────────────────
$today = new DateTime();
$dayOfWeek = (int) $today->format('N');
$weekStart = (clone $today)->modify('monday this week')->format('Y-m-d');
$weekEnd   = (clone $today)->modify('sunday this week')->format('Y-m-d');

// ══════════════════════════════════════════════════════════════════════
// FETCH ASSIGNED ACTIVE STUDENTS (Dashboard Overview)
// ══════════════════════════════════════════════════════════════════════
$stu_detail_sql = "
    SELECT u.id AS uid, u.username, u.email, u.academic_year, u.profile_pic,
           sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
           sp.internship_start_date, sp.internship_end_date,
           sp.instructor_name, sp.instructor_email, sp.instructor_id
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?
    ORDER BY sp.full_name ASC
";
$stu_detail_stmt = $db->prepare($stu_detail_sql);
$stu_detail_stmt->bind_param("i", $sup_id);
$stu_detail_stmt->execute();
$res = $stu_detail_stmt->get_result();
$all_students_detail = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$total_assigned = count($all_students_detail);

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC WEEK & PROGRESS CALCULATION
// ══════════════════════════════════════════════════════════════════════
$today_obj = new DateTime();
$student_dynamic_week = [];
$student_not_started = [];
$student_progress = [];

foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $not_started = false;

    if (!empty($sd['internship_start_date'])) {
        $start_date = $sd['internship_start_date'];
        $end_date   = $sd['internship_end_date'] ?: null;
        $dynamic_week = internship_current_week($start_date, $end_date, $today_obj);

        if ($today_obj < new DateTime($start_date)) {
            $not_started = true;
        } elseif ($end_date && $today_obj > new DateTime($end_date)) {
            require_once __DIR__ . '/../config/notify.php';
            notify_user_once(
                $db,
                $sup_id,
                'Internship Completed',
                ($sd['full_name'] ?: $sd['username']) . ' has completed their internship (ended ' . $sd['internship_end_date'] . ').',
                'internship_completed',
                null,
                $uid
            );
        }
    } else {
        $not_started = true;
        $dynamic_week = 1;
    }

    $student_dynamic_week[$uid] = $dynamic_week;
    $student_not_started[$uid]  = $not_started;
    $student_progress[$uid]     = internship_progress($db, $uid, $sd['internship_start_date'], $sd['internship_end_date']);
}

// ══════════════════════════════════════════════════════════════════════
// STATUS CLASSIFICATION (Behind Schedule, In Progress, Complete)
// ══════════════════════════════════════════════════════════════════════
$behind_schedule = 0;
$in_progress = 0;
$complete = 0;
$progress_status = [];

$report_status_cache = [];
$rs_q = $db->prepare("SELECT report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rs_q->bind_param("ii", $uid, $dw);
    $rs_q->execute();
    $res = $rs_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $report_status_cache[$uid] = $row[0] ?? 'pending';
}

$log_q = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rstatus = $report_status_cache[$uid] ?? 'pending';
    $not_started = $student_not_started[$uid] ?? false;

    if ($not_started) {
        $progress_status[$uid] = 'none';
        continue;
    }

    if ($rstatus === 'approved_by_supervisor') {
        $complete++;
        $progress_status[$uid] = 'green';
        continue;
    }

    if (!empty($sd['internship_start_date'])) {
        $stu_start = new DateTime($sd['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        $sws = $weekStart;
        $swe = $weekEnd;
    }

    $log_q->bind_param("iss", $uid, $sws, $swe);
    $log_q->execute();
    $res = $log_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $log_count = (int) ($row[0] ?? 0);

    if ($dayOfWeek >= 3 && $log_count === 0) {
        $behind_schedule++;
        $progress_status[$uid] = 'red';

        sendRedBadgeAlert(
            $db, $sup_id, $sup_name, $sup_email,
            $uid, $sd['full_name'] ?: $sd['username'],
            $sd['student_roll'], $sd['company_name']
        );
    } elseif ($log_count >= 1 && $log_count <= 4) {
        $in_progress++;
        $progress_status[$uid] = 'amber';
    } elseif ($log_count >= 5) {
        $complete++;
        $progress_status[$uid] = 'green';
    } else {
        $progress_status[$uid] = 'none';
    }
}

// Progress percentage
$progress_pct = [];
foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $not_started = $student_not_started[$uid] ?? false;
    $progress_pct[$uid] = $not_started ? 0 : ($student_progress[$uid]['pct'] ?? 0);
}

// ══════════════════════════════════════════════════════════════════════
// UNREVIEWED REPORTS PER STUDENT & TOTAL PENDING REVIEWS
// ══════════════════════════════════════════════════════════════════════
$unreviewed = [];
$unrev_q = $db->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    WHERE re.student_id = ?
      AND re.report_status = 'approved_by_instructor'
      AND NOT EXISTS (
          SELECT 1 FROM supervisor_weekly_evaluations swe
          WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
      )
");
foreach ($all_students_detail as $s) {
    $unrev_q->bind_param("i", $s['uid']);
    $unrev_q->execute();
    $res = $unrev_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $unreviewed[$s['uid']] = (int) ($row[0] ?? 0);
}

// Total pending reviews across assigned students
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

// ══════════════════════════════════════════════════════════════════════
// RECENT REPORTS (From Assigned Students)
// ══════════════════════════════════════════════════════════════════════
$recent_reports_q = $db->prepare("
    SELECT re.week_number, re.report_status, re.evaluated_at,
           u.id AS student_id, u.username, u.profile_pic,
           sp.full_name, sp.student_roll, sp.company_name
    FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
    ORDER BY re.evaluated_at DESC
    LIMIT 6
");
$recent_reports_q->bind_param("i", $sup_id);
$recent_reports_q->execute();
$res = $recent_reports_q->get_result();
$recent_reports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ══════════════════════════════════════════════════════════════════════
// NEEDS ATTENTION (Prioritized Action Items for Supervisor)
// ══════════════════════════════════════════════════════════════════════
$tasks = [];

// 1. HIGH PRIORITY: Students behind schedule (No logs this week)
foreach ($all_students_detail as $sd) {
    if (($progress_status[$sd['uid']] ?? 'none') === 'red') {
        $tasks[] = [
            'priority' => 1,
            'type'     => 'behind',
            'badge'    => 'Behind Schedule',
            'badge_cls'=> 'bg-red-50 text-red-700 border-red-200',
            'title'    => htmlspecialchars($sd['full_name'] ?: $sd['username']),
            'subtitle' => 'No daily logs submitted this week (' . htmlspecialchars($sd['company_name'] ?: 'No Company') . ')',
            'student_id' => $sd['uid'],
            'action_label' => 'View Log',
            'url'      => 'view-student-dashboard.php?id=' . (int) $sd['uid'],
            'can_warn' => true,
        ];
    }
}

// 2. MEDIUM PRIORITY: Reports approved by instructor awaiting supervisor grade
$pending_task_q = $db->prepare("
    SELECT re.week_number, re.evaluated_at, u.id AS student_id, u.username, sp.full_name, sp.company_name
    FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND re.report_status = 'approved_by_instructor'
      AND NOT EXISTS (
          SELECT 1 FROM supervisor_weekly_evaluations swe
          WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
      )
    ORDER BY re.evaluated_at ASC
    LIMIT 10
");
$pending_task_q->bind_param("i", $sup_id);
$pending_task_q->execute();
$res = $pending_task_q->get_result();
if ($res) {
    while ($ptr = $res->fetch_assoc()) {
        $tasks[] = [
            'priority' => 2,
            'type'     => 'review',
            'badge'    => 'Ready for Review',
            'badge_cls'=> 'bg-blue-50 text-blue-700 border-blue-200',
            'title'    => htmlspecialchars($ptr['full_name'] ?: $ptr['username']) . ' · Week ' . (int) $ptr['week_number'],
            'subtitle' => '✅ Approved by instructor · ' . htmlspecialchars($ptr['company_name'] ?: 'Internship Report'),
            'student_id' => (int) $ptr['student_id'],
            'action_label' => 'Review & Grade',
            'url'      => 'supervisor-review.php?student_id=' . (int) $ptr['student_id'] . '&week=' . (int) $ptr['week_number'],
            'can_warn' => false,
        ];
    }
}


// 3. FINAL EVALUATIONS / IMPORTANT TASKS: Internship ended without complete weekly grading
$final_task_q = $db->prepare("
    SELECT u.id AS student_id, u.username, sp.full_name,
           sp.internship_start_date, sp.internship_end_date,
           (SELECT COUNT(*) FROM supervisor_weekly_evaluations swe WHERE swe.student_id = u.id) AS graded_weeks
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.internship_end_date > '2000-01-01'
      AND sp.internship_end_date <= CURDATE()
");
$final_task_q->bind_param("i", $sup_id);
$final_task_q->execute();
$res = $final_task_q->get_result();
if ($res) {
    while ($ftr = $res->fetch_assoc()) {
        $graded = (int) $ftr['graded_weeks'];
        $ftr_total = internship_total_weeks($ftr['internship_start_date'], $ftr['internship_end_date']);
        if ($ftr_total > 0 && $graded < $ftr_total) {
            $tasks[] = [
                'priority' => 3,
                'type'     => 'final',
                'badge'    => 'Final Evaluation',
                'badge_cls'=> 'bg-blue-50 text-blue-700 border-blue-200',
                'title'    => htmlspecialchars($ftr['full_name'] ?: $ftr['username']),
                'subtitle' => 'Internship concluded (' . $graded . '/' . $ftr_total . ' weeks graded)',
                'student_id' => (int) $ftr['student_id'],
                'action_label' => 'Evaluate',
                'url'      => 'supervisor-review.php?student_id=' . (int) $ftr['student_id'],
                'can_warn' => false,
            ];
        }
    }
}

// Sort tasks by priority
usort($tasks, function ($a, $b) {
    return $a['priority'] <=> $b['priority'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
    function showToast(message, type) {
        var toast = document.createElement('div');
        var bgColor, icon;
        switch (type) {
            case 'success': bgColor = 'bg-emerald-600'; icon = '✓'; break;
            case 'error': bgColor = 'bg-red-600'; icon = '✕'; break;
            case 'warning': bgColor = 'bg-amber-500'; icon = '⚠️'; break;
            default: bgColor = 'bg-slate-700'; icon = 'ℹ';
        }
        toast.className = 'fixed bottom-6 right-6 z-[3000] ' + bgColor + ' text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300';
        toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)';
        toast.innerHTML = '<span class="text-base">' + icon + '</span> ' + message;
        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; });
        setTimeout(function() {
            toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'dashboard'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN CONTENT WRAPPER ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- ─── TOP BAR ─── -->
        <?php $pageTitle = 'University Supervisor Dashboard'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ─── MAIN DASHBOARD BODY ─── -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">


                <!-- ═══ 1. COMPACT WELCOME BANNER ═══ -->
                <section class="bg-gradient-to-r from-[#005f73] via-[#0a9396] to-[#005f73] rounded-2xl p-5 sm:p-6 text-white shadow-md shadow-teal-900/10 relative overflow-hidden">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.12),transparent_60%)]"></div>
                    <div class="relative flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            <div class="w-11 h-11 rounded-xl bg-white/15 backdrop-blur-md flex items-center justify-center text-xl border border-white/20 shadow-xs shrink-0">
                                👨‍🏫
                            </div>
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h2 class="text-lg sm:text-lg font-black tracking-tight text-white">Welcome back, <?= htmlspecialchars(format_supervisor_name($sup_name)) ?>!</h2>
                                </div>
                                <p class="text-xs text-teal-100/90 font-medium mt-0.5">
                                    <?= date('l, d F Y') ?> · Overview of assigned interns and pending supervisory tasks
                                </p>
                            </div>
                        </div>

                        <!-- Quick Badges -->
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <div class="flex items-center gap-2 bg-white/10 backdrop-blur-md border border-white/20 rounded-xl px-3.5 py-1.5 text-xs font-semibold">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span><?= $total_assigned ?> Assigned Students</span>
                            </div>
                            <div class="flex items-center gap-2 bg-white/10 backdrop-blur-md border border-white/20 rounded-xl px-3.5 py-1.5 text-xs font-semibold">
                                <span class="w-2 h-2 rounded-full <?= $pending_reviews > 0 ? 'bg-amber-400' : 'bg-teal-300' ?>"></span>
                                <span><?= $pending_reviews ?> Pending Review<?= $pending_reviews !== 1 ? 's' : '' ?></span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ═══ 2. KEY SUMMARY CARDS (ROW OF 4) ═══ -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Card 1: Assigned Students -->
                    <a href="my-students.php" class="group bg-white rounded-2xl border border-slate-200/70 p-4 sm:p-5 shadow-xs hover:shadow-md hover:border-teal-300 transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Assigned Students</p>
                                <p class="text-2xl font-black text-slate-800 mt-1.5"><?= $total_assigned ?></p>
                            </div>
                            <div class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 border border-teal-100 flex items-center justify-center text-lg shadow-xs group-hover:scale-105 transition-transform">
                                🎓
                            </div>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs">
                            <span class="text-slate-400 font-medium">Active intern placements</span>
                            <span class="font-bold text-teal-700 group-hover:translate-x-0.5 transition-transform inline-flex items-center gap-0.5">View All →</span>
                        </div>
                    </a>

                    <!-- Card 2: Pending Reports -->
                    <a href="supervisor-reports.php?status=approved_by_instructor" class="group bg-white rounded-2xl border border-slate-200/70 p-4 sm:p-5 shadow-xs hover:shadow-md hover:border-amber-300 transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Pending Reports</p>
                                <p class="text-2xl font-black text-slate-800 mt-1.5"><?= $pending_reviews ?></p>
                            </div>
                            <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-700 border border-amber-100 flex items-center justify-center text-lg shadow-xs group-hover:scale-105 transition-transform">
                                📩
                            </div>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs">
                            <span class="<?= $pending_reviews > 0 ? 'text-amber-600 font-semibold' : 'text-slate-400 font-medium' ?>">Awaiting supervisor grade</span>
                            <span class="font-bold text-amber-700 group-hover:translate-x-0.5 transition-transform inline-flex items-center gap-0.5">Review →</span>
                        </div>
                    </a>

                    <!-- Card 3: Behind Schedule -->
                    <a href="my-students.php?status=red" class="group bg-white rounded-2xl border border-slate-200/70 p-4 sm:p-5 shadow-xs hover:shadow-md hover:border-red-300 transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Behind Schedule</p>
                                <p class="text-2xl font-black <?= $behind_schedule > 0 ? 'text-red-600' : 'text-slate-800' ?> mt-1.5"><?= $behind_schedule ?></p>
                            </div>
                            <div class="w-11 h-11 rounded-xl bg-red-50 text-red-600 border border-red-100 flex items-center justify-center text-lg shadow-xs group-hover:scale-105 transition-transform">
                                ⚠️
                            </div>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs">
                            <span class="<?= $behind_schedule > 0 ? 'text-red-600 font-semibold' : 'text-slate-400 font-medium' ?>"><?= $behind_schedule > 0 ? 'No logs submitted' : 'None behind' ?></span>
                            <span class="font-bold text-red-600 group-hover:translate-x-0.5 transition-transform inline-flex items-center gap-0.5">Check →</span>
                        </div>
                    </a>

                    <!-- Card 4: Completed -->
                    <a href="my-students.php?status=green" class="group bg-white rounded-2xl border border-slate-200/70 p-4 sm:p-5 shadow-xs hover:shadow-md hover:border-emerald-300 transition-all duration-200 flex flex-col justify-between">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Completed</p>
                                <p class="text-2xl font-black text-slate-800 mt-1.5"><?= $complete ?></p>
                            </div>
                            <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 flex items-center justify-center text-lg shadow-xs group-hover:scale-105 transition-transform">
                                ✅
                            </div>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs">
                            <span class="text-emerald-600 font-medium">On track / graded</span>
                            <span class="font-bold text-emerald-700 group-hover:translate-x-0.5 transition-transform inline-flex items-center gap-0.5">Details →</span>
                        </div>
                    </a>
                </div>



                <!-- ═══ 3. 2-COLUMN SECTION: RECENT REPORTS + NEEDS ATTENTION ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                    <!-- LEFT COLUMN (7 COLS): RECENT REPORTS -->
                    <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-200/70 shadow-xs overflow-hidden flex flex-col justify-between">
                        <div>
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50/80 via-white to-white flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center text-sm font-bold">
                                        📄
                                    </div>
                                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Recent Reports</h3>
                                </div>
                                <a href="supervisor-reports.php" class="text-xs font-bold text-purple-600 hover:text-purple-800 hover:underline">
                                    View All Reports →
                                </a>
                            </div>

                            <?php if (!empty($recent_reports)): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($recent_reports as $rep):
                                    $rep_student = $rep['full_name'] ?: $rep['username'];
                                    $is_awaiting = ($rep['report_status'] === 'approved_by_instructor');
                                    $is_graded = ($rep['report_status'] === 'approved_by_supervisor');
                                ?>
                                <div class="p-4 sm:px-6 hover:bg-slate-50/60 transition-colors duration-150 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3.5 min-w-0">
                                        <?php if (!empty($rep['profile_pic'])): ?>
                                        <img src="../uploads/avatars/<?= htmlspecialchars($rep['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover border border-slate-200 shrink-0">
                                        <?php else: ?>
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white flex items-center justify-center text-xs font-black shrink-0 shadow-xs">
                                            <?= strtoupper(substr($rep_student, 0, 1)) ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($rep_student) ?></p>
                                                <span class="text-[11px] font-bold text-purple-700 bg-purple-50 border border-purple-200/60 px-2 py-0.5 rounded-md shrink-0">
                                                    Week <?= (int)$rep['week_number'] ?>
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-400 truncate mt-0.5"><?= htmlspecialchars($rep['company_name'] ?: 'Internship Report') ?></p>
                                            <p class="text-[10px] text-slate-400 mt-0.5">Submitted <?= (new DateTime($rep['evaluated_at']))->format('d M Y · h:i A') ?></p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2.5 shrink-0">
                                        <?php if ($is_awaiting): ?>
                                        <div class="hidden sm:inline-flex flex-col items-end gap-0.5">
                                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-md">
                                                ✅ Instructor Approved
                                            </span>
                                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-blue-700 bg-blue-50 border border-blue-200 px-1.5 py-0.5 rounded-md">
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span> Ready for Review
                                            </span>
                                        </div>
                                        <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-gradient-to-r from-teal-600 to-[#005f73] hover:from-teal-700 hover:to-[#004e5f] text-white text-xs font-bold rounded-lg shadow-xs transition-all duration-150">
                                            Review & Grade →
                                        </a>
                                        <?php elseif ($is_graded): ?>
                                        <span class="hidden sm:inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-md">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Supervisor Approved
                                        </span>
                                        <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg shadow-xs transition-all duration-150">
                                            View →
                                        </a>
                                        <?php else: ?>
                                        <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg shadow-xs transition-all duration-150">
                                            View →
                                        </a>
                                        <?php endif; ?>
                                    </div>

                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-10 text-center">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-xl mx-auto mb-2">📭</div>
                                <p class="text-xs font-semibold text-slate-500">No recent reports submitted yet.</p>
                                <p class="text-[11px] text-slate-400 mt-1">Submitted reports approved by instructors will show here for grading.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN (5 COLS): NEEDS ATTENTION / ACTION REQUIRED -->
                    <div class="lg:col-span-5 bg-white rounded-2xl border border-slate-200/70 shadow-xs overflow-hidden flex flex-col justify-between">
                        <div>
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50/80 via-white to-white flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-sm font-bold">
                                        ⚡
                                    </div>
                                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Needs Attention</h3>
                                </div>
                                <span class="text-xs font-bold <?= count($tasks) > 0 ? 'text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full' : 'text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full' ?>">
                                    <?= count($tasks) ?> <?= count($tasks) === 1 ? 'Task' : 'Tasks' ?>
                                </span>
                            </div>

                            <?php if (!empty($tasks)): ?>
                            <div class="divide-y divide-slate-100 max-h-[380px] overflow-y-auto">
                                <?php foreach ($tasks as $task): ?>
                                <div class="p-4 hover:bg-slate-50/70 transition-colors duration-150 flex items-center justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap mb-1">
                                            <span class="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-md border <?= $task['badge_cls'] ?>">
                                                <?= $task['badge'] ?>
                                            </span>
                                        </div>
                                        <p class="text-xs font-bold text-slate-800 truncate"><?= $task['title'] ?></p>
                                        <p class="text-[11px] text-slate-400 truncate mt-0.5"><?= $task['subtitle'] ?></p>
                                    </div>

                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <?php if (!empty($task['can_warn'])): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Send a reminder warning notification to <?= htmlspecialchars($task['title']) ?>?');">
                                            <input type="hidden" name="student_id" value="<?= (int)$task['student_id'] ?>">
                                            <button type="submit" name="send_warning" class="px-2.5 py-1 bg-red-50 hover:bg-red-100 border border-red-200 text-red-700 text-xs font-bold rounded-lg transition cursor-pointer" title="Send Warning Notification">
                                                ⚠️ Warn
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <a href="<?= htmlspecialchars($task['url']) ?>" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-lg transition">
                                            <?= $task['action_label'] ?> →
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-10 text-center">
                                <div class="w-12 h-12 rounded-full bg-emerald-50 flex items-center justify-center text-2xl mx-auto mb-2 text-emerald-600">🎉</div>
                                <p class="text-xs font-bold text-emerald-700">All caught up!</p>
                                <p class="text-[11px] text-slate-400 mt-1">You have no pending reviews, behind-schedule students, or incomplete evaluations.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>
</div>

<?php if (!empty($_GET['warned'])): ?>
<script>
    showToast('Warning reminder notification sent to student.', 'success');
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
