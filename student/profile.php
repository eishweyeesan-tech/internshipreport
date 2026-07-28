<?php
require_once __DIR__ . '/../auth.php';

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}

// ─── Database Connection (mysqli) ─────────────────────────────────────
$conn = new mysqli('localhost', 'root', 'root', 'intern_report_db');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

$esc_uid = $conn->real_escape_string($user_id);

// ── Fetch or create profile row ──────────────────────────────────
$profile_r = $conn->query("SELECT * FROM student_profiles WHERE user_id = {$esc_uid}");
$profile = $profile_r ? $profile_r->fetch_assoc() : null;

if (!$profile) {
    $esc_un = $conn->real_escape_string($username);
    $conn->query("INSERT INTO student_profiles (user_id, full_name) VALUES ({$esc_uid}, '{$esc_un}')");
    $profile_r = $conn->query("SELECT * FROM student_profiles WHERE user_id = {$esc_uid}");
    $profile = $profile_r ? $profile_r->fetch_assoc() : null;
}

// ── Fetch user data ─────────────────────────────────────────────
$user_email    = '';
$profile_pic   = '';
$github_link   = '';
$linkedin_link = '';
$portfolio_link = '';
$last_login_at = '';

$user_r = $conn->query("SELECT email, profile_pic, github_link, linkedin_link, portfolio_link, last_login_at FROM users WHERE id = {$esc_uid}");
if ($user_r && $user_r->num_rows > 0) {
    $user_data = $user_r->fetch_assoc();
    $user_email     = $user_data['email'];
    $profile_pic    = $user_data['profile_pic'] ?? '';
    $github_link    = $user_data['github_link'] ?? '';
    $linkedin_link  = $user_data['linkedin_link'] ?? '';
    $portfolio_link = $user_data['portfolio_link'] ?? '';
    $last_login_at  = $user_data['last_login_at'] ?? '';
} else {
    $email_r = $conn->query("SELECT email FROM users WHERE id = {$esc_uid}");
    $user_email = ($email_r && $email_r->num_rows > 0) ? $email_r->fetch_row()[0] : '';
}

// ── Handle Profile Update ────────────────────────────────────────
$profile_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name         = trim($_POST['full_name'] ?? '');
    $student_roll      = trim($_POST['student_roll'] ?? '');
    $major             = trim($_POST['major'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $company_name      = trim($_POST['company_name'] ?? '');
    $job_role          = trim($_POST['job_role'] ?? '');
    $instructor_name   = trim($_POST['instructor_name'] ?? '');
    $instructor_email  = trim($_POST['instructor_email'] ?? '');
    $instructor_phone  = trim($_POST['instructor_phone'] ?? '');
    $internship_start  = trim($_POST['internship_start_date'] ?? '');

    $esc_fn  = $conn->real_escape_string($full_name);
    $esc_sr  = $conn->real_escape_string($student_roll);
    $esc_mj  = $conn->real_escape_string($major);
    $esc_ph  = $conn->real_escape_string($phone);
    $esc_cn  = $conn->real_escape_string($company_name);
    $esc_jr  = $conn->real_escape_string($job_role);
    $esc_in  = $conn->real_escape_string($instructor_name);
    $esc_ie  = $conn->real_escape_string($instructor_email);
    $esc_ip  = $conn->real_escape_string($instructor_phone);
    $esc_is  = $conn->real_escape_string($internship_start ?: '');

    $conn->query("UPDATE student_profiles SET
        full_name = '{$esc_fn}', student_roll = '{$esc_sr}', major = '{$esc_mj}', phone = '{$esc_ph}',
        company_name = '{$esc_cn}', job_role = '{$esc_jr}', instructor_name = '{$esc_in}',
        instructor_email = '{$esc_ie}', instructor_phone = '{$esc_ip}', internship_start_date = " . ($internship_start ? "'{$esc_is}'" : "NULL") . "
        WHERE user_id = {$esc_uid}");

    // Refresh profile data
    $profile_r = $conn->query("SELECT * FROM student_profiles WHERE user_id = {$esc_uid}");
    $profile = $profile_r ? $profile_r->fetch_assoc() : null;
    $profile_msg = 'saved';
}

// ── Handle Password Change ───────────────────────────────────────
$pw_msg = '';
$pw_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new_pw) || empty($confirm)) {
        $pw_err = 'All password fields are required.';
    } elseif (strlen($new_pw) < 8) {
        $pw_err = 'New password must be at least 8 characters.';
    } elseif ($new_pw !== $confirm) {
        $pw_err = 'New passwords do not match.';
    } else {
        $check_r = $conn->query("SELECT password FROM users WHERE id = {$esc_uid}");
        $hash = ($check_r && $check_r->num_rows > 0) ? $check_r->fetch_row()[0] : '';

        if (!password_verify($current, $hash)) {
            $pw_err = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $esc_nw = $conn->real_escape_string($new_hash);
            $conn->query("UPDATE users SET password = '{$esc_nw}', is_first_login = 0 WHERE id = {$esc_uid}");
            $_SESSION['is_first_login'] = false;
            $pw_msg = 'Password updated successfully.';
        }
    }
}

