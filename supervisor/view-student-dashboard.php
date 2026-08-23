<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';
require_once __DIR__ . '/../config/notify.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'] ?? '';
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

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->bind_param("i", $sup_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$student_id = (int) ($_GET['id'] ?? $_GET['student_id'] ?? $_GET['uid'] ?? 0);

if ($student_id <= 0) {
    header('Location: my-students.php');
    exit;
}

// ── Verify student belongs to this supervisor ────────────────────
$check = $db->prepare("
    SELECT 1 FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ? AND sp.supervisor_id = ? AND u.role = 'student'
");
$check->bind_param("ii", $student_id, $sup_id);
$check->execute();
$res = $check->get_result();
if (!$res || !$res->fetch_row()) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$profile_r = $db->prepare("
    SELECT sp.*, u.username, u.email, u.phone, u.profile_pic,
           ay.year_label AS academic_year,
           COALESCE(c.company_name, '') AS company_name, c.contact_person, c.contact_email, c.contact_phone,
           sup_u.username AS supervisor_name
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
    LEFT JOIN companies c ON c.id = sp.company_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE sp.user_id = ?
");
$profile_r->bind_param("i", $student_id);
$profile_r->execute();
$res = $profile_r->get_result();
$profile = $res ? $res->fetch_assoc() : null;

if (!$profile) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$intern_start    = $profile['internship_start_date'] ?? null;
$intern_end      = $profile['internship_end_date'] ?? null;
$student_name    = ($profile['username'] ?? 'Student');
$student_roll    = $profile['student_roll'] ?? '';
$company_name    = $profile['company_name'] ?? '';
$major           = $profile['major'] ?? '';
$job_role        = $profile['job_role'] ?? '';
$phone           = $profile['phone'] ?? '';
$instructor_name = '—';
$profile_pic     = $profile['profile_pic'] ?? '';
$academic_year   = $profile['academic_year'] ?? '';

// Total assigned students for supervisor
$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?");
$total_assigned_q->bind_param("i", $sup_id);
$total_assigned_q->execute();
$res = $total_assigned_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);

// ── Determine Week Ranges from internship start date ───────────────
$weeks = [];
$auto_week = 1;
$total_weeks = 12;
$not_started = false;

if ($intern_start) {
    $total_weeks = internship_total_weeks($intern_start, $intern_end ?: null);
    $today_obj = new DateTime();
    $auto_week = internship_current_week($intern_start, $intern_end ?: null, $today_obj);

    if ($today_obj < new DateTime($intern_start)) {
        $not_started = true;
    }

    $w = 1;
    while (true) {
        $range = getWeekRange($intern_start, $w);
        if (!$range) break;
        if ($intern_end && $range['start'] > $intern_end) break;
        $weeks[$w] = $range;
        if ($total_weeks > 0 && $w >= $total_weeks) break;
        $w++;
    }
} else {
    $not_started = true;
    // Fallback: build from log dates
    $all_dates = $db->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE student_id = ? ORDER BY log_date ASC");
    $all_dates->bind_param("i", $student_id);
    $all_dates->execute();
    $res = $all_dates->get_result();
    $log_dates = [];
    if ($res) {
        while ($r = $res->fetch_row()) {
            $log_dates[] = $r[0];
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
        $total_weeks = max(1, count($weeks));
    }
}

$total_internship_weeks = max(1, count($weeks) ?: $total_weeks);

$selected_week = $auto_week;
if (isset($_GET['week'])) {
    $w = (int) $_GET['week'];
    if (isset($weeks[$w])) {
        $selected_week = $w;
    }
}

$week_start = $weeks[$selected_week]['start'] ?? '';
$week_end   = $weeks[$selected_week]['end'] ?? '';

// Format date range (e.g. 05 May 2026 to 09 May 2026)
$week_date_range = '';
if ($week_start && $week_end) {
    $ws_obj = new DateTime($week_start);
    $we_obj = new DateTime($week_end);
    $week_date_range = $ws_obj->format('d M Y') . ' to ' . $we_obj->format('d M Y');
}

// ── Handle Supervisor Evaluation POST Submission ──────────────────
$eval_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sup_eval'])) {
    $grade    = $_POST['weekly_grade'] ?? '';
    $comments = trim($_POST['supervisor_comments'] ?? '');
    $allowed  = ['A', 'B', 'C', 'D', 'F'];

    if (!in_array($grade, $allowed, true)) {
        $eval_msg = 'invalid_grade';
    } else {
        $upsert = $db->prepare("
            UPDATE weekly_reports SET
            supervisor_grade = ?,
            supervisor_comments = ?,
            status = 'graded'
            WHERE student_id = ? AND week_number = ?
        ");
        $upsert->bind_param("ssii", $grade, $comments, $student_id, $selected_week);
        $upsert->execute();

        // Notify student
        $student_link = '../student/student-dashboard.php?week=' . (int)$selected_week;
        notify_user_once(
            $db,
            $student_id,
            "Week {$selected_week} Report Graded",
            "Your university supervisor evaluated and graded your Week {$selected_week} report with '{$grade}'.",
            'supervisor_approved',
            $selected_week,
            $student_id,
            null,
            false,
            $student_link
        );

        $eval_msg = 'saved';
    }
}

// ── Lifetime Statistics (Overall Full Internship) ──────────────────
if ($intern_start && $intern_end) {
    $all_dl_stmt = $db->prepare("SELECT * FROM daily_logs WHERE student_id = ? AND log_date >= ? AND log_date <= ? ORDER BY log_date ASC");
    $all_dl_stmt->bind_param("iss", $student_id, $intern_start, $intern_end);
} else {
    $all_dl_stmt = $db->prepare("SELECT * FROM daily_logs WHERE student_id = ? ORDER BY log_date ASC");
    $all_dl_stmt->bind_param("i", $student_id);
}
$all_dl_stmt->execute();
$all_dl_res = $all_dl_stmt->get_result();
$all_daily_logs = $all_dl_res ? $all_dl_res->fetch_all(MYSQLI_ASSOC) : [];

$all_present_days     = 0;
$all_absent_days      = 0;
$intern_total_minutes = 0;
$seen_intern_dates    = [];
foreach ($all_daily_logs as $dl_row) {
    $st = strtolower($dl_row['attendance_status'] ?? 'present');
    if ($st === 'present') {
        $all_present_days++;
    } elseif (in_array($st, ['absent', 'leave'], true)) {
        $all_absent_days++;
    }
    if (!isset($seen_intern_dates[$dl_row['log_date']])) {
        $seen_intern_dates[$dl_row['log_date']] = true;
    }
    $dur_parts = explode(':', (string) ($dl_row['calculated_duration'] ?? ''));
    if (count($dur_parts) === 2) {
        $intern_total_minutes += ((int)$dur_parts[0] * 60) + (int)$dur_parts[1];
    }
}
$all_recorded_days = $all_present_days + $all_absent_days;
$all_att_rate      = $all_recorded_days > 0 ? (int)round(($all_present_days / $all_recorded_days) * 100) : 0;
$intern_hours      = floor($intern_total_minutes / 60);
$intern_mins       = $intern_total_minutes % 60;

$intern_att = [
    'present'  => $all_present_days,
    'absent'   => $all_absent_days,
    'expected' => $all_recorded_days,
    'rate'     => $all_att_rate,
];

// Fetch all weekly reports across all weeks for full report printing & evaluations
$all_wr_stmt = $db->prepare("SELECT * FROM weekly_reports WHERE student_id = ? ORDER BY week_number ASC");
$all_wr_stmt->bind_param("i", $student_id);
$all_wr_stmt->execute();
$all_wr_res = $all_wr_stmt->get_result();
$all_weekly_reports = [];
if ($all_wr_res) {
    while ($wr_row = $all_wr_res->fetch_assoc()) {
        $all_weekly_reports[(int)$wr_row['week_number']] = $wr_row;
    }
}

// Present & Absent counts for tooltip (strictly scoped to active week)
$present_dates = [];
$total_present = 0;
$absent_logs   = [];
$total_absent  = 0;

if ($week_start && $week_end) {
    $pd_stmt = $db->prepare("SELECT log_date FROM daily_logs WHERE student_id = ? AND log_date >= ? AND log_date <= ? AND attendance_status = 'present' ORDER BY log_date ASC");
    $pd_stmt->bind_param("iss", $student_id, $week_start, $week_end);
    $pd_stmt->execute();
    $res = $pd_stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_row()) {
            $present_dates[] = $r[0];
        }
    }
    $total_present = count($present_dates);

    $ad_stmt = $db->prepare("SELECT log_date, reason_for_absence FROM daily_logs WHERE student_id = ? AND log_date >= ? AND log_date <= ? AND attendance_status IN ('absent','leave') ORDER BY log_date ASC");
    $ad_stmt->bind_param("iss", $student_id, $week_start, $week_end);
    $ad_stmt->execute();
    $res = $ad_stmt->get_result();
    $absent_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $total_absent = count($absent_logs);
}

// ── Selected Week Data ─────────────────────────────────────────────
$daily_logs = [];
$reflection = null;
$instructor_eval = null;
$supervisor_eval = null;

if ($week_start && $week_end) {
    $dl = $db->prepare("
        SELECT * FROM daily_logs 
        WHERE student_id = ? 
          AND log_date >= ? 
          AND log_date <= ? 
        ORDER BY log_date ASC
    ");
    $dl->bind_param("iss", $student_id, $week_start, $week_end);
    $dl->execute();
    $res = $dl->get_result();
    $daily_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    $rep_stmt = $db->prepare("SELECT * FROM weekly_reports WHERE student_id = ? AND week_number = ? LIMIT 1");
    $rep_stmt->bind_param("ii", $student_id, $selected_week);
    $rep_stmt->execute();
    $res = $rep_stmt->get_result();
    $weekly_report = $res ? $res->fetch_assoc() : null;

    if ($weekly_report) {
        $reflection = [
            'what_done' => $weekly_report['what_done'],
            'how_done'  => $weekly_report['how_done'],
            'why_done'  => $weekly_report['why_done'],
        ];
        if (!empty($weekly_report['instructor_grade']) || in_array($weekly_report['status'] ?? '', ['approved_by_instructor', 'graded', 'rejected'], true)) {
            $instructor_eval = [
                'grade'               => $weekly_report['instructor_grade'] ?? '',
                'comment'             => $weekly_report['instructor_comments'] ?? '',
                'instructor_comments' => $weekly_report['instructor_comments'] ?? '',
                'signature_type'      => $weekly_report['instructor_signature_type'] ?? null,
                'signature_value'     => $weekly_report['instructor_signature_value'] ?? null,
                'report_status'       => $weekly_report['status'] ?? '',
                'evaluated_at'        => $weekly_report['instructor_signed_at'] ?? $weekly_report['submitted_at'] ?? null,
            ];
        }
        if (!empty($weekly_report['supervisor_grade'])) {
            $supervisor_eval = [
                'weekly_grade'        => $weekly_report['supervisor_grade'],
                'supervisor_comments' => $weekly_report['supervisor_comments'],
                'evaluated_at'        => $weekly_report['submitted_at'],
            ];
        }
    }
}

// Week Attendance
$week_att = ($week_start && $week_end)
    ? internship_attendance($db, $student_id, $week_start, $week_end)
    : ['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => 0];
$week_present         = $week_att['present'];
$week_expected        = $week_att['expected'];
$week_attendance_rate = $week_att['rate'];

// Total Graded Weeks
$graded_q = $db->prepare("SELECT COUNT(DISTINCT week_number) FROM weekly_reports WHERE student_id = ? AND supervisor_grade IS NOT NULL");
$graded_q->bind_param("i", $student_id);
$graded_q->execute();
$res = $graded_q->get_result();
$row = $res ? $res->fetch_row() : null;
$student_graded = (int) ($row[0] ?? 0);

// Fetch all weekly supervisor evaluations for quick status display in dropdown
$all_sup_evals = [];
$all_rep_evals = [];
$ase_q = $db->prepare("SELECT week_number, supervisor_grade AS weekly_grade, status FROM weekly_reports WHERE student_id = ?");
$ase_q->bind_param("i", $student_id);
$ase_q->execute();
$res = $ase_q->get_result();
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $wn = (int) $r['week_number'];
        if (!empty($r['weekly_grade'])) {
            $all_sup_evals[$wn] = $r['weekly_grade'];
        }
        $all_rep_evals[$wn] = $r['status'];
    }
}

// Grade definitions for Instructor Feedback
$grade_labels = [
    'excellent'         => ['Excellent',          'text-emerald-600', 'bg-emerald-50'],
    'good'              => ['Good',               'text-blue-600',    'bg-blue-50'],
    'average'           => ['Average',            'text-amber-600',   'bg-amber-50'],
    'needs_improvement' => ['Needs Improvement',  'text-red-600',     'bg-red-50'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student_name) ?> – Student Dashboard &amp; Review</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Great+Vibes&family=Noto+Sans+Myanmar:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .scroll-margin {
            scroll-margin-top: 88px;
        }

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

            #web-app-container,
            aside,
            header,
            #sidebarBackdrop,
            #supervisorSidebarBackdrop,
            .print\:hidden,
            button,
            form button,
            .no-print,
            #printModal {
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

            .print-doc-table th,
            .print-doc-table td {
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
            if (e) e.stopPropagation();
            var menu = document.getElementById('week-menu');
            if (menu) menu.classList.toggle('hidden');
        }
        document.addEventListener('click', function(e) {
            var dd = document.getElementById('week-dropdown');
            var menu = document.getElementById('week-menu');
            if (dd && menu && !dd.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });

        function toggleProfileDropdown(e) {
            if (e) e.stopPropagation();
            var dd = document.getElementById('profile-dropdown-menu');
            if (dd) dd.classList.toggle('hidden');
        }
        document.addEventListener('click', function(e) {
            var dd = document.getElementById('profile-dropdown-menu');
            var btn = document.getElementById('profile-avatar-btn');
            if (dd && !dd.contains(e.target) && btn && !btn.contains(e.target)) {
                dd.classList.add('hidden');
            }
        });

        function toggleNotifDropdown() {
            var dd = document.getElementById('notif-dropdown');
            if (!dd) return;
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
            if (seconds < 0 || seconds < 60) return 'Just now';
            var minutes = Math.floor(seconds / 60);
            if (minutes < 60) return minutes + 'm ago';
            var hours = Math.floor(minutes / 60);
            if (hours < 24) return hours + 'h ago';
            var days = Math.floor(hours / 24);
            if (days === 1) return 'Yesterday';
            if (days < 7) return days + 'd ago';
            return date.toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short'
            });
        }

        function updateNotifTimestamps() {
            document.querySelectorAll('[data-notif-time]').forEach(function(el) {
                el.textContent = timeAgo(el.getAttribute('data-notif-time'));
            });
        }
        document.addEventListener('DOMContentLoaded', function() {
            updateNotifTimestamps();
            setInterval(updateNotifTimestamps, 60000);
        });

        function markNotifRead(el) {
            var notifId = el.getAttribute('data-notif-id');
            var redirectUrl = el.getAttribute('data-redirect-url') || 'supervisor-dashboard.php';
            var fd = new FormData();
            fd.append('notification_id', notifId);
            fd.append('mark_notification_read', '1');
            fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    updateNotifBadge(data.unread_count);
                })
                .catch(function() {});
            window.location.href = redirectUrl;
        }

        function markAllNotifsRead() {
            var fd = new FormData();
            fd.append('mark_all_notifications_read', '1');
            fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    updateNotifBadge(data.unread_count);
                })
                .catch(function() {});
        }

        function updateNotifBadge(count) {
            var existing = document.getElementById('notif-badge');
            if (count > 0) {
                if (existing) {
                    existing.textContent = count > 9 ? '9+' : count;
                } else {
                    var bell = document.querySelector('#notif-bell-wrapper button');
                    if (bell) {
                        var span = document.createElement('span');
                        span.id = 'notif-badge';
                        span.className = 'absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border border-white animate-pulse';
                        span.textContent = count > 9 ? '9+' : count;
                        bell.appendChild(span);
                    }
                }
            } else if (existing) {
                existing.remove();
            }
        }

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

