<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

$admin_name = $_SESSION['username'];
$admin_id   = $_SESSION['user_id'];
$msg = '';
$err = '';

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// ── Add Student ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $s_name          = trim($_POST['s_name'] ?? '');
    $s_roll          = trim($_POST['s_roll'] ?? '');
    $s_major         = trim($_POST['s_major'] ?? '');
    $s_email         = trim($_POST['s_email'] ?? '');
    $s_company_id    = (int) ($_POST['s_company_id'] ?? 0);
    $s_supervisor_id = (int) ($_POST['s_supervisor_id'] ?? 0);
    $s_instructor    = trim($_POST['s_instructor'] ?? '');
    $s_start         = trim($_POST['s_start_date'] ?? '');
    $s_end           = trim($_POST['s_end_date'] ?? '');
    $s_academic      = trim($_POST['s_academic_year'] ?? '');
    $s_password      = $_POST['s_password'] ?? '';

    if (empty($s_name) || empty($s_roll) || empty($s_email) || empty($s_password)) {
        $err = 'Name, Roll No, Email, and Password are required.';
    } elseif (!filter_var($s_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } elseif (strlen($s_password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif ($s_academic && !preg_match('/^\d{4}-\d{4}$/', $s_academic)) {
        $err = 'Academic year must be in range format (e.g. 2024-2025).';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$s_email]);
        if ($check->fetch()) {
            $err = 'A user with this email already exists.';
        } else {
            // Look up company name from selected company_id
            $company_name = '';
            if ($s_company_id > 0) {
                $cn = $pdo->prepare("SELECT company_name FROM companies WHERE id = ?");
                $cn->execute([$s_company_id]);
                $company_name = $cn->fetchColumn() ?: '';
            }

            $hash = password_hash($s_password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, email, password, role, is_first_login, academic_year) VALUES (?, ?, ?, 'student', 1, ?)")
                ->execute([$s_roll, $s_email, $hash, $s_academic ?: null]);
            $uid = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO student_profiles (user_id, full_name, student_roll, major, company_id, company_name, supervisor_id, instructor_name, internship_start_date, internship_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$uid, $s_name, $s_roll, $s_major, $s_company_id ?: null, $company_name, $s_supervisor_id ?: null, $s_instructor, $s_start ?: null, $s_end ?: null]);
            $msg = "Student \"{$s_name}\" created. Email: {$s_email}, Password: {$s_password}";
        }
    }
}

// ── Add Supervisor ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supervisor'])) {
    $t_name     = trim($_POST['t_name'] ?? '');
    $t_dept     = trim($_POST['t_dept'] ?? '');
    $t_email    = trim($_POST['t_email'] ?? '');
    $t_academic = trim($_POST['t_academic_year'] ?? '');
    $t_password = $_POST['t_password'] ?? '';

    if (empty($t_name) || empty($t_email) || empty($t_password)) {
        $err = 'Name, Email, and Password are required.';
    } elseif (!filter_var($t_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } elseif (strlen($t_password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif ($t_academic && !preg_match('/^\d{4}-\d{4}$/', $t_academic)) {
        $err = 'Academic year must be in range format (e.g. 2024-2025).';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$t_email]);
        if ($check->fetch()) {
            $err = 'A user with this email already exists.';
        } else {
            $hash = password_hash($t_password, PASSWORD_DEFAULT);
            $uname = 'sup_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t_name));
            $pdo->prepare("INSERT INTO users (username, email, password, role, is_first_login, academic_year) VALUES (?, ?, ?, 'supervisor', 1, ?)")
                ->execute([$uname, $t_email, $hash, $t_academic ?: null]);
            $msg = "Supervisor \"{$t_name}\" created. Email: {$t_email}, Password: {$t_password}";
        }
    }
}

// ── Delete User ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $did = (int) ($_POST['delete_uid'] ?? 0);
    if ($did > 0 && $did !== $admin_id) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$did]);
        $msg = 'User deleted.';
    }
}

