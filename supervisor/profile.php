<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/phone_validation.php';
require_once __DIR__ . '/../includes/security_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$db       = $mysqli ?? $conn;
$msg = '';
$err = '';

// ══════════════════════════════════════════════════════════════════
// NOTIFICATION REDIRECT URL HELPER
// ══════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../config/notify.php';

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($db, $sup_id);

// ══════════════════════════════════════════════════════════════════
// FETCH NOTIFICATIONS
// ══════════════════════════════════════════════════════════════════
$unread_notif_count = 0;
$recent_notifications = [];
try {
    $notif_stmt2 = $db->prepare("SELECT id, title, message, type, related_week, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $notif_stmt2->bind_param("i", $sup_id);
    $notif_stmt2->execute();
    $res = $notif_stmt2->get_result();
    $recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($recent_notifications as $n) {
        if (!$n['is_read']) $unread_notif_count++;
    }
} catch (Throwable $e) { /* table may not exist yet */
}

// ══════════════════════════════════════════════════════════════════
// ACTIVE YEAR BADGE DATA
// ══════════════════════════════════════════════════════════════════
$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ?");
$total_assigned_q->bind_param("i", $sup_id);
$total_assigned_q->execute();
$res = $total_assigned_q->get_result();
$row = $res ? $res->fetch_row() : null;
$total_assigned = (int) ($row[0] ?? 0);
$selected_year_label = '';

// ══════════════════════════════════════════════════════════════════
// FETCH CURRENT INFO
// ══════════════════════════════════════════════════════════════════
$profile_pic   = '';
$last_login_at = '';

try {
    $sup_stmt = $db->prepare("SELECT id, username, email, phone, department, position, role, profile_pic, last_login_at FROM users WHERE id = ?");
    $sup_stmt->bind_param("i", $sup_id);
    $sup_stmt->execute();
    $res = $sup_stmt->get_result();
    $sup = $res ? $res->fetch_assoc() : null;
    $profile_pic   = $sup['profile_pic'] ?? '';
    $last_login_at = $sup['last_login_at'] ?? '';
} catch (Throwable $e) {
    $sup_stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $sup_stmt->bind_param("i", $sup_id);
    $sup_stmt->execute();
    $res = $sup_stmt->get_result();
    $sup = $res ? $res->fetch_assoc() : null;
}

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name     = trim($_POST['new_name'] ?? '');
    $new_email    = trim($_POST['new_email'] ?? '');
    $new_phone    = trim($_POST['phone'] ?? '');
    $new_dept     = trim($_POST['department'] ?? '');
    $new_position = trim($_POST['position'] ?? '');

    if (empty($new_name) || empty($new_email)) {
        $err = 'Name and Email are required.';
    } elseif ($email_err = validate_gmail_address($new_email)) {
        $err = $email_err;
    } elseif (($phone_err = phone_validation_error($new_phone)) !== null) {
        $err = $phone_err;
    } else {
        $new_phone = normalize_phone($new_phone);
        $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->bind_param("si", $new_email, $sup_id);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'This email is already in use.';
        } else {
            try {
                $up = $db->prepare("UPDATE users SET username = ?, email = ?, phone = ?, department = ?, position = ? WHERE id = ?");
                $up->bind_param("sssssi", $new_name, $new_email, $new_phone, $new_dept, $new_position, $sup_id);
                $up->execute();
                $_SESSION['username'] = $new_name;
                $sup['username'] = $new_name;
                $sup['email'] = $new_email;
                $sup['phone'] = $new_phone;
                $sup['department'] = $new_dept;
                $sup['position'] = $new_position;
                $sup_name = $new_name;
                $msg = 'Profile updated successfully.';
            } catch (Throwable $e) {
                $err = 'Could not save all fields. Please run the migration database/migrate_supervisor_profile_fields.sql to add the Phone / Department / Position columns, then try again.';
            }
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
    } elseif ($err_msg = validate_strong_password($new_pw)) {
        $err = $err_msg;
    } elseif ($new_pw !== $confirm) {
        $err = 'New passwords do not match.';
    } else {
        $hash_row = $db->prepare("SELECT password FROM users WHERE id = ?");
        $hash_row->bind_param("i", $sup_id);
        $hash_row->execute();
        $res = $hash_row->get_result();
        $row = $res ? $res->fetch_row() : null;
        $current_hash = $row[0] ?? '';

        if (!password_verify($current, $current_hash)) {
            $err = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?");
            $up->bind_param("si", $new_hash, $sup_id);
            $up->execute();
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
                    $up = $db->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                    $up->bind_param("si", $filename, $sup_id);
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
<html lang="en" style="scroll-behavior: smooth;">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Profile – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
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
        html, body, main {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        main::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR ─── -->
        <?php $active_page = 'profile';
        include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

        <!-- ─── MAIN ─── -->
        <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

            <!-- Top Bar -->
            <?php $pageTitle = '👤 My Profile';
            include __DIR__ . '/includes/supervisor_topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
                <div class="max-w-4xl w-full mx-auto space-y-6">

                    <!-- Flash Alerts -->
                    <?php if ($msg): ?>
                        <div class="bg-emerald-50 border border-emerald-200/80 text-emerald-800 text-xs font-bold px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-7 h-7 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-xs font-bold shrink-0">✓</span>
                                <span><?= htmlspecialchars($msg) ?></span>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800 text-xs font-bold p-1 cursor-pointer">✕</button>
                        </div>
                    <?php endif; ?>
                    <?php if ($err): ?>
                        <div class="bg-rose-50 border border-rose-200/80 text-rose-800 text-xs font-bold px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-7 h-7 rounded-xl bg-rose-500 text-white flex items-center justify-center text-xs font-bold shrink-0">✕</span>
                                <span><?= htmlspecialchars($err) ?></span>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800 text-xs font-bold p-1 cursor-pointer">✕</button>
                        </div>
                    <?php endif; ?>
                    <?php if ($avatar_msg): ?>
                        <div class="bg-teal-50 border border-teal-200/80 text-teal-800 text-xs font-bold px-4 py-3.5 rounded-2xl flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-7 h-7 rounded-xl bg-teal-500 text-white flex items-center justify-center text-xs font-bold shrink-0">📷</span>
                                <span><?= htmlspecialchars($avatar_msg) ?></span>
                            </div>
                            <button type="button" onclick="this.parentElement.remove()" class="text-teal-500 hover:text-teal-800 text-xs font-bold p-1 cursor-pointer">✕</button>
                        </div>
                    <?php endif; ?>

                    <!-- ════ HERO SUPERVISOR PROFILE BANNER ════ -->
                    <div class="bg-gradient-to-r from-teal-700 via-teal-800 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-md relative overflow-hidden">
                        <!-- Decorative glow circle -->
                        <div class="absolute -top-16 -right-16 w-64 h-64 bg-teal-500/10 rounded-full blur-3xl pointer-events-none"></div>
                        <div class="absolute -bottom-16 -left-16 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>

                        <div class="relative z-10 flex flex-col sm:flex-row items-center sm:items-start gap-6 text-center sm:text-left">
                            <div class="relative group shrink-0">
                                <?php if ($profile_pic): ?>
                                    <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-24 h-24 sm:w-28 sm:h-28 rounded-3xl object-cover ring-4 ring-white/20 shadow-xl">
                                <?php else: ?>
                                    <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-3xl bg-gradient-to-br from-teal-400 to-emerald-600 text-white flex items-center justify-center text-3xl font-black ring-4 ring-white/20 shadow-xl">
                                        <?= strtoupper(substr(format_supervisor_name($sup_name), 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <a href="#avatar-upload-card" class="absolute -bottom-1.5 -right-1.5 w-8 h-8 rounded-xl bg-teal-500 hover:bg-teal-400 text-white flex items-center justify-center shadow-lg border-2 border-slate-900 transition-transform hover:scale-110" title="Change Avatar">
                                    📷
                                </a>
                            </div>

                            <div class="min-w-0 flex-1 space-y-2.5">
                                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                                    <h2 class="text-xl sm:text-2xl font-black tracking-tight text-white"><?= htmlspecialchars(format_supervisor_name($sup['username'])) ?></h2>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">
                                        ✓ Verified Supervisor
                                    </span>
                                </div>
                                <p class="text-xs sm:text-sm text-teal-100/90 font-medium flex items-center justify-center sm:justify-start gap-1.5">
                                    <span>✉️</span> <?= htmlspecialchars($sup['email']) ?>
                                    <?php if (!empty($sup['phone'])): ?>
                                        <span class="text-teal-400">•</span>
                                        <span>📞 <?= htmlspecialchars($sup['phone']) ?></span>
                                    <?php endif; ?>
                                </p>

                                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 pt-1">
                                    <span class="px-3 py-1 rounded-xl text-xs font-semibold bg-white/10 text-teal-50 border border-white/10 backdrop-blur-xs">
                                        👨‍🏫 <?= htmlspecialchars($sup['position'] ?? '') ?: 'Faculty Supervisor' ?>
                                    </span>
                                    <span class="px-3 py-1 rounded-xl text-xs font-semibold bg-white/10 text-teal-50 border border-white/10 backdrop-blur-xs">
                                        🏛️ <?= htmlspecialchars($sup['department'] ?? '') ?: 'Faculty of Computer Science' ?>
                                    </span>
                                </div>

                                <div class="pt-3 border-t border-white/10 flex flex-wrap items-center justify-center sm:justify-start gap-3 sm:gap-6 text-xs text-teal-100">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-emerald-300 font-black text-sm"><?= $total_assigned ?></span>
                                        <span>Assigned Interns</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                                        <span>Status: Active</span>
                                    </div>
                                    <?php if ($last_login_at): ?>
                                    <div class="flex items-center gap-1.5 text-teal-200/80 text-[11px]">
                                        <span>🕒 Last Active: <?= date('d M Y, h:i A', strtotime($last_login_at)) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ════ CARD 1: PROFILE PICTURE UPLOAD ════ -->
                    <div id="avatar-upload-card" class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                            <h3 class="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center text-xs">📷</span>
                                Profile Picture
                            </h3>
                            <span class="text-[11px] font-bold text-slate-400">JPG, JPEG, PNG (Max 2MB)</span>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="p-6">
                            <input type="hidden" name="update_avatar" value="1">
                            <div class="flex flex-col sm:flex-row items-center gap-6">
                                <?php if ($profile_pic): ?>
                                    <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Current avatar" class="w-20 h-20 rounded-2xl object-cover border-2 border-slate-200 shadow-xs shrink-0">
                                <?php else: ?>
                                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-teal-50 to-teal-100 text-teal-700 flex items-center justify-center text-2xl font-black border border-teal-200 shadow-xs shrink-0">
                                        <?= strtoupper(substr(format_supervisor_name($sup_name), 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1 w-full space-y-2">
                                    <label class="block text-xs font-bold text-slate-700">Choose a new photo</label>
                                    <input type="file" name="avatar" accept="image/jpeg,image/png" class="w-full bg-slate-50 hover:bg-slate-100/70 border border-slate-200 rounded-2xl px-3.5 py-2 text-xs text-slate-800 focus:outline-none focus:border-teal-500 transition file:mr-3 file:py-1.5 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 file:cursor-pointer">
                                </div>
                                <div class="shrink-0 w-full sm:w-auto">
                                    <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs rounded-2xl shadow-sm transition-all hover:scale-[1.02] cursor-pointer">
                                        <span>📷</span>
                                        <span>Upload Photo</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ════ CARD 2: EDIT PROFILE INFORMATION ════ -->
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                            <h3 class="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xs">👤</span>
                                Edit Personal &amp; Faculty Information
                            </h3>
                        </div>
                        <form method="POST" class="p-6 space-y-5">
                            <input type="hidden" name="update_profile" value="1">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Supervisor Full Name *</label>
                                    <input type="text" name="new_name" value="<?= htmlspecialchars($sup['username']) ?>" required class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-2xl px-3.5 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Email Address *</label>
                                    <input type="email" name="new_email" value="<?= htmlspecialchars($sup['email']) ?>" required class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-2xl px-3.5 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Phone Number</label>
                                    <input type="text" name="phone" value="<?= htmlspecialchars($sup['phone'] ?? '') ?>" placeholder="e.g. 09450099111" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-2xl px-3.5 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Department</label>
                                    <select name="department" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-2xl px-3.5 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all cursor-pointer">
                                        <option value="">— Select Department —</option>
                                        <option value="Faculty of Computer Science (FCS)" <?= (($sup['department'] ?? '') === 'Faculty of Computer Science (FCS)') ? 'selected' : '' ?>>Faculty of Computer Science (FCS)</option>
                                        <option value="Faculty of Information Science (FIS)" <?= (($sup['department'] ?? '') === 'Faculty of Information Science (FIS)') ? 'selected' : '' ?>>Faculty of Information Science (FIS)</option>
                                        <option value="Faculty of Computer Systems and Technologies (FCST)" <?= (($sup['department'] ?? '') === 'Faculty of Computer Systems and Technologies (FCST)') ? 'selected' : '' ?>>Faculty of Computer Systems and Technologies (FCST)</option>
                                        <option value="Department of Information Technology Supporting and Maintenance" <?= (($sup['department'] ?? '') === 'Department of Information Technology Supporting and Maintenance') ? 'selected' : '' ?>>Department of Information Technology Supporting and Maintenance</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Rank / Academic Position</label>
                                    <select name="position" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-2xl px-3.5 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all cursor-pointer">
                                        <option value="">— Select Rank —</option>
                                        <option value="Professor" <?= (($sup['position'] ?? '') === 'Professor') ? 'selected' : '' ?>>Professor</option>
                                        <option value="Associate Professor" <?= (($sup['position'] ?? '') === 'Associate Professor') ? 'selected' : '' ?>>Associate Professor</option>
                                        <option value="Lecturer" <?= (($sup['position'] ?? '') === 'Lecturer') ? 'selected' : '' ?>>Lecturer</option>
                                        <option value="Assistant Lecturer" <?= (($sup['position'] ?? '') === 'Assistant Lecturer') ? 'selected' : '' ?>>Assistant Lecturer</option>
                                        <option value="Tutor" <?= (($sup['position'] ?? '') === 'Tutor') ? 'selected' : '' ?>>Tutor</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">System Role</label>
                                    <div class="w-full bg-slate-100/80 border border-slate-200 rounded-2xl px-3.5 py-2.5 text-xs text-teal-800 font-bold flex items-center gap-2 select-none">
                                        <span>👨‍🏫</span> University Supervisor
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs rounded-2xl shadow-sm transition-all hover:scale-[1.02] cursor-pointer">
                                    <span>💾</span>
                                    <span>Save Profile</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ════ CARD 3: SECURITY & CHANGE PASSWORD ════ -->
                    <div id="security-section" class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                            <h3 class="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-xs">🔑</span>
                                Security &amp; Password
                            </h3>
                            <span class="text-[11px] font-bold text-slate-400">Min 6 characters</span>
                        </div>
                        <form method="POST" class="p-6 space-y-5">
                            <input type="hidden" name="change_password" value="1">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Current Password *</label>
                                    <div class="relative">
                                        <input type="password" id="sup_current_password" name="current_password" required placeholder="••••••••" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-amber-500 rounded-2xl pl-3.5 pr-10 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20 transition-all placeholder:tracking-widest">
                                        <button type="button" onclick="togglePasswordVisibility('sup_current_password', this)" title="Show password" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                            <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                            </svg>
                                            <svg class="eye-open w-4 h-4 text-amber-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">New Password *</label>
                                    <div class="relative">
                                        <input type="password" id="sup_new_password" name="new_password" required minlength="6" placeholder="••••••••" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-amber-500 rounded-2xl pl-3.5 pr-10 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20 transition-all placeholder:tracking-widest">
                                        <button type="button" onclick="togglePasswordVisibility('sup_new_password', this)" title="Show password" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                            <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                            </svg>
                                            <svg class="eye-open w-4 h-4 text-amber-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Confirm New Password *</label>
                                    <div class="relative">
                                        <input type="password" id="sup_confirm_password" name="confirm_password" required placeholder="••••••••" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-amber-500 rounded-2xl pl-3.5 pr-10 py-2.5 text-xs text-slate-800 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/20 transition-all placeholder:tracking-widest">
                                        <button type="button" onclick="togglePasswordVisibility('sup_confirm_password', this)" title="Show password" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                            <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                            </svg>
                                            <svg class="eye-open w-4 h-4 text-amber-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-2xl shadow-sm transition-all hover:scale-[1.02] cursor-pointer">
                                    <span>🔒</span>
                                    <span>Update Password</span>
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;
            var eyeSlash = btn.querySelector('.eye-slash');
            var eyeOpen = btn.querySelector('.eye-open');
            if (input.type === 'password') {
                input.type = 'text';
                if (eyeSlash) eyeSlash.classList.add('hidden');
                if (eyeOpen) eyeOpen.classList.remove('hidden');
                btn.setAttribute('title', 'Hide password');
            } else {
                input.type = 'password';
                if (eyeOpen) eyeOpen.classList.add('hidden');
                if (eyeSlash) eyeSlash.classList.remove('hidden');
                btn.setAttribute('title', 'Show password');
            }
        }
    </script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
    <?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>

</html>