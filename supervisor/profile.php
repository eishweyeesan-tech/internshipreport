<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$msg = '';
$err = '';

// Fetch current info
$profile_pic   = '';
$github_link   = '';
$linkedin_link = '';
$portfolio_link = '';
$last_login_at = '';

try {
    $sup = $pdo->prepare("SELECT id, username, email, role, profile_pic, github_link, linkedin_link, portfolio_link, last_login_at FROM users WHERE id = ?");
    $sup->execute([$sup_id]);
    $sup = $sup->fetch();
    $profile_pic   = $sup['profile_pic'] ?? '';
    $github_link   = $sup['github_link'] ?? '';
    $linkedin_link = $sup['linkedin_link'] ?? '';
    $portfolio_link = $sup['portfolio_link'] ?? '';
    $last_login_at = $sup['last_login_at'] ?? '';
} catch (PDOException $e) {
    $sup = $pdo->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $sup->execute([$sup_id]);
    $sup = $sup->fetch();
}

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name  = trim($_POST['new_name'] ?? '');
    $new_email = trim($_POST['new_email'] ?? '');

    if (empty($new_name) || empty($new_email)) {
        $err = 'Name and Email are required.';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$new_email, $sup_id]);
        if ($chk->fetch()) {
            $err = 'This email is already in use.';
        } else {
            $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?")
                ->execute([$new_name, $new_email, $sup_id]);
            $_SESSION['username'] = $new_name;
            $sup['username'] = $new_name;
            $sup['email'] = $new_email;
            $sup_name = $new_name;
            $msg = 'Profile updated successfully.';
        }
    }
}

// Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new_pw) || empty($confirm)) {
        $err = 'All password fields are required.';
    } elseif (strlen($new_pw) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($new_pw !== $confirm) {
        $err = 'New passwords do not match.';
    } else {
        $hash_row = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $hash_row->execute([$sup_id]);
        $current_hash = $hash_row->fetchColumn();

        if (!password_verify($current, $current_hash)) {
            $err = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?")
                ->execute([$new_hash, $sup_id]);
            $_SESSION['is_first_login'] = false;
            $msg = 'Password changed successfully.';
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

            $filename = 'avatar_' . $sup_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $filepath)) {
                if ($profile_pic) {
                    $old_path = $upload_dir . $profile_pic;
                    if (file_exists($old_path)) unlink($old_path);
                }
                try {
                    $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?")
                        ->execute([$filename, $sup_id]);
                } catch (PDOException $e) {
                    $avatar_msg = 'Avatar uploaded but database column not ready. Run migration SQL.';
                }
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

    try {
        $pdo->prepare("UPDATE users SET github_link = ?, linkedin_link = ?, portfolio_link = ? WHERE id = ?")
            ->execute([$github, $linkedin, $portfolio, $sup_id]);
        $github_link   = $github;
        $linkedin_link = $linkedin;
        $portfolio_link = $portfolio;
        $portfolio_msg = 'Portfolio links updated successfully.';
    } catch (PDOException $e) {
        $portfolio_msg = 'Database columns not ready yet. Run migration SQL first.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Profile – InternReport</title>
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
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
</head>
<body class="bg-slate-50 dark:bg-slate-900 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 bg-white border-r border-slate-200 flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-slate-100">
            <span class="text-sm font-black text-slate-800 tracking-tight">📋 InternReport</span>
            <span class="ml-2 text-sm font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">SUPERVISOR</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="supervisor-dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>📊</span> Dashboard
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold bg-indigo-50 text-indigo-600 transition" style="border-right:3px solid #4f46e5">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-slate-100">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-500 hover:bg-red-50 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-14 bg-white border-b border-slate-200 flex items-center justify-between px-6 shrink-0">
            <h1 class="text-sm font-bold text-slate-700">Supervisor Profile</h1>
            <div class="flex items-center gap-2 text-xs text-slate-400">
                <span class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs font-bold">S</span>
                <?= htmlspecialchars($sup_name) ?>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">
            <div class="max-w-3xl mx-auto space-y-6">

                <?php if ($msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>
                <?php if ($err): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>❌</span> <?= htmlspecialchars($err) ?>
                </div>
                <?php endif; ?>

                <!-- Avatar Header -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex items-center gap-5">
                    <div class="relative w-16 h-16 shrink-0">
                        <?php if ($profile_pic): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-16 h-16 rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl font-bold">
                                <?= strtoupper($sup['username'][0]) ?>
                            </div>
                        <?php endif; ?>
                        <div class="absolute -bottom-0.5 -right-0.5 w-5 h-5 bg-slate-100 rounded-full flex items-center justify-center border border-slate-200" title="Change avatar">
                            <svg class="w-2.5 h-2.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-sm font-black text-slate-800"><?= htmlspecialchars($sup['username']) ?></h2>
                        <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($sup['email']) ?></p>
                        <span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded mt-1.5 inline-block">👨‍🏫 University Supervisor</span>
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
                                <div class="w-14 h-14 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg font-bold border border-slate-200">
                                    <?= strtoupper($sup_name[0]) ?>
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

                <!-- Section 1: Account Info -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-blue-50 text-blue-600 rounded">👤</span> Account Information
                        </h3>
                    </div>
                    <form method="POST" class="p-5 space-y-4">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Full Name</label>
                                <input type="text" name="new_name" value="<?= htmlspecialchars($sup['username']) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Email Address</label>
                                <input type="email" name="new_email" value="<?= htmlspecialchars($sup['email']) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Role</label>
                                <div class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-500 font-semibold cursor-default select-none">
                                    👨‍🏫 University Supervisor
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end pt-1">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Profile</button>
                        </div>
                    </form>
                </div>

                <!-- Section 2: Change Password -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-amber-50 text-amber-600 rounded">🔑</span> Security & Password
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
                        <div class="flex justify-end pt-1">
                            <button type="submit" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">🔒 Update Password</button>
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
