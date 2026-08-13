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
require_once __DIR__ . '/../config/notify.php';

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
    header('Location: supervisor-reports.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: supervisor-reports.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
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
    header('Location: supervisor-reports.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ── Fetch notifications ─────────────────────────────────────────
$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

// ── Filters ─────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$allowed_statuses = ['pending', 'approved_by_instructor', 'approved_by_supervisor', 'rejected'];
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = '';

$filter_week = isset($_GET['week']) && $_GET['week'] !== '' ? (int) $_GET['week'] : null;
$filter_company = trim($_GET['company'] ?? '');
$search = trim($_GET['search'] ?? '');

$page = (isset($_GET['page']) && (int) $_GET['page'] > 0) ? (int) $_GET['page'] : 1;
$per_page = 12;

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

$total_reports_q = $pdo->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
");
$total_reports_q->execute([$sup_id]);
$total_reports = (int) $total_reports_q->fetchColumn();

// ── Available filter options ────────────────────────────────────
$weeks_q = $pdo->prepare("
    SELECT DISTINCT re.week_number
    FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
    ORDER BY re.week_number DESC
");
$weeks_q->execute([$sup_id]);
$available_weeks = $weeks_q->fetchAll(PDO::FETCH_COLUMN);

$companies_q = $pdo->prepare("
    SELECT DISTINCT sp.company_name
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
    ORDER BY sp.company_name ASC
");
$companies_q->execute([$sup_id]);
$available_companies = $companies_q->fetchAll(PDO::FETCH_COLUMN);

// ── Main reports query ─────────────────────────────────────────
$base_sql = "
    SELECT re.*, u.id AS uid, u.username, u.academic_year,
           sp.full_name, sp.student_roll, sp.company_name,
           swe.weekly_grade
    FROM report_evaluations re
    JOIN users u ON u.id = re.student_id
    JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN supervisor_weekly_evaluations swe
           ON swe.student_id = re.student_id AND swe.week_number = re.week_number
    WHERE u.role = 'student' AND sp.supervisor_id = ?
";
$where = '';
$params = [$sup_id];

if ($filter_status) {
    $where .= " AND re.report_status = ?";
    $params[] = $filter_status;
}
if ($filter_week) {
    $where .= " AND re.week_number = ?";
    $params[] = $filter_week;
}
if ($filter_company) {
    $where .= " AND sp.company_name = ?";
    $params[] = $filter_company;
}
if ($search) {
    $where .= " AND (sp.full_name LIKE ? OR u.username LIKE ? OR sp.student_roll LIKE ? OR sp.company_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

$count_sql = "SELECT COUNT(*) FROM report_evaluations re
              JOIN users u ON u.id = re.student_id
              JOIN student_profiles sp ON sp.user_id = u.id
              WHERE u.role = 'student' AND sp.supervisor_id = ?" . $where;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_filtered = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = $base_sql . $where . " ORDER BY re.evaluated_at DESC LIMIT $per_page OFFSET $offset";
$reports_stmt = $pdo->prepare($sql);
$reports_stmt->execute($params);
$reports = $reports_stmt->fetchAll();

// ── Status badge helper ────────────────────────────────────────
function report_status_badge($status) {
    switch ($status) {
        case 'approved_by_instructor':
            return ['Awaiting grade', 'text-amber-600 bg-amber-50 border-amber-200'];
        case 'approved_by_supervisor':
            return ['Graded', 'text-emerald-600 bg-emerald-50 border-emerald-200'];
        case 'rejected':
            return ['Rejected', 'text-red-600 bg-red-50 border-red-200'];
        default:
            return ['Pending', 'text-slate-500 bg-slate-50 border-slate-200'];
    }
}
function report_status_dot($status) {
    switch ($status) {
        case 'approved_by_instructor': return 'bg-amber-500';
        case 'approved_by_supervisor': return 'bg-emerald-500';
        case 'rejected': return 'bg-red-500';
        default: return 'bg-slate-400';
    }
}

function build_query_url($overrides = []) {
    $q = array_merge($_GET, $overrides);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    unset($q['page']);
    return $q;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Reports – InternReport</title>
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
        var url = el.getAttribute('data-redirect-url') || 'supervisor-reports.php';
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
    <?php $active_page = 'reports'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Header -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <h1 class="text-base font-bold text-slate-800 hidden sm:block">University Supervisor Reports</h1>

                <!-- Search -->
                <form method="GET" class="relative flex-1 max-w-xs hidden md:block ml-4">
                    <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                    <?php if ($filter_week): ?><input type="hidden" name="week" value="<?= (int)$filter_week ?>"><?php endif; ?>
                    <?php if ($filter_company): ?><input type="hidden" name="company" value="<?= htmlspecialchars($filter_company) ?>"><?php endif; ?>
                    <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search students, companies…"
                        class="w-full bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl pl-9 pr-9 py-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
                    <?php if ($search): ?>
                    <a href="?<?= http_build_query(build_query_url(['search' => ''])) ?>" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold px-1.5 py-0.5 rounded-full hover:bg-slate-200 transition" title="Clear search">✕</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="flex items-center gap-5 shrink-0">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-amber-500 <?= $pending_reviews > 0 ? 'animate-pulse' : '' ?>"></span>
                    <span class="text-xs font-bold text-slate-600"><?= $pending_reviews ?> pending review<?= $pending_reviews !== 1 ? 's' : '' ?></span>
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

        <!-- ════ REPORTS CONTENT ════ -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- ═══ PAGE HEADER ═══ -->
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 tracking-tight">📄 Reports</h2>
                        <p class="text-sm text-slate-400 mt-1 font-medium">Weekly reports submitted by your assigned students</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600">📄 <?= $total_reports ?> total</span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-xl text-xs font-bold text-amber-600">⏳ <?= $pending_reviews ?> awaiting review</span>
                    </div>
                </div>

                <!-- ═══ FILTERS ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <a href="?<?= http_build_query(build_query_url(['status' => '', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === '' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">All</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'approved_by_instructor', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'approved_by_instructor' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-amber-600 border-amber-200 hover:bg-amber-50' ?>">⏳ Awaiting grade</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'approved_by_supervisor', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'approved_by_supervisor' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-emerald-600 border-emerald-200 hover:bg-emerald-50' ?>">✅ Graded</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'rejected', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'rejected' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-red-600 border-red-200 hover:bg-red-50' ?>">✕ Rejected</a>
                            <a href="?<?= http_build_query(build_query_url(['status' => 'pending', 'page' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'pending' ? 'bg-slate-600 text-white border-slate-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50' ?>">Pending</a>
                        </div>

                        <div class="flex-1"></div>

                        <form method="GET" class="flex items-center gap-2 flex-wrap">
                            <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                            <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>

                            <?php if (!empty($available_weeks)): ?>
                            <select name="week" onchange="this.form.submit()" class="bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl px-3 py-2 text-xs text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200 cursor-pointer">
                                <option value="">All weeks</option>
                                <?php foreach ($available_weeks as $wk): ?>
                                <option value="<?= (int)$wk ?>" <?= $filter_week === (int)$wk ? 'selected' : '' ?>>Week <?= (int)$wk ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <?php if (!empty($available_companies)): ?>
                            <select name="company" onchange="this.form.submit()" class="bg-slate-100/80 border border-transparent focus:border-indigo-300 rounded-xl px-3 py-2 text-xs text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all duration-200 cursor-pointer max-w-[13rem]">
                                <option value="">All companies</option>
                                <?php foreach ($available_companies as $comp): ?>
                                <option value="<?= htmlspecialchars($comp) ?>" <?= $filter_company === $comp ? 'selected' : '' ?>><?= htmlspecialchars($comp) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <?php if ($filter_status || $filter_week || $filter_company || $search): ?>
                            <a href="supervisor-reports.php" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition-all duration-200">✕ Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- ═══ REPORTS TABLE ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <?php if (!empty($reports)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gradient-to-r from-slate-50 to-white border-b border-slate-100">
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Week</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider">Submitted</th>
                                    <th class="px-6 py-3.5 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($reports as $rep):
                                    $badge = report_status_badge($rep['report_status']);
                                    $rep_student = $rep['full_name'] ?: $rep['username'];
                                ?>
                                <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-md shadow-purple-500/20">
                                                <?= strtoupper(substr($rep_student, 0, 1)) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <a href="view-student-dashboard.php?id=<?= (int)$rep['uid'] ?>" class="text-sm font-bold text-slate-800 hover:text-purple-600 transition-colors duration-150 truncate block"><?= htmlspecialchars($rep_student) ?></a>
                                                <p class="text-xs text-slate-400 font-medium"><?= htmlspecialchars($rep['student_roll'] ?: $rep['username']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="text-sm font-medium text-blue-600 truncate block max-w-[14rem]"><?= htmlspecialchars($rep['company_name'] ?: '—') ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700">
                                            Week <?= (int)$rep['week_number'] ?>
                                            <?php if (!empty($rep['weekly_grade'])): ?>
                                            <span class="text-xs font-black text-emerald-600 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-lg"><?= htmlspecialchars($rep['weekly_grade']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold <?= $badge[1] ?> border px-2.5 py-1 rounded-lg">
                                            <span class="w-1.5 h-1.5 rounded-full <?= report_status_dot($rep['report_status']) ?>"></span> <?= $badge[0] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="text-xs text-slate-500 font-medium"><?= htmlspecialchars((new DateTime($rep['evaluated_at']))->format('d M Y, h:i A')) ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($rep['report_status'] === 'approved_by_supervisor'): ?>
                                        <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-all duration-200">👁️ View</a>
                                        <?php else: ?>
                                        <a href="supervisor-review.php?student_id=<?= (int)$rep['student_id'] ?>&week=<?= (int)$rep['week_number'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white text-xs font-bold rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-purple-500/20">🔍 Review</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between flex-wrap gap-3">
                        <p class="text-xs text-slate-400 font-medium">Showing <?= $total_filtered > 0 ? ($offset + 1) : 0 ?>–<?= min($offset + $per_page, $total_filtered) ?> of <?= $total_filtered ?> reports</p>
                        <div class="flex items-center gap-1.5">
                            <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(build_query_url(['page' => $page - 1])) ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-all duration-200">← Prev</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?<?= http_build_query(build_query_url(['page' => $i])) ?>" class="w-8 h-8 flex items-center justify-center text-xs font-bold rounded-lg transition-all duration-200 <?= $i === $page ? 'bg-slate-800 text-white' : 'text-slate-600 bg-slate-100 hover:bg-slate-200' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(build_query_url(['page' => $page + 1])) ?>" class="px-3 py-1.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-all duration-200">Next →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="p-16 text-center">
                        <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">📭</div>
                        <p class="text-base font-bold text-slate-500">No reports found</p>
                        <p class="text-sm text-slate-400 mt-1.5"><?= $search || $filter_status || $filter_week || $filter_company ? 'Try adjusting your filters or search terms.' : 'No weekly reports have been submitted by your students yet.' ?></p>
                        <?php if ($search || $filter_status || $filter_week || $filter_company): ?>
                        <a href="supervisor-reports.php" class="mt-5 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear all filters</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
