<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

// ── Notification redirect URL helper ────────────────────────────
function notif_redirect_url($type, $related_week) {
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

// ── Mark notification as read ──────────────────────────────────
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
    header('Location: supervisor-companies.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: supervisor-companies.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ── Fetch notifications ─────────────────────────────────────────
$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

// ── Search filter ───────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ── Summary counts (assigned students scope) ───────────────────
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

$company_count_q = $pdo->prepare("
    SELECT COUNT(DISTINCT sp.company_name) FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
");
$company_count_q->execute([$sup_id]);
$company_count = (int) $company_count_q->fetchColumn();

// ── Students grouped by company (assigned students scope) ──────
$sql = "
    SELECT u.id AS uid, u.username,
           sp.full_name, sp.student_roll, sp.job_role, sp.company_name,
           c.id AS company_id, c.address, c.contact_person, c.contact_email, c.contact_phone, c.website
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    LEFT JOIN companies c ON c.company_name = sp.company_name
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
";
$params = [$sup_id];

if ($search) {
    $sql .= " AND (sp.company_name LIKE ? OR sp.full_name LIKE ? OR sp.job_role LIKE ? OR c.contact_person LIKE ? OR c.contact_email LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY sp.company_name ASC, sp.full_name ASC";

$companies_stmt = $pdo->prepare($sql);
$companies_stmt->execute($params);
$company_rows = $companies_stmt->fetchAll();

// Group rows by company name in PHP
$companies = [];
foreach ($company_rows as $row) {
    $key = $row['company_name'];
    if (!isset($companies[$key])) {
        $companies[$key] = [
            'company_name' => $row['company_name'],
            'company_id'   => $row['company_id'],
            'address'      => $row['address'],
            'contact_person' => $row['contact_person'],
            'contact_email'  => $row['contact_email'],
            'contact_phone'  => $row['contact_phone'],
            'website'        => $row['website'],
            'students'     => [],
        ];
    }
    $companies[$key]['students'][] = [
        'uid'         => (int) $row['uid'],
        'full_name'   => $row['full_name'],
        'username'    => $row['username'],
        'student_roll'=> $row['student_roll'],
        'job_role'    => $row['job_role'],
    ];
}

$filtered_count = count($companies);

function build_query_url($overrides = []) {
    $q = array_merge($_GET, $overrides);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    return $q;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Companies – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        .scroll-margin { scroll-margin-top: 88px; }
    </style>
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
        var nd = document.getElementById('notif-dropdown');
        if (nd) { nd.style.opacity = '0'; nd.style.visibility = 'hidden'; nd.style.transform = 'translateY(-8px) scale(0.95)'; }
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
        var visible = dd.style.opacity === '1';
        dd.style.opacity    = visible ? '0'    : '1';
        dd.style.visibility = visible ? 'hidden' : 'visible';
        dd.style.transform  = visible ? 'translateY(-8px) scale(0.95)' : 'translateY(0) scale(1)';
        var pm = document.getElementById('profile-dropdown-menu');
        if (pm) pm.classList.add('hidden');
    }
    document.addEventListener('click', function(e) {
        var wrapper = document.getElementById('notif-bell-wrapper');
        var dd = document.getElementById('notif-dropdown');
        if (wrapper && dd && !wrapper.contains(e.target)) {
            dd.style.opacity = '0';
            dd.style.visibility = 'hidden';
            dd.style.transform = 'translateY(-8px) scale(0.95)';
        }
    });

    function openNotifFromSidebar() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        setTimeout(function() { toggleNotifDropdown(); }, 300);
    }

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

    function onNotificationItemClick(e, el) {
        e.preventDefault();
        var id = el.getAttribute('data-notif-id');
        var url = el.getAttribute('data-redirect-url') || 'supervisor-companies.php';
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
          .catch(function() {});
        window.location.href = url;
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
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'companies'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Header -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <h1 class="text-base font-bold text-slate-800 hidden sm:block">University Supervisor Companies</h1>

                <!-- Search -->
                <form method="GET" class="relative flex-1 max-w-xs hidden md:block ml-4">
                    <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search companies, students…"
                        class="w-full bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl pl-9 pr-9 py-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
                    <?php if ($search): ?>
                    <a href="?<?= http_build_query(build_query_url(['search' => ''])) ?>" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold px-1.5 py-0.5 rounded-full hover:bg-slate-200 transition" title="Clear search">✕</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="flex items-center gap-5 shrink-0">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span class="text-xs font-bold text-slate-600"><?= $company_count ?> active placement<?= $company_count !== 1 ? 's' : '' ?></span>
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
                                <?php $notif_url = notif_redirect_url($notif['type'], $notif['related_week'] ?? null); ?>
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

                    <!-- Profile -->
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

        <!-- ════ COMPANIES CONTENT ════ -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- ═══ PAGE HEADER ═══ -->
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 tracking-tight">🏢 Companies</h2>
                        <p class="text-sm text-slate-400 mt-1 font-medium">Placement companies of your assigned students</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600">🏢 <?= $company_count ?> active</span>
                        <?php if ($search): ?>
                        <a href="supervisor-companies.php" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all duration-200">✕ Clear search</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ COMPANIES GRID ═══ -->
                <?php if (!empty($companies)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <?php foreach ($companies as $company):
                        $student_count = count($company['students']);
                    ?>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden flex flex-col hover:shadow-md transition-shadow duration-300">
                        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xs font-black shrink-0 shadow-md shadow-blue-500/20">
                                <?= strtoupper(substr($company['company_name'], 0, 2)) ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($company['company_name']) ?></h3>
                                <?php if (!empty($company['website'])): ?>
                                <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline font-medium truncate block"><?= htmlspecialchars($company['website']) ?></a>
                                <?php else: ?>
                                <p class="text-xs text-slate-400 font-medium truncate">Company placement</p>
                                <?php endif; ?>
                            </div>
                            <span class="text-sm font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200/60 shrink-0"><?= $student_count ?> student<?= $student_count !== 1 ? 's' : '' ?></span>
                        </div>

                        <div class="px-5 py-4 flex-1 space-y-2.5">
                            <?php if (!empty($company['address']) || !empty($company['contact_person']) || !empty($company['contact_email']) || !empty($company['contact_phone'])): ?>
                            <div class="space-y-1.5">
                                <?php if (!empty($company['address'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-start gap-2"><span class="shrink-0">📍</span><span><?= htmlspecialchars($company['address']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_person'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-center gap-2"><span class="shrink-0">👤</span><span><?= htmlspecialchars($company['contact_person']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_email'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-center gap-2"><span class="shrink-0">✉️</span><a href="mailto:<?= htmlspecialchars($company['contact_email']) ?>" class="text-blue-600 hover:underline truncate"><?= htmlspecialchars($company['contact_email']) ?></a></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_phone'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-center gap-2"><span class="shrink-0">📞</span><span><?= htmlspecialchars($company['contact_phone']) ?></span></p>
                                <?php endif; ?>
                            </div>
                            <div class="border-t border-slate-100 pt-2.5"></div>
                            <?php else: ?>
                            <p class="text-xs text-slate-400 font-medium italic">No contact details on file.</p>
                            <div class="border-t border-slate-100 pt-2.5"></div>
                            <?php endif; ?>

                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Assigned Students</p>
                            <div class="space-y-2">
                                <?php foreach ($company['students'] as $stu): ?>
                                <a href="view-student-dashboard.php?id=<?= (int)$stu['uid'] ?>" class="flex items-center gap-2.5 p-2.5 bg-gradient-to-r from-slate-50 to-white border border-slate-100 rounded-xl hover:border-blue-200 hover:shadow-sm transition-all duration-200 group">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-xs font-black shrink-0 shadow-sm">
                                        <?= strtoupper(substr($stu['full_name'] ?: $stu['username'], 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-bold text-slate-700 truncate group-hover:text-purple-600 transition-colors duration-150"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                        <p class="text-[11px] text-slate-400 font-medium truncate"><?= htmlspecialchars($stu['student_roll'] ?: $stu['username']) ?><?= !empty($stu['job_role']) ? ' · ' . htmlspecialchars($stu['job_role']) : '' ?></p>
                                    </div>
                                    <span class="text-[11px] font-bold text-blue-600 opacity-0 group-hover:opacity-100 transition-opacity duration-150">View →</span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-16 text-center">
                    <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">🏢</div>
                    <p class="text-base font-bold text-slate-500">No companies yet</p>
                    <p class="text-sm text-slate-400 mt-1.5"><?= $search ? 'No companies match your search.' : 'Placement companies will appear here once students are assigned to you.' ?></p>
                    <?php if ($search): ?>
                    <a href="supervisor-companies.php" class="mt-5 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear search</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>
</body>
</html>