// ── Reset User Password ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $rid = (int) ($_POST['reset_uid'] ?? 0);
    if ($rid > 0 && $rid !== $admin_id) {
        // Determine which default password to use based on the user's role
        $r_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $r_role->execute([$rid]);
        $r_role = $r_role->fetchColumn();
        $default_pw = ($r_role === 'supervisor') ? $def_supervisor_pw : $def_student_pw;

        $hash = password_hash($default_pw, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ?, is_first_login = 1 WHERE id = ? AND role IN ('student','supervisor')")
            ->execute([$hash, $rid]);

        $rname = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $rname->execute([$rid]);
        $rname = $rname->fetchColumn() ?: 'User';

        $msg = "Password reset for \"{$rname}\". Default password: {$default_pw}";
    }
}

// ── Batch Archive ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_batch'])) {
    $batch_year = trim($_POST['batch_year'] ?? '');
    if (empty($batch_year)) {
        $err = 'Please select an academic year to archive.';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $batch_year)) {
        $err = 'Invalid academic year format.';
    } else {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE academic_year = ? AND role = 'student'");
        $cnt->execute([$batch_year]);
        $count = (int) $cnt->fetchColumn();

        $pdo->prepare("UPDATE users SET status = 'Archived' WHERE academic_year = ? AND role = 'student'")->execute([$batch_year]);
        $msg = "Archived {$count} student(s) from batch {$batch_year}.";
    }
}

// ── Add Holiday ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_holiday'])) {
    $h_date      = trim($_POST['h_date'] ?? '');
    $h_name_mm   = trim($_POST['h_name_mm'] ?? '');
    $h_note      = trim($_POST['h_note'] ?? '');
    if (empty($h_date)) {
        $err = 'Holiday date is required.';
    } else {
        $dup = $pdo->prepare("SELECT id FROM holidays WHERE holiday_date = ?");
        $dup->execute([$h_date]);
        if ($dup->fetch()) {
            $err = 'A holiday already exists for this date.';
        } else {
            $displayName = $h_name_mm ?: $h_date;
            $pdo->prepare("INSERT INTO holidays (holiday_date, holiday_name, holiday_name_mm, note) VALUES (?, ?, ?, ?)")
                ->execute([$h_date, $displayName, $h_name_mm ?: null, $h_note ?: null]);
            $msg = "Holiday \"{$displayName}\" added for {$h_date}.";
        }
    }
}

// ── Delete Holiday ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_holiday'])) {
    $hid = (int) ($_POST['holiday_id'] ?? 0);
    if ($hid > 0) {
        $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([$hid]);
        $msg = 'Holiday deleted.';
    }
}

// ══════════════════════════════════════════════════════════════════
// DATA QUERIES
// ══════════════════════════════════════════════════════════════════

// Analytics counts
$student_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$supervisor_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'supervisor'")->fetchColumn();
$company_count = (int) $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$pending_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_first_login = 1 AND role != 'admin'")->fetchColumn();

// System default passwords
$sys_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$def_student_pw  = $sys_settings['default_student_password'] ?? 'password123';
$def_supervisor_pw = $sys_settings['default_supervisor_password'] ?? 'password123';

// Companies list
$companies = $pdo->query("SELECT * FROM companies ORDER BY company_name ASC")->fetchAll();

// Supervisors list
$supervisors = $pdo->query("SELECT id, username, email FROM users WHERE role = 'supervisor' ORDER BY username")->fetchAll();

