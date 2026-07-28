<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';
require_once __DIR__ . '/../includes/notification_helper.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

// Get supervisor email for alerts
$sup_email_q = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$sup_email_q->execute([$sup_id]);
$sup_email = $sup_email_q->fetchColumn();

// ══════════════════════════════════════════════════════════════════════
// WARNING NOTIFICATION HANDLER
// When supervisor clicks "Send Warning", set is_warned = 1 for that student
// ══════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_warning'])) {
    $warn_student_id = (int) ($_POST['student_id'] ?? 0);
    if ($warn_student_id > 0) {
        $warn_q = $pdo->prepare("UPDATE users SET is_warned = 1 WHERE id = ? AND role = 'student'");
        $warn_q->execute([$warn_student_id]);
        header('Location: supervisor-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
}

// ── Mark notification as read ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    $notif_id = (int)($_POST['notification_id'] ?? 0);
    if ($notif_id > 0) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notif_id, $sup_id]);
    }
    header('Location: supervisor-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    header('Location: supervisor-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ── Fetch notifications ─────────────────────────────────────────
$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

// ══════════════════════════════════════════════════════════════════════
// EMAIL ALERT HELPER FUNCTION
// ══════════════════════════════════════════════════════════════════════
function sendRedBadgeAlert($pdo, $supervisor_id, $supervisor_name, $supervisor_email, $student_id, $student_name, $student_roll, $company_name) {
    $today = date('Y-m-d');
    $today_display = date('l, d M Y');

    // Check if alert already sent today for this student
    $check = $pdo->prepare("SELECT id FROM supervisor_alerts WHERE supervisor_id = ? AND student_id = ? AND alert_type = 'red_badge' AND alert_date = ?");
    $check->execute([$supervisor_id, $student_id, $today]);
    if ($check->fetch()) {
        return false; // Already sent today
    }

    // Email subject and body
    $subject = "⚠️ Student Behind Schedule Alert - " . $student_name;
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 20px; border-radius: 10px 10px 0 0; }
            .content { background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb; }
            .alert-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 15px; margin: 15px 0; }
            .footer { background: #1e293b; color: #94a3b8; padding: 15px; border-radius: 0 0 10px 10px; text-align: center; font-size: 0.8125rem; }
            .btn { display: inline-block; background: #6366f1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2 style='margin:0;'>⚠️ Student Behind Schedule Alert</h2>
                <p style='margin:5px 0 0; opacity:0.9;'>InternReport System Notification</p>
            </div>
            <div class='content'>
                <p>Dear <strong>" . htmlspecialchars($supervisor_name) . "</strong>,</p>
                
                <div class='alert-box'>
                    <p style='margin:0; color:#dc2626; font-weight:bold;'>🔴 RED ALERT: Student Behind Schedule</p>
                </div>
                
                <p>The following student has <strong>not submitted any daily logs</strong> this week and requires immediate attention:</p>
                
                <table style='width:100%; border-collapse:collapse; margin:15px 0;'>
                    <tr style='background:#e5e7eb;'>
                        <td style='padding:10px; font-weight:bold; border:1px solid #d1d5db;'>Student Name</td>
                        <td style='padding:10px; border:1px solid #d1d5db;'>" . htmlspecialchars($student_name) . "</td>
                    </tr>
                    <tr>
                        <td style='padding:10px; font-weight:bold; border:1px solid #d1d5db;'>Roll Number</td>
                        <td style='padding:10px; border:1px solid #d1d5db;'>" . htmlspecialchars($student_roll ?: 'N/A') . "</td>
                    </tr>
                    <tr style='background:#e5e7eb;'>
                        <td style='padding:10px; font-weight:bold; border:1px solid #d1d5db;'>Company</td>
                        <td style='padding:10px; border:1px solid #d1d5db;'>" . htmlspecialchars($company_name ?: 'N/A') . "</td>
                    </tr>
                    <tr>
                        <td style='padding:10px; font-weight:bold; border:1px solid #d1d5db;'>Alert Date</td>
                        <td style='padding:10px; border:1px solid #d1d5db;'>" . $today_display . "</td>
                    </tr>
                    <tr style='background:#e5e7eb;'>
                        <td style='padding:10px; font-weight:bold; border:1px solid #d1d5db;'>Status</td>
                        <td style='padding:10px; border:1px solid #d1d5db; color:#dc2626; font-weight:bold;'>🔴 Behind Schedule (0/5 Logs)</td>
                    </tr>
                </table>
                
                <p>Please review this student's progress and take appropriate action. You can view the student's details in your supervisor dashboard.</p>
                
                <p style='text-align:center;'>
                    <a href='http://localhost/internreport/supervisor/supervisor-dashboard.php' class='btn'>View Dashboard</a>
                </p>
            </div>
            <div class='footer'>
                <p>This is an automated notification from InternReport System.</p>
                <p>© " . date('Y') . " InternReport. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    // Send email via PHPMailer SMTP
    require_once __DIR__ . '/../config/mail.php';
    $email_sent = sendAlertEmail($supervisor_email, $subject, $body, $supervisor_email);

    // Record alert in database
    $insert = $pdo->prepare("INSERT INTO supervisor_alerts (supervisor_id, student_id, alert_type, alert_date, email_sent, sent_at) VALUES (?, ?, 'red_badge', ?, ?, NOW())");
    $insert->execute([$supervisor_id, $student_id, $today, $email_sent ? 1 : 0]);

    return $email_sent;
}
$filter_year = $_GET['academic_year'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;
$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, ['dashboard', 'trainee-archive'])) $tab = 'dashboard';

