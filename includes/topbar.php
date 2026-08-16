<?php
// topbar.php – Shared admin top navigation bar
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/notification_actions.php';

$topbar_user_id = (int)($_SESSION['user_id'] ?? 0);
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
}
?>
<header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 shrink-0">
    <div class="flex items-center gap-3">
        <button type="button" onclick="toggleAdminSidebar()" class="lg:hidden p-2 text-slate-500 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle sidebar">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-base font-bold text-slate-800"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
    </div>
    <div class="flex items-center gap-4 shrink-0 h-full justify-end">

        <!-- Notification Bell -->
        <div class="relative" id="notif-bell-wrapper">
            <button onclick="toggleNotifDropdown()" class="relative p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-50 rounded-full transition cursor-pointer">
                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if (($unread_notif_count ?? 0) > 0): ?>
                <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </button>
            <!-- Notification Dropdown -->
            <div id="notif-dropdown" class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                <div class="p-3 border-b border-slate-100 flex items-center justify-between bg-teal-50/60">
                    <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">Notifications</h4>
                    <?php if (($unread_notif_count ?? 0) > 0): ?>
                    <button onclick="markAllNotifsRead()" id="notif-mark-all-btn" class="text-label font-bold text-teal-700 hover:text-teal-900 hover:bg-teal-100/60 px-2 py-1 rounded transition cursor-pointer">Mark all read</button>
                    <?php endif; ?>
                </div>
                <div class="max-h-80 overflow-y-auto">
                    <?php if (!empty($recent_notifications)): ?>
                    <?php foreach ($recent_notifications as $notif): ?>
                    <?php
                        $notif_url = notif_action_url($notif, 'admin');
                        $meta = notif_type_meta($notif['type'] ?? 'info');
                    ?>
                    <a href="<?= htmlspecialchars($notif_url) ?>" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)" class="flex items-start gap-2.5 px-3 py-3 <?= !$notif['is_read'] ? 'bg-teal-50/40' : '' ?> hover:bg-teal-50 transition-all duration-150 border-b border-slate-100 last:border-0 group cursor-pointer block no-underline">
                        <?php if (!$notif['is_read']): ?>
                        <span class="w-2 h-2 bg-teal-500 rounded-full flex-shrink-0 mt-2"></span>
                        <?php else: ?>
                        <span class="w-2 h-2 flex-shrink-0 mt-2"></span>
                        <?php endif; ?>
                        <div class="w-8 h-8 rounded-full <?= $meta['classes'] ?> flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-sm">
                            <?= $meta['icon'] ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-caption font-bold <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-500' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                            <p class="text-label text-slate-400 mt-0.5 leading-snug" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($notif['message']) ?></p>
                            <p class="text-caption text-slate-300 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <p class="text-xs font-semibold text-slate-400">No notifications yet</p>
                        <p class="text-label text-slate-300 mt-1">You'll see updates here</p>
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
                class="flex items-center gap-2.5 p-1.5 hover:bg-teal-50 border border-transparent hover:border-teal-100 rounded-xl transition-all cursor-pointer group"
            >
                <?php if (!empty($_SESSION['profile_pic'])): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover border border-teal-200 shadow-sm">
                <?php else: ?>
                <div class="w-9 h-9 rounded-xl bg-teal-700 flex items-center justify-center font-bold text-sm text-white shadow-sm">
                    <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div class="text-left hidden sm:block">
                    <p class="font-semibold text-sm text-slate-800 leading-tight"><?= htmlspecialchars($admin_name ?? 'Admin') ?></p>
                    <p class="text-xs font-medium text-teal-700 capitalize">Administrator</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <!-- Profile Dropdown Menu -->
            <div
                id="profileDropdownMenu"
                class="hidden absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-lg border border-teal-100 py-1.5 z-50 divide-y divide-slate-100"
            >
                <a href="admin-profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                    <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> My Profile
                </a>
                <a href="admin-profile.php#security-section" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                    <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> Change Password
                </a>
                <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                    <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
                </a>
            </div>
        </div>

    </div>
</header>

<script src="../assets/js/main.js"></script>
<script src="../assets/js/notifications.js"></script>
