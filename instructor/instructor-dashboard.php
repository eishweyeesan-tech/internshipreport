<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/internship_progress.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../includes/notification_actions.php';

$inst_id   = $_SESSION['user_id'];
$inst_name = $_SESSION['username'];
$db        = $mysqli ?? $conn;

handle_notification_ajax_actions($db, $inst_id);

$unread_notif_q = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->bind_param("i", $inst_id);
$unread_notif_q->execute();
$res = $unread_notif_q->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->bind_param("i", $inst_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch instructor email
$inst_email_q = $db->prepare("SELECT email FROM users WHERE id = ?");
$inst_email_q->bind_param("i", $inst_id);
$inst_email_q->execute();
$res = $inst_email_q->get_result();
$row = $res ? $res->fetch_row() : null;
$inst_email = $row[0] ?? '';

// ══════════════════════════════════════════════════════════════════════
// DYNAMIC ACADEMIC YEAR SELECTION
// ══════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/academic_year_helper.php';
ensure_academic_years_table($db);

$academic_years = get_academic_years_list($db);
$default_active_year = get_active_academic_year_label($db);

if (isset($_GET['year']) && $_GET['year'] !== '') {
    $selected_year = trim($_GET['year']);
} else {
    if (in_array($default_active_year, $academic_years, true)) {
        $selected_year = $default_active_year;
    } elseif (!empty($academic_years)) {
        $selected_year = $academic_years[0];
    } else {
        $selected_year = '';
    }
}

// ══════════════════════════════════════════════════════════════════════
// FETCH ASSIGNED STUDENTS (WITH OPTIONAL SEARCH AND YEAR FILTER)
// ══════════════════════════════════════════════════════════════════════
$search_term = trim($_GET['search'] ?? '');

$sql_stu = "
    SELECT u.id AS uid, u.username, u.email, ay.year_label AS academic_year,
           u.username AS full_name, sp.student_roll, sp.major,
           COALESCE(c.company_name, '') AS company_name,
           u.position AS job_role, sp.supervisor_id,
           sup_u.username AS supervisor_name,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
    LEFT JOIN companies c ON c.id = sp.company_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE u.role = 'student'
";
$stu_params = [];
$stu_types  = "";

if ($selected_year !== '' && $selected_year !== 'all') {
    $sql_stu .= " AND ay.year_label = ?";
    $stu_params[] = $selected_year;
    $stu_types .= "s";
}

if ($search_term !== '') {
    $sql_stu .= " AND (u.username LIKE ? OR sp.student_roll LIKE ? OR c.company_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search_term . '%';
    $stu_params[] = $like; $stu_params[] = $like; $stu_params[] = $like; $stu_params[] = $like;
    $stu_types .= "ssss";
}

$sql_stu .= " ORDER BY u.username ASC";

$stu_stmt = $db->prepare($sql_stu);
if (!empty($stu_types)) {
    $stu_stmt->bind_param($stu_types, ...$stu_params);
}
$stu_stmt->execute();
$res = $stu_stmt->get_result();
$all_students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stu_stmt->close();

// ══════════════════════════════════════════════════════════════════════
// COMPUTE PER-STUDENT STATUS FOR CURRENT WEEK
// ══════════════════════════════════════════════════════════════════════
$today = new DateTime();
$today_str = $today->format('Y-m-d');

$student_status = [];
foreach ($all_students as $stu) {
    $uid = $stu['uid'];
    $dw = 1;
    $not_started = false;

    if ($stu['internship_start_date']) {
        $start_date = $stu['internship_start_date'];
        $end_date   = $stu['internship_end_date'] ?: null;
        $dw = internship_current_week($start_date, $end_date, $today);

        if ($today < new DateTime($start_date)) {
            $not_started = true;
        }
    } else {
        $not_started = true;
    }

    // Compute week date range
    if ($stu['internship_start_date']) {
        $stu_start = new DateTime($stu['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        $sws = $today->modify('monday this week')->format('Y-m-d');
        $swe = $today->modify('sunday this week')->format('Y-m-d');
    }

    // Daily logs count for current week
    $log_q = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND log_date BETWEEN ? AND ?");
    $log_q->bind_param("iss", $uid, $sws, $swe);
    $log_q->execute();
    $res = $log_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $log_count = (int) ($row[0] ?? 0);

    // Weekly report status & review code
    $eval_q = $db->prepare("SELECT status, instructor_grade, instructor_review_code, submitted_at FROM weekly_reports WHERE student_id = ? AND week_number = ?");
    $eval_q->bind_param("ii", $uid, $dw);
    $eval_q->execute();
    $res = $eval_q->get_result();
    $eval = $res ? $res->fetch_assoc() : null;
    $eval_status = $eval ? $eval['status'] : 'pending';
    $magic_token = $eval ? $eval['instructor_review_code'] : null;

    // Total logs count
    $total_log_q = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ?");
    $total_log_q->bind_param("i", $uid);
    $total_log_q->execute();
    $res = $total_log_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $total_logs = (int) ($row[0] ?? 0);

    // Total graded weeks
    $graded_q = $db->prepare("SELECT COUNT(*) FROM weekly_reports WHERE student_id = ? AND status IN ('approved_by_instructor', 'graded')");
    $graded_q->bind_param("i", $uid);
    $graded_q->execute();
    $res = $graded_q->get_result();
    $row = $res ? $res->fetch_row() : null;
    $graded_weeks = (int) ($row[0] ?? 0);

    $student_status[$uid] = [
        'current_week'  => $dw,
        'not_started'   => $not_started,
        'log_count'     => $log_count,
        'eval_status'   => $eval_status,
        'has_link'      => !empty($magic_token),
        'magic_token'   => $magic_token,
        'total_logs'    => $total_logs,
        'graded_weeks'  => $graded_weeks,
    ];
}

// Count stats
$total_students = count($all_students);
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
foreach ($student_status as $st) {
    if ($st['eval_status'] === 'approved_by_instructor' || $st['eval_status'] === 'graded') {
        $approved_count++;
    } elseif ($st['eval_status'] === 'rejected') {
        $rejected_count++;
    } else {
        $pending_count++;
    }
}

// ══════════════════════════════════════════════════════════════════════
// HANDLE MAGIC LINK GENERATION FOR A STUDENT
// ══════════════════════════════════════════════════════════════════════
$generated_link = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_link'])) {
    $link_student_id = (int) ($_POST['student_id'] ?? 0);
    $link_week = (int) ($_POST['week_number'] ?? 0);

    if ($link_student_id > 0 && $link_week > 0) {
        $token = bin2hex(random_bytes(32));
        $ins_link = $db->prepare("INSERT INTO weekly_reports
            (student_id, week_number, what_done, how_done, why_done, instructor_review_code, status)
            VALUES (?, ?, '', '', '', ?, 'pending')
            ON DUPLICATE KEY UPDATE instructor_review_code = VALUES(instructor_review_code)");
        $ins_link->bind_param("iis", $link_student_id, $link_week, $token);
        $ins_link->execute();

        $generated_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
            . '/instructor/view-report.php?token=' . $token;

        header('Location: instructor-dashboard.php?generated=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard – InternReport</title>
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
    function toggleProfileDropdown(e) {
        e.stopPropagation();
        document.getElementById('profile-dropdown-menu').classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
    function copyLink(inputId) {
        var input = document.getElementById(inputId);
        if (!input || !input.value) return;
        navigator.clipboard.writeText(input.value).then(function () {
            var btn = input.nextElementSibling;
            if (btn) { btn.textContent = '✓ Copied!'; setTimeout(function() { btn.textContent = '📋 Copy'; }, 2000); }
        });
    }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-teal-50/20 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
    <div id="instructorSidebarBackdrop" onclick="toggleInstructorSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

    <!-- ─── SIDEBAR ─── -->
    <aside id="instructorSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out bg-[#005f73] border-r border-teal-700/40 flex flex-col shrink-0 text-white shadow-xl print:hidden">
        <div class="h-16 flex items-center justify-between px-6 border-b border-teal-700/40 bg-teal-900/30">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-teal-600 flex items-center justify-center shadow-md">
                    <span class="text-white text-sm">📋</span>
                </div>
                <div>
                    <span class="text-sm font-extrabold text-white tracking-tight">InternReport</span>
                    <span class="block text-caption font-bold text-teal-100 bg-teal-700/60 border border-teal-500/40 px-1.5 py-0.5 rounded mt-0.5">INSTRUCTOR</span>
                </div>
            </div>
            <button type="button" onclick="toggleInstructorSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">✕</button>
        </div>
        <nav class="flex-1 py-5 px-3 space-y-1">
            <a href="instructor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="../supervisor/supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👩‍🏫</span> Supervisor View
            </a>
        </nav>
        <div class="p-3 border-t border-teal-700/40">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-subtitle leading-relaxed font-semibold text-red-300 hover:text-white hover:bg-red-500/20 rounded-xl transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>

    <script>
    function toggleInstructorSidebar() {
        var sb = document.getElementById('instructorSidebar');
        var bd = document.getElementById('instructorSidebarBackdrop');
        if (!sb) return;
        if (sb.classList.contains('-translate-x-full')) {
            sb.classList.remove('-translate-x-full');
            if (bd) bd.classList.remove('hidden');
        } else {
            sb.classList.add('-translate-x-full');
            if (bd) bd.classList.add('hidden');
        }
    }
    </script>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-16 bg-white/90 backdrop-blur-xl border-b border-teal-100 flex items-center justify-between px-4 lg:px-8 shrink-0 shadow-sm print:hidden">
            <div class="flex items-center gap-3">
                <button type="button" onclick="toggleInstructorSidebar()" class="lg:hidden p-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle Navigation">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-base font-bold text-slate-800">Instructor Dashboard</h1>
                <?php if (!empty($academic_years)): ?>
                <form method="GET" class="flex items-center gap-1.5 border-l border-teal-100 pl-3">
                    <select name="year" id="ay_select" onchange="this.form.submit()" class="bg-white border border-teal-200 text-teal-900 focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm font-semibold rounded-lg px-3 py-1 shadow-sm focus:outline-none transition cursor-pointer">
                        <?= render_academic_year_options($db, $selected_year, true, 'All Academic Years') ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-teal-50 border border-teal-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-teal-700"><?= $total_students ?> Student<?= $total_students !== 1 ? 's' : '' ?></span>
                </div>
                <!-- Notification Bell -->
                <div class="relative" id="notif-bell-wrapper">
                    <button onclick="toggleNotifDropdown()" class="relative p-2 text-slate-400 hover:text-slate-600 hover:bg-teal-50 rounded-full transition cursor-pointer">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if (($unread_notif_count ?? 0) > 0): ?>
                        <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                        <?php endif; ?>
                    </button>
                    <!-- Notification Dropdown -->
                    <div id="notif-dropdown" class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                        <div class="p-3 border-b border-slate-100 flex items-center justify-between bg-teal-50/60">
                            <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">Notifications</h4>
                            <?php if (($unread_notif_count ?? 0) > 0): ?>
                            <button onclick="markAllNotifsRead()" id="notif-mark-all-btn" class="text-label font-bold text-teal-700 hover:text-teal-900 hover:bg-teal-100/60 px-2 py-1 rounded transition cursor-pointer">Mark all read</button>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            <?php if (!empty($recent_notifications)): ?>
                            <?php foreach ($recent_notifications as $notif): ?>
                            <?php
                                $notif_url = !empty($notif['link']) ? $notif['link'] : notif_action_url($notif, 'instructor');
                                $meta = notif_type_meta($notif['type'] ?? 'info');
                            ?>
                            <a href="<?= htmlspecialchars($notif_url) ?>" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)" class="flex items-start gap-2.5 px-3 py-3 <?= !$notif['is_read'] ? 'bg-teal-50/40' : '' ?> hover:bg-teal-50 transition-all duration-150 border-b border-slate-100 last:border-0 group cursor-pointer block no-underline">
                                <?php if (!$notif['is_read']): ?>
                                <span class="w-2 h-2 bg-teal-500 rounded-full flex-shrink-0 mt-2"></span>
                                <?php else: ?>
                                <span class="w-2 h-2 flex-shrink-0 mt-2"></span>
                                <?php endif; ?>
                                <div class="w-8 h-8 rounded-full <?= $meta['classes'] ?> flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-sm">
                                    <?= $meta['icon'] ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-caption font-bold <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-500' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                                    <p class="text-label text-slate-400 mt-0.5 leading-snug" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($notif['message']) ?></p>
                                    <p class="text-caption text-slate-300 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                                </div>
                            </a>
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
                            <?= strtoupper(substr($_SESSION['username'] ?? 'I', 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-left hidden sm:block">
                            <p class="font-semibold text-sm text-slate-800 leading-tight"><?= htmlspecialchars($inst_name) ?></p>
                            <p class="text-xs font-medium text-teal-700 capitalize">Company Instructor</p>
                        </div>
                        <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <!-- Profile Dropdown Menu -->
                    <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-lg border border-teal-100 py-1.5 z-50 divide-y divide-slate-100">
                        <a href="instructor-dashboard.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                            <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> My Profile
                        </a>
                        <a href="instructor-dashboard.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                            <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> Change Password
                        </a>
                        <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                            <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <?php if (isset($_GET['generated'])): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold px-5 py-4 rounded-2xl flex items-center gap-3 shadow-sm">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-sm shadow-lg shadow-emerald-500/30">✅</div>
                    <span>Magic link generated successfully. Copy and share it with the instructor for review.</span>
                </div>
                <?php endif; ?>

                <!-- ═══ STATS CARDS ═══ -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30">👨‍🏫</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">My Students</p>
                                <p class="text-2xl font-black text-slate-800"><?= $total_students ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30">⏳</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Pending Review</p>
                                <p class="text-2xl font-black text-slate-800"><?= $pending_count ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-xl shadow-lg shadow-emerald-500/30">✅</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Approved</p>
                                <p class="text-2xl font-black text-slate-800"><?= $approved_count ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center text-xl shadow-lg shadow-red-500/30">❌</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Rejected</p>
                                <p class="text-2xl font-black text-slate-800"><?= $rejected_count ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ STUDENT TABLE ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between flex-wrap gap-3">
                        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-sm">📋</span> Assigned Students
                        </h2>
                        <!-- Search Form -->
                        <form method="GET" class="flex items-center gap-2">
                            <?php if (!empty($selected_year)): ?><input type="hidden" name="year" value="<?= htmlspecialchars($selected_year) ?>"><?php endif; ?>
                            <div class="relative flex-1 sm:w-64 max-w-xs">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search_term ?? '') ?>" placeholder="Search student, roll, company..." class="w-full bg-white border border-teal-200 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition-all duration-200">
                                <?php if (!empty($search_term)): ?>
                                <a href="?<?= http_build_query(array_diff_key($_GET, ['search' => ''])) ?>" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 text-xs font-bold transition" title="Clear search">✕</a>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer">Search</button>
                        </form>
                    </div>
                    <?php if (!empty($all_students)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                    <th class="px-5 py-3 text-left">Student</th>
                                    <th class="px-5 py-3 text-left">Roll No</th>
                                    <th class="px-5 py-3 text-left">Job Role</th>
                                    <th class="px-5 py-3 text-left">Company</th>
                                    <th class="px-5 py-3 text-left">Supervisor</th>
                                    <th class="px-5 py-3 text-left">Week</th>
                                    <th class="px-5 py-3 text-left">Logs</th>
                                    <th class="px-5 py-3 text-left">Status</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($all_students as $stu):
                                    $uid = $stu['uid'];
                                    $st = $student_status[$uid];
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center text-xs font-bold shrink-0">
                                                <?= strtoupper(($stu['full_name'] ?: $stu['username'])[0]) ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-700"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                                <p class="text-sm text-slate-400"><?= htmlspecialchars($stu['email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-xs font-mono text-slate-600"><?= htmlspecialchars($stu['student_roll'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars($stu['job_role'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars($stu['company_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars($stu['supervisor_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4">
                                        <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono">W<?= $st['current_week'] ?></span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-xs font-bold <?= $st['log_count'] >= 5 ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $st['log_count'] ?>/5</span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($st['eval_status'] === 'approved_by_instructor' || $st['eval_status'] === 'approved_by_supervisor'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> ✅ Approved
                                        </span>
                                        <?php elseif ($st['eval_status'] === 'rejected'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-1 rounded-lg">
                                            <span class="w-2 h-2 rounded-full bg-red-500"></span> ❌ Rejected
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg">
                                            <span class="w-2 h-2 rounded-full bg-amber-500"></span> ⏳ Pending
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <?php if ($st['magic_token']): ?>
                                            <a href="view-report.php?token=<?= htmlspecialchars($st['magic_token']) ?>" class="px-2.5 py-1 text-sm font-bold text-amber-600 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">📋 Review</a>
                                            <?php else: ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="student_id" value="<?= $uid ?>">
                                                <input type="hidden" name="week_number" value="<?= $st['current_week'] ?>">
                                                <button type="submit" name="generate_link" class="px-2.5 py-1 text-sm font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition cursor-pointer">🔗 Get Link</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm text-slate-500 font-medium">No students assigned to you yet.</p>
                        <p class="text-xs text-slate-400 mt-1">Students will appear here once their profile has your email as the instructor.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ═══ WORKFLOW INFO ═══ -->
                <div class="bg-gradient-to-r from-amber-600 via-orange-500 to-amber-600 rounded-2xl p-6 text-white shadow-xl shadow-amber-500/20">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-xl">💡</div>
                        <div>
                            <p class="text-sm font-bold">How the Review Process Works</p>
                            <p class="text-xs text-amber-100 mt-1 leading-relaxed">
                                1. Students submit daily logs and weekly reflections → 2. Students sign & generate a magic link →
                                <strong>3. You review and approve/reject (you are here!)</strong> → 4. University supervisor assigns final grade.
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="../assets/js/main.js"></script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
