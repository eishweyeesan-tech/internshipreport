<?php
// topbar.php – Shared admin top navigation bar
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/notification_actions.php';

$topbar_user_id = (int)($_SESSION['user_id'] ?? 0);
$topbar_pic = $_SESSION['profile_pic'] ?? '';
$topbar_email = $_SESSION['email'] ?? '';
$topbar_display_name = $admin_name ?? ($_SESSION['username'] ?? 'Admin');

if ($topbar_user_id > 0 && isset($db) && $db) {
    handle_notification_ajax_actions($db, $topbar_user_id);
    if (!isset($unread_notif_count)) {
        $_unr = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $_unr->bind_param("i", $topbar_user_id);
        $_unr->execute();
        $_res = $_unr->get_result();
        $_row = $_res ? $_res->fetch_row() : null;
        $unread_notif_count = (int)($_row[0] ?? 0);
    }
    if (!isset($recent_notifications)) {
        $_rnr = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
        $_rnr->bind_param("i", $topbar_user_id);
        $_rnr->execute();
        $_res = $_rnr->get_result();
        $recent_notifications = $_res ? $_res->fetch_all(MYSQLI_ASSOC) : [];
    }
    if (empty($topbar_pic) || empty($topbar_email)) {
        $_uinfo = $db->prepare("SELECT email, profile_pic FROM users WHERE id = ?");
        if ($_uinfo) {
            $_uinfo->bind_param("i", $topbar_user_id);
            $_uinfo->execute();
            $_res = $_uinfo->get_result();
            if ($_res && $row = $_res->fetch_assoc()) {
                if (empty($topbar_pic)) $topbar_pic = $row['profile_pic'] ?? '';
                if (empty($topbar_email)) $topbar_email = $row['email'] ?? '';
            }
        }
    }
}
?>
<header class="h-16 bg-white border-b border-slate-200/80 flex items-center justify-between px-4 lg:px-8 shrink-0 shadow-xs relative z-[1050] print:hidden">
    <div class="flex items-center gap-3">
        <button type="button" onclick="toggleAdminSidebar()" class="lg:hidden p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition-all duration-200 ease-in-out cursor-pointer" aria-label="Toggle sidebar">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-base font-bold text-slate-800 tracking-tight hidden sm:block"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
    </div>
    <div class="flex items-center gap-4 shrink-0 h-full justify-end">

        <!-- Notification Bell -->
        <div class="relative" id="notif-bell-wrapper">
            <button onclick="toggleNotifDropdown()" class="relative p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-50 rounded-full transition-all duration-200 ease-in-out cursor-pointer" aria-label="Notifications">
                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if (($unread_notif_count ?? 0) > 0): ?>
                <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </button>
            <!-- Notification Dropdown -->
            <div id="notif-dropdown" class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200/80 rounded-2xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                <div class="p-3.5 border-b border-slate-100 flex items-center justify-between bg-teal-50/60">
                    <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Notifications</h4>
                    <?php if (($unread_notif_count ?? 0) > 0): ?>
                    <button onclick="markAllNotifsRead()" id="notif-mark-all-btn" class="text-xs font-semibold text-teal-700 hover:text-teal-900 hover:bg-teal-100/60 px-2.5 py-1 rounded-lg transition-all duration-200 ease-in-out cursor-pointer">Mark all read</button>
                    <?php endif; ?>
                </div>
                <div class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                    <?php if (!empty($recent_notifications)): ?>
                    <?php foreach ($recent_notifications as $notif): ?>
                    <?php
                        $notif_url = !empty($notif['link']) ? $notif['link'] : notif_action_url($notif, 'admin');
                        $meta = notif_type_meta($notif['type'] ?? 'info');
                    ?>
                    <a href="<?= htmlspecialchars($notif_url) ?>" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)" class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-teal-50/40' : '' ?> hover:bg-teal-50 transition-all duration-150 group cursor-pointer block no-underline">
                        <?php if (!$notif['is_read']): ?>
                        <span class="w-2 h-2 bg-teal-500 rounded-full flex-shrink-0 mt-2"></span>
                        <?php else: ?>
                        <span class="w-2 h-2 flex-shrink-0 mt-2"></span>
                        <?php endif; ?>
                        <div class="w-8 h-8 rounded-xl <?= $meta['classes'] ?> flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-xs">
                            <?= $meta['icon'] ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-600' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                            <p class="text-xs text-slate-500 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                            <p class="text-[11px] text-slate-400 font-medium mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center mx-auto mb-3 text-slate-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <p class="text-xs font-semibold text-slate-400">No notifications yet</p>
                        <p class="text-[11px] text-slate-300 mt-0.5">You'll see updates here</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Profile Dropdown -->
        <div class="relative shrink-0" id="profileDropdownContainer">
            <button
                type="button"
                onclick="toggleProfileDropdown(event)"
                id="profile-avatar-btn"
                class="flex items-center gap-2.5 p-1.5 pr-2.5 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-200/80 transition-all duration-200 ease-in-out cursor-pointer group focus:outline-none"
                aria-label="User menu"
            >
                <div class="relative shrink-0">
                    <?php if (!empty($topbar_pic)): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($topbar_pic) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover ring-2 ring-teal-500/20 shadow-xs">
                    <?php else: ?>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-teal-700 to-teal-500 flex items-center justify-center font-bold text-sm text-white shadow-xs">
                        <?= strtoupper(substr($topbar_display_name, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-emerald-500 border-2 border-white rounded-full"></span>
                </div>
                <div class="text-left hidden sm:block">
                    <p class="font-bold text-xs text-slate-800 leading-tight group-hover:text-teal-700 transition-colors"><?= htmlspecialchars($topbar_display_name) ?></p>
                    <p class="text-[11px] font-medium text-slate-400">Administrator</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <!-- Profile Dropdown Menu -->
            <div
                id="profileDropdownMenu"
                class="hidden absolute right-0 top-full mt-2 w-72 bg-white rounded-2xl shadow-xl shadow-slate-900/10 border border-slate-200/80 p-2.5 z-50 transition-all duration-200 ease-out"
            >
                <!-- User Info Card Header -->
                <div class="p-3 bg-gradient-to-br from-slate-50 to-teal-50/40 rounded-xl border border-slate-100 mb-1.5 flex items-center gap-3">
                    <div class="relative shrink-0">
                        <?php if (!empty($topbar_pic)): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($topbar_pic) ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover border border-slate-200/80 shadow-xs">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-teal-700 to-teal-500 flex items-center justify-center font-bold text-sm text-white shadow-xs">
                            <?= strtoupper(substr($topbar_display_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <p class="font-bold text-xs text-slate-900 truncate"><?= htmlspecialchars($topbar_display_name) ?></p>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-teal-100 text-teal-800">Admin</span>
                        </div>
                        <p class="text-[11px] text-slate-500 truncate mt-0.5"><?= htmlspecialchars($topbar_email ?: 'Administrator') ?></p>
                    </div>
                </div>

                <!-- Menu Items -->
                <div class="space-y-1">
                    <a href="admin-profile.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold text-slate-700 hover:bg-teal-50/70 hover:text-teal-900 transition-all duration-150 group">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center group-hover:bg-teal-600 group-hover:text-white transition-colors duration-150 shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800 group-hover:text-teal-900 leading-tight">My Profile</p>
                                <p class="text-[10px] text-slate-400 font-normal mt-0.5">Account & system settings</p>
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

<script src="../assets/js/main.js"></script>
<script src="../assets/js/notifications.js"></script>
<script>
if (typeof toggleProfileDropdown !== 'function') {
    window.toggleProfileDropdown = function(e) {
        if (e) e.stopPropagation();
        var dd = document.getElementById('profileDropdownMenu') || document.getElementById('profile-dropdown-menu');
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
        var dd = document.getElementById('profileDropdownMenu') || document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.classList.contains('hidden') && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
}
</script>

