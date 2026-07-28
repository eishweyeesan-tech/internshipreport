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

// Mark notification as read (when clicking from notification dropdown)
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
    header('Location: announcements.php');
    exit;
}

// Fetch notifications
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

// Fetch all announcements (newest first), with read/unread status per supervisor
$announcements = [];
try {
    $ann_q = $pdo->prepare("
        SELECT a.*, u.username AS sender_name,
               CASE WHEN n.id IS NOT NULL THEN n.is_read ELSE 0 END AS is_read
        FROM announcements a
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN notifications n ON n.announcement_id = a.id AND n.user_id = ?
        WHERE a.is_active = 1
        ORDER BY a.created_at DESC
    ");
    $ann_q->execute([$sup_id]);
    $announcements = $ann_q->fetchAll();
} catch (PDOException $e) {}

// Assigned count for top bar badge
$ay_filter = get_ay_filter($pdo, 'u');
$total_assigned_q = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql']);
$total_assigned_q->execute(array_merge([$sup_id], $ay_filter['params']));
$total_assigned = (int) $total_assigned_q->fetchColumn();
$selected_year_label = $_SESSION['selected_academic_year_label'] ?? '';

// Count unread announcements
$unread_ann_count = 0;
foreach ($announcements as $a) { if (!$a['is_read']) $unread_ann_count++; }

// ── Handle AJAX mark-as-read for announcement cards ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_announcement_read'])) {
    header('Content-Type: application/json');
    $ann_id_to_mark = (int)($_POST['announcement_id'] ?? 0);
    if ($ann_id_to_mark > 0) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE announcement_id = ? AND user_id = ?")->execute([$ann_id_to_mark, $sup_id]);
    }
    $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $count_q->execute([$sup_id]);
    echo json_encode(['unread_count' => (int)$count_q->fetchColumn()]);
    exit;
}

