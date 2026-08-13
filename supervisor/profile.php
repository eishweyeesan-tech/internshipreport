<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../config/ay_helper.php';
require_once __DIR__ . '/../includes/phone_validation.php';

if ($_SESSION['role'] !== 'supervisor') {
    header('Location: ../dashboard.php');
    exit;
}

$sup_id   = $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$msg = '';
$err = '';

// ══════════════════════════════════════════════════════════════════
// NOTIFICATION REDIRECT URL HELPER
// ══════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../config/notify.php';

// ══════════════════════════════════════════════════════════════════
// MARK NOTIFICATION AS READ POST HANDLERS
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    $notif_id = (int)($_POST['notification_id'] ?? 0);
    if ($notif_id > 0) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notif_id, $sup_id]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $count_q->execute([$sup_id]);
        echo json_encode(['unread_count' => (int)$count_q->fetchColumn()]);
        exit;
    }
    header('Location: profile.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$sup_id]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit;
    }
    header('Location: profile.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notification'])) {
    $notif_id = (int) ($_POST['notification_id'] ?? 0);
    $deleted  = false;
    if ($notif_id > 0) {
        $del = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $del->execute([$notif_id, $sup_id]);
        $deleted = $del->rowCount() > 0;
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        $count_q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $count_q->execute([$sup_id]);
        echo json_encode(['success' => $deleted, 'unread_count' => (int) $count_q->fetchColumn()]);
        exit;
    }
    header('Location: profile.php');
    exit;
}

