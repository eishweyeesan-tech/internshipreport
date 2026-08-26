<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/phone_validation.php';
require_once __DIR__ . '/../includes/security_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'] ?? 'student';

$db = $mysqli ?? $conn;

// Fetch or create profile row with supervisor details
$profile_stmt = $db->prepare("
    SELECT sp.*, sup_u.username AS supervisor_name, sup_u.email AS supervisor_email,
           u.academic_year, u.email AS user_email, u.profile_pic, u.last_login_at
    FROM student_profiles sp
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?
");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile = $res ? $res->fetch_assoc() : null;

if (!$profile) {
    $ins_prof = $db->prepare("INSERT INTO student_profiles (user_id, full_name) VALUES (?, ?)");
    $ins_prof->bind_param("is", $user_id, $username);
    try {
        $ins_prof->execute();
    } catch (mysqli_sql_exception $e) {
        header('Location: ../logout.php');
        exit;
    }
    $profile_stmt->bind_param("i", $user_id);
    $profile_stmt->execute();
    $res = $profile_stmt->get_result();
    $profile = $res ? $res->fetch_assoc() : null;
}

$student_name     = ($profile['full_name'] ?? '') ?: $username;
$student_roll     = $profile['student_roll'] ?? '';
$intern_start     = $profile['internship_start_date'] ?? null;
$intern_end       = $profile['internship_end_date'] ?? null;
$user_email       = $profile['user_email'] ?? ($_SESSION['email'] ?? '');
$profile_pic      = $profile['profile_pic'] ?? '';
$last_login_at    = $profile['last_login_at'] ?? '';
$supervisor_name  = $profile['supervisor_name'] ?? '';
$supervisor_email = $profile['supervisor_email'] ?? '';
$academic_year    = $profile['academic_year'] ?? '';

// Build Week Ranges
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

// Handle Profile Update (Job Role is excluded)
$profile_msg = '';
$profile_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name         = trim($_POST['full_name'] ?? '');
    $student_roll      = trim($_POST['student_roll'] ?? '');
    $major             = trim($_POST['major'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');

    $phone_err = phone_validation_error($phone);
    if ($phone_err !== null) {
        $profile_err = $phone_err;
    } else {
        $phone = normalize_phone($phone);

        $upd_prof = $db->prepare("UPDATE student_profiles SET
            full_name = ?, student_roll = ?, major = ?, phone = ?
            WHERE user_id = ?");
        $upd_prof->bind_param(
            "ssssi",
            $full_name,
            $student_roll,
            $major,
            $phone,
            $user_id
        );
        $upd_prof->execute();

        $profile_stmt->bind_param("i", $user_id);
        $profile_stmt->execute();
        $res = $profile_stmt->get_result();
        $profile = $res ? $res->fetch_assoc() : null;

        $student_name     = ($profile['full_name'] ?? '') ?: $username;
        $student_roll     = $profile['student_roll'] ?? '';
        $intern_start     = $profile['internship_start_date'] ?? null;
        $intern_end       = $profile['internship_end_date'] ?? null;
        $supervisor_name  = $profile['supervisor_name'] ?? '';
        $supervisor_email = $profile['supervisor_email'] ?? '';

        $profile_msg = 'Personal details updated successfully.';
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
    } elseif ($err_msg = validate_strong_password($new_pw)) {
        $pw_err = $err_msg;
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
$avatar_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $avatar_err = 'Only JPG, JPEG, PNG, and WEBP files are allowed.';
        } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $avatar_err = 'File size must be less than 2MB.';
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
                $avatar_msg = 'Profile picture updated successfully.';
            } else {
                $avatar_err = 'Failed to upload picture.';
            }
        }
    } else {
        $avatar_err = 'Please select a valid image file.';
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        html {
            scrollbar-gutter: stable;
            overflow-y: scroll;
        }

        body {
            font-family: 'Inter', sans-serif;
        }

        .glass-sidebar {
            background: #005f73;
            border-right: 1px solid rgba(15, 118, 110, 0.4);
        }

        .glass-sidebar nav {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
        }

        .glass-sidebar nav::-webkit-scrollbar {
            width: 4px;
        }

        .glass-sidebar nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .nav-link {
            color: #ccfbf1;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #fff;
            background: rgba(15, 118, 110, 0.6);
        }

        .active-nav {
            background: #0a9396;
            color: #fff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3);
        }

        @media print {
            aside,
            header,
            .no-print {
                display: none !important;
            }

            .flex.h-screen {
                height: auto !important;
                overflow: visible !important;
            }

            main {
                overflow: visible !important;
            }

            body {
                background: white !important;
            }
        }
    </style>
    <script>
        function toggleEdit(section) {
            var card = document.getElementById('card-' + section);
            var view = card.querySelector('.view-mode');
            var edit = card.querySelector('.edit-mode');
            var btn = card.querySelector('.edit-toggle');
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
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg> Dashboard
                </a>
                <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg> Log History
                </a>
                <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z" />
                    </svg> Instructions
                </a>
                <a href="profile.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg> Profile
                </a>
            </nav>
            <div class="p-3 border-t border-white/10">
                <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg> Logout
                </a>
            </div>
        </aside>

        <!-- ─── MAIN ─── -->
        <div class="flex-1 flex flex-col min-h-0">

            <!-- Top Bar -->
            <?php $pageTitle = 'My Profile';
            $show_back_link = true;
            include '../includes/student-topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto p-6 md:p-8">
                <div class="max-w-5xl mx-auto w-full space-y-6">

                    <!-- Alerts -->
                    <?php if ($profile_msg): ?>
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2 shadow-xs">
                            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= htmlspecialchars($profile_msg) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($profile_err): ?>
                        <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2 shadow-xs">
                            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= htmlspecialchars($profile_err) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pw_msg): ?>
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2 shadow-xs">
                            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= htmlspecialchars($pw_msg) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pw_err): ?>
                        <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2 shadow-xs">
                            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= htmlspecialchars($pw_err) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($avatar_msg): ?>
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2 shadow-xs">
                            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?= htmlspecialchars($avatar_msg) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($avatar_err): ?>
                        <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-2xl flex items-center gap-2 shadow-xs">
                            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= htmlspecialchars($avatar_err) ?>
                        </div>
                    <?php endif; ?>

                    <!-- ════ UNIFIED AVATAR & STUDENT PROFILE HEADER ════ -->
                    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 sm:p-7">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
                            <div class="flex items-center gap-5">
                                <div class="relative w-20 h-20 shrink-0">
                                    <?php if ($profile_pic): ?>
                                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-20 h-20 rounded-2xl object-cover border-2 border-indigo-100 shadow-xs">
                                    <?php else: ?>
                                        <?php
                                        $_p_initial = mb_substr($student_name, 0, 1, 'UTF-8');
                                        $_p_initial_display = ($_p_initial === '—' || empty($_p_initial)) ? 'S' : mb_strtoupper($_p_initial, 'UTF-8');
                                        ?>
                                        <div class="w-20 h-20 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-200 flex items-center justify-center text-2xl font-black shadow-xs">
                                            <?= htmlspecialchars($_p_initial_display) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h2 class="text-base sm:text-lg font-black text-slate-800 tracking-tight"><?= htmlspecialchars($student_name) ?></h2>
                                    <p class="text-xs text-slate-500 font-medium mt-0.5"><?= htmlspecialchars($user_email) ?></p>
                                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                                        <span class="text-xs font-bold text-teal-700 bg-teal-50 px-2.5 py-0.5 rounded-lg border border-teal-200 capitalize">Student</span>
                                        <?php if (!empty($student_roll)): ?>
                                            <span class="text-xs font-bold text-slate-700 bg-slate-100 px-2.5 py-0.5 rounded-lg border border-slate-200 font-mono"><?= htmlspecialchars($student_roll) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($academic_year)): ?>
                                            <span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-lg border border-indigo-200">AY: <?= htmlspecialchars($academic_year) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Avatar Upload Form -->
                            <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:items-end gap-2 w-full sm:w-auto pt-4 sm:pt-0 border-t sm:border-t-0 border-slate-100">
                                <input type="hidden" name="update_avatar" value="1">
                                <div class="flex items-center gap-2">
                                    <input type="file" name="avatar" id="avatarFileInput" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="this.form.submit()">
                                    <button type="button" onclick="document.getElementById('avatarFileInput').click()" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">
                                        <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        Change Photo
                                    </button>
                                </div>
                                <p class="text-[10px] text-slate-400">JPG, PNG, WEBP. Max 2MB.</p>
                            </form>
                        </div>
                    </div>

                    <!-- ════ PERSONAL INFORMATION ════ -->
                    <div id="card-personal" class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </span>
                                Personal Information
                            </h3>
                            <button type="button" onclick="toggleEdit('personal')" class="edit-toggle px-3.5 py-1.5 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl transition cursor-pointer">Edit</button>
                        </div>

                        <!-- View Mode -->
                        <div class="view-mode p-6">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Full Name</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($profile['full_name'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Student Roll No</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold font-mono"><?= htmlspecialchars($profile['student_roll'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Major / Department</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($profile['major'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Phone Number</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold font-mono"><?= htmlspecialchars($profile['phone'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Email Address</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($user_email) ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Academic Year</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($academic_year ?: '—') ?></dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Edit Mode -->
                        <form method="POST" class="edit-mode hidden">
                            <input type="hidden" name="update_profile" value="1">
                            <div class="p-6 space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Full Name</label>
                                        <input type="text" name="full_name" value="<?= htmlspecialchars($profile['full_name']) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Student Roll No</label>
                                        <input type="text" name="student_roll" value="<?= htmlspecialchars($profile['student_roll']) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Major / Department</label>
                                        <input type="text" name="major" value="<?= htmlspecialchars($profile['major']) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Phone Number</label>
                                        <input type="text" name="phone" value="<?= htmlspecialchars($profile['phone']) ?>" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number, e.g. 09-123-456-789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                                    </div>
                                </div>
                                <!-- Hidden fields to preserve internship data on save -->
                                <input type="hidden" name="company_name" value="<?= htmlspecialchars($profile['company_name']) ?>">
                                <input type="hidden" name="instructor_name" value="<?= htmlspecialchars($profile['instructor_name']) ?>">
                                <input type="hidden" name="instructor_email" value="<?= htmlspecialchars($profile['instructor_email']) ?>">
                                <input type="hidden" name="instructor_phone" value="<?= htmlspecialchars($profile['instructor_phone']) ?>">
                                <input type="hidden" name="internship_start_date" value="<?= htmlspecialchars($profile['internship_start_date']) ?>">
                                <input type="hidden" name="internship_end_date" value="<?= htmlspecialchars($profile['internship_end_date']) ?>">
                                <div class="flex justify-end pt-2">
                                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-xs transition active:scale-95 cursor-pointer">Save Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ════ INTERNSHIP & SUPERVISION DETAILS ════ -->
                    <div id="card-internship" class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </span>
                                Internship &amp; Placement Details
                            </h3>
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-amber-700 bg-amber-50 px-3 py-1 rounded-xl border border-amber-200/80">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Supervisor Assigned
                            </span>
                        </div>

                        <!-- Read-Only View Mode (Students cannot edit their instructor to prevent self-approval fraud) -->
                        <div class="p-6">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Company / Organization</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($profile['company_name'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Company Instructor</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($profile['instructor_name'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Instructor Email</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold"><?= htmlspecialchars($profile['instructor_email'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Instructor Phone</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold font-mono"><?= htmlspecialchars($profile['instructor_phone'] ?: '—') ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Internship Start Date</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold font-mono">
                                        <?= $profile['internship_start_date'] ? (new DateTime($profile['internship_start_date']))->format('d M Y') : '—' ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Internship End Date</dt>
                                    <dd class="text-xs sm:text-sm text-slate-800 font-bold font-mono">
                                        <?= $profile['internship_end_date'] ? (new DateTime($profile['internship_end_date']))->format('d M Y') : '—' ?>
                                    </dd>
                                </div>
                            </dl>

                            <!-- Assigned University Supervisor Block -->
                            <div class="mt-6 pt-5 border-t border-slate-100 bg-slate-50/70 -mx-6 -mb-6 p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center font-bold shrink-0">
                                        🎓
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Assigned University Supervisor</p>
                                        <p class="text-xs sm:text-sm font-bold text-slate-800"><?= htmlspecialchars($supervisor_name ?: 'Not Assigned Yet') ?></p>
                                        <?php if ($supervisor_email): ?>
                                            <p class="text-[11px] text-slate-500 font-medium"><?= htmlspecialchars($supervisor_email) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="text-xs font-semibold <?= $supervisor_name ? 'text-teal-700 bg-teal-50 border-teal-200' : 'text-slate-500 bg-slate-100 border-slate-200' ?> px-3 py-1 rounded-full border">
                                    <?= $supervisor_name ? 'Supervised' : 'Pending Assignment' ?>
                                </span>
                            </div>

                            <p class="text-[11px] text-slate-500 mt-4 pt-3 border-t border-slate-100 flex items-center gap-2">
                                <span class="text-amber-600 font-bold">🔒 လုံခြုံရေး အသိပေးချက်:</span>
                                ကုမ္ပဏီနှင့် Instructor အချက်အလက်များကို Supervisor / Admin ကသာ တရားဝင် သတ်မှတ်ပြင်ဆင်ပေးပါသည်။ (ပြောင်းလဲလိုပါက တာဝန်ခံ Supervisor ထံ ဆက်သွယ်ပါ)
                            </p>
                        </div>
                    </div>

                    <!-- ════ SECURITY & CHANGE PASSWORD ════ -->
                    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100">
                            <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                    </svg>
                                </span>
                                Security &amp; Change Password
                            </h3>
                        </div>
                        <div class="p-6 space-y-6">
                            <!-- Security Info Bar -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="flex items-center gap-3 p-3.5 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                    <span class="w-3 h-3 bg-emerald-500 rounded-full shrink-0 shadow-xs"></span>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Account Status</p>
                                        <p class="text-xs font-bold text-emerald-700">Active</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 p-3.5 bg-slate-50/70 border border-slate-200/60 rounded-2xl">
                                    <span class="w-3 h-3 bg-blue-500 rounded-full shrink-0 shadow-xs"></span>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Last Login Detected</p>
                                        <p class="text-xs font-bold text-slate-700"><?= $last_login_at ? date('d M Y, h:i A', strtotime($last_login_at)) : 'Current Session' ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Password Form -->
                            <form method="POST" class="pt-2 space-y-4 border-t border-slate-100">
                                <input type="hidden" name="change_password" value="1">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Current Password</label>
                                        <div class="relative">
                                            <input type="password" id="current_password" name="current_password" required placeholder="••••••••" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3.5 pr-9 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-amber-500 focus:bg-white transition">
                                            <button type="button" onclick="togglePasswordVisibility('current_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                                <!-- Eye Slash (When Password is Hidden) -->
                                                <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                </svg>
                                                <!-- Eye Open (When Password is Shown) -->
                                                <svg class="eye-open w-4 h-4 text-amber-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">New Password</label>
                                        <div class="relative">
                                            <input type="password" id="new_password" name="new_password" required minlength="6" placeholder="Min 6 characters" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3.5 pr-9 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-amber-500 focus:bg-white transition">
                                            <button type="button" onclick="togglePasswordVisibility('new_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                                <!-- Eye Slash (When Password is Hidden) -->
                                                <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                </svg>
                                                <!-- Eye Open (When Password is Shown) -->
                                                <svg class="eye-open w-4 h-4 text-amber-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Confirm New Password</label>
                                        <div class="relative">
                                            <input type="password" id="confirm_password" name="confirm_password" required placeholder="Repeat new password" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3.5 pr-9 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-amber-500 focus:bg-white transition">
                                            <button type="button" onclick="togglePasswordVisibility('confirm_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                                <!-- Eye Slash (When Password is Hidden) -->
                                                <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                </svg>
                                                <!-- Eye Open (When Password is Shown) -->
                                                <svg class="eye-open w-4 h-4 text-amber-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-end pt-2">
                                    <button type="submit" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-xs transition active:scale-95 cursor-pointer">Update Password</button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

</body>

</html>