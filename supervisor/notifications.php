<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'] ?? 'Supervisor';
$db       = $mysqli ?? $conn;

// ── Centralized Notification Action Handler (AJAX / POST) ────────
handle_notification_ajax_actions($db, $sup_id);

// ── Helper: Map notification to normalized category and UI metadata ──
if (!function_exists('get_supervisor_notif_meta')) {
    function get_supervisor_notif_meta($type, $title = '') {
        $t = strtolower(trim((string)$type));
        $tl = strtolower(trim((string)$title));

        if (in_array($t, ['report_needs_review', 'ready_for_review', 'instructor_approved'], true) || str_contains($tl, 'ready for') || str_contains($tl, 'instructor approved')) {
            return [
                'category'    => 'ready_for_review',
                'badge_label' => 'Ready for Review',
                'badge_class' => 'bg-emerald-50 text-emerald-700 border-emerald-200/80',
                'icon_bg'     => 'bg-emerald-100 text-emerald-600 ring-2 ring-emerald-50',
                'icon_svg'    => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
            ];
        }
        if (in_array($t, ['student_behind_schedule', 'behind_schedule'], true) || str_contains($tl, 'behind') || str_contains($tl, 'warning')) {
            return [
                'category'    => 'behind_schedule',
                'badge_label' => 'Behind Schedule',
                'badge_class' => 'bg-amber-50 text-amber-700 border-amber-200/80',
                'icon_bg'     => 'bg-amber-100 text-amber-600 ring-2 ring-amber-50',
                'icon_svg'    => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
            ];
        }
        if (in_array($t, ['new_report_submitted', 'new_report'], true) || str_contains($tl, 'new report') || str_contains($tl, 'submitted')) {
            return [
                'category'    => 'new_report',
                'badge_label' => 'New Report',
                'badge_class' => 'bg-blue-50 text-blue-700 border-blue-200/80',
                'icon_bg'     => 'bg-blue-100 text-blue-600 ring-2 ring-blue-50',
                'icon_svg'    => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            ];
        }
        if (in_array($t, ['internship_completed', 'completed'], true) || str_contains($tl, 'completed') || str_contains($tl, 'finished')) {
            return [
                'category'    => 'completed',
                'badge_label' => 'Completed',
                'badge_class' => 'bg-purple-50 text-purple-700 border-purple-200/80',
                'icon_bg'     => 'bg-purple-100 text-purple-600 ring-2 ring-purple-50',
                'icon_svg'    => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222"/></svg>',
            ];
        }
        return [
            'category'    => 'system',
            'badge_label' => 'System Notice',
            'badge_class' => 'bg-slate-100 text-slate-700 border-slate-200/80',
            'icon_bg'     => 'bg-slate-100 text-slate-600 ring-2 ring-slate-50',
            'icon_svg'    => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        ];
    }
}

// ── Fetch unread count (bell badge) ────────────────────────────
$unread_notif_q = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->bind_param("i", $sup_id);
$unread_notif_q->execute();
$res = $unread_notif_q->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

// ── Category Definitions ───────────────────────────────────────
$type_categories = [
    ''                 => 'All notifications',
    'ready_for_review' => 'Ready for Review',
    'new_report'       => 'New reports',
    'behind_schedule'  => 'Behind schedule',
    'completed'        => 'Internships completed',
    'system'           => 'System notices',
];

// Support both ?type= and ?filter= in URL
$type_filter = trim((string)($_GET['type'] ?? $_GET['filter'] ?? ''));
if ($type_filter === 'all' || !array_key_exists($type_filter, $type_categories)) {
    // Check aliases
    $alias_map = [
        'report_needs_review'     => 'ready_for_review',
        'new_report_submitted'    => 'new_report',
        'student_behind_schedule' => 'behind_schedule',
        'internship_completed'    => 'completed',
        'system_notice'           => 'system',
    ];
    $type_filter = $alias_map[$type_filter] ?? '';
}