// ══════════════════════════════════════════════════════════════════
// FETCH NOTIFICATIONS
// ══════════════════════════════════════════════════════════════════
$unread_notif_count = 0;
$recent_notifications = [];
try {
    $notif_stmt = $pdo->prepare("SELECT is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $notif_stmt->execute([$sup_id]);
    $recent_notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($recent_notifications as $n) { if (!$n['is_read']) $unread_notif_count++; }
    $notif_stmt2 = $pdo->prepare("SELECT id, title, message, type, related_week, announcement_id, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $notif_stmt2->execute([$sup_id]);
    $recent_notifications = $notif_stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist yet */ }

// ══════════════════════════════════════════════════════════════════
// ACTIVE YEAR BADGE DATA
// ══════════════════════════════════════════════════════════════════
$ay_filter = get_ay_filter($pdo, 'u');
$total_assigned_q = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?" . $ay_filter['sql']);
$total_assigned_q->execute(array_merge([$sup_id], $ay_filter['params']));
$total_assigned = (int) $total_assigned_q->fetchColumn();
$selected_year_label = $_SESSION['selected_academic_year_label'] ?? '';

// ══════════════════════════════════════════════════════════════════
// FETCH CURRENT INFO
// ══════════════════════════════════════════════════════════════════
$profile_pic   = '';
$last_login_at = '';

try {
    $sup = $pdo->prepare("SELECT id, username, email, phone, department, position, role, profile_pic, last_login_at FROM users WHERE id = ?");
    $sup->execute([$sup_id]);
    $sup = $sup->fetch();
    $profile_pic   = $sup['profile_pic'] ?? '';
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
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$new_email, $sup_id]);
        if ($chk->fetch()) {
            $err = 'This email is already in use.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, department = ?, position = ? WHERE id = ?")
                    ->execute([$new_name, $new_email, $new_phone, $new_dept, $new_position, $sup_id]);
                $_SESSION['username'] = $new_name;
                $sup['username'] = $new_name;
                $sup['email'] = $new_email;
                $sup['phone'] = $new_phone;
                $sup['department'] = $new_dept;
                $sup['position'] = $new_position;
                $sup_name = $new_name;
                $msg = 'Profile updated successfully.';
            } catch (PDOException $e) {
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
    <div id="top" class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm relative z-[1050]">
            <div class="flex items-center gap-4">
                <h1 class="text-base font-bold text-slate-800">Supervisor Profile</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-emerald-700"><?= $total_assigned ?> Assigned</span>
                    <?php if (!empty($selected_year_label)): ?>
                    <span class="text-sm font-bold text-emerald-600 bg-emerald-100 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($selected_year_label) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                    <!-- Notification Bell -->
                    <div class="relative" id="notif-bell-wrapper">
                        <button onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-white/30 rounded-xl transition cursor-pointer">
                            <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <?php if ($unread_notif_count > 0): ?>
                            <span id="notif-badge" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse"><?= $unread_notif_count > 9 ? '9+' : $unread_notif_count ?></span>
                            <?php endif; ?>
                        </button>
                        <!-- Notification Dropdown -->
                        <div id="notif-dropdown" class="absolute right-0 top-full mt-1 w-[22rem] bg-white border border-slate-200 rounded-xl shadow-xl z-[1060] overflow-hidden transition-all duration-200 ease-out" style="opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);">
                            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-gradient-to-br from-blue-50/80 to-white/60">
                                <h4 class="text-sm font-black text-slate-700">Notifications</h4>
                                <?php if ($unread_notif_count > 0): ?>
                                <button onclick="markAllNotifsRead()" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition cursor-pointer">Mark all read</button>
                                <?php endif; ?>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php if (!empty($recent_notifications)): ?>
                                <?php foreach ($recent_notifications as $notif): ?>
                                <?php $notif_url = notif_redirect_url($notif['type'], $notif['related_week'] ?? null, $notif['announcement_id'] ?? null, $notif['student_id'] ?? null); ?>
                                <div class="flex items-start gap-3 px-4 py-3 <?= !$notif['is_read'] ? 'bg-[#e7f3ff]' : '' ?> hover:bg-slate-50 transition-all duration-150 border-b border-slate-100/80 last:border-0 group relative cursor-pointer" data-notif-id="<?= (int)$notif['id'] ?>" data-announcement-id="<?= (int)($notif['announcement_id'] ?? 0) ?>" data-redirect-url="<?= htmlspecialchars($notif_url) ?>" data-fallback-href="<?= htmlspecialchars($notif_url) ?>" onclick="onNotificationItemClick(event, this)">
                                    <?php if ($notif['type'] === 'instructor_approved'): ?>
                                    <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                    <?php elseif ($notif['type'] === 'instructor_rejected'): ?>
                                    <div class="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </div>
                                    <?php else: ?>
                                    <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-sm shrink-0 ring-2 ring-white shadow-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                    </div>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm <?= !$notif['is_read'] ? 'font-bold text-slate-800' : 'font-medium text-slate-600' ?> leading-snug"><?= htmlspecialchars($notif['title']) ?></p>
                                        <p class="text-xs text-slate-500 mt-0.5 leading-snug line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                                        <p class="text-[11px] text-slate-400 mt-1.5" data-notif-time="<?= htmlspecialchars($notif['created_at']) ?>" data-notif-id="<?= (int)$notif['id'] ?>"><?= (new DateTime($notif['created_at']))->format('d M Y, h:i A') ?></p>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
                                        <?php if (!$notif['is_read']): ?>
                                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm"></span>
                                        <?php endif; ?>
                                        <div class="relative">
                                            <button onclick="event.stopPropagation(); toggleNotifOptions(this)" class="w-7 h-7 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 transition opacity-0 group-hover:opacity-100 cursor-pointer" title="More options">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                                            </button>
                                            <div class="hidden absolute right-0 top-full mt-1 w-44 bg-white border border-slate-200 rounded-xl shadow-lg z-50 py-1.5 notif-options-menu" onclick="event.stopPropagation();">
                                                <?php if (!$notif['is_read']): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                                    <button type="submit" name="mark_notification_read" class="w-full text-left px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition flex items-center gap-2.5 cursor-pointer">
                                                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                        Mark as read
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <div class="px-4 py-2.5 text-xs font-medium text-slate-400 flex items-center gap-2.5">
                                                    <svg class="w-3.5 h-3.5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    Already read
                                                </div>
                                                <?php endif; ?>
                                                <div class="my-1 border-t border-slate-100"></div>
                                                <button type="button" onclick="requestDeleteNotification(<?= (int)$notif['id'] ?>)" class="w-full text-left px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition flex items-center gap-2.5 cursor-pointer">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div class="p-10 text-center">
                                    <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                                        <svg class="w-7 h-7 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-400">No notifications yet</p>
                                    <p class="text-xs text-slate-300 mt-1">You'll see updates here</p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="border-t border-slate-100">
                                <a href="notifications.php" class="flex items-center justify-center gap-2 px-4 py-3 text-xs font-bold text-blue-600 hover:bg-blue-50 transition">View all notifications</a>
                            </div>
                        </div>
                    </div>
                    <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                        <?php if (!empty($_SESSION['profile_pic'])): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-indigo-500/20">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-indigo-500/20">
                            <?= strtoupper(substr($sup_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </button>
                    <div class="text-right">
                        <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($sup_name) ?></p>
                        <p class="text-sm text-slate-400">Supervisor</p>
                    </div>
                    <!-- Profile Dropdown Menu -->
                    <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 z-[1050] bg-white border border-slate-200 rounded-xl shadow-[0_4px_12px_rgba(0,0,0,0.15)] w-48 py-2">
                        <a href="profile.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                            <span>👤</span> My Profile
                        </a>
                        <a href="profile.php#security-section" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                            <span>🔑</span> Change Password
                        </a>
                        <div class="my-1 border-t border-slate-100"></div>
                        <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-500 hover:bg-red-50 transition">
                            <span>🚪</span> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-[900px] mx-auto space-y-6">

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
                        <div class="flex justify-start pt-3">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">📷 Upload Picture</button>
                        </div>
                    </form>
                </div>

                <!-- ════ SUPERVISOR INFORMATION ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🧑‍💼</span> Supervisor Information
                        </h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-3 bg-slate-50 rounded-xl">
                                <p class="text-sm font-bold text-slate-500 uppercase">Full Name</p>
                                <p class="text-xs font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($sup['username']) ?></p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl">
                                <p class="text-sm font-bold text-slate-500 uppercase">Username</p>
                                <p class="text-xs font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($sup['username']) ?></p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl">
                                <p class="text-sm font-bold text-slate-500 uppercase">Email</p>
                                <p class="text-xs font-semibold text-slate-800 mt-0.5 break-all"><?= htmlspecialchars($sup['email']) ?></p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl">
                                <p class="text-sm font-bold text-slate-500 uppercase">Phone</p>
                                <p class="text-xs font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($sup['phone'] ?? '') ?: '—' ?></p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl">
                                <p class="text-sm font-bold text-slate-500 uppercase">Department</p>
                                <p class="text-xs font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($sup['department'] ?? '') ?: '—' ?></p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl">
                                <p class="text-sm font-bold text-slate-500 uppercase">Position / Job Title</p>
                                <p class="text-xs font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($sup['position'] ?? '') ?: '—' ?></p>
                            </div>
                        </div>
                    </div>
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
                                <label class="block text-sm font-bold text-slate-500 mb-1">Phone</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($sup['phone'] ?? '') ?>" placeholder="e.g. 09-123456789" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number, e.g. 09-123-456-789 or +959 123 456 789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Department</label>
                                <input type="text" name="department" value="<?= htmlspecialchars($sup['department'] ?? '') ?>" placeholder="e.g. Computer Science" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Position / Job Title</label>
                                <input type="text" name="position" value="<?= htmlspecialchars($sup['position'] ?? '') ?>" placeholder="e.g. Senior Lecturer" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Role</label>
                                <div class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-500 font-semibold cursor-default select-none">
                                    👨‍🏫 University Supervisor
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-start pt-1">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Profile</button>
                        </div>
                    </form>
                </div>

                <!-- Section 2: Change Password -->
                <div id="security-section" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
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
                                <input type="password" name="new_password" required minlength="6" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <p class="text-sm text-slate-400 mt-0.5">Min 6 characters</p>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1">Confirm New Password</label>
                                <input type="password" name="confirm_password" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                        </div>
                        <div class="flex justify-start pt-1">
                            <button type="submit" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">🔒 Update Password</button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
    </div>
</div>

<script>
function toggleProfileDropdown(e) {
    e.stopPropagation();
    var menu = document.getElementById('profile-dropdown-menu');
    menu.classList.toggle('hidden');
    document.getElementById('notif-dropdown').style.cssText = 'opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.95);';
}
document.addEventListener('click', function(e) {
    var profileMenu = document.getElementById('profile-dropdown-menu');
    var profileBtn  = document.getElementById('profile-avatar-btn');
    if (!profileMenu.classList.contains('hidden') && !profileBtn.contains(e.target) && !profileMenu.contains(e.target)) {
        profileMenu.classList.add('hidden');
    }
});
function toggleNotifDropdown() {
    var dd = document.getElementById('notif-dropdown');
    var isVisible = dd.style.opacity === '1';
    dd.style.opacity    = isVisible ? '0'    : '1';
    dd.style.visibility = isVisible ? 'hidden': 'visible';
    dd.style.transform  = isVisible ? 'translateY(-8px) scale(0.95)' : 'translateY(0) scale(1)';
    document.getElementById('profile-dropdown-menu').classList.add('hidden');
}
function openNotifFromSidebar() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(function() { toggleNotifDropdown(); }, 300);
}
document.addEventListener('click', function(e) {
    var bellWrapper = document.getElementById('notif-bell-wrapper');
    var dd = document.getElementById('notif-dropdown');
    if (dd.style.opacity === '1' && !bellWrapper.contains(e.target)) {
        dd.style.opacity    = '0';
        dd.style.visibility = 'hidden';
        dd.style.transform  = 'translateY(-8px) scale(0.95)';
    }
});

function timeAgo(dateStr) {
    var date = new Date(dateStr);
    var now = new Date();
    var seconds = Math.floor((now - date) / 1000);
    if (seconds < 0) return 'Just now';
    if (seconds < 60) return 'Just now';
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + 'm ago';
    var hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + 'h ago';
    var days = Math.floor(hours / 24);
    if (days === 1) return 'Yesterday';
    if (days < 7) return days + 'd ago';
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}
function updateNotifTimestamps() {
    document.querySelectorAll('[data-notif-time]').forEach(function(el) {
        el.textContent = timeAgo(el.getAttribute('data-notif-time'));
    });
}
updateNotifTimestamps();
setInterval(updateNotifTimestamps, 60000);
</script>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
