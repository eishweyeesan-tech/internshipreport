<?php
/**
 * Admin Sidebar — Single source of truth.
 * Usage: <?php $activePage = 'dashboard'; require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
 *
 * Valid $activePage values:
 *   dashboard, students, supervisors, manage, companies, announcements,
 *   academic-years, archive, history, past-year, holidays, profile
 */

if (!isset($activePage)) $activePage = 'dashboard';

$nav_current = [
    ['key' => 'dashboard',     'href' => 'admin-dashboard.php',                   'icon' => '📊', 'label' => 'Overview'],
    ['key' => 'students',      'href' => 'admin-dashboard.php?tab=students',      'icon' => '🎓', 'label' => 'Add Student'],
    ['key' => 'supervisors',   'href' => 'admin-dashboard.php?tab=supervisors',   'icon' => '👨‍🏫', 'label' => 'Add Supervisor'],
    ['key' => 'manage',        'href' => 'admin-dashboard.php?tab=manage',        'icon' => '👥', 'label' => 'Manage Users'],
    ['key' => 'companies',     'href' => 'manage-companies.php',                  'icon' => '🏢', 'label' => 'Manage Companies'],
    ['key' => 'academic-years','href' => 'manage-academic-years.php',             'icon' => '📅', 'label' => 'Academic Years'],
    ['key' => 'announcements', 'href' => 'create-announcement.php',               'icon' => '📢', 'label' => 'Announcements'],
];

$nav_past = [
    ['key' => 'archive',       'href' => 'admin-dashboard.php?tab=archive',       'icon' => '📦', 'label' => 'Batch Archive'],
    ['key' => 'history',       'href' => 'admin-dashboard.php?tab=history',       'icon' => '📜', 'label' => 'Student History'],
    ['key' => 'past-year',     'href' => 'past-year.php',                         'icon' => '🗄️', 'label' => 'Past Academic Years'],
];

$nav_settings = [
    ['key' => 'holidays',      'href' => 'admin-dashboard.php?tab=holidays',      'icon' => '🇲🇲', 'label' => 'Myanmar Holidays'],
    ['key' => 'profile',       'href' => 'admin-profile.php',                     'icon' => '👤', 'label' => 'Profile'],
];
?>
    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 flex flex-col shrink-0 bg-gradient-to-b from-slate-900 via-slate-800 to-slate-900 shadow-lg">
        <div class="h-14 flex items-center px-5 bg-white/5 backdrop-blur-sm">
            <span class="text-sm font-black tracking-tight text-slate-100">📋 InternReport</span>
            <span class="ml-2 text-xs font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/20">ADMIN</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-3 overflow-y-auto scrollbar-thin">

            <!-- Current Year Section -->
            <p class="text-micro font-bold uppercase tracking-widest text-slate-500 px-3 pt-1 pb-1">Current Year</p>
            <?php foreach ($nav_current as $item): ?>
            <?php $isActive = $activePage === $item['key']; ?>
            <a href="<?= $item['href'] ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle font-medium transition-all duration-200
                <?= $isActive
                    ? 'bg-gradient-to-r from-indigo-500/20 to-indigo-600/15 text-indigo-300 border border-indigo-500/25 shadow-[0_0_15px_rgba(99,102,241,0.1)] font-semibold relative before:content-[\'\'] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[60%] before:bg-gradient-to-b before:from-indigo-400 before:to-indigo-600 before:rounded-r'
                    : 'text-slate-400 border border-transparent hover:text-slate-200 hover:bg-white/5 hover:border-white/5' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0 transition-transform duration-200 hover:scale-110"><?= $item['icon'] ?></span> <?= $item['label'] ?>
            </a>
            <?php endforeach; ?>

            <!-- Previous Years Section -->
            <p class="text-micro font-bold uppercase tracking-widest text-slate-500 px-3 pt-4 pb-1">Previous Years</p>
            <?php foreach ($nav_past as $item): ?>
            <?php $isActive = $activePage === $item['key']; ?>
            <a href="<?= $item['href'] ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle font-medium transition-all duration-200
                <?= $isActive
                    ? 'bg-gradient-to-r from-indigo-500/20 to-indigo-600/15 text-indigo-300 border border-indigo-500/25 shadow-[0_0_15px_rgba(99,102,241,0.1)] font-semibold relative before:content-[\'\'] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[60%] before:bg-gradient-to-b before:from-indigo-400 before:to-indigo-600 before:rounded-r'
                    : 'text-slate-400 border border-transparent hover:text-slate-200 hover:bg-white/5 hover:border-white/5' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0 transition-transform duration-200 hover:scale-110"><?= $item['icon'] ?></span> <?= $item['label'] ?>
            </a>
            <?php endforeach; ?>

            <!-- Settings Section -->
            <p class="text-micro font-bold uppercase tracking-widest text-slate-500 px-3 pt-4 pb-1">Settings</p>
            <?php foreach ($nav_settings as $item): ?>
            <?php $isActive = $activePage === $item['key']; ?>
            <a href="<?= $item['href'] ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle font-medium transition-all duration-200
                <?= $isActive
                    ? 'bg-gradient-to-r from-indigo-500/20 to-indigo-600/15 text-indigo-300 border border-indigo-500/25 shadow-[0_0_15px_rgba(99,102,241,0.1)] font-semibold relative before:content-[\'\'] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[60%] before:bg-gradient-to-b before:from-indigo-400 before:to-indigo-600 before:rounded-r'
                    : 'text-slate-400 border border-transparent hover:text-slate-200 hover:bg-white/5 hover:border-white/5' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0 transition-transform duration-200 hover:scale-110"><?= $item['icon'] ?></span> <?= $item['label'] ?>
            </a>
            <?php endforeach; ?>

        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-red-400 font-semibold rounded-lg transition-all duration-200 border border-transparent hover:bg-red-500/10 hover:text-red-300 hover:border-red-500/15">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>
