<?php
/**
 * Supervisor Sidebar — Single source of truth.
 * Usage:
 *   <?php $active_page = 'students'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
 *
 * Valid $active_page values:
 *   dashboard, students, reports, companies, notifications, profile
 *
 * Optional vars read (all guarded):
 *   $unread_notif_count  → red badge on the Notifications item
 *   $pending_reviews     → amber badge on the Reports item
 */

if (!isset($active_page)) $active_page = 'dashboard';

if (!isset($unread_notif_count) && isset($db, $_SESSION['user_id'])) {
    $sup_id_sb = (int) $_SESSION['user_id'];
    $unread_notif_q_sb = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($unread_notif_q_sb) {
        $unread_notif_q_sb->bind_param("i", $sup_id_sb);
        $unread_notif_q_sb->execute();
        $res_sb = $unread_notif_q_sb->get_result();
        $row_sb = $res_sb ? $res_sb->fetch_row() : null;
        $unread_notif_count = (int) ($row_sb[0] ?? 0);
    }
}

if (!isset($pending_reviews) && isset($db, $_SESSION['user_id'])) {
    $sup_id_sb = (int) $_SESSION['user_id'];
    $pending_q_sb = $db->prepare("
        SELECT COUNT(*) FROM report_evaluations re
        WHERE re.report_status = 'approved_by_instructor'
          AND re.student_id IN (
              SELECT u.id FROM users u
              JOIN student_profiles sp ON sp.user_id = u.id
              WHERE u.role = 'student' AND sp.supervisor_id = ?
          )
          AND NOT EXISTS (
              SELECT 1 FROM supervisor_weekly_evaluations swe
              WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
          )
    ");
    if ($pending_q_sb) {
        $pending_q_sb->bind_param("i", $sup_id_sb);
        $pending_q_sb->execute();
        $res_sb = $pending_q_sb->get_result();
        $row_sb = $res_sb ? $res_sb->fetch_row() : null;
        $pending_reviews = (int) ($row_sb[0] ?? 0);
    }
}

$unread_notif_count = (int) ($unread_notif_count ?? 0);
$pending_reviews    = (int) ($pending_reviews ?? 0);

$nav_items = [
    ['key' => 'dashboard',     'href' => 'supervisor-dashboard.php', 'icon' => '📊', 'label' => 'Dashboard',     'badge' => 0, 'badge_color' => ''],
    ['key' => 'students',      'href' => 'my-students.php',           'icon' => '🎓', 'label' => 'My Students',   'badge' => 0, 'badge_color' => ''],
    ['key' => 'reports',       'href' => 'supervisor-reports.php',   'icon' => '📄', 'label' => 'Reports',       'badge' => $pending_reviews, 'badge_color' => 'bg-amber-500'],
    ['key' => 'companies',     'href' => 'supervisor-companies.php', 'icon' => '🏢', 'label' => 'Companies',     'badge' => 0, 'badge_color' => ''],
    ['key' => 'notifications', 'href' => 'notifications.php',        'icon' => '🔔', 'label' => 'Notifications', 'badge' => $unread_notif_count, 'badge_color' => 'bg-red-500'],
    ['key' => 'profile',       'href' => 'profile.php',              'icon' => '👤', 'label' => 'Profile',       'badge' => 0, 'badge_color' => ''],
];
?>
<!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
<div id="supervisorSidebarBackdrop" onclick="toggleSupervisorSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

<!-- ─── SIDEBAR ─── -->
<aside id="supervisorSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out flex flex-col shrink-0 bg-[#005f73] border-r border-teal-700/40 shadow-xl text-white print:hidden">
    <div class="h-16 flex items-center justify-between px-5 bg-teal-900/40 backdrop-blur-sm border-b border-teal-700/40">
        <div class="flex items-center gap-2">
            <span class="font-black text-white tracking-tight text-lg">InternReport</span>
        </div>
        <button type="button" onclick="toggleSupervisorSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition cursor-pointer" aria-label="Close sidebar">
            ✕
        </button>
    </div>
    <nav class="flex-1 py-4 space-y-1 px-3 overflow-y-auto scrollbar-thin">
        <?php foreach ($nav_items as $item): ?>
            <?php $isActive = $active_page === $item['key']; ?>
            <a href="<?= $item['href'] ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle font-medium transition-all duration-200
                <?= $isActive
                    ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30'
                    : 'text-teal-100 hover:text-white hover:bg-teal-700/60' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0 transition-transform duration-200 hover:scale-110"><?= $item['icon'] ?></span>
                <span class="truncate"><?= $item['label'] ?></span>
                <?php if ($item['badge'] > 0): ?>
                <span class="ml-auto text-micro font-bold text-white <?= $item['badge_color'] ?> rounded-full w-5 h-5 flex items-center justify-center shadow-xs"><?= $item['badge'] > 9 ? '9+' : $item['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="p-3 border-t border-teal-700/40">
        <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-red-300 font-semibold rounded-lg transition-all duration-200 hover:bg-red-500/20 hover:text-white">
            <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
        </a>
    </div>
</aside>

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
</script>