// Valid academic years for filter dropdown (from dimension table)
$vy_stmt = $pdo->prepare("
    SELECT DISTINCT ay.year_label
    FROM academic_years ay
    INNER JOIN users u ON u.academic_year_id = ay.id
    INNER JOIN student_profiles sp ON sp.user_id = u.id
    WHERE sp.supervisor_id = ?
    ORDER BY ay.year_label DESC
");
$vy_stmt->execute([$sup_id]);
$valid_years = $vy_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fallback: if no FK matches, also check string column for legacy data
if (empty($valid_years)) {
    $vy_stmt2 = $pdo->prepare("SELECT DISTINCT u.academic_year FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE sp.supervisor_id = ? AND u.academic_year IS NOT NULL AND u.academic_year != '' ORDER BY u.academic_year DESC");
    $vy_stmt2->execute([$sup_id]);
    $valid_years = $vy_stmt2->fetchAll(PDO::FETCH_COLUMN);
}

// Resolve selected year to academic_year_id for FK-based filtering
$selected_year_id = null;
if ($selected_year && preg_match('/^\d{4}-\d{4}$/', $selected_year)) {
    $ayid_stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year_label = ?");
    $ayid_stmt->execute([$selected_year]);
    $selected_year_id = $ayid_stmt->fetchColumn() ?: null;
}

// ── Detect Current Active Academic Year ─────────────────────────────
$current_academic_year = $valid_years[0] ?? '';
if (!$current_academic_year) {
    $now = new DateTime();
    $yr = (int) $now->format('Y');
    if ((int) $now->format('n') >= 8) {
        $current_academic_year = $yr . '-' . ($yr + 1);
    } else {
        $current_academic_year = ($yr - 1) . '-' . $yr;
    }
}

// ── Selected Year (defaults to current academic year) ───────────────
$selected_year = $filter_year ?: $current_academic_year;

// ── Current Week Boundaries ─────────────────────────────────────────
$today = new DateTime();
$dayOfWeek = (int) $today->format('N');
$weekStart = (clone $today)->modify('monday this week')->format('Y-m-d');
$weekEnd   = (clone $today)->modify('sunday this week')->format('Y-m-d');

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC CARD COUNTS (Filtered by Selected Academic Year)
// ══════════════════════════════════════════════════════════════════════
$ay = get_ay_filter($pdo, 'u');

// 1. ALL STUDENTS: Count assigned students for selected year
$sc = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ?" . $ay['sql']);
$sc->execute(array_merge([$sup_id], $ay['params']));
$total_assigned = (int) $sc->fetchColumn();

// 2. COMPANIES: Count distinct companies for selected year
$cc = $pdo->prepare("SELECT COUNT(DISTINCT sp.company_name) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ? AND sp.company_name IS NOT NULL AND sp.company_name != ''" . $ay['sql']);
$cc->execute(array_merge([$sup_id], $ay['params']));
$company_count = (int) $cc->fetchColumn();

// Build base query for students in selected year
$base_where = "u.role = 'student' AND sp.supervisor_id = ?" . $ay['sql'];
$base_params = array_merge([$sup_id], $ay['params']);

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC CURRENT WEEK CALCULATION (per student)
// ══════════════════════════════════════════════════════════════════════
// For each student, compute their active week number relative to TODAY
// Formula: floor(days_elapsed / 7) + 1
// If before start → Week 1; if after end → max final week (12)

$stu_detail_sql = "
    SELECT u.id AS uid, u.username, u.email, u.academic_year,
           sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
           sp.internship_start_date, sp.internship_end_date,
           sp.instructor_name, sp.instructor_email, sp.instructor_id
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE {$base_where}
    ORDER BY sp.full_name ASC
";
$stu_detail_stmt = $pdo->prepare($stu_detail_sql);
$stu_detail_stmt->execute($base_params);
$all_students_detail = $stu_detail_stmt->fetchAll();

$today_obj = new DateTime();
$today_str = $today_obj->format('Y-m-d');
$max_week = 12;

$student_dynamic_week = [];
$student_not_started = [];

foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $dynamic_week = 1;
    $not_started = false;

    if ($sd['internship_start_date']) {
        $start_date = new DateTime($sd['internship_start_date']);
        $end_date = $sd['internship_end_date'] ? new DateTime($sd['internship_end_date']) : null;

        // If today is before internship start, default to Week 1
        if ($today_obj < $start_date) {
            $dynamic_week = 1;
            $not_started = true;
        }
        // If today is after internship end, cap at max week
        elseif ($end_date && $today_obj > $end_date) {
            $dynamic_week = $max_week;
        }
        else {
            $days_elapsed = (int) $today_obj->diff($start_date)->days;
            $dynamic_week = (int) floor($days_elapsed / 7) + 1;
            // Clamp to valid range
            $dynamic_week = max(1, min($dynamic_week, $max_week));
        }
    } else {
        // No start date configured → treat as not started
        $not_started = true;
    }

    $student_dynamic_week[$uid] = $dynamic_week;
    $student_not_started[$uid] = $not_started;
}

// ══════════════════════════════════════════════════════════════════════
// PROGRESS CARD COUNTS (Dynamic Week per Student)
// ══════════════════════════════════════════════════════════════════════
// Re-classification exception: if report_status for the active week is
// 'approved_by_supervisor', classify student as Complete regardless of log count.

$behind_schedule = 0;
$in_progress = 0;
$complete = 0;

// Fetch report statuses for all students in bulk for their dynamic weeks
$report_status_cache = [];
foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rs_q = $pdo->prepare("SELECT report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $rs_q->execute([$uid, $dw]);
    $report_status_cache[$uid] = $rs_q->fetchColumn() ?: 'pending';
}

