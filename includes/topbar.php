<?php
// topbar.php – Shared admin top navigation bar
$topbar_user_id = (int)($_SESSION['user_id'] ?? 0);
$topbar_pic = $_SESSION['profile_pic'] ?? '';
$topbar_email = $_SESSION['email'] ?? '';
$topbar_display_name = $admin_name ?? ($_SESSION['username'] ?? 'Admin');

if ($topbar_user_id > 0 && isset($db) && $db) {
    if (empty($topbar_pic) || empty($topbar_email)) {
        $_uinfo = $db->prepare("SELECT email, profile_pic FROM users WHERE id = ?");
        if ($_uinfo) {
            $_uinfo->bind_param("i", $topbar_user_id);
            $_uinfo->execute();
            $_res = $_uinfo->get_result();
            if ($_res && $row = $_res->fetch_assoc()) {
                if (empty($topbar_pic)) $topbar_pic = $row['profile_pic'] ?? '';
                if (empty($topbar_email)) $topbar_email = $row['email'] ?? '';
            }
        }
    }
}
?>
<header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 shrink-0">
    <div class="flex items-center gap-3">
        <button type="button" onclick="toggleAdminSidebar()" class="lg:hidden p-2 text-slate-500 hover:bg-slate-100 rounded-xl transition cursor-pointer" aria-label="Toggle sidebar">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-base sm:text-lg font-black text-slate-800 tracking-tight truncate"><?= htmlspecialchars($pageTitle ?? '📊 Admin Dashboard') ?></h1>
    </div>
    <div class="flex items-center gap-4 shrink-0 h-full justify-end">

        <!-- Profile Dropdown -->
        <div class="relative shrink-0" id="profileDropdownContainer">
            <button
                type="button"
                onclick="toggleProfileDropdown(event)"
                id="profile-avatar-btn"
                class="flex items-center gap-2.5 p-1.5 pr-2 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-200/80 transition-all duration-200 cursor-pointer group focus:outline-none"
                aria-label="User menu"
            >
                <div class="relative shrink-0">
                    <?php if (!empty($topbar_pic)): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($topbar_pic) ?>" alt="Avatar" class="w-9 h-9 rounded-xl object-cover ring-2 ring-teal-500/20 shadow-xs">
                    <?php else: ?>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-teal-700 to-teal-500 flex items-center justify-center font-bold text-sm text-white shadow-xs">
                        <?= strtoupper(substr($topbar_display_name, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-emerald-500 border-2 border-white rounded-full"></span>
                </div>
                <div class="text-left hidden sm:block">
                    <p class="font-bold text-xs text-slate-800 leading-tight group-hover:text-teal-700 transition-colors"><?= htmlspecialchars($topbar_display_name) ?></p>
                    <p class="text-[11px] font-medium text-slate-400">Administrator</p>
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-teal-600 shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <!-- Profile Dropdown Menu -->
            <div
                id="profileDropdownMenu"
                class="hidden absolute right-0 top-full mt-2 w-72 bg-white rounded-2xl shadow-xl shadow-slate-900/10 border border-slate-200/80 p-2 z-50 transition-all duration-200 ease-out"
            >
                <!-- User Info Card Header -->
                <div class="p-3 bg-gradient-to-br from-slate-50 to-teal-50/40 rounded-xl border border-slate-100 mb-1.5 flex items-center gap-3">
                    <div class="relative shrink-0">
                        <?php if (!empty($topbar_pic)): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($topbar_pic) ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover border border-slate-200/80 shadow-xs">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-teal-700 to-teal-500 flex items-center justify-center font-bold text-sm text-white shadow-xs">
                            <?= strtoupper(substr($topbar_display_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <p class="font-bold text-xs text-slate-900 truncate"><?= htmlspecialchars($topbar_display_name) ?></p>
                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-teal-100 text-teal-800">Admin</span>
                        </div>
                        <p class="text-[11px] text-slate-500 truncate mt-0.5"><?= htmlspecialchars($topbar_email ?: 'Administrator') ?></p>
                    </div>
                </div>

                <!-- Menu Items -->
                <div class="space-y-1">
                    <a href="admin-profile.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold text-slate-700 hover:bg-teal-50/70 hover:text-teal-900 transition-all duration-150 group">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center group-hover:bg-teal-600 group-hover:text-white transition-colors duration-150 shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800 group-hover:text-teal-900 leading-tight">My Profile</p>
                                <p class="text-[10px] text-slate-400 font-normal mt-0.5">Account & system settings</p>
                            </div>
                        </div>
                        <svg class="w-3.5 h-3.5 text-slate-300 group-hover:text-teal-600 group-hover:translate-x-0.5 transition-all shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>

                <div class="my-1.5 border-t border-slate-100"></div>

                <div>
                    <a href="../logout.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold text-rose-600 hover:bg-rose-50 hover:text-rose-700 transition-all duration-150 group">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center group-hover:bg-rose-600 group-hover:text-white transition-colors duration-150 shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            </span>
                            <span class="font-semibold text-rose-600 group-hover:text-rose-700">Sign Out</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>

    </div>
</header>

<script src="../assets/js/main.js"></script>

