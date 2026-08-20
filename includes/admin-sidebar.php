<?php

/**
 * Admin Sidebar — Single source of truth.
 * Usage: <?php $activePage = 'dashboard'; require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
 *
 * Valid $activePage values:
 *   dashboard, students, supervisors, manage, companies, history, profile
 */

if (!isset($activePage)) $activePage = 'dashboard';

$nav_items = [
    ['key' => 'dashboard',      'href' => 'admin-dashboard.php',                 'icon' => '📊', 'label' => 'Overview'],
    ['key' => 'companies',      'href' => 'manage-companies.php',                'icon' => '🏢', 'label' => 'Companies'],
    ['key' => 'supervisors',    'href' => 'admin-dashboard.php?tab=supervisors', 'icon' => '👨‍🏫', 'label' => 'Supervisors'],
    ['key' => 'students',       'href' => 'admin-dashboard.php?tab=students',    'icon' => '🎓', 'label' => 'Students'],
    ['key' => 'manage',         'href' => 'admin-dashboard.php?tab=manage',      'icon' => '👥', 'label' => 'Manage Users'],
    ['key' => 'academic-years', 'href' => 'academic-years.php',                   'icon' => '📅', 'label' => 'Academic Years'],
    ['key' => 'history',        'href' => 'admin-dashboard.php?tab=history',     'icon' => '📜', 'label' => 'Reports'],
];
?>
<!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
<div id="sidebarBackdrop" onclick="toggleAdminSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden"></div>

<!-- ─── SIDEBAR ─── -->
<aside id="adminSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out flex flex-col shrink-0 bg-[#005f73] border-r border-teal-700/40 shadow-xl text-white">
    <div class="h-16 flex items-center justify-between px-5 bg-teal-900/40 backdrop-blur-sm border-b border-teal-700/40">
        <div class="flex items-center gap-2">
            <span class="text-sm font-black tracking-tight text-white">📋 InternReport</span>
            <span class="text-micro font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full bg-teal-600/40 text-teal-100 border border-teal-400/30">ADMIN</span>
        </div>
        <button type="button" onclick="toggleAdminSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">
            ✕
        </button>
    </div>
    <nav class="flex-1 py-4 space-y-1 px-3 overflow-y-auto scrollbar-thin">
        <?php foreach ($nav_items as $item): ?>
            <?php $isActive = $activePage === $item['key']; ?>
            <a href="<?= $item['href'] ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle font-medium transition-all duration-200
                <?= $isActive
                    ? 'bg-[#0a9396] text-white font-semibold shadow-sm border border-teal-400/30'
                    : 'text-teal-100 hover:text-white hover:bg-teal-700/60' ?>">
                <span class="w-5 h-5 flex items-center justify-center shrink-0 transition-transform duration-200 hover:scale-110"><?= $item['icon'] ?></span> <?= $item['label'] ?>
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
    function toggleAdminSidebar() {
        var sb = document.getElementById('adminSidebar');
        var bd = document.getElementById('sidebarBackdrop');
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