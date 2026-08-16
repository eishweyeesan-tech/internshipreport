<?php
/**
 * Supervisor Sidebar — Single source of truth (mirrors the Supervisor Dashboard sidebar).
 * Usage:
 *   <?php $active_page = 'profile'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
 *
 * Valid $active_page values:
 *   dashboard, students, reports, companies, notifications, profile
 *
 * Optional vars read (all guarded):
 *   $unread_notif_count  → red badge on the Notifications item
 *   $pending_reviews     → amber badge on the Reports item
 */

if (!isset($active_page)) $active_page = 'dashboard';

$unread_notif_count = (int) ($unread_notif_count ?? 0);
$pending_reviews    = (int) ($pending_reviews ?? 0);
?>
    <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
    <div id="supervisorSidebarBackdrop" onclick="toggleSupervisorSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

    <!-- ─── SIDEBAR ─── -->
    <aside id="supervisorSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out bg-[#005f73] border-r border-teal-700/40 flex flex-col shrink-0 text-white shadow-xl print:hidden">
        <div class="h-16 flex items-center justify-between px-6 border-b border-teal-700/40 bg-teal-900/30">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-teal-600 flex items-center justify-center shadow-md">
                    <span class="text-white text-sm">📋</span>
                </div>
                <div>
                    <span class="text-sm font-extrabold text-white tracking-tight">InternReport</span>
                    <span class="block text-caption font-bold text-teal-100 bg-teal-700/60 border border-teal-500/40 px-1.5 py-0.5 rounded mt-0.5">SUPERVISOR</span>
                </div>
            </div>
            <button type="button" onclick="toggleSupervisorSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">
                ✕
            </button>
        </div>

        <nav class="flex-1 py-5 px-3 space-y-1 overflow-y-auto">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'dashboard' ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30' : 'font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="my-students.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'students' ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30' : 'font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🎓</span> My Students
            </a>
            <a href="supervisor-reports.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'reports' ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30' : 'font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📄</span> Reports
                <?php if ($pending_reviews > 0): ?>
                <span class="ml-auto text-micro font-bold text-white bg-amber-500 rounded-full w-5 h-5 flex items-center justify-center shadow-sm"><?= $pending_reviews > 9 ? '9+' : $pending_reviews ?></span>
                <?php endif; ?>
            </a>
            <a href="supervisor-companies.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'companies' ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30' : 'font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🏢</span> Companies
            </a>
            <a href="notifications.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'notifications' ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30' : 'font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🔔</span> Notifications
                <?php if ($unread_notif_count > 0): ?>
                <span class="ml-auto text-micro font-bold text-white bg-red-500 rounded-full w-5 h-5 flex items-center justify-center shadow-sm"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'profile' ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30' : 'font-medium text-teal-100 hover:bg-teal-700/60 hover:text-white' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>
        </nav>

        <div class="p-3 border-t border-teal-700/40">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-subtitle leading-relaxed font-semibold text-red-300 hover:text-white hover:bg-red-500/20 rounded-xl transition-colors duration-200">
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
