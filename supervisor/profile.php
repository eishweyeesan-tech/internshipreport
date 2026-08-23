<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/phone_validation.php';
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
    $notif_stmt2 = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $notif_stmt2->bind_param("i", $sup_id);
    $notif_stmt2->execute();
    $res = $notif_stmt2->get_result();
    $recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($recent_notifications as $n) { if (!$n['is_read']) $unread_notif_count++; }
} catch (Throwable $e) { /* table may not exist yet */ }

// ══════════════════════════════════════════════════════════════════
// ACTIVE YEAR BADGE DATA
// ══════════════════════════════════════════════════════════════════
$total_assigned_q = $db->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?");
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
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
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
    } elseif (strlen($new_pw) < 6) {
        $err = 'New password must be at least 6 characters.';
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
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'profile'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- Top Bar -->
        <?php $pageTitle = 'Supervisor Profile'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
            <div class="max-w-4xl w-full mx-auto space-y-6">


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
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 flex items-center gap-5">
                    <div class="relative w-16 h-16 shrink-0">
                        <?php if ($profile_pic): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-16 h-16 rounded-2xl object-cover ring-2 ring-slate-100 shadow-xs">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-[#005f73] to-teal-700 text-white flex items-center justify-center text-xl font-bold shadow-xs">
                                <?= strtoupper($sup['username'][0]) ?>
                            </div>
                        <?php endif; ?>
                        <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-white rounded-full flex items-center justify-center border border-slate-200 shadow-xs" title="Change avatar">
                            <svg class="w-3 h-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800 tracking-tight leading-tight"><?= htmlspecialchars(format_supervisor_name($sup['username'])) ?></h2>
                        <p class="text-xs text-slate-400 font-medium mt-1"><?= htmlspecialchars($sup['email']) ?></p>
                        <span class="text-xs font-semibold text-teal-700 bg-teal-50 ring-1 ring-teal-600/20 px-3 py-1 rounded-full mt-2 inline-block">👨‍🏫 University Supervisor</span>
                    </div>
                </div>

                <?php if ($avatar_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($avatar_msg) ?>
                </div>
                <?php endif; ?>

                <!-- ════ PROFILE PICTURE UPLOAD ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="text-lg font-semibold text-slate-700 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center text-xs font-bold">📷</span> Profile Picture
                        </h3>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="p-6">
                        <input type="hidden" name="update_avatar" value="1">
                        <div class="flex items-center gap-5">
                            <?php if ($profile_pic): ?>
                                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Current avatar" class="w-16 h-16 rounded-2xl object-cover border border-slate-200 shadow-xs">
                            <?php else: ?>
                                <div class="w-16 h-16 rounded-2xl bg-teal-50 text-teal-700 flex items-center justify-center text-xl font-bold border border-teal-200/60 shadow-xs">
                                    <?= strtoupper(substr(format_supervisor_name($sup_name), 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Upload New Picture</label>
                                <input type="file" name="avatar" accept="image/jpeg,image/png" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-teal-500 transition-all duration-200 ease-in-out file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 file:cursor-pointer">
                                <p class="text-xs text-slate-400 font-medium mt-1">JPG, JPEG, or PNG. Max 2MB.</p>
                            </div>
                        </div>
                        <div class="flex justify-start pt-4">
                            <button type="submit" class="px-5 py-2.5 bg-[#005f73] hover:bg-[#0a9396] text-white font-semibold text-xs rounded-xl shadow-xs transition-all duration-200 ease-in-out cursor-pointer">📷 Upload Picture</button>
                        </div>
                    </form>
                </div>

                <!-- ════ SUPERVISOR INFORMATION ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="text-lg font-semibold text-slate-700 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs font-bold">🧑‍💼</span> Supervisor Information
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Full Name</p>
                                <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars(format_supervisor_name($sup['username'])) ?></p>
                            </div>
                            <div class="p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Username</p>
                                <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars($sup['username']) ?></p>
                            </div>

                            <div class="p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email</p>
                                <p class="text-sm font-semibold text-slate-800 mt-1 break-all"><?= htmlspecialchars($sup['email']) ?></p>
                            </div>
                            <div class="p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Phone</p>
                                <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars($sup['phone'] ?? '') ?: '—' ?></p>
                            </div>
                            <div class="p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Department</p>
                                <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars($sup['department'] ?? '') ?: '—' ?></p>
                            </div>
                            <div class="p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Position / Job Title</p>
                                <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars($sup['position'] ?? '') ?: '—' ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════ SECURITY & LAST LOGIN ════ -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="text-lg font-semibold text-slate-700 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs font-bold">🛡️</span> Security & Last Login
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="flex items-center gap-3.5 p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <span class="w-3 h-3 bg-emerald-500 rounded-full shrink-0"></span>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Account Status</p>
                                    <p class="text-sm font-semibold text-emerald-600 mt-0.5">Active</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3.5 p-4 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                <span class="w-3 h-3 bg-blue-500 rounded-full shrink-0"></span>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Last Login Detected</p>
                                    <?php if ($last_login_at): ?>
                                        <p class="text-sm font-semibold text-slate-700 mt-0.5"><?= date('d M Y, h:i A', strtotime($last_login_at)) ?></p>
                                    <?php else: ?>
                                        <p class="text-sm font-normal text-slate-400 mt-0.5">No login recorded yet</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Account Info -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="text-lg font-semibold text-slate-700 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs font-bold">👤</span> Account Information
                        </h3>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Full Name</label>
                                <input type="text" name="new_name" value="<?= htmlspecialchars($sup['username']) ?>" required class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 ease-in-out">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Email Address</label>
                                <input type="email" name="new_email" value="<?= htmlspecialchars($sup['email']) ?>" required class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 ease-in-out">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Phone</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($sup['phone'] ?? '') ?>" placeholder="e.g. 09-123456789" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid phone number" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 ease-in-out">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Department</label>
                                <input type="text" name="department" value="<?= htmlspecialchars($sup['department'] ?? '') ?>" placeholder="e.g. Computer Science" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 ease-in-out">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Position / Job Title</label>
                                <input type="text" name="position" value="<?= htmlspecialchars($sup['position'] ?? '') ?>" placeholder="e.g. Senior Lecturer" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 ease-in-out">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Role</label>
                                <div class="w-full bg-slate-100 border border-slate-200/80 rounded-xl px-3.5 py-2 text-sm text-slate-500 font-semibold cursor-default select-none">
                                    👨‍🏫 University Supervisor
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-start pt-2">
                            <button type="submit" class="px-5 py-2.5 bg-[#005f73] hover:bg-[#0a9396] text-white font-semibold text-xs rounded-xl shadow-xs transition-all duration-200 ease-in-out cursor-pointer">💾 Save Profile</button>
                        </div>
                    </form>
                </div>

                <!-- Section 2: Change Password -->
                <div id="security-section" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="text-lg font-semibold text-slate-700 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs font-bold">🔑</span> Security & Password
                        </h3>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="change_password" value="1">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Current Password</label>
                                <input type="password" name="current_password" required class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-amber-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500/20 transition-all duration-200 ease-in-out">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">New Password</label>
                                <input type="password" name="new_password" required minlength="6" class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-amber-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500/20 transition-all duration-200 ease-in-out">
                                <p class="text-xs text-slate-400 font-medium mt-1">Min 6 characters</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Confirm New Password</label>
                                <input type="password" name="confirm_password" required class="w-full bg-slate-50 hover:bg-slate-100/60 focus:bg-white border border-slate-200 focus:border-amber-500 rounded-xl px-3.5 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500/20 transition-all duration-200 ease-in-out">
                            </div>
                        </div>
                        <div class="flex justify-start pt-2">
                            <button type="submit" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold text-xs rounded-xl shadow-xs transition-all duration-200 ease-in-out cursor-pointer">🔒 Update Password</button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="../assets/js/main.js"></script>
<script src="../assets/js/notifications.js"></script>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