// ── Handle Avatar Upload ─────────────────────────────────────────
$avatar_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $avatar_msg = 'Only JPG, JPEG, and PNG files are allowed.';
        } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $avatar_msg = 'File size must be less than 2MB.';
        } else {
            $upload_dir = __DIR__ . '/../uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $filepath)) {
                if ($profile_pic) {
                    $old_path = $upload_dir . $profile_pic;
                    if (file_exists($old_path)) unlink($old_path);
                }
                $esc_fn2 = $conn->real_escape_string($filename);
                $conn->query("UPDATE users SET profile_pic = '{$esc_fn2}' WHERE id = {$esc_uid}");
                $profile_pic = $filename;
                $avatar_msg = 'Avatar updated successfully.';
            } else {
                $avatar_msg = 'Failed to upload avatar.';
            }
        }
    }
}

// ── Handle Portfolio Links ───────────────────────────────────────
$portfolio_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_portfolio'])) {
    $github   = trim($_POST['github_link'] ?? '');
    $linkedin = trim($_POST['linkedin_link'] ?? '');
    $portfolio = trim($_POST['portfolio_link'] ?? '');

    $esc_gh = $conn->real_escape_string($github);
    $esc_li = $conn->real_escape_string($linkedin);
    $esc_pl = $conn->real_escape_string($portfolio);
    $conn->query("UPDATE users SET github_link = '{$esc_gh}', linkedin_link = '{$esc_li}', portfolio_link = '{$esc_pl}' WHERE id = {$esc_uid}");
    $github_link    = $github;
    $linkedin_link  = $linkedin;
    $portfolio_link = $portfolio;
    $portfolio_msg  = 'Portfolio links updated successfully.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile – InternReport</title>
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
    .active-nav { background: rgba(255,255,255,0.15); color: #fff; border-right: 3px solid #a78bfa; }
    .glass { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); }
    .glass-strong { background: rgba(255,255,255,0.72); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.5); }
    .glass-sidebar { background: rgba(15,23,42,0.82); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-right: 1px solid rgba(255,255,255,0.08); }
    .glass-header { background: rgba(255,255,255,0.6); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid rgba(255,255,255,0.4); }
    .glass-card { background: rgba(255,255,255,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.45); box-shadow: 0 8px 32px rgba(0,0,0,0.06); }
    @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
    .animated-bg { background: linear-gradient(-45deg, #e0e7ff, #ede9fe, #fce7f3, #dbeafe, #d1fae5); background-size: 400% 400%; animation: gradientShift 20s ease infinite; }
    @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } .glass, .glass-card, .glass-strong, .glass-header, .glass-sidebar { background: white !important; backdrop-filter: none !important; border-color: #e2e8f0 !important; box-shadow: none !important; } }
    </style>
    <script>
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
    </script>
    <script>
    function toggleEdit(section) {
        var card = document.getElementById('card-' + section);
        var view = card.querySelector('.view-mode');
        var edit = card.querySelector('.edit-mode');
        var btn  = card.querySelector('.edit-toggle');
        if (edit.classList.contains('hidden')) {
            view.classList.add('hidden');
            edit.classList.remove('hidden');
            btn.textContent = '✕ Cancel';
            btn.classList.remove('bg-slate-800', 'hover:bg-slate-900');
            btn.classList.add('bg-slate-200', 'hover:bg-slate-300', 'text-slate-700');
        } else {
            edit.classList.add('hidden');
            view.classList.remove('hidden');
            btn.textContent = '✏️ Edit';
            btn.classList.remove('bg-slate-200', 'hover:bg-slate-300', 'text-slate-700');
            btn.classList.add('bg-slate-800', 'hover:bg-slate-900');
        }
    }
    </script>
</head>
<body class="animated-bg font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 glass-sidebar flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-white/10">
            <span class="text-sm font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="student-dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📝</span> Dashboard
            </a>
            <a href="student-dashboard.php?section=analytics" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📊</span> Analytics
            </a>
            <a href="log-history.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📅</span> Public Holidays
            </a>
            <a href="instructions.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📋</span> Instructions
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-14 bg-white border-b border-slate-200 flex items-center justify-between px-6 shrink-0 relative z-50">
            <h1 class="text-sm font-bold text-slate-700">My Profile</h1>
            <div class="flex items-center gap-2 text-xs text-slate-400">
                <span class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-bold"><?= strtoupper($username[0]) ?></span>
                <?= htmlspecialchars($username) ?>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">
            <div class="max-w-3xl mx-auto space-y-6">

                <?php if ($profile_msg === 'saved'): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> Profile updated successfully.
                </div>
                <?php endif; ?>

                <?php if ($pw_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($pw_msg) ?>
                </div>
                <?php endif; ?>

                <?php if ($pw_err): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>❌</span> <?= htmlspecialchars($pw_err) ?>
                </div>
                <?php endif; ?>

                <!-- ════ AVATAR HEADER ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex items-center gap-5">
                    <div class="relative w-16 h-16 shrink-0">
                        <?php if ($profile_pic): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-16 h-16 rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xl font-bold">
                                <?= strtoupper(($profile['full_name'] ?: $username)[0]) ?>
                            </div>
                        <?php endif; ?>
                        <div class="absolute -bottom-0.5 -right-0.5 w-5 h-5 bg-slate-100 rounded-full flex items-center justify-center border border-slate-200" title="Change avatar">
                            <svg class="w-2.5 h-2.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-sm font-black text-slate-800"><?= htmlspecialchars($profile['full_name'] ?: $username) ?></h2>
                        <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($user_email) ?></p>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded capitalize"><?= htmlspecialchars($role) ?></span>
                            <?php if ($profile['student_roll']): ?>
                            <span class="text-sm font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($profile['student_roll']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($avatar_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($avatar_msg) ?>
                </div>
                <?php endif; ?>

                <?php if ($portfolio_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($portfolio_msg) ?>
                </div>
                <?php endif; ?>

                <!-- ════ PROFILE PICTURE UPLOAD ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-violet-50 text-violet-600 rounded">📷</span> Profile Picture
                        </h3>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="p-5">
                        <input type="hidden" name="update_avatar" value="1">
                        <div class="flex items-center gap-5">
                            <?php if ($profile_pic): ?>
                                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Current avatar" class="w-14 h-14 rounded-full object-cover border border-slate-200">
                            <?php else: ?>
                                <div class="w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-bold border border-slate-200">
                                    <?= strtoupper($username[0]) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <label class="block text-sm font-bold text-slate-500 mb-1">Upload New Picture</label>
                                <input type="file" name="avatar" accept="image/jpeg,image/png" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100 file:cursor-pointer">
                                <p class="text-sm text-slate-400 mt-1">JPG, JPEG, or PNG. Max 2MB.</p>
                            </div>
                        </div>
                        <div class="flex justify-end pt-3">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">📷 Upload Picture</button>
                        </div>
                    </form>
                </div>

                <!-- ════ DEVELOPER PORTFOLIO LINKS ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-cyan-50 text-cyan-600 rounded">🔗</span> Developer Portfolio Links
                        </h3>
                    </div>
                    <form method="POST" class="p-5 space-y-4">
                        <input type="hidden" name="update_portfolio" value="1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">GitHub Link</label>
                                <input type="url" name="github_link" value="<?= htmlspecialchars($github_link) ?>" placeholder="https://github.com/username" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">LinkedIn Link</label>
                                <input type="url" name="linkedin_link" value="<?= htmlspecialchars($linkedin_link) ?>" placeholder="https://linkedin.com/in/username" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-bold text-slate-500 mb-1">Personal Portfolio Website Link</label>
                                <input type="url" name="portfolio_link" value="<?= htmlspecialchars($portfolio_link) ?>" placeholder="https://yourportfolio.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                        </div>
                        <div class="flex justify-end pt-1">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Links</button>
                        </div>
                    </form>
                </div>

                <!-- ════ SECURITY & LAST LOGIN ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-emerald-50 text-emerald-600 rounded">🛡️</span> Security & Last Login
                        </h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                                <span class="w-2.5 h-2.5 bg-emerald-500 rounded-full shrink-0"></span>
                                <div>
                                    <p class="text-sm font-bold text-slate-500 uppercase">Account Status</p>
                                    <p class="text-xs font-semibold text-emerald-600">Active</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                                <span class="w-2.5 h-2.5 bg-blue-500 rounded-full shrink-0"></span>
                                <div>
                                    <p class="text-sm font-bold text-slate-500 uppercase">Last Login Detected</p>
                                    <?php if ($last_login_at): ?>
                                        <p class="text-xs font-semibold text-slate-700"><?= date('d M Y, h:i A', strtotime($last_login_at)) ?></p>
                                    <?php else: ?>
                                        <p class="text-xs font-semibold text-slate-400">No login recorded yet</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════ PERSONAL INFO ════ -->
                <div id="card-personal" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-blue-50 text-blue-600 rounded">👤</span> Personal Information
                        </h3>
                        <button type="button" onclick="toggleEdit('personal')" class="edit-toggle px-3 py-1 bg-slate-800 hover:bg-slate-900 text-white text-sm font-bold rounded-lg transition cursor-pointer">✏️ Edit</button>
                    </div>

                    <!-- View Mode -->
                    <div class="view-mode p-5">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Full Name</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['full_name'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Student Roll No</dt>
                                <dd class="text-xs text-slate-700 font-semibold font-mono"><?= htmlspecialchars($profile['student_roll'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Major / Department</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['major'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Phone</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['phone'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Email</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($user_email) ?></dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Edit Mode -->
                    <form method="POST" class="edit-mode hidden">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="p-5 space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Full Name</label>
                                    <input type="text" name="full_name" value="<?= htmlspecialchars($profile['full_name']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Student Roll No</label>
                                    <input type="text" name="student_roll" value="<?= htmlspecialchars($profile['student_roll']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Major / Department</label>
                                    <input type="text" name="major" value="<?= htmlspecialchars($profile['major']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Phone</label>
                                    <input type="text" name="phone" value="<?= htmlspecialchars($profile['phone']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                            </div>
                            <!-- Hidden fields to preserve internship data on save -->
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($profile['company_name']) ?>">
                            <input type="hidden" name="job_role" value="<?= htmlspecialchars($profile['job_role']) ?>">
                            <input type="hidden" name="instructor_name" value="<?= htmlspecialchars($profile['instructor_name']) ?>">
                            <input type="hidden" name="instructor_email" value="<?= htmlspecialchars($profile['instructor_email']) ?>">
                            <input type="hidden" name="instructor_phone" value="<?= htmlspecialchars($profile['instructor_phone']) ?>">
                            <input type="hidden" name="internship_start_date" value="<?= htmlspecialchars($profile['internship_start_date']) ?>">
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ════ INTERNSHIP DETAILS ════ -->
                <div id="card-internship" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-emerald-50 text-emerald-600 rounded">🏢</span> Internship Details
                        </h3>
                        <button type="button" onclick="toggleEdit('internship')" class="edit-toggle px-3 py-1 bg-slate-800 hover:bg-slate-900 text-white text-sm font-bold rounded-lg transition cursor-pointer">✏️ Edit</button>
                    </div>

                    <!-- View Mode -->
                    <div class="view-mode p-5">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Company Name</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['company_name'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Job Role</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['job_role'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Instructor Name</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['instructor_name'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Instructor Email</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['instructor_email'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Instructor Phone</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['instructor_phone'] ?: '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Internship Start Date</dt>
                                <dd class="text-xs text-slate-700 font-semibold font-mono">
                                    <?php if ($profile['internship_start_date']): ?>
                                        <?= (new DateTime($profile['internship_start_date']))->format('d M Y') ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Edit Mode -->
                    <form method="POST" class="edit-mode hidden">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="p-5 space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Company Name</label>
                                    <input type="text" name="company_name" value="<?= htmlspecialchars($profile['company_name']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Job Role</label>
                                    <input type="text" name="job_role" value="<?= htmlspecialchars($profile['job_role']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Instructor Name</label>
                                    <input type="text" name="instructor_name" value="<?= htmlspecialchars($profile['instructor_name']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Instructor Email</label>
                                    <input type="email" name="instructor_email" value="<?= htmlspecialchars($profile['instructor_email']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Instructor Phone</label>
                                    <input type="text" name="instructor_phone" value="<?= htmlspecialchars($profile['instructor_phone']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-500 mb-1">Internship Start Date</label>
                                    <input type="date" name="internship_start_date" value="<?= htmlspecialchars($profile['internship_start_date']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                            </div>
                            <!-- Hidden fields to preserve personal data on save -->
                            <input type="hidden" name="full_name" value="<?= htmlspecialchars($profile['full_name']) ?>">
                            <input type="hidden" name="student_roll" value="<?= htmlspecialchars($profile['student_roll']) ?>">
                            <input type="hidden" name="major" value="<?= htmlspecialchars($profile['major']) ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($profile['phone']) ?>">
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ════ CHANGE PASSWORD ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-amber-50 text-amber-600 rounded">🔑</span> Change Password
                        </h3>
                    </div>
                    <form method="POST" class="p-5 space-y-4">
                        <input type="hidden" name="change_password" value="1">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Current Password</label>
                                <input type="password" name="current_password" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">New Password</label>
                                <input type="password" name="new_password" required minlength="8" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <p class="text-sm text-slate-400 mt-0.5">Min 8 characters</p>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Confirm New Password</label>
                                <input type="password" name="confirm_password" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">🔒 Update Password</button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- Dark Mode Toggle -->
<div class="fixed bottom-6 right-6 z-50">
    <button id="darkToggle" onclick="toggleDarkMode()" class="flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 shadow-lg rounded-full text-xs font-semibold text-slate-600 hover:bg-slate-50 transition cursor-pointer">
        <svg id="sunIcon" class="w-4 h-4 text-amber-500 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
        <svg id="moonIcon" class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
        <span id="toggleLabel">Dark Mode</span>
    </button>
</div>

<script>
function toggleDarkMode() {
    var isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateToggleUI(isDark);
}
function updateToggleUI(isDark) {
    document.getElementById('sunIcon').classList.toggle('hidden', !isDark);
    document.getElementById('moonIcon').classList.toggle('hidden', isDark);
    document.getElementById('toggleLabel').textContent = isDark ? 'Light Mode' : 'Dark Mode';
}
document.addEventListener('DOMContentLoaded', function() {
    updateToggleUI(document.documentElement.classList.contains('dark'));
});
</script>

</body>
</html>
