<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security_helper.php';

$admin_id   = (int) $_SESSION['user_id'];
$admin_name = $_SESSION['username'];
$db         = $mysqli ?? $conn;
$msg = '';
$err = '';
$tab = $_GET['tab'] ?? '';

// ── Fetch current admin info ─────────────────────────────────────
$profile_pic   = '';
$last_login_at = '';

try {
    $admin_stmt = $db->prepare("SELECT id, username, email, role, profile_pic, last_login_at FROM users WHERE id = ?");
    $admin_stmt->bind_param("i", $admin_id);
    $admin_stmt->execute();
    $res = $admin_stmt->get_result();
    $admin = $res ? $res->fetch_assoc() : null;
    $profile_pic   = $admin['profile_pic'] ?? '';
    $last_login_at = $admin['last_login_at'] ?? '';
} catch (Throwable $e) {
    $admin_stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $admin_stmt->bind_param("i", $admin_id);
    $admin_stmt->execute();
    $res = $admin_stmt->get_result();
    $admin = $res ? $res->fetch_assoc() : null;
}

// ── Fetch system settings ────────────────────────────────────────
$get_settings = $db->query("SELECT setting_key, setting_value FROM system_settings");
$settings = [];
if ($get_settings) {
    while ($row = $get_settings->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$default_student_pw  = $settings['default_student_password'] ?? 'password123';
$default_supervisor_pw = $settings['default_supervisor_password'] ?? 'password123';

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// ── Update Profile Info ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name  = trim($_POST['new_name'] ?? '');
    $new_email = trim($_POST['new_email'] ?? '');

    if (empty($new_name) || empty($new_email)) {
        $err = 'Name and Email are required.';
    } elseif ($email_err = validate_gmail_address($new_email)) {
        $err = $email_err;
    } else {
        // Check email uniqueness (exclude self)
        $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->bind_param("si", $new_email, $admin_id);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'This email is already in use by another account.';
        } else {
            $up = $db->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
            $up->bind_param("ssi", $new_name, $new_email, $admin_id);
            $up->execute();
            $_SESSION['username'] = $new_name;
            $admin['username'] = $new_name;
            $admin['email'] = $new_email;
            $admin_name = $new_name;
            $msg = 'Profile updated successfully.';
        }
    }
}

// ── Change Password ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new_pw) || empty($confirm)) {
        $err = 'All password fields are required.';
    } elseif ($err_msg = validate_strong_password($new_pw)) {
        $err = $err_msg;
    } elseif ($new_pw !== $confirm) {
        $err = 'New passwords do not match.';
    } else {
        $hash_row = $db->prepare("SELECT password FROM users WHERE id = ?");
        $hash_row->bind_param("i", $admin_id);
        $hash_row->execute();
        $res = $hash_row->get_result();
        $row = $res ? $res->fetch_row() : null;
        $current_hash = $row[0] ?? '';

        if (!password_verify($current, $current_hash)) {
            $err = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?");
            $up->bind_param("si", $new_hash, $admin_id);
            $up->execute();
            $_SESSION['is_first_login'] = false;
            $msg = 'Password changed successfully.';
        }
    }
}

// ── Update Default Passwords ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_defaults'])) {
    $d_student = trim($_POST['default_student_password'] ?? '');
    $d_sup     = trim($_POST['default_supervisor_password'] ?? '');

    if (empty($d_student) || empty($d_sup)) {
        $err = 'Both default password fields are required.';
    } elseif ($err_msg = validate_strong_password($d_student)) {
        $err = 'Student default password invalid: ' . $err_msg;
    } elseif ($err_msg = validate_strong_password($d_sup)) {
        $err = 'Supervisor default password invalid: ' . $err_msg;
    } else {
        $st1 = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_student_password', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st1->bind_param("s", $d_student);
        $st1->execute();

        $st2 = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_supervisor_password', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st2->bind_param("s", $d_sup);
        $st2->execute();

        $default_student_pw = $d_student;
        $default_supervisor_pw = $d_sup;
        $msg = 'Default passwords updated.';
    }
}