foreach ($all_students_detail as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rstatus = $report_status_cache[$uid] ?? 'pending';
    $not_started = $student_not_started[$uid] ?? false;

    // Skip students whose internship hasn't started yet
    if ($not_started) {
        continue;
    }

    // RE-CLASSIFICATION EXCEPTION: approved_by_supervisor → always Complete
    if ($rstatus === 'approved_by_supervisor') {
        $complete++;
        continue;
    }

    // Compute week date range for this student's dynamic week
    if ($sd['internship_start_date']) {
        $stu_start = new DateTime($sd['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        // Fallback: use calendar week
        $sws = $weekStart;
        $swe = $weekEnd;
    }

    $log_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $log_q->execute([$uid, $sws, $swe]);
    $log_count = (int) $log_q->fetchColumn();

    if ($log_count === 0) {
        $behind_schedule++;
    } elseif ($log_count >= 1 && $log_count <= 4) {
        $in_progress++;
    } else {
        $complete++;
    }
}

// Summary counts for cards
$warning_counts = [
    'red'   => $behind_schedule,
    'amber' => $in_progress,
    'green' => $complete,
    'none'  => $total_assigned - ($behind_schedule + $in_progress + $complete)
];

// ══════════════════════════════════════════════════════════════════════
// STUDENT LIST (for table display) — reuses all_students_detail
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

// ── Progress Badges for Table (Dynamic Week per Student) ─────────────
$progress_badges = [];
$progress_status = [];

foreach ($students as $s) {
    $uid = $s['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rstatus = $report_status_cache[$uid] ?? 'pending';
    $not_started = $student_not_started[$uid] ?? false;

    // Not Started — internship hasn't begun yet
    if ($not_started) {
        $progress_badges[$uid] = '<span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-600 bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
            ⏳ Not Started Yet
        </span>';
        $progress_status[$uid] = 'none';
        continue;
    }

    // RE-CLASSIFICATION EXCEPTION: approved_by_supervisor → always Complete
    if ($rstatus === 'approved_by_supervisor') {
        $progress_badges[$uid] = '<span class="inline-flex items-center gap-1.5 text-sm font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-blue-500"></span>
            ✓ Graded (Week ' . $dw . ')
        </span>';
        $progress_status[$uid] = 'green';
        continue;
    }

    // Compute week date range for this student's dynamic week
    $all_stu = $all_students_detail;
    $stu_arr = null;
    foreach ($all_stu as $sd) { if ($sd['uid'] === $uid) { $stu_arr = $sd; break; } }

    if ($stu_arr && $stu_arr['internship_start_date']) {
        $stu_start = new DateTime($stu_arr['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        $sws = $weekStart;
        $swe = $weekEnd;
    }

    $log_count_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $log_count_q->execute([$uid, $sws, $swe]);
    $log_count = (int) $log_count_q->fetchColumn();

    $badge_html = '';
    $badge_type = 'none';

    if ($dayOfWeek >= 3 && $log_count === 0) {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-sm font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
            🔴 Behind Schedule (Wk ' . $dw . ', 0 Logs)
        </span>';
        $badge_type = 'red';

        sendRedBadgeAlert(
            $pdo, $sup_id, $sup_name, $sup_email,
            $uid, $s['full_name'] ?: $s['username'],
            $s['student_roll'], $s['company_name']
        );
    } elseif ($log_count >= 1 && $log_count <= 4) {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-sm font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
            🟡 In Progress (Wk ' . $dw . ', ' . $log_count . '/5 Logs)
        </span>';
        $badge_type = 'amber';
    } elseif ($log_count >= 5) {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            🟢 Complete (Wk ' . $dw . ', 5/5 Logs)
        </span>';
        $badge_type = 'green';
    } else {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-600 bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
            ⏳ No Logs Yet (Week ' . $dw . ')
        </span>';
    }

    $progress_badges[$uid] = $badge_html;
    $progress_status[$uid] = $badge_type;
}

// Count students whose internship hasn't started yet
$not_started_count = 0;
foreach ($all_students_detail as $sd) {
    if ($student_not_started[$sd['uid']] ?? false) {
        $not_started_count++;
    }
}

// Unreviewed status per student (approved by instructor but not yet graded by supervisor)
$unreviewed = [];
foreach ($students as $s) {
    $q = $pdo->prepare("
        SELECT COUNT(*) FROM report_evaluations re
        WHERE re.student_id = ?
          AND re.report_status = 'approved_by_instructor'
          AND NOT EXISTS (
              SELECT 1 FROM supervisor_weekly_evaluations swe
              WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
          )
    ");
    $q->execute([$s['uid']]);
    $unreviewed[$s['uid']] = (int) $q->fetchColumn();
}

// Fully evaluated count per student (status is approved_by_supervisor or already has supervisor grade)
$evaluated = [];
foreach ($students as $s) {
    $q = $pdo->prepare("
        SELECT COUNT(*) FROM report_evaluations re
        WHERE re.student_id = ?
          AND re.report_status = 'approved_by_supervisor'
    ");
    $q->execute([$s['uid']]);
    $evaluated[$s['uid']] = (int) $q->fetchColumn();
}

// Instructor evaluation status per student (for current active week)
$instructor_eval_status = [];
foreach ($students as $s) {
    $uid = $s['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $ieq = $pdo->prepare("SELECT report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $ieq->execute([$uid, $dw]);
    $istatus = $ieq->fetchColumn();
    $instructor_eval_status[$uid] = $istatus ?: 'pending';
}

// ══════════════════════════════════════════════════════════════════════
// COHORT ANALYTICS (Batch queries for professional dashboard)
// ══════════════════════════════════════════════════════════════════════

// 1. Overall attendance rate across all assigned students
$att_q = $pdo->prepare("
    SELECT
        SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS total_present,
        COUNT(*) AS total_logs
    FROM daily_logs dl
    JOIN users u ON u.id = dl.internship_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
");
$att_q->execute([$sup_id]);
$att_row = $att_q->fetch();
$cohort_present = (int) ($att_row['total_present'] ?? 0);
$cohort_total_logs = (int) ($att_row['total_logs'] ?? 0);
$cohort_attendance_rate = $cohort_total_logs > 0 ? round(($cohort_present / $cohort_total_logs) * 100) : 0;

// 2. Pending reviews count (approved by instructor, awaiting supervisor grade)
$pending_reviews_q = $pdo->prepare("
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
$pending_reviews_q->execute([$sup_id]);
$pending_reviews = (int) $pending_reviews_q->fetchColumn();

// 3. Total graded weeks across all students
$total_graded_q = $pdo->prepare("
    SELECT COUNT(*) FROM supervisor_weekly_evaluations swe
    JOIN student_profiles sp ON sp.user_id = swe.student_id
    WHERE sp.supervisor_id = ?
");
$total_graded_q->execute([$sup_id]);
$total_graded_weeks = (int) $total_graded_q->fetchColumn();

// 4. Grade distribution across all graded weeks
$grade_dist_q = $pdo->prepare("
    SELECT swe.weekly_grade, COUNT(*) AS cnt
    FROM supervisor_weekly_evaluations swe
    JOIN student_profiles sp ON sp.user_id = swe.student_id
    WHERE sp.supervisor_id = ?
    GROUP BY swe.weekly_grade
");
$grade_dist_q->execute([$sup_id]);
$grade_distribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
while ($gr = $grade_dist_q->fetch()) {
    $grade_distribution[$gr['weekly_grade']] = (int) $gr['cnt'];
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
$company_q = $pdo->prepare("
    SELECT sp.company_name, COUNT(*) AS student_count
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
    GROUP BY sp.company_name
    ORDER BY student_count DESC
");
$company_q->execute([$sup_id]);
$company_breakdown = $company_q->fetchAll();

// 6. Per-student attendance rates (for table display)
$stu_att_q = $pdo->prepare("
    SELECT dl.internship_id,
           SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
           COUNT(*) AS total_count
    FROM daily_logs dl
    JOIN users u ON u.id = dl.internship_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
    GROUP BY dl.internship_id
");
$stu_att_q->execute([$sup_id]);
$student_attendance = [];
while ($sa = $stu_att_q->fetch()) {
    $sid = (int) $sa['internship_id'];
    $pc = (int) $sa['present_count'];
    $tc = (int) $sa['total_count'];
    $student_attendance[$sid] = $tc > 0 ? round(($pc / $tc) * 100) : 0;
}

// ── Filter students by status if filter is selected ────────────────
if ($filter_status && in_array($filter_status, ['red', 'amber', 'green'])) {
    $students = array_filter($students, function ($s) use ($filter_status, $progress_status) {
        return ($progress_status[$s['uid']] ?? 'none') === $filter_status;
    });
    $students = array_values($students); // Re-index array
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

    // BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header row
    fputcsv($output, ['Roll No', 'Student Name', 'Email', 'Major', 'Job Role', 'Company', 'Instructor Email', 'Attendance Rate', 'Weekly Status']);

    // Data rows
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
    function toggleNotifDropdown() {
        var dd = document.getElementById('notif-dropdown');
        if (dd.classList.contains('show')) {
            dd.classList.remove('show');
            dd.style.opacity = '0';
            dd.style.visibility = 'hidden';
            dd.style.transform = 'translateY(-8px) scale(0.95)';
        } else {
            dd.classList.add('show');
            dd.style.opacity = '1';
            dd.style.visibility = 'visible';
            dd.style.transform = 'translateY(0) scale(1)';
        }
    }
    document.addEventListener('click', function(e) {
        var wrapper = document.getElementById('notif-bell-wrapper');
        var dd = document.getElementById('notif-dropdown');
        if (wrapper && dd && !wrapper.contains(e.target)) {
            dd.classList.remove('show');
            dd.style.opacity = '0';
            dd.style.visibility = 'hidden';
            dd.style.transform = 'translateY(-8px) scale(0.95)';
        }
    });

    function timeAgo(dateStr) {
        var date = new Date(dateStr);
        var now = new Date();
        var seconds = Math.floor((now - date) / 1000);
        if (seconds < 60) return 'Just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    }

    function updateNotifTimestamps() {
        document.querySelectorAll('[data-notif-time]').forEach(function(el) {
            el.textContent = timeAgo(el.getAttribute('data-notif-time'));
        });
    }
    updateNotifTimestamps();
    setInterval(updateNotifTimestamps, 60000);

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
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>
            <a href="view-student-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🎓</span> Student View
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
                <h1 class="text-base font-bold text-slate-800">University Supervisor Dashboard</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if ($selected_year): ?>
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                    <!-- Notification Bell -->
                    <div class="relative" id="notif-bell-wrapper">
                        <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-white/30 rounded-xl transition cursor-pointer">
                            <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <?php if ($unread_notif_count > 0): ?>
                            <span class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                            <?php endif; ?>
                        </button>
                        <!-- Notification Dropdown -->
                        <div id="notif-dropdown" class="absolute right-0 top-full mt-1 w-80 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                            <div class="p-3 border-b border-slate-100 bg-gradient-to-br from-violet-50/80 to-white/60 flex items-center justify-between">
                                <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">Notifications</h4>
                                <?php if ($unread_notif_count > 0): ?>
                                <form method="POST" class="inline">
                                    <button type="submit" name="mark_all_notifications_read" class="text-label font-bold text-violet-600 hover:text-violet-800 transition cursor-pointer">Mark all read</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <div class="max-h-80 overflow-y-auto">
                                <?php if (!empty($recent_notifications)): ?>
                                <?php foreach ($recent_notifications as $notif): ?>
                                <div class="flex items-start gap-2.5 px-3 py-3 <?= !$notif['is_read'] ? 'bg-violet-50/40' : 'hover:bg-slate-50' ?> transition-all duration-150 border-b border-slate-100 last:border-0 group">
                                    <div class="w-8 h-8 rounded-full <?= $notif['type'] === 'instructor_approved' ? 'bg-emerald-100 text-emerald-600' : ($notif['type'] === 'instructor_rejected' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600') ?> flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-sm">
                                        <?= $notif['type'] === 'instructor_approved' ? '✓' : ($notif['type'] === 'instructor_rejected' ? '✕' : 'ℹ') ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-caption font-bold <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-500' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                                        <p class="text-label text-slate-400 mt-0.5 leading-snug" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($notif['message']) ?></p>
                                        <p class="text-caption text-slate-300 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                    <form method="POST" class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity duration-150">
                                        <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                        <button type="submit" name="mark_notification_read" class="w-6 h-6 rounded-full bg-slate-100 hover:bg-violet-100 text-slate-400 hover:text-violet-600 flex items-center justify-center text-label font-bold transition cursor-pointer shadow-sm" title="Mark as read">✓</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div class="p-8 text-center">
                                    <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                                        <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                    </div>
                                    <p class="text-xs font-semibold text-slate-400">No notifications yet</p>
                                    <p class="text-label text-slate-300 mt-1">You'll see updates here</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                        <?php if (!empty($_SESSION['profile_pic'])): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-indigo-500/20">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-indigo-500/20">
                            <?= strtoupper(substr($sup_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </button>
                    <div class="text-right">
                        <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($sup_name) ?></p>
                        <p class="text-sm text-slate-400">Supervisor</p>
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
            </div>
        </header>

<?php if ($tab === 'dashboard'): ?>
        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- ═══ ACADEMIC YEAR SELECTOR ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <!-- Left: Section Title -->
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-lg shadow-lg shadow-indigo-500/30">👩‍🏫</div>
                            <div>
                                <h2 class="text-sm font-bold text-slate-800">Assigned Students Overview</h2>
                                <p class="text-sm text-slate-400 mt-0.5">Select academic year to filter all dashboard data</p>
                            </div>
                        </div>
                        <!-- Right: Academic Year Dropdown -->
                        <div class="flex items-center gap-3">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Academic Year:</label>
                            <div class="relative">
                                <select id="academic_year_filter" onchange="location = this.value;" class="appearance-none bg-gradient-to-r from-indigo-50 to-white border border-indigo-200 rounded-xl px-4 py-2.5 pr-10 text-sm font-bold text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition-all duration-200 shadow-sm cursor-pointer hover:border-indigo-300 hover:shadow-md">
                                    <option value="?<?= http_build_query(array_merge($_GET, ['academic_year' => ''])) ?>" <?= !$filter_year ? 'selected' : '' ?>>All Academic Years</option>
                                    <?php foreach ($valid_years as $vy): ?>
                                    <option value="?<?= http_build_query(array_merge($_GET, ['academic_year' => $vy])) ?>" <?= $filter_year === $vy ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vy) ?><?= $vy === $current_academic_year ? ' (Current)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-indigo-400 pointer-events-none text-xs">▾</span>
                            </div>
                            <?php if ($filter_year): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['academic_year' => ''])) ?>" class="inline-flex items-center gap-1 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all duration-200">
                                ✕ Clear
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Analytics Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center text-xl shadow-lg shadow-indigo-500/30">🎓</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Students</p>
                                <p class="text-2xl font-black text-slate-800"><?= $total_assigned ?></p>
                                <?php if ($selected_year): ?>
                                <p class="text-sm text-indigo-500 font-bold font-mono"><?= htmlspecialchars($selected_year) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-xl shadow-lg shadow-emerald-500/30">✅</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Attendance</p>
                                <p class="text-2xl font-black text-slate-800"><?= $cohort_attendance_rate ?>%</p>
                                <p class="text-sm text-emerald-500 font-bold"><?= number_format($cohort_present) ?>/<?= number_format($cohort_total_logs) ?> days</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30">📩</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Pending Reviews</p>
                                <p class="text-2xl font-black text-slate-800"><?= $pending_reviews ?></p>
                                <p class="text-sm <?= $pending_reviews > 0 ? 'text-amber-500' : 'text-slate-400' ?> font-bold"><?= $pending_reviews > 0 ? 'Needs attention' : 'All caught up' ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xl shadow-lg shadow-blue-500/30">📊</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Avg. Grade</p>
                                <p class="text-2xl font-black text-slate-800"><?= $avg_grade_points > 0 ? number_format($avg_grade_points, 1) : '—' ?></p>
                                <p class="text-sm text-blue-500 font-bold"><?= $total_graded_weeks ?> week<?= $total_graded_weeks !== 1 ? 's' : '' ?> graded</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Week Info Banner -->
                <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-700 rounded-2xl p-6 text-white shadow-xl shadow-indigo-500/20">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-xl">📅</div>
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-wider text-indigo-200">Current Calendar Week</p>
                                <p class="text-lg font-bold mt-0.5"><?= (new DateTime('monday this week'))->format('d M') ?> – <?= (new DateTime('sunday this week'))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <div class="text-right bg-white/10 backdrop-blur-sm rounded-xl px-5 py-3">
                            <p class="text-sm font-semibold text-indigo-200">Day <?= $dayOfWeek ?>/7</p>
                            <p class="text-sm font-bold mt-0.5"><?= $today->format('l') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Warning Summary Card with Filter Tabs -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-red-50 text-red-500 flex items-center justify-center text-sm">⚠️</span> Weekly Progress Summary
                            <?php if ($selected_year): ?>
                            <span class="ml-auto text-sm font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-200/60 font-mono">
                                📅 <?= htmlspecialchars($selected_year) ?>
                            </span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="p-5 grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <!-- All Students (Filter: None → scroll to table) -->
                        <a href="#student-table" class="bg-gradient-to-br <?= $filter_status === '' ? 'from-slate-100 to-slate-200 border-slate-300 ring-2 ring-slate-400 ring-offset-2' : 'from-slate-50 to-white border-slate-200/60 hover:from-slate-100 hover:to-slate-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <span class="text-3xl font-black text-slate-700"><?= $total_assigned ?></span>
                            </div>
                            <p class="text-sm font-bold text-slate-700 uppercase tracking-wider">All Students</p>
                            <p class="text-sm text-slate-500 mt-1">Total assigned</p>
                        </a>

                        <!-- Red Warnings (Filter: red) -->
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'red'])) ?>" class="bg-gradient-to-br <?= $filter_status === 'red' ? 'from-red-100 to-red-200 border-red-300 ring-2 ring-red-400 ring-offset-2' : 'from-red-50 to-red-100/50 border-red-200/60 hover:from-red-100 hover:to-red-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <?php if ($behind_schedule > 0): ?>
                                <span class="w-3 h-3 rounded-full bg-red-500 animate-pulse shadow-lg shadow-red-500/40"></span>
                                <?php endif; ?>
                                <span class="text-3xl font-black text-red-600"><?= $behind_schedule ?></span>
                            </div>
                            <p class="text-sm font-bold text-red-700 uppercase tracking-wider">Behind Schedule</p>
                            <p class="text-sm text-red-500 mt-1">No logs for active week</p>
                        </a>

                        <!-- Amber Warnings (Filter: amber) -->
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'amber'])) ?>" class="bg-gradient-to-br <?= $filter_status === 'amber' ? 'from-amber-100 to-amber-200 border-amber-300 ring-2 ring-amber-400 ring-offset-2' : 'from-amber-50 to-amber-100/50 border-amber-200/60 hover:from-amber-100 hover:to-amber-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <span class="w-3 h-3 rounded-full bg-amber-500 shadow-lg shadow-amber-500/40"></span>
                                <span class="text-3xl font-black text-amber-600"><?= $in_progress ?></span>
                            </div>
                            <p class="text-sm font-bold text-amber-700 uppercase tracking-wider">In Progress</p>
                            <p class="text-sm text-amber-500 mt-1">Partial logs (1-4)</p>
                        </a>

                        <!-- Green Complete (Filter: green) -->
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'green'])) ?>" class="bg-gradient-to-br <?= $filter_status === 'green' ? 'from-emerald-100 to-emerald-200 border-emerald-300 ring-2 ring-emerald-400 ring-offset-2' : 'from-emerald-50 to-emerald-100/50 border-emerald-200/60 hover:from-emerald-100 hover:to-emerald-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <span class="w-3 h-3 rounded-full bg-emerald-500 shadow-lg shadow-emerald-500/40"></span>
                                <span class="text-3xl font-black text-emerald-600"><?= $complete ?></span>
                            </div>
                            <p class="text-sm font-bold text-emerald-700 uppercase tracking-wider">Complete / Graded</p>
                            <p class="text-sm text-emerald-500 mt-1">5 logs or Supervisor approved</p>
                        </a>
                    </div>
                    <div class="px-6 py-3 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white rounded-b-2xl">
                        <p class="text-sm text-slate-500 text-center font-medium">
                            <?php if ($filter_status): ?>
                                Filtering by: <span class="font-bold text-slate-700"><?= $filter_status === 'red' ? 'Behind Schedule' : ($filter_status === 'amber' ? 'In Progress' : 'Complete') ?></span> · <?= count($students) ?> student(s)
                                <a href="?<?= http_build_query(array_merge($_GET, ['status' => ''])) ?>" class="ml-2 text-indigo-600 hover:underline">✕ Clear filter</a>
                            <?php else: ?>
                                <?= $total_assigned ?> total student(s) · <span class="font-bold text-slate-700"><?= $behind_schedule + $in_progress ?></span> need attention<?php if ($not_started_count > 0): ?> · <span class="text-slate-500"><?= $not_started_count ?> not started</span><?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- ═══ COHORT ANALYTICS ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Grade Distribution -->
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📊</span> Grade Distribution
                            </h2>
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
                                            <?php if ($pct > 8): ?>
                                            <span class="text-sm font-bold text-white"><?= $cnt ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold text-slate-500 w-12 text-right"><?= $pct ?>%</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-sm text-slate-400 font-medium">Total: <?= $total_graded_sum ?> graded week(s)</span>
                                <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-200/60">GPA: <?= number_format($avg_grade_points, 2) ?>/4.00</span>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-3">📋</div>
                                <p class="text-xs text-slate-400 font-medium">No grades submitted yet.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Company Breakdown -->
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">🏢</span> Company Placement
                                <span class="ml-auto text-sm font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200/60"><?= count($company_breakdown) ?> companies</span>
                            </h2>
                        </div>
                        <div class="p-5">
                            <?php if (!empty($company_breakdown)): ?>
                            <div class="space-y-2.5 max-h-64 overflow-y-auto">
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

                <!-- Students Table -->
                <div id="student-table" class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between flex-wrap gap-4">
                        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-500 flex items-center justify-center text-sm">🎓</span> My Assigned Students
                        </h2>
                        <div class="flex items-center gap-3">
                            <!-- Search Box -->
                            <form method="GET" class="flex items-center gap-1.5">
                                <?php if ($filter_year): ?><input type="hidden" name="academic_year" value="<?= htmlspecialchars($filter_year) ?>"><?php endif; ?>
                                <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                                <div class="relative">
                                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, roll, email…"
                                        class="bg-white border border-slate-200 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-700 w-48 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 shadow-sm placeholder:text-slate-400">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
                                </div>
                                <button type="submit" class="px-3 py-2 bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold rounded-xl transition-all duration-200 shadow-sm">Search</button>
                                <?php if ($search): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['search' => ''])) ?>" class="px-2 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all duration-200">✕</a>
                                <?php endif; ?>
                            </form>
                            <!-- Year Filter -->
                            <form method="GET" class="flex items-center gap-1.5">
                                <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                                <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                                <select name="academic_year" onchange="this.form.submit()" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 shadow-sm">
                                    <option value="">All Years</option>
                                    <?php foreach ($valid_years as $vy): ?>
                                    <option value="<?= htmlspecialchars($vy) ?>" <?= $filter_year === $vy ? 'selected' : '' ?>><?= htmlspecialchars($vy) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <span class="text-xs text-slate-400 font-medium"><?= count($students) ?> student(s)</span>
                            <!-- Export CSV Button -->
                            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold rounded-xl transition-all duration-200 shadow-sm">
                                📥 Export CSV
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($paginated_students)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                    <th class="px-5 py-3 text-left">Roll No</th>
                                    <th class="px-5 py-3 text-left">Student Name</th>
                                    <th class="px-5 py-3 text-left">Major</th>
                                    <th class="px-5 py-3 text-left">Job Role</th>
                                    <th class="px-5 py-3 text-left">Company</th>
                                    <th class="px-5 py-3 text-left">Attendance</th>
                                    <th class="px-5 py-3 text-left">Instructor</th>
                                    <th class="px-5 py-3 text-left">Status</th>
                                    <th class="px-5 py-3 text-left">Weekly Progress</th>
                                    <th class="px-5 py-3 text-left">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($paginated_students as $s): ?>
                                <?php $ur = $unreviewed[$s['uid']] ?? 0; ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                    <td class="px-5 py-4 font-mono font-semibold text-slate-700 text-xs"><?= htmlspecialchars($s['student_roll'] ?: '—') ?></td>
                                    <td class="px-5 py-4">
                                        <a href="view-student-dashboard.php?id=<?= $s['uid'] ?>" class="flex items-center gap-3 hover:opacity-80 transition">
                                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shrink-0 shadow-md shadow-indigo-500/20">
                                                <?= strtoupper(($s['full_name'] ?: $s['username'])[0]) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-800 hover:text-indigo-600 transition"><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></p>
                                                <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($s['email']) ?></p>
                                            </div>
                                        </a>
                                    </td>
                                    <td class="px-5 py-4 text-slate-600 font-medium"><?= htmlspecialchars($s['major'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-slate-600 font-medium text-xs"><?= htmlspecialchars($s['job_role'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-slate-600 max-w-[150px] truncate font-medium" title="<?= htmlspecialchars($s['company_name'] ?? '') ?>"><?= htmlspecialchars($s['company_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4">
                                        <?php $att_rate = $student_attendance[$s['uid']] ?? null; ?>
                                        <?php if ($att_rate !== null): ?>
                                            <div class="flex items-center gap-2">
                                                <div class="w-12 bg-slate-100 rounded-full h-2 overflow-hidden">
                                                    <div class="h-2 rounded-full <?= $att_rate >= 80 ? 'bg-emerald-500' : ($att_rate >= 60 ? 'bg-amber-500' : 'bg-red-500') ?>" style="width: <?= $att_rate ?>%"></div>
                                                </div>
                                                <span class="text-sm font-bold <?= $att_rate >= 80 ? 'text-emerald-600' : ($att_rate >= 60 ? 'text-amber-600' : 'text-red-600') ?>"><?= $att_rate ?>%</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                                <?php if (!empty($s['instructor_name'])): ?>
                                                <div class="flex items-center gap-3">
                                                    <div class="w-7 h-7 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-sm font-bold shrink-0">
                                                        <?= strtoupper(substr($s['instructor_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($s['instructor_name']) ?></p>
                                                <?php
                                                $ist = $instructor_eval_status[$s['uid']] ?? 'pending';
                                                if ($ist === 'approved_by_instructor' || $ist === 'approved_by_supervisor'):
                                                ?>
                                                <span class="text-sm font-bold text-emerald-600">✅ Approved</span>
                                                <?php elseif ($ist === 'rejected'): ?>
                                                <span class="text-sm font-bold text-red-600">❌ Rejected</span>
                                                <?php else: ?>
                                                <span class="text-sm font-bold text-amber-600">⏳ Pending</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-sm text-slate-400 italic">Not assigned</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($evaluated[$s['uid']] ?? 0 > 0): ?>
                                            <a href="supervisor-review.php?student_id=<?= $s['uid'] ?>" class="inline-flex items-center gap-1.5 text-sm font-bold text-blue-700 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-200/60 hover:bg-blue-100 hover:border-blue-300 transition-all duration-200 cursor-pointer">
                                                <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                                ✓ Graded — View Details
                                            </a>
                                        <?php elseif ($ur > 0): ?>
                                            <a href="supervisor-review.php?student_id=<?= $s['uid'] ?>" class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-200/60 hover:bg-emerald-100 hover:border-emerald-300 transition-all duration-200 cursor-pointer">
                                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                                📩 Pending Your Grade (Instructor Approved)
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200/60">
                                                <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                                                Awaiting Reports
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?= $progress_badges[$s['uid']] ?: '<span class="text-slate-300 text-xs">—</span>' ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-2">
                                            <a href="supervisor-review.php?student_id=<?= $s['uid'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-sm font-bold rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-purple-500/20">
                                                👁️ View &amp; Grade
                                            </a>
                                            <?php if (($progress_status[$s['uid']] ?? 'none') === 'red'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Send a warning notification to <?= htmlspecialchars($s['full_name'] ?: $s['username']) ?>?');">
                                                <input type="hidden" name="student_id" value="<?= $s['uid'] ?>">
                                                <button type="submit" name="send_warning" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-lg transition-all duration-200 shadow-sm" title="Send Warning">
                                                    ⚠️ Send Warning
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <p class="text-sm text-slate-400 font-medium">
                                Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_students) ?> of <?= $total_students ?> student(s)
                                <?php if ($search): ?>
                                    matching "<span class="font-bold text-slate-600"><?= htmlspecialchars($search) ?></span>"
                                <?php endif; ?>
                            </p>
                            <div class="flex items-center gap-1.5">
                                <?php
                                // Build base query params without 'page'
                                $base_params = $_GET;
                                unset($base_params['page']);
                                $base_query = http_build_query($base_params);
                                $sep = $base_query ? '&' : '';
                                ?>
                                <!-- Previous Button -->
                                <?php if ($page > 1): ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $page - 1 ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200 shadow-sm">← Prev</a>
                                <?php else: ?>
                                <span class="px-3 py-1.5 text-xs font-bold text-slate-300 bg-slate-50 border border-slate-200 rounded-lg cursor-not-allowed">← Prev</span>
                                <?php endif; ?>

                                <!-- Page Numbers -->
                                <?php
                                $range = 2;
                                $start_page = max(1, $page - $range);
                                $end_page = min($total_pages, $page + $range);
                                ?>
                                <?php if ($start_page > 1): ?>
                                <a href="?<?= $base_query . $sep ?>page=1" class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200">1</a>
                                <?php if ($start_page > 2): ?>
                                <span class="text-slate-400 text-xs">…</span>
                                <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <?php if ($i === $page): ?>
                                <span class="w-8 h-8 flex items-center justify-center text-xs font-bold text-white bg-indigo-500 border border-indigo-500 rounded-lg shadow-md shadow-indigo-500/30"><?= $i ?></span>
                                <?php else: ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $i ?>" class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200"><?= $i ?></a>
                                <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                <span class="text-slate-400 text-xs">…</span>
                                <?php endif; ?>
                                <a href="?<?= $base_query . $sep ?>page=<?= $total_pages ?>" class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all duration-200"><?= $total_pages ?></a>
                                <?php endif; ?>

                                <!-- Next Button -->
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
                            <?php if ($search): ?>
                                matching "<span class="font-bold text-slate-600"><?= htmlspecialchars($search) ?></span>"
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm text-slate-500 font-medium">
                            <?php if ($search): ?>
                                No students found matching "<strong><?= htmlspecialchars($search) ?></strong>".
                            <?php elseif ($filter_year): ?>
                                No students found for <?= htmlspecialchars($filter_year) ?>.
                            <?php elseif ($filter_status): ?>
                                No students with status "<?= $filter_status === 'red' ? 'Behind Schedule' : ($filter_status === 'amber' ? 'In Progress' : 'Complete') ?>".
                            <?php else: ?>
                                No students assigned to you yet.
                            <?php endif; ?>
                        </p>
                        <?php if ($search || $filter_year || $filter_status): ?>
                        <a href="supervisor-dashboard.php" class="mt-3 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear all filters</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>

<?php elseif ($tab === 'trainee-archive'): ?>
        <!-- ═══ TRAINEE ARCHIVE CONTENT ═══ -->
        <?php
        $ta_year = $_GET['academic_year'] ?? '';
        $ta_years = $pdo->prepare("
            SELECT DISTINCT ay.year_label
            FROM academic_years ay
            INNER JOIN users u ON u.academic_year_id = ay.id
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE sp.supervisor_id = ? AND u.role = 'student' AND u.status = 'Archived'
            ORDER BY ay.year_label DESC
        ");
        $ta_years->execute([$sup_id]);
        $ta_valid_years = $ta_years->fetchAll(PDO::FETCH_COLUMN);

        // Fallback: also check string column for legacy data
        if (empty($ta_valid_years)) {
            $ta_years2 = $pdo->prepare("SELECT DISTINCT u.academic_year FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE sp.supervisor_id = ? AND u.role = 'student' AND u.status = 'Archived' AND u.academic_year IS NOT NULL ORDER BY u.academic_year DESC");
            $ta_years2->execute([$sup_id]);
            $ta_valid_years = $ta_years2->fetchAll(PDO::FETCH_COLUMN);
        }

        // Resolve selected archive year to FK
        $ta_year_id = null;
        if ($ta_year && preg_match('/^\d{4}-\d{4}$/', $ta_year)) {
            $ta_ayid = $pdo->prepare("SELECT id FROM academic_years WHERE year_label = ?");
            $ta_ayid->execute([$ta_year]);
            $ta_year_id = $ta_ayid->fetchColumn() ?: null;
        }

        $ta_sql = "
            SELECT u.id AS uid, u.username, u.email, u.academic_year, u.created_at,
                   sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
                   sp.instructor_name, sp.internship_start_date, sp.internship_end_date
            FROM users u
            JOIN student_profiles sp ON sp.user_id = u.id
            WHERE sp.supervisor_id = ? AND u.role = 'student' AND u.status = 'Archived'
        ";
        $ta_params = [$sup_id];
        if ($ta_year_id) {
            $ta_sql .= " AND u.academic_year_id = ?";
            $ta_params[] = $ta_year_id;
        } elseif ($ta_year && preg_match('/^\d{4}-\d{4}$/', $ta_year)) {
            $ta_sql .= " AND u.academic_year = ?";
            $ta_params[] = $ta_year;
        }
        $ta_sql .= " ORDER BY sp.full_name ASC";
        $ta_stmt = $pdo->prepare($ta_sql);
        $ta_stmt->execute($ta_params);
        $ta_students = $ta_stmt->fetchAll();

        $ta_total = count($ta_students);
        $ta_companies = [];
        foreach ($ta_students as $ts) {
            if (!empty($ts['company_name'])) $ta_companies[$ts['company_name']] = true;
        }

        $ta_grades = [];
        foreach ($ta_students as $ts) {
            $gq = $pdo->prepare("SELECT grade FROM report_evaluations WHERE student_id = ? ORDER BY evaluated_at DESC LIMIT 1");
            $gq->execute([$ts['uid']]);
            $ta_grades[$ts['uid']] = $gq->fetchColumn() ?: null;
        }

        $ta_grade_dist = ['excellent' => 0, 'good' => 0, 'average' => 0, 'needs_improvement' => 0];
        foreach ($ta_grades as $gv) {
            if ($gv && isset($ta_grade_dist[$gv])) $ta_grade_dist[$gv]++;
        }
        ?>

        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- Back Button -->
                <a href="supervisor-dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-600 hover:bg-slate-50 hover:border-slate-300 transition-all shadow-sm">
                    ← Back to Dashboard
                </a>

                <!-- Archive Header -->
                <div class="bg-gradient-to-r from-purple-900 via-purple-950 to-indigo-950 rounded-2xl p-6 text-white shadow-xl shadow-purple-500/10">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center text-xl border border-white/20">⏪</div>
                            <div>
                                <h2 class="text-lg font-black uppercase tracking-wider">My Trainees <?= $ta_year ? htmlspecialchars($ta_year) : '' ?></h2>
                                <p class="text-sm text-purple-200 mt-0.5">Your Past Assigned Students — Historical Data</p>
                            </div>
                        </div>
                        <form method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="tab" value="trainee-archive">
                            <select name="academic_year" onchange="this.form.submit()" class="appearance-none bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-2.5 pr-10 text-sm font-bold text-white focus:outline-none cursor-pointer">
                                <option value="">All Archived Years</option>
                                <?php foreach ($ta_valid_years as $ty): ?>
                                <option value="<?= htmlspecialchars($ty) ?>" <?= $ta_year === $ty ? 'selected' : '' ?>><?= htmlspecialchars($ty) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($ta_year): ?>
                            <a href="?tab=trainee-archive" class="px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white text-sm font-bold rounded-xl transition border border-white/20">✕ Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-600 text-white flex items-center justify-center text-lg">⏪</div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Archived</p>
                                <p class="text-2xl font-black text-slate-800"><?= $ta_total ?></p>
                                <?php if ($ta_year): ?>
                                <p class="text-xs text-purple-500 font-bold font-mono"><?= htmlspecialchars($ta_year) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center text-lg">🏢</div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Companies</p>
                                <p class="text-2xl font-black text-slate-800"><?= count($ta_companies) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 col-span-2 lg:col-span-1">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-lg">📊</div>
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Grades</p>
                                <div class="flex items-center gap-1 mt-1">
                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded"><?= $ta_grade_dist['excellent'] ?>E</span>
                                    <span class="text-xs font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded"><?= $ta_grade_dist['good'] ?>G</span>
                                    <span class="text-xs font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded"><?= $ta_grade_dist['average'] ?>A</span>
                                    <span class="text-xs font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded"><?= $ta_grade_dist['needs_improvement'] ?>N</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Archived Trainees Table -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-purple-50 text-purple-600 rounded">⏪</span> Past Assigned Students
                        </h3>
                        <span class="text-sm text-slate-400"><?= $ta_total ?> student(s)</span>
                    </div>

                    <?php if (!empty($ta_students)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                    <th class="px-4 py-2.5 text-left">Roll No</th>
                                    <th class="px-4 py-2.5 text-left">Student Name</th>
                                    <th class="px-4 py-2.5 text-left">Job Role</th>
                                    <th class="px-4 py-2.5 text-left">Company</th>
                                    <th class="px-4 py-2.5 text-left">Duration</th>
                                    <th class="px-4 py-2.5 text-left">Year</th>
                                    <th class="px-4 py-2.5 text-left">Grade</th>
                                    <th class="px-4 py-2.5 text-left">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($ta_students as $ts): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-2.5 font-mono font-semibold text-slate-700"><?= htmlspecialchars($ts['student_roll'] ?: '—') ?></td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm font-bold shrink-0">
                                                <?= strtoupper(($ts['full_name'] ?: $ts['username'])[0]) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-700"><?= htmlspecialchars($ts['full_name'] ?: $ts['username']) ?></p>
                                                <p class="text-xs text-slate-400"><?= htmlspecialchars($ts['email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-slate-600 max-w-[120px] truncate" title="<?= htmlspecialchars($ts['job_role'] ?? '') ?>"><?= htmlspecialchars($ts['job_role'] ?: '—') ?></td>
                                    <td class="px-4 py-2.5 text-slate-600 max-w-[130px] truncate" title="<?= htmlspecialchars($ts['company_name'] ?? '') ?>"><?= htmlspecialchars($ts['company_name'] ?: '—') ?></td>
                                    <td class="px-4 py-2.5 text-slate-500 text-xs">
                                        <?php if ($ts['internship_start_date'] && $ts['internship_end_date']): ?>
                                            <?= htmlspecialchars((new DateTime($ts['internship_start_date']))->format('d M Y')) ?> – <?= htmlspecialchars((new DateTime($ts['internship_end_date']))->format('d M Y')) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($ts['academic_year'] ?: '—') ?></span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <?php
                                        $grade_map = [
                                            'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
                                            'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
                                            'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
                                            'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
                                        ];
                                        $gv = $ta_grades[$ts['uid']] ?? null;
                                        $gs = $gv ? ($grade_map[$gv] ?? ['—', 'text-slate-400', 'bg-slate-50']) : ['—', 'text-slate-400', 'bg-slate-50'];
                                        ?>
                                        <span class="text-xs font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <a href="../view_student_history.php?uid=<?= $ts['uid'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-50 text-purple-600 text-xs font-bold rounded-lg hover:bg-purple-100 transition">
                                            👁️ View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">⏪</div>
                        <p class="text-sm text-slate-500 font-medium">
                            <?php if ($ta_year): ?>
                                No archived trainees found for <?= htmlspecialchars($ta_year) ?>.
                            <?php else: ?>
                                No archived trainees assigned to you yet.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
<?php endif; ?>
    </div>
</div>

</body>
</html>