// ── Compute counts per category ────────────────────────────────
$all_notifs_stmt = $db->prepare("SELECT id, type, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$all_notifs_stmt->bind_param("i", $sup_id);
$all_notifs_stmt->execute();
$all_res = $all_notifs_stmt->get_result();
$raw_notifications = $all_res ? $all_res->fetch_all(MYSQLI_ASSOC) : [];

$category_counts = [
    ''                 => count($raw_notifications),
    'ready_for_review' => 0,
    'new_report'       => 0,
    'behind_schedule'  => 0,
    'completed'        => 0,
    'system'           => 0,
];

$decorated_notifications = [];
foreach ($raw_notifications as $n) {
    $meta = get_supervisor_notif_meta($n['type'] ?? '', $n['title'] ?? '');
    $cat = $meta['category'];
    if (isset($category_counts[$cat])) {
        $category_counts[$cat]++;
    }
    $n['meta'] = $meta;
    $decorated_notifications[] = $n;
}

// ── Filter notifications according to selected category ───────
if ($type_filter !== '') {
    $filtered_notifications = array_values(array_filter($decorated_notifications, function($n) use ($type_filter) {
        return ($n['meta']['category'] ?? '') === $type_filter;
    }));
} else {
    $filtered_notifications = $decorated_notifications;
}

// ── Pagination ─────────────────────────────────────────────────
$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 15;
$total_notifs = count($filtered_notifications);
$total_pages  = max(1, (int) ceil($total_notifs / $per_page));
if ($page > $total_pages) { $page = $total_pages; }
$offset = ($page - 1) * $per_page;

$notifications = array_slice($filtered_notifications, $offset, $per_page);

// For Topbar compatibility
$recent_notifications = array_slice($decorated_notifications, 0, 10);

if (!function_exists('build_query_url')) {
    function build_query_url($overrides = []) {
        $q = array_merge($_GET, $overrides);
        foreach ($overrides as $k => $v) {
            if ($v === '' || $v === null) unset($q[$k]);
        }
        return $q;
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
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'notifications'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- Top Header -->
        <?php $pageTitle = 'Notifications'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ════ NOTIFICATIONS CONTENT ════ -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">

                <!-- Header Banner -->
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800 tracking-tight flex items-center gap-2">
                            <span>🔔 Notifications</span>
                            <?php if ($unread_notif_count > 0): ?>
                            <span id="page-unread-badge" class="px-2.5 py-0.5 text-xs font-bold bg-teal-600 text-white rounded-full"><?= $unread_notif_count ?> new</span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-xs text-slate-400 font-medium mt-1">All updates about your students' reports, schedules and internships.</p>
                    </div>

                    <?php if ($unread_notif_count > 0): ?>
                    <button onclick="markAllNotifsRead()" id="page-mark-all-btn" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-[#005f73] to-[#0a9396] hover:from-[#004e5f] hover:to-[#087f82] text-white text-xs font-semibold rounded-xl shadow-xs transition-all duration-200 ease-in-out cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Mark all as read
                    </button>
                    <?php endif; ?>
                </div>

                <!-- ════ DYNAMIC FILTER PILLS ════ -->
                <div class="flex items-center gap-2 flex-wrap" id="notif-filter-pills">
                    <?php foreach ($type_categories as $type_key => $type_label): ?>
                    <?php 
                        $is_active = ($type_filter === $type_key);
                        $pill_count = $category_counts[$type_key] ?? 0;
                        $pill_url = '?' . http_build_query(build_query_url(['type' => $type_key, 'filter' => '', 'page' => '']));
                    ?>
                    <a href="<?= htmlspecialchars($pill_url) ?>"
                       data-filter-pill="<?= htmlspecialchars($type_key) ?>"
                       class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-full border transition-all duration-200 ease-in-out cursor-pointer <?= $is_active ? 'bg-slate-800 text-white border-slate-800 shadow-xs' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 hover:border-slate-300' ?>">
                        <span><?= htmlspecialchars($type_label) ?></span>
                        <span class="px-1.5 py-0.5 text-[10px] font-bold rounded-full <?= $is_active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600' ?>">
                            <?= $pill_count ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- ════ NOTIFICATIONS LIST ════ -->
                <?php if (!empty($notifications)): ?>

                <div id="notif-list" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden divide-y divide-slate-100">
                    <?php foreach ($notifications as $notif): ?>
                    <?php
                        $meta = $notif['meta'] ?? get_supervisor_notif_meta($notif['type'] ?? '', $notif['title'] ?? '');
                        $notif_url = !empty($notif['link']) ? $notif['link'] : 'supervisor-dashboard.php';
                    ?>
                    <div class="notif-item flex items-start gap-4 px-6 py-4 <?= !$notif['is_read'] ? 'bg-blue-50/30' : '' ?> hover:bg-slate-50/80 transition-all duration-200 ease-in-out group relative cursor-pointer"
                         data-notif-id="<?= (int)$notif['id'] ?>"
                         data-category="<?= htmlspecialchars($meta['category']) ?>"
                         data-redirect-url="<?= htmlspecialchars($notif_url) ?>"
                         onclick="onNotificationItemClick(event, this)">

                        <!-- Category Icon -->
                        <div class="w-11 h-11 rounded-2xl <?= $meta['icon_bg'] ?> flex items-center justify-center text-base shrink-0 shadow-xs mt-0.5">
                            <?= $meta['icon_svg'] ?>
                        </div>

                        <!-- Content -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2.5 flex-wrap mb-1">
                                <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full border <?= $meta['badge_class'] ?>">
                                    <?= htmlspecialchars($meta['badge_label']) ?>
                                </span>
                                <p class="text-sm <?= !$notif['is_read'] ? 'font-semibold text-slate-900' : 'font-semibold text-slate-700' ?> leading-snug">
                                    <?= htmlspecialchars($notif['title']) ?>
                                </p>
                            </div>
                            <p class="text-sm text-slate-600 font-normal leading-relaxed"><?= htmlspecialchars($notif['message']) ?></p>
                            <p class="text-xs text-slate-400 font-medium mt-2 flex items-center gap-3" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>">
                                <span><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></span>
                                <a href="<?= htmlspecialchars($notif_url) ?>" class="font-semibold text-teal-600 hover:text-teal-800 opacity-0 group-hover:opacity-100 transition-all duration-200 ease-in-out inline-flex items-center gap-1" onclick="event.stopPropagation()">
                                    <span>View Details</span>
                                    <span>→</span>
                                </a>
                            </p>
                        </div>

                        <!-- Action buttons & status indicator -->
                        <div class="flex items-center gap-2 shrink-0 mt-1">
                            <?php if (!$notif['is_read']): ?>
                            <span class="unread-dot w-2.5 h-2.5 rounded-full bg-teal-500 shadow-xs" title="Unread notification"></span>
                            <?php endif; ?>

                            <button type="button" onclick="event.stopPropagation(); requestDeleteNotification(<?= (int)$notif['id'] ?>)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-rose-600 bg-rose-50 hover:bg-rose-100 ring-1 ring-rose-600/20 rounded-xl transition-all duration-200 ease-in-out opacity-0 group-hover:opacity-100 cursor-pointer shadow-xs" title="Delete notification">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                <span>Delete</span>
                            </button>

                            <div class="relative">
                                <button onclick="event.stopPropagation(); toggleNotifOptions(this)" class="w-8 h-8 rounded-xl hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 transition-all duration-200 ease-in-out opacity-0 group-hover:opacity-100 cursor-pointer" title="More options">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                                </button>
                                <div class="hidden absolute right-0 top-full mt-1 w-44 bg-white border border-slate-200/80 rounded-2xl shadow-lg z-50 py-1.5 notif-options-menu" onclick="event.stopPropagation();">
                                    <?php if (!$notif['is_read']): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                        <button type="submit" name="mark_notification_read" class="w-full text-left px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-all duration-200 ease-in-out flex items-center gap-2.5 cursor-pointer">
                                            <svg class="w-3.5 h-3.5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Mark as read
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <div class="px-4 py-2 text-xs font-medium text-slate-400 flex items-center gap-2.5">
                                        <svg class="w-3.5 h-3.5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Already read
                                    </div>
                                    <?php endif; ?>
                                    <div class="my-1 border-t border-slate-100"></div>
                                    <button type="button" onclick="requestDeleteNotification(<?= (int)$notif['id'] ?>)" class="w-full text-left px-4 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition-all duration-200 ease-in-out flex items-center gap-2.5 cursor-pointer">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="flex items-center justify-between mt-6">
                    <p class="text-xs font-semibold text-slate-500">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_notifs) ?> of <?= $total_notifs ?> notifications</p>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(build_query_url(['page' => $page - 1])) ?>" class="px-3.5 py-1.5 text-xs font-semibold bg-white border border-slate-200/80 rounded-xl text-slate-600 hover:bg-slate-50 transition-all duration-200 ease-in-out shadow-xs">← Prev</a>
                        <?php endif; ?>
                        <span class="px-3.5 py-1.5 text-xs font-semibold bg-slate-800 text-white rounded-xl shadow-xs"><?= $page ?> / <?= $total_pages ?></span>
                        <?php if ($page < $total_pages): ?>
                        <a href="?<?= http_build_query(build_query_url(['page' => $page + 1])) ?>" class="px-3.5 py-1.5 text-xs font-semibold bg-white border border-slate-200/80 rounded-xl text-slate-600 hover:bg-slate-50 transition-all duration-200 ease-in-out shadow-xs">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>

                <!-- Empty State -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-14 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mx-auto mb-4 text-slate-400">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    </div>
                    <p class="text-sm font-semibold text-slate-700">No <?= $type_filter ? 'matching ' : '' ?>notifications</p>
                    <p class="text-xs text-slate-400 font-medium mt-1">There are no <?= $type_filter ? strtolower($type_categories[$type_filter] ?? '') : '' ?> notifications at this time.</p>
                    <?php if ($type_filter): ?>
                    <div class="mt-4">
                        <a href="notifications.php" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-800 text-white text-xs font-semibold rounded-xl hover:bg-slate-900 transition-all duration-200 ease-in-out shadow-xs">
                            <span>View all notifications</span>
                            <span>→</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
