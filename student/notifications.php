<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];

$db = $mysqli ?? $conn;

// Fetch Student Profile (for top bar)
$profile_stmt = $db->prepare("SELECT sp.student_roll, u.username, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile_row = $res ? $res->fetch_assoc() : null;

$student_name = (($profile_row['username'] ?? '') ?: $username);
$student_roll = $profile_row['student_roll'] ?? '';
$profile_pic  = $profile_row['profile_pic'] ?? '';

// Mark single notification read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    $notif_id = (int) ($_POST['notification_id'] ?? 0);
    if ($notif_id > 0) {
        $upd_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $upd_stmt->bind_param("ii", $notif_id, $user_id);
        $upd_stmt->execute();
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        $unr_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $unr_stmt->bind_param("i", $user_id);
        $unr_stmt->execute();
        $res = $unr_stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        echo json_encode(['unread_count' => (int) ($row[0] ?? 0)]);
        exit;
    }
    header('Location: notifications.php');
    exit;
}

// Mark all notifications read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $upd_all = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $upd_all->bind_param("i", $user_id);
    $upd_all->execute();
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: notifications.php');
    exit;
}

// Unread count + recent
$unr_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unr_stmt->bind_param("i", $user_id);
$unr_stmt->execute();
$res = $unr_stmt->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

$rec_stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$rec_stmt->bind_param("i", $user_id);
$rec_stmt->execute();
$res = $rec_stmt->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// All notifications for this student
$all_stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$all_stmt->bind_param("i", $user_id);
$all_stmt->execute();
$res = $all_stmt->get_result();
$notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

if (!function_exists('student_notif_url')) {
    function student_notif_url($type, $related_week, $announcement_id = null) {
        if ($announcement_id) {
            return 'student-dashboard.php';
        }
        $base = 'student-dashboard.php';
        if (in_array($type, ['instructor_approved', 'instructor_rejected', 'supervisor_approved'], true) && $related_week) {
            return $base . '?week=' . (int)$related_week;
        }
        return $base;
    }
}

if (!function_exists('student_notif_meta')) {
    function student_notif_meta($type) {
        switch ($type) {
            case 'instructor_approved':
                return ['icon' => 'M5 13l4 4L19 7', 'classes' => 'bg-emerald-100 text-emerald-600'];
            case 'instructor_rejected':
                return ['icon' => 'M6 18L18 6M6 6l12 12', 'classes' => 'bg-red-100 text-red-600'];
            case 'new_report_submitted':
                return ['icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'classes' => 'bg-blue-100 text-blue-600'];
            case 'report_needs_review':
                return ['icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z', 'classes' => 'bg-amber-100 text-amber-600'];
            case 'student_behind_schedule':
                return ['icon' => 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z', 'classes' => 'bg-red-100 text-red-600'];
            case 'internship_completed':
                return ['icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'classes' => 'bg-emerald-100 text-emerald-600'];
            case 'system_notice':
                return ['icon' => 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z', 'classes' => 'bg-indigo-100 text-indigo-600'];
            default:
                return ['icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'classes' => 'bg-blue-100 text-blue-600'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
    <style>
        html { scrollbar-gutter: stable; overflow-y: scroll; }
        .glass-sidebar {
            background: #005f73;
            border-right: 1px solid rgba(15, 118, 110, 0.4);
        }
        .glass-sidebar nav { scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.15) transparent; }
        .glass-sidebar nav::-webkit-scrollbar { width: 4px; }
        .glass-sidebar nav::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 4px; }
        .nav-link { color: #ccfbf1; font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(15, 118, 110, 0.6); }
        .active-nav { background: #0a9396; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3); }
    </style>
</head>
<body class="bg-slate-100 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
    <div id="studentSidebarBackdrop" onclick="toggleStudentSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

    <!-- ─── SIDEBAR ─── -->
    <aside id="studentSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out glass-sidebar flex flex-col shrink-0 text-white shadow-xl print:hidden">
        <div class="h-16 flex items-center justify-between px-5 border-b border-white/10 shrink-0">
            <span class="font-black text-white tracking-tight text-lg">InternReport</span>
            <button type="button" onclick="toggleStudentSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">✕</button>
        </div>
        <nav class="flex-1 min-h-0 py-4 space-y-1 px-3 overflow-y-auto">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg> Dashboard
            </a>
            <a href="notifications.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> Notifications
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> Log History
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"/></svg> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'Notifications'; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 md:p-8">
            <div class="max-w-7xl mx-auto w-full">

            <!-- ════ PAGE HEADER ════ -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-xl font-bold text-slate-800">Notifications</h1>
                    <p class="text-xs text-gray-400 mt-1">Updates about your weekly reports and internship progress.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-full shadow-sm">
                        <span class="w-2 h-2 rounded-full <?= $unread_notif_count > 0 ? 'bg-blue-500 animate-pulse' : 'bg-slate-300' ?>"></span>
                        <span class="text-xs font-medium text-slate-600"><span id="page-unread-count"><?= $unread_notif_count ?></span> unread</span>
                    </span>
                    <?php if ($unread_notif_count > 0): ?>
                    <button id="mark-all-btn" onclick="markAllNotificationsRead()" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded-xl shadow-md shadow-indigo-500/20 transition-all duration-200 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Mark all as read
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($notifications)): ?>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <?php foreach ($notifications as $notif): ?>
                <?php
                    $meta      = student_notif_meta($notif['type'] ?? 'info');
                    $notif_url = !empty($notif['link']) ? $notif['link'] : student_notif_url($notif['type'] ?? 'info', $notif['related_week'] ?? null, $notif['announcement_id'] ?? null);
                ?>
                <div onclick="onNotificationItemClick(event, this)" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" data-fallback-href="<?= htmlspecialchars($notif_url) ?>"
                     class="flex items-start gap-4 px-5 py-4 cursor-pointer transition-all duration-150 border-b border-slate-100 last:border-0 <?= !$notif['is_read'] ? 'bg-blue-50/60 hover:bg-blue-50' : 'hover:bg-slate-50' ?>">
                    <div class="w-9 h-9 rounded-xl <?= $meta['classes'] ?> flex items-center justify-center text-xs shrink-0 ring-2 ring-white shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $meta['icon'] ?>"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-sm <?= !$notif['is_read'] ? 'font-semibold text-slate-800' : 'font-medium text-slate-700' ?> leading-normal"><?= htmlspecialchars($notif['title']) ?></p>
                            <?php if (!empty($notif['related_week'])): ?>
                            <span class="text-xs font-medium text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">Week <?= (int)$notif['related_week'] ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-700 mt-0.5 leading-normal line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                        <p class="text-xs text-gray-400 mt-1.5 flex items-center gap-2">
                            <span data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></span>
                            <span class="font-medium <?= !$notif['is_read'] ? 'text-blue-500' : 'text-blue-600' ?>">Open →</span>
                        </p>
                    </div>
                    <?php if (!$notif['is_read']): ?>
                    <span class="unread-dot w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shrink-0 mt-1.5"></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200/60 p-14 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </div>
                <p class="text-sm font-bold text-slate-600">No notifications</p>
                <p class="text-xs text-slate-400 mt-1">When something happens with your reports, it will show up here.</p>
            </div>

            <?php endif; ?>

        </div>
        </main>
    </div>
</div>

<script src="../assets/js/notifications.js"></script>
</body>
</html>
