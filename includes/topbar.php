<?php
// topbar.php – Shared admin top navigation bar
// Requires: $pageTitle (string), $admin_name (string), $admin_id (int),
//           $unread_notif_count (int), $recent_notifications (array)
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
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if (($unread_notif_count ?? 0) > 0): ?>
                <span class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </button>
            <!-- Notification Dropdown -->
            <div id="notif-dropdown" class="absolute right-0 top-full mt-2 w-80 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                <div class="p-3 border-b border-slate-100 flex items-center justify-between">
                    <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">Notifications</h4>
                    <?php if (($unread_notif_count ?? 0) > 0): ?>
                    <form method="POST" class="inline">
                        <button type="submit" name="mark_all_notifications_read" class="text-label font-bold text-indigo-600 hover:text-indigo-800 transition cursor-pointer">Mark all read</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="max-h-80 overflow-y-auto">
                    <?php if (!empty($recent_notifications)): ?>
                    <?php foreach ($recent_notifications as $notif): ?>
                    <a href="<?= htmlspecialchars($notif['redirect_url'] ?? '#') ?>" class="flex items-start gap-2.5 px-3 py-3 <?= !$notif['is_read'] ? 'bg-indigo-50/40' : 'hover:bg-slate-50' ?> transition-all duration-150 border-b border-slate-100 last:border-0 group cursor-pointer">
                        <?php if (!$notif['is_read']): ?>
                        <span class="w-2 h-2 bg-indigo-500 rounded-full flex-shrink-0 mt-2"></span>
                        <?php else: ?>
                        <span class="w-2 h-2 flex-shrink-0 mt-2"></span>
                        <?php endif; ?>
                        <div class="w-8 h-8 rounded-full <?= ($notif['type'] ?? '') === 'instructor_approved' ? 'bg-emerald-100 text-emerald-600' : (($notif['type'] ?? '') === 'instructor_rejected' ? 'bg-red-100 text-red-600' : (($notif['type'] ?? '') === 'report_submitted' ? 'bg-amber-100 text-amber-600' : 'bg-blue-100 text-blue-600')) ?> flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-sm">
                            <?= ($notif['type'] ?? '') === 'instructor_approved' ? '✓' : (($notif['type'] ?? '') === 'instructor_rejected' ? '✕' : (($notif['type'] ?? '') === 'report_submitted' ? '📄' : 'ℹ')) ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-caption font-bold <?= !$notif['is_read'] ? 'text-slate-800' : 'text-slate-500' ?> leading-tight"><?= htmlspecialchars($notif['title']) ?></p>
                            <p class="text-label text-slate-400 mt-0.5 leading-snug" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($notif['message']) ?></p>
                            <p class="text-caption text-slate-300 mt-1" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                        </div>
                        <?php if (!$notif['is_read']): ?>
                        <form method="POST" class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity duration-150" onclick="event.stopPropagation();">
                            <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                            <button type="submit" name="mark_notification_read" class="w-6 h-6 rounded-full bg-slate-100 hover:bg-indigo-100 text-slate-400 hover:text-indigo-600 flex items-center justify-center text-label font-bold transition cursor-pointer shadow-sm" title="Mark as read">✓</button>
                        </form>
                        <?php endif; ?>
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
                class="flex items-center gap-3 p-1.5 hover:bg-slate-50 border border-transparent hover:border-slate-100 rounded-xl transition-all cursor-pointer group"
            >
                <?php if (!empty($_SESSION['profile_pic'])): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-9 h-9 rounded-full object-cover border-2 border-white shadow-sm">
                <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center text-sm font-bold shadow-sm">
                    <?= strtoupper(substr($admin_name ?? 'A', 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div class="text-left hidden lg:block">
                    <p class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($admin_name ?? 'Admin') ?></p>
                    <p class="text-caption text-slate-400 font-medium">Administrator</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 shrink-0 transition-colors" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <!-- Profile Dropdown Menu -->
            <div
                id="profileDropdownMenu"
                class="hidden absolute right-0 top-full mt-2 w-52 bg-white border border-slate-200 rounded-xl shadow-[0_4px_12px_rgba(0,0,0,0.15)] z-[1050] py-1 overflow-hidden"
            >
                <a href="admin-profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                    <span>👤</span> My Profile
                </a>
                <a href="admin-dashboard.php" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                    <span>📊</span> Dashboard
                </a>
                <div class="my-1 border-t border-slate-100"></div>
                <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-red-500 hover:bg-red-50 transition">
                    <span>🚪</span> Logout
                </a>
            </div>
        </div>

    </div>
</header>

<script src="../assets/js/main.js"></script>
<script src="../assets/js/notifications.js"></script>
