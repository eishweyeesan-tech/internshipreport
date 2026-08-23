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

if ($topbar_sup_id > 0 && isset($db) && $db) {
    handle_notification_ajax_actions($db, $topbar_sup_id);

    if (!isset($unread_notif_count)) {
        $_unr = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($_unr) {
            $_unr->bind_param("i", $topbar_sup_id);
            $_unr->execute();
            $_res = $_unr->get_result();
            $_row = $_res ? $_res->fetch_row() : null;
            $unread_notif_count = (int)($_row[0] ?? 0);
            $_unr->close();
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
            $_rnr->close();
        } else {
            $recent_notifications = [];
        }
    }

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
            $_uinfo->close();
        }
    }
}
?>
<header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 shrink-0 shadow-xs relative z-[1050] print:hidden">
    <div class="flex items-center gap-3 flex-1 min-w-0">
        <button type="button" onclick="toggleSupervisorSidebar()" class="lg:hidden p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle Navigation">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-base font-bold text-slate-800 hidden sm:block"><?= htmlspecialchars($pageTitle ?? 'Supervisor Dashboard') ?></h1>

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

    <div class="flex items-center gap-4 shrink-0 h-full justify-end">
        <?php if (!empty($show_topbar_pending) && !empty($pending_reviews) && $pending_reviews > 0): ?>
        <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-full shadow-xs">
            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
            <span class="text-xs font-bold text-amber-700"><?= $pending_reviews ?> pending review<?= $pending_reviews !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>

        <!-- Notification Bell -->
        <div class="relative" id="notif-bell-wrapper">
            <button onclick="toggleNotifDropdown()" class="relative p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-50 rounded-full transition cursor-pointer" aria-label="Notifications">
                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if (($unread_notif_count ?? 0) > 0): ?>
                <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </button>

            <!-- Notification Dropdown -->
            <div id="notif-dropdown" class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                <div class="p-3 border-b border-slate-100 flex items-center justify-between bg-teal-50/60">
                    <h4 class="text-[11px] font-semibold tracking-wider text-slate-500 uppercase">Notifications</h4>
                    <?php if (($unread_notif_count ?? 0) > 0): ?>
                    <button onclick="markAllNotifsRead()" id="notif-mark-all-btn" class="text-[11px] font-semibold tracking-wider text-slate-500 hover:text-teal-900 hover:bg-teal-100/60 px-2 py-1 rounded transition cursor-pointer">Mark all read</button>
                    <?php endif; ?>
                </div>
                <div class="max-h-80 overflow-y-auto">
                    <?php if (!empty($recent_notifications)): ?>
                    <?php foreach ($recent_notifications as $notif): ?>
                    <?php 
                        $notif_url = !empty($notif['link']) ? $notif['link'] : (function_exists('notif_action_url') ? notif_action_url($notif, 'supervisor') : (function_exists('notif_redirect_url') ? notif_redirect_url($notif['type'] ?? 'info', $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null) : 'supervisor-reports.php'));
                        $meta = function_exists('get_supervisor_notif_meta')
                            ? get_supervisor_notif_meta($notif['type'] ?? '', $notif['title'] ?? '')
                            : [
                                'icon_bg' => 'bg-teal-100 text-teal-600',
                                'icon_svg' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>'
                            ];
                    ?>
                    <a href="<?= htmlspecialchars($notif_url) ?>" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)" class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-teal-50/50' : '' ?> hover:bg-teal-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer block no-underline">
                        <div class="w-8 h-8 rounded-lg <?= $meta['icon_bg'] ?> flex items-center justify-center text-xs shrink-0 shadow-xs mt-0.5">
                            <?= $meta['icon_svg'] ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-600' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                            <p class="text-[11px] text-slate-500 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                            <p class="text-[10px] text-slate-400 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                            <?php if (!$notif['is_read']): ?>
                            <span class="unread-dot w-2 h-2 rounded-full bg-teal-500 shadow-sm"></span>
                            <?php endif; ?>
                            <div class="relative">
                                <button onclick="event.stopPropagation(); toggleNotifOptions(this)" class="w-6 h-6 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 transition opacity-0 group-hover:opacity-100 cursor-pointer" title="More options">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                                </button>
                                <div class="hidden absolute right-0 top-full mt-1 w-40 bg-white border border-slate-200 rounded-xl shadow-lg z-50 py-1 notif-options-menu" onclick="event.stopPropagation();">
                                    <?php if (!$notif['is_read']): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                        <button type="submit" name="mark_notification_read" class="w-full text-left px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition flex items-center gap-2 cursor-pointer">
                                            <svg class="w-3 h-3 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Mark as read
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <div class="px-3 py-2 text-xs font-medium text-slate-400 flex items-center gap-2">
                                        <svg class="w-3 h-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Already read
                                    </div>
                                    <?php endif; ?>
                                    <div class="my-1 border-t border-slate-100"></div>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                        <button type="submit" name="delete_notification" class="w-full text-left px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 transition flex items-center gap-2 cursor-pointer">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <p class="text-xs font-semibold text-slate-400">No notifications yet</p>
                        <p class="text-[11px] text-slate-300 mt-1">You'll see updates here</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="border-t border-slate-100">
                    <a href="notifications.php" class="flex items-center justify-center gap-2 px-4 py-2.5 text-xs font-bold text-teal-600 hover:bg-teal-50 transition">View all notifications</a>
                </div>
            </div>
        </div>

        <!-- Supervisor Profile Dropdown Container -->
        <div class="relative shrink-0" id="profileDropdownContainer">
            <button
                type="button"
                onclick="toggleProfileDropdown(event)"
                id="profile-avatar-btn"
                class="flex items-center gap-2.5 p-1.5 pr-2 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-200/80 transition-all duration-200 cursor-pointer group focus:outline-none"
                aria-label="Supervisor menu"
            >
                <div class="relative shrink-0">
                    <?php if (!empty($topbar_sup_pic)): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($topbar_sup_pic) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover ring-2 ring-teal-500/20 shadow-xs">
                    <?php else: ?>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-teal-700 to-teal-500 flex items-center justify-center font-bold text-sm text-white shadow-xs">
                        <?= strtoupper(substr($topbar_sup_name, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-emerald-500 border-2 border-white rounded-full"></span>
                </div>
                <div class="text-left hidden sm:block">
                    <p class="font-bold text-xs text-slate-800 leading-tight group-hover:text-teal-700 transition-colors"><?= htmlspecialchars($topbar_sup_name) ?></p>
                    <p class="text-[11px] font-medium text-slate-400">Supervisor</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <!-- Profile Dropdown Menu -->
            <div
                id="profile-dropdown-menu"
                class="hidden absolute right-0 top-full mt-2 w-72 bg-white rounded-2xl shadow-xl shadow-slate-900/10 border border-slate-200/80 p-2 z-50 transition-all duration-200 ease-out"
            >
                <!-- User Info Card Header -->
                <div class="p-3 bg-gradient-to-br from-slate-50 to-teal-50/40 rounded-xl border border-slate-100 mb-1.5 flex items-center gap-3">
                    <div class="relative shrink-0">
                        <?php if (!empty($topbar_sup_pic)): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($topbar_sup_pic) ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover border border-slate-200/80 shadow-xs">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-teal-700 to-teal-500 flex items-center justify-center font-bold text-sm text-white shadow-xs">
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
                <div class="space-y-1">
                    <a href="profile.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold text-slate-700 hover:bg-teal-50/70 hover:text-teal-900 transition-all duration-150 group">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center group-hover:bg-teal-600 group-hover:text-white transition-colors duration-150 shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800 group-hover:text-teal-900 leading-tight">My Profile</p>
                                <p class="text-[10px] text-slate-400 font-normal mt-0.5">Account & profile settings</p>
                            </div>
                        </div>
                        <svg class="w-3.5 h-3.5 text-slate-300 group-hover:text-teal-600 group-hover:translate-x-0.5 transition-all shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>

                <div class="my-1.5 border-t border-slate-100"></div>

                <div>
                    <a href="../logout.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold text-rose-600 hover:bg-rose-50 hover:text-rose-700 transition-all duration-150 group">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center group-hover:bg-rose-600 group-hover:text-white transition-colors duration-150 shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            </span>
                            <span class="font-semibold text-rose-600 group-hover:text-rose-700">Sign Out</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>
<script src="../assets/js/notifications.js"></script>
<script>
if (typeof toggleProfileDropdown !== 'function') {
    window.toggleProfileDropdown = function(e) {
        if (e) e.stopPropagation();
        var dd = document.getElementById('profile-dropdown-menu') || document.getElementById('profileDropdownMenu');
        if (dd) {
            dd.classList.toggle('hidden');
        }
        var nd = document.getElementById('notif-dropdown');
        if (nd) {
            nd.style.opacity = '0';
            nd.style.visibility = 'hidden';
            nd.style.transform = 'translateY(-8px) scale(0.95)';
        }
    };
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu') || document.getElementById('profileDropdownMenu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.classList.contains('hidden') && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
}
</script>