// ── Handle ?id=N → fetch specific announcement for modal ─────────
$modal_announcement = null;
$focus_ann_id = (int)($_GET['id'] ?? 0);
if ($focus_ann_id > 0) {
    foreach ($announcements as $a) {
        if ((int)$a['id'] === $focus_ann_id) {
            $modal_announcement = $a;
            break;
        }
    }
    // If not found in already-fetched list, try direct query
    if (!$modal_announcement) {
        try {
            $ma_q = $pdo->prepare("
                SELECT a.*, u.username AS sender_name,
                       CASE WHEN n.id IS NOT NULL THEN n.is_read ELSE 0 END AS is_read
                FROM announcements a
                LEFT JOIN users u ON u.id = a.created_by
                LEFT JOIN notifications n ON n.announcement_id = a.id AND n.user_id = ?
                WHERE a.id = ? AND a.is_active = 1
            ");
            $ma_q->execute([$sup_id, $focus_ann_id]);
            $modal_announcement = $ma_q->fetch();
        } catch (PDOException $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements – InternReport</title>
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

    // ── Card click → AJAX mark-as-read (non-blocking, lets link navigate) ──
    function handleCardClick(e) {
        var card = e.currentTarget;
        var annId = card.getAttribute('data-ann-id');
        var isUnread = card.classList.contains('ann-unread');
        if (isUnread && annId) {
            var fd = new FormData();
            fd.append('announcement_id', annId);
            fd.append('mark_announcement_read', '1');
            fetch('announcements.php', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r) { return r.json(); })
              .then(function(data) {
                updateNotifBadge(data.unread_count);
                card.classList.remove('ann-unread', 'border-blue-200', 'bg-[#f8fbff]');
                card.classList.add('border-slate-200/60');
                var badge = card.querySelector('.ann-new-badge');
                if (badge) badge.remove();
                var accent = card.querySelector('.ann-accent-bar');
                if (accent) accent.remove();
                var unreadBar = document.getElementById('ann-unread-bar');
                if (unreadBar) {
                    var remaining = parseInt(unreadBar.getAttribute('data-count')) - 1;
                    if (remaining <= 0) { unreadBar.remove(); }
                    else { unreadBar.setAttribute('data-count', remaining); unreadBar.querySelector('span').textContent = remaining + ' unread announcement' + (remaining !== 1 ? 's' : ''); }
                }
              })
              .catch(function() {});
        }
        // Allow natural link navigation to announcement-detail.php
    }
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
                <h1 class="text-base font-bold text-slate-800">Announcements</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if (!empty($selected_year_label)): ?>
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year_label) ?></span>
                    <?php endif; ?>
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
            <div class="max-w-5xl mx-auto space-y-5">

                <?php if (empty($announcements)): ?>
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-16 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                        <span class="text-3xl">📭</span>
                    </div>
                    <h3 class="text-lg font-bold text-slate-700 mb-1">No Announcements Yet</h3>
                    <p class="text-sm text-slate-400">When administrators post announcements, they'll appear here.</p>
                </div>
                <?php else: ?>

                <?php if ($unread_ann_count > 0): ?>
                <div id="ann-unread-bar" data-count="<?= $unread_ann_count ?>" class="flex items-center gap-2 px-4 py-2 bg-blue-50/80 border border-blue-200/60 rounded-xl">
                    <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-blue-700"><?= $unread_ann_count ?> unread announcement<?= $unread_ann_count !== 1 ? 's' : '' ?></span>
                </div>
                <?php endif; ?>

                <?php foreach ($announcements as $ann): ?>
                <?php $is_unread = !$ann['is_read']; ?>
                <a href="announcement-detail.php?id=<?= (int)$ann['id'] ?>" data-ann-id="<?= (int)$ann['id'] ?>" onclick="handleCardClick(event)" class="block bg-white rounded-2xl border shadow-sm p-6 transition-all duration-300 group relative overflow-hidden <?= $is_unread ? 'ann-unread border-blue-200 bg-[#f8fbff] hover:shadow-lg hover:shadow-blue-100/60 hover:-translate-y-0.5 hover:border-blue-300' : 'border-slate-200/60 hover:shadow-md hover:border-blue-200 hover:-translate-y-0.5' ?>">
                    <?php if ($is_unread): ?>
                    <div class="absolute top-0 left-0 w-1 h-full bg-gradient-to-b from-blue-500 to-indigo-500 rounded-l-2xl ann-accent-bar"></div>
                    <?php endif; ?>
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center text-xl shrink-0 shadow-lg shadow-blue-500/20 group-hover:scale-105 transition-transform <?= $is_unread ? '' : 'opacity-70' ?>">
                            📢
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-[0.95rem] font-extrabold leading-snug transition-colors <?= $is_unread ? 'text-slate-900 group-hover:text-blue-700' : 'text-slate-700 group-hover:text-blue-600' ?>"><?= htmlspecialchars($ann['title']) ?></h3>
                                <?php if ($is_unread): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-500 text-white text-[10px] font-bold rounded-full uppercase tracking-wider shrink-0 ann-new-badge">New</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-3 mt-1.5 text-xs text-slate-400 font-medium">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <?= htmlspecialchars($ann['sender_name'] ?? 'Admin') ?>
                                </span>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    <span data-notif-time="<?= htmlspecialchars($ann['created_at']) ?>"><?= (new DateTime($ann['created_at']))->format('d M Y, h:i A') ?></span>
                                </span>
                            </div>
                            <p class="text-sm text-slate-500 mt-2 leading-relaxed line-clamp-2 <?= $is_unread ? 'text-slate-600' : '' ?>"><?= htmlspecialchars(mb_strimwidth($ann['body'], 0, 180, '...')) ?></p>
                        </div>
                        <svg class="w-5 h-5 text-slate-300 group-hover:text-blue-500 group-hover:translate-x-0.5 transition-all shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>
</body>
</html>
