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
        header('Location: supervisor-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
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
// EMAIL ALERT HELPER FUNCTION
// ══════════════════════════════════════════════════════════════════════
function sendRedBadgeAlert($db, $supervisor_id, $supervisor_name, $supervisor_email, $student_id, $student_name, $student_roll, $company_name) {
    $today = date('Y-m-d');
    $today_display = date('l, d M Y');

    // In-app notification with daily deduplication
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

$filter_year = $_GET['year'] ?? ($_GET['academic_year'] ?? '');
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;
$tab = 'dashboard';

require_once __DIR__ . '/../includes/academic_year_helper.php';
ensure_academic_years_table($db);

$filter_year = trim($_GET['year'] ?? ($_GET['academic_year'] ?? ''));

// Fetch academic years from academic_years table
$valid_years = get_academic_years_list($db);
$current_academic_year = get_active_academic_year_label($db);
$selected_year = $filter_year ?: $current_academic_year;

// ── Current Week Boundaries ─────────────────────────────────────────
$today = new DateTime();
$dayOfWeek = (int) $today->format('N');
$weekStart = (clone $today)->modify('monday this week')->format('Y-m-d');
$weekEnd   = (clone $today)->modify('sunday this week')->format('Y-m-d');

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC CARD COUNTS
// ══════════════════════════════════════════════════════════════════════

// 1. ALL STUDENTS: Count active assigned students
$sc = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?");
$sc->bind_param("i", $sup_id);
$sc->execute();
$res = $sc->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);

// 2. COMPANIES: Count distinct companies
$cc = $db->prepare("SELECT COUNT(DISTINCT sp.company_name) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ? AND sp.company_name IS NOT NULL AND sp.company_name != ''");
$cc->bind_param("i", $sup_id);
$cc->execute();
$res = $cc->get_result();
$row = $res ? $res->fetch_row() : null;
$company_count = (int) ($row[0] ?? 0);

// 3. TOTAL REPORTS: Count submitted reports (instructor evaluations) from assigned students
$tr = $db->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
");
$tr->bind_param("i", $sup_id);
$tr->execute();
$res = $tr->get_result();
$row = $res ? $res->fetch_row() : null;
$total_reports = (int) ($row[0] ?? 0);

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC CURRENT WEEK CALCULATION (per student)
// ══════════════════════════════════════════════════════════════════════

if ($filter_year && $filter_year !== 'all') {
    $stu_detail_sql = "
        SELECT u.id AS uid, u.username, u.email, u.academic_year,
               sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
               sp.internship_start_date, sp.internship_end_date,
               sp.instructor_name, sp.instructor_email, sp.instructor_id
        FROM users u
        JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student' AND sp.supervisor_id = ? AND u.academic_year = ?
        ORDER BY sp.full_name ASC
    ";
    $stu_detail_stmt = $db->prepare($stu_detail_sql);
    $stu_detail_stmt->bind_param("is", $sup_id, $filter_year);
} else {
    $stu_detail_sql = "
        SELECT u.id AS uid, u.username, u.email, u.academic_year,
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
}
$stu_detail_stmt->execute();
$res = $stu_detail_stmt->get_result();
$all_students_detail = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$today_obj = new DateTime();
$today_str = $today_obj->format('Y-m-d');

$student_dynamic_week = [];
$student_not_started = [];
$student_progress = [];

foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $not_started = false;

    if ($sd['internship_start_date']) {
        $start_date = $sd['internship_start_date'];
        $end_date   = $sd['internship_end_date'] ?: null;
        $dynamic_week = internship_current_week($start_date, $end_date, $today_obj);

        if ($today_obj < new DateTime($start_date)) {
            $not_started = true;
        }
        elseif ($end_date && $today_obj > new DateTime($end_date)) {
            // Notify supervisor that this student's internship is over (once)
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

    $student_dynamic_week[$uid]  = $dynamic_week;
    $student_not_started[$uid]   = $not_started;
    $student_progress[$uid]      = internship_progress($db, $uid, $sd['internship_start_date'], $sd['internship_end_date']);
}

// ══════════════════════════════════════════════════════════════════════
// PROGRESS STATUS CLASSIFICATION (per student, dynamic week)
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

    if ($sd['internship_start_date']) {
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

// Per-student progress percentage (completed weeks / total internship weeks)
$progress_pct = [];
foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $not_started = $student_not_started[$uid] ?? false;
    $progress_pct[$uid] = $not_started ? 0 : ($student_progress[$uid]['pct'] ?? 0);
}

// ══════════════════════════════════════════════════════════════════════
// STUDENT LIST (for table display)
// ══════════════════════════════════════════════════════════════════════
$students = array_map(function ($sd) {
    return [
        'uid'           => $sd['uid'],
        'username'      => $sd['username'],
        'email'         => $sd['email'],
        'academic_year' => $sd['academic_year'],
        'full_name'     => $sd['full_name'],
        'student_roll'  => $sd['student_roll'],
        'major'         => $sd['major'],
        'company_name'  => $sd['company_name'],
        'job_role'      => $sd['job_role'] ?? '',
        'instructor_name' => $sd['instructor_name'] ?? '',
        'instructor_email' => $sd['instructor_email'] ?? '',
        'instructor_id' => $sd['instructor_id'] ?? null,
    ];
}, $all_students_detail);

// ── Reports count per student ───────────────────────────────────────
$report_counts = [];
if (!empty($all_students_detail)) {
    $ids = array_map(function ($sd) { return (int) $sd['uid']; }, $all_students_detail);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat("i", count($ids));
    $rc_q = $db->prepare("SELECT student_id, COUNT(*) AS cnt FROM report_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $rc_q->bind_param($types, ...$ids);
    $rc_q->execute();
    $res = $rc_q->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $report_counts[(int) $row['student_id']] = (int) $row['cnt'];
        }
    }
}

// ── Unreviewed status per student ───────────────────────────────────
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
foreach ($students as $s) {
    $unrev_q->bind_param("i", $s['uid']);
    $unrev_q->execute();
    $res = $unrev_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $unreviewed[$s['uid']] = (int) ($row[0] ?? 0);
}

// ── Fully evaluated count per student ───────────────────────────────
$evaluated = [];
$eval_q = $db->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    WHERE re.student_id = ?
      AND re.report_status = 'approved_by_supervisor'
");
foreach ($students as $s) {
    $eval_q->bind_param("i", $s['uid']);
    $eval_q->execute();
    $res = $eval_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $evaluated[$s['uid']] = (int) ($row[0] ?? 0);
}

// ══════════════════════════════════════════════════════════════════════
// COHORT ANALYTICS
// ══════════════════════════════════════════════════════════════════════

// 1. Overall attendance rate across all assigned students
$att_q = $db->prepare("
    SELECT
        SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS total_present,
        COUNT(*) AS total_logs
    FROM daily_logs dl
    JOIN users u ON u.id = dl.internship_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
");
$att_q->bind_param("i", $sup_id);
$att_q->execute();
$res = $att_q->get_result();
$att_row = $res ? $res->fetch_assoc() : null;
$cohort_present = (int) ($att_row['total_present'] ?? 0);
$cohort_total_logs = (int) ($att_row['total_logs'] ?? 0);
$cohort_attendance_rate = $cohort_total_logs > 0 ? round(($cohort_present / $cohort_total_logs) * 100) : 0;

// 2. Pending reviews count (approved by instructor, awaiting supervisor grade)
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

// 3. Total graded weeks across all students
$total_graded_q = $db->prepare("
    SELECT COUNT(*) FROM supervisor_weekly_evaluations swe
    JOIN student_profiles sp ON sp.user_id = swe.student_id
    WHERE sp.supervisor_id = ?
");
$total_graded_q->bind_param("i", $sup_id);
$total_graded_q->execute();
$res = $total_graded_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_graded_weeks = (int) ($row[0] ?? 0);

// 4. Grade distribution across all graded weeks
$grade_dist_q = $db->prepare("
    SELECT swe.weekly_grade, COUNT(*) AS cnt
    FROM supervisor_weekly_evaluations swe
    JOIN student_profiles sp ON sp.user_id = swe.student_id
    WHERE sp.supervisor_id = ?
    GROUP BY swe.weekly_grade
");
$grade_dist_q->bind_param("i", $sup_id);
$grade_dist_q->execute();
$res = $grade_dist_q->get_result();
$grade_distribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
if ($res) {
    while ($gr = $res->fetch_assoc()) {
        $grade_distribution[$gr['weekly_grade']] = (int) $gr['cnt'];
    }
}
$avg_grade_points = 0;
$grade_point_map = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'F' => 0];
$total_graded_sum = array_sum($grade_distribution);
if ($total_graded_sum > 0) {
    $weighted_sum = 0;
    foreach ($grade_distribution as $g => $cnt) {
        $weighted_sum += ($grade_point_map[$g] ?? 0) * $cnt;
    }
    $avg_grade_points = round($weighted_sum / $total_graded_sum, 2);
}

// 5. Company breakdown for cohort
$company_q = $db->prepare("
    SELECT sp.company_name, COUNT(*) AS student_count
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
    GROUP BY sp.company_name
    ORDER BY student_count DESC
");
$company_q->bind_param("i", $sup_id);
$company_q->execute();
$res = $company_q->get_result();
$company_breakdown = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// 6. Per-student attendance rates (for table/CSV display)
$stu_att_q = $db->prepare("
    SELECT dl.internship_id,
           SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
           COUNT(*) AS total_count
    FROM daily_logs dl
    JOIN users u ON u.id = dl.internship_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
    GROUP BY dl.internship_id
");
$stu_att_q->bind_param("i", $sup_id);
$stu_att_q->execute();
$res = $stu_att_q->get_result();
$student_attendance = [];
if ($res) {
    while ($sa = $res->fetch_assoc()) {
        $sid = (int) $sa['internship_id'];
        $pc = (int) $sa['present_count'];
        $tc = (int) $sa['total_count'];
        $student_attendance[$sid] = $tc > 0 ? round(($pc / $tc) * 100) : 0;
    }
}

// ══════════════════════════════════════════════════════════════════════
// RECENT REPORTS (from assigned students)
// ══════════════════════════════════════════════════════════════════════
$recent_reports_q = $db->prepare("
    SELECT re.week_number, re.report_status, re.evaluated_at,
           u.id AS student_id, u.username,
           sp.full_name, sp.company_name
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
// MY TASKS & REMINDERS (real pending actions)
// ══════════════════════════════════════════════════════════════════════
$tasks = [];

// a) Pending weekly report reviews (instructor approved, no supervisor grade)
$pending_task_q = $db->prepare("
    SELECT re.week_number, re.evaluated_at, u.id AS student_id, u.username, sp.full_name
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
");
$pending_task_q->bind_param("i", $sup_id);
$pending_task_q->execute();
$res = $pending_task_q->get_result();
if ($res) {
    while ($ptr = $res->fetch_assoc()) {
        $tasks[] = [
            'type'    => 'review',
            'label'   => 'Review weekly report',
            'text'    => htmlspecialchars($ptr['full_name'] ?: $ptr['username']) . ' – Week ' . (int) $ptr['week_number'],
            'url'     => 'supervisor-review.php?student_id=' . (int) $ptr['student_id'] . '&week=' . (int) $ptr['week_number'],
        ];
    }
}

// b) Students behind schedule
foreach ($all_students_detail as $sd) {
    if (($progress_status[$sd['uid']] ?? 'none') === 'red') {
        $tasks[] = [
            'type'    => 'behind',
            'label'   => 'Student behind schedule',
            'text'    => htmlspecialchars($sd['full_name'] ?: $sd['username']) . ' – no logs this week',
            'url'     => 'supervisor-review.php?student_id=' . (int) $sd['uid'],
        ];
    }
}

// c) Final evaluations due (internship ended without full grading)
$final_task_q = $db->prepare("
    SELECT u.id AS student_id, u.username, sp.full_name,
           sp.internship_start_date, sp.internship_end_date,
           (SELECT COUNT(*) FROM supervisor_weekly_evaluations swe WHERE swe.student_id = u.id) AS graded_weeks
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.internship_end_date > '2000-01-01'
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
                'type'    => 'final',
                'label'   => 'Final evaluation',
                'text'    => htmlspecialchars($ftr['full_name'] ?: $ftr['username']) . ' – internship completed (' . $graded . '/' . $ftr_total . ' weeks graded)',
                'url'     => 'supervisor-review.php?student_id=' . (int) $ftr['student_id'],
            ];
        }
    }
}

// ── Filter students by status if filter is selected ────────────────
if ($filter_status && in_array($filter_status, ['red', 'amber', 'green'])) {
    $students = array_filter($students, function ($s) use ($filter_status, $progress_status) {
        return ($progress_status[$s['uid']] ?? 'none') === $filter_status;
    });
    $students = array_values($students);
}

// ── Filter students by search term ─────────────────────────────────
if ($search !== '') {
    $search_lower = strtolower($search);
    $students = array_filter($students, function ($s) use ($search_lower) {
        $name = strtolower($s['full_name'] ?? $s['username'] ?? '');
        $roll = strtolower($s['student_roll'] ?? '');
        $email = strtolower($s['email'] ?? '');
        return str_contains($name, $search_lower) || str_contains($roll, $search_lower) || str_contains($email, $search_lower);
    });
    $students = array_values($students);
}

// ── Pagination ─────────────────────────────────────────────────────
$total_students = count($students);
$total_pages = max(1, (int) ceil($total_students / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$paginated_students = array_slice($students, $offset, $per_page);

// ── CSV Export Handler ─────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d_His') . '.csv"');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Roll No', 'Student Name', 'Email', 'Major', 'Job Role', 'Company', 'Instructor Email', 'Attendance Rate', 'Weekly Status']);

    foreach ($students as $s) {
        $status_label = 'N/A';
        $badge = $progress_status[$s['uid']] ?? 'none';
        if ($badge === 'red') $status_label = 'Behind Schedule';
        elseif ($badge === 'amber') $status_label = 'In Progress';
        elseif ($badge === 'green') $status_label = 'Complete';

        $att_rate = $student_attendance[$s['uid']] ?? null;

        fputcsv($output, [
            $s['student_roll'] ?? '',
            $s['full_name'] ?: $s['username'],
            $s['email'] ?? '',
            $s['major'] ?? '',
            $s['job_role'] ?? '',
            $s['company_name'] ?? '',
            $s['instructor_email'] ?? '',
            $att_rate !== null ? $att_rate . '%' : 'N/A',
            $status_label
        ]);
    }

    fclose($output);
    exit;
}
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
    <style>
        /* Print helper: on-screen hidden, shown only inside @media print below. */
        .print-only { display: none; }

        @media print {
            /* Hide every dashboard element except the My Students print section. */
            body * { visibility: hidden; }
            #my-students-print-section,
            #my-students-print-section * { visibility: visible; }

            #my-students-print-section {
                display: block !important;
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 0;
                color: #000;
                background: #fff;
            }

            /* Belt-and-suspenders: drop major UI chrome as well. */
            aside, header, nav, form, footer, .no-print,
            #profile-dropdown-menu, #notif-dropdown { display: none !important; }

            body { background: #fff !important; }

            @page { margin: 15mm; }

            /* Print-friendly table */
            #my-students-print-section table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
                color: #000;
                background: #fff;
            }
            #my-students-print-section th,
            #my-students-print-section td {
                border: 1px solid #333;
                padding: 7px 10px;
                text-align: left;
                vertical-align: top;
            }
            #my-students-print-section th {
                background: #f1f1f1 !important;
                font-weight: 700;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.04em;
            }
            #my-students-print-section tbody tr:nth-child(even) { background: #fafafa !important; }

            /* Print header */
            #my-students-print-section .print-title {
                font-size: 16px;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                margin: 0 0 6px;
            }
            #my-students-print-section .print-meta {
                font-size: 12px;
                margin: 0 0 2px;
            }
            #my-students-print-section .print-divider {
                border: 0;
                border-top: 2px solid #000;
                margin: 10px 0 14px;
            }
            #my-students-print-section .print-sub { font-size: 10px; color: #555; font-weight: 400; }
            #my-students-print-section .print-empty { font-size: 12px; color: #000; }

            /* Repeat the header row on every printed page; never split a row in half. */
            #my-students-print-section thead { display: table-header-group; }
            #my-students-print-section tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            #my-students-print-section a { text-decoration: none; color: #000; }
        }
        .scroll-margin { scroll-margin-top: 88px; }
        #dashboard-student-search::-webkit-search-cancel-button { -webkit-appearance: none; }
    </style>
    <script>
    function onNotificationItemClick(e, el) {
        e.preventDefault();
        var id = el.getAttribute('data-notif-id');
        var url = el.getAttribute('data-redirect-url') || 'supervisor-dashboard.php';
        var fd = new FormData();
        fd.append('notification_id', id);
        fd.append('mark_notification_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) {
              var badge = document.getElementById('notif-badge');
              if (badge) {
                  if (data.unread_count > 0) badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                  else badge.remove();
              }
              var item = el.closest('[data-notif-id]');
              if (item) {
                  item.classList.remove('bg-[#e7f3ff]');
                  item.querySelector('.unread-dot')?.remove();
              }
          })
          .catch(function() {})
          .finally(function() {
              window.location.href = url;
          });
    }

    function markAllNotifsRead() {
        var fd = new FormData();
        fd.append('mark_all_notifications_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) {
              var badge = document.getElementById('notif-badge');
              if (badge) badge.remove();
              document.querySelectorAll('#notif-dropdown [data-notif-id]').forEach(function(item) {
                  item.classList.remove('bg-[#e7f3ff]');
                  item.querySelector('.unread-dot')?.remove();
              });
          })
          .catch(function() {});
    }

    function showToast(message, type) {
        var toast = document.createElement('div');
        var bgColor, icon;
        switch (type) {
            case 'success': bgColor = 'bg-emerald-600'; icon = '✓'; break;
            case 'error': bgColor = 'bg-red-600'; icon = '✕'; break;
            case 'warning': bgColor = 'bg-amber-500'; icon = '⚠'; break;
            default: bgColor = 'bg-slate-700'; icon = 'ℹ';
        }
        toast.className = 'fixed bottom-6 right-6 z-[1000] ' + bgColor + ' text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300';
        toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)';
        toast.innerHTML = '<span class="text-base">' + icon + '</span> ' + message;
        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; });
        setTimeout(function() {
            toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }
    function toggleProfileDropdown(e) {
        if (e) e.stopPropagation();
        var dd = document.getElementById('profile-dropdown-menu');
        if (dd) dd.classList.toggle('hidden');
        var nd = document.getElementById('notif-dropdown');
        if (nd) {
            nd.style.opacity = '0';
            nd.style.visibility = 'hidden';
            nd.style.transform = 'translateY(-8px) scale(0.95)';
        }
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.classList.contains('hidden') && !dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'dashboard'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Header -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <h1 class="text-base font-bold text-slate-800 hidden sm:block">University Supervisor Dashboard</h1>

                <!-- Search -->
                <div id="dash-search" class="relative flex-1 max-w-xs hidden md:block ml-4 no-print">
                    <form method="GET" class="relative">
                        <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                        <?php if ($filter_year): ?><input type="hidden" name="academic_year" value="<?= htmlspecialchars($filter_year) ?>"><?php endif; ?>
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none">🔍</span>
                        <input type="search" name="search" id="dashboard-student-search" value="<?= htmlspecialchars($search) ?>" placeholder="Search students…" autocomplete="off" spellcheck="false"
                            data-search-url="supervisor-student-search-api.php"
                            class="w-full bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl pl-9 pr-9 py-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200">
                        <button type="button" id="dashboard-search-clear" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold px-1.5 py-0.5 rounded-full hover:bg-slate-200 transition cursor-pointer" title="Clear search">✕</button>
                    </form>
                    <!-- Live search results dropdown -->
                    <div id="dashboard-search-dropdown" class="hidden absolute left-0 right-0 top-full mt-2 bg-white border border-slate-200 rounded-xl shadow-xl z-[1060] overflow-hidden"></div>
                </div>
            </div>

            <div class="flex items-center gap-5 shrink-0">
                <?php if ($tab === 'dashboard'): ?>
                <div class="flex items-center gap-2.5 no-print">
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    </div>
                    <form method="GET" class="flex items-center">
                        <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                        <select name="year" onchange="this.form.submit()" class="bg-white border border-teal-200 text-teal-900 focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-xs font-semibold rounded-lg px-2.5 py-1.5 shadow-sm focus:outline-none transition cursor-pointer">
                            <?= render_academic_year_options($db, $filter_year, true, 'All Academic Years') ?>
                        </select>
                    </form>
                </div>
                <?php endif; ?>

                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                    <!-- Notification Bell -->
                    <div class="relative" id="notif-bell-wrapper">
                        <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-white/30 rounded-xl transition cursor-pointer">
                            <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <?php if ($unread_notif_count > 0): ?>
                            <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                            <?php endif; ?>
                        </button>

                        <!-- Notification Dropdown -->
                        <div id="notif-dropdown" class="absolute right-0 top-full mt-1 w-[22rem] bg-white border border-slate-200 rounded-xl shadow-xl z-[1060] overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-gradient-to-br from-blue-50/80 to-white/60">
                                <h4 class="text-sm font-black text-slate-700">Notifications</h4>
                                <?php if ($unread_notif_count > 0): ?>
                                <button onclick="markAllNotifsRead()" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition cursor-pointer">Mark all read</button>
                                <?php endif; ?>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php if (!empty($recent_notifications)): ?>
                                <?php foreach ($recent_notifications as $notif): ?>
                                <?php $notif_url = notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null); ?>
                                <div class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)">
                                    <?php if ($notif['type'] === 'instructor_approved'): ?>
                                    <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                    <?php elseif ($notif['type'] === 'instructor_rejected'): ?>
                                    <div class="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </div>
                                    <?php else: ?>
                                    <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                    </div>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm <?= !$notif['is_read'] ? 'font-bold text-slate-800' : 'font-medium text-slate-600' ?> leading-snug"><?= htmlspecialchars($notif['title']) ?></p>
                                        <p class="text-xs text-slate-500 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                                        <p class="text-[11px] text-slate-400 mt-1.5" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                                        <?php if (!$notif['is_read']): ?>
                                        <span class="unread-dot w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm"></span>
                                        <?php endif; ?>
                                        <div class="relative">
                                            <button onclick="event.stopPropagation(); toggleNotifOptions(this)" class="w-7 h-7 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 transition opacity-0 group-hover:opacity-100 cursor-pointer" title="More options">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                                            </button>
                                            <div class="hidden absolute right-0 top-full mt-1 w-44 bg-white border border-slate-200 rounded-xl shadow-lg z-50 py-1.5 notif-options-menu" onclick="event.stopPropagation();">
                                                <?php if (!$notif['is_read']): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                                    <button type="submit" name="mark_notification_read" class="w-full text-left px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition flex items-center gap-2.5 cursor-pointer">
                                                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                        Mark as read
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <div class="px-4 py-2.5 text-xs font-medium text-slate-400 flex items-center gap-2.5">
                                                    <svg class="w-3.5 h-3.5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    Already read
                                                </div>
                                                <?php endif; ?>
                                                <div class="my-1 border-t border-slate-100"></div>
                                                <button type="button" onclick="requestDeleteNotification(<?= (int)$notif['id'] ?>)" class="w-full text-left px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition flex items-center gap-2.5 cursor-pointer">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div class="p-10 text-center">
                                    <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                                        <svg class="w-7 h-7 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-400">No notifications yet</p>
                                    <p class="text-xs text-slate-300 mt-1">You'll see updates here</p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="border-t border-slate-100">
                                <a href="notifications.php" class="flex items-center justify-center gap-2 px-4 py-3 text-xs font-bold text-blue-600 hover:bg-blue-50 transition">View all notifications</a>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative shrink-0" id="profileDropdownContainer">
                        <button
                            type="button"
                            onclick="toggleProfileDropdown(event)"
                            id="profile-avatar-btn"
                            class="flex items-center gap-2.5 p-1.5 hover:bg-teal-50 border border-transparent hover:border-teal-100 rounded-xl transition-all cursor-pointer group"
                        >
                            <?php if (!empty($_SESSION['profile_pic'])): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover border border-teal-200 shadow-sm">
                            <?php else: ?>
                            <div class="w-9 h-9 rounded-xl bg-teal-700 flex items-center justify-center font-bold text-sm text-white shadow-sm">
                                <?= strtoupper(substr($_SESSION['username'] ?? 'S', 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-left hidden sm:block">
                                <p class="font-semibold text-sm text-slate-800 leading-tight"><?= htmlspecialchars($sup_name) ?></p>
                                <p class="text-xs font-medium text-teal-700 capitalize">Supervisor</p>
                            </div>
                            <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Profile Dropdown Menu -->
                        <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-lg border border-teal-100 py-1.5 z-50 divide-y divide-slate-100">
                            <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                                <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> My Profile
                            </a>
                            <a href="profile.php#security-section" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                                <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> Change Password
                            </a>
                            <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                                <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

<?php if ($tab === 'dashboard'): ?>
        <!-- ════ DASHBOARD CONTENT ════ -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- ═══ WELCOME SECTION ═══ -->
                <section class="bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-700 rounded-3xl p-8 text-white shadow-xl shadow-indigo-500/20 relative overflow-hidden">
                    <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" viewBox=\"0 0 60 60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cg fill=\"none\" fill-rule=\"evenodd\"%3E%3Cg fill=\"%23ffffff\" fill-opacity=\"0.06\"%3E%3Cpath d=\"M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')]"></div>
                    <div class="relative flex flex-wrap items-center justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="w-16 h-16 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center text-3xl border border-white/20 shadow-lg">👋</div>
                            <div>
                                <p class="text-sm font-semibold text-indigo-200 uppercase tracking-wider"><?= date('l, d F Y') ?></p>
                                <h1 class="text-2xl lg:text-3xl font-black mt-1">Welcome back, <?= htmlspecialchars($sup_name) ?>!</h1>
                                <p class="text-sm text-indigo-100/90 mt-2 max-w-xl">Here's what's happening with your assigned interns today. Review reports, track progress, and keep everything on schedule.</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-3 text-center">
                                <p class="text-xs font-bold text-indigo-200 uppercase tracking-wider">Calendar Week</p>
                                <p class="text-sm font-bold mt-1"><?= (new DateTime('monday this week'))->format('d M') ?> – <?= (new DateTime('sunday this week'))->format('d M Y') ?></p>
                            </div>
                            <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-3 text-center">
                                <p class="text-xs font-bold text-indigo-200 uppercase tracking-wider">Academic Year</p>
                                <select onchange="location = this.value;" class="mt-1 bg-white/20 text-sm font-bold text-white rounded-lg px-2.5 py-1 border border-white/30 focus:outline-none focus:ring-2 focus:ring-white/50 cursor-pointer">
                                    <option value="?<?= http_build_query(array_merge($_GET, ['year' => '', 'academic_year' => ''])) ?>" class="text-slate-800" <?= !$filter_year ? 'selected' : '' ?>>All Years</option>
                                    <?php foreach ($valid_years as $vy): ?>
                                    <option value="?<?= http_build_query(array_merge($_GET, ['year' => $vy, 'academic_year' => $vy])) ?>" class="text-slate-800" <?= $filter_year === $vy ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vy) ?><?= $vy === $current_academic_year ? ' (Current)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ═══ STATISTICS CARDS ═══ -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <a href="my-students.php" class="group bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300 block">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center text-xl shadow-lg shadow-indigo-500/30">🎓</div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">My Students</p>
                                <p class="text-2xl font-black text-slate-800"><?= $total_assigned ?></p>
                                <p class="text-sm text-indigo-500 font-bold truncate"><?= $selected_year ? htmlspecialchars($selected_year) : 'All years' ?></p>
                            </div>
                            <span class="ml-auto text-xs font-bold text-indigo-600 bg-indigo-50 group-hover:bg-indigo-100 border border-indigo-200/60 px-2.5 py-1 rounded-lg shrink-0 transition-all duration-200">View All →</span>
                        </div>
                    </a>
                    <a href="supervisor-reports.php" class="group bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300 block">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-xl shadow-lg shadow-purple-500/30">📄</div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Total Reports</p>
                                <p class="text-2xl font-black text-slate-800"><?= $total_reports ?></p>
                                <p class="text-sm text-purple-500 font-bold truncate">Submitted by your students</p>
                            </div>
                            <span class="ml-auto text-xs font-bold text-purple-600 bg-purple-50 group-hover:bg-purple-100 border border-purple-200/60 px-2.5 py-1 rounded-lg shrink-0 transition-all duration-200">View Reports →</span>
                        </div>
                    </a>
                    <a href="supervisor-companies.php" class="group bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300 block">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xl shadow-lg shadow-blue-500/30">🏢</div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Companies</p>
                                <p class="text-2xl font-black text-slate-800"><?= $company_count ?></p>
                                <p class="text-sm text-blue-500 font-bold truncate">Active placements</p>
                            </div>
                            <span class="ml-auto text-xs font-bold text-blue-600 bg-blue-50 group-hover:bg-blue-100 border border-blue-200/60 px-2.5 py-1 rounded-lg shrink-0 transition-all duration-200">View Companies →</span>
                        </div>
                    </a>
                    <a href="supervisor-reports.php?status=approved_by_instructor" class="group bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300 block">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30">📩</div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Pending Reviews</p>
                                <p class="text-2xl font-black text-slate-800"><?= $pending_reviews ?></p>
                                <p class="text-sm <?= $pending_reviews > 0 ? 'text-amber-500' : 'text-slate-400' ?> font-bold truncate">Reports awaiting your review</p>
                            </div>
                            <span class="ml-auto text-xs font-bold text-amber-600 bg-amber-50 group-hover:bg-amber-100 border border-amber-200/60 px-2.5 py-1 rounded-lg shrink-0 transition-all duration-200">Review Reports →</span>
                        </div>
                    </a>
                </div>

                <!-- ═══ MY STUDENTS ═══ -->
                <div id="my-students" class="scroll-margin bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between flex-wrap gap-4 no-print">
                        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-500 flex items-center justify-center text-sm">🎓</span> My Students
                            <span class="ml-2 text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-lg border border-indigo-200/60"><?= $total_assigned ?> assigned</span>
                        </h2>
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Status Filter Chips -->
                            <a href="?<?= http_build_query(array_merge($_GET, ['status' => '', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === '' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">All</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'red', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'red' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-red-600 border-red-200 hover:bg-red-50' ?>">🔴 Behind</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'amber', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'amber' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-amber-600 border-amber-200 hover:bg-amber-50' ?>">🟡 In Progress</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'green', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'green' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-emerald-600 border-emerald-200 hover:bg-emerald-50' ?>">🟢 Complete</a>
                            <?php if ($total_assigned > 0): ?>
                            <button onclick="window.print()" class="no-print inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white text-xs font-bold rounded-xl transition-all duration-200 shadow-sm cursor-pointer">🖨️ Print</button>
                            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="no-print inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all duration-200">⬇️ Export</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($paginated_students)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                    <th class="px-5 py-3 text-left">Student</th>
                                    <th class="px-5 py-3 text-left">Company</th>
                                    <th class="px-5 py-3 text-left">Internship Role</th>
                                    <th class="px-5 py-3 text-left">Progress</th>
                                    <th class="px-5 py-3 text-left">Reports</th>
                                    <th class="px-5 py-3 text-left">Status</th>
                                    <th class="px-5 py-3 text-left">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($paginated_students as $s): ?>
                                <?php
                                    $uid = $s['uid'];
                                    $st = $progress_status[$uid] ?? 'none';
                                    [$status_label, $status_classes] = progress_status_label($st);
                                    $dot = progress_status_dot($st);
                                    $pct = $progress_pct[$uid] ?? 0;
                                    $rep_count = $report_counts[$uid] ?? 0;
                                    $ur = $unreviewed[$uid] ?? 0;
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                    <td class="px-5 py-4">
                                        <a href="view-student-dashboard.php?id=<?= $uid ?>" class="flex items-center gap-3 hover:opacity-80 transition">
                                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shrink-0 shadow-md shadow-indigo-500/20">
                                                <?= strtoupper(($s['full_name'] ?: $s['username'])[0]) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-800 hover:text-indigo-600 transition"><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></p>
                                                <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($s['student_roll'] ?: $s['email']) ?></p>
                                            </div>
                                        </a>
                                    </td>
                                    <td class="px-5 py-4 text-slate-600 max-w-[160px] truncate font-medium" title="<?= htmlspecialchars($s['company_name'] ?? '') ?>"><?= htmlspecialchars($s['company_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-slate-600 font-medium text-xs max-w-[150px] truncate" title="<?= htmlspecialchars($s['job_role'] ?? '') ?>"><?= htmlspecialchars($s['job_role'] ?: '—') ?></td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-2 min-w-[110px]">
                                            <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                                                <div class="h-2 rounded-full bg-gradient-to-r <?= progress_bar_color($pct) ?> transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                            </div>
                                            <span class="text-xs font-bold text-slate-600"><?= $pct ?>%</span>
                                        </div>
                                        <p class="text-[11px] text-slate-400 mt-1"><?= $student_not_started[$uid] ?? false ? 'Not started' : 'Week ' . ($student_progress[$uid]['completed'] ?? 0) . '/' . ($student_progress[$uid]['total'] ?? 0) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg">
                                            📄 <?= $rep_count ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold <?= $status_classes ?> px-2.5 py-1 rounded-lg border">
                                            <span class="w-2 h-2 rounded-full <?= $dot ?>"></span>
                                            <?= $status_label ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-2">
                                            <a href="view-student-dashboard.php?id=<?= $uid ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-indigo-500 to-purple-600 text-white text-sm font-bold rounded-lg hover:from-indigo-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-indigo-500/20">
                                                👁️ View
                                            </a>
                                            <?php if ($ur > 0): ?>
                                            <a href="supervisor-review.php?student_id=<?= $uid ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-bold rounded-lg transition-all duration-200 shadow-sm" title="<?= $ur ?> report(s) awaiting your grade">
                                                📩 Grade (<?= $ur ?>)
                                            </a>
                                            <?php elseif (($evaluated[$uid] ?? 0) > 0): ?>
                                            <a href="supervisor-review.php?student_id=<?= $uid ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white text-sm font-bold rounded-lg transition-all duration-200 shadow-sm" title="View graded reports">
                                                ✅ Review
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($st === 'red'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Send a warning notification to <?= htmlspecialchars($s['full_name'] ?: $s['username']) ?>?');">
                                                <input type="hidden" name="student_id" value="<?= $uid ?>">
                                                <button type="submit" name="send_warning" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-lg transition-all duration-200 shadow-sm" title="Send Warning">⚠️</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <p class="text-sm text-slate-400 font-medium">
                                Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_students) ?> of <?= $total_students ?> student(s)
                                <?php if ($search): ?>matching "<span class="font-bold text-slate-600"><?= htmlspecialchars($search) ?></span>"<?php endif; ?>
                            </p>
                            <div class="flex items-center gap-1.5">
                                <?php
                                $base_params = $_GET;
                                unset($base_params['page']);
                                $base_query = http_build_query($base_params);
                                $sep = $base_query ? '&' : '';
                                ?>
                                <?php if ($page > 1): ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $page - 1 ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200 shadow-sm">← Prev</a>
                                <?php else: ?>
                                <span class="px-3 py-1.5 text-xs font-bold text-slate-300 bg-slate-50 border border-slate-200 rounded-lg cursor-not-allowed">← Prev</span>
                                <?php endif; ?>

                                <?php
                                $range = 2;
                                $start_page = max(1, $page - $range);
                                $end_page = min($total_pages, $page + $range);
                                ?>
                                <?php if ($start_page > 1): ?>
                                <a href="?<?= $base_query . $sep ?>page=1" class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200">1</a>
                                <?php if ($start_page > 2): ?><span class="text-slate-400 text-xs">…</span><?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <?php if ($i === $page): ?>
                                <span class="w-8 h-8 flex items-center justify-center text-xs font-bold text-white bg-indigo-500 border border-indigo-500 rounded-lg shadow-md shadow-indigo-500/30"><?= $i ?></span>
                                <?php else: ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $i ?>" class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200"><?= $i ?></a>
                                <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?><span class="text-slate-400 text-xs">…</span><?php endif; ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $total_pages ?>" class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200"><?= $total_pages ?></a>
                                <?php endif; ?>

                                <?php if ($page < $total_pages): ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $page + 1 ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200 shadow-sm">Next →</a>
                                <?php else: ?>
                                <span class="px-3 py-1.5 text-xs font-bold text-slate-300 bg-slate-50 border border-slate-200 rounded-lg cursor-not-allowed">Next →</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="px-6 py-3 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <p class="text-sm text-slate-400 font-medium">
                            Showing all <?= $total_students ?> student(s)
                            <?php if ($search): ?>matching "<span class="font-bold text-slate-600"><?= htmlspecialchars($search) ?></span>"<?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <!-- Empty State -->
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm text-slate-500 font-medium">
                            <?php if ($search): ?>
                                No students found matching "<strong><?= htmlspecialchars($search) ?></strong>".
                            <?php elseif ($filter_year): ?>
                                No students assigned to you for <?= htmlspecialchars($filter_year) ?>.
                            <?php elseif ($filter_status): ?>
                                No students with status "<?= $filter_status === 'red' ? 'Behind Schedule' : ($filter_status === 'amber' ? 'In Progress' : 'Complete') ?>".
                            <?php else: ?>
                                You don't have any assigned students yet for <?= $selected_year ? htmlspecialchars($selected_year) : 'this academic year' ?>.
                            <?php endif; ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-2">Once students are assigned to you, they will appear here automatically.</p>
                        <?php if ($search || $filter_year || $filter_status): ?>
                        <a href="supervisor-dashboard.php" class="mt-4 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear all filters</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ═══ RECENT REPORTS + MY TASKS ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Recent Reports -->
                    <div id="recent-reports" class="scroll-margin lg:col-span-2 bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-purple-50 text-purple-500 flex items-center justify-center text-sm">📄</span> Recent Reports
                            </h2>
                            <a href="supervisor-reports.php" class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-purple-50 hover:bg-purple-100 border border-purple-200/60 px-2.5 py-1 rounded-lg transition-all duration-200">View All →</a>
                        </div>

                        <?php if (!empty($recent_reports)): ?>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($recent_reports as $rep):
                                $rep_student = $rep['full_name'] ?: $rep['username'];
                            ?>
                            <div class="flex items-center gap-4 px-6 py-4 hover:bg-slate-50/60 transition-colors duration-150">
                                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-md shadow-purple-500/20">
                                    <?= strtoupper(substr($rep_student, 0, 1)) ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-slate-800 truncate">Weekly Report – Week <?= (int)$rep['week_number'] ?></p>
                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                        <span class="text-xs text-slate-500 font-medium"><?= htmlspecialchars($rep_student) ?></span>
                                        <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                                        <span class="text-xs text-blue-600 font-medium truncate"><?= htmlspecialchars($rep['company_name'] ?: '—') ?></span>
                                    </div>
                                    <p class="text-[11px] text-slate-400 mt-1">Submitted <?= htmlspecialchars((new DateTime($rep['evaluated_at']))->format('d M Y, h:i A')) ?></p>
                                </div>
                                <div class="shrink-0">
                                    <?php if ($rep['report_status'] === 'approved_by_instructor'): ?>
                                    <span class="hidden sm:inline-flex items-center gap-1 text-xs font-bold text-amber-600 bg-amber-50 border border-amber-200 px-2 py-1 rounded-lg mr-2">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Awaiting grade
                                    </span>
                                    <?php elseif ($rep['report_status'] === 'approved_by_supervisor'): ?>
                                    <span class="hidden sm:inline-flex items-center gap-1 text-xs font-bold text-emerald-600 bg-emerald-50 border border-emerald-200 px-2 py-1 rounded-lg mr-2">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Graded
                                    </span>
                                    <?php endif; ?>
                                    <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-xs font-bold rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-purple-500/20">
                                        🔍 Review
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="p-12 text-center">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                            <p class="text-sm text-slate-500 font-medium">No reports submitted by your students yet.</p>
                            <p class="text-xs text-slate-400 mt-1">Submitted weekly reports will appear here for review.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- My Tasks & Reminders -->
                    <div id="tasks" class="scroll-margin bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-sm">⏰</span> My Tasks &amp; Reminders
                            </h2>
                            <span class="text-xs <?= count($tasks) > 0 ? 'text-amber-500' : 'text-emerald-500' ?> font-bold"><?= count($tasks) ?> pending</span>
                        </div>

                        <?php if (!empty($tasks)): ?>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($tasks as $task):
                                if ($task['type'] === 'review') {
                                    $t_icon = '📩'; $t_badge = 'text-emerald-600 bg-emerald-50 border-emerald-200';
                                } elseif ($task['type'] === 'behind') {
                                    $t_icon = '🔴'; $t_badge = 'text-red-600 bg-red-50 border-red-200';
                                } else {
                                    $t_icon = '🎓'; $t_badge = 'text-indigo-600 bg-indigo-50 border-indigo-200';
                                }
                            ?>
                            <div class="flex items-center gap-3 px-6 py-3.5 hover:bg-slate-50/60 transition-colors duration-150">
                                <div class="w-9 h-9 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-base shrink-0"><?= $t_icon ?></div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($task['label']) ?></p>
                                    <p class="text-[11px] text-slate-500 mt-0.5 truncate"><?= $task['text'] ?></p>
                                </div>
                                <a href="<?= $task['url'] ?>" class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold <?= $t_badge ?> border rounded-lg hover:shadow-sm transition-all duration-200">
                                    View →
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="p-12 text-center">
                            <div class="w-16 h-16 rounded-full bg-emerald-50 flex items-center justify-center text-3xl mx-auto mb-4">🎉</div>
                            <p class="text-sm font-semibold text-emerald-600">All caught up!</p>
                            <p class="text-xs text-slate-400 mt-1">You have no pending reviews or reminders right now.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ ANALYTICS + COMPANIES ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Internship Progress Overview -->
                    <div class="lg:col-span-1 bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📊</span> Internship Progress
                            </h2>
                            <span class="text-xs text-slate-400 font-medium"><?= count($all_students_detail) ?> student(s)</span>
                        </div>
                        <?php if (!empty($all_students_detail)): ?>
                        <div class="p-5 space-y-4 max-h-[420px] overflow-y-auto">
                            <?php foreach ($all_students_detail as $sd):
                                $uid = $sd['uid'];
                                $pct = $progress_pct[$uid] ?? 0;
                                $ns = $student_not_started[$uid] ?? false;
                                $nm = $sd['full_name'] ?: $sd['username'];
                                $wcomp = $student_progress[$uid]['completed'] ?? 0;
                                $wtot = $student_progress[$uid]['total'] ?? 0;
                            ?>
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($nm) ?></p>
                                    <p class="text-xs font-black <?= $pct >= 80 ? 'text-emerald-600' : ($pct >= 40 ? 'text-indigo-600' : 'text-amber-600') ?>"><?= $pct ?>%</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                        <div class="h-2.5 rounded-full bg-gradient-to-r <?= progress_bar_color($pct) ?> transition-all duration-700" style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-[11px] font-bold text-slate-400 shrink-0 w-14 text-right"><?= $ns ? '—' : 'Wk ' . $wcomp . '/' . $wtot ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="px-6 py-3 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                            <div class="flex items-center justify-center gap-4 flex-wrap">
                                <span class="flex items-center gap-1.5 text-[11px] font-bold text-red-600"><span class="w-2 h-2 rounded-full bg-red-500"></span> <?= $behind_schedule ?> Behind</span>
                                <span class="flex items-center gap-1.5 text-[11px] font-bold text-amber-600"><span class="w-2 h-2 rounded-full bg-amber-500"></span> <?= $in_progress ?> In Progress</span>
                                <span class="flex items-center gap-1.5 text-[11px] font-bold text-emerald-600"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> <?= $complete ?> Complete</span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="p-12 text-center">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📊</div>
                            <p class="text-sm text-slate-500 font-medium">No internship progress data.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Grade Distribution -->
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">🏆</span> Grade Distribution
                            </h2>
                            <span class="text-xs text-slate-400 font-medium"><?= $total_graded_weeks ?> graded</span>
                        </div>
                        <div class="p-5">
                            <?php if ($total_graded_sum > 0): ?>
                            <div class="space-y-3">
                                <?php foreach (['A' => 'Excellent', 'B' => 'Good', 'C' => 'Satisfactory', 'D' => 'Pass', 'F' => 'Fail'] as $g => $label):
                                    $cnt = $grade_distribution[$g] ?? 0;
                                    $pct = $total_graded_sum > 0 ? round(($cnt / $total_graded_sum) * 100) : 0;
                                    $bar_colors = ['A' => 'from-emerald-500 to-emerald-600', 'B' => 'from-blue-500 to-blue-600', 'C' => 'from-amber-500 to-amber-600', 'D' => 'from-orange-500 to-orange-600', 'F' => 'from-red-500 to-red-600'];
                                    $text_colors = ['A' => 'text-emerald-700', 'B' => 'text-blue-700', 'C' => 'text-amber-700', 'D' => 'text-orange-700', 'F' => 'text-red-700'];
                                ?>
                                <div class="flex items-center gap-3">
                                    <span class="w-6 text-sm font-black <?= $text_colors[$g] ?> text-center"><?= $g ?></span>
                                    <div class="flex-1 bg-slate-100 rounded-full h-5 overflow-hidden">
                                        <div class="bg-gradient-to-r <?= $bar_colors[$g] ?> h-5 rounded-full transition-all duration-500 flex items-center justify-end pr-2" style="width: <?= $pct ?>%">
                                            <?php if ($pct > 8): ?><span class="text-sm font-bold text-white"><?= $cnt ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold text-slate-500 w-10 text-right"><?= $pct ?>%</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-sm text-slate-400 font-medium"><?= $total_graded_sum ?> graded week(s)</span>
                                <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-200/60">GPA: <?= number_format($avg_grade_points, 2) ?>/4.00</span>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-3">🏆</div>
                                <p class="text-xs text-slate-400 font-medium">No grades submitted yet.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Companies -->
                    <div id="companies" class="scroll-margin bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">🏢</span> Companies
                            </h2>
                            <a href="supervisor-companies.php" class="inline-flex items-center gap-1 text-xs font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 border border-blue-200/60 px-2.5 py-1 rounded-lg transition-all duration-200"><?= count($company_breakdown) ?> active · View All →</a>
                        </div>
                        <div class="p-5">
                            <?php if (!empty($company_breakdown)): ?>
                            <div class="space-y-2.5 max-h-72 overflow-y-auto">
                                <?php foreach ($company_breakdown as $cb): ?>
                                <div class="flex items-center gap-3 p-3 bg-gradient-to-r from-slate-50 to-white border border-slate-100 rounded-xl hover:border-blue-200 hover:shadow-sm transition-all duration-200">
                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xs font-bold shrink-0 shadow-md shadow-blue-500/20">
                                        <?= strtoupper(substr($cb['company_name'], 0, 2)) ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($cb['company_name']) ?></p>
                                    </div>
                                    <span class="text-sm font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200/60 shrink-0"><?= (int)$cb['student_count'] ?> student<?= (int)$cb['student_count'] !== 1 ? 's' : '' ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-3">🏢</div>
                                <p class="text-xs text-slate-400 font-medium">No company data available.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    <?php endif; ?>
    </div>
</div>

<?php if ($tab === 'dashboard'): ?>
<!-- ═══ PRINT-ONLY: MY STUDENTS TABLE ═══ -->
<div id="my-students-print-section" class="print-only">
    <h1 class="print-title">Internship Report Management System</h1>
    <p class="print-meta"><strong>MY STUDENTS</strong></p>
    <p class="print-meta">Supervisor: <?= htmlspecialchars($sup_name) ?></p>
    <p class="print-meta">Academic Year: <?= htmlspecialchars($selected_year ?: '—') ?></p>
    <hr class="print-divider">

    <?php if (!empty($students)): ?>
    <table>
        <thead>
            <tr>
                <th>Student</th>
                <th>Company</th>
                <th>Internship Role</th>
                <th>Progress</th>
                <th>Reports</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $s):
                $uid = $s['uid'];
                $st = $progress_status[$uid] ?? 'none';
                [$status_label] = progress_status_label($st);
                $pct = $progress_pct[$uid] ?? 0;
                $rep_count = $report_counts[$uid] ?? 0;
                $wc = $student_progress[$uid]['completed'] ?? 0;
                $wt = $student_progress[$uid]['total'] ?? 0;
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></strong>
                    <?php if (!empty($s['student_roll']) || !empty($s['email'])): ?>
                    <br><span class="print-sub"><?= htmlspecialchars($s['student_roll'] ?: $s['email']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($s['company_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($s['job_role'] ?: '—') ?></td>
                <td>
                    <?= $pct ?>%
                    <?php if ($wt > 0): ?><br><span class="print-sub">Week <?= $wc ?>/<?= $wt ?></span><?php endif; ?>
                </td>
                <td><?= $rep_count ?></td>
                <td><?= htmlspecialchars($status_label) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="print-empty">No students to display.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($_GET['warned'])): ?>
<script>
    showToast('Warning sent to student.', 'success');
</script>
<?php endif; ?>

<script>
(function() {
    var input = document.getElementById('dashboard-student-search');
    if (!input) return;

    var wrapper   = document.getElementById('dash-search');
    var dropdown  = document.getElementById('dashboard-search-dropdown');
    var clearBtn  = document.getElementById('dashboard-search-clear');
    var searchUrl = input.getAttribute('data-search-url') || 'supervisor-student-search-api.php';

    var debounceTimer = null;
    var requestSeq    = 0;
    var results       = [];
    var activeIndex   = -1;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function showDropdown(html) {
        dropdown.innerHTML = html;
        dropdown.classList.remove('hidden');
    }

    function hideDropdown() {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
        results = [];
        activeIndex = -1;
    }

    function doSearch(q) {
        q = q.trim();
        if (q === '') { hideDropdown(); return; }
        var seq = ++requestSeq;
        fetch(searchUrl + '?q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (seq !== requestSeq || input.value.trim() !== q) return;
            results = data.results || [];
            activeIndex = results.length ? 0 : -1;
            renderDropdown(q, !!data.has_more);
        })
        .catch(function() {});
    }

    function renderDropdown(q, hasMore) {
        var html = '';

        if (results.length === 0) {
            html += '<div class="px-4 py-6 text-center">'
                  +   '<div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-lg mx-auto mb-2">🔍</div>'
                  +   '<p class="text-sm font-semibold text-slate-500">No students found</p>'
                  +   '<p class="text-xs text-slate-400 mt-1">No assigned student matches "' + esc(q) + '".</p>'
                  + '</div>';
        } else {
            html += '<div class="py-1">';
            results.forEach(function(r, i) {
                var cls = i === activeIndex ? 'bg-indigo-50' : '';
                var subtitle = esc(r.student_roll || '—');
                if (r.company_name) subtitle += ' · ' + esc(r.company_name);
                html += '<a href="view-student-dashboard.php?id=' + r.uid + '" data-index="' + i + '" class="flex items-center gap-3 px-3 py-2.5 hover:bg-indigo-50 transition ' + cls + '">'
                      +   '<div class="w-8 h-8 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shrink-0">' + esc(r.initials) + '</div>'
                      +   '<div class="min-w-0 flex-1">'
                      +     '<p class="text-sm font-semibold text-slate-800 truncate">' + esc(r.full_name || r.username) + '</p>'
                      +     '<p class="text-xs text-slate-400 truncate">' + subtitle + '</p>'
                      +   '</div>'
                      +   '<span class="text-slate-300 shrink-0">→</span>'
                      + '</a>';
            });
            html += '</div>';
            if (hasMore) {
                html += '<a href="my-students.php?search=' + encodeURIComponent(q) + '" class="block text-center text-xs font-bold text-indigo-600 bg-gradient-to-r from-indigo-50/60 to-purple-50/60 hover:bg-indigo-100 py-2.5 border-t border-slate-100 transition">View all matching students →</a>';
            }
        }

        showDropdown(html);
    }

    function rehighlight() {
        var anchors = dropdown.querySelectorAll('a[data-index]');
        anchors.forEach(function(a, i) {
            a.classList.toggle('bg-indigo-50', i === activeIndex);
        });
    }

    function updateClearBtn() {
        clearBtn.classList.toggle('hidden', input.value.trim() === '');
    }

    input.addEventListener('input', function() {
        updateClearBtn();
        clearTimeout(debounceTimer);
        var q = input.value;
        if (q.trim() === '') { hideDropdown(); return; }
        debounceTimer = setTimeout(function() { doSearch(q); }, 250);
    });

    input.addEventListener('focus', function() {
        updateClearBtn();
        var q = input.value;
        if (q.trim() !== '') doSearch(q);
    });

    input.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (results.length) { activeIndex = (activeIndex + 1) % results.length; rehighlight(); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (results.length) { activeIndex = (activeIndex - 1 + results.length) % results.length; rehighlight(); }
        } else if (e.key === 'Enter') {
            if (results.length && activeIndex >= 0 && results[activeIndex]) {
                e.preventDefault();
                window.location.href = 'view-student-dashboard.php?id=' + results[activeIndex].uid;
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            hideDropdown();
            input.blur();
        }
    });

    clearBtn.addEventListener('click', function() {
        input.value = '';
        hideDropdown();
        updateClearBtn();
        input.focus();
        var params = new URLSearchParams(window.location.search);
        if (params.has('search')) {
            var parts = [];
            params.forEach(function(v, k) {
                if (k !== 'search' && k !== 'page' && v !== '') parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
            });
            window.location.href = window.location.pathname + (parts.length ? '?' + parts.join('&') : '');
        }
    });

    document.addEventListener('click', function(e) {
        if (!wrapper.contains(e.target)) hideDropdown();
    });

    updateClearBtn();
})();
</script>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