// Students list
$students = $pdo->query("
    SELECT u.id AS uid, u.username, u.email, u.is_first_login, u.academic_year, u.status, u.created_at,
           sp.full_name, sp.student_roll, sp.major, sp.company_name,
           sp.instructor_name, sp.supervisor_id
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student'
    ORDER BY sp.full_name ASC, u.username ASC
")->fetchAll();

// All users (with optional role filter)
$filter_role = $_GET['role'] ?? '';
$all_users_sql = "
    SELECT u.id, u.username, u.email, u.role, u.is_first_login, u.academic_year, u.status, u.created_at,
           sp.full_name, sp.student_roll
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
";
$params = [];
if (in_array($filter_role, ['admin', 'supervisor', 'student'])) {
    $all_users_sql .= " WHERE u.role = ?";
    $params[] = $filter_role;
}
$all_users_sql .= " ORDER BY FIELD(u.role, 'admin', 'supervisor', 'student'), u.created_at DESC";
$all_users_stmt = $pdo->prepare($all_users_sql);
$all_users_stmt->execute($params);
$all_users = $all_users_stmt->fetchAll();

// Holidays
$holidays = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC")->fetchAll();

// ══════════════════════════════════════════════════════════════════
// ACTIVE TAB
// ══════════════════════════════════════════════════════════════════
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'students', 'supervisors', 'manage', 'archive', 'history', 'holidays'])) $tab = 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    'inter': ['Inter', 'sans-serif'],
                },
            }
        }
    }
    function toggleProfileDropdown(e) {
        e.stopPropagation();
        var dd = document.getElementById('profile-dropdown-menu');
        dd.classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
    </script>
</head>
<body class="bg-slate-50 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 bg-white border-r border-slate-200 flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-slate-100">
            <span class="text-sm font-black text-slate-800 tracking-tight">📋 InternReport</span>
            <span class="ml-2 text-sm font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded">ADMIN</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="?tab=overview" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'overview' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>📊</span> Overview
            </a>
            <a href="?tab=students" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'students' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>🎓</span> Add Student
            </a>
            <a href="?tab=supervisors" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'supervisors' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>👨‍🏫</span> Add Supervisor
            </a>
            <a href="?tab=manage" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'manage' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>👥</span> Manage Users
            </a>
            <a href="manage-companies.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>🏢</span> Manage Companies
            </a>
            <a href="create-announcement.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>📢</span> Announcements
            </a>
            <a href="?tab=archive" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'archive' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>📦</span> Batch Archive
            </a>
            <a href="?tab=history" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'history' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>📜</span> Student History
            </a>
            <a href="?tab=holidays" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition <?= $tab === 'holidays' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                <span>🇲🇲</span> Myanmar Holidays
            </a>
            <a href="admin-profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-slate-100">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-sm font-semibold text-red-500 hover:bg-red-50 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-14 bg-white border-b border-slate-200 flex items-center justify-between px-6 shrink-0">
            <h1 class="text-sm font-bold text-slate-700">Admin Control Panel</h1>
            <div class="flex items-center gap-3 relative">
                <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                    <?php if (!empty($_SESSION['profile_pic'])): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-amber-500/20">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-amber-400 to-amber-600 text-white flex items-center justify-center text-lg font-bold shadow-lg shadow-amber-500/20">
                        <?= strtoupper(substr($admin_name, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                </button>
                <div class="text-right">
                    <p class="text-lg font-bold text-slate-700"><?= htmlspecialchars($admin_name) ?></p>
                    <p class="text-sm text-slate-400">Admin</p>
                </div>
                <!-- Profile Dropdown Menu -->
                <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 z-50 bg-white border border-slate-200 rounded-xl shadow-xl w-48 py-2">
                    <a href="admin-profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                        <span>👤</span> My Profile
                    </a>
                    <a href="admin-profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                        <span>🔑</span> Change Password
                    </a>
                    <div class="my-1 border-t border-slate-100"></div>
                    <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-red-500 hover:bg-red-50 transition">
                        <span>🚪</span> Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">

            <?php if ($msg): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 mb-6">
                <span>✅</span> <?= htmlspecialchars($msg) ?>
            </div>
            <?php endif; ?>
            <?php if ($err): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 mb-6">
                <span>❌</span> <?= htmlspecialchars($err) ?>
            </div>
            <?php endif; ?>

            <div class="max-w-6xl mx-auto space-y-6">

            <!-- ════ ANALYTICS SUMMARY CARDS (always visible) ════ -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Students Card → Manage Users tab -->
                <a href="?tab=manage" class="block bg-white rounded-2xl border border-slate-200 shadow-sm p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:bg-indigo-50/80 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">🎓</div>
                        <div>
                            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Students</p>
                            <p class="text-sm font-black text-slate-800"><?= $student_count ?></p>
                        </div>
                    </div>
                </a>
                <!-- Supervisors Card → Add Supervisor tab -->
                <a href="?tab=supervisors" class="block bg-white rounded-2xl border border-slate-200 shadow-sm p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:bg-emerald-50/80 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">👨‍🏫</div>
                        <div>
                            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Supervisors</p>
                            <p class="text-sm font-black text-slate-800"><?= $supervisor_count ?></p>
                        </div>
                    </div>
                </a>
                <!-- Companies Card → Manage Companies page -->
                <a href="manage-companies.php" class="block bg-white rounded-2xl border border-slate-200 shadow-sm p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:bg-blue-50/80 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg">🏢</div>
                        <div>
                            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Companies</p>
                            <p class="text-sm font-black text-slate-800"><?= $company_count ?></p>
                        </div>
                    </div>
                </a>
                <!-- Pending Password Card → Manage Users tab (filtered to pending users) -->
                <a href="?tab=manage" class="block bg-white rounded-2xl border border-slate-200 shadow-sm p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:bg-amber-50/80 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">⏳</div>
                        <div>
                            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Pending P.W.</p>
                            <p class="text-sm font-black text-slate-800"><?= $pending_count ?></p>
                        </div>
                    </div>
                </a>
            </div>

            <?php if ($tab === 'overview'): ?>
            <!-- ════ TAB: OVERVIEW ════ -->
            
            <!-- Quick Actions Panel -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-blue-50 text-blue-600 rounded">⚡</span> Quick Actions
                    </h2>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        <a href="?tab=students" class="flex flex-col items-center gap-2 p-4 bg-indigo-50 hover:bg-indigo-100 rounded-xl transition cursor-pointer group">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg group-hover:scale-110 transition">🎓</div>
                            <span class="text-sm font-bold text-indigo-600 text-center">Add Student</span>
                        </a>
                        <a href="?tab=supervisors" class="flex flex-col items-center gap-2 p-4 bg-emerald-50 hover:bg-emerald-100 rounded-xl transition cursor-pointer group">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg group-hover:scale-110 transition">👨‍🏫</div>
                            <span class="text-sm font-bold text-emerald-600 text-center">Add Supervisor</span>
                        </a>
                        <a href="manage-companies.php" class="flex flex-col items-center gap-2 p-4 bg-blue-50 hover:bg-blue-100 rounded-xl transition cursor-pointer group">
                            <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg group-hover:scale-110 transition">🏢</div>
                            <span class="text-sm font-bold text-blue-600 text-center">Manage Companies</span>
                        </a>
                        <a href="create-announcement.php" class="flex flex-col items-center gap-2 p-4 bg-amber-50 hover:bg-amber-100 rounded-xl transition cursor-pointer group">
                            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg group-hover:scale-110 transition">📢</div>
                            <span class="text-sm font-bold text-amber-600 text-center">Post Announcement</span>
                        </a>
                        <a href="?tab=archive" class="flex flex-col items-center gap-2 p-4 bg-purple-50 hover:bg-purple-100 rounded-xl transition cursor-pointer group">
                            <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-lg group-hover:scale-110 transition">📦</div>
                            <span class="text-sm font-bold text-purple-600 text-center">Batch Archive</span>
                        </a>
                        <a href="?tab=history" class="flex flex-col items-center gap-2 p-4 bg-rose-50 hover:bg-rose-100 rounded-xl transition cursor-pointer group">
                            <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center text-lg group-hover:scale-110 transition">📜</div>
                            <span class="text-sm font-bold text-rose-600 text-center">Student History</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6">

                <!-- Recent Students -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider">Recent Students</h2>
                        <a href="?tab=manage" class="text-sm font-bold text-indigo-600 hover:underline">View All →</a>
                    </div>
                    <div class="divide-y divide-slate-100 max-h-64 overflow-y-auto">
                        <?php foreach (array_slice($students, 0, 5) as $s): ?>
                        <div class="px-4 py-3 flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <?= strtoupper(($s['full_name'] ?: $s['username'])[0]) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-slate-700 truncate"><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></p>
                                <p class="text-sm text-slate-400"><?= htmlspecialchars($s['company_name'] ?: 'No company') ?></p>
                            </div>
                            <span class="text-sm text-slate-400 shrink-0"><?= htmlspecialchars($s['student_roll'] ?: '') ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                        <div class="p-6 text-center text-xs text-slate-400">No students yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <?php elseif ($tab === 'students'): ?>
            <!-- ════ TAB: ADD STUDENT ════ -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🎓</span> Register New Student
                    </h2>
                </div>
                <form method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="add_student" value="1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Full Name *</label>
                            <input type="text" name="s_name" required placeholder="e.g. Aung Kyaw" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Roll Number *</label>
                            <input type="text" name="s_roll" required placeholder="e.g. CS-2022-045" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Major / Department</label>
                            <input type="text" name="s_major" placeholder="e.g. Computer Science" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Email *</label>
                            <input type="email" name="s_email" required placeholder="student@example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Company <span class="text-slate-300 font-normal">(ကုမ္ပဏီ)</span></label>
                            <select name="s_company_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Company —</option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Company Instructor</label>
                            <input type="text" name="s_instructor" placeholder="e.g. U Tin Aung" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Supervisor <span class="text-slate-300 font-normal">(ကျောင်းကဆရာ/မ)</span></label>
                            <select name="s_supervisor_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Supervisor —</option>
                                <?php foreach ($supervisors as $sup): ?>
                                <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['username']) ?> (<?= htmlspecialchars($sup['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                            <select name="s_academic_year" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Year —</option>
                                <option value="2023-2024">2023-2024</option>
                                <option value="2024-2025" selected>2024-2025</option>
                                <option value="2025-2026">2025-2026</option>
                                <option value="2026-2027">2026-2027</option>
                                <option value="2027-2028">2027-2028</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Internship Start Date</label>
                            <input type="date" name="s_start_date" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Internship End Date</label>
                            <input type="date" name="s_end_date" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Default Password *</label>
                            <input type="text" name="s_password" required value="<?= htmlspecialchars($def_student_pw) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 font-mono focus:outline-none focus:border-blue-500 transition">
                            <p class="text-sm text-slate-400 mt-0.5">Must change on first login.</p>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">🎓 Create Student</button>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'supervisors'): ?>
            <!-- ════ TAB: ADD SUPERVISOR ════ -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-emerald-50 text-emerald-600 rounded">👨‍🏫</span> Register New Supervisor
                    </h2>
                </div>
                <form method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="add_supervisor" value="1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Teacher Name *</label>
                            <input type="text" name="t_name" required placeholder="e.g. Dr. Myint Thein" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Department</label>
                            <input type="text" name="t_dept" placeholder="e.g. Computer Science" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Email *</label>
                            <input type="email" name="t_email" required placeholder="supervisor@example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                            <select name="t_academic_year" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Year —</option>
                                <option value="2023-2024">2023-2024</option>
                                <option value="2024-2025">2024-2025</option>
                                <option value="2025-2026" selected>2025-2026</option>
                                <option value="2026-2027">2026-2027</option>
                                <option value="2027-2028">2027-2028</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Default Password *</label>
                            <input type="text" name="t_password" required value="<?= htmlspecialchars($def_supervisor_pw) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 font-mono focus:outline-none focus:border-blue-500 transition">
                            <p class="text-sm text-slate-400 mt-0.5">Must change on first login.</p>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">👨‍🏫 Create Supervisor</button>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'manage'): ?>
            <!-- ════ TAB: MANAGE USERS ════ -->

            <!-- All Users -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-slate-100 text-slate-600 rounded">👥</span> All Users
                    </h2>
                    <div class="flex items-center gap-2">
                        <a href="?tab=manage" class="px-3 py-1.5 text-sm font-bold rounded-lg transition <?= $filter_role === '' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">All</a>
                        <a href="?tab=manage&role=admin" class="px-3 py-1.5 text-sm font-bold rounded-lg transition <?= $filter_role === 'admin' ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-600 hover:bg-amber-100' ?>">Admin</a>
                        <a href="?tab=manage&role=supervisor" class="px-3 py-1.5 text-sm font-bold rounded-lg transition <?= $filter_role === 'supervisor' ? 'bg-emerald-500 text-white' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' ?>">Supervisor</a>
                        <a href="?tab=manage&role=student" class="px-3 py-1.5 text-sm font-bold rounded-lg transition <?= $filter_role === 'student' ? 'bg-indigo-500 text-white' : 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100' ?>">Student</a>
                        <span class="text-sm text-slate-400 ml-1"><?= count($all_users) ?> total</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                <th class="px-3 py-2.5 text-left">User</th>
                                <th class="px-3 py-2.5 text-left">Role</th>
                                <th class="px-3 py-2.5 text-left">Year</th>
                                <th class="px-3 py-2.5 text-left">Status</th>
                                <th class="px-3 py-2.5 text-left">Created</th>
                                <th class="px-3 py-2.5 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($all_users as $u): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold shrink-0
                                            <?= $u['role'] === 'admin' ? 'bg-amber-100 text-amber-600' : ($u['role'] === 'supervisor' ? 'bg-emerald-100 text-emerald-600' : 'bg-indigo-100 text-indigo-600') ?>">
                                            <?= strtoupper(($u['full_name'] ?? $u['username'])[0]) ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-slate-700"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></p>
                                            <p class="text-sm text-slate-400"><?= htmlspecialchars($u['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <?php
                                    $rs = ['admin'=>['Admin','text-amber-600','bg-amber-50'], 'supervisor'=>['Supervisor','text-emerald-600','bg-emerald-50'], 'student'=>['Student','text-indigo-600','bg-indigo-50']];
                                    $r = $rs[$u['role']] ?? ['Unknown','text-slate-600','bg-slate-100'];
                                    ?>
                                    <a href="?tab=manage&role=<?= $u['role'] ?>" class="inline-block text-sm font-bold <?= $r[1] ?> <?= $r[2] ?> px-2 py-0.5 rounded capitalize hover:opacity-80 transition"><?= $r[0] ?></a>
                                </td>
                                <td class="px-3 py-2.5">
                                    <?php if (!empty($u['academic_year'])): ?>
                                        <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($u['academic_year']) ?></span>
                                    <?php else: ?>
                                        <span class="text-sm text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5">
                                    <?= $u['is_first_login'] ? '<span class="text-sm font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">⏳ Pending</span>' : '<span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ Active</span>' ?>
                                    <?php if (($u['status'] ?? 'Active') === 'Archived'): ?>
                                        <span class="text-sm font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1">📦</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5 text-slate-400 whitespace-nowrap"><?= (new DateTime($u['created_at']))->format('d M Y') ?></td>
                                <td class="px-3 py-2.5">
                                    <?php if ($u['role'] !== 'admin'): ?>
                                    <div class="flex items-center gap-1.5">
                                        <form method="POST" onsubmit="return confirm('Reset password for <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>?\nNew password will be: <?= $u['role'] === 'supervisor' ? htmlspecialchars($def_supervisor_pw) : htmlspecialchars($def_student_pw) ?>')" class="inline">
                                            <input type="hidden" name="reset_password" value="1">
                                            <input type="hidden" name="reset_uid" value="<?= $u['id'] ?>">
                                            <button type="submit" class="px-2 py-1 bg-amber-50 text-amber-600 text-sm font-bold rounded-lg hover:bg-amber-100 transition cursor-pointer" title="Reset to default password">🔑</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Delete this user?')" class="inline">
                                            <input type="hidden" name="delete_user" value="1">
                                            <input type="hidden" name="delete_uid" value="<?= $u['id'] ?>">
                                            <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 text-sm font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($tab === 'archive'): ?>
            <!-- ════ TAB: BATCH ARCHIVE ════ -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-lg font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-amber-50 text-amber-600 rounded">📦</span> Batch Archive
                    </h2>
                </div>
                <form method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="archive_batch" value="1">
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Archive all students from a specific academic year. Archived students will no longer appear in active lists.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                            <select name="batch_year" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Year —</option>
                                <option value="2023-2024">2023-2024</option>
                                <option value="2024-2025">2024-2025</option>
                                <option value="2025-2026">2025-2026</option>
                                <option value="2026-2027">2026-2027</option>
                                <option value="2027-2028">2027-2028</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" onclick="return confirm('Archive all students from this batch?')" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">📦 Archive Batch</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Archived Students Summary -->
            <?php
            $archived = $pdo->query("SELECT academic_year, COUNT(*) AS cnt FROM users WHERE role = 'student' AND status = 'Archived' AND academic_year IS NOT NULL GROUP BY academic_year ORDER BY academic_year DESC")->fetchAll();
            ?>
            <?php if (!empty($archived)): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-xl font-black text-slate-700 uppercase tracking-wider">Archived Batches</h2>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <?php foreach ($archived as $ar): ?>
                        <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center">
                            <p class="text-sm font-bold text-amber-600 uppercase tracking-wider mb-0.5">📦 <?= htmlspecialchars($ar['academic_year']) ?></p>
                            <p class="text-sm font-black text-amber-700"><?= $ar['cnt'] ?></p>
                            <p class="text-sm text-amber-400">student(s) archived</p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'history'): ?>
            <!-- ════ TAB: STUDENT HISTORY ════ -->
            <?php
            $hist_year = $_GET['academic_year'] ?? '';
            $hist_years = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE academic_year IS NOT NULL AND academic_year != '' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);

            $hist_sql = "
                SELECT u.id AS uid, u.username, u.email, u.academic_year, u.status, u.created_at,
                       sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
                       sp.instructor_name, sp.supervisor_id,
                       sup_u.username AS supervisor_name
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
                WHERE u.role = 'student'
            ";
            $hist_params = [];
            if ($hist_year && preg_match('/^\d{4}-\d{4}$/', $hist_year)) {
                $hist_sql .= " AND u.academic_year = ?";
                $hist_params[] = $hist_year;
            }
            $hist_sql .= " ORDER BY sp.full_name ASC, u.username ASC";
            $hist_stmt = $pdo->prepare($hist_sql);
            $hist_stmt->execute($hist_params);
            $hist_students = $hist_stmt->fetchAll();

            // Fetch latest grade for each student
            $hist_grades = [];
            foreach ($hist_students as $hs) {
                $gq = $pdo->prepare("SELECT grade FROM report_evaluations WHERE student_id = ? ORDER BY evaluated_at DESC LIMIT 1");
                $gq->execute([$hs['uid']]);
                $hist_grades[$hs['uid']] = $gq->fetchColumn() ?: null;
            }
            ?>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-lg font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-purple-50 text-purple-600 rounded">📜</span> Student History
                        <?php if ($hist_year): ?>
                            <span class="text-indigo-600 font-mono">— <?= htmlspecialchars($hist_year) ?></span>
                        <?php endif; ?>
                    </h2>
                    <form method="GET" class="flex items-center gap-2">
                        <input type="hidden" name="tab" value="history">
                        <select name="academic_year" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-slate-700 font-semibold focus:outline-none focus:border-blue-500 transition cursor-pointer">
                            <option value="">All Academic Years</option>
                            <?php foreach ($hist_years as $hy): ?>
                            <option value="<?= htmlspecialchars($hy) ?>" <?= $hist_year === $hy ? 'selected' : '' ?>><?= htmlspecialchars($hy) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($hist_year): ?>
                        <a href="?tab=history" class="px-2 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-bold rounded-lg transition">✕ Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (!empty($hist_students)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                <th class="px-3 py-2.5 text-left">Roll No</th>
                                <th class="px-3 py-2.5 text-left">Student Name</th>
                                <th class="px-3 py-2.5 text-left">Job Role</th>
                                <th class="px-3 py-2.5 text-left">Company</th>
                                <th class="px-3 py-2.5 text-left">Supervisor</th>
                                <th class="px-3 py-2.5 text-left">Year</th>
                                <th class="px-3 py-2.5 text-left">Final Grade</th>
                                <th class="px-3 py-2.5 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($hist_students as $hs): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2.5 font-mono font-semibold text-slate-700"><?= htmlspecialchars($hs['student_roll'] ?: '—') ?></td>
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm font-bold shrink-0">
                                            <?= strtoupper(($hs['full_name'] ?: $hs['username'])[0]) ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-slate-700"><?= htmlspecialchars($hs['full_name'] ?: $hs['username']) ?></p>
                                            <p class="text-sm text-slate-400"><?= htmlspecialchars($hs['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[120px] truncate" title="<?= htmlspecialchars($hs['job_role'] ?? '') ?>"><?= htmlspecialchars($hs['job_role'] ?: '—') ?></td>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[130px] truncate" title="<?= htmlspecialchars($hs['company_name'] ?? '') ?>"><?= htmlspecialchars($hs['company_name'] ?: '—') ?></td>
                                <td class="px-3 py-2.5 text-slate-500"><?= htmlspecialchars($hs['supervisor_name'] ?: 'Unassigned') ?></td>
                                <td class="px-3 py-2.5">
                                    <?php if ($hs['academic_year']): ?>
                                        <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($hs['academic_year']) ?></span>
                                    <?php else: ?>
                                        <span class="text-sm text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5">
                                    <?php
                                    $grade_map = [
                                        'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
                                        'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
                                        'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
                                        'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
                                    ];
                                    $gv = $hist_grades[$hs['uid']] ?? null;
                                    $gs = $gv ? ($grade_map[$gv] ?? ['—', 'text-slate-400', 'bg-slate-50']) : ['—', 'text-slate-400', 'bg-slate-50'];
                                    ?>
                                    <span class="text-sm font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <a href="../view_student_history.php?uid=<?= $hs['uid'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-50 text-purple-600 text-sm font-bold rounded-lg hover:bg-purple-100 transition">
                                        👁️ View History
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-2.5 border-t border-slate-100 bg-slate-50">
                    <p class="text-sm text-slate-400">Showing <?= count($hist_students) ?> student(s) <?= $hist_year ? 'for ' . htmlspecialchars($hist_year) : 'across all years' ?></p>
                </div>
                <?php else: ?>
                <div class="p-8 text-center text-sm text-slate-400">
                    <?php if ($hist_year): ?>
                        No students found for academic year <?= htmlspecialchars($hist_year) ?>.
                    <?php else: ?>
                        No students registered yet.
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'holidays'): ?>
            <!-- ════ TAB: MYANMAR HOLIDAYS ════ -->

            <!-- Add Holiday Form -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-red-50 text-red-600 rounded">🇲🇲</span> Add Public Holiday
                    </h2>
                </div>
                <form method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="add_holiday" value="1">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Holiday Date *</label>
                            <input type="date" name="h_date" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Holiday Name (Myanmar)</label>
                            <input type="text" name="h_name_mm" placeholder="e.g. အာဇာနည်နေ့" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Note</label>
                            <input type="text" name="h_note" placeholder="Optional note" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-5 py-2 bg-red-500 hover:bg-red-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">🇲🇲 Add Holiday</button>
                    </div>
                </form>
            </div>

            <!-- Existing Holidays -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-red-50 text-red-600 rounded">🇲🇲</span> Myanmar Public Holidays
                    </h2>
                    <span class="text-sm text-slate-400"><?= count($holidays) ?> total</span>
                </div>
                <?php if (!empty($holidays)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                <th class="px-3 py-2.5 text-left">Date</th>
                                <th class="px-3 py-2.5 text-left">Day</th>
                                <th class="px-3 py-2.5 text-left">Myanmar Name</th>
                                <th class="px-3 py-2.5 text-left">Note</th>
                                <th class="px-3 py-2.5 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($holidays as $h): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2.5 font-mono font-semibold text-slate-700"><?= htmlspecialchars((new DateTime($h['holiday_date']))->format('d M Y')) ?></td>
                                <td class="px-3 py-2.5 text-slate-500"><?= htmlspecialchars((new DateTime($h['holiday_date']))->format('l')) ?></td>
                                <td class="px-3 py-2.5 text-slate-500"><?= htmlspecialchars($h['holiday_name_mm'] ?: '—') ?></td>
                                <td class="px-3 py-2.5 text-slate-400 text-xs"><?= htmlspecialchars($h['note'] ?: '—') ?></td>
                                <td class="px-3 py-2.5">
                                    <form method="POST" onsubmit="return confirm('Delete holiday: <?= htmlspecialchars($h['holiday_name']) ?> on <?= htmlspecialchars($h['holiday_date']) ?>?')" class="inline">
                                        <input type="hidden" name="delete_holiday" value="1">
                                        <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                                        <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 text-sm font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-8 text-center text-sm text-slate-400">
                    No holidays configured yet. Add Myanmar public holidays above.
                </div>
                <?php endif; ?>
            </div>

            <!-- Info Note -->
            <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                <h3 class="text-sm font-bold text-slate-700 mb-2">ℹ️ Notes</h3>
                <ul class="text-sm text-slate-500 space-y-1">
                    <li>• Holidays set here will be visible to all students during their intern period.</li>
                    <li>• Holiday dates will be marked as <strong>"leave"</strong> (Public Holiday) in student daily logs.</li>
                    <li>• Students will see these holidays highlighted in their calendar and log form.</li>
                    <li>• Holiday dates based on the official Myanmar calendar for 2026.</li>
                </ul>
            </div>

            <?php endif; ?>
            </div>
        </main>
    </div>
</div>

</body>
</html>
