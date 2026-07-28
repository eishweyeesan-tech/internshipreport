<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

function notif_redirect_url($type, $related_week, $announcement_id = null) {
    if ($announcement_id) return 'announcement-detail.php?id=' . (int)$announcement_id;
    switch ($type) {
        case 'instructor_approved':
        case 'instructor_rejected':
        case 'supervisor_approved':
            if ($related_week) return 'supervisor-dashboard.php?week=' . (int)$related_week;
            return 'supervisor-dashboard.php';
        default:
            return 'supervisor-dashboard.php';
    }
}

$ann_id = (int)($_GET['id'] ?? 0);
if ($ann_id <= 0) {
    header('Location: announcements.php');
    exit;
}

// Fetch the announcement
$ann_q = $pdo->prepare("
    SELECT a.*, u.username AS sender_name
    FROM announcements a
    LEFT JOIN users u ON u.id = a.created_by
    WHERE a.id = ? AND a.is_active = 1
");
$ann_q->execute([$ann_id]);
$announcement = $ann_q->fetch();

if (!$announcement) {
    header('Location: announcements.php');
    exit;
}

// ── Auto mark notification as read on page load ──────────────────
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE announcement_id = ? AND user_id = ? AND is_read = 0")->execute([$ann_id, $sup_id]);

// ── Handle AJAX mark-notification-read ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    $notif_id = (int)($_POST['notification_id'] ?? 0);
    if ($notif_id > 0) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notif_id, $sup_id]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $count_q->execute([$sup_id]);
        echo json_encode(['unread_count' => (int)$count_q->fetchColumn()]);
        exit;
    }
    header('Location: announcement-detail.php?id=' . $ann_id);
    exit;
}

// ── Handle AJAX mark-all-read ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: announcement-detail.php?id=' . $ann_id);
    exit;
}

// Fetch notifications (fresh count after auto-read)
$unread_notif_count = 0;
$recent_notifications = [];
try {
    $unread_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unread_q->execute([$sup_id]);
    $unread_notif_count = (int) $unread_q->fetchColumn();
    $notif_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $notif_q->execute([$sup_id]);
    $recent_notifications = $notif_q->fetchAll();
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($announcement['title']) ?> – Announcements – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] },
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
    function notif_redirect_url(type, related_week, announcement_id) {
        if (announcement_id) return 'announcement-detail.php?id=' + parseInt(announcement_id);
        return 'supervisor-dashboard.php';
    }
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
        if (seconds < 0) return 'Just now';
        if (seconds < 60) return 'Just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days === 1) return 'Yesterday';
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

    function toggleNotifOptions(btn) {
        var menu = btn.nextElementSibling;
        document.querySelectorAll('.notif-options-menu').forEach(function(m) { if (m !== menu) m.classList.add('hidden'); });
        menu.classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('[onclick*="toggleNotifOptions"]')) {
            document.querySelectorAll('.notif-options-menu').forEach(function(m) { m.classList.add('hidden'); });
        }
    });
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- SIDEBAR -->
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
            <div class="px-4 mb-1">
                <h3 class="text-xs font-bold text-slate-500 tracking-wider uppercase">Academic Year (2025-2026)</h3>
            </div>
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="view-student-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🎓</span> Student View
            </a>
            <a href="announcements.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📢</span> Announcements
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>
        </nav>

        <div class="px-4 mb-1">
            <h3 class="text-xs font-bold text-slate-500 tracking-wider uppercase">Past Academic Years</h3>
        </div>
        <a href="supervisor-dashboard.php?tab=trainee-archive" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium text-slate-600 hover:bg-slate-800 hover:text-slate-200">
            <span class="w-5 h-5 flex items-center justify-center shrink-0">⏪</span> Archived Records
        </a>

        <div class="p-3 border-t border-slate-100/80">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-subtitle leading-relaxed font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4">
                <a href="announcements.php" class="flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-slate-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back
                </a>
                <h1 class="text-base font-bold text-slate-800">Announcement Detail</h1>
            </div>
            <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                <!-- Notification Bell -->
                <div class="relative" id="notif-bell-wrapper">
                    <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-white/30 rounded-xl transition cursor-pointer">
                        <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if ($unread_notif_count > 0): ?>
                        <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                        <?php endif; ?>
                    </button>
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
                            <?php $notif_url = notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null); ?>
                            <div class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-announcement-id="<?= (int)($notif['announcement_id'] ?? 0) ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" data-fallback-href="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)">
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
                                    <p class="text-[11px] text-slate-400 mt-1.5" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>" data-notif-id="<?= (int)$notif['id'] ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                                    <?php if (!$notif['is_read']): ?>
                                    <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm"></span>
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
                <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 z-[1050] bg-white border border-slate-200 rounded-xl shadow-[0_4px_12px_rgba(0,0,0,0.15)] w-48 py-2">
                    <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                        <span>👤</span> My Profile
                    </a>
                    <a href="profile.php#security-section" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                        <span>🔑</span> Change Password
                    </a>
                    <div class="my-1 border-t border-slate-100"></div>
                    <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-500 hover:bg-red-50 transition">
                        <span>🚪</span> Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-3xl mx-auto space-y-6">

                <!-- Announcement Card -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-8 py-6">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="text-3xl">📢</span>
                            <span class="text-xs font-bold text-blue-200 uppercase tracking-wider bg-white/20 backdrop-blur-sm px-3 py-1 rounded-full">Announcement</span>
                        </div>
                        <h1 class="text-xl font-black text-white leading-tight"><?= htmlspecialchars($announcement['title']) ?></h1>
                    </div>

                    <!-- Meta -->
                    <div class="px-8 py-4 border-b border-slate-100 flex items-center gap-5 text-sm text-slate-500 font-medium">
                        <span class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-slate-400 to-slate-500 text-white flex items-center justify-center text-xs font-bold">
                                <?= strtoupper(substr($announcement['sender_name'] ?? 'A', 0, 1)) ?>
                            </div>
                            <?= htmlspecialchars($announcement['sender_name'] ?? 'Administrator') ?>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <?= (new DateTime($announcement['created_at']))->format('l, d M Y \a\t h:i A') ?>
                        </span>
                    </div>

                    <!-- Body -->
                    <div class="px-8 py-6">
                        <div class="prose prose-slate max-w-none text-sm leading-relaxed text-slate-700 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($announcement['body'])) ?></div>
                    </div>
                </div>

                <!-- Back Button -->
                <div class="flex justify-center">
                    <a href="announcements.php" class="inline-flex items-center gap-2 px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-sm rounded-xl transition-colors duration-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back to Announcements
                    </a>
                </div>

            </div>
        </main>
    </div>
</div>

<?php
$announcement_api_url = '../api/get_announcement.php';
$notifications_api_url = '../api/notifications.php';
include __DIR__ . '/../includes/announcement-modal-bundle.php';
?>

</body>
</html>
