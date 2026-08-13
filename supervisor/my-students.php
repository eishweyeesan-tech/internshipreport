<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';
require_once __DIR__ . '/../config/internship_progress.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

require_once __DIR__ . '/../config/notify.php';

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
    header('Location: my-students.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: my-students.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
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
    header('Location: my-students.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

// ── Academic year filter (from session, same as dashboard) ──────
$ay_filter = get_ay_filter($pdo, 'u');

// ── Filters ─────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
if (!in_array($filter_status, ['red', 'amber', 'green', 'none'], true)) $filter_status = '';
$search = trim($_GET['search'] ?? '');

// ── Summary counts (assigned students scope) ───────────────────
$pending_reviews_q = $pdo->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    WHERE re.report_status = 'approved_by_instructor'
      AND re.student_id IN (
          SELECT u.id FROM users u
          JOIN student_profiles sp ON sp.user_id = u.id
          WHERE u.role = 'student' AND sp.supervisor_id = ?" . $ay_filter['sql'] . "
      )
      AND NOT EXISTS (
          SELECT 1 FROM supervisor_weekly_evaluations swe
          WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
      )
");
$pending_reviews_q->execute(array_merge([$sup_id], $ay_filter['params']));
$pending_reviews = (int) $pending_reviews_q->fetchColumn();

$total_assigned_q = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql']);
$total_assigned_q->execute(array_merge([$sup_id], $ay_filter['params']));
$total_assigned = (int) $total_assigned_q->fetchColumn();

// ── Students detail (assigned + active) ─────────────────────────
$sql = "
    SELECT u.id AS uid, u.username, u.email, u.academic_year, u.profile_pic,
           sp.full_name, sp.student_roll, sp.major, sp.phone,
           sp.company_name, sp.job_role,
           sp.instructor_name, sp.instructor_email, sp.instructor_phone,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql'] . "
";
$params = array_merge([$sup_id], $ay_filter['params']);

if ($search) {
    $sql .= " AND (sp.full_name LIKE ? OR u.username LIKE ? OR sp.student_roll LIKE ? OR sp.company_name LIKE ? OR sp.job_role LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY sp.full_name ASC";
$students_stmt = $pdo->prepare($sql);
$students_stmt->execute($params);
$students = $students_stmt->fetchAll();

// ── Attendance per student ──────────────────────────────────────
$attendance = [];
if (!empty($students)) {
    $ids = array_map(function ($s) { return (int) $s['uid']; }, $students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_q = $pdo->prepare("
        SELECT dl.internship_id,
               SUM(CASE WHEN dl.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
               COUNT(*) AS total_count
        FROM daily_logs dl
        WHERE dl.internship_id IN ($in_placeholders)
        GROUP BY dl.internship_id
    ");
    $att_q->execute($ids);
    foreach ($att_q->fetchAll() as $row) {
        $attendance[(int) $row['internship_id']] = $row;
    }
}

// ── Reports + graded weeks per student ──────────────────────────
$report_counts = [];
$graded_counts = [];
if (!empty($students)) {
    $ids = array_map(function ($s) { return (int) $s['uid']; }, $students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rc_q = $pdo->prepare("SELECT student_id, COUNT(*) AS cnt FROM report_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $rc_q->execute($ids);
    foreach ($rc_q->fetchAll() as $row) {
        $report_counts[(int) $row['student_id']] = (int) $row['cnt'];
    }
    $gc_q = $pdo->prepare("SELECT student_id, COUNT(*) AS cnt FROM supervisor_weekly_evaluations WHERE student_id IN ($in_placeholders) GROUP BY student_id");
    $gc_q->execute($ids);
    foreach ($gc_q->fetchAll() as $row) {
        $graded_counts[(int) $row['student_id']] = (int) $row['cnt'];
    }
}

// ── Students with a report awaiting supervisor review ───────────
$student_pending = [];
if (!empty($students)) {
    $ids = array_map(function ($s) { return (int) $s['uid']; }, $students);
    $in_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pq = $pdo->prepare("
        SELECT re.student_id, re.week_number FROM report_evaluations re
        WHERE re.report_status = 'approved_by_instructor'
          AND re.student_id IN ($in_placeholders)
          AND NOT EXISTS (
              SELECT 1 FROM supervisor_weekly_evaluations swe
              WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
          )
    ");
    $pq->execute($ids);
    foreach ($pq->fetchAll() as $row) {
        $student_pending[(int) $row['student_id']] = (int) $row['week_number'];
    }
}

// ── Dynamic week + progress status (consistent with dashboard) ──
$today_obj = new DateTime();
$dayOfWeek = (int) $today_obj->format('N');

$student_dynamic_week = [];
$student_not_started = [];
$student_progress = [];
foreach ($students as $sd) {
    $uid = $sd['uid'];
    $dynamic_week = 1;
    $not_started = false;

    if ($sd['internship_start_date']) {
        $start_date = $sd['internship_start_date'];
        $end_date   = $sd['internship_end_date'] ?: null;
        $dynamic_week = internship_current_week($start_date, $end_date, $today_obj);

        if ($today_obj < new DateTime($start_date)) {
            $not_started = true;
        }
    } else {
        $not_started = true;
    }

    $student_dynamic_week[$uid]  = $dynamic_week;
    $student_not_started[$uid]   = $not_started;
    $student_progress[$uid]      = internship_progress($pdo, $uid, $sd['internship_start_date'], $sd['internship_end_date']);
}

$progress_status = [];
$report_status_cache = [];
foreach ($students as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rs_q = $pdo->prepare("SELECT report_status FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $rs_q->execute([$uid, $dw]);
    $report_status_cache[$uid] = $rs_q->fetchColumn() ?: 'pending';
}

foreach ($students as $sd) {
    $uid = $sd['uid'];
    $dw = $student_dynamic_week[$uid] ?? 1;
    $rstatus = $report_status_cache[$uid] ?? 'pending';
    $not_started = $student_not_started[$uid] ?? false;

    if ($not_started) {
        $progress_status[$uid] = 'none';
        continue;
    }
    if ($rstatus === 'approved_by_supervisor') {
        $progress_status[$uid] = 'green';
        continue;
    }
    if ($sd['internship_start_date']) {
        $stu_start = new DateTime($sd['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        $sws = (new DateTime('monday this week'))->format('Y-m-d');
        $swe = (new DateTime('sunday this week'))->format('Y-m-d');
    }

    $log_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $log_q->execute([$uid, $sws, $swe]);
    $log_count = (int) $log_q->fetchColumn();

    if ($dayOfWeek >= 3 && $log_count === 0) {
        $progress_status[$uid] = 'red';
    } elseif ($log_count >= 1 && $log_count <= 4) {
        $progress_status[$uid] = 'amber';
    } elseif ($log_count >= 5) {
        $progress_status[$uid] = 'green';
    } else {
        $progress_status[$uid] = 'none';
    }
}

// ── Filter students by status ───────────────────────────────────
if ($filter_status) {
    $students = array_values(array_filter($students, function ($s) use ($filter_status, $progress_status) {
        return ($progress_status[$s['uid']] ?? 'none') === $filter_status;
    }));
}

// ── Helpers ─────────────────────────────────────────────────────
function status_label($status) {
    switch ($status) {
        case 'red':    return ['Behind Schedule', 'text-red-700 bg-red-50 border-red-200'];
        case 'amber':  return ['In Progress',     'text-amber-700 bg-amber-50 border-amber-200'];
        case 'green':  return ['Complete',        'text-emerald-700 bg-emerald-50 border-emerald-200'];
        default:       return ['Not Started',     'text-slate-500 bg-slate-50 border-slate-200'];
    }
}
function status_dot($status) {
    switch ($status) {
        case 'red':    return 'bg-red-500 animate-pulse';
        case 'amber':  return 'bg-amber-500';
        case 'green':  return 'bg-emerald-500';
        default:       return 'bg-slate-400';
    }
}
function bar_color($pct) {
    if ($pct >= 80) return 'from-emerald-500 to-emerald-600';
    if ($pct >= 40) return 'from-indigo-500 to-purple-600';
    return 'from-amber-500 to-orange-500';
}
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
    <title>My Students – InternReport</title>
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
        var url = el.getAttribute('data-redirect-url') || 'my-students.php';
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
    <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Header -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <h1 class="text-base font-bold text-slate-800 hidden sm:block">My Students</h1>

                <!-- Search -->
                <form method="GET" class="relative flex-1 max-w-xs hidden md:block ml-4">
                    <?php if ($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                    <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search students…"
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
                <h2 class="text-2xl font-black text-slate-800 tracking-tight">🎓 My Students</h2>
                <p class="text-sm text-slate-400 mt-1 font-medium">Interns currently assigned to you, with live weekly progress</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600">👥 <?= $total_assigned ?> assigned</span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-xl text-xs font-bold text-amber-600">⏳ <?= $pending_reviews ?> awaiting review</span>
            </div>
        </div>

        <!-- ═══ STATUS FILTER CHIPS ═══ -->
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <a href="?<?= http_build_query(build_query_url(['status' => ''])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === '' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">All</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'red'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'red' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-red-600 border-red-200 hover:bg-red-50' ?>">🔴 Behind Schedule</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'amber'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'amber' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-amber-600 border-amber-200 hover:bg-amber-50' ?>">🟡 In Progress</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'green'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'green' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-emerald-600 border-emerald-200 hover:bg-emerald-50' ?>">🟢 Complete</a>
                    <a href="?<?= http_build_query(build_query_url(['status' => 'none'])) ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $filter_status === 'none' ? 'bg-slate-600 text-white border-slate-600' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50' ?>">⚪ Not Started</a>
                </div>

                <div class="flex-1"></div>

                <?php if ($filter_status || $search): ?>
                <a href="my-students.php" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition-all duration-200">✕ Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($students)): ?>

        <!-- ═══ EMPTY STATE ═══ -->
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-16 text-center">
            <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">👥</div>
            <p class="text-base font-bold text-slate-500">No students found</p>
            <p class="text-sm text-slate-400 mt-1.5"><?= $search || $filter_status ? 'Try adjusting your search terms or filters.' : 'No interns are currently assigned to you.' ?></p>
            <?php if ($search || $filter_status): ?>
            <a href="my-students.php" class="mt-5 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear all filters</a>
            <?php endif; ?>
        </div>

        <?php else: ?>

        <!-- ═══ MY STUDENTS TABLE ═══ -->
        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gradient-to-r from-slate-50 to-white border-b border-slate-100 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                            <th class="px-5 py-3.5 text-left">Student</th>
                            <th class="px-5 py-3.5 text-left">Company</th>
                            <th class="px-5 py-3.5 text-left">Role</th>
                            <th class="px-5 py-3.5 text-left">Progress</th>
                            <th class="px-5 py-3.5 text-left">Reports</th>
                            <th class="px-5 py-3.5 text-left">Status</th>
                            <th class="px-5 py-3.5 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($students as $s):
                            $uid = (int) $s['uid'];
                            $status = $progress_status[$uid] ?? 'none';
                            $label = status_label($status);
                            $dot = status_dot($status);
                            $not_started = $student_not_started[$uid] ?? false;
                            $att = $attendance[$uid] ?? null;
                            $att_pct = $att && $att['total_count'] > 0 ? (int) round(($att['present_count'] / $att['total_count']) * 100) : 0;
                            $r_count = $report_counts[$uid] ?? 0;
                            $g_count = $graded_counts[$uid] ?? 0;
                            $name = $s['full_name'] ?: $s['username'];
                            $pending_week = $student_pending[$uid] ?? null;
                        ?>
                        <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                            <td class="px-5 py-4">
                                <a href="view-student-dashboard.php?id=<?= $uid ?>" class="flex items-center gap-3 hover:opacity-80 transition">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-md shadow-indigo-500/20">
                                        <?= strtoupper(substr($name, 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-bold text-slate-800 hover:text-indigo-600 transition truncate"><?= htmlspecialchars($name) ?></p>
                                        <p class="text-xs text-slate-400 font-medium mt-0.5 truncate"><?= htmlspecialchars($s['student_roll'] ?: $s['username']) ?><?= !empty($s['academic_year']) ? ' • ' . htmlspecialchars($s['academic_year']) : '' ?></p>
                                        <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($s['email']) ?><?= !empty($s['phone']) ? ' • ' . htmlspecialchars($s['phone']) : '' ?></p>
                                    </div>
                                </a>
                            </td>
                            <td class="px-5 py-4 text-slate-600 font-medium max-w-[170px] truncate" title="<?= htmlspecialchars($s['company_name'] ?? '') ?>"><?= htmlspecialchars($s['company_name'] ?: '—') ?></td>
                            <td class="px-5 py-4 text-slate-600 font-medium text-xs max-w-[140px]">
                                <?= htmlspecialchars($s['job_role'] ?: '—') ?>
                                <?php if (!empty($s['major'])): ?>
                                <p class="text-slate-400 mt-0.5 truncate"><?= htmlspecialchars($s['major']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2 min-w-[110px]">
                                    <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                                        <div class="h-2 rounded-full bg-gradient-to-r <?= bar_color($att_pct) ?> transition-all duration-500" style="width: <?= $att_pct ?>%"></div>
                                    </div>
                                    <span class="text-xs font-bold text-slate-600"><?= $att_pct ?>%</span>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1"><?= $not_started ? 'Not started' : 'Week ' . (int) ($student_progress[$uid]['completed'] ?? 0) . '/' . (int) ($student_progress[$uid]['total'] ?? 0) ?></p>
                                <p class="text-[11px] text-slate-400"><?= $s['internship_start_date'] ? (new DateTime($s['internship_start_date']))->format('d M Y') . ' – ' . ($s['internship_end_date'] ? (new DateTime($s['internship_end_date']))->format('d M Y') : '…') : '—' ?></p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg">📄 <?= $r_count ?></span>
                                    <span class="text-[11px] text-emerald-600 font-bold" title="Graded weeks">✓ <?= $g_count ?></span>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold <?= $label[1] ?> px-2.5 py-1 rounded-lg border whitespace-nowrap">
                                    <span class="w-2 h-2 rounded-full <?= $dot ?>"></span>
                                    <?= $label[0] ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <a href="view-student-dashboard.php?id=<?= $uid ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-indigo-500 to-purple-600 text-white text-xs font-bold rounded-lg hover:from-indigo-600 hover:to-purple-700 transition-all duration-200 shadow-md shadow-indigo-500/20">👁️ View</a>
                                    <?php if ($pending_week): ?>
                                    <a href="supervisor-review.php?student_id=<?= $uid ?>&week=<?= (int)$pending_week ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold rounded-lg transition-all duration-200 shadow-sm" title="Report awaiting your grade">📩 Grade</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>

    </div>
</main>

    </div>
</div>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
