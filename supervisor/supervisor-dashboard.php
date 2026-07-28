<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

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
            .footer { background: #1e293b; color: #94a3b8; padding: 15px; border-radius: 0 0 10px 10px; text-align: center; font-size: 12px; }
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

    // Set email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: InternReport System <noreply@internreport.com>\r\n";
    $headers .= "Reply-To: " . $supervisor_email . "\r\n";

    // Send email
    $email_sent = mail($supervisor_email, $subject, $body, $headers);

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

// Valid academic years for filter dropdown
$vy_stmt = $pdo->prepare("SELECT DISTINCT u.academic_year FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE sp.supervisor_id = ? AND u.academic_year IS NOT NULL AND u.academic_year != '' ORDER BY u.academic_year DESC");
$vy_stmt->execute([$sup_id]);
$valid_years = $vy_stmt->fetchAll(PDO::FETCH_COLUMN);

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

// 1. ALL STUDENTS: Count assigned students for selected year
if ($selected_year && preg_match('/^\d{4}-\d{4}$/', $selected_year)) {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ? AND u.academic_year = ?");
    $sc->execute([$sup_id, $selected_year]);
} else {
    $sc = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ?");
    $sc->execute([$sup_id]);
}
$total_assigned = (int) $sc->fetchColumn();

// 2. COMPANIES: Count distinct companies for selected year
if ($selected_year && preg_match('/^\d{4}-\d{4}$/', $selected_year)) {
    $cc = $pdo->prepare("SELECT COUNT(DISTINCT sp.company_name) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ? AND u.academic_year = ? AND sp.company_name IS NOT NULL AND sp.company_name != ''");
    $cc->execute([$sup_id, $selected_year]);
} else {
    $cc = $pdo->prepare("SELECT COUNT(DISTINCT sp.company_name) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ? AND sp.company_name IS NOT NULL AND sp.company_name != ''");
    $cc->execute([$sup_id]);
}
$company_count = (int) $cc->fetchColumn();

// Build base query for weekly log counts (students in selected year)
$base_where = "u.role = 'student' AND sp.supervisor_id = ?";
$base_params = [$sup_id];
if ($selected_year && preg_match('/^\d{4}-\d{4}$/', $selected_year)) {
    $base_where .= " AND u.academic_year = ?";
    $base_params[] = $selected_year;
}

// 3. BEHIND SCHEDULE: Students with 0 logs this week
$red_sql = "SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id
    WHERE {$base_where}
    AND u.id NOT IN (
        SELECT DISTINCT dl.internship_id FROM daily_logs dl
        WHERE dl.log_date BETWEEN ? AND ?
    )";
$red_params = array_merge($base_params, [$weekStart, $weekEnd]);
$red_q = $pdo->prepare($red_sql);
$red_q->execute($red_params);
$behind_schedule = (int) $red_q->fetchColumn();

// 4. IN PROGRESS: Students with 1-4 logs this week
$amber_sql = "SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id
    WHERE {$base_where}
    AND (
        SELECT COUNT(*) FROM daily_logs dl
        WHERE dl.internship_id = u.id AND dl.log_date BETWEEN ? AND ?
    ) BETWEEN 1 AND 4";
$amber_params = array_merge($base_params, [$weekStart, $weekEnd]);
$amber_q = $pdo->prepare($amber_sql);
$amber_q->execute($amber_params);
$in_progress = (int) $amber_q->fetchColumn();

// 5. COMPLETE: Students with 5+ logs this week
$green_sql = "SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id
    WHERE {$base_where}
    AND (
        SELECT COUNT(*) FROM daily_logs dl
        WHERE dl.internship_id = u.id AND dl.log_date BETWEEN ? AND ?
    ) >= 5";
$green_params = array_merge($base_params, [$weekStart, $weekEnd]);
$green_q = $pdo->prepare($green_sql);
$green_q->execute($green_params);
$complete = (int) $green_q->fetchColumn();

// Summary counts for cards
$warning_counts = [
    'red'   => $behind_schedule,
    'amber' => $in_progress,
    'green' => $complete,
    'none'  => $total_assigned - ($behind_schedule + $in_progress + $complete)
];

// ══════════════════════════════════════════════════════════════════════
// STUDENT LIST (for table display)
// ══════════════════════════════════════════════════════════════════════
$stu_sql = "
    SELECT u.id AS uid, u.username, u.email, u.academic_year,
           sp.full_name, sp.student_roll, sp.major, sp.company_name
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE {$base_where}
    ORDER BY sp.full_name ASC
";
$stu_stmt = $pdo->prepare($stu_sql);
$stu_stmt->execute($base_params);
$students = $stu_stmt->fetchAll();

// ── Progress Badges for Table ───────────────────────────────────────
$progress_badges = [];
$progress_status = [];

foreach ($students as $s) {
    $log_count_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $log_count_q->execute([$s['uid'], $weekStart, $weekEnd]);
    $log_count = (int) $log_count_q->fetchColumn();

    $badge_html = '';
    $badge_type = 'none';

    if ($dayOfWeek >= 3 && $log_count === 0) {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
            🔴 Behind Schedule (No Logs)
        </span>';
        $badge_type = 'red';

        sendRedBadgeAlert(
            $pdo, $sup_id, $sup_name, $sup_email,
            $s['uid'], $s['full_name'] ?: $s['username'],
            $s['student_roll'], $s['company_name']
        );
    } elseif ($log_count >= 1 && $log_count <= 4) {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
            🟡 In Progress (' . $log_count . '/5 Logs)
        </span>';
        $badge_type = 'amber';
    } elseif ($log_count >= 5) {
        $badge_html = '<span class="inline-flex items-center gap-1.5 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            🟢 Complete (5/5 Logs)
        </span>';
        $badge_type = 'green';
    }

    $progress_badges[$s['uid']] = $badge_html;
    $progress_status[$s['uid']] = $badge_type;
}

// Unreviewed status per student
$unreviewed = [];
foreach ($students as $s) {
    $q = $pdo->prepare("
        SELECT COUNT(*) FROM report_evaluations re
        WHERE re.student_id = ?
          AND re.report_status = 'approved_by_instructor'
          AND NOT EXISTS (
              SELECT 1 FROM supervisor_evaluations se
              WHERE se.student_id = re.student_id AND se.week_number = re.week_number
          )
    ");
    $q->execute([$s['uid']]);
    $unreviewed[$s['uid']] = (int) $q->fetchColumn();
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
    fputcsv($output, ['Roll No', 'Student Name', 'Email', 'Major', 'Company', 'Academic Year', 'Weekly Status']);

    // Data rows
    foreach ($students as $s) {
        $status_label = 'N/A';
        $badge = $progress_status[$s['uid']] ?? 'none';
        if ($badge === 'red') $status_label = 'Behind Schedule';
        elseif ($badge === 'amber') $status_label = 'In Progress';
        elseif ($badge === 'green') $status_label = 'Complete';

        fputcsv($output, [
            $s['student_roll'] ?? '',
            $s['full_name'] ?: $s['username'],
            $s['email'] ?? '',
            $s['major'] ?? '',
            $s['company_name'] ?? '',
            $s['academic_year'] ?? '',
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
                    <span class="block text-[9px] font-bold text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded mt-0.5">SUPERVISOR</span>
                </div>
            </div>
        </div>
        <nav class="flex-1 py-5 px-3 space-y-1">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-indigo-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/30 transition-all duration-200">
                <span class="text-base">📊</span> Dashboard
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">👤</span> Profile
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
                <h1 class="text-base font-bold text-slate-800">University Supervisor Dashboard</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if ($selected_year): ?>
                    <span class="text-[9px] font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
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
                        <p class="text-[10px] text-slate-400">Supervisor</p>
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
                                <p class="text-[11px] text-slate-400 mt-0.5">Select academic year to filter all dashboard data</p>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center text-xl shadow-lg shadow-indigo-500/30">🎓</div>
                            <div>
                                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total Students</p>
                                <p class="text-2xl font-black text-slate-800"><?= $total_assigned ?></p>
                                <?php if ($selected_year): ?>
                                <p class="text-[10px] text-indigo-500 font-bold font-mono"><?= htmlspecialchars($selected_year) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xl shadow-lg shadow-blue-500/30">🏢</div>
                            <div>
                                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Companies</p>
                                <p class="text-2xl font-black text-slate-800"><?= $company_count ?></p>
                                <?php if ($selected_year): ?>
                                <p class="text-[10px] text-blue-500 font-bold font-mono"><?= htmlspecialchars($selected_year) ?></p>
                                <?php endif; ?>
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
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-200">Current Calendar Week</p>
                                <p class="text-lg font-bold mt-0.5"><?= (new DateTime('monday this week'))->format('d M') ?> – <?= (new DateTime('sunday this week'))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <div class="text-right bg-white/10 backdrop-blur-sm rounded-xl px-5 py-3">
                            <p class="text-[11px] font-semibold text-indigo-200">Day <?= $dayOfWeek ?>/7</p>
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
                            <span class="ml-auto text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-200/60 font-mono">
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
                            <p class="text-[11px] font-bold text-slate-700 uppercase tracking-wider">All Students</p>
                            <p class="text-[10px] text-slate-500 mt-1">Total assigned</p>
                        </a>

                        <!-- Red Warnings (Filter: red) -->
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'red'])) ?>" class="bg-gradient-to-br <?= $filter_status === 'red' ? 'from-red-100 to-red-200 border-red-300 ring-2 ring-red-400 ring-offset-2' : 'from-red-50 to-red-100/50 border-red-200/60 hover:from-red-100 hover:to-red-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <?php if ($behind_schedule > 0): ?>
                                <span class="w-3 h-3 rounded-full bg-red-500 animate-pulse shadow-lg shadow-red-500/40"></span>
                                <?php endif; ?>
                                <span class="text-3xl font-black text-red-600"><?= $behind_schedule ?></span>
                            </div>
                            <p class="text-[11px] font-bold text-red-700 uppercase tracking-wider">Behind Schedule</p>
                            <p class="text-[10px] text-red-500 mt-1">No logs submitted</p>
                        </a>

                        <!-- Amber Warnings (Filter: amber) -->
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'amber'])) ?>" class="bg-gradient-to-br <?= $filter_status === 'amber' ? 'from-amber-100 to-amber-200 border-amber-300 ring-2 ring-amber-400 ring-offset-2' : 'from-amber-50 to-amber-100/50 border-amber-200/60 hover:from-amber-100 hover:to-amber-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <span class="w-3 h-3 rounded-full bg-amber-500 shadow-lg shadow-amber-500/40"></span>
                                <span class="text-3xl font-black text-amber-600"><?= $in_progress ?></span>
                            </div>
                            <p class="text-[11px] font-bold text-amber-700 uppercase tracking-wider">In Progress</p>
                            <p class="text-[10px] text-amber-500 mt-1">Partial logs (1-4)</p>
                        </a>

                        <!-- Green Complete (Filter: green) -->
                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'green'])) ?>" class="bg-gradient-to-br <?= $filter_status === 'green' ? 'from-emerald-100 to-emerald-200 border-emerald-300 ring-2 ring-emerald-400 ring-offset-2' : 'from-emerald-50 to-emerald-100/50 border-emerald-200/60 hover:from-emerald-100 hover:to-emerald-50' ?> border rounded-2xl p-4 text-center transition-all duration-200 cursor-pointer hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-center gap-2 mb-2">
                                <span class="w-3 h-3 rounded-full bg-emerald-500 shadow-lg shadow-emerald-500/40"></span>
                                <span class="text-3xl font-black text-emerald-600"><?= $complete ?></span>
                            </div>
                            <p class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider">Complete</p>
                            <p class="text-[10px] text-emerald-500 mt-1">All 5 logs done</p>
                        </a>
                    </div>
                    <div class="px-6 py-3 border-t border-slate-100 bg-gradient-to-r from-slate-50 to-white rounded-b-2xl">
                        <p class="text-[11px] text-slate-500 text-center font-medium">
                            <?php if ($filter_status): ?>
                                Filtering by: <span class="font-bold text-slate-700"><?= $filter_status === 'red' ? 'Behind Schedule' : ($filter_status === 'amber' ? 'In Progress' : 'Complete') ?></span> · <?= count($students) ?> student(s)
                                <a href="?<?= http_build_query(array_merge($_GET, ['status' => ''])) ?>" class="ml-2 text-indigo-600 hover:underline">✕ Clear filter</a>
                            <?php else: ?>
                                <?= $total_assigned ?> total student(s) · <span class="font-bold text-slate-700"><?= $behind_schedule + $in_progress ?></span> need attention
                            <?php endif; ?>
                        </p>
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
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-[11px]">
                                    <th class="px-5 py-3 text-left">Roll No</th>
                                    <th class="px-5 py-3 text-left">Student Name</th>
                                    <th class="px-5 py-3 text-left">Major</th>
                                    <th class="px-5 py-3 text-left">Company</th>
                                    <th class="px-5 py-3 text-left">Year</th>
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
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shrink-0 shadow-md shadow-indigo-500/20">
                                                <?= strtoupper(($s['full_name'] ?: $s['username'])[0]) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-800"><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></p>
                                                <p class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars($s['email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-slate-600 font-medium"><?= htmlspecialchars($s['major'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-slate-600 max-w-[150px] truncate font-medium" title="<?= htmlspecialchars($s['company_name'] ?? '') ?>"><?= htmlspecialchars($s['company_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4">
                                        <?php if ($s['academic_year']): ?>
                                            <span class="text-[11px] font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg font-mono"><?= htmlspecialchars($s['academic_year']) ?></span>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($ur > 0): ?>
                                            <a href="supervisor-review.php?student_id=<?= $s['uid'] ?>" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-200/60 hover:bg-emerald-100 hover:border-emerald-300 transition-all duration-200 cursor-pointer">
                                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                                📩 Pending Your Grade (Instructor Approved)
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200/60">
                                                <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                                                Awaiting Reports
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?= $progress_badges[$s['uid']] ?: '<span class="text-slate-300 text-xs">—</span>' ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <a href="supervisor-review.php?student_id=<?= $s['uid'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-[11px] font-bold rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-purple-500/20">
                                            👁️ View &amp; Grade
                                        </a>
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
                            <p class="text-[11px] text-slate-400 font-medium">
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
                        <p class="text-[11px] text-slate-400 font-medium">
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
    </div>
</div>

</body>
</html>
