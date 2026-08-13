<?php
/**
 * Supervisor Sidebar — Single source of truth (mirrors the Supervisor Dashboard sidebar).
 * Usage:
 *   <?php $active_page = 'profile'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>
 *
 * Valid $active_page values:
 *   dashboard, students, reports, companies, notifications, profile, archive
 *
 * Optional vars read (all guarded):
 *   $unread_notif_count  → red badge on the Notifications item
 *   $pending_reviews     → amber badge on the Reports item
 */

if (!isset($active_page)) $active_page = 'dashboard';

$unread_notif_count = (int) ($unread_notif_count ?? 0);
$pending_reviews    = (int) ($pending_reviews ?? 0);
?>
    <!-- ─── SIDEBAR ─── -->
    <aside class="w-64 bg-white/80 backdrop-blur-xl border-r border-slate-200/60 flex flex-col shrink-0 shadow-xl shadow-slate-200/20">
        <div class="h-16 flex items-center px-6 border-b border-slate-100/80 bg-gradient-to-r from-indigo-500/5 to-purple-500/5">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                    <span class="text-white text-sm">📋</span>
                </div>
                <div>
                    <span class="text-sm font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    <span class="block text-sm font-bold text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded mt-0.5">SUPERVISOR</span>
                </div>
            </div>
        </div>

        <nav class="flex-1 py-5 px-3 space-y-1 overflow-y-auto">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'dashboard' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="my-students.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'students' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🎓</span> My Students
            </a>
            <a href="supervisor-reports.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'reports' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📄</span> Reports
                <?php if ($pending_reviews > 0): ?>
                <span class="ml-auto text-micro font-bold text-white bg-amber-500 rounded-full w-5 h-5 flex items-center justify-center shadow-sm"><?= $pending_reviews > 9 ? '9+' : $pending_reviews ?></span>
                <?php endif; ?>
            </a>
            <a href="supervisor-companies.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'companies' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🏢</span> Companies
            </a>
            <a href="notifications.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'notifications' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🔔</span> Notifications
                <?php if ($unread_notif_count > 0): ?>
                <span class="ml-auto text-micro font-bold text-white bg-red-500 rounded-full w-5 h-5 flex items-center justify-center shadow-sm"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'profile' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>

            <div class="pt-4 mt-2 border-t border-slate-100/80">
                <h3 class="px-4 mb-1 text-xs font-bold text-slate-500 tracking-wider uppercase">Past Academic Years</h3>
                <a href="supervisor-dashboard.php?tab=trainee-archive" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium <?= $active_page === 'archive' ? 'bg-gradient-to-r from-purple-600 to-purple-700 text-white font-semibold shadow-lg shadow-purple-500/30' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                    <span class="w-5 h-5 flex items-center justify-center shrink-0">⏪</span> Archived Records
                </a>
            </div>
        </nav>

        <div class="p-3 border-t border-slate-100/80">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-subtitle leading-relaxed font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>
