<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';
require_once __DIR__ . '/../config/internship_progress.php';

function getWeekRange(string $internship_start_date, int $week_number): ?array
{
    if ($week_number < 1) {
        return null;
    }

    $start = new DateTime($internship_start_date);

    if ($week_number === 1) {
        $dayOfWeek = (int) $start->format('N');
        $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
        $end = (clone $start)->modify("+{$daysToSat} days");
        return [
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
        ];
    }

    $dayOfWeek = (int) $start->format('N');
    $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
    $endOfWeek1 = (clone $start)->modify("+{$daysToSat} days");

    $weekStart = (clone $endOfWeek1)->modify('+1 day');
    if ($week_number > 2) {
        $weekStart->modify('+' . (($week_number - 2) * 7) . ' days');
    }
    $weekEnd = (clone $weekStart)->modify('+6 days');

    return [
        'start' => $weekStart->format('Y-m-d'),
        'end'   => $weekEnd->format('Y-m-d'),
    ];
}

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

// ── Notification redirect URL helper ────────────────────────────
require_once __DIR__ . '/../config/notify.php';

// ── Notification POST handlers ──────────────────────────────────
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
    header('Location: view-student-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: view-student-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notification'])) {
    $notif_id = (int) ($_POST['notification_id'] ?? 0);
    $deleted  = false;
    if ($notif_id > 0) {
        $del = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $del->execute([$notif_id, $sup_id]);
        $deleted = $del->rowCount() > 0;
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $count_q->execute([$sup_id]);
        echo json_encode(['success' => $deleted, 'unread_count' => (int) $count_q->fetchColumn()]);
        exit;
    }
    header('Location: view-student-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ── Fetch notifications ─────────────────────────────────────────
$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

$student_id = (int) ($_GET['id'] ?? 0);

// ── Academic year filter ───────────────────────────────────────
$ay_filter = get_ay_filter($pdo, 'u');

// ── No student selected: show student picker ──────────────────────
if ($student_id <= 0) {
    $sql = "
        SELECT u.id AS uid, u.username, u.email,
               sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
               COALESCE(ls.log_count, 0) AS log_count,
               COALESCE(ls.present_count, 0) AS present_count,
               COALESCE(ls.total_count, 0) AS total_count,
               ls.latest_log
        FROM users u
        JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN (
            SELECT internship_id,
                   COUNT(*) AS log_count,
                   SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
                   COUNT(*) AS total_count,
                   MAX(log_date) AS latest_log
            FROM daily_logs
            GROUP BY internship_id
        ) ls ON ls.internship_id = u.id
        WHERE u.role = 'student' AND sp.supervisor_id = ?" . $ay_filter['sql'] . "
        ORDER BY sp.full_name ASC
    ";
    $all_stu_q = $pdo->prepare($sql);
    $all_stu_q->execute(array_merge([$sup_id], $ay_filter['params']));
    $all_students = $all_stu_q->fetchAll();

    $total_assigned = count($all_students);
    $selected_year_label = $_SESSION['selected_academic_year_label'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Student – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
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

    function openAnnouncementModal(annId) {
        var modal = document.getElementById('ann-detail-modal');
        var backdrop = document.getElementById('ann-detail-backdrop');
        var body = document.getElementById('ann-detail-body');
        var title = document.getElementById('ann-detail-title');
        var sender = document.getElementById('ann-detail-sender');
        var date = document.getElementById('ann-detail-date');
        if (!modal) return;
        body.innerHTML = '<div class="flex items-center justify-center py-12"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div></div>';
        title.textContent = 'Loading...';
        sender.textContent = '';
        date.textContent = '';
        modal.classList.remove('hidden');
        backdrop.classList.remove('hidden');
        requestAnimationFrame(function() { modal.style.opacity = '1'; modal.style.transform = 'scale(1)'; backdrop.style.opacity = '1'; });
        document.body.style.overflow = 'hidden';
        fetch('get_announcement.php?id=' + annId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { body.innerHTML = '<p class="text-sm text-red-400 text-center py-8">' + data.error + '</p>'; return; }
            var a = data.announcement;
            title.textContent = a.title;
            sender.textContent = a.sender_name || 'Admin';
            date.textContent = new Date(a.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            body.innerHTML = '<div class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">' + a.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</div>';
            updateNotifBadge(data.unread_count);
        })
        .catch(function() { body.innerHTML = '<p class="text-sm text-red-400 text-center py-8">Failed to load announcement.</p>'; });
    }
    function closeAnnouncementModal() {
        var modal = document.getElementById('ann-detail-modal');
        var backdrop = document.getElementById('ann-detail-backdrop');
        if (modal && backdrop) {
            modal.style.opacity = '0'; modal.style.transform = 'scale(0.95)'; backdrop.style.opacity = '0';
            setTimeout(function() { modal.classList.add('hidden'); backdrop.classList.add('hidden'); }, 200);
            document.body.style.overflow = '';
        }
    }
    document.addEventListener('click', function(e) { if (e.target.id === 'ann-detail-backdrop') closeAnnouncementModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAnnouncementModal(); });

    function markNotifRead(el) {
        var notifId = el.getAttribute('data-notif-id');
        var redirectUrl = el.getAttribute('data-redirect-url') || 'supervisor-dashboard.php';
        var fd = new FormData();
        fd.append('notification_id', notifId);
        fd.append('mark_notification_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) { updateNotifBadge(data.unread_count); })
          .catch(function() {});
        var annMatch = redirectUrl.match(/announcement-detail\.php\?id=(\d+)/);
        if (annMatch) {
            openAnnouncementModal(annMatch[1]);
            var dd = document.getElementById('notif-dropdown');
            if (dd) { dd.classList.remove('show'); dd.style.opacity = '0'; dd.style.visibility = 'hidden'; dd.style.transform = 'translateY(-8px) scale(0.95)'; }
            return;
        }
        window.location.href = redirectUrl;
    }
    function markAllNotifsRead() {
        var fd = new FormData();
        fd.append('mark_all_notifications_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) { updateNotifBadge(data.unread_count); })
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
                    span.className = 'absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse';
                    span.textContent = count > 9 ? '9+' : count;
                    bell.appendChild(span);
                }
            }
        } else if (existing) {
            existing.remove();
        }
    }
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
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
    <!-- MAIN -->
    <div class="flex-1 flex flex-col min-h-0">
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4">
                <a href="supervisor-dashboard.php" class="flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-purple-600 transition-colors duration-200 cursor-pointer">
                    <span class="p-2 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded-full border border-slate-100 shadow-sm transition-all duration-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </span>
                </a>
                <div class="w-px h-6 bg-slate-200"></div>
                <h1 class="text-base font-bold text-slate-800">Select a Student to View</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if (!empty($selected_year_label)): ?>
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year_label) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 border border-purple-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-purple-700"><?= count($all_students) ?> Student<?= count($all_students) !== 1 ? 's' : '' ?></span>
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
                                <?php $notif_url = notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null); ?>
                                <div class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="markNotifRead(this)">
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
            </div>
        </header>
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto">
                <div class="w-full bg-white rounded-3xl border border-slate-100 p-8 shadow-sm">
                    <!-- Header -->
                    <div class="pb-6 border-b border-slate-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Your Students</h2>
                                <p class="text-sm text-slate-400 mt-1">Click any row to open their full dashboard.</p>
                            </div>
                            <span class="text-sm font-medium text-slate-400"><?= count($all_students) ?> total</span>
                        </div>
                    </div>

                    <?php if (!empty($all_students)): ?>
                    <!-- Feed List -->
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($all_students as $stu):
                            $att_rate = $stu['total_count'] > 0 ? round(($stu['present_count'] / $stu['total_count']) * 100) : 0;
                            $initials = strtoupper(mb_substr($stu['full_name'] ?: $stu['username'], 0, 2));
                        ?>
                        <a href="view-student-dashboard.php?id=<?= $stu['uid'] ?>" class="flex flex-col md:flex-row md:items-center justify-between py-4 px-2 hover:bg-slate-50/70 rounded-xl transition-all duration-200 cursor-pointer group">
                            <!-- Left: Avatar + Info -->
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-purple-600 text-white font-semibold text-base shadow-sm shrink-0 group-hover:scale-105 transition-transform duration-200">
                                    <?= $initials ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-900 text-base truncate group-hover:text-purple-600 transition-colors"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                    <p class="text-sm text-slate-400 truncate"><?= htmlspecialchars($stu['email']) ?></p>
                                </div>
                            </div>
                            <!-- Right: Metrics -->
                            <div class="flex items-center gap-6 mt-3 md:mt-0 shrink-0">
                                <div class="flex items-center gap-1.5 text-sm text-slate-600 font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>
                                    <?= $stu['log_count'] ?> Log<?= $stu['log_count'] !== 1 ? 's' : '' ?>
                                </div>
                                <?php if ($stu['total_count'] > 0): ?>
                                <div class="flex items-center gap-1.5 text-sm font-medium <?= $att_rate >= 80 ? 'text-emerald-600' : ($att_rate >= 50 ? 'text-amber-600' : 'text-red-500') ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $att_rate >= 80 ? 'bg-emerald-400' : ($att_rate >= 50 ? 'bg-amber-400' : 'bg-red-400') ?>"></span>
                                    <?= $att_rate ?>%
                                </div>
                                <?php endif; ?>
                                <?php if ($stu['latest_log']): ?>
                                <span class="text-xs text-slate-400 hidden sm:inline"><?= (new DateTime($stu['latest_log']))->format('d M') ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="py-20 text-center">
                        <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center text-2xl mx-auto mb-4">📭</div>
                        <p class="text-base font-medium text-slate-500">No students currently assigned to you for this academic year.</p>
                        <p class="text-sm text-slate-400 mt-1">Please contact the Admin.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
<!-- Announcement Detail Modal -->
<div id="ann-detail-backdrop" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-[2000] transition-opacity duration-200" style="opacity:0"></div>
<div id="ann-detail-modal" class="hidden fixed inset-0 z-[2001] flex items-center justify-center p-4 transition-all duration-200" style="opacity:0;transform:scale(0.95)">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
        <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-8 py-6 shrink-0">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">📢</span>
                    <span class="text-xs font-bold text-blue-200 uppercase tracking-wider bg-white/20 backdrop-blur-sm px-3 py-1 rounded-full">Announcement</span>
                </div>
                <button onclick="closeAnnouncementModal()" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition shrink-0 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <h1 id="ann-detail-title" class="text-xl font-black text-white leading-tight mt-4">Loading...</h1>
            <div class="flex items-center gap-4 mt-3 text-sm text-blue-200 font-medium">
                <span class="flex items-center gap-1.5" id="ann-detail-sender"></span>
                <span class="flex items-center gap-1.5" id="ann-detail-date"></span>
            </div>
        </div>
        <div id="ann-detail-body" class="flex-1 overflow-y-auto px-8 py-6">
            <div class="flex items-center justify-center py-12"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div></div>
        </div>
        <div class="px-8 py-4 border-t border-slate-100 flex items-center justify-end shrink-0 bg-slate-50/80">
            <button onclick="closeAnnouncementModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">Close</button>
        </div>
    </div>
</div>
</body>
</html>
<?php exit; }
// ── Student selected: continue with normal page ───────────────────

