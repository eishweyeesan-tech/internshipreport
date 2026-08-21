<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/phone_validation.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'] ?? 'student';

$db = $mysqli ?? $conn;

// Fetch or create profile row
$profile_stmt = $db->prepare("SELECT sp.*, u.username, u.email, u.phone, u.profile_pic, u.last_login_at,
    COALESCE(c.company_name, '') AS company_name
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    LEFT JOIN companies c ON c.id = sp.company_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile = $res ? $res->fetch_assoc() : null;

if (!$profile) {
    $ins_prof = $db->prepare("INSERT INTO student_profiles (user_id) VALUES (?)");
    $ins_prof->bind_param("i", $user_id);
    try {
        $ins_prof->execute();
    } catch (mysqli_sql_exception $e) {
        // user_id not found in users table — redirect to login
        header('Location: ../logout.php');
        exit;
    }
    $profile_stmt->bind_param("i", $user_id);
    $profile_stmt->execute();
    $res = $profile_stmt->get_result();
    $profile = $res ? $res->fetch_assoc() : null;
}

$student_name = ($profile['username'] ?? '') ?: $username;
$student_roll = $profile['student_roll'] ?? '';
$intern_start = $profile['internship_start_date'] ?? null;
$intern_end   = $profile['internship_end_date'] ?? null;

$weeks = [];
if ($intern_start) {
    $w = 1;
    while (true) {
        $range = getWeekRange($intern_start, $w);
        if (!$range) break;
        if ($intern_end && $range['start'] > $intern_end) break;
        $weeks[$w] = $range;
        $w++;
    }
}

$progress_weeks_completed = 0;
$progress_total_weeks = count($weeks);
if (!empty($weeks)) {
    $wc_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND log_date BETWEEN ? AND ?");
    foreach ($weeks as $wn => $wr) {
        $wc_stmt->bind_param("iss", $user_id, $wr['start'], $wr['end']);
        $wc_stmt->execute();
        $res = $wc_stmt->get_result();
        $wc_row = $res ? $res->fetch_row() : null;
        if ((int) ($wc_row[0] ?? 0) > 0) {
            $progress_weeks_completed++;
        }
    }
}

// Fetch user data
$user_stmt = $db->prepare("SELECT email, profile_pic, last_login_at FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$res = $user_stmt->get_result();
$user_data = $res ? $res->fetch_assoc() : [];

$user_email     = $user_data['email'] ?? '';
$profile_pic    = $user_data['profile_pic'] ?? '';
$last_login_at  = $user_data['last_login_at'] ?? '';

// Handle Profile Update
$profile_msg = '';
$profile_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name         = trim($_POST['full_name'] ?? '');
    $student_roll      = trim($_POST['student_roll'] ?? '');
    $major             = trim($_POST['major'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $company_name      = trim($_POST['company_name'] ?? '');
    $job_role          = trim($_POST['job_role'] ?? '');
    $internship_start  = trim($_POST['internship_start_date'] ?? '');

    $phone_err = phone_validation_error($phone);
    if ($phone_err !== null) {
        $profile_err = $phone_err;
    } else {
        $phone = normalize_phone($phone);

        // Update user's username & phone
        $upd_u = $db->prepare("UPDATE users SET username = ?, phone = ? WHERE id = ?");
        $disp_name = $full_name ?: $username;
        $upd_u->bind_param("ssi", $disp_name, $phone, $user_id);
        $upd_u->execute();
        $_SESSION['username'] = $disp_name;

        // Resolve company_id
        $company_id = null;
        if (!empty($company_name)) {
            $c_chk = $db->prepare("SELECT id FROM companies WHERE company_name = ? LIMIT 1");
            $c_chk->bind_param("s", $company_name);
            $c_chk->execute();
            $c_res = $c_chk->get_result();
            if ($c_res && $c_row = $c_res->fetch_assoc()) {
                $company_id = (int)$c_row['id'];
            } else {
                $c_ins = $db->prepare("INSERT INTO companies (company_name) VALUES (?)");
                $c_ins->bind_param("s", $company_name);
                $c_ins->execute();
                $company_id = (int)$db->insert_id;
            }
        }

        $upd_prof = $db->prepare("UPDATE student_profiles SET
            student_roll = ?, major = ?, company_id = ?, job_role = ?, internship_start_date = ?
            WHERE user_id = ?");
        $istart = $internship_start ?: null;
        $upd_prof->bind_param("ssissi",
            $student_roll, $major, $company_id, $job_role, $istart,
            $user_id
        );
        $upd_prof->execute();

        $profile_stmt->bind_param("i", $user_id);
        $profile_stmt->execute();
        $res = $profile_stmt->get_result();
        $profile = $res ? $res->fetch_assoc() : null;
        $profile_msg = 'saved';
    }
}

// Handle Password Change
$pw_msg = '';
$pw_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new_pw) || empty($confirm)) {
        $pw_err = 'All password fields are required.';
    } elseif (strlen($new_pw) < 6) {
        $pw_err = 'New password must be at least 6 characters.';
    } elseif ($new_pw !== $confirm) {
        $pw_err = 'New passwords do not match.';
    } else {
        $check_stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $res = $check_stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        $hash = $row[0] ?? '';

        if (!password_verify($current, $hash)) {
            $pw_err = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $upd_pw = $db->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?");
            $upd_pw->bind_param("si", $new_hash, $user_id);
            $upd_pw->execute();
            $_SESSION['is_first_login'] = false;
            $pw_msg = 'Password updated successfully.';
        }
    }
}

// Handle Avatar Upload
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
                $upd_pic = $db->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                $upd_pic->bind_param("si", $filename, $user_id);
                $upd_pic->execute();
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
    <title>My Profile – InternReport</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
    html { scrollbar-gutter: stable; overflow-y: scroll; }
    .nav-link { color: #ccfbf1; font-weight: 500; }
    .nav-link:hover { color: #fff; background: rgba(15, 118, 110, 0.6); }
    .active-nav { background: #0a9396; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3); }
    .glass-sidebar { background: #005f73; border-right: 1px solid rgba(15, 118, 110, 0.4); }
    .glass-sidebar nav { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
    .glass-sidebar nav::-webkit-scrollbar { width: 4px; }
    .glass-sidebar nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
    @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }
    </style>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontSize: {
                    'micro': '0.5rem',
                    'caption': '0.6875rem',
                    'label': '0.8125rem',
                    'subtitle': '0.9375rem',
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
            btn.textContent = 'Edit';
            btn.classList.remove('bg-slate-200', 'hover:bg-slate-300', 'text-slate-700');
            btn.classList.add('bg-slate-800', 'hover:bg-slate-900');
        }
    }
    </script>
</head>
<body class="bg-slate-100 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
    <div id="studentSidebarBackdrop" onclick="toggleStudentSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

    <!-- ─── SIDEBAR ─── -->
    <aside id="studentSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out glass-sidebar flex flex-col shrink-0 text-white shadow-xl print:hidden">
        <div class="h-16 flex items-center justify-between px-5 border-b border-white/10 shrink-0">
            <span class="font-black text-white tracking-tight text-lg">InternReport</span>
            <button type="button" onclick="toggleStudentSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">✕</button>
        </div>
        <nav class="flex-1 min-h-0 py-4 space-y-1 px-3 overflow-y-auto">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg> Dashboard
            </a>
            <a href="notifications.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> Notifications
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> Log History
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"/></svg> Instructions
            </a>
            <a href="profile.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'My Profile'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 md:p-8">
            <div class="max-w-7xl mx-auto w-full space-y-6">

                <?php if ($profile_msg === 'saved'): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    Profile updated successfully.
                </div>
                <?php endif; ?>

                <?php if ($profile_err): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <?= htmlspecialchars($profile_err) ?>
                </div>
                <?php endif; ?>

                <?php if ($pw_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <?= htmlspecialchars($pw_msg) ?>
                </div>
                <?php endif; ?>

                <?php if ($pw_err): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <?= htmlspecialchars($pw_err) ?>
                </div>
                <?php endif; ?>

                <!-- ════ AVATAR HEADER ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex items-center gap-5">
                    <div class="relative w-16 h-16 shrink-0">
                        <?php if ($profile_pic): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-16 h-16 rounded-full object-cover">
                        <?php else: ?>
                            <?php
                            $_p_initial = mb_substr($student_name, 0, 1, 'UTF-8');
                            $_p_initial_display = ($_p_initial === '—' || empty($_p_initial)) ? 'S' : mb_strtoupper($_p_initial, 'UTF-8');
                            ?>
                            <div class="w-16 h-16 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xl font-bold">
                                <?= htmlspecialchars($_p_initial_display) ?>
                            </div>
                        <?php endif; ?>
                        <div class="absolute -bottom-0.5 -right-0.5 w-5 h-5 bg-slate-100 rounded-full flex items-center justify-center border border-slate-200" title="Change avatar">
                            <svg class="w-2.5 h-2.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-sm font-black text-slate-800"><?= htmlspecialchars($student_name) ?></h2>
                        <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($user_email) ?></p>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded capitalize"><?= htmlspecialchars($role) ?></span>
                            <?php if (!empty($student_roll)): ?>
                            <span class="text-sm font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($student_roll) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($avatar_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <?= htmlspecialchars($avatar_msg) ?>
                </div>
                <?php endif; ?>

                <!-- ════ PROFILE PICTURE UPLOAD ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg> Profile Picture
                        </h3>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="p-5">
                        <input type="hidden" name="update_avatar" value="1">
                        <div class="flex items-center gap-5">
                            <?php if ($profile_pic): ?>
                                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Current avatar" class="w-14 h-14 rounded-full object-cover border border-slate-200">
                            <?php else: ?>
                                <div class="w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-bold border border-slate-200">
                                    <?= htmlspecialchars($_p_initial_display) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <label class="block text-sm font-bold text-slate-500 mb-1">Upload New Picture</label>
                                <input type="file" name="avatar" accept="image/jpeg,image/png" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100 file:cursor-pointer">
                                <p class="text-sm text-slate-400 mt-1">JPG, JPEG, or PNG. Max 2MB.</p>
                            </div>
                        </div>
                        <div class="flex justify-end pt-3">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">Upload Picture</button>
                        </div>
                    </form>
                </div>

                <!-- ════ SECURITY & LAST LOGIN ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg> Security & Last Login
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
                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Personal Information
                        </h3>
                        <button type="button" onclick="toggleEdit('personal')" class="edit-toggle px-3 py-1 bg-slate-800 hover:bg-slate-900 text-white text-sm font-bold rounded-lg transition cursor-pointer">Edit</button>
                    </div>

                    <!-- View Mode -->
                    <div class="view-mode p-5">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                            <div>
                                <dt class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-0.5">Full Name</dt>
                                <dd class="text-xs text-slate-700 font-semibold"><?= htmlspecialchars($profile['username'] ?: '—') ?></dd>
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
                                    <input type="text" name="full_name" value="<?= htmlspecialchars($profile['username']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
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
                                    <input type="text" name="phone" value="<?= htmlspecialchars($profile['phone']) ?>" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number, e.g. 09-123-456-789 or +959 123 456 789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                </div>
                            </div>
                            <!-- Hidden fields to preserve internship data on save -->
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($profile['company_name']) ?>">
                            <input type="hidden" name="job_role" value="<?= htmlspecialchars($profile['job_role']) ?>">
                            <input type="hidden" name="internship_start_date" value="<?= htmlspecialchars($profile['internship_start_date']) ?>">
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ════ INTERNSHIP DETAILS ════ -->
                <div id="card-internship" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg> Internship Details
                        </h3>
                        <button type="button" onclick="toggleEdit('internship')" class="edit-toggle px-3 py-1 bg-slate-800 hover:bg-slate-900 text-white text-sm font-bold rounded-lg transition cursor-pointer">Edit</button>
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
                                    <input type="text" name="instructor_phone" value="<?= htmlspecialchars($profile['instructor_phone']) ?>" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number, e.g. 09-123-456-789 or +959 123 456 789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
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
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ════ CHANGE PASSWORD ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> Change Password
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
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">Update Password</button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
    </div>
</div>



</body>
</html>
