<?php
/**
 * Shared Supervisor Top Navigation Bar Partial
 *
 * Variables expected:
 *   $pageTitle (string)              – Title text for header
 *   $sup_name (string)               – Supervisor full name / username
 *   $unread_notif_count (int)        – Unread notifications count
 *   $recent_notifications (array)    – Recent notification items
 *   $show_topbar_pending (bool)      – Whether to show pending reviews badge (default: false)
 *   $show_topbar_search (bool)       – Whether to show search input (default: false)
 */

require_once __DIR__ . '/../../includes/ui_helpers.php';
require_once __DIR__ . '/../../config/notify.php';
require_once __DIR__ . '/../../includes/notification_actions.php';

$topbar_sup_id = (int)($_SESSION['user_id'] ?? 0);
$topbar_sup_pic = $_SESSION['profile_pic'] ?? '';
$topbar_sup_email = $_SESSION['email'] ?? '';
$topbar_sup_raw_name = $sup_name ?? ($_SESSION['username'] ?? 'Supervisor');
$topbar_sup_name = function_exists('format_supervisor_name') ? format_supervisor_name($topbar_sup_raw_name) : $topbar_sup_raw_name;

if (!isset($db) || !$db) {
    $db = $mysqli ?? $conn ?? null;
}

if ($topbar_sup_id > 0 && isset($db) && $db) {
    handle_notification_ajax_actions($db, $topbar_sup_id);
    if (empty($topbar_sup_pic) || empty($topbar_sup_email)) {
        $_uinfo = $db->prepare("SELECT email, profile_pic FROM users WHERE id = ?");
        if ($_uinfo) {
            $_uinfo->bind_param("i", $topbar_sup_id);
            $_uinfo->execute();
            $_res = $_uinfo->get_result();
            if ($_res && $row = $_res->fetch_assoc()) {
                if (empty($topbar_sup_pic)) $topbar_sup_pic = $row['profile_pic'] ?? '';
                if (empty($topbar_sup_email)) $topbar_sup_email = $row['email'] ?? '';
            }
        }
    }

    if (!isset($unread_notif_count)) {
        $_unr = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($_unr) {
            $_unr->bind_param("i", $topbar_sup_id);
            $_unr->execute();
            $_res = $_unr->get_result();
            $_row = $_res ? $_res->fetch_row() : null;
            $unread_notif_count = (int)($_row[0] ?? 0);
        } else {
            $unread_notif_count = 0;
        }
    }
    if (!isset($recent_notifications)) {
        $_rnr = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
        if ($_rnr) {
            $_rnr->bind_param("i", $topbar_sup_id);
            $_rnr->execute();
            $_res = $_rnr->get_result();
            $recent_notifications = $_res ? $_res->fetch_all(MYSQLI_ASSOC) : [];
        } else {
            $recent_notifications = [];
        }
    }
}
?>
<header class="h-16 bg-white border-b border-teal-100 flex items-center justify-between px-4 lg:px-6 shrink-0 relative z-50 print:hidden">
    <div class="flex items-center gap-3 flex-1 min-w-0">
        <button type="button" onclick="toggleSupervisorSidebar()" class="lg:hidden p-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle Navigation">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <span class="text-lg font-bold text-slate-800 hidden sm:block"><?= htmlspecialchars($pageTitle ?? 'Supervisor Dashboard') ?></span>

        <?php if (!empty($show_topbar_search) && isset($search)): ?>
        <!-- Search Form -->
        <form method="GET" class="relative flex-1 max-w-xs hidden md:block ml-4">
            <?php if (!empty($filter_status)): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
            <?php if (!empty($filter_week)): ?><input type="hidden" name="week" value="<?= (int)$filter_week ?>"><?php endif; ?>
            <?php if (!empty($filter_company)): ?><input type="hidden" name="company" value="<?= htmlspecialchars($filter_company) ?>"><?php endif; ?>
            <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search students, companies…"
                class="w-full bg-slate-100/80 border border-transparent focus:border-teal-500 rounded-xl pl-9 pr-9 py-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:bg-white transition-all duration-200">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
            <?php if ($search): ?>
            <a href="?<?= http_build_query(build_query_url(['search' => ''])) ?>" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold px-1.5 py-0.5 rounded-full hover:bg-slate-200 transition" title="Clear search">✕</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>

    <div class="flex items-center gap-3 shrink-0 h-full justify-end">
        <?php if (!empty($show_topbar_pending) && isset($pending_reviews) && $pending_reviews !== null): ?>
        <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-full shadow-xs">
            <span class="w-2 h-2 rounded-full bg-amber-500 <?= $pending_reviews > 0 ? 'animate-pulse' : '' ?>"></span>
            <span class="text-xs font-bold text-amber-700"><?= $pending_reviews ?> pending review<?= $pending_reviews !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>

        <!-- Notification Bell – Facebook Style (Identical to Student Topbar) -->
        <div class="relative" id="notif-bell-wrapper">
            <button onclick="toggleNotifDropdown(event)" class="relative p-2 hover:bg-teal-50 rounded-full transition cursor-pointer" id="notif-bell-btn" aria-label="Notifications">
                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if (($unread_notif_count ?? 0) > 0): ?>
                <span id="notif-badge" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white shadow-sm"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php else: ?>
                <span id="notif-badge" class="hidden absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-500 text-white text-[10px] font-bold rounded-full items-center justify-center border-2 border-white shadow-sm">0</span>
                <?php endif; ?>
            </button>

            <!-- Notification Dropdown Menu -->
            <div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-[360px] bg-white rounded-xl shadow-2xl border border-gray-200 z-50 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gradient-to-br from-teal-50/80 to-white/60">
                    <h3 class="text-[17px] font-bold text-gray-900">Notifications</h3>
                    <button onclick="markAllSupervisorNotificationsRead()" id="notif-mark-all-btn" class="text-[13px] font-semibold text-teal-600 hover:text-teal-700 hover:bg-teal-50 px-2 py-1 rounded-lg transition cursor-pointer <?= ($unread_notif_count ?? 0) === 0 ? 'hidden' : '' ?>">Mark all as read</button>
                </div>
                <div class="max-h-[420px] overflow-y-auto" id="notif-list">
                    <?php if (!empty($recent_notifications)): ?>
                        <?php
                        $_today = (new DateTime())->format('Y-m-d');
                        $_section = '';
                        foreach ($recent_notifications as $notif):
                            $_ndate = (new DateTime($notif['created_at']))->format('Y-m-d');
                            if ($_ndate === $_today && $_section !== 'today') {
                                $_section = 'today';
                                echo '<div class="px-4 pt-3 pb-1"><p class="text-[13px] font-bold text-gray-900">New</p></div>';
                            } elseif ($_ndate !== $_today && $_section !== 'older') {
                                $_section = 'older';
                                echo '<div class="px-4 pt-3 pb-1 border-t border-gray-100"><p class="text-[13px] font-bold text-gray-900">Earlier</p></div>';
                            }
                            $notif_url = function_exists('notif_redirect_url') ? notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null) : 'supervisor-reports.php';
                        ?>
                        <a href="<?= htmlspecialchars($notif_url) ?>" class="flex items-start gap-3 px-4 py-3 hover:bg-teal-50 transition-colors duration-100 cursor-pointer group relative no-underline <?= !$notif['is_read'] ? 'bg-teal-50/40' : '' ?>" onclick="return onSupervisorNotifClick(event, this)" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>">
                            <?php if (!$notif['is_read']): ?>
                            <span class="unread-dot w-2.5 h-2.5 bg-teal-500 rounded-full flex-shrink-0 mt-2 shadow-sm"></span>
                            <?php else: ?>
                            <span class="w-2.5 flex-shrink-0 mt-2"></span>
                            <?php endif; ?>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm shrink-0 shadow-sm <?= ($notif['type'] ?? '') === 'instructor_approved' ? 'bg-emerald-100 text-emerald-600' : (($notif['type'] ?? '') === 'instructor_rejected' ? 'bg-red-100 text-red-600' : 'bg-teal-100 text-teal-600') ?>">
                                <?php if (($notif['type'] ?? '') === 'instructor_approved'): ?>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <?php elseif (($notif['type'] ?? '') === 'instructor_rejected'): ?>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] leading-snug <?= !$notif['is_read'] ? 'font-semibold text-gray-900' : 'text-gray-600' ?>"><?= htmlspecialchars($notif['title']) ?></p>
                                <p class="text-[12px] text-gray-400 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                                <p class="text-[11px] mt-1 <?= !$notif['is_read'] ? 'text-teal-600 font-medium' : 'text-gray-400' ?>" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="py-12 px-6 text-center">
                        <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-gray-500">No notifications</p>
                        <p class="text-xs text-gray-400 mt-1">You're all caught up!</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="border-t border-gray-100">
                    <a href="notifications.php" class="block text-center py-3 text-[13px] font-semibold text-teal-600 hover:bg-teal-50 transition-colors">See all</a>
                </div>
            </div>
        </div>

        <!-- Profile Dropdown Container -->
        <div class="relative shrink-0" id="profile-dropdown-wrapper">
            <button
                type="button"
                onclick="toggleProfileDropdown(event)"
                id="profile-avatar-btn"
                class="flex items-center gap-2.5 p-1.5 hover:bg-teal-50 border border-transparent hover:border-teal-100 rounded-xl transition-all cursor-pointer group"
                aria-label="Supervisor menu"
            >
                <?php if (!empty($topbar_sup_pic)): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($topbar_sup_pic) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover border border-teal-200 shadow-sm shrink-0">
                <?php else: ?>
                <div class="w-9 h-9 rounded-xl bg-teal-700 flex items-center justify-center font-bold text-sm text-white shadow-sm shrink-0">
                    <?= strtoupper(substr($topbar_sup_name, 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div class="text-left hidden sm:block">
                    <p class="font-semibold text-sm text-slate-800 leading-tight group-hover:text-teal-800 transition-colors"><?= htmlspecialchars($topbar_sup_name) ?></p>
                    <p class="text-xs font-medium text-teal-700 capitalize">Supervisor</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>

            <!-- Profile Dropdown Menu -->
            <div
                id="profile-dropdown-menu"
                class="hidden absolute right-0 top-full mt-2 w-64 bg-white rounded-2xl shadow-xl border border-teal-100 p-2 z-50 transition-all duration-200 ease-out divide-y divide-slate-100"
            >
                <!-- User Info Header -->
                <div class="p-3 bg-gradient-to-br from-slate-50 to-teal-50/50 rounded-xl border border-teal-100/60 mb-1.5 flex items-center gap-3">
                    <div class="relative shrink-0">
                        <?php if (!empty($topbar_sup_pic)): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($topbar_sup_pic) ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover border border-teal-200 shadow-xs">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-teal-700 flex items-center justify-center font-bold text-sm text-white shadow-xs">
                            <?= strtoupper(substr($topbar_sup_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <p class="font-bold text-xs text-slate-900 truncate"><?= htmlspecialchars($topbar_sup_name) ?></p>
                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-teal-100 text-teal-800">Supervisor</span>
                        </div>
                        <p class="text-[11px] text-slate-500 truncate mt-0.5"><?= htmlspecialchars($topbar_sup_email ?: 'Supervisor') ?></p>
                    </div>
                </div>

                <!-- Menu Items -->
                <div class="space-y-0.5 py-1">
                    <a href="profile.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                        <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span>My Profile</span>
                    </a>
                    <a href="profile.php#password-section" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                        <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        <span>Change Password</span>
                    </a>
                </div>

                <div class="pt-1">
                    <a href="../logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                        <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
function toggleSupervisorSidebar() {
    var sb = document.getElementById('supervisorSidebar');
    var bd = document.getElementById('supervisorSidebarBackdrop');
    if (!sb) return;
    if (sb.classList.contains('-translate-x-full')) {
        sb.classList.remove('-translate-x-full');
        if (bd) bd.classList.remove('hidden');
    } else {
        sb.classList.add('-translate-x-full');
        if (bd) bd.classList.add('hidden');
    }
}

function toggleProfileDropdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    var menu = document.getElementById('profile-dropdown-menu');
    if (!menu) return;
    var notifMenu = document.getElementById('notif-dropdown');
    if (notifMenu) {
        notifMenu.classList.add('hidden');
        notifMenu.style.opacity = '0';
        notifMenu.style.visibility = 'hidden';
    }
    menu.classList.toggle('hidden');
}

function toggleNotifDropdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    var notifMenu = document.getElementById('notif-dropdown');
    if (!notifMenu) return;
    var profileMenu = document.getElementById('profile-dropdown-menu');
    if (profileMenu) profileMenu.classList.add('hidden');

    var isHidden = notifMenu.classList.contains('hidden') || notifMenu.style.visibility === 'hidden' || notifMenu.style.opacity === '0';
    if (!isHidden) {
        notifMenu.style.opacity    = '0';
        notifMenu.style.visibility = 'hidden';
        notifMenu.style.transform  = 'translateY(-8px) scale(0.95)';
        notifMenu.classList.add('hidden');
    } else {
        notifMenu.classList.remove('hidden');
        notifMenu.style.opacity    = '1';
        notifMenu.style.visibility = 'visible';
        notifMenu.style.transform  = 'translateY(0) scale(1)';
    }
}

document.addEventListener('click', function(e) {
    var profileWrapper = document.getElementById('profile-dropdown-wrapper') || document.getElementById('profileDropdownContainer');
    var profileMenu = document.getElementById('profile-dropdown-menu');
    if (profileMenu && !profileMenu.classList.contains('hidden')) {
        if (profileWrapper && !profileWrapper.contains(e.target)) {
            profileMenu.classList.add('hidden');
        }
    }

    var notifWrapper = document.getElementById('notif-bell-wrapper');
    var notifMenu = document.getElementById('notif-dropdown');
    if (notifMenu && (!notifMenu.classList.contains('hidden') || notifMenu.style.visibility === 'visible')) {
        if (notifWrapper && !notifWrapper.contains(e.target)) {
            notifMenu.style.opacity = '0';
            notifMenu.style.visibility = 'hidden';
            notifMenu.style.transform = 'translateY(-8px) scale(0.95)';
            notifMenu.classList.add('hidden');
        }
    }
});

function markAllSupervisorNotificationsRead() {
    if (typeof markAllNotificationsRead === 'function') {
        markAllNotificationsRead();
    } else if (typeof markAllNotifsRead === 'function') {
        markAllNotifsRead();
    }
}

function onSupervisorNotifClick(e, el) {
    if (typeof onNotificationItemClick === 'function') {
        return onNotificationItemClick(e, el);
    }
}
</script>