$check = $pdo->prepare("
    SELECT 1 FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.id = ? AND sp.supervisor_id = ? AND u.role = 'student'
");
$check->execute([$student_id, $sup_id]);
if (!$check->fetch()) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$profile_r = $pdo->prepare("
    SELECT sp.*, u.username, u.email, u.profile_pic,
           sup_u.username AS supervisor_name
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE sp.user_id = ?
");
$profile_r->execute([$student_id]);
$profile = $profile_r->fetch();

if (!$profile) {
    header('Location: supervisor-dashboard.php');
    exit;
}

$intern_start  = $profile['internship_start_date'] ?? null;
$intern_end    = $profile['internship_end_date'] ?? null;
$student_name  = $profile['full_name'] ?: ($profile['username'] ?? 'Student');
$student_roll  = $profile['student_roll'] ?? '';
$company_name  = $profile['company_name'] ?? '';
$major         = $profile['major'] ?? '';
$job_role      = $profile['job_role'] ?? '';
$phone         = $profile['phone'] ?? '';
$instructor_name = $profile['instructor_name'] ?? '—';
$profile_pic   = $profile['profile_pic'] ?? '';

$total_assigned_q = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql']);
$total_assigned_q->execute(array_merge([$sup_id], $ay_filter['params']));
$total_assigned = (int) $total_assigned_q->fetchColumn();
$selected_year_label = $_SESSION['selected_academic_year_label'] ?? '';

$weeks = [];
if ($intern_start) {
    $w = 1;
    while (true) {
        $range = getWeekRange($intern_start, $w);
        if (!$range) break;
        if ($intern_end && $range['start'] > $intern_end) break;
        $weeks[$w] = $range;
        $w++;
    }
}

$selected_week = 1;
if (isset($_GET['week'])) {
    $w = (int) $_GET['week'];
    if (isset($weeks[$w])) $selected_week = $w;
}

$week_date_range = '';
if (!empty($weeks[$selected_week])) {
    $ws_obj = new DateTime($weeks[$selected_week]['start']);
    $we_obj = new DateTime($weeks[$selected_week]['end']);
    $week_date_range = $ws_obj->format('d M Y') . ' to ' . $we_obj->format('d M Y');
}

if (!empty($weeks)) {
    $esc_ws = $pdo->quote($weeks[$selected_week]['start']);
    $esc_we = $pdo->quote($weeks[$selected_week]['end']);
    $log_r = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date DESC");
    $log_r->execute([$student_id, $weeks[$selected_week]['start'], $weeks[$selected_week]['end']]);
} else {
    $log_r = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date DESC");
    $log_r->execute([$student_id]);
}
$recent_logs = $log_r->fetchAll();

// ── Selected-week summary (used only by the weekly sections below) ─
// Attendance is recorded per day (one daily_log row per date, enforced by
// the unique index on internship_id + log_date). Missing days simply have
// no row and are NOT counted as present or absent — matching the rest of
// the app (attendance_rate = present / logged_days).
$week_logs_count = count($recent_logs);

$week_att = !empty($weeks[$selected_week])
    ? internship_attendance($pdo, $student_id, $weeks[$selected_week]['start'], $weeks[$selected_week]['end'])
    : ['present' => 0, 'absent' => 0, 'expected' => 0, 'rate' => 0];
$week_present_count = $week_att['present'];
$week_absent_count  = $week_att['absent'];

// ── Entire-internship summary (statistics cards) ──────────────────
$total_internship_weeks = count($weeks);

$intern_att    = internship_attendance($pdo, $student_id);
$intern_logs_q = $pdo->prepare("SELECT log_date, calculated_duration FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$intern_logs_q->execute([$student_id]);
$intern_total_minutes = 0;
$intern_log_days      = 0;
$seen_intern_dates    = [];
foreach ($intern_logs_q->fetchAll() as $log) {
    if (!isset($seen_intern_dates[$log['log_date']])) {
        $seen_intern_dates[$log['log_date']] = true;
        $intern_log_days++;
    }
    $dur_parts = explode(':', (string) ($log['calculated_duration'] ?? ''));
    if (count($dur_parts) === 2) {
        $intern_total_minutes += ((int)$dur_parts[0] * 60) + (int)$dur_parts[1];
    }
}
$intern_hours = floor($intern_total_minutes / 60);
$intern_mins  = $intern_total_minutes % 60;

$ref_r = $pdo->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$ref_r->execute([$student_id, $selected_week]);
$weekly_refs = $ref_r->fetchAll();

$eval_r = $pdo->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$eval_r->execute([$student_id, $selected_week]);
$evaluation = $eval_r->fetch();

$sup_eval_r = $pdo->prepare("SELECT * FROM supervisor_weekly_evaluations WHERE student_id = ? AND week_number = ?");
$sup_eval_r->execute([$student_id, $selected_week]);
$sup_evaluation = $sup_eval_r->fetch();

$today_obj = new DateTime();
$today_str = $today_obj->format('Y-m-d');
$dynamic_week = 1;
$not_started = false;
if ($intern_start) {
    $dynamic_week = internship_current_week($intern_start, $intern_end ?: null, $today_obj);
    if ($today_obj < new DateTime($intern_start)) {
        $not_started = true;
    }
} else {
    $not_started = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student_name) ?> – Student View</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
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
    function toggleWeekDropdown() {
        document.getElementById('week-menu').classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('week-dropdown');
        if (dd && !dd.contains(e.target)) {
            document.getElementById('week-menu').classList.add('hidden');
        }
    });
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

    function openAnnouncementModal(annId) {
        var modal = document.getElementById('ann-detail-modal');
        var backdrop = document.getElementById('ann-detail-backdrop');
        var body = document.getElementById('ann-detail-body');
        var title = document.getElementById('ann-detail-title');
        var sender = document.getElementById('ann-detail-sender');
        var date = document.getElementById('ann-detail-date');
        if (!modal) return;
        body.innerHTML = '<div class="flex items-center justify-center py-12"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div></div>';
        title.textContent = 'Loading...';
        sender.textContent = '';
        date.textContent = '';
        modal.classList.remove('hidden');
        backdrop.classList.remove('hidden');
        requestAnimationFrame(function() { modal.style.opacity = '1'; modal.style.transform = 'scale(1)'; backdrop.style.opacity = '1'; });
        document.body.style.overflow = 'hidden';
        fetch('get_announcement.php?id=' + annId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { body.innerHTML = '<p class="text-sm text-red-400 text-center py-8">' + data.error + '</p>'; return; }
            var a = data.announcement;
            title.textContent = a.title;
            sender.textContent = a.sender_name || 'Admin';
            date.textContent = new Date(a.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            body.innerHTML = '<div class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">' + a.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</div>';
            updateNotifBadge(data.unread_count);
        })
        .catch(function() { body.innerHTML = '<p class="text-sm text-red-400 text-center py-8">Failed to load announcement.</p>'; });
    }
    function closeAnnouncementModal() {
        var modal = document.getElementById('ann-detail-modal');
        var backdrop = document.getElementById('ann-detail-backdrop');
        if (modal && backdrop) {
            modal.style.opacity = '0'; modal.style.transform = 'scale(0.95)'; backdrop.style.opacity = '0';
            setTimeout(function() { modal.classList.add('hidden'); backdrop.classList.add('hidden'); }, 200);
            document.body.style.overflow = '';
        }
    }
    document.addEventListener('click', function(e) { if (e.target.id === 'ann-detail-backdrop') closeAnnouncementModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAnnouncementModal(); });

    function markNotifRead(el) {
        var notifId = el.getAttribute('data-notif-id');
        var redirectUrl = el.getAttribute('data-redirect-url') || 'supervisor-dashboard.php';
        var fd = new FormData();
        fd.append('notification_id', notifId);
        fd.append('mark_notification_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) { updateNotifBadge(data.unread_count); })
          .catch(function() {});
        var annMatch = redirectUrl.match(/announcement-detail\.php\?id=(\d+)/);
        if (annMatch) {
            openAnnouncementModal(annMatch[1]);
            var dd = document.getElementById('notif-dropdown');
            if (dd) { dd.classList.remove('show'); dd.style.opacity = '0'; dd.style.visibility = 'hidden'; dd.style.transform = 'translateY(-8px) scale(0.95)'; }
            return;
        }
        window.location.href = redirectUrl;
    }
    function markAllNotifsRead() {
        var fd = new FormData();
        fd.append('mark_all_notifications_read', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); })
          .then(function(data) { updateNotifBadge(data.unread_count); })
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
                    span.className = 'absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse';
                    span.textContent = count > 9 ? '9+' : count;
                    bell.appendChild(span);
                }
            }
        } else if (existing) {
            existing.remove();
        }
    }
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
    <style>
    .glow-indigo { box-shadow: 0 4px 20px rgba(99,102,241,0.25); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4">
                <a href="view-student-dashboard.php" class="flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-purple-600 transition-colors duration-200 cursor-pointer">
                    <span class="p-2 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded-full border border-slate-100 shadow-sm transition-all duration-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </span>
                </a>
                <div class="w-px h-6 bg-slate-200 hidden sm:block"></div>
                <h1 class="text-base font-bold text-slate-800 hidden sm:block"><?= htmlspecialchars($student_name) ?></h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if (!empty($selected_year_label)): ?>
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year_label) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 border border-purple-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                    <span class="text-xs font-bold text-purple-700">Week <?= $selected_week ?></span>
                    <?php if ($week_date_range): ?>
                    <span class="text-xs font-bold text-purple-600 bg-purple-100 px-1.5 py-0.5 rounded font-mono hidden md:inline"><?= $week_date_range ?></span>
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
                                <?php $notif_url = notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null); ?>
                                <div class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="markNotifRead(this)">
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
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- Student Info Bar -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5">
                    <div class="flex items-center gap-4">
                        <?php if ($profile_pic): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-14 h-14 rounded-full object-cover border-2 border-white shadow-lg shadow-indigo-500/20 shrink-0">
                        <?php else: ?>
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-lg font-bold shadow-lg shadow-indigo-500/20 shrink-0">
                            <?= strtoupper(substr($student_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-sm font-black text-slate-800"><?= htmlspecialchars($student_name) ?></h2>
                            <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($profile['email'] ?? '') ?></p>
                            <div class="flex items-center gap-3 mt-1">
                                <?php if ($student_roll): ?>
                                <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($student_roll) ?></span>
                                <?php endif; ?>
                                <?php if ($major): ?>
                                <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded"><?= htmlspecialchars($major) ?></span>
                                <?php endif; ?>
                                <?php if ($job_role): ?>
                                <span class="text-xs font-bold text-violet-600 bg-violet-50 px-2 py-0.5 rounded border border-violet-200/60">💼 <?= htmlspecialchars($job_role) ?></span>
                                <?php endif; ?>
                                <?php if ($company_name): ?>
                                <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">🏢 <?= htmlspecialchars($company_name) ?></span>
                                <?php endif; ?>
                                <?php if ($phone): ?>
                                <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">📱 <?= htmlspecialchars($phone) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                        <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                            <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <?= strtoupper(substr($profile['supervisor_name'] ?? '', 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-slate-400 uppercase tracking-wider">Supervisor</p>
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($profile['supervisor_name'] ?? '—') ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
                            <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <?= strtoupper(substr($instructor_name, 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-slate-400 uppercase tracking-wider">Instructor</p>
                                <p class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($instructor_name) ?></p>
                            </div>
                        </div>
                        <?php if ($intern_start && $intern_end): ?>
                        <div class="flex items-center gap-3 bg-indigo-50/50 rounded-xl px-4 py-3 border border-indigo-200/50">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-indigo-400 uppercase tracking-wider">Internship Period</p>
                                <p class="text-xs font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?> – <?= (new DateTime($intern_end))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <?php elseif ($intern_start): ?>
                        <div class="flex items-center gap-3 bg-indigo-50/50 rounded-xl px-4 py-3 border border-indigo-200/50">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm shrink-0">📅</div>
                            <div class="min-w-0">
                                <p class="text-label font-bold text-indigo-400 uppercase tracking-wider">Internship Start</p>
                                <p class="text-xs font-bold text-indigo-700"><?= (new DateTime($intern_start))->format('d M Y') ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-lg shadow-lg shadow-blue-500/30 mb-3">⏱️</div>
                        <p class="text-2xl font-black text-slate-800"><?= $intern_hours ?>h <?= $intern_mins ?>m</p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Total Hours Worked</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-violet-600 text-white flex items-center justify-center text-lg shadow-lg shadow-violet-500/30 mb-3">📋</div>
                        <p class="text-2xl font-black text-slate-800"><?= $intern_log_days ?></p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Daily Logs Submitted</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-lg shadow-lg shadow-emerald-500/30 mb-3">✅</div>
                        <p class="text-2xl font-black text-slate-800"><?= $intern_att['rate'] ?>%</p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Attendance Rate</p>
                        <p class="text-[10px] text-slate-500 font-semibold mt-0.5"><?= $intern_att['present'] ?>/<?= $intern_att['expected'] ?> days</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4 hover:shadow-md transition-shadow duration-300">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-lg shadow-lg shadow-purple-500/30 mb-3">📆</div>
                        <p class="text-2xl font-black text-slate-800"><?= $total_internship_weeks > 0 ? $total_internship_weeks : '—' ?></p>
                        <p class="text-xs text-slate-400 font-medium mt-1">Total Internship Weeks</p>
                    </div>
                </div>

                <!-- Week Selector -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            <div class="relative" id="week-dropdown">
                                <button onclick="toggleWeekDropdown()" class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition cursor-pointer whitespace-nowrap">
                                    📆 Week <?= $selected_week ?>
                                    <span class="text-slate-400 text-label">▾</span>
                                </button>
                                <div id="week-menu" class="absolute left-0 top-full mt-1 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden overflow-hidden max-h-64 overflow-y-auto">
                                    <?php foreach ($weeks as $wn => $wr): ?>
                                    <a href="?id=<?= $student_id ?>&week=<?= $wn ?>" class="flex items-center justify-between px-3 py-2 text-xs font-semibold <?= $selected_week === $wn ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?> transition">
                                        Week <?= $wn ?>
                                        <span class="text-label text-slate-400"><?= $wr['start'] ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($week_date_range): ?>
                            <span class="text-xs text-slate-400 font-medium"><?= $week_date_range ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="supervisor-review.php?student_id=<?= $student_id ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-xs font-bold rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-purple-500/20">
                            View & Grade
                        </a>
                    </div>
                </div>

                <!-- 2-Column Layout -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- LEFT (2/3): Logs + Reflection -->
                    <div class="lg:col-span-2 space-y-6">

                        <!-- Daily Logs Table -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📝</span> Daily Logs
                                </h2>
                                <span class="text-xs text-slate-400 font-medium"><?= $week_logs_count ?> day(s)</span>
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">✓ Present: <?= $week_present_count ?></span>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded">✕ Absent: <?= $week_absent_count ?></span>
                                </div>
                            </div>
                            <?php if (!empty($recent_logs)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                            <th class="px-4 py-3 text-left">Date</th>
                                            <th class="px-4 py-3 text-left">Status</th>
                                            <th class="px-4 py-3 text-left">Task</th>
                                            <th class="px-4 py-3 text-left">Details</th>
                                            <th class="px-4 py-3 text-left">Duration</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($recent_logs as $log): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                            <td class="px-4 py-3 font-medium text-slate-700 whitespace-nowrap">
                                                <?= (new DateTime($log['log_date']))->format('D, d M') ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">✓ Present</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded" <?= !empty($log['reason_for_absence']) ? 'title="' . htmlspecialchars(($log['attendance_status'] === 'leave' ? 'Leave' : 'Absent') . ': ' . $log['reason_for_absence']) . '"' : '' ?>>✕ Absent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600 max-w-[180px] truncate" title="<?= htmlspecialchars($log['task_title'] ?? '') ?>"><?= htmlspecialchars($log['task_title'] ?? '—') ?></td>
                                            <td class="px-4 py-3 text-slate-600 max-w-[200px] truncate" title="<?= htmlspecialchars($log['task_detail'] ?? '') ?>"><?= htmlspecialchars($log['task_detail'] ?? '—') ?></td>
                                            <td class="px-4 py-3 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="p-8 text-center text-xs text-slate-400">No daily logs found for Week <?= $selected_week ?>.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Weekly Reflection -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span> Weekly Reflection
                                </h2>
                            </div>
                            <?php if (!empty($weekly_refs)): ?>
                            <div class="p-5 space-y-4">
                                <?php foreach ($weekly_refs as $ref): ?>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">❓ What was done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['what_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">⚙️ How was it done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['how_done'] ?? '')) ?></p>
                                    </div>
                                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">🎯 Why was it done?</span>
                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['why_done'] ?? '')) ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-8 text-center text-xs text-slate-400">No weekly reflection submitted for Week <?= $selected_week ?>.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RIGHT (1/3): Evaluation Status -->
                    <div class="lg:col-span-1 space-y-6">
                        <!-- Instructor Evaluation -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-amber-50 text-amber-500 rounded">👨‍🏫</span> Instructor Evaluation
                                </h2>
                            </div>
                            <?php if ($evaluation && ($evaluation['report_status'] === 'approved_by_instructor' || $evaluation['report_status'] === 'approved_by_supervisor')): ?>
                            <div class="p-5 space-y-3">
                                <div class="flex items-center gap-2 text-sm text-emerald-600 bg-emerald-50 px-3 py-2 rounded-xl font-bold">
                                    <span>✅</span> Approved
                                </div>
                                <?php if ($evaluation['grade']): ?>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Grade</span>
                                    <p class="text-sm font-bold text-slate-700 mt-0.5"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $evaluation['grade']))) ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if ($evaluation['comment']): ?>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Comment</span>
                                    <p class="text-xs text-slate-600 leading-relaxed mt-0.5"><?= nl2br(htmlspecialchars($evaluation['comment'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($evaluation && $evaluation['report_status'] === 'rejected'): ?>
                            <div class="p-5 space-y-3">
                                <div class="flex items-center gap-2 text-sm text-red-600 bg-red-50 px-3 py-2 rounded-xl font-bold">
                                    <span>❌</span> Rejected
                                </div>
                                <?php if ($evaluation['instructor_comments']): ?>
                                <div class="bg-red-50 rounded-xl p-3 border border-red-200">
                                    <span class="text-xs font-bold text-red-400 uppercase tracking-wider">Reason</span>
                                    <p class="text-xs text-red-600 leading-relaxed mt-0.5"><?= nl2br(htmlspecialchars($evaluation['instructor_comments'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-5 text-center">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-lg mx-auto mb-2">⏳</div>
                                <p class="text-xs text-slate-400 font-medium">Pending instructor review.</p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Supervisor Evaluation -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-purple-50 text-purple-500 rounded">👩‍🏫</span> Supervisor Grade
                                </h2>
                            </div>
                            <?php if ($sup_evaluation): ?>
                            <div class="p-5 space-y-3">
                                <div class="flex items-center gap-2 text-sm text-emerald-600 bg-emerald-50 px-3 py-2 rounded-xl font-bold">
                                    <span>✅</span> Graded
                                </div>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Grade</span>
                                    <p class="text-sm font-bold text-slate-700 mt-0.5"><?= htmlspecialchars($sup_evaluation['weekly_grade'] ?? '—') ?></p>
                                </div>
                                <?php if (!empty($sup_evaluation['feedback'])): ?>
                                <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Feedback</span>
                                    <p class="text-xs text-slate-600 leading-relaxed mt-0.5"><?= nl2br(htmlspecialchars($sup_evaluation['feedback'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-5 text-center">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-lg mx-auto mb-2">⏳</div>
                                <p class="text-xs text-slate-400 font-medium">Not yet graded.</p>
                                <a href="supervisor-review.php?student_id=<?= $student_id ?>" class="mt-2 inline-block text-xs font-bold text-indigo-600 hover:underline">Grade now →</a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Links -->
                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-blue-50 text-blue-500 rounded">🔗</span> Quick Actions
                                </h2>
                            </div>
                            <div class="p-4 space-y-2">
                                <a href="supervisor-review.php?student_id=<?= $student_id ?>" class="flex items-center gap-2 px-3 py-2 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-xs font-bold rounded-xl hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-sm">
                                    View & Grade Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- Announcement Detail Modal -->
<div id="ann-detail-backdrop" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-[2000] transition-opacity duration-200" style="opacity:0"></div>
<div id="ann-detail-modal" class="hidden fixed inset-0 z-[2001] flex items-center justify-center p-4 transition-all duration-200" style="opacity:0;transform:scale(0.95)">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
        <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-8 py-6 shrink-0">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">📢</span>
                    <span class="text-xs font-bold text-blue-200 uppercase tracking-wider bg-white/20 backdrop-blur-sm px-3 py-1 rounded-full">Announcement</span>
                </div>
                <button onclick="closeAnnouncementModal()" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition shrink-0 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <h1 id="ann-detail-title" class="text-xl font-black text-white leading-tight mt-4">Loading...</h1>
            <div class="flex items-center gap-4 mt-3 text-sm text-blue-200 font-medium">
                <span class="flex items-center gap-1.5" id="ann-detail-sender"></span>
                <span class="flex items-center gap-1.5" id="ann-detail-date"></span>
            </div>
        </div>
        <div id="ann-detail-body" class="flex-1 overflow-y-auto px-8 py-6">
            <div class="flex items-center justify-center py-12"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div></div>
        </div>
        <div class="px-8 py-4 border-t border-slate-100 flex items-center justify-end shrink-0 bg-slate-50/80">
            <button onclick="closeAnnouncementModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">Close</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
