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
    <aside class="w-64 bg-white/90 backdrop-blur-xl border-r border-teal-100 flex flex-col shrink-0 shadow-xl shadow-teal-900/5">
        <div class="h-16 flex items-center px-6 border-b border-teal-50 bg-gradient-to-r from-teal-50/50 to-emerald-50/30">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center shadow-lg shadow-teal-600/30">
                    <span class="text-white text-sm">📋</span>
                </div>
                <div>
                    <span class="text-sm font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    <span class="block text-caption font-bold text-teal-700 bg-teal-100/60 px-1.5 py-0.5 rounded mt-0.5">SUPERVISOR</span>
                </div>
            </div>
        </div>

        <nav class="flex-1 py-5 px-3 space-y-1 overflow-y-auto">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'dashboard' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Dashboard
            </a>
            <a href="my-students.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'students' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🎓</span> My Students
            </a>
            <a href="supervisor-reports.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'reports' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📄</span> Reports
                <?php if ($pending_reviews > 0): ?>
                <span class="ml-auto text-micro font-bold text-white bg-amber-500 rounded-full w-5 h-5 flex items-center justify-center shadow-sm"><?= $pending_reviews > 9 ? '9+' : $pending_reviews ?></span>
                <?php endif; ?>
            </a>
            <a href="supervisor-companies.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'companies' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🏢</span> Companies
            </a>
            <a href="notifications.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'notifications' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🔔</span> Notifications
                <?php if ($unread_notif_count > 0): ?>
                <span class="ml-auto text-micro font-bold text-white bg-red-500 rounded-full w-5 h-5 flex items-center justify-center shadow-sm"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 <?= $active_page === 'profile' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>

            <div class="pt-4 mt-2 border-t border-slate-100">
                <h3 class="px-4 mb-1 text-xs font-bold text-slate-400 tracking-wider uppercase">Past Academic Years</h3>
                <a href="supervisor-dashboard.php?tab=trainee-archive" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-subtitle leading-relaxed transition-colors duration-200 font-medium <?= $active_page === 'archive' ? 'bg-gradient-to-r from-teal-600 to-teal-700 text-white font-semibold shadow-lg shadow-teal-600/25' : 'text-slate-600 hover:bg-teal-50 hover:text-teal-900' ?>">
                    <span class="w-5 h-5 flex items-center justify-center shrink-0">⏪</span> Archived Records
                </a>
            </div>
        </nav>

        <div class="p-3 border-t border-slate-100">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-subtitle leading-relaxed font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>