<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

    <div id="web-app-container" class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR ─── -->
        <?php $active_page = 'students';
        include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

        <!-- ─── MAIN CONTAINER ─── -->
        <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

            <!-- ─── TOP HEADER ─── -->
            <header class="h-16 bg-white/90 backdrop-blur-xl border-b border-slate-200/80 flex items-center justify-between px-4 lg:px-8 shrink-0 shadow-xs relative z-[1050] print:hidden">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" onclick="toggleSupervisorSidebar()" class="lg:hidden p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle Navigation">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <a href="my-students.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition shadow-xs" title="Back to My Students">
                        ← My Students
                    </a>
                    <div class="w-px h-5 bg-slate-200 hidden sm:block"></div>
                    <div class="hidden sm:flex items-center gap-2 min-w-0">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Student Dashboard</span>
                        <span class="text-slate-300">/</span>
                        <h1 class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($student_name) ?></h1>
                    </div>
                </div>
            </header>

            <!-- ─── PAGE CONTENT ─── -->
            <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl w-full mx-auto space-y-4">

                    <!-- ═══ FLASH MESSAGES ═══ -->
                    <?php if ($eval_msg === 'saved'): ?>
                        <div class="p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-semibold flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2">
                                <span class="text-base">✓</span>
                                <span>Evaluation for <strong>Week <?= $selected_week ?></strong> has been successfully saved &amp; approved.</span>
                            </div>
                            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800 cursor-pointer">✕</button>
                        </div>
                    <?php elseif ($eval_msg === 'invalid_grade'): ?>
                        <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-semibold flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2">
                                <span class="text-base">⚠️</span>
                                <span>Please select a valid grade (A, B, C, D, or F).</span>
                            </div>
                            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800 cursor-pointer">✕</button>
                        </div>
                    <?php endif; ?>

                    <!-- ════ TOP CARD: STUDENT IDENTITY & QUICK ACTIONS ════ -->
                    <div id="section-student-summary" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                        <div class="p-5 border-b border-slate-100">
                            <div class="flex items-center justify-between flex-wrap gap-4">
                                <!-- Student Profile Strip -->
                                <div class="flex items-center gap-3.5 min-w-0">
                                    <?php if ($profile_pic): ?>
                                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-12 h-12 rounded-xl object-cover border-2 border-indigo-100 shadow-xs shrink-0">
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-base font-black shrink-0 shadow-xs">
                                            <?= strtoupper(substr($student_name, 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <h2 class="text-base font-black text-slate-800 leading-tight"><?= htmlspecialchars($student_name) ?></h2>
                                            <?php if ($not_started): ?>
                                                <span class="text-[11px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">Not Started</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-slate-400 font-mono mt-0.5">
                                            Roll: <?= htmlspecialchars($student_roll ?: '—') ?> · <?= htmlspecialchars($profile['email'] ?? '') ?>
                                        </p>
                                        <div class="flex items-center gap-2 mt-2 flex-wrap text-xs">
                                            <?php if ($company_name): ?>
                                                <span class="font-semibold text-blue-700 bg-blue-50 px-2.5 py-0.5 rounded-lg border border-blue-200/60">🏢 <?= htmlspecialchars($company_name) ?></span>
                                            <?php endif; ?>
                                            <?php if ($job_role): ?>
                                                <span class="font-semibold text-violet-700 bg-violet-50 px-2.5 py-0.5 rounded-lg border border-violet-200/60">💼 <?= htmlspecialchars($job_role) ?></span>
                                            <?php endif; ?>
                                            <?php if ($major): ?>
                                                <span class="font-semibold text-slate-600 bg-slate-100 px-2.5 py-0.5 rounded-lg"><?= htmlspecialchars($major) ?></span>
                                            <?php endif; ?>
                                            <?php if ($academic_year): ?>
                                                <span class="font-semibold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-lg font-mono border border-indigo-200/60"><?= htmlspecialchars($academic_year) ?></span>
                                            <?php endif; ?>
                                            <?php if ($intern_start || $intern_end): ?>
                                                <span class="font-semibold text-slate-500 bg-slate-50 px-2.5 py-0.5 rounded-lg border border-slate-200/60">
                                                    📅 <?= $intern_start ? (new DateTime($intern_start))->format('d M Y') : '—' ?> – <?= $intern_end ? (new DateTime($intern_end))->format('d M Y') : '—' ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Quick Action Buttons -->
                                <div class="flex items-center gap-2 flex-wrap shrink-0 print:hidden">
                                    <button type="button" onclick="openPrintModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#005f73] hover:bg-[#0a9396] text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                        </svg>
                                        <span>Print</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Statistics Strip -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-100 bg-slate-50/50 text-xs">
                            <div class="p-3.5 text-center">
                                <p class="text-base font-black text-slate-800">⏱️ <?= $intern_hours ?>h <?= $intern_mins ?>m</p>
                                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Total Hours Worked</p>
                            </div>
                            <div class="p-3.5 text-center">
                                <p class="text-base font-black text-emerald-700">✅ <?= $intern_att['rate'] ?>%</p>
                                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Attendance (<?= $intern_att['present'] ?>/<?= $intern_att['expected'] ?> d)</p>
                            </div>
                            <div class="p-3.5 text-center">
                                <p class="text-base font-black text-indigo-700">📊 <?= $student_graded ?> / <?= $total_internship_weeks ?></p>
                                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Weeks Graded</p>
                            </div>
                        </div>

                        <!-- Week Selector & Navigation Summary Bar -->
                        <div class="px-5 py-3 bg-gradient-to-r from-slate-50 to-slate-100/50 flex items-center justify-between flex-wrap gap-3 text-xs border-t border-slate-100">
                            <!-- Left: Week Navigation Controls -->
                            <div class="flex items-center gap-2 flex-wrap">
                                <!-- Week Select Dropdown -->
                                <div class="relative inline-flex items-center">
                                    <span class="absolute left-2.5 pointer-events-none text-xs">📆</span>
                                    <select id="weekSelectDropdown"
                                        onchange="window.location.href='view-student-dashboard.php?id=<?= $student_id ?>&week=' + this.value"
                                        class="bg-white border border-slate-200 hover:border-indigo-400 focus:border-indigo-500 rounded-xl pl-8 pr-8 py-1.5 text-xs font-bold text-slate-700 shadow-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition cursor-pointer appearance-none">
                                        <?php if (!empty($weeks)): ?>
                                            <?php foreach ($weeks as $wn => $wr):
                                                $opt_s = new DateTime($wr['start']);
                                                $opt_e = new DateTime($wr['end']);
                                                $opt_range = $opt_s->format('d M') . ' – ' . $opt_e->format('d M Y');

                                                $status_text = '';
                                                if (isset($all_sup_evals[$wn])) {
                                                    $status_text = ' — ✓ Graded (' . $all_sup_evals[$wn] . ')';
                                                } elseif (isset($all_rep_evals[$wn]) && $all_rep_evals[$wn] === 'approved_by_instructor') {
                                                    $status_text = ' — ⏳ Ready for Review';
                                                } elseif (isset($all_rep_evals[$wn]) && $all_rep_evals[$wn] === 'rejected') {
                                                    $status_text = ' — ❌ Instructor Rejected';
                                                } elseif ($wn === $auto_week) {
                                                    $status_text = ' — ★ Current Week';
                                                }
                                            ?>
                                                <option value="<?= $wn ?>" <?= $wn === $selected_week ? 'selected' : '' ?>>
                                                    Week <?= $wn ?> (<?= $opt_range ?>)<?= $status_text ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="1" selected>Week 1</option>
                                        <?php endif; ?>
                                    </select>
                                    <span class="absolute right-2.5 pointer-events-none text-slate-400 text-[10px]">▼</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ════ SECTION 1: DAILY LOGS TABLE ════ -->
                    <div id="section-daily-logs" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                        <div class="px-5 py-3.5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">📝</span>
                                Daily Logs – Week <?= $selected_week ?>
                            </h3>
                            <span class="text-xs text-slate-400 font-medium"><?= count($daily_logs) ?> log entry<?= count($daily_logs) !== 1 ? 's' : '' ?></span>
                        </div>

                        <?php if (!empty($daily_logs)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="bg-slate-50/90 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-100">
                                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[130px] align-top">
                                                Date / Day
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">ရက်စွဲ / နေ့</span>
                                            </th>
                                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[140px] align-top">
                                                Attendance Status
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">တက်ရောက်မှုအခြေအနေ</span>
                                            </th>
                                            <th class="px-4 py-3 text-left min-w-[180px] align-top">
                                                Intended Task
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">ဆောင်ရွက်မည့်လုပ်ငန်း</span>
                                            </th>
                                            <th class="px-4 py-3 text-left min-w-[220px] align-top">
                                                Actual Task Performed
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</span>
                                            </th>
                                            <th class="px-4 py-3 text-left min-w-[160px] align-top">
                                                Tools / Tech Used
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">အသုံးပြုသောပစ္စည်းများ</span>
                                            </th>
                                            <th class="px-4 py-3 text-left min-w-[180px] align-top">
                                                Knowledge Gained
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">လေ့လာသိရှိသော အသိပညာ</span>
                                            </th>
                                            <th class="px-4 py-3 text-left whitespace-nowrap min-w-[90px] align-top">
                                                Duration
                                                <span class="block text-[10px] font-normal normal-case text-slate-400 mt-0.5">ကြာချိန်</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($daily_logs as $log):
                                            $is_absent = ($log['attendance_status'] ?? 'present') === 'absent' || (($log['attendance_status'] ?? '') === 'leave' && stripos($log['reason_for_absence'] ?? '', 'Public Holiday') === 0);
                                            $reason = $log['reason_for_absence'] ?? '';
                                            $is_holiday = ($log['attendance_status'] ?? '') === 'leave' || stripos($reason, 'Public Holiday') === 0;
                                            $actual_tasks = ($log['tasks_performed'] ?: ($log['actual_tasks'] ?? '')) ?: '';
                                        ?>
                                            <tr class="hover:bg-slate-50/60 transition-colors">
                                                <td class="px-4 py-3 font-semibold text-slate-700 whitespace-nowrap align-top">
                                                    <div><?= htmlspecialchars((new DateTime($log['log_date']))->format('D, d M Y')) ?></div>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                                    <?php if ($is_holiday): ?>
                                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200/60" title="<?= htmlspecialchars($reason) ?>">🏖️ Public Holiday</span>
                                                    <?php elseif (!$is_absent): ?>
                                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200/60">✅ Present</span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-red-700 bg-red-50 px-2 py-0.5 rounded-md border border-red-200/60" title="<?= htmlspecialchars($reason) ?>">❌ Absent</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-700 font-medium whitespace-normal break-words leading-relaxed align-top">
                                                    <?php if ($is_absent): ?>
                                                        <span class="text-slate-500 italic"><?= $reason ? htmlspecialchars($reason) : '—' ?></span>
                                                    <?php else: ?>
                                                        <?= !empty($log['task_title']) ? htmlspecialchars($log['task_title']) : '—' ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-700 font-normal whitespace-normal break-words leading-relaxed align-top">
                                                    <?php if ($is_absent): ?>
                                                        <span class="text-slate-400">—</span>
                                                    <?php else: ?>
                                                        <?= !empty($actual_tasks) ? nl2br(htmlspecialchars($actual_tasks)) : '—' ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-700 font-medium whitespace-normal break-words leading-relaxed align-top">
                                                    <?php if ($is_absent || empty($log['tools_used'])): ?>
                                                        <span class="text-slate-400">—</span>
                                                    <?php else: ?>
                                                        <div class="flex items-center gap-1.5 flex-wrap">
                                                            <?php foreach (explode(',', $log['tools_used']) as $tool): ?>
                                                                <span class="px-2 py-0.5 bg-slate-50 border border-slate-200/80 rounded-md text-[11px] font-mono font-medium text-teal-700 shadow-2xs"><?= htmlspecialchars(trim($tool)) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-slate-700 font-normal whitespace-normal break-words leading-relaxed align-top">
                                                    <?php if ($is_absent || empty($log['learnt_skills'])): ?>
                                                        <span class="text-slate-400">—</span>
                                                    <?php else: ?>
                                                        <?= nl2br(htmlspecialchars($log['learnt_skills'])) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 font-mono text-blue-600 font-bold whitespace-nowrap align-top">
                                                    <?= htmlspecialchars($log['calculated_duration'] ?: '00:00') ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-8 text-center">
                                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-2">📭</div>
                                <p class="text-xs font-semibold text-slate-500">No daily logs recorded for Week <?= $selected_week ?>.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ════ SECTION 2: WEEKLY REFLECTION ════ -->
                    <div id="section-weekly-reflection" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                        <div class="px-4 py-2.5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                            <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-5 h-5 rounded-md bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">📊</span>
                                Weekly Reflection – Week <?= $selected_week ?>
                            </h3>
                        </div>
                        <?php if ($reflection): ?>
                            <div class="p-3.5 space-y-2.5 text-xs">
                                <div>
                                    <span class="font-bold text-slate-500 uppercase tracking-wider block mb-1">❓ 1. What was done? <span class="text-slate-400 font-normal">/ ဘာလုပ်သလဲ</span></span>
                                    <div class="bg-slate-50/70 border border-slate-200/70 rounded-xl p-2.5 text-slate-700 leading-relaxed">
                                        <?= nl2br(htmlspecialchars($reflection['what_done'] ?? '')) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="font-bold text-slate-500 uppercase tracking-wider block mb-1">⚙️ 2. How was it done? <span class="text-slate-400 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></span>
                                    <div class="bg-slate-50/70 border border-slate-200/70 rounded-xl p-2.5 text-slate-700 leading-relaxed">
                                        <?= nl2br(htmlspecialchars($reflection['how_done'] ?? '')) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="font-bold text-slate-500 uppercase tracking-wider block mb-1">🎯 3. Why was it done? <span class="text-slate-400 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></span>
                                    <div class="bg-slate-50/70 border border-slate-200/70 rounded-xl p-2.5 text-slate-700 leading-relaxed">
                                        <?= nl2br(htmlspecialchars($reflection['why_done'] ?? '')) ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="p-6 text-center">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-lg mx-auto mb-1.5">📭</div>
                                <p class="text-xs font-semibold text-slate-500">No weekly reflection submitted for Week <?= $selected_week ?>.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ════ UNIFIED CARD: SIGNATURES & EVALUATIONS ════ -->
                    <?php
                    $grade_defs = [
                        'A' => ['Excellent',     'text-emerald-600', 'border-emerald-200', 'hover:border-emerald-400'],
                        'B' => ['Good',          'text-blue-600',    'border-blue-200',    'hover:border-blue-400'],
                        'C' => ['Satisfactory',  'text-slate-700',   'border-slate-200',   'hover:border-indigo-400'],
                        'D' => ['Pass',          'text-amber-600',   'border-amber-200',   'hover:border-amber-400'],
                        'F' => ['Fail',          'text-red-500',     'border-red-200',     'hover:border-red-400']
                    ];
                    $existing_grade = $supervisor_eval['weekly_grade'] ?? 'C';
                    ?>
                    <div id="section-signatures-evaluations" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                        <!-- Header -->
                        <div class="px-4 py-2.5 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between flex-wrap gap-2">
                            <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-5 h-5 rounded-md bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">🛡️</span>
                                Signatures &amp; Evaluations – Week <?= $selected_week ?>
                            </h3>
                            <div>
                                <?php if ($supervisor_eval): ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200/60 px-2 py-0.5 rounded-full">✓ Fully Reviewed</span>
                                <?php elseif ($instructor_eval && in_array($instructor_eval['report_status'], ['approved_by_instructor', 'graded', 'approved_by_supervisor'], true)): ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200/60 px-2 py-0.5 rounded-full">⏳ Awaiting University Evaluation</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">In Progress</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 3-Column Verification Strip -->
                        <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-100 p-4 bg-white gap-4 md:gap-0">

                            <!-- ─── COLUMN 1: STUDENT SIGNATURE ─── -->
                            <div class="space-y-2 md:pr-4 py-1 md:py-0">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Student Signature</span>
                                    <?php if (!empty($weekly_report['student_signature_value'])): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200/60 px-1.5 py-0.5 rounded-md">✅ Signed</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200/60 px-1.5 py-0.5 rounded-md">⏳ Pending</span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($weekly_report['student_signature_value'])): ?>
                                    <div class="flex items-center gap-2 min-h-[36px]">
                                        <?php if (($weekly_report['student_signature_type'] ?? '') === 'typed'): ?>
                                            <span class="inline-block px-2.5 py-1 bg-slate-50 border border-slate-200/70 rounded-lg font-bold text-slate-800 shadow-2xs" style="font-family:'Great Vibes', cursive; font-size:20px; line-height:1;">
                                                <?= htmlspecialchars($weekly_report['student_signature_value']) ?>
                                            </span>
                                        <?php else: ?>
                                            <?php
                                            $std_sig_src = $weekly_report['student_signature_value'];
                                            if (!str_starts_with($std_sig_src, 'data:') && !str_starts_with($std_sig_src, 'http') && !str_starts_with($std_sig_src, '../uploads/') && !str_starts_with($std_sig_src, 'uploads/')) {
                                                $std_sig_src = '../uploads/signatures/' . $std_sig_src;
                                            } elseif (str_starts_with($std_sig_src, 'uploads/')) {
                                                $std_sig_src = '../' . $std_sig_src;
                                            }
                                            ?>
                                            <img src="<?= htmlspecialchars($std_sig_src) ?>" alt="Student Signature" class="max-h-9 object-contain bg-slate-50 border border-slate-200/70 rounded-lg p-0.5 shadow-2xs">
                                        <?php endif; ?>
                                    </div>

                                    <?php
                                    $student_signed_date = '';
                                    if (!empty($weekly_report['student_signed_at'])) {
                                        $student_signed_date = (new DateTime($weekly_report['student_signed_at']))->format('d M Y');
                                    } elseif (!empty($weekly_report['submitted_at'])) {
                                        $student_signed_date = (new DateTime($weekly_report['submitted_at']))->format('d M Y');
                                    }
                                    ?>
                                    <p class="text-[11px] text-slate-400">Signed: <span class="font-semibold text-slate-600"><?= $student_signed_date ?: '—' ?></span></p>
                                <?php else: ?>
                                    <p class="text-xs text-slate-400 italic py-2">No student signature submitted yet.</p>
                                <?php endif; ?>
                            </div>

                            <!-- ─── COLUMN 2: INSTRUCTOR FEEDBACK ─── -->
                            <div class="space-y-2 md:px-4 py-2 md:py-0">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Instructor Feedback</span>
                                    <?php if ($instructor_eval && in_array($instructor_eval['report_status'], ['approved_by_instructor', 'graded', 'approved_by_supervisor'], true)):
                                        $ig = $grade_labels[$instructor_eval['grade']] ?? ['—', 'text-slate-600', 'bg-slate-100'];
                                    ?>
                                        <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md <?= $ig[1] ?> <?= $ig[2] ?> border border-current/20">
                                            <?= htmlspecialchars($ig[0]) ?>
                                        </span>
                                    <?php elseif ($instructor_eval && $instructor_eval['report_status'] === 'rejected'): ?>
                                        <span class="inline-flex items-center text-[10px] font-bold text-red-700 bg-red-50 border border-red-200/60 px-1.5 py-0.5 rounded-md">❌ Rejected</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center text-[10px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-md">⏳ Waiting</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($instructor_eval && in_array($instructor_eval['report_status'], ['approved_by_instructor', 'graded', 'approved_by_supervisor'], true)): ?>
                                    <div class="text-xs text-slate-700 leading-relaxed bg-slate-50/70 border border-slate-200/60 rounded-lg p-2 min-h-[36px]">
                                        <?= !empty($instructor_eval['comment']) ? nl2br(htmlspecialchars($instructor_eval['comment'])) : '<span class="italic text-slate-400">No written comments provided.</span>' ?>
                                    </div>
                                    <div class="flex items-center justify-between text-[11px] text-slate-400">
                                        <span>Date: <span class="font-semibold text-slate-600"><?= !empty($instructor_eval['evaluated_at']) ? (new DateTime($instructor_eval['evaluated_at']))->format('d M Y') : '—' ?></span></span>
                                        <?php if (!empty($instructor_eval['signature_value'])): ?>
                                            <span class="text-emerald-700 font-bold text-[10px]">✓ Signed</span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($instructor_eval && $instructor_eval['report_status'] === 'rejected'): ?>
                                    <div class="text-xs text-red-700 leading-relaxed bg-red-50/70 border border-red-200/60 rounded-lg p-2 min-h-[36px]">
                                        <?= !empty($instructor_eval['instructor_comments']) ? nl2br(htmlspecialchars($instructor_eval['instructor_comments'])) : 'Rejected (revision requested).' ?>
                                    </div>
                                    <p class="text-[11px] text-slate-400">Date: <span class="font-semibold text-slate-600"><?= !empty($instructor_eval['evaluated_at']) ? (new DateTime($instructor_eval['evaluated_at']))->format('d M Y') : '—' ?></span></p>
                                <?php else: ?>
                                    <p class="text-xs text-slate-400 italic py-2">Waiting for company instructor review.</p>
                                <?php endif; ?>
                            </div>

                            <!-- ─── COLUMN 3: SUPERVISOR EVALUATION ─── -->
                            <div class="space-y-2 md:pl-4 py-2 md:py-0">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Supervisor Evaluation</span>
                                    <?php if ($supervisor_eval): ?>
                                        <span class="inline-flex items-center text-[10px] font-black px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200/60">
                                            Grade: <?= htmlspecialchars($supervisor_eval['weekly_grade']) ?> (<?= $grade_defs[$supervisor_eval['weekly_grade']][0] ?? '' ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200/60 px-1.5 py-0.5 rounded-md">⏳ Awaiting Grade</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($supervisor_eval): ?>
                                    <div class="text-xs text-slate-700 leading-relaxed bg-slate-50/70 border border-slate-200/60 rounded-lg p-2 min-h-[36px]">
                                        <?= !empty($supervisor_eval['supervisor_comments']) ? nl2br(htmlspecialchars($supervisor_eval['supervisor_comments'])) : '<span class="italic text-slate-400">No written feedback recorded.</span>' ?>
                                    </div>
                                    <div class="flex items-center justify-between text-[11px]">
                                        <span class="text-slate-400">Date: <span class="font-semibold text-slate-600"><?= (new DateTime($supervisor_eval['evaluated_at']))->format('d M Y') ?></span></span>
                                        <button type="button" onclick="toggleSupEvalForm()" class="text-indigo-600 hover:text-indigo-800 font-bold underline cursor-pointer">
                                            ✏️ Modify Grade
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="py-1">
                                        <button type="button" onclick="toggleSupEvalForm()" class="w-full py-1.5 px-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center justify-center gap-1.5">
                                            <span>🎓 Grade &amp; Evaluate Week <?= $selected_week ?></span>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>

                        <!-- ─── EXPANDABLE SUPERVISOR EVALUATION FORM ─── -->
                        <div id="sup-eval-form-container" class="<?= $supervisor_eval ? 'hidden' : '' ?> border-t border-indigo-100 bg-slate-50/60 p-4">
                            <form method="POST" class="space-y-3 text-xs">
                                <div class="flex items-center justify-between text-xs text-indigo-800 font-bold">
                                    <span><?= $supervisor_eval ? '✏️ Modify University Evaluation (Week ' . $selected_week . ')' : '🎓 University Supervisor Evaluation (Week ' . $selected_week . ')' ?></span>
                                    <?php if ($supervisor_eval): ?>
                                        <button type="button" onclick="toggleSupEvalForm()" class="text-xs font-semibold text-slate-400 hover:text-slate-600 underline cursor-pointer">Close</button>
                                    <?php endif; ?>
                                </div>

                                <!-- Grade Selector -->
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Weekly Grade</label>
                                    <div class="grid grid-cols-5 gap-2">
                                        <?php
                                        foreach ($grade_defs as $g => $info):
                                            $isSelected = ($g === $existing_grade);
                                        ?>
                                            <label class="flex flex-col items-center gap-0.5 p-2 bg-white border <?= $isSelected ? 'border-indigo-600 ring-2 ring-indigo-500/20 bg-indigo-50/30' : 'border-slate-200' ?> rounded-xl cursor-pointer hover:shadow-xs transition text-center">
                                                <input type="radio" name="weekly_grade" value="<?= $g ?>" <?= $isSelected ? 'checked' : '' ?> class="accent-indigo-600 text-xs">
                                                <span class="text-sm font-black <?= $info[1] ?>"><?= $g ?></span>
                                                <span class="text-[9px] text-slate-500 font-semibold uppercase tracking-wider"><?= $info[0] ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Supervisor Comments -->
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Supervisor Assessment &amp; Feedback Comments</label>
                                    <textarea name="supervisor_comments" rows="3" placeholder="Write university supervisor feedback, recommendations, or grading comments for the student…"
                                        class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition resize-none shadow-xs"><?= htmlspecialchars($supervisor_eval['supervisor_comments'] ?? '') ?></textarea>
                                </div>

                                <!-- Submit Button -->
                                <div>
                                    <button type="submit" name="submit_sup_eval" class="w-full py-2 px-4 bg-gradient-to-r from-[#005f73] via-teal-700 to-[#0a9396] hover:from-[#004e5f] hover:to-[#087f82] text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center justify-center gap-1.5">
                                        <span><?= $supervisor_eval ? '💾 Update University Grade' : '✅ Submit & Approve Evaluation' ?></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
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
                    <input type="checkbox" id="print_opt_summary" checked class="accent-[#005f73] rounded">
                    <span>Student Summary</span>
                </label>
                <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                    <input type="checkbox" id="print_opt_logs" checked class="accent-[#005f73] rounded">
                    <span>Daily Logs</span>
                </label>
                <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                    <input type="checkbox" id="print_opt_reflections" checked class="accent-[#005f73] rounded">
                    <span>Weekly Reflections</span>
                </label>
                <label class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-slate-50 cursor-pointer border border-slate-100">
                    <input type="checkbox" id="print_opt_evaluations" checked class="accent-[#005f73] rounded">
                    <span>Signatures &amp; Evaluations</span>
                </label>
                <div class="pt-1">
                    <label class="flex items-center gap-2.5 p-2 rounded-xl bg-teal-50/60 hover:bg-teal-50 cursor-pointer border border-teal-200 text-teal-900 font-bold">
                        <input type="checkbox" id="print_opt_full" onchange="toggleFullReport(this)" checked class="accent-[#005f73] rounded">
                        <span>Full Internship Report</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" onclick="closePrintModal()" class="px-3.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition cursor-pointer">
                    Cancel
                </button>
                <button type="button" onclick="executePrint()" class="px-4 py-1.5 text-xs font-bold text-white bg-[#005f73] hover:bg-[#0a9396] rounded-xl shadow-xs transition cursor-pointer flex items-center gap-1.5">
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
                        <td class="py-1"><?= htmlspecialchars($job_role ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">University Supervisor:</td>
                        <td class="py-1"><?= htmlspecialchars($sup_name ?: ($profile['supervisor_name'] ?? '—')) ?></td>
                        <td class="font-bold py-1">Company Instructor:</td>
                        <td class="py-1"><?= htmlspecialchars($profile['contact_person'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">Internship Period:</td>
                        <td class="py-1" colspan="3">
                            <?= $intern_start ? (new DateTime($intern_start))->format('d M Y') : '—' ?> to <?= $intern_end ? (new DateTime($intern_end))->format('d M Y') : '—' ?>
                            (<?= $total_internship_weeks ?> Weeks)
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
                        <td class="py-1"><?= $all_present_days ?> days</td>
                        <td class="font-bold py-1">Days Absent / Leave:</td>
                        <td class="py-1"><?= $all_absent_days ?> days</td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">Total Hours Worked:</td>
                        <td class="py-1" colspan="3"><?= $intern_hours ?>h <?= $intern_mins ?>m</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ── 2. Daily Activity Logs ── -->
        <div id="print-doc-logs" class="mb-4">
            <div class="print-section-title">3. Daily Activity Logs</div>
            <?php if (!empty($all_daily_logs)): ?>
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
                        <?php foreach ($all_daily_logs as $log):
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
                    $w_rep = $all_weekly_reports[$wn] ?? null;
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
                    $w_rep = $all_weekly_reports[$wn] ?? null;
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
                                        if (!str_starts_with($sig_src, 'data:') && !str_starts_with($sig_src, 'http') && !str_starts_with($sig_src, '../uploads/') && !str_starts_with($sig_src, 'uploads/')) {
                                            $sig_src = '../uploads/signatures/' . $sig_src;
                                        } elseif (str_starts_with($sig_src, 'uploads/')) {
                                            $sig_src = '../' . $sig_src;
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
                                        if (!str_starts_with($i_sig_src, 'data:') && !str_starts_with($i_sig_src, 'http') && !str_starts_with($i_sig_src, '../uploads/') && !str_starts_with($i_sig_src, 'uploads/')) {
                                            $i_sig_src = '../uploads/signatures/' . $i_sig_src;
                                        } elseif (str_starts_with($i_sig_src, 'uploads/')) {
                                            $i_sig_src = '../' . $i_sig_src;
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
                                    <p class="text-[8pt] text-gray-500">Supervisor: <?= htmlspecialchars($sup_name ?: 'Supervisor') ?></p>
                                    <p class="text-[8pt] text-gray-500">Date: <?= !empty($w_rep['submitted_at']) ? (new DateTime($w_rep['submitted_at']))->format('d M Y') : '—' ?></p>
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

    <?php include __DIR__ . '/includes/notification_delete.php'; ?>
    <script src="../assets/js/notifications.js"></script>
    <script>
        function toggleSupEvalForm() {
            var c = document.getElementById('sup-eval-form-container');
            if (c) {
                c.classList.toggle('hidden');
                if (!c.classList.contains('hidden')) {
                    c.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                }
            }
        }
    </script>
</body>

</html>