// ── Handle Avatar Upload ─────────────────────────────────────────
$avatar_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $avatar_msg = 'Only JPG, JPEG, PNG, and WEBP files are allowed.';
        } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $avatar_msg = 'File size must be less than 2MB.';
        } else {
            $upload_dir = __DIR__ . '/../uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $filename = 'avatar_' . $admin_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $filepath)) {
                if ($profile_pic) {
                    $old_path = $upload_dir . $profile_pic;
                    if (file_exists($old_path)) unlink($old_path);
                }
                try {
                    $up = $db->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                    $up->bind_param("si", $filename, $admin_id);
                    $up->execute();
                } catch (Throwable $e) {
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile Settings – InternReport</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'sans-serif'],
                        'inter': ['Inter', 'sans-serif'],
                        'mono': ['"JetBrains Mono"', 'monospace'],
                    },
                    fontSize: {
                        'micro': '0.5rem',
                        'caption': '0.6875rem',
                        'label': '0.8125rem',
                        'subtitle': '0.9375rem',
                        'body': '1rem',
                    },
                }
            }
        }
    </script>
    <style>
        /* ════ Standardized SaaS Dashboard Card Elevation ════ */
        .saas-card {
            background: #ffffff;
            border-radius: 1.25rem;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.02);
            border: 1px solid rgba(226, 232, 240, 0.85);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .saas-card:hover {
            box-shadow: 0 12px 30px -4px rgba(15, 23, 42, 0.08), 0 4px 12px -2px rgba(15, 23, 42, 0.03);
            border-color: rgba(203, 213, 225, 0.95);
        }

        .saas-hero-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 60%, #f0fdfa 100%);
            border-radius: 1.5rem;
            box-shadow: 0 10px 30px -4px rgba(0, 95, 115, 0.08), 0 4px 12px -2px rgba(15, 23, 42, 0.03);
            border: 1px solid rgba(148, 210, 189, 0.45);
        }

        .input-saas {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 0.75rem;
            box-shadow: inset 0 1px 2px 0 rgba(15, 23, 42, 0.03);
            transition: all 0.18s ease;
        }

        .input-saas:focus {
            background: #ffffff;
            border-color: #0a9396;
            box-shadow: 0 0 0 3.5px rgba(10, 147, 150, 0.14), inset 0 1px 2px 0 rgba(0, 0, 0, 0.01);
            outline: none;
        }

        .btn-saas-primary {
            background: linear-gradient(135deg, #005f73 0%, #0a9396 100%);
            box-shadow: 0 4px 14px -2px rgba(0, 95, 115, 0.32), inset 0 1px 0 0 rgba(255, 255, 255, 0.25);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-saas-primary:hover {
            background: linear-gradient(135deg, #004e5f 0%, #098285 100%);
            box-shadow: 0 8px 20px -3px rgba(0, 95, 115, 0.42), inset 0 1px 0 0 rgba(255, 255, 255, 0.35);
            transform: translateY(-1.5px);
        }

        .btn-saas-primary:active {
            box-shadow: 0 2px 6px -1px rgba(0, 95, 115, 0.3);
            transform: translateY(0);
        }

        .btn-saas-indigo {
            background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);
            box-shadow: 0 4px 14px -2px rgba(79, 70, 229, 0.32), inset 0 1px 0 0 rgba(255, 255, 255, 0.25);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-saas-indigo:hover {
            background: linear-gradient(135deg, #3730a3 0%, #4f46e5 100%);
            box-shadow: 0 8px 20px -3px rgba(79, 70, 229, 0.42), inset 0 1px 0 0 rgba(255, 255, 255, 0.35);
            transform: translateY(-1.5px);
        }

        .btn-saas-indigo:active {
            box-shadow: 0 2px 6px -1px rgba(79, 70, 229, 0.3);
            transform: translateY(0);
        }

        .btn-saas-purple {
            background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%);
            box-shadow: 0 4px 14px -2px rgba(124, 58, 237, 0.32), inset 0 1px 0 0 rgba(255, 255, 255, 0.25);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-saas-purple:hover {
            background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
            box-shadow: 0 8px 20px -3px rgba(124, 58, 237, 0.42), inset 0 1px 0 0 rgba(255, 255, 255, 0.35);
            transform: translateY(-1.5px);
        }

        .btn-saas-purple:active {
            box-shadow: 0 2px 6px -1px rgba(124, 58, 237, 0.3);
            transform: translateY(0);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>

<body class="bg-slate-50 font-sans antialiased text-slate-800 min-h-screen">

    <div class="flex h-screen overflow-hidden">

        <?php $activePage = 'profile'; ?>
        <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

        <!-- ─── MAIN WRAPPER ─── -->
        <div class="flex-1 flex flex-col overflow-hidden">

            <!-- Top Navigation Bar -->
            <?php $pageTitle = '👤 Admin Profile & Settings';
            require_once __DIR__ . '/../includes/topbar.php'; ?>

            <!-- Content Viewport -->
            <main class="flex-1 overflow-y-auto scroll-smooth p-4 lg:p-6" style="scrollbar-gutter: stable;">
                <div class="w-full max-w-5xl mx-auto space-y-6 pb-16">

                    <!-- ════ ALERT NOTIFICATIONS ════ -->
                    <?php if ($msg): ?>
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs font-semibold px-4 py-3.5 rounded-2xl shadow-xs flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="w-6 h-6 rounded-lg bg-emerald-500 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
                                    <i class="fa-solid fa-check"></i>
                                </span>
                                <div>
                                    <p class="font-bold text-emerald-950 text-sm">Success</p>
                                    <p class="text-emerald-800 text-xs"><?= htmlspecialchars($msg) ?></p>
                                </div>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800 p-1.5 rounded-lg hover:bg-emerald-100/50 transition">
                                <i class="fa-solid fa-xmark text-sm"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($err): ?>
                        <div class="bg-rose-50 border border-rose-200 text-rose-900 text-xs font-semibold px-4 py-3.5 rounded-2xl shadow-xs flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="w-6 h-6 rounded-lg bg-rose-500 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </span>
                                <div>
                                    <p class="font-bold text-rose-950 text-sm">Error</p>
                                    <p class="text-rose-800 text-xs"><?= htmlspecialchars($err) ?></p>
                                </div>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800 p-1.5 rounded-lg hover:bg-rose-100/50 transition">
                                <i class="fa-solid fa-xmark text-sm"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($avatar_msg): ?>
                        <div class="bg-teal-50 border border-teal-200 text-teal-900 text-xs font-semibold px-4 py-3.5 rounded-2xl shadow-xs flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="w-6 h-6 rounded-lg bg-teal-600 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
                                    <i class="fa-solid fa-image"></i>
                                </span>
                                <div>
                                    <p class="font-bold text-teal-950 text-sm">Avatar Notice</p>
                                    <p class="text-teal-800 text-xs"><?= htmlspecialchars($avatar_msg) ?></p>
                                </div>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" class="text-teal-600 hover:text-teal-800 p-1.5 rounded-lg hover:bg-teal-100/50 transition">
                                <i class="fa-solid fa-xmark text-sm"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- ════ 1. UNIFIED PROFILE & AVATAR HERO CARD (FULL-WIDTH) ════ -->
                    <div class="saas-hero-card p-6 sm:p-7">
                        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6">

                            <!-- Left: Admin Profile Identity -->
                            <div class="flex items-center gap-5 sm:gap-6 flex-1 min-w-0">
                                <div class="relative shrink-0">
                                    <?php if ($profile_pic): ?>
                                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>?v=<?= time() ?>" alt="Admin Avatar" id="hero-avatar-preview" class="w-18 h-18 sm:w-20 sm:h-20 rounded-2xl object-cover ring-3 ring-teal-500/20 shadow-md border-2 border-white">
                                    <?php else: ?>
                                        <div id="hero-avatar-preview" class="w-18 h-18 sm:w-20 sm:h-20 rounded-2xl bg-gradient-to-tr from-[#005f73] to-[#0a9396] text-white flex items-center justify-center text-2xl sm:text-3xl font-black ring-3 ring-teal-500/20 shadow-md border-2 border-white">
                                            <?= strtoupper($admin['username'][0] ?? 'A') ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-white rounded-full shadow-xs" title="Online & Authorized"></span>
                                </div>

                                <div class="space-y-1 min-w-0">
                                    <div class="flex items-center gap-2.5 flex-wrap">
                                        <h2 class="text-xl font-bold text-slate-900 tracking-tight truncate">
                                            <?= htmlspecialchars($admin['username'] ?? 'Administrator') ?>
                                        </h2>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-extrabold bg-amber-50 text-amber-700 border border-amber-200/80 shadow-xs">
                                            <i class="fa-solid fa-key text-[10px]"></i> System Administrator
                                        </span>
                                    </div>
                                    <p class="text-xs sm:text-sm font-medium text-slate-500 flex items-center gap-1.5 truncate">
                                        <i class="fa-regular fa-envelope text-slate-400"></i>
                                        <span><?= htmlspecialchars($admin['email'] ?? 'admin@internreport.edu') ?></span>
                                    </p>
                                    <div class="flex items-center gap-2 pt-0.5 flex-wrap">
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-600 bg-white/90 px-2 py-0.5 rounded-md border border-slate-200/80">
                                            <i class="fa-solid fa-id-badge text-teal-600"></i> Admin ID #<?= (int)$admin_id ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Clean Avatar Upload Box -->
                            <div class="w-full lg:w-auto p-4 bg-white/90 rounded-2xl border border-slate-200/90 shadow-xs shrink-0">
                                <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-3.5">
                                    <input type="hidden" name="update_avatar" value="1">
                                    <div class="flex items-center gap-3 w-full sm:w-auto">
                                        <label for="avatar_input" class="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200/80 rounded-xl cursor-pointer transition border border-slate-200/80 shrink-0">
                                            <i class="fa-solid fa-camera text-teal-700"></i>
                                            <span>Choose Photo</span>
                                        </label>
                                        <input type="file" id="avatar_input" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewSelectedAvatar(this)" class="hidden">
                                        <div class="min-w-0">
                                            <p id="file-chosen-name" class="text-xs font-semibold text-teal-700 truncate max-w-[160px]">No file selected</p>
                                            <p class="text-[10px] text-slate-400">JPG, PNG, WEBP (Max 2MB)</p>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn-saas-primary w-full sm:w-auto px-4 py-2 text-white text-xs font-bold rounded-xl flex items-center justify-center gap-1.5 cursor-pointer shrink-0">
                                        <i class="fa-solid fa-cloud-arrow-up"></i>
                                        <span>Upload Avatar</span>
                                    </button>
                                </form>
                            </div>

                        </div>
                    </div>

                    <!-- ════ 2. 50% / 50% SIDE-BY-SIDE SECTION: ACCOUNT INFO & SECURITY & PASSWORD ════ -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">

                        <!-- LEFT (50%): Account Information Card -->
                        <div id="account-info-card" class="saas-card p-6 sm:p-7 flex flex-col justify-between space-y-5 h-full">
                            <div class="space-y-4">
                                <div class="flex items-center justify-between pb-3.5 border-b border-slate-100">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-teal-50 text-teal-700 border border-teal-200/80 flex items-center justify-center text-sm shrink-0 shadow-xs">
                                            <i class="fa-solid fa-user-gear"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900 tracking-tight">Account Information</h3>
                                            <p class="text-[11px] text-slate-400">Update your primary administrator username and email address</p>
                                        </div>
                                    </div>
                                </div>

                                <form method="POST" id="profile-info-form" class="space-y-3.5 pt-1">
                                    <input type="hidden" name="update_profile" value="1">

                                    <div class="space-y-1">
                                        <label for="admin_new_name" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                                            Admin Username <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">
                                                <i class="fa-regular fa-user"></i>
                                            </span>
                                            <input
                                                type="text"
                                                id="admin_new_name"
                                                name="new_name"
                                                value="<?= htmlspecialchars($admin['username'] ?? '') ?>"
                                                required
                                                placeholder="Enter username"
                                                class="input-saas w-full pl-8 pr-3 py-2 text-xs font-semibold text-slate-800 focus:bg-white">
                                        </div>
                                    </div>

                                    <div class="space-y-1">
                                        <label for="admin_new_email" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                                            Email Address <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">
                                                <i class="fa-regular fa-envelope"></i>
                                            </span>
                                            <input
                                                type="email"
                                                id="admin_new_email"
                                                name="new_email"
                                                value="<?= htmlspecialchars($admin['email'] ?? '') ?>"
                                                required
                                                placeholder="admin@internreport.edu"
                                                class="input-saas w-full pl-8 pr-3 py-2 text-xs font-semibold text-slate-800 focus:bg-white">
                                        </div>
                                    </div>

                                    <div class="space-y-1">
                                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                                            Role Access Permissions
                                        </label>
                                        <div class="p-2.5 bg-slate-50 border border-slate-200/80 rounded-xl flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full bg-teal-500"></span>
                                                <span class="text-xs font-bold text-slate-700">🔑 System Administrator</span>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="flex justify-end pt-3 border-t border-slate-100">
                                <button type="submit" form="profile-info-form" class="btn-saas-primary px-5 py-2 text-white text-xs font-bold rounded-xl flex items-center gap-2 cursor-pointer">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                    <span>Save Profile Details</span>
                                </button>
                            </div>
                        </div>

                        <!-- RIGHT (50%): Security & Password Card -->
                        <div id="security-password-card" class="saas-card p-6 sm:p-7 flex flex-col justify-between space-y-5 h-full">
                            <div class="space-y-4">
                                <div class="flex items-center justify-between pb-3.5 border-b border-slate-100">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 border border-amber-200/80 flex items-center justify-center text-sm shrink-0 shadow-xs">
                                            <i class="fa-solid fa-key"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900 tracking-tight">Security & Password</h3>
                                            <p class="text-[11px] text-slate-400">Change your administrator account login credentials</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200/60">
                                        Min 6 Chars
                                    </span>
                                </div>

                                <form method="POST" id="password-change-form" class="space-y-3.5 pt-1">
                                    <input type="hidden" name="change_password" value="1">

                                    <!-- Current Password -->
                                    <div class="space-y-1">
                                        <label for="current_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                                            Current Password <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <input
                                                type="password"
                                                id="current_password"
                                                name="current_password"
                                                required
                                                autocomplete="current-password"
                                                placeholder="Enter current password"
                                                class="input-saas w-full pl-3.5 pr-9 py-2 text-xs font-medium text-slate-800 focus:bg-white">
                                            <button
                                                type="button"
                                                onclick="togglePasswordVisibility('current_password', this)"
                                                class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-teal-700 p-1.5 rounded-md transition focus:outline-none cursor-pointer"
                                                aria-label="Toggle Current Password Visibility">
                                                <i class="fa-regular fa-eye text-xs"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- New Password -->
                                    <div class="space-y-1">
                                        <label for="new_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                                            New Password <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <input
                                                type="password"
                                                id="new_password"
                                                name="new_password"
                                                required
                                                minlength="6"
                                                autocomplete="new-password"
                                                placeholder="Enter new password (min. 6 chars)"
                                                oninput="checkPasswordMatch()"
                                                class="input-saas w-full pl-3.5 pr-9 py-2 text-xs font-medium text-slate-800 focus:bg-white">
                                            <button
                                                type="button"
                                                onclick="togglePasswordVisibility('new_password', this)"
                                                class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-teal-700 p-1.5 rounded-md transition focus:outline-none cursor-pointer"
                                                aria-label="Toggle New Password Visibility">
                                                <i class="fa-regular fa-eye text-xs"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Confirm New Password -->
                                    <div class="space-y-1">
                                        <label for="confirm_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                                            Confirm New Password <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <input
                                                type="password"
                                                id="confirm_password"
                                                name="confirm_password"
                                                required
                                                autocomplete="new-password"
                                                placeholder="Confirm new password"
                                                oninput="checkPasswordMatch()"
                                                class="input-saas w-full pl-3.5 pr-9 py-2 text-xs font-medium text-slate-800 focus:bg-white">
                                            <button
                                                type="button"
                                                onclick="togglePasswordVisibility('confirm_password', this)"
                                                class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-teal-700 p-1.5 rounded-md transition focus:outline-none cursor-pointer"
                                                aria-label="Toggle Confirm Password Visibility">
                                                <i class="fa-regular fa-eye text-xs"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Real-time Password Match Indicator -->
                                    <div id="password-match-hint" class="hidden text-[11px] font-semibold flex items-center gap-1.5 pt-0.5"></div>
                                </form>
                            </div>

                            <div class="flex items-center justify-between pt-3 border-t border-slate-100">
                                <p class="text-[11px] text-slate-400">Min. 6 characters</p>
                                <button type="submit" form="password-change-form" class="btn-saas-indigo px-5 py-2 text-white text-xs font-bold rounded-xl flex items-center gap-2 cursor-pointer">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    <span>Update Password</span>
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- ════ 3. FULL-WIDTH CARD: GLOBAL DEFAULT PASSWORDS ════ -->
                    <div id="global-defaults-card" class="saas-card p-6 sm:p-7 space-y-5">
                        <div class="flex items-center justify-between pb-3.5 border-b border-slate-100">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 border border-purple-200/80 flex items-center justify-center text-sm shrink-0 shadow-xs">
                                    <i class="fa-solid fa-sliders"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 tracking-tight">Global Default Passwords</h3>
                                    <p class="text-[11px] text-slate-400">Configure initial default passwords used when provisioning new user accounts</p>
                                </div>
                            </div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-purple-700 bg-purple-50 px-2 py-0.5 rounded-md border border-purple-200/60">
                                System Provisioning
                            </span>
                        </div>

                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="update_defaults" value="1">

                            <p class="text-xs text-slate-500 leading-relaxed">
                                These default passwords are used when creating new accounts or resetting credentials. Users will be prompted to change their password on first login.
                            </p>

                            <!-- Two Column Password Fields on Desktop -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label for="default_student_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 flex items-center gap-1.5">
                                        <i class="fa-solid fa-user-graduate text-teal-600"></i>
                                        <span>Default Student Password <span class="text-rose-500">*</span></span>
                                    </label>
                                    <div class="relative">
                                        <input
                                            type="text"
                                            id="default_student_password"
                                            name="default_student_password"
                                            value="<?= htmlspecialchars($default_student_pw) ?>"
                                            required
                                            minlength="6"
                                            class="input-saas w-full pl-3.5 pr-9 py-2 text-xs text-slate-800 font-mono font-medium focus:bg-white">
                                        <button
                                            type="button"
                                            onclick="togglePasswordVisibility('default_student_password', this)"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-purple-700 p-1 rounded-md transition focus:outline-none cursor-pointer"
                                            aria-label="Toggle Default Student Password Visibility">
                                            <i class="fa-regular fa-eye-slash text-xs"></i>
                                        </button>
                                    </div>
                                    <p class="text-[10px] text-slate-400">Applied during student account registration</p>
                                </div>

                                <div class="space-y-1">
                                    <label for="default_supervisor_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 flex items-center gap-1.5">
                                        <i class="fa-solid fa-user-tie text-purple-600"></i>
                                        <span>Default Supervisor Password <span class="text-rose-500">*</span></span>
                                    </label>
                                    <div class="relative">
                                        <input
                                            type="text"
                                            id="default_supervisor_password"
                                            name="default_supervisor_password"
                                            value="<?= htmlspecialchars($default_supervisor_pw) ?>"
                                            required
                                            minlength="6"
                                            class="input-saas w-full pl-3.5 pr-9 py-2 text-xs text-slate-800 font-mono font-medium focus:bg-white">
                                        <button
                                            type="button"
                                            onclick="togglePasswordVisibility('default_supervisor_password', this)"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-purple-700 p-1 rounded-md transition focus:outline-none cursor-pointer"
                                            aria-label="Toggle Default Supervisor Password Visibility">
                                            <i class="fa-regular fa-eye-slash text-xs"></i>
                                        </button>
                                    </div>
                                    <p class="text-[10px] text-slate-400">Applied during supervisor account registration</p>
                                </div>
                            </div>

                            <!-- Compact Warning Alert -->
                            <div class="bg-amber-50/80 border border-amber-200/80 rounded-xl p-3 flex items-start gap-2.5">
                                <span class="text-amber-500 text-sm">⚠️</span>
                                <p class="text-xs text-amber-800 leading-relaxed font-medium">
                                    Changing these will affect future account creation and resets. Existing accounts will not be altered.
                                </p>
                            </div>

                            <div class="flex justify-end pt-2 border-t border-slate-100">
                                <button type="submit" class="btn-saas-purple px-5 py-2 text-white text-xs font-bold rounded-xl flex items-center gap-2 cursor-pointer">
                                    <i class="fa-solid fa-check-double"></i>
                                    <span>Save Defaults</span>
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <!-- ════ JAVASCRIPT LOGIC & HELPERS ════ -->
    <script>
        /**
         * Toggle Password Visibility for any password input
         * @param {string} inputId
         * @param {HTMLElement} btn
         */
        function togglePasswordVisibility(inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;

            var val = input.value;
            var isCurrentlyPassword = (input.type === 'password');
            input.type = isCurrentlyPassword ? 'text' : 'password';
            input.value = val;

            try {
                input.focus();
                if (input.setSelectionRange) {
                    var len = input.value.length;
                    input.setSelectionRange(len, len);
                }
            } catch (e) {}

            var icon = btn.querySelector('i');
            if (icon) {
                if (isCurrentlyPassword) {
                    icon.className = 'fa-solid fa-eye-slash text-xs';
                    btn.setAttribute('aria-label', 'Hide password');
                } else {
                    icon.className = 'fa-regular fa-eye text-xs';
                    btn.setAttribute('aria-label', 'Show password');
                }
            }
        }

        /**
         * Real-time non-blocking password match indicator
         */
        function checkPasswordMatch() {
            var np = document.getElementById('new_password');
            var cp = document.getElementById('confirm_password');
            var hint = document.getElementById('password-match-hint');
            if (!np || !cp || !hint) return;

            var val1 = np.value;
            var val2 = cp.value;

            if (!val1 && !val2) {
                hint.classList.add('hidden');
                return;
            }

            hint.classList.remove('hidden');
            if (val1 && val2 && val1 === val2) {
                hint.className = 'text-[11px] font-bold text-emerald-600 flex items-center gap-1.5 pt-0.5';
                hint.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-500"></i> <span>Passwords match successfully</span>';
            } else if (val2.length > 0 && val1 !== val2) {
                hint.className = 'text-[11px] font-bold text-rose-600 flex items-center gap-1.5 pt-0.5';
                hint.innerHTML = '<i class="fa-solid fa-circle-xmark text-rose-500"></i> <span>Passwords do not match yet</span>';
            } else {
                hint.classList.add('hidden');
            }
        }

        /**
         * Preview avatar file selected by user in the UI
         */
        function previewSelectedAvatar(input) {
            if (input.files && input.files[0]) {
                var file = input.files[0];
                var nameSpan = document.getElementById('file-chosen-name');
                if (nameSpan) {
                    nameSpan.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    var heroPreview = document.getElementById('hero-avatar-preview');
                    if (heroPreview) {
                        if (heroPreview.tagName === 'IMG') {
                            heroPreview.src = e.target.result;
                        } else {
                            var heroImg = document.createElement('img');
                            heroImg.id = 'hero-avatar-preview';
                            heroImg.src = e.target.result;
                            heroImg.alt = 'Admin Avatar';
                            heroImg.className = 'w-18 h-18 sm:w-20 sm:h-20 rounded-2xl object-cover ring-3 ring-teal-500/20 shadow-md border-2 border-white';
                            heroPreview.parentNode.replaceChild(heroImg, heroPreview);
                        }
                    }
                };
                reader.readAsDataURL(file);
            }
        }
    </script>

</body>

</html>