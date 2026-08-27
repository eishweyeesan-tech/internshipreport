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
    <title>Student Academic Dossier – University Internship Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;0,800;0,900;1,600&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        oxford: {
                            DEFAULT: '#002147',
                            50: '#f0f4f9',
                            100: '#dce5f1',
                            800: '#002855',
                            900: '#002147',
                            950: '#00142b',
                        },
                        gold: {
                            DEFAULT: '#c5a059',
                            50: '#fcfaf6',
                            100: '#f7f2e8',
                            200: '#ede0c8',
                            300: '#dfcaa0',
                            400: '#c5a059',
                            500: '#b8860b',
                            600: '#996f08',
                        }
                    },
                    fontFamily: {
                        'sans': ['Inter', 'sans-serif'],
                        'serif': ['"Playfair Display"', 'Georgia', 'serif'],
                        'crest': ['Cinzel', 'serif'],
                        'mono': ['"JetBrains Mono"', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        html {
            scrollbar-gutter: stable;
            overflow-y: scroll;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f7f4;
            background-image: 
                radial-gradient(circle at 100% 0%, rgba(197, 160, 89, 0.05) 0%, transparent 25%),
                radial-gradient(circle at 0% 100%, rgba(0, 33, 71, 0.04) 0%, transparent 25%);
        }

        .glass-sidebar {
            background: #002147;
            border-right: 1px solid rgba(197, 160, 89, 0.25);
        }

        .glass-sidebar nav {
            scrollbar-width: thin;
            scrollbar-color: rgba(197, 160, 89, 0.3) transparent;
        }

        .glass-sidebar nav::-webkit-scrollbar {
            width: 4px;
        }

        .glass-sidebar nav::-webkit-scrollbar-thumb {
            background: rgba(197, 160, 89, 0.3);
            border-radius: 4px;
        }

        .nav-link {
            color: #dce5f1;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #ffffff;
            background: rgba(197, 160, 89, 0.15);
        }

        .active-nav {
            background: linear-gradient(135deg, rgba(197, 160, 89, 0.25) 0%, rgba(197, 160, 89, 0.1) 100%);
            color: #ffffff;
            font-weight: 600;
            border-left: 3px solid #c5a059;
        }

        .parchment-card {
            background: #ffffff;
            border: 1px solid #e7e2d7;
            box-shadow: 0 4px 20px -2px rgba(0, 33, 71, 0.05), 0 1px 3px 0 rgba(0, 0, 0, 0.02);
        }

        .oxford-gradient {
            background: linear-gradient(135deg, #001833 0%, #002147 50%, #0a2f5c 100%);
        }

        .academic-seal-watermark {
            background-image: radial-gradient(rgba(197, 160, 89, 0.08) 1px, transparent 1px);
            background-size: 20px 20px;
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
                btn.innerHTML = '<i class="fa-solid fa-xmark text-xs mr-1"></i> Cancel';
                btn.className = 'edit-toggle px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg border border-slate-300 transition cursor-pointer';
            } else {
                edit.classList.add('hidden');
                view.classList.remove('hidden');
                btn.innerHTML = '<i class="fa-solid fa-pen-nib text-xs mr-1"></i> Edit Record';
                btn.className = 'edit-toggle px-3.5 py-1.5 bg-[#c5a059] hover:bg-[#b8860b] text-[#002147] text-xs font-bold rounded-lg shadow-xs transition cursor-pointer';
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
            } else {
                input.type = 'password';
                if (eyeOpen) eyeOpen.classList.add('hidden');
                if (eyeSlash) eyeSlash.classList.remove('hidden');
            }
        }
    </script>
</head>

<body class="font-sans antialiased text-slate-800">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
        <div id="studentSidebarBackdrop" onclick="toggleStudentSidebar()" class="hidden fixed inset-0 bg-[#00142b]/60 backdrop-blur-xs z-40 lg:hidden print:hidden"></div>

        <!-- ─── SIDEBAR ─── -->
        <aside id="studentSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out glass-sidebar flex flex-col shrink-0 text-white shadow-2xl print:hidden">
            <!-- University Crest Top Brand -->
            <div class="h-20 flex items-center gap-3 px-5 border-b border-[#c5a059]/25 shrink-0 bg-[#001833]">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#c5a059] to-[#8f6d28] text-[#002147] flex items-center justify-center font-bold text-lg shadow-md border border-[#f7f2e8]/40 shrink-0">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="min-w-0">
                    <span class="font-crest tracking-wider text-sm font-bold text-white block uppercase leading-tight">University Portal</span>
                    <span class="text-[10px] text-[#c5a059] font-medium tracking-widest uppercase block">Internship Dossier</span>
                </div>
                <button type="button" onclick="toggleStudentSidebar()" class="lg:hidden ml-auto text-slate-300 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">✕</button>
            </div>

            <!-- Navigation Links -->
            <nav class="flex-1 min-h-0 py-5 space-y-1.5 px-3 overflow-y-auto">
                <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-sm transition-colors duration-200">
                    <i class="fa-solid fa-chart-line text-sm w-5 text-center text-[#c5a059]"></i>
                    <span>Dashboard</span>
                </a>
                <a href="log-history.php" class="nav-link flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-sm transition-colors duration-200">
                    <i class="fa-solid fa-book-bookmark text-sm w-5 text-center text-[#c5a059]"></i>
                    <span>Log History</span>
                </a>
                <a href="instructions.php" class="nav-link flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-sm transition-colors duration-200">
                    <i class="fa-solid fa-scroll text-sm w-5 text-center text-[#c5a059]"></i>
                    <span>Guidelines</span>
                </a>
                <a href="profile.php" class="nav-link active-nav flex items-center gap-3 px-3.5 py-2.5 rounded-lg text-sm transition-colors duration-200">
                    <i class="fa-solid fa-id-card-clip text-sm w-5 text-center text-[#c5a059]"></i>
                    <span>Academic Dossier</span>
                </a>
            </nav>

            <div class="p-4 border-t border-[#c5a059]/20 bg-[#001833]">
                <a href="../logout.php" class="flex items-center gap-3 px-3 py-2 text-xs font-bold text-rose-300 hover:text-white hover:bg-rose-900/30 rounded-lg transition-colors duration-200">
                    <i class="fa-solid fa-arrow-right-from-bracket text-xs w-4 text-center"></i>
                    <span>Sign Out</span>
                </a>
            </div>
        </aside>

        <!-- ─── MAIN CONTENT ─── -->
        <div class="flex-1 flex flex-col min-h-0 overflow-hidden">

            <!-- Top Bar -->
            <?php $pageTitle = '🎓 Student Academic Dossier';
            $show_back_link = true;
            include '../includes/student-topbar.php'; ?>

            <!-- Content Container -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8" style="scrollbar-gutter: stable;">
                <div class="max-w-6xl mx-auto w-full space-y-6 pb-20">

                    <!-- Alerts -->
                    <?php if ($profile_msg): ?>
                        <div class="bg-emerald-50 border-l-4 border-emerald-600 text-emerald-900 text-xs font-semibold px-4 py-3 rounded-r-xl flex items-center gap-2.5 shadow-xs">
                            <i class="fa-solid fa-circle-check text-emerald-600 text-sm"></i>
                            <span><?= htmlspecialchars($profile_msg) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($profile_err): ?>
                        <div class="bg-rose-50 border-l-4 border-rose-600 text-rose-900 text-xs font-semibold px-4 py-3 rounded-r-xl flex items-center gap-2.5 shadow-xs">
                            <i class="fa-solid fa-circle-exclamation text-rose-600 text-sm"></i>
                            <span><?= htmlspecialchars($profile_err) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($pw_msg): ?>
                        <div class="bg-emerald-50 border-l-4 border-emerald-600 text-emerald-900 text-xs font-semibold px-4 py-3 rounded-r-xl flex items-center gap-2.5 shadow-xs">
                            <i class="fa-solid fa-shield-halved text-emerald-600 text-sm"></i>
                            <span><?= htmlspecialchars($pw_msg) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($pw_err): ?>
                        <div class="bg-rose-50 border-l-4 border-rose-600 text-rose-900 text-xs font-semibold px-4 py-3 rounded-r-xl flex items-center gap-2.5 shadow-xs">
                            <i class="fa-solid fa-triangle-exclamation text-rose-600 text-sm"></i>
                            <span><?= htmlspecialchars($pw_err) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($avatar_msg): ?>
                        <div class="bg-emerald-50 border-l-4 border-emerald-600 text-emerald-900 text-xs font-semibold px-4 py-3 rounded-r-xl flex items-center gap-2.5 shadow-xs">
                            <i class="fa-solid fa-camera text-emerald-600 text-sm"></i>
                            <span><?= htmlspecialchars($avatar_msg) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($avatar_err): ?>
                        <div class="bg-rose-50 border-l-4 border-rose-600 text-rose-900 text-xs font-semibold px-4 py-3 rounded-r-xl flex items-center gap-2.5 shadow-xs">
                            <i class="fa-solid fa-circle-xmark text-rose-600 text-sm"></i>
                            <span><?= htmlspecialchars($avatar_err) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════════════════════ -->
                    <!-- 🏛️ PRESTIGIOUS OXFORD-STYLE UNIVERSITY IDENTITY HERO BANNER -->
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <div class="oxford-gradient rounded-2xl border-2 border-[#c5a059]/40 shadow-xl overflow-hidden text-white relative">
                        <!-- Subtle Academic Pattern Overlay -->
                        <div class="absolute inset-0 academic-seal-watermark opacity-25 pointer-events-none"></div>
                        <div class="absolute -right-12 -bottom-16 text-[#c5a059]/10 text-[220px] font-crest pointer-events-none select-none">
                            <i class="fa-solid fa-landmark"></i>
                        </div>

                        <!-- Top Prestige Header Ribbon -->
                        <div class="px-6 py-2.5 bg-[#00142b]/80 border-b border-[#c5a059]/30 flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-2 h-2 rounded-full bg-[#c5a059] animate-pulse"></span>
                                <span class="font-crest tracking-widest text-[#c5a059] uppercase text-[11px] font-bold">Faculty of Computer Studies</span>
                                <span class="text-slate-400 hidden sm:inline">•</span>
                                <span class="text-slate-300 text-[11px] hidden sm:inline">Official Student Internship Record</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#c5a059]/20 text-[#c5a059] border border-[#c5a059]/40 font-mono uppercase tracking-wider">
                                    AY <?= htmlspecialchars($academic_year ?: '2023-2024') ?>
                                </span>
                            </div>
                        </div>

                        <!-- Main Hero Body -->
                        <div class="p-6 sm:p-8 relative z-10">
                            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">

                                <!-- Student Profile & Avatar -->
                                <div class="flex items-center gap-5 sm:gap-6">
                                    <!-- Avatar with Gold Academic Ring -->
                                    <div class="relative group shrink-0">
                                        <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-2xl overflow-hidden ring-4 ring-[#c5a059]/50 border-2 border-white shadow-2xl bg-[#001833] flex items-center justify-center">
                                            <?php if ($profile_pic): ?>
                                                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?php
                                                $_p_initial = mb_substr($student_name, 0, 1, 'UTF-8');
                                                $_p_initial_display = ($_p_initial === '—' || empty($_p_initial)) ? 'S' : mb_strtoupper($_p_initial, 'UTF-8');
                                                ?>
                                                <span class="text-4xl font-serif font-bold text-[#c5a059]"><?= htmlspecialchars($_p_initial_display) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Camera Upload Badge Overlay -->
                                        <form method="POST" enctype="multipart/form-data" class="absolute inset-0 flex items-center justify-center bg-[#00142b]/75 rounded-2xl opacity-0 group-hover:opacity-100 transition-all duration-200 cursor-pointer">
                                            <input type="hidden" name="update_avatar" value="1">
                                            <input type="file" name="avatar" id="heroPhotoInput" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="this.form.submit()">
                                            <button type="button" onclick="document.getElementById('heroPhotoInput').click()" class="text-[#c5a059] text-xs font-bold flex flex-col items-center gap-1 cursor-pointer">
                                                <i class="fa-solid fa-camera text-base"></i>
                                                <span class="text-[10px] text-white">Update Photo</span>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Identity Info -->
                                    <div class="min-w-0 space-y-1.5">
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <h1 class="text-2xl sm:text-3xl font-serif font-bold text-white tracking-tight leading-none"><?= htmlspecialchars($student_name) ?></h1>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-emerald-950/80 text-emerald-300 border border-emerald-500/40">
                                                <i class="fa-solid fa-shield-halved text-[10px]"></i> Enrolled Candidate
                                            </span>
                                        </div>
                                        <p class="text-xs sm:text-sm text-slate-300 font-mono flex items-center gap-2">
                                            <i class="fa-regular fa-envelope text-[#c5a059]"></i>
                                            <span><?= htmlspecialchars($user_email) ?></span>
                                        </p>

                                        <!-- Academic Badges -->
                                        <div class="flex items-center gap-2 pt-1 flex-wrap">
                                            <?php if (!empty($student_roll)): ?>
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-[#c5a059]/20 text-[#c5a059] text-xs font-mono font-bold rounded-lg border border-[#c5a059]/40 shadow-xs">
                                                    <i class="fa-solid fa-id-badge text-[11px]"></i>
                                                    <span>Roll: <?= htmlspecialchars($student_roll) ?></span>
                                                </span>
                                            <?php endif; ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/10 text-slate-200 text-xs font-bold rounded-lg border border-white/15">
                                                <i class="fa-solid fa-graduation-cap text-[#c5a059] text-xs"></i>
                                                <span><?= htmlspecialchars($profile['major'] ?: 'Computer Science') ?></span>
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/10 text-slate-200 text-xs font-bold rounded-lg border border-white/15">
                                                <i class="fa-regular fa-calendar text-[#c5a059] text-xs"></i>
                                                <span>AY <?= htmlspecialchars($academic_year ?: '2023-2024') ?></span>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Placement Seal Snippet -->
                                <div class="w-full md:w-auto bg-[#00142b]/70 backdrop-blur-md rounded-xl border border-[#c5a059]/30 p-4 shrink-0 space-y-2">
                                    <div class="flex items-center gap-2 text-[#c5a059] text-[10px] font-crest font-bold uppercase tracking-widest">
                                        <i class="fa-solid fa-building-columns"></i>
                                        <span>Practicum Host Institution</span>
                                    </div>
                                    <p class="text-sm font-serif font-bold text-white"><?= htmlspecialchars($profile['company_name'] ?: 'Pending Placement') ?></p>
                                    <div class="pt-2 border-t border-white/10 flex items-center justify-between gap-4 text-xs">
                                        <span class="text-slate-400">Faculty Advisor:</span>
                                        <span class="font-bold text-[#c5a059]"><?= htmlspecialchars($supervisor_name ?: 'Pending Assignment') ?></span>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════════════ -->
                    <!-- 2-COLUMN PRESTIGE DOSSIER & RECORD LAYOUT -->
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                        <!-- ── LEFT COLUMN (4 cols): OFFICIAL DIGITAL STUDENT PASS CARD ── -->
                        <div class="lg:col-span-4 space-y-6">

                            <!-- University Pass Card -->
                            <div class="parchment-card rounded-2xl overflow-hidden border-2 border-[#e2d9c8]">
                                <!-- Card Header -->
                                <div class="bg-[#002147] text-white p-4 text-center border-b-2 border-[#c5a059] relative">
                                    <div class="w-8 h-8 mx-auto mb-1 rounded-full bg-[#c5a059] text-[#002147] flex items-center justify-center font-bold text-sm">
                                        <i class="fa-solid fa-feather-pointed"></i>
                                    </div>
                                    <h2 class="font-crest text-xs tracking-widest uppercase font-bold text-[#c5a059]">Academic Student Pass</h2>
                                    <p class="text-[10px] text-slate-300">Department of Computer Studies</p>
                                </div>

                                <!-- Card Photo & Roll -->
                                <div class="p-6 text-center space-y-3 bg-gradient-to-b from-[#fcfbf9] to-[#f7f5ef]">
                                    <div class="w-20 h-20 mx-auto rounded-xl overflow-hidden border-2 border-[#c5a059] shadow-md bg-[#002147] flex items-center justify-center">
                                        <?php if ($profile_pic): ?>
                                            <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Pass Avatar" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <span class="text-3xl font-serif font-bold text-[#c5a059]"><?= htmlspecialchars($_p_initial_display) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3 class="font-serif text-base font-bold text-[#002147]"><?= htmlspecialchars($student_name) ?></h3>
                                        <p class="text-xs font-mono font-bold text-[#b8860b] mt-0.5"><?= htmlspecialchars($student_roll ?: '5CS-PENDING') ?></p>
                                    </div>

                                    <div class="pt-3 border-t border-[#e2d9c8] text-left space-y-2 text-xs">
                                        <div class="flex justify-between items-center">
                                            <span class="text-slate-500 font-medium">Program:</span>
                                            <span class="font-bold text-slate-800"><?= htmlspecialchars($profile['major'] ?: 'Computer Science') ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-slate-500 font-medium">Academic Batch:</span>
                                            <span class="font-bold font-mono text-slate-800"><?= htmlspecialchars($academic_year ?: '2023-2024') ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-slate-500 font-medium">Practicum Length:</span>
                                            <span class="font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">12 Weeks (60 Days)</span>
                                        </div>
                                    </div>

                                    <div class="pt-3 border-t border-[#e2d9c8] flex items-center justify-center gap-2 text-[10px] text-slate-400 font-mono uppercase tracking-wider">
                                        <i class="fa-solid fa-stamp text-[#c5a059]"></i>
                                        <span>Verified Academic Credential</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Support Card -->
                            <div class="parchment-card rounded-2xl p-5 border border-[#e2d9c8] space-y-3">
                                <div class="flex items-center gap-2.5 text-[#002147]">
                                    <div class="w-7 h-7 rounded-lg bg-[#c5a059]/20 text-[#8f6d28] flex items-center justify-center text-xs font-bold">
                                        <i class="fa-solid fa-circle-info"></i>
                                    </div>
                                    <h4 class="font-serif text-xs font-bold uppercase tracking-wider">Practicum Guidelines</h4>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    အလုပ်သင်ကာလအတွင်း နေ့စဉ်မှတ်တမ်း (Daily Logs) များနှင့် အပတ်စဉ်သုံးသပ်ချက် (Weekly Reflections) များကို အချိန်မီ တင်ပြပေးရပါမည်။
                                </p>
                                <a href="instructions.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-[#002147] hover:text-[#b8860b] transition">
                                    <span>Read Full Guidelines</span>
                                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                </a>
                            </div>

                        </div>

                        <!-- ── RIGHT COLUMN (8 cols): ACADEMIC DOSSIER, PLACEMENT & SECURITY ── -->
                        <div class="lg:col-span-8 space-y-6">

                            <!-- ── SECTION 1: PERSONAL & ACADEMIC DOSSIER ── -->
                            <div id="card-personal" class="parchment-card rounded-2xl border border-[#e2d9c8] overflow-hidden">
                                <div class="px-6 py-4 bg-[#fcfbf9] border-b border-[#e2d9c8] flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-[#002147] text-[#c5a059] flex items-center justify-center text-xs font-bold shadow-xs">
                                            <i class="fa-solid fa-user-graduate"></i>
                                        </div>
                                        <div>
                                            <h2 class="font-serif text-sm font-bold text-[#002147] tracking-tight">Student Academic Dossier</h2>
                                            <p class="text-[11px] text-slate-500">Official student identity and registration information</p>
                                        </div>
                                    </div>
                                    <button type="button" onclick="toggleEdit('personal')" class="edit-toggle px-3.5 py-1.5 bg-[#c5a059] hover:bg-[#b8860b] text-[#002147] text-xs font-bold rounded-lg shadow-xs transition cursor-pointer">
                                        <i class="fa-solid fa-pen-nib text-xs mr-1"></i> Edit Record
                                    </button>
                                </div>
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
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
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
<div class="w-9 h-9 rounded-xl bg-[#002147] text-[#c5a059] flex items-center justify-center font-bold text-sm">
    <i class="fa-solid fa-timeline"></i>
</div>
<div>
    <p class="text-[10px] font-crest font-bold text-slate-400 uppercase tracking-widest">12-Week Semester Program Timeline</p>
    <p class="text-xs sm:text-sm font-serif font-bold text-[#002147]">
        <?= $profile['internship_start_date'] ? (new DateTime($profile['internship_start_date']))->format('d M Y') : '02 Oct 2023' ?>
        <span class="text-slate-400 font-normal mx-1.5">to</span>
        <?= $profile['internship_end_date'] ? (new DateTime($profile['internship_end_date']))->format('d M Y') : '22 Dec 2023' ?>
    </p>
</div>
</div>
<span class="text-xs font-mono font-bold text-[#002147] bg-[#c5a059]/20 border border-[#c5a059]/40 px-3 py-1.5 rounded-lg w-fit">
    60 Working Days Required
</span>
</div>

<p class="text-xs text-slate-500 pt-2 border-t border-[#e2d9c8] flex items-center gap-2">
    <span class="text-[#b8860b] font-bold">🔒 Academic Standing:</span>
    Placement and supervisor credentials are authenticated and overseen by the University Internship Board.
</p>
</div>
</div>

<!-- ── SECTION 3: PORTAL CREDENTIALS & SECURITY ── -->
<div class="parchment-card rounded-2xl border border-[#e2d9c8] overflow-hidden">
    <div class="px-6 py-4 bg-[#fcfbf9] border-b border-[#e2d9c8] flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-[#002147] text-[#c5a059] flex items-center justify-center text-xs font-bold shadow-xs">
                <i class="fa-solid fa-lock"></i>
            </div>
            <div>
                <h2 class="font-serif text-sm font-bold text-[#002147] tracking-tight">Security & Authentication</h2>
                <p class="text-[11px] text-slate-500">Manage your student portal login password</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-800 bg-emerald-50 px-3 py-1 rounded-lg border border-emerald-200">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
            <span>Authenticated Session</span>
        </span>
    </div>

    <div class="p-6 space-y-6">
        <!-- Security Info Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="flex items-center gap-3 p-3.5 bg-[#fcfbf9] rounded-xl border border-[#e7e2d7]">
                <span class="w-3 h-3 bg-emerald-600 rounded-full shrink-0 shadow-xs"></span>
                <div>
                    <p class="text-[10px] font-crest font-bold text-slate-400 uppercase">Account Status</p>
                    <p class="text-xs font-serif font-bold text-emerald-800">Active University Account</p>
                </div>
            </div>
            <div class="flex items-center gap-3 p-3.5 bg-[#fcfbf9] rounded-xl border border-[#e7e2d7]">
                <span class="w-3 h-3 bg-[#002147] rounded-full shrink-0 shadow-xs"></span>
                <div>
                    <p class="text-[10px] font-crest font-bold text-slate-400 uppercase">Last Login Record</p>
                    <p class="text-xs font-mono font-bold text-slate-700"><?= $last_login_at ? date('d M Y, h:i A', strtotime($last_login_at)) : 'Current Active Session' ?></p>
                </div>
            </div>
        </div>

        <!-- Password Form -->
        <form method="POST" class="pt-3 space-y-4 border-t border-[#e2d9c8]">
            <input type="hidden" name="change_password" value="1">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-serif font-bold text-[#002147] uppercase tracking-wider mb-1.5">Current Password</label>
                    <div class="relative">
                        <input type="password" id="current_password" name="current_password" required placeholder="••••••••" class="w-full bg-white border border-[#e2d9c8] rounded-xl pl-3.5 pr-9 py-2.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#c5a059]/30 focus:border-[#c5a059] transition">
                        <button type="button" onclick="togglePasswordVisibility('current_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                            <i class="eye-slash fa-regular fa-eye-slash text-xs"></i>
                            <i class="eye-open fa-regular fa-eye text-xs hidden text-[#b8860b]"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-serif font-bold text-[#002147] uppercase tracking-wider mb-1.5">New Password</label>
                    <div class="relative">
                        <input type="password" id="new_password" name="new_password" required minlength="6" placeholder="Min 6 characters" class="w-full bg-white border border-[#e2d9c8] rounded-xl pl-3.5 pr-9 py-2.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#c5a059]/30 focus:border-[#c5a059] transition">
                        <button type="button" onclick="togglePasswordVisibility('new_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                            <i class="eye-slash fa-regular fa-eye-slash text-xs"></i>
                            <i class="eye-open fa-regular fa-eye text-xs hidden text-[#b8860b]"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-serif font-bold text-[#002147] uppercase tracking-wider mb-1.5">Confirm New Password</label>
                    <div class="relative">
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Repeat new password" class="w-full bg-white border border-[#e2d9c8] rounded-xl pl-3.5 pr-9 py-2.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#c5a059]/30 focus:border-[#c5a059] transition">
                        <button type="button" onclick="togglePasswordVisibility('confirm_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                            <i class="eye-slash fa-regular fa-eye-slash text-xs"></i>
                            <i class="eye-open fa-regular fa-eye text-xs hidden text-[#b8860b]"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="flex justify-end pt-2">
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#c5a059] hover:bg-[#b8860b] text-[#002147] font-serif font-bold text-xs rounded-xl shadow-xs transition active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-key text-xs"></i>
                    <span>Update Password</span>
                </button>
            </div>
        </form>
    </div>
</div>

</div>

</div>

</div>
</main>
</div>
</div>

</body>

</html>