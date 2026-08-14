<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($pdo, $sup_id);

// ── Fetch unread count (bell badge) ────────────────────────────
$unread_notif_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->execute([$sup_id]);
$unread_notif_count = (int) $unread_notif_q->fetchColumn();

$recent_notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->execute([$sup_id]);
$recent_notifications = $recent_notifs_q->fetchAll();

// ── Type filter (supervisor-scoped) ────────────────────────────
$type_labels = [
    ''                        => 'All notifications',
    'new_report_submitted'    => 'New reports',
    'report_needs_review'     => 'Awaiting review',
    'student_behind_schedule' => 'Behind schedule',
    'internship_completed'    => 'Internships completed',
    'system_notice'           => 'System notices',
];
$type_filter = $_GET['type'] ?? '';
if (!array_key_exists($type_filter, $type_labels)) $type_filter = '';

// ── Pagination ─────────────────────────────────────────────────
$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$base_where  = "n.user_id = ?";
$base_params = [$sup_id];
if ($type_filter !== '') {
    $base_where  .= " AND n.type = ?";
    $base_params[] = $type_filter;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE {$base_where}");
$count_stmt->execute($base_params);
$total_notifs = (int) $count_stmt->fetchColumn();
$total_pages  = max(1, (int) ceil($total_notifs / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

$list_sql = "
    SELECT n.id, n.title, n.message, n.type, n.related_week, n.student_id, n.report_id,
           n.announcement_id, n.is_read, n.created_at,
           su.username AS student_username, su.profile_pic,
           sp.full_name AS student_name
    FROM notifications n
    LEFT JOIN users su ON su.id = n.student_id
    LEFT JOIN student_profiles sp ON sp.user_id = n.student_id
    WHERE {$base_where}
    ORDER BY n.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$list_stmt = $pdo->prepare($list_sql);
$list_stmt->execute($base_params);
$notifications = $list_stmt->fetchAll();

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
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Header -->
        <?php $pageTitle = 'Notifications'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ════ NOTIFICATIONS CONTENT ════ -->
        <main class="flex-1 overflow-y-auto p-8">

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Notifications</h2>
                    <p class="text-xs text-slate-500 mt-1">All updates about your students' reports, schedules and internships.</p>
                </div>
                <?php if ($unread_notif_count > 0): ?>
                <button onclick="markAllNotifsRead()" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-bold rounded-xl shadow-md shadow-blue-500/20 hover:from-blue-700 hover:to-indigo-700 transition-all duration-200 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Mark all as read
                </button>
                <?php endif; ?>
            </div>

            <!-- Filter tabs -->
            <div class="flex items-center gap-2 flex-wrap mb-5">
                <?php foreach ($type_labels as $type_key => $type_label): ?>
                <?php $is_active = $type_filter === $type_key; ?>
                <a href="?<?= http_build_query(build_query_url(['type' => $type_key, 'page' => ''])) ?>"
                   class="px-3 py-1.5 text-xs font-bold rounded-xl border transition-all duration-200 <?= $is_active ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
                    <?= htmlspecialchars($type_label) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($notifications)): ?>

            <div id="notif-list" class="bg-white/80 backdrop-blur-xl border border-slate-200/60 rounded-2xl shadow-sm overflow-hidden">
                <?php foreach ($notifications as $notif): ?>
                <?php
                    $meta        = notif_type_meta($notif['type']);
                    $notif_url   = notif_action_url($notif);
                    $student_pic = !empty($notif['student_id']) && !empty($notif['profile_pic']) ? '../uploads/avatars/' . htmlspecialchars($notif['profile_pic']) : null;
                    $student_show = $notif['student_name'] ?: ($notif['student_username'] ?: null);
                ?>
                <div class="flex items-start gap-4 px-6 py-4 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)">
                    <?php if ($student_pic): ?>
                    <img src="<?= $student_pic ?>" alt="" class="w-11 h-11 rounded-full object-cover ring-2 ring-white shadow-sm shrink-0">
                    <?php else: ?>
                    <div class="w-11 h-11 rounded-full <?= $meta['classes'] ?> flex items-center justify-center text-base shrink-0 ring-2 ring-white shadow-sm">
                        <span><?= $meta['icon'] ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-sm <?= !$notif['is_read'] ? 'font-bold text-slate-800' : 'font-semibold text-slate-700' ?> leading-snug"><?= htmlspecialchars($notif['title']) ?></p>
                            <?php if ($notif['related_week']): ?>
                            <span class="text-[11px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">Week <?= (int)$notif['related_week'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($student_show): ?>
                        <p class="text-xs font-semibold text-slate-500 mt-0.5">👤 <?= htmlspecialchars($student_show) ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-slate-500 mt-1 leading-relaxed"><?= htmlspecialchars($notif['message']) ?></p>
                        <p class="text-[11px] text-slate-400 mt-1.5 flex items-center gap-2" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>">
                            <span><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></span>
                            <a href="<?= htmlspecialchars($notif_url) ?>" class="font-bold text-blue-600 hover:text-blue-800 opacity-0 group-hover:opacity-100 transition" onclick="event.stopPropagation()">Open →</a>
                        </p>
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0 mt-1">
                        <?php if (!$notif['is_read']): ?>
                        <span class="unread-dot w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm"></span>
                        <?php endif; ?>
                        <button type="button" onclick="event.stopPropagation(); requestDeleteNotification(<?= (int)$notif['id'] ?>)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-red-500 bg-red-50 hover:bg-red-100 rounded-xl transition opacity-0 group-hover:opacity-100 cursor-pointer" title="Delete notification">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Delete
                        </button>
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
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="flex items-center justify-between mt-6">
                <p class="text-xs font-semibold text-slate-500">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_notifs) ?> of <?= $total_notifs ?> notifications</p>
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(build_query_url(['page' => $page - 1])) ?>" class="px-3 py-1.5 text-xs font-bold bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 transition">← Prev</a>
                    <?php endif; ?>
                    <span class="px-3 py-1.5 text-xs font-bold bg-slate-800 text-white rounded-xl"><?= $page ?> / <?= $total_pages ?></span>
                    <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(build_query_url(['page' => $page + 1])) ?>" class="px-3 py-1.5 text-xs font-bold bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 transition">Next →</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>

            <div class="bg-white/80 backdrop-blur-xl border border-slate-200/60 rounded-2xl shadow-sm p-14 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </div>
                <p class="text-sm font-bold text-slate-600">No <?= $type_filter ? 'matching ' : '' ?>notifications</p>
                <p class="text-xs text-slate-400 mt-1">When something happens with your students, it will show up here.</p>
            </div>

            <?php endif; ?>
        </main>
    </div>
</div>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
