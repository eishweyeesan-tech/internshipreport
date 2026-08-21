<?php
/**
 * Shared Student Top Bar
 * 
 * Required variables (set before including):
 *   $pageTitle (string)              – Page title text
 *   $student_name (string)           – Student full name
 * 
 * Optional variables:
 *   $student_roll (string|null)      – Student roll number
 *   $profile_pic (string|null)       – Profile picture filename
 *   $show_back_link (bool)           – Show "Back to Dashboard" link
 *   $unread_notif_count (int)        – Unread notification count (auto-fetched if not set)
 *   $recent_notifications (array)    – Notification rows (auto-fetched if not set)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/notification_actions.php';

$student_user_id = (int)($_SESSION['user_id'] ?? 0);

$db = $mysqli ?? $conn ?? null;

if ($student_user_id > 0 && $db) {
    handle_notification_ajax_actions($db, $student_user_id);
}

if (!isset($unread_notif_count) || !isset($recent_notifications)) {
    if ($student_user_id > 0 && $db) {
        if (!isset($unread_notif_count)) {
            $_unr = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $_unr->bind_param("i", $student_user_id);
            $_unr->execute();
            $_res = $_unr->get_result();
            $_row = $_res ? $_res->fetch_row() : null;
            $unread_notif_count = (int)($_row[0] ?? 0);
        }
        if (!isset($recent_notifications)) {
            $_rnr = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
            $_rnr->bind_param("i", $student_user_id);
            $_rnr->execute();
            $_res = $_rnr->get_result();
            $recent_notifications = $_res ? $_res->fetch_all(MYSQLI_ASSOC) : [];
        }
    } else {
        if (!isset($unread_notif_count)) $unread_notif_count = 0;
        if (!isset($recent_notifications)) $recent_notifications = [];
    }
}

$student_academic_year = '';
if ($student_user_id > 0 && $db) {
    $student_year_stmt = $db->prepare("SELECT u.academic_year FROM users u WHERE u.id = ? LIMIT 1");
    $student_year_stmt->bind_param("i", $student_user_id);
    $student_year_stmt->execute();
    $_res = $student_year_stmt->get_result();
    $student_year_row = $_res ? $_res->fetch_assoc() : null;
    if ($student_year_row) {
        $student_academic_year = trim((string) ($student_year_row['academic_year'] ?? ''));
    }
    $student_year_stmt->close();
}

if (!function_exists('student_notif_url')) {
    function student_notif_url($type, $related_week, $announcement_id = null) {
        if ($announcement_id) {
            return '#';
        }
        $base = 'student-dashboard.php';
        if (in_array($type, ['instructor_approved', 'instructor_rejected', 'supervisor_approved'], true) && $related_week) {
            return $base . '?week=' . (int)$related_week;
        }
        return $base;
    }
}
?>
<header class="h-16 bg-white border-b border-teal-100 flex items-center justify-between px-4 lg:px-6 shrink-0 relative z-50 print:hidden">
    <div class="flex items-center gap-3">
        <button type="button" onclick="toggleStudentSidebar()" class="lg:hidden p-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle Navigation">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <span class="text-lg font-bold text-slate-800"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></span>
        <?php if (!empty($student_academic_year)): ?>
        <span class="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700">
            Academic Year <?= htmlspecialchars($student_academic_year) ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-2 shrink-0">

        <!-- Notification Bell – Facebook Style -->
        <div class="relative" id="notif-bell-wrapper">
            <button onclick="toggleNotifDropdown(event)" class="relative p-2 hover:bg-teal-50 rounded-full transition cursor-pointer" id="notif-bell-btn">
                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if ($unread_notif_count > 0): ?>
                <span id="notif-badge" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white shadow-sm"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php else: ?>
                <span id="notif-badge" class="hidden absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-500 text-white text-[10px] font-bold rounded-full items-center justify-center border-2 border-white shadow-sm">0</span>
                <?php endif; ?>
            </button>
            <div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-[360px] bg-white rounded-xl shadow-2xl border border-gray-200 z-50 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gradient-to-br from-teal-50/80 to-white/60">
                    <h3 class="text-[17px] font-bold text-gray-900">Notifications</h3>
                    <button onclick="markAllNotificationsRead()" id="notif-mark-all-btn" class="text-[13px] font-semibold text-teal-600 hover:text-teal-700 hover:bg-teal-50 px-2 py-1 rounded-lg transition cursor-pointer <?= $unread_notif_count === 0 ? 'hidden' : '' ?>">Mark all as read</button>
                </div>
                <div class="max-h-[420px] overflow-y-auto" id="notif-list">
                    <?php if (!empty($recent_notifications)): ?>
                        <?php
                        $_today = (new DateTime())->format('Y-m-d');
                        $_section = '';
                        foreach ($recent_notifications as $_notif):
                            $_ndate = (new DateTime($_notif['created_at']))->format('Y-m-d');
                            if ($_ndate === $_today && $_section !== 'today') {
                                $_section = 'today';
                                echo '<div class="px-4 pt-3 pb-1"><p class="text-[13px] font-bold text-gray-900">New</p></div>';
                            } elseif ($_ndate !== $_today && $_section !== 'older') {
                                $_section = 'older';
                                echo '<div class="px-4 pt-3 pb-1 border-t border-gray-100"><p class="text-[13px] font-bold text-gray-900">Earlier</p></div>';
                            }
                        ?>
                        <?php
                            $_notif_href = !empty($_notif['link']) ? $_notif['link'] : notif_action_url($_notif, 'student');
                        ?>
                        <a href="<?= htmlspecialchars($_notif_href) ?>" class="flex items-start gap-3 px-4 py-3 hover:bg-teal-50 transition-colors duration-100 cursor-pointer group relative no-underline <?= !$_notif['is_read'] ? 'bg-teal-50/40' : '' ?>" onclick="return onNotificationItemClick(event, this)" data-notif-id="<?= (int)$_notif['id'] ?>" data-redirect-url="<?= htmlspecialchars($_notif_href) ?>" data-fallback-href="<?= htmlspecialchars($_notif_href) ?>">
                            <?php if (!$_notif['is_read']): ?>
                            <span class="w-2.5 h-2.5 bg-teal-500 rounded-full flex-shrink-0 mt-2 shadow-sm"></span>
                            <?php else: ?>
                            <span class="w-2.5 flex-shrink-0 mt-2"></span>
                            <?php endif; ?>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm shrink-0 shadow-sm <?= ($_notif['type'] ?? '') === 'instructor_approved' ? 'bg-emerald-100 text-emerald-600' : (($_notif['type'] ?? '') === 'instructor_rejected' ? 'bg-red-100 text-red-600' : 'bg-teal-100 text-teal-600') ?>">
                                <?php if (($_notif['type'] ?? '') === 'instructor_approved'): ?>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <?php elseif (($_notif['type'] ?? '') === 'instructor_rejected'): ?>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] leading-snug <?= !$_notif['is_read'] ? 'font-semibold text-gray-900' : 'text-gray-600' ?>"><?= htmlspecialchars($_notif['title']) ?></p>
                                <p class="text-[12px] text-gray-400 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($_notif['message']) ?></p>
                                <p class="text-[11px] mt-1 <?= !$_notif['is_read'] ? 'text-teal-600 font-medium' : 'text-gray-400' ?>" data-notif-time="<?= htmlspecialchars($_notif['created_at']) ?>"><?= (new DateTime($_notif['created_at']))->format('d M Y, h:i A') ?></p>
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
                    <a href="log-history.php" class="block text-center py-3 text-[13px] font-semibold text-teal-600 hover:bg-teal-50 transition-colors">See all</a>
                </div>
            </div>
        </div>

        <!-- Profile Dropdown -->
        <div class="relative shrink-0" id="profile-dropdown-wrapper">
            <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="flex items-center gap-2.5 p-1.5 hover:bg-teal-50 border border-transparent hover:border-teal-100 rounded-xl transition-all cursor-pointer group">
                <?php if (!empty($profile_pic)): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover border border-teal-200 shadow-sm shrink-0">
                <?php else: ?>
                <div class="w-9 h-9 rounded-xl bg-teal-700 flex items-center justify-center font-bold text-sm text-white shadow-sm shrink-0">
                    <?= strtoupper(substr($_SESSION['username'] ?? 'S', 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div class="text-left hidden sm:block">
                    <p class="font-semibold text-sm text-slate-800 leading-tight"><?= htmlspecialchars($student_name ?? '') ?></p>
                    <p class="text-xs font-medium text-teal-700 capitalize">Student</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-lg border border-teal-100 py-1.5 z-50 divide-y divide-slate-100">
                <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                    <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> My Profile
                </a>
                <a href="profile.php#security-section" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-teal-50 hover:text-teal-900 transition">
                    <svg class="w-4 h-4 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> Change Password
                </a>
                <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                    <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
                </a>
            </div>
        </div>

    </div>
</header>

<script>
function toggleStudentSidebar() {
    var sb = document.getElementById('studentSidebar');
    var bd = document.getElementById('studentSidebarBackdrop');
    if (!sb) return;
    if (sb.classList.contains('-translate-x-full')) {
        sb.classList.remove('-translate-x-full');
        if (bd) bd.classList.remove('hidden');
    } else {
        sb.classList.add('-translate-x-full');
        if (bd) bd.classList.add('hidden');
    }
}
<?php
// Announcement modal intentionally removed for student view to avoid showing modal.
// If needed later, set $enable_announcement_modal = true before including this file.
?>
</script>

<script src="../assets/js/main.js"></script>
<script src="../assets/js/notifications.js"></script>
