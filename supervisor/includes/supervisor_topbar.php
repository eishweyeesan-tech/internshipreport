<?php
/**
 * Shared Supervisor Top Navigation Bar Partial
 *
 * Variables expected:
 *   $pageTitle (string)              – Title text for header
 *   $sup_name (string)               – Supervisor full name / username
 *   $unread_notif_count (int)        – Unread notifications count
 *   $recent_notifications (array)    – Recent notification items
 *   $pending_reviews (int|null)      – Optional pending review count pill
 *   $search (string|null)            – Optional search string (shows search input if set)
 */
?>
<header class="h-16 bg-white/90 backdrop-blur-xl border-b border-teal-100 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
    <div class="flex items-center gap-4 flex-1 min-w-0">
        <h1 class="text-base font-bold text-slate-800 hidden sm:block"><?= htmlspecialchars($pageTitle ?? 'Supervisor Dashboard') ?></h1>

        <?php if (isset($search)): ?>
        <!-- Search Form -->
        <form method="GET" class="relative flex-1 max-w-xs hidden md:block ml-4">
            <?php if (!empty($filter_status)): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
            <?php if (!empty($filter_week)): ?><input type="hidden" name="week" value="<?= (int)$filter_week ?>"><?php endif; ?>
            <?php if (!empty($filter_company)): ?><input type="hidden" name="company" value="<?= htmlspecialchars($filter_company) ?>"><?php endif; ?>
            <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search students, companies…"
                class="w-full bg-slate-100/80 border border-transparent focus:border-teal-400 rounded-xl pl-9 pr-9 py-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:bg-white transition-all duration-200">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
            <?php if ($search): ?>
            <a href="?<?= http_build_query(build_query_url(['search' => ''])) ?>" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold px-1.5 py-0.5 rounded-full hover:bg-slate-200 transition" title="Clear search">✕</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>

    <div class="flex items-center gap-5 shrink-0">
        <?php if (isset($pending_reviews) && $pending_reviews !== null): ?>
        <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-teal-100 rounded-full shadow-xs">
            <span class="w-2 h-2 rounded-full bg-amber-500 <?= $pending_reviews > 0 ? 'animate-pulse' : '' ?>"></span>
            <span class="text-xs font-bold text-slate-600"><?= $pending_reviews ?> pending review<?= $pending_reviews !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>

        <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
            <!-- Notification Bell -->
            <div class="relative" id="notif-bell-wrapper">
                <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-teal-50 rounded-xl transition cursor-pointer">
                    <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <?php if (($unread_notif_count ?? 0) > 0): ?>
                    <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                    <?php endif; ?>
                </button>

                <!-- Notification Dropdown -->
                <div id="notif-dropdown" class="absolute right-0 top-full mt-1 w-[22rem] bg-white border border-slate-200 rounded-xl shadow-xl z-[1060] overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                    <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-gradient-to-br from-teal-50/80 to-white/60">
                        <h4 class="text-sm font-black text-slate-700">Notifications</h4>
                        <?php if (($unread_notif_count ?? 0) > 0): ?>
                        <button onclick="markAllNotifsRead()" id="notif-mark-all-btn" class="text-xs font-bold text-teal-600 hover:text-teal-800 transition cursor-pointer">Mark all read</button>
                        <?php endif; ?>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <?php if (!empty($recent_notifications)): ?>
                        <?php foreach ($recent_notifications as $notif): ?>
                        <?php $notif_url = function_exists('notif_redirect_url') ? notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null) : 'supervisor-reports.php'; ?>
                        <div class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-teal-50/50' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)">
                            <?php if ($notif['type'] === 'instructor_approved'): ?>
                            <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <?php elseif ($notif['type'] === 'instructor_rejected'): ?>
                            <div class="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </div>
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
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
                                <span class="unread-dot w-2.5 h-2.5 rounded-full bg-teal-500 shadow-sm"></span>
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
                                                <svg class="w-3.5 h-3.5 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
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
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                            <button type="submit" name="delete_notification" class="w-full text-left px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition flex items-center gap-2.5 cursor-pointer">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                Delete
                                            </button>
                                        </form>
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
                        <a href="notifications.php" class="flex items-center justify-center gap-2 px-4 py-3 text-xs font-bold text-teal-600 hover:bg-teal-50 transition">View all notifications</a>
                    </div>
                </div>
            </div>

            <!-- Profile Avatar -->
            <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                <?php if (!empty($_SESSION['profile_pic'])): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-teal-500/20">
                <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-teal-600 to-emerald-700 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-teal-500/20">
                    <?= strtoupper(substr($sup_name ?? 'S', 0, 1)) ?>
                </div>
                <?php endif; ?>
            </button>
            <div class="text-right">
                <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($sup_name ?? '') ?></p>
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